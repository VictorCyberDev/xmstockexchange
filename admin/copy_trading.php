<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../db.php';

// ✅ Ensure DB connection exists
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? 'No connection'));
}

// ✅ Auth check
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$uploadDir = __DIR__ . '/../uploads/traders/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

// ✅ Detect AJAX properly
$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

// ── AJAX handler ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {

    header('Content-Type: application/json');

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }

    $action = $input['action'] ?? 'stop_trade';

    // ✅ EDIT TRADE
    if ($action === 'edit_trade') {

        $trade_id     = intval($input['trade_id'] ?? 0);
        $new_invested = floatval($input['invested_amount'] ?? -1);
        $new_profit   = floatval($input['manual_profit'] ?? -1);

        if (!$trade_id) {
            echo json_encode(['success'=>false,'message'=>'Invalid trade ID']);
            exit;
        }

        if ($new_invested < 0 || $new_profit < 0) {
            echo json_encode(['success'=>false,'message'=>'Amounts must be >= 0']);
            exit;
        }

        $st = $conn->prepare("SELECT id FROM copy_trades WHERE id=? AND status='active'");
        if (!$st) {
            echo json_encode(['success'=>false,'message'=>$conn->error]);
            exit;
        }

        $st->bind_param("i", $trade_id);
        $st->execute();
        $result = $st->get_result();

        if (!$result || !$result->fetch_assoc()) {
            echo json_encode(['success'=>false,'message'=>'Trade not found']);
            exit;
        }

        $upd = $conn->prepare("UPDATE copy_trades SET invested_amount=?, manual_profit=? WHERE id=?");
        if (!$upd) {
            echo json_encode(['success'=>false,'message'=>$conn->error]);
            exit;
        }

        $upd->bind_param("ddi", $new_invested, $new_profit, $trade_id);

        if ($upd->execute()) {
            echo json_encode([
                'success'=>true,
                'message'=>'Updated',
                'invested_amount'=>$new_invested,
                'manual_profit'=>$new_profit
            ]);
        } else {
            echo json_encode(['success'=>false,'message'=>$upd->error]);
        }

        exit;
    }

    // ✅ STOP TRADE
    $trade_id = intval($input['trade_id'] ?? 0);

    if (!$trade_id) {
        echo json_encode(['success'=>false,'message'=>'Invalid trade ID']);
        exit;
    }

    $st = $conn->prepare("SELECT id,user_id,invested_amount FROM copy_trades WHERE id=? AND status='active'");
    if (!$st) {
        echo json_encode(['success'=>false,'message'=>$conn->error]);
        exit;
    }

    $st->bind_param("i", $trade_id);
    $st->execute();
    $trade = $st->get_result()->fetch_assoc();

    if (!$trade) {
        echo json_encode(['success'=>false,'message'=>'Trade not found']);
        exit;
    }

    $invested = (float)$trade['invested_amount'];

    // ✅ FIX: Ensure balance column exists
    if ($invested > 0) {
        $wq = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id=?");

        if (!$wq) {
            echo json_encode(['success'=>false,'message'=>$conn->error]);
            exit;
        }

        $wq->bind_param("di", $invested, $trade['user_id']);
        $wq->execute();
    }

    $upd = $conn->prepare("UPDATE copy_trades SET status='stopped', stopped_at=NOW() WHERE id=?");

    if (!$upd) {
        echo json_encode(['success'=>false,'message'=>$conn->error]);
        exit;
    }

    $upd->bind_param("i", $trade_id);
    $upd->execute();

    echo json_encode([
        'success'=>true,
        'message'=>'Trade stopped. $'.number_format($invested,2).' refunded.'
    ]);

    exit;
}

// ── Regular POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAjax) {

    $action = $_POST['action'] ?? '';

    if ($action === 'delete' && !empty($_POST['id'])) {
        $s = $conn->prepare("DELETE FROM copy_traders WHERE id=?");
        if ($s) {
            $s->bind_param("i", $_POST['id']);
            $s->execute();
        }

    } elseif ($action === 'save') {
        $id             = !empty($_POST['id']) ? intval($_POST['id']) : null;
        $display_name   = $_POST['display_name'] ?? 'Unnamed Trader';
        $category       = $_POST['category'] ?? '';
        $monthly_return = floatval($_POST['monthly_return'] ?? 0);
        $win_rate       = floatval($_POST['win_rate'] ?? 0);
        $risk_score     = intval($_POST['risk_score'] ?? 1);
        $duration_days  = intval($_POST['duration_days'] ?? 30); // Flexible Duration
        $min_deposit    = floatval($_POST['min_deposit'] ?? 0);   // Flexible Min Deposit
        $trading_fee    = floatval($_POST['trading_fee'] ?? 0);   // Flexible Fee
        $status         = $_POST['status'] ?? 'active';
        $verified       = isset($_POST['verified']) ? 1 : 0;

        if ($id) {
            // ✅ UPDATE EXISTING TRADER
            $sql = "UPDATE copy_traders SET 
                    display_name=?, category=?, monthly_return=?, win_rate=?, 
                    risk_score=?, duration_days=?, min_deposit=?, trading_fee=?, 
                    status=?, verified=? 
                    WHERE id=?";
            $st = $conn->prepare($sql);
            $st->bind_param("ssddidddsii", 
                $display_name, $category, $monthly_return, $win_rate, 
                $risk_score, $duration_days, $min_deposit, $trading_fee, 
                $status, $verified, $id
            );
        } else {
            // ✅ INSERT NEW TRADER
            $sql = "INSERT INTO copy_traders 
                    (display_name, category, monthly_return, win_rate, risk_score, duration_days, min_deposit, trading_fee, status, verified) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $st = $conn->prepare($sql);
            $st->bind_param("ssddidddsi", 
                $display_name, $category, $monthly_return, $win_rate, 
                $risk_score, $duration_days, $min_deposit, $trading_fee, 
                $status, $verified
            );
        }

        if ($st && $st->execute()) {
            // Optional: Set a success message in session for a toast notification
            $_SESSION['admin_msg'] = "Trader " . ($id ? "updated" : "added") . " successfully.";
        }
        
        // Refresh to see changes
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ── Data ──
$rows = [];
$res  = $conn->query("SELECT * FROM copy_traders ORDER BY followers DESC LIMIT 200");

if ($res) {
    while ($r = $res->fetch_assoc()) $rows[] = $r;
}

// ✅ Safe active trades query
$activeTrades = [];
$atq = $conn->query("
    SELECT ct.id AS trade_id, ct.user_id, ct.invested_amount, ct.manual_profit,
           ct.created_at, ct.trading_fee,
           u.email AS user_email, u.name AS user_name,
           t.display_name AS trader_name, t.profile_image AS trader_img,
           t.monthly_return, t.duration_days
    FROM copy_trades ct
    JOIN users u ON ct.user_id = u.id
    JOIN copy_traders t ON ct.leader_id = t.id
    WHERE ct.status='active'
    ORDER BY ct.created_at DESC
    LIMIT 500
");

if ($atq) {
    $activeTrades = $atq->fetch_all(MYSQLI_ASSOC);
}

// Stats
$totalTraders  = count($rows);
$activeTraders = count(array_filter($rows, fn($r) => $r['status'] === 'active'));
$totalCopies   = count($activeTrades);
$totalInvested = array_sum(array_column($activeTrades, 'invested_amount'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Copy Trading • Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link href="assets/css/admin.css" rel="stylesheet"/>
<style>
:root{
  --accent:#00d4ff;--green:#00e676;--red:#ff3d57;
  --gold:#ffd700;--card:#0e1c30;--border:#1a2e48;
  --text:#e8f4ff;--muted:#5a7a99;--card2:#0a1525;
}
body{font-family:'DM Sans',sans-serif;}
h3,h4,h5,th{font-family:'Syne',sans-serif;}
#sidebar{position:fixed;left:0;top:0;height:100%;width:260px;background:#111;z-index:1000;transition:transform .3s ease;}
#sidebar.hide{transform:translateX(-260px);}
.main-content{margin-left:260px;transition:margin-left .3s ease;}
.main-content.full{margin-left:0;}
#menuToggle{position:fixed;top:15px;left:15px;z-index:1100;background:#000;color:#fff;border:none;padding:10px 14px;border-radius:6px;cursor:pointer;font-size:18px;}
@media(max-width:991px){#sidebar{transform:translateX(-260px);}#sidebar.open{transform:translateX(0);}.main-content{margin-left:0;}}
 
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px 22px;display:flex;align-items:center;gap:16px;}
.stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;}
.stat-label{font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;}
.stat-val{font-size:1.5rem;font-weight:800;color:var(--text);font-family:'Syne',sans-serif;line-height:1.1;}
 
.page-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;}
.page-card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.page-card-header h5{margin:0;font-size:1rem;font-weight:700;color:var(--text);}
 
.admin-tabs{display:flex;gap:4px;background:var(--card2);border-radius:10px;padding:4px;}
.admin-tab{padding:8px 20px;border-radius:8px;border:none;background:transparent;color:var(--muted);font-weight:600;font-size:0.85rem;cursor:pointer;font-family:'Syne',sans-serif;transition:all 0.2s;white-space:nowrap;}
.admin-tab.active{background:var(--card);color:var(--text);box-shadow:0 2px 8px rgba(0,0,0,0.3);}
.admin-tab.active.t-trades{color:var(--red);}
.tab-pane{display:none;}.tab-pane.show{display:block;}
 
.table{--bs-table-bg:transparent;}
.table thead th{color:var(--muted);font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;border-bottom-color:var(--border)!important;font-weight:600;padding:12px 14px;}
.table tbody td{vertical-align:middle;border-color:var(--border);color:var(--text);font-size:0.87rem;padding:10px 14px;}
.table tbody tr:hover{background:rgba(255,255,255,0.02);}
 
.trader-thumb{width:44px;height:44px;border-radius:10px;object-fit:cover;}
.trader-thumb-ph{width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#0a2540,#0e3356);display:inline-flex;align-items:center;justify-content:center;font-size:1.3rem;color:var(--accent);}
.t-name{font-weight:600;font-size:0.9rem;color:var(--text);}
.t-cat{font-size:0.72rem;color:var(--muted);}
 
.badge-status{font-size:0.7rem;font-weight:700;padding:4px 10px;border-radius:20px;font-family:'Syne',sans-serif;letter-spacing:0.3px;}
.badge-active{background:rgba(0,230,118,0.12);color:var(--green);border:1px solid rgba(0,230,118,0.25);}
.badge-inactive{background:rgba(255,61,87,0.1);color:var(--red);border:1px solid rgba(255,61,87,0.2);}
.badge-verified{background:rgba(255,215,0,0.1);color:var(--gold);border:1px solid rgba(255,215,0,0.2);font-size:0.65rem;}
.up{color:#00e676;}.down{color:#ff3d57;}.info{color:#00d4ff;}.gold-text{color:var(--gold);}
 
.btn-edit-row{background:rgba(0,212,255,0.1);border:1px solid rgba(0,212,255,0.25);color:var(--accent);font-size:0.78rem;padding:5px 11px;border-radius:7px;cursor:pointer;transition:background 0.2s;white-space:nowrap;}
.btn-edit-row:hover{background:rgba(0,212,255,0.2);}
.btn-del-row{background:rgba(255,61,87,0.1);border:1px solid rgba(255,61,87,0.25);color:var(--red);font-size:0.78rem;padding:5px 11px;border-radius:7px;cursor:pointer;transition:background 0.2s;}
.btn-del-row:hover{background:rgba(255,61,87,0.2);}
.btn-stop-trade{background:rgba(255,61,87,0.1);border:1px solid rgba(255,61,87,0.3);color:var(--red);font-size:0.78rem;padding:6px 11px;border-radius:7px;cursor:pointer;transition:all 0.2s;font-weight:600;white-space:nowrap;}
.btn-stop-trade:hover{background:rgba(255,61,87,0.22);}
.btn-stop-trade:disabled{opacity:0.45;cursor:not-allowed;}
.btn-add-new{background:linear-gradient(90deg,var(--accent),#0099cc);border:none;color:#000;font-weight:700;font-family:'Syne',sans-serif;padding:10px 20px;border-radius:10px;font-size:0.88rem;cursor:pointer;letter-spacing:0.3px;}
 
/* ── Inline editable cells ── */
.editable-cell{position:relative;cursor:pointer;}
.editable-cell .cell-display{display:flex;align-items:center;gap:5px;}
.editable-cell .edit-icon{opacity:0;font-size:0.7rem;color:var(--gold);transition:opacity 0.15s;}
.editable-cell:hover .edit-icon{opacity:1;}
.editable-cell .cell-input-wrap{display:none;align-items:center;gap:5px;}
.editable-cell .cell-input-wrap input{background:var(--card2);border:1px solid var(--gold);color:var(--text);border-radius:7px;padding:5px 10px;font-size:0.85rem;width:100px;outline:none;font-family:'Syne',sans-serif;}
.editable-cell.editing .cell-display{display:none;}
.editable-cell.editing .cell-input-wrap{display:flex;}
.cell-save-btn{background:var(--green);border:none;color:#000;border-radius:6px;padding:4px 8px;font-size:0.75rem;cursor:pointer;font-weight:700;}
.cell-cancel-btn{background:var(--card2);border:1px solid var(--border);color:var(--muted);border-radius:6px;padding:4px 8px;font-size:0.75rem;cursor:pointer;}
.saving-spinner{font-size:0.75rem;color:var(--gold);animation:spin 0.8s linear infinite;display:none;}
@keyframes spin{to{transform:rotate(360deg);}}
.manual-tag{font-size:0.6rem;color:var(--gold);margin-left:3px;vertical-align:middle;}
 
/* Modal */
.modal-content{background:#0b1929!important;border:1px solid var(--border)!important;border-radius:16px!important;color:var(--text)!important;}
.modal-header{border-bottom:1px solid var(--border)!important;}
.modal-footer{border-top:1px solid var(--border)!important;}
.form-label-sm{font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.4px;margin-bottom:5px;display:block;}
.form-control,.form-select{background:var(--card)!important;border:1px solid var(--border)!important;color:var(--text)!important;border-radius:9px!important;font-size:0.88rem!important;}
.form-control:focus,.form-select:focus{border-color:var(--accent)!important;box-shadow:0 0 0 2px rgba(0,212,255,0.15)!important;}
.img-preview-wrap{margin-top:10px;display:none;position:relative;width:90px;}
.img-preview-wrap img{width:90px;height:90px;object-fit:cover;border-radius:10px;border:2px solid var(--border);}
.img-preview-wrap .clear-img{position:absolute;top:-8px;right:-8px;width:22px;height:22px;background:var(--red);color:#fff;border-radius:50%;font-size:0.65rem;display:flex;align-items:center;justify-content:center;cursor:pointer;border:none;}
.section-divider{font-size:0.68rem;text-transform:uppercase;letter-spacing:1px;color:var(--muted);border-bottom:1px solid var(--border);padding-bottom:5px;margin:18px 0 14px;}
.verified-check{display:flex;align-items:center;gap:10px;}
.verified-check input[type=checkbox]{width:18px;height:18px;accent-color:var(--gold);}
 
/* Stop overlay */
.stop-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.78);z-index:3000;align-items:center;justify-content:center;backdrop-filter:blur(5px);}
.stop-overlay.show{display:flex;}
.stop-box{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:32px 28px;width:94%;max-width:400px;text-align:center;animation:popIn 0.3s cubic-bezier(0.175,0.885,0.32,1.275);}
@keyframes popIn{0%{opacity:0;transform:scale(0.85);}100%{opacity:1;transform:scale(1);}}
.stop-icon{width:68px;height:68px;border-radius:50%;background:rgba(255,61,87,0.12);border:2px solid var(--red);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:1.9rem;margin:0 auto 18px;}
.stop-box h5{font-size:1.1rem;font-weight:700;margin-bottom:8px;}
.stop-box p{color:var(--muted);font-size:0.88rem;margin-bottom:22px;line-height:1.55;}
.stop-trade-detail{background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:20px;text-align:left;}
.stop-trade-detail .sd-row{display:flex;justify-content:space-between;font-size:0.83rem;margin-bottom:5px;}
.stop-trade-detail .sd-row:last-child{margin-bottom:0;}
.stop-trade-detail .sd-label{color:var(--muted);}
.stop-trade-detail .sd-val{font-weight:600;color:var(--text);}
.stop-dual{display:flex;gap:10px;}
.stop-dual button{flex:1;padding:12px;border-radius:11px;border:none;font-weight:700;font-family:'Syne',sans-serif;cursor:pointer;font-size:0.9rem;transition:opacity 0.2s;}
.stop-dual button:disabled{opacity:0.45;cursor:not-allowed;}
.btn-cancel-stop{background:var(--card2);border:1px solid var(--border)!important;color:var(--muted);}
.btn-confirm-stop{background:var(--red);color:#fff;}
.btn-confirm-stop:hover{opacity:0.85;}
 
.admin-toast{position:fixed;top:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;}
.admin-toast-item{background:var(--card);border:1px solid var(--border);color:var(--text);padding:13px 18px;border-radius:12px;font-size:0.88rem;display:flex;align-items:center;gap:10px;min-width:280px;box-shadow:0 4px 20px rgba(0,0,0,0.5);animation:tin .35s ease forwards;}
.admin-toast-item.s{border-left:3px solid var(--green);}
.admin-toast-item.e{border-left:3px solid var(--red);}
@keyframes tin{from{opacity:0;transform:translateX(60px);}to{opacity:1;transform:translateX(0);}}
 
.pulse-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);animation:pd 1.5s infinite;margin-right:4px;}
@keyframes pd{0%,100%{opacity:1;transform:scale(1);}50%{opacity:0.35;transform:scale(1.5);}}
.user-av{width:34px;height:34px;border-radius:8px;background:linear-gradient(135deg,#0a2540,#0e3356);display:inline-flex;align-items:center;justify-content:center;font-size:1rem;color:var(--accent);flex-shrink:0;}
.progress-mini{height:5px;background:rgba(255,255,255,0.07);border-radius:5px;overflow:hidden;margin-top:4px;width:80px;}
.progress-mini-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--green));border-radius:5px;}
</style>
</head>
<body class="bg-dark text-light">
<button id="menuToggle" onclick="toggleSidebar()">☰</button>
 
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/sidebar.php'; ?>
 
<div class="main-content" id="mainContent">
<div class="container-fluid py-4" style="max-width:1400px">
 
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h3 style="font-weight:800;color:var(--text);margin:0">Copy Trading</h3>
      <p style="color:var(--muted);font-size:0.85rem;margin:2px 0 0">Manage traders and monitor all active user copy trades</p>
    </div>
    <button class="btn-add-new" data-bs-toggle="modal" data-bs-target="#traderModal" onclick="openTraderModal()">
      <i class="bi bi-plus-lg me-1"></i> Add New Trader
    </button>
  </div>
 
  <!-- Stats -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(0,212,255,0.1);color:var(--accent)"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-label">Total Traders</div><div class="stat-val"><?= $totalTraders ?></div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(0,230,118,0.1);color:var(--green)"><i class="bi bi-activity"></i></div>
        <div><div class="stat-label">Active Traders</div><div class="stat-val" style="color:var(--green)"><?= $activeTraders ?></div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(255,61,87,0.1);color:var(--red)"><i class="bi bi-graph-up-arrow"></i></div>
        <div><div class="stat-label">Active Copies</div><div class="stat-val" style="color:var(--red)" id="activeCopiesCount"><?= $totalCopies ?></div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(255,215,0,0.1);color:var(--gold)"><i class="bi bi-wallet2"></i></div>
        <div><div class="stat-label">Total Invested</div><div class="stat-val" style="color:var(--gold)">$<?= number_format($totalInvested, 0) ?></div></div>
      </div>
    </div>
  </div>
 
  <!-- Tabbed card -->
  <div class="page-card">
    <div class="page-card-header">
      <div class="admin-tabs">
        <button class="admin-tab active" id="tab-traders-btn" onclick="switchTab('traders')">
          <i class="bi bi-people me-1"></i> Traders
        </button>
        <button class="admin-tab t-trades" id="tab-trades-btn" onclick="switchTab('trades')">
          <i class="bi bi-activity me-1"></i> Active Copy Trades
          <?php if ($totalCopies > 0): ?>
            <span style="background:var(--red);color:#fff;font-size:0.65rem;padding:2px 7px;border-radius:20px;margin-left:5px;font-family:'Syne',sans-serif"><?= $totalCopies ?></span>
          <?php endif; ?>
        </button>
      </div>
      <input type="text" id="adminSearch" placeholder="Search…"
        style="background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:7px 14px;font-size:0.83rem;outline:none;width:180px"
        oninput="filterTable()">
    </div>
 
    <!-- ── TRADERS TAB ── -->
    <div id="tab-traders" class="tab-pane show">
      <div class="table-responsive">
        <table class="table table-dark table-hover mb-0">
          <thead>
            <tr><th>Trader</th><th>Return</th><th>Win %</th><th>Risk</th><th>Duration</th><th>Min Dep.</th><th>Fee</th><th>Followers</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody id="tradersBody">
            <?php foreach ($rows as $r):
              $riskLabel = (int)$r['risk_score'] <= 3 ? 'Low' : ((int)$r['risk_score'] <= 6 ? 'Med' : 'High');
              $riskCls   = (int)$r['risk_score'] <= 3 ? 'up' : ((int)$r['risk_score'] <= 6 ? '' : 'down');
            ?>
            <tr class="admin-row" data-name="<?= strtolower(htmlspecialchars($r['display_name'])) ?> <?= strtolower(htmlspecialchars($r['category'] ?? '')) ?>">
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?php if (!empty($r['profile_image'])): ?>
                    <img src="../<?= htmlspecialchars($r['profile_image']) ?>" class="trader-thumb">
                  <?php else: ?>
                    <div class="trader-thumb-ph"><i class="bi bi-person-circle"></i></div>
                  <?php endif; ?>
                  <div>
                    <div class="t-name"><?= htmlspecialchars($r['display_name']) ?>
                      <?php if ($r['verified']): ?><span class="badge-verified ms-1">✓</span><?php endif; ?>
                    </div>
                    <div class="t-cat"><?= htmlspecialchars($r['category'] ?? '') ?></div>
                  </div>
                </div>
              </td>
              <td><span class="<?= (float)$r['monthly_return'] >= 0 ? 'up' : 'down' ?>"><?= (float)$r['monthly_return'] ?>%</span> <small style="color:var(--muted)">/mo</small></td>
              <td class="info"><?= (float)$r['win_rate'] ?>%</td>
              <td><span class="<?= $riskCls ?>"><?= $riskLabel ?> (<?= (int)$r['risk_score'] ?>)</span></td>
              <td><?= (int)($r['duration_days'] ?? 30) ?>d</td>
              <td>$<?= number_format((float)($r['min_deposit'] ?? 0), 2) ?></td>
              <td>$<?= number_format((float)($r['trading_fee'] ?? 0), 2) ?></td>
              <td><?= number_format((int)$r['followers']) ?></td>
              <td><span class="badge-status badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
              <td>
                <div style="display:flex;gap:6px">
                  <button class="btn-edit-row" data-bs-toggle="modal" data-bs-target="#traderModal" onclick='openTraderModal(<?= json_encode($r) ?>)'>
                    <i class="bi bi-pencil"></i> Edit
                  </button>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Delete this trader?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn-del-row"><i class="bi bi-trash"></i></button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="10" class="text-center py-5" style="color:var(--muted)"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:8px;opacity:0.4"></i>No traders found</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
 
    <!-- ── ACTIVE TRADES TAB ── -->
    <div id="tab-trades" class="tab-pane">
      <div style="padding:10px 22px 8px;font-size:0.78rem;color:var(--muted);display:flex;align-items:center;gap:6px;">
        <i class="bi bi-pencil-fill" style="color:var(--gold)"></i>
        Click any <strong style="color:var(--gold)">Invested</strong> or <strong style="color:var(--green)">Profit</strong> cell to edit it inline. Press <kbd style="background:var(--card2);border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:0.72rem">Enter</kbd> or click ✓ to save.
      </div>
      <div class="table-responsive">
        <table class="table table-dark table-hover mb-0">
          <thead>
            <tr><th>#</th><th>User</th><th>Copying</th><th>Invested <i class="bi bi-pencil-fill" style="color:var(--gold);font-size:0.65rem"></i></th><th>Profit <i class="bi bi-pencil-fill" style="color:var(--gold);font-size:0.65rem"></i></th><th>Progress</th><th>Started</th><th>Fee</th><th>Action</th></tr>
          </thead>
          <tbody id="tradesBody">
            <?php if (empty($activeTrades)): ?>
            <tr><td colspan="9" class="text-center py-5" style="color:var(--muted)"><i class="bi bi-activity" style="font-size:2rem;display:block;margin-bottom:8px;opacity:0.4"></i>No active copy trades</td></tr>
            <?php else: ?>
            <?php foreach ($activeTrades as $i => $at):
              $days_running = max(1, (int)floor((time() - strtotime($at['created_at'])) / 86400));
              $total_days   = (int)($at['duration_days'] ?? 30);
              $pct          = min(100, round(($days_running / max(1,$total_days)) * 100));
              $auto_profit  = round((float)$at['invested_amount'] * ((float)$at['monthly_return'] / 100) * ($days_running / 30), 2);
              $show_profit  = ($at['manual_profit'] !== null && $at['manual_profit'] >= 0) ? (float)$at['manual_profit'] : $auto_profit;
              $is_manual    = ($at['manual_profit'] !== null && $at['manual_profit'] >= 0);
              $uname        = htmlspecialchars($at['user_name'] ?? 'User #'.$at['user_id']);
              $uemail       = htmlspecialchars($at['user_email'] ?? '');
              $tname        = htmlspecialchars($at['trader_name']);
              $timg         = !empty($at['trader_img']) ? htmlspecialchars($at['trader_img']) : '';
              $inv_amt      = (float)$at['invested_amount'];
            ?>
            <tr class="trades-row" id="trade-row-<?= (int)$at['trade_id'] ?>"
                data-search="<?= strtolower($uname.' '.$uemail.' '.$tname) ?>">
              <td style="color:var(--muted);font-size:0.8rem"><?= $i+1 ?></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="user-av"><i class="bi bi-person"></i></div>
                  <div>
                    <div style="font-weight:600;font-size:0.88rem"><?= $uname ?></div>
                    <div style="font-size:0.72rem;color:var(--muted)"><?= $uemail ?></div>
                  </div>
                </div>
              </td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?php if ($timg): ?>
                    <img src="../<?= $timg ?>" style="width:34px;height:34px;border-radius:8px;object-fit:cover">
                  <?php else: ?>
                    <div class="user-av"><i class="bi bi-graph-up"></i></div>
                  <?php endif; ?>
                  <div>
                    <div style="font-weight:600;font-size:0.88rem"><?= $tname ?></div>
                    <div style="font-size:0.72rem;color:var(--green)"><?= (float)$at['monthly_return'] ?>% / mo</div>
                  </div>
                </div>
              </td>
 
              <!-- EDITABLE: Invested Amount -->
              <td class="editable-cell" id="inv-cell-<?= (int)$at['trade_id'] ?>"
                  data-trade-id="<?= (int)$at['trade_id'] ?>" data-field="invested_amount"
                  data-value="<?= $inv_amt ?>" title="Click to edit">
                <div class="cell-display">
                  <span class="cell-text" style="font-weight:700;color:var(--text)">$<?= number_format($inv_amt, 2) ?></span>
                  <i class="bi bi-pencil edit-icon"></i>
                </div>
                <div class="cell-input-wrap">
                  <input type="number" class="cell-input" step="0.01" min="0" value="<?= $inv_amt ?>">
                  <button class="cell-save-btn" title="Save">✓</button>
                  <button class="cell-cancel-btn" title="Cancel">✕</button>
                  <i class="bi bi-arrow-repeat saving-spinner"></i>
                </div>
              </td>
 
              <!-- EDITABLE: Profit -->
              <td class="editable-cell" id="profit-cell-<?= (int)$at['trade_id'] ?>"
                  data-trade-id="<?= (int)$at['trade_id'] ?>" data-field="manual_profit"
                  data-value="<?= $show_profit ?>" title="Click to edit">
                <div class="cell-display">
                  <span class="cell-text" style="font-weight:700;color:var(--green)">+$<?= number_format($show_profit, 2) ?></span>
                  <?php if ($is_manual): ?><span class="manual-tag" title="Manually set"><i class="bi bi-pencil-fill"></i></span><?php endif; ?>
                  <i class="bi bi-pencil edit-icon"></i>
                </div>
                <div class="cell-input-wrap">
                  <input type="number" class="cell-input" step="0.01" min="0" value="<?= $show_profit ?>">
                  <button class="cell-save-btn" title="Save">✓</button>
                  <button class="cell-cancel-btn" title="Cancel">✕</button>
                  <i class="bi bi-arrow-repeat saving-spinner"></i>
                </div>
              </td>
 
              <td>
                <div style="font-size:0.75rem;color:var(--muted);margin-bottom:3px"><?= $days_running ?>/<?= $total_days ?>d &nbsp;<?= $pct ?>%</div>
                <div class="progress-mini"><div class="progress-mini-fill" style="width:<?= $pct ?>%"></div></div>
              </td>
              <td style="font-size:0.82rem;color:var(--muted)"><?= date('M d, Y', strtotime($at['created_at'])) ?></td>
              <td style="color:var(--gold)">$<?= number_format((float)$at['trading_fee'], 2) ?></td>
              <td>
                <button class="btn-stop-trade"
                  onclick="openStopModal(<?= (int)$at['trade_id'] ?>, '<?= addslashes($uname) ?>', '<?= addslashes($uemail) ?>', '<?= addslashes($tname) ?>', <?= $inv_amt ?>)">
                  <i class="bi bi-stop-circle me-1"></i> Stop
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div><!-- /page-card -->
</div>
</div>
 
<div class="modal fade" id="traderModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="traderForm" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title" id="modalTitle">Add Trader</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="traderId">
          <input type="hidden" name="existing_image" id="existing_image">

          <div class="section-divider">Profile Image</div>
          <div class="mb-3">
            <label class="form-label-sm">Upload Image</label>
            <input type="file" class="form-control" name="profile_image_file" id="imgFile" accept="image/*" onchange="previewImg(this)">
            <div class="img-preview-wrap" id="imgPreviewWrap">
              <img id="imgPreview" src="" alt="Preview">
              <button type="button" class="clear-img" onclick="clearImg()">✕</button>
            </div>
          </div>

          <div class="section-divider">Basic Information</div>
          <div class="row g-3 mb-2">
            <div class="col-md-6">
              <label class="form-label-sm">Display Name *</label>
              <input type="text" class="form-control" name="display_name" id="display_name" required placeholder="e.g. Alexander Crypto">
            </div>
            <div class="col-md-6">
              <label class="form-label-sm">Category</label>
              <input type="text" class="form-control" name="category" id="category" placeholder="e.g. Crypto, Forex, Stocks">
            </div>
          </div>

          <div class="section-divider">Performance Metrics</div>
          <div class="row g-3 mb-2">
            <div class="col-md-4">
              <label class="form-label-sm">Monthly Return %</label>
              <input type="number" step="0.01" class="form-control" name="monthly_return" id="monthly_return" placeholder="0.00" required>
            </div>
            <div class="col-md-4">
              <label class="form-label-sm">Win Rate %</label>
              <input type="number" step="0.01" class="form-control" name="win_rate" id="win_rate" max="100" placeholder="0.00">
            </div>
            <div class="col-md-4">
              <label class="form-label-sm">Risk Score (1–10)</label>
              <input type="number" class="form-control" name="risk_score" id="risk_score" min="1" max="10" placeholder="5">
            </div>
          </div>

          <div class="section-divider">Trade Configuration (Flexible)</div>
          <div class="row g-3 mb-2">
            <div class="col-md-4">
              <label class="form-label-sm">Duration (Days)</label>
              <input type="number" class="form-control" name="duration_days" id="duration_days" min="1" placeholder="30" required>
            </div>
            <div class="col-md-4">
              <label class="form-label-sm">Min Deposit ($)</label>
              <input type="number" step="0.01" class="form-control" name="min_deposit" id="min_deposit" min="0" placeholder="500.00" required>
            </div>
            <div class="col-md-4">
              <label class="form-label-sm">Trading Fee ($)</label>
              <input type="number" step="0.01" class="form-control" name="trading_fee" id="trading_fee" min="0" placeholder="10.00">
            </div>
          </div>

          <div class="section-divider">Social & Influence</div>
          <div class="row g-3 mb-2">
            <div class="col-md-6">
              <label class="form-label-sm">Followers Count</label>
              <input type="number" class="form-control" name="followers" id="followers" placeholder="1250">
            </div>
            <div class="col-md-6">
              <label class="form-label-sm">Verified Profile</label>
              <div class="verified-check" style="margin-top: 8px;">
                <input type="checkbox" name="verified" id="verified" style="cursor: pointer;">
                <label for="verified" style="font-size:0.85rem;color:var(--gold);cursor:pointer; margin-left: 8px;">
                  <i class="bi bi-patch-check-fill me-1"></i> Apply Verified Badge
                </label>
              </div>
            </div>
          </div>

          <div class="section-divider">Visibility</div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label-sm">Status</label>
              <select class="form-select" name="status" id="status">
                <option value="active">Active (Visible to Users)</option>
                <option value="inactive">Inactive (Hidden)</option>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer" style="background: var(--card2);">
          <button type="button" class="btn btn-secondary btn-sm" style="border-radius: 8px;" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-add-new" style="padding:9px 28px">
            <i class="bi bi-shield-check me-1"></i> Confirm & Save
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
 
<!-- ════ STOP CONFIRM OVERLAY ════ -->
<div class="stop-overlay" id="stopOverlay">
  <div class="stop-box">
    <div class="stop-icon"><i class="bi bi-stop-circle"></i></div>
    <h5>Stop This Copy Trade?</h5>
    <p>The invested amount will be automatically refunded to the user's wallet. This cannot be undone.</p>
    <div class="stop-trade-detail" id="stopDetail">
      <div class="sd-row"><span class="sd-label">User</span><span class="sd-val" id="sd_user">—</span></div>
      <div class="sd-row"><span class="sd-label">Email</span><span class="sd-val" id="sd_email" style="font-size:0.8rem;color:var(--muted)">—</span></div>
      <div class="sd-row"><span class="sd-label">Copying</span><span class="sd-val" id="sd_trader">—</span></div>
      <div class="sd-row"><span class="sd-label">Invested Amount</span><span class="sd-val" id="sd_amount" style="color:var(--green)">—</span></div>
    </div>
    <input type="hidden" id="stop_trade_id">
    <div class="stop-dual">
      <button class="btn-cancel-stop" onclick="closeStopModal()">Cancel</button>
      <button class="btn-confirm-stop" id="confirmStopBtn" onclick="confirmStop()"><i class="bi bi-stop-circle me-1"></i> Yes, Stop Trade</button>
    </div>
  </div>
</div>
 
<div class="admin-toast" id="adminToast"></div>
 
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const fmt = n => '$' + parseFloat(n).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
 
// ── Sidebar ──
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('hide');
  document.getElementById('mainContent').classList.toggle('full');
}
 
// ── Tab switching ──
function switchTab(tab) {
  document.getElementById('tab-traders').classList.toggle('show', tab === 'traders');
  document.getElementById('tab-trades').classList.toggle('show',  tab === 'trades');
  document.getElementById('tab-traders-btn').classList.toggle('active', tab === 'traders');
  document.getElementById('tab-trades-btn').classList.toggle('active',  tab === 'trades');
  filterTable();
}
 
// ── Search ──
function filterTable() {
  const term = document.getElementById('adminSearch').value.toLowerCase();
  const tradersVisible = document.getElementById('tab-traders').classList.contains('show');
  if (tradersVisible) {
    document.querySelectorAll('.admin-row').forEach(r => { r.style.display = r.dataset.name.includes(term) ? '' : 'none'; });
  } else {
    document.querySelectorAll('.trades-row').forEach(r => { r.style.display = r.dataset.search.includes(term) ? '' : 'none'; });
  }
}
 
// ── Inline editable cells ──
document.querySelectorAll('.editable-cell').forEach(cell => {
  const display   = cell.querySelector('.cell-display');
  const inputWrap = cell.querySelector('.cell-input-wrap');
  const input     = cell.querySelector('.cell-input');
  const saveBtn   = cell.querySelector('.cell-save-btn');
  const cancelBtn = cell.querySelector('.cell-cancel-btn');
  const spinner   = cell.querySelector('.saving-spinner');
 
  // click cell to edit
  display.addEventListener('click', () => {
    input.value = cell.dataset.value;
    cell.classList.add('editing');
    input.focus(); input.select();
  });
 
  // Enter to save, Escape to cancel
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter')  { e.preventDefault(); doSave(); }
    if (e.key === 'Escape') { doCancel(); }
  });
 
  cancelBtn.addEventListener('click', doCancel);
  saveBtn.addEventListener('click', doSave);
 
  function doCancel() {
    input.value = cell.dataset.value;
    cell.classList.remove('editing');
  }
 
  async function doSave() {
    const newVal   = parseFloat(input.value);
    if (isNaN(newVal) || newVal < 0) { aToast('Value must be 0 or greater.', 'e'); return; }
    const tradeId  = cell.dataset.tradeId;
    const field    = cell.dataset.field;
 
    // Determine the partner cell's current value
    const isInvested = field === 'invested_amount';
    const invCell    = document.getElementById(`inv-cell-${tradeId}`);
    const profCell   = document.getElementById(`profit-cell-${tradeId}`);
    const invVal     = isInvested ? newVal    : parseFloat(invCell.dataset.value);
    const profVal    = isInvested ? parseFloat(profCell.dataset.value) : newVal;
 
    spinner.style.display = 'inline-block';
    saveBtn.style.display = cancelBtn.style.display = 'none';
 
    try {
      const res  = await fetch('', {
        method:  'POST',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body:    JSON.stringify({ action:'edit_trade', trade_id: parseInt(tradeId), invested_amount: invVal, manual_profit: profVal })
      });
      const data = await res.json();
      if (data.success) {
        // Update invested cell
        invCell.dataset.value = data.invested_amount;
        invCell.querySelector('.cell-text').textContent = fmt(data.invested_amount);
        invCell.querySelector('.cell-input').value = data.invested_amount;
        // Update profit cell
        profCell.dataset.value = data.manual_profit;
        const profDisplay = profCell.querySelector('.cell-text');
        profDisplay.textContent = '+' + fmt(data.manual_profit);
        // ensure manual tag shows
        let tag = profCell.querySelector('.manual-tag');
        if (!tag) {
          tag = document.createElement('span');
          tag.className = 'manual-tag'; tag.title = 'Manually set';
          tag.innerHTML = '<i class="bi bi-pencil-fill"></i>';
          profCell.querySelector('.cell-display').insertBefore(tag, profCell.querySelector('.edit-icon'));
        }
        aToast('Trade updated successfully.', 's');
      } else {
        aToast(data.message || 'Update failed.', 'e');
      }
    } catch(e) { aToast('Connection error.', 'e'); }
 
    spinner.style.display = 'none';
    saveBtn.style.display = cancelBtn.style.display = '';
    cell.classList.remove('editing');
  }
});
// ── Unified Trader Modal Handler ──
function openTraderModal(data = null) {
    const form = document.getElementById('traderForm');
    const title = document.getElementById('modalTitle');
    const wrap = document.getElementById('imgPreviewWrap');
    const imgPreview = document.getElementById('imgPreview');
    
    // Reset form and clear specific IDs to ensure a clean slate
    form.reset();
    document.getElementById('traderId').value = "";
    wrap.style.display = 'none';

    if (data) {
        // SET TITLE
        title.innerText = "Edit Trader: " + (data.display_name || "Untitled");
        
        // MAPPING CORE FIELDS
        // This array matches the ID in your HTML to the key in your Database (data)
        const fields = [
            'traderId', 'display_name', 'category', 'monthly_return', 
            'annual_return', 'risk_score', 'followers', 'win_rate', 
            'managed_assets', 'min_deposit', 'duration_days', 'trading_fee'
        ];

        fields.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                // If the ID in HTML is 'traderId', look for 'id' in the database object
                const dataKey = (id === 'traderId') ? 'id' : id;
                element.value = data[dataKey] ?? '';
            }
        });

        // STATUS & VERIFIED TOGGLES
        document.getElementById('status').value = data.status ?? 'active';
        document.getElementById('verified').checked = (data.verified == 1);
        
        // IMAGE HANDLING
        document.getElementById('existing_image').value = data.profile_image ?? '';
        if (data.profile_image) {
            imgPreview.src = '../' + data.profile_image;
            wrap.style.display = 'block';
        }

    } else {
        // ADD NEW TRADER MODE
        title.innerText = "Add New Trader";
    }
}

// ── Image Preview Handler ──
function previewImg(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { 
            document.getElementById('imgPreview').src = e.target.result; 
            document.getElementById('imgPreviewWrap').style.display = 'block'; 
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// ── Clear Image Handler ──
function clearImg() {
    document.getElementById('imgFile').value = '';
    document.getElementById('imgPreview').src = '';
    document.getElementById('existing_image').value = '';
    document.getElementById('imgPreviewWrap').style.display = 'none';
}
 
// ── Stop modal ──
function openStopModal(tradeId, userName, userEmail, traderName, investedAmt) {
  document.getElementById('stop_trade_id').value = tradeId;
  document.getElementById('sd_user').textContent    = userName;
  document.getElementById('sd_email').textContent   = userEmail;
  document.getElementById('sd_trader').textContent  = traderName;
  document.getElementById('sd_amount').textContent  = fmt(investedAmt);
  document.getElementById('stopOverlay').classList.add('show');
}
function closeStopModal() { document.getElementById('stopOverlay').classList.remove('show'); }
document.getElementById('stopOverlay').addEventListener('click', e => { if (e.target === document.getElementById('stopOverlay')) closeStopModal(); });
 
async function confirmStop() {
  const tradeId = document.getElementById('stop_trade_id').value;
  const btn = document.getElementById('confirmStopBtn');
  btn.disabled=true; btn.innerHTML='<i class="bi bi-hourglass-split me-1"></i> Stopping…';
  try {
    const res  = await fetch('', { method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body: JSON.stringify({ trade_id: parseInt(tradeId) }) });
    const data = await res.json();
    closeStopModal();
    if (data.success) {
      aToast(data.message, 's');
      const row = document.getElementById(`trade-row-${tradeId}`);
      if (row) { row.style.transition='opacity 0.4s'; row.style.opacity='0'; setTimeout(()=>row.remove(), 420); }
      const cnt = document.getElementById('activeCopiesCount');
      if (cnt) cnt.textContent = Math.max(0, parseInt(cnt.textContent)-1);
    } else { aToast(data.message || 'Failed.', 'e'); }
  } catch(e) { closeStopModal(); aToast('Connection error.', 'e'); }
  btn.disabled=false; btn.innerHTML='<i class="bi bi-stop-circle me-1"></i> Yes, Stop Trade';
}
 
// ── Toast ──
function aToast(msg, type='s') {
  const wrap = document.getElementById('adminToast');
  const el   = document.createElement('div');
  el.className = `admin-toast-item ${type}`;
  el.innerHTML = `<i class="bi bi-${type==='s'?'check-circle':'exclamation-circle'}" style="font-size:1.1rem;flex-shrink:0"></i> <span>${msg}</span>`;
  wrap.appendChild(el);
  setTimeout(()=>{ el.style.transition='opacity 0.4s'; el.style.opacity='0'; setTimeout(()=>el.remove(),420); }, 3500);
}
</script>
</body>
</html>