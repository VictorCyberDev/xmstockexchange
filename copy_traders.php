<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db.php';

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed.");
}

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// ── AJAX handler ──
$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true);
    if (!$input) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }

    $action = $input['action'] ?? '';

    // ── START / COPY TRADE ──
    if ($action === 'start_trade') {
        $leader_id = intval($input['leader_id'] ?? 0);
        $amount    = floatval($input['amount'] ?? 0);

        if (!$leader_id || $amount <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid data']); exit; }

        // Fetch trader + user balance
        $tq = $conn->prepare("SELECT id, display_name, min_deposit, trading_fee, duration_days, monthly_return, status FROM copy_traders WHERE id=? AND status='active'");
        $tq->bind_param("i", $leader_id); $tq->execute();
        $trader = $tq->get_result()->fetch_assoc();
        if (!$trader) { echo json_encode(['success'=>false,'message'=>'Trader not found or inactive']); exit; }

        if ($amount < (float)$trader['min_deposit']) {
            echo json_encode(['success'=>false,'message'=>'Amount below minimum deposit of $'.number_format($trader['min_deposit'],2)]); exit;
        }

        $uq = $conn->prepare("SELECT balance FROM users WHERE id=?");
        $uq->bind_param("i", $user_id); $uq->execute();
        $user = $uq->get_result()->fetch_assoc();
        $balance = (float)$user['balance'];
        $fee = (float)$trader['trading_fee'];
        $totalDeduct = $amount + $fee;

        if ($balance < $totalDeduct) {
            echo json_encode(['success'=>false,'message'=>'Insufficient balance. Need $'.number_format($totalDeduct,2).' (including $'.number_format($fee,2).' fee)']); exit;
        }

        // Check if already copying this trader
        $cq = $conn->prepare("SELECT id FROM copy_trades WHERE user_id=? AND leader_id=? AND status='active'");
        $cq->bind_param("ii", $user_id, $leader_id); $cq->execute();
        if ($cq->get_result()->fetch_assoc()) {
            echo json_encode(['success'=>false,'message'=>'You are already copying this trader']); exit;
        }

        // Deduct balance
        $dq = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id=?");
        $dq->bind_param("di", $totalDeduct, $user_id); $dq->execute();

        // Insert trade
        $iq = $conn->prepare("INSERT INTO copy_trades (user_id, leader_id, invested_amount, trading_fee, status, created_at) VALUES (?,?,?,?,'active',NOW())");
        $iq->bind_param("iidd", $user_id, $leader_id, $amount, $fee); $iq->execute();

        $new_balance = $balance - $totalDeduct;
        echo json_encode(['success'=>true,'message'=>'Copy trade started!','new_balance'=>$new_balance,'trade_id'=>$conn->insert_id]);
        exit;
    }

    // ── ADD FUNDS TO LIVE TRADE ──
    if ($action === 'add_funds') {
        $trade_id = intval($input['trade_id'] ?? 0);
        $amount   = floatval($input['amount'] ?? 0);
        if (!$trade_id || $amount <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid data']); exit; }

        $tq = $conn->prepare("SELECT ct.id, ct.invested_amount FROM copy_trades ct WHERE ct.id=? AND ct.user_id=? AND ct.status='active'");
        $tq->bind_param("ii", $trade_id, $user_id); $tq->execute();
        $trade = $tq->get_result()->fetch_assoc();
        if (!$trade) { echo json_encode(['success'=>false,'message'=>'Trade not found']); exit; }

        $uq = $conn->prepare("SELECT balance FROM users WHERE id=?");
        $uq->bind_param("i", $user_id); $uq->execute();
        $user = $uq->get_result()->fetch_assoc();
        if ((float)$user['balance'] < $amount) { echo json_encode(['success'=>false,'message'=>'Insufficient balance']); exit; }

        // Deduct from balance, add to trade
        $conn->prepare("UPDATE users SET balance = balance - ? WHERE id=?")->bind_param("di", $amount, $user_id) && $conn->prepare("UPDATE users SET balance = balance - ? WHERE id=?")->execute();
        $dq = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id=?");
        $dq->bind_param("di", $amount, $user_id); $dq->execute();

        $aq = $conn->prepare("UPDATE copy_trades SET invested_amount = invested_amount + ? WHERE id=?");
        $aq->bind_param("di", $amount, $trade_id); $aq->execute();

        $new_invested = (float)$trade['invested_amount'] + $amount;
        $new_balance  = (float)$user['balance'] - $amount;
        echo json_encode(['success'=>true,'message'=>'Funds added!','new_invested'=>$new_invested,'new_balance'=>$new_balance]);
        exit;
    }

    // ── WITHDRAW PROFIT ONLY ──
    if ($action === 'withdraw_profit') {
        $trade_id = intval($input['trade_id'] ?? 0);
        if (!$trade_id) { echo json_encode(['success'=>false,'message'=>'Invalid trade']); exit; }

        $tq = $conn->prepare("
            SELECT ct.id, ct.invested_amount, ct.manual_profit, ct.created_at,
                   tr.monthly_return, tr.duration_days
            FROM copy_trades ct
            JOIN copy_traders tr ON ct.leader_id = tr.id
            WHERE ct.id=? AND ct.user_id=? AND ct.status='active'
        ");
        $tq->bind_param("ii", $trade_id, $user_id); $tq->execute();
        $trade = $tq->get_result()->fetch_assoc();
        if (!$trade) { echo json_encode(['success'=>false,'message'=>'Trade not found']); exit; }

        $days_running = max(1, (int)floor((time() - strtotime($trade['created_at'])) / 86400));
        $auto_profit  = round((float)$trade['invested_amount'] * ((float)$trade['monthly_return'] / 100) * ($days_running / 30), 2);
        $profit = ($trade['manual_profit'] !== null && $trade['manual_profit'] >= 0) ? (float)$trade['manual_profit'] : $auto_profit;

        if ($profit <= 0) { echo json_encode(['success'=>false,'message'=>'No profit available to withdraw']); exit; }

        // Credit profit to balance, reset manual_profit to 0
        $cq = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id=?");
        $cq->bind_param("di", $profit, $user_id); $cq->execute();

        $rq = $conn->prepare("UPDATE copy_trades SET manual_profit = 0 WHERE id=?");
        $rq->bind_param("i", $trade_id); $rq->execute();

        $uq = $conn->prepare("SELECT balance FROM users WHERE id=?");
        $uq->bind_param("i", $user_id); $uq->execute();
        $new_balance = (float)$uq->get_result()->fetch_assoc()['balance'];

        echo json_encode(['success'=>true,'message'=>'$'.number_format($profit,2).' profit withdrawn!','profit_withdrawn'=>$profit,'new_balance'=>$new_balance]);
        exit;
    }

    // ── STOP TRADE + WITHDRAW ALL ──
    if ($action === 'stop_and_withdraw') {
        $trade_id = intval($input['trade_id'] ?? 0);
        if (!$trade_id) { echo json_encode(['success'=>false,'message'=>'Invalid trade']); exit; }

        $tq = $conn->prepare("
            SELECT ct.id, ct.invested_amount, ct.manual_profit, ct.created_at,
                   tr.monthly_return, tr.duration_days
            FROM copy_trades ct
            JOIN copy_traders tr ON ct.leader_id = tr.id
            WHERE ct.id=? AND ct.user_id=? AND ct.status='active'
        ");
        $tq->bind_param("ii", $trade_id, $user_id); $tq->execute();
        $trade = $tq->get_result()->fetch_assoc();
        if (!$trade) { echo json_encode(['success'=>false,'message'=>'Trade not found']); exit; }

        $invested = (float)$trade['invested_amount'];
        $days_running = max(1, (int)floor((time() - strtotime($trade['created_at'])) / 86400));
        $auto_profit  = round($invested * ((float)$trade['monthly_return'] / 100) * ($days_running / 30), 2);
        $profit = ($trade['manual_profit'] !== null && $trade['manual_profit'] >= 0) ? (float)$trade['manual_profit'] : $auto_profit;

        $total_return = $invested + $profit;

        // Credit back everything
        $cq = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id=?");
        $cq->bind_param("di", $total_return, $user_id); $cq->execute();

        // Stop trade
        $sq = $conn->prepare("UPDATE copy_trades SET status='stopped', stopped_at=NOW() WHERE id=?");
        $sq->bind_param("i", $trade_id); $sq->execute();

        $uq = $conn->prepare("SELECT balance FROM users WHERE id=?");
        $uq->bind_param("i", $user_id); $uq->execute();
        $new_balance = (float)$uq->get_result()->fetch_assoc()['balance'];

        echo json_encode([
            'success'      => true,
            'message'      => 'Trade stopped. $'.number_format($total_return,2).' returned to wallet.',
            'total_return' => $total_return,
            'new_balance'  => $new_balance
        ]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// ── Fetch page data ──
$uq = $conn->prepare("SELECT name, email, balance FROM users WHERE id=?");
$uq->bind_param("i", $user_id); $uq->execute();
$user = $uq->get_result()->fetch_assoc();
$balance = (float)$user['balance'];

// Active traders list
$traders = [];
$trq = $conn->query("SELECT * FROM copy_traders WHERE status='active' ORDER BY monthly_return DESC LIMIT 50");
if ($trq) while ($r = $trq->fetch_assoc()) $traders[] = $r;

// User's active copy trades
$myTrades = [];
$mq = $conn->prepare("
    SELECT ct.id AS trade_id, ct.invested_amount, ct.manual_profit, ct.trading_fee,
           ct.created_at, ct.status,
           tr.id AS trader_id, tr.display_name, tr.profile_image,
           tr.monthly_return, tr.win_rate, tr.risk_score, tr.duration_days, tr.category
    FROM copy_trades ct
    JOIN copy_traders tr ON ct.leader_id = tr.id
    WHERE ct.user_id=? AND ct.status='active'
    ORDER BY ct.created_at DESC
");
$mq->bind_param("i", $user_id); $mq->execute();
$myTrades = $mq->get_result()->fetch_all(MYSQLI_ASSOC);

// Recent stopped trades
$stoppedTrades = [];
$sq = $conn->prepare("
    SELECT ct.id AS trade_id, ct.invested_amount, ct.manual_profit, ct.trading_fee,
           ct.created_at, ct.stopped_at,
           tr.display_name, tr.profile_image, tr.monthly_return
    FROM copy_trades ct
    JOIN copy_traders tr ON ct.leader_id = tr.id
    WHERE ct.user_id=? AND ct.status='stopped'
    ORDER BY ct.stopped_at DESC LIMIT 10
");
$sq->bind_param("i", $user_id); $sq->execute();
$stoppedTrades = $sq->get_result()->fetch_all(MYSQLI_ASSOC);

$totalInvested = array_sum(array_column($myTrades, 'invested_amount'));
$totalProfit   = 0;
foreach ($myTrades as $t) {
    $days = max(1, (int)floor((time() - strtotime($t['created_at'])) / 86400));
    $ap   = round((float)$t['invested_amount'] * ((float)$t['monthly_return'] / 100) * ($days / 30), 2);
    $totalProfit += ($t['manual_profit'] !== null && $t['manual_profit'] >= 0) ? (float)$t['manual_profit'] : $ap;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Copy Trading • SwiftTrade</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#060d18; --bg2:#091322; --card:#0c1a2e; --card2:#091220;
  --border:#162035; --border2:#1c2d48;
  --accent:#00d4ff; --green:#00e676; --red:#ff3d57;
  --gold:#ffd700; --purple:#a78bfa; --orange:#ff9500;
  --text:#e8f4ff; --muted:#4a6a8a; --muted2:#6b8aaa;
}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh;overflow-x:hidden;}

/* ── Noise overlay ── */
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");pointer-events:none;z-index:0;opacity:.4;}

h2,h3,h4,h5,h6,.label,.btn-action{font-family:'Syne',sans-serif;}

/* ── Layout ── */
.ct-wrap{position:relative;z-index:1;max-width:1380px;margin:0 auto;padding:28px 20px 60px;}

/* ── Header bar ── */
.ct-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:36px;flex-wrap:wrap;gap:16px;}
.ct-logo{font-family:'Syne',sans-serif;font-size:1.05rem;font-weight:800;letter-spacing:-0.5px;color:var(--text);}
.ct-logo span{color:var(--accent);}
.wallet-pill{background:var(--card);border:1px solid var(--border2);border-radius:50px;padding:10px 20px;display:flex;align-items:center;gap:10px;font-size:0.88rem;}
.wallet-pill .bal{font-family:'Syne',sans-serif;font-weight:700;color:var(--green);font-size:1.05rem;}
.wallet-icon{width:34px;height:34px;border-radius:50%;background:rgba(0,230,118,0.1);border:1px solid rgba(0,230,118,0.2);display:flex;align-items:center;justify-content:center;color:var(--green);}

/* ── Stats strip ── */
.stats-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:36px;}
@media(max-width:767px){.stats-strip{grid-template-columns:repeat(2,1fr);}}
.stat-block{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;position:relative;overflow:hidden;}
.stat-block::before{content:'';position:absolute;top:-30px;right:-30px;width:90px;height:90px;border-radius:50%;filter:blur(35px);opacity:0.15;}
.stat-block.s-blue::before{background:var(--accent);}
.stat-block.s-green::before{background:var(--green);}
.stat-block.s-gold::before{background:var(--gold);}
.stat-block.s-purple::before{background:var(--purple);}
.stat-label{font-size:0.68rem;text-transform:uppercase;letter-spacing:0.8px;color:var(--muted2);margin-bottom:6px;}
.stat-val{font-family:'Syne',sans-serif;font-size:1.55rem;font-weight:800;line-height:1;}
.stat-sub{font-size:0.72rem;color:var(--muted);margin-top:5px;}

/* ── Section titles ── */
.section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.section-title{font-size:1.1rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:8px;}
.section-title .dot{width:8px;height:8px;border-radius:50%;background:var(--accent);display:inline-block;}

/* ── Trader cards ── */
.traders-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:18px;margin-bottom:48px;}
.trader-card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden;transition:transform 0.25s,border-color 0.25s,box-shadow 0.25s;cursor:pointer;}
.trader-card:hover{transform:translateY(-4px);border-color:var(--border2);box-shadow:0 12px 40px rgba(0,0,0,0.4);}
.tc-banner{height:7px;background:linear-gradient(90deg,var(--accent),var(--green));}
.tc-banner.low{background:linear-gradient(90deg,#00e676,#00bcd4);}
.tc-banner.med{background:linear-gradient(90deg,#ffd700,#ff9500);}
.tc-banner.high{background:linear-gradient(90deg,#ff9500,#ff3d57);}
.tc-body{padding:18px;}
.tc-head{display:flex;align-items:center;gap:12px;margin-bottom:14px;}
.tc-avatar{width:50px;height:50px;border-radius:13px;object-fit:cover;flex-shrink:0;}
.tc-avatar-ph{width:50px;height:50px;border-radius:13px;background:linear-gradient(135deg,#0a2540,#0e3356);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:var(--accent);flex-shrink:0;}
.tc-name{font-weight:700;font-size:0.95rem;color:var(--text);line-height:1.2;}
.tc-cat{font-size:0.72rem;color:var(--muted2);margin-top:2px;}
.badge-ver{font-size:0.6rem;background:rgba(255,215,0,0.12);color:var(--gold);border:1px solid rgba(255,215,0,0.25);border-radius:20px;padding:2px 7px;font-family:'Syne',sans-serif;font-weight:700;letter-spacing:0.3px;vertical-align:middle;}
.tc-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px;}
.tc-metric{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:9px 8px;text-align:center;}
.tc-metric-val{font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;line-height:1;}
.tc-metric-label{font-size:0.62rem;color:var(--muted);margin-top:3px;text-transform:uppercase;letter-spacing:0.4px;}
.tc-info-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-top:1px solid var(--border);}
.tc-info-item{font-size:0.75rem;text-align:center;}
.tc-info-item .val{font-weight:700;color:var(--text);font-family:'Syne',sans-serif;}
.tc-info-item .lbl{color:var(--muted);font-size:0.67rem;display:block;margin-top:1px;}
.btn-copy{width:100%;margin-top:14px;padding:12px;border-radius:12px;border:none;background:linear-gradient(90deg,var(--accent),#0099cc);color:#000;font-weight:800;font-family:'Syne',sans-serif;font-size:0.88rem;cursor:pointer;letter-spacing:0.3px;transition:opacity 0.2s,transform 0.15s;}
.btn-copy:hover{opacity:0.88;transform:scale(0.985);}
.btn-copy:disabled{background:var(--card2);color:var(--muted);cursor:not-allowed;transform:none;}
.already-copying{background:rgba(0,230,118,0.08);border:1px solid rgba(0,230,118,0.2);border-radius:10px;padding:8px 12px;font-size:0.78rem;color:var(--green);text-align:center;font-weight:600;margin-top:8px;display:flex;align-items:center;justify-content:center;gap:6px;}

/* ── My Trades ── */
.my-trades-list{display:flex;flex-direction:column;gap:16px;margin-bottom:48px;}
.trade-card{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden;transition:border-color 0.2s;}
.trade-card:hover{border-color:var(--border2);}
.trade-card-top{padding:18px 20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.tc-trader-info{display:flex;align-items:center;gap:10px;flex:1;min-width:200px;}
.tc-trader-img{width:44px;height:44px;border-radius:10px;object-fit:cover;}
.tc-trader-img-ph{width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#0a2540,#0e3356);display:flex;align-items:center;justify-content:center;color:var(--accent);}
.live-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(0,230,118,0.08);border:1px solid rgba(0,230,118,0.2);border-radius:20px;padding:3px 10px;font-size:0.68rem;font-weight:700;color:var(--green);font-family:'Syne',sans-serif;letter-spacing:0.3px;}
.pulse{width:6px;height:6px;border-radius:50%;background:var(--green);animation:pulse 1.4s infinite;}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:0.3;transform:scale(1.6);}}
.trade-amounts{display:flex;gap:20px;flex-wrap:wrap;align-items:center;}
.amount-block{text-align:right;}
.amount-block .amt{font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem;}
.amount-block .lbl{font-size:0.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.4px;}
.trade-card-body{padding:0 20px 20px;}
.progress-bar-wrap{background:rgba(255,255,255,0.05);border-radius:50px;height:6px;overflow:hidden;margin-bottom:12px;}
.progress-bar-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--green));border-radius:50px;transition:width 0.6s ease;}
.trade-meta{display:flex;gap:14px;flex-wrap:wrap;font-size:0.75rem;color:var(--muted2);margin-bottom:16px;}
.trade-meta span{display:flex;align-items:center;gap:4px;}
.trade-actions{display:flex;gap:10px;flex-wrap:wrap;}
.btn-trade-action{padding:9px 16px;border-radius:10px;border:none;font-weight:700;font-family:'Syne',sans-serif;font-size:0.78rem;cursor:pointer;display:flex;align-items:center;gap:6px;transition:all 0.2s;white-space:nowrap;}
.btn-add{background:rgba(0,212,255,0.1);border:1px solid rgba(0,212,255,0.25);color:var(--accent);}
.btn-add:hover{background:rgba(0,212,255,0.2);}
.btn-withdraw{background:rgba(0,230,118,0.1);border:1px solid rgba(0,230,118,0.25);color:var(--green);}
.btn-withdraw:hover{background:rgba(0,230,118,0.2);}
.btn-stop-all{background:rgba(255,61,87,0.1);border:1px solid rgba(255,61,87,0.25);color:var(--red);}
.btn-stop-all:hover{background:rgba(255,61,87,0.2);}

/* ── History ── */
.history-table{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;}
.history-table table{width:100%;}
.history-table thead th{background:var(--card2);color:var(--muted);font-size:0.7rem;text-transform:uppercase;letter-spacing:0.5px;padding:12px 16px;font-weight:600;border-bottom:1px solid var(--border);}
.history-table tbody td{padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle;font-size:0.85rem;}
.history-table tbody tr:last-child td{border-bottom:none;}
.history-table tbody tr:hover{background:rgba(255,255,255,0.015);}
.badge-stopped{background:rgba(255,61,87,0.1);color:var(--red);border:1px solid rgba(255,61,87,0.2);font-size:0.68rem;padding:3px 9px;border-radius:20px;font-family:'Syne',sans-serif;font-weight:700;}

/* ── Modals ── */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.82);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(6px);}
.overlay.show{display:flex;}
.modal-box{background:var(--card);border:1px solid var(--border2);border-radius:24px;padding:32px 28px;width:95%;max-width:440px;animation:popIn 0.3s cubic-bezier(0.175,0.885,0.32,1.275);}
@keyframes popIn{from{opacity:0;transform:scale(0.88);}to{opacity:1;transform:scale(1);}}
.modal-title{font-size:1.1rem;font-weight:800;margin-bottom:6px;}
.modal-sub{font-size:0.82rem;color:var(--muted2);margin-bottom:22px;line-height:1.5;}
.modal-icon{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.7rem;margin:0 auto 18px;}
.modal-icon.blue{background:rgba(0,212,255,0.1);border:2px solid rgba(0,212,255,0.3);color:var(--accent);}
.modal-icon.green{background:rgba(0,230,118,0.1);border:2px solid rgba(0,230,118,0.3);color:var(--green);}
.modal-icon.red{background:rgba(255,61,87,0.1);border:2px solid rgba(255,61,87,0.3);color:var(--red);}
.modal-icon.gold{background:rgba(255,215,0,0.1);border:2px solid rgba(255,215,0,0.3);color:var(--gold);}
.form-field{margin-bottom:16px;}
.form-field label{display:block;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted2);margin-bottom:6px;font-family:'Syne',sans-serif;}
.form-field input{width:100%;background:var(--bg2);border:1px solid var(--border2);color:var(--text);border-radius:10px;padding:12px 14px;font-size:0.9rem;outline:none;transition:border-color 0.2s;}
.form-field input:focus{border-color:var(--accent);}
.info-box{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:18px;}
.info-row{display:flex;justify-content:space-between;font-size:0.82rem;margin-bottom:6px;}
.info-row:last-child{margin-bottom:0;}
.info-row .lbl{color:var(--muted2);}
.info-row .val{font-weight:600;font-family:'Syne',sans-serif;}
.modal-actions{display:flex;gap:10px;margin-top:20px;}
.modal-actions button{flex:1;padding:13px;border-radius:12px;border:none;font-weight:700;font-family:'Syne',sans-serif;cursor:pointer;font-size:0.88rem;transition:opacity 0.2s;}
.modal-actions button:disabled{opacity:0.45;cursor:not-allowed;}
.btn-m-cancel{background:var(--bg2);border:1px solid var(--border)!important;color:var(--muted);}
.btn-m-confirm-blue{background:linear-gradient(90deg,var(--accent),#0099cc);color:#000;}
.btn-m-confirm-green{background:linear-gradient(90deg,var(--green),#00bcd4);color:#000;}
.btn-m-confirm-red{background:var(--red);color:#fff;}
.warning-box{background:rgba(255,61,87,0.07);border:1px solid rgba(255,61,87,0.2);border-radius:10px;padding:11px 14px;font-size:0.78rem;color:var(--red);margin-bottom:16px;line-height:1.5;}

/* ── Toast ── */
.toast-wrap{position:fixed;top:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;}
.toast-item{background:var(--card);border:1px solid var(--border);color:var(--text);padding:13px 18px;border-radius:12px;font-size:0.85rem;display:flex;align-items:center;gap:10px;min-width:280px;box-shadow:0 8px 30px rgba(0,0,0,0.5);animation:tin .35s ease forwards;}
.toast-item.s{border-left:3px solid var(--green);}
.toast-item.e{border-left:3px solid var(--red);}
@keyframes tin{from{opacity:0;transform:translateX(60px);}to{opacity:1;transform:translateX(0);}}

/* ── Empty states ── */
.empty-state{text-align:center;padding:48px 20px;color:var(--muted);}
.empty-state i{font-size:2.5rem;opacity:0.3;display:block;margin-bottom:12px;}
.empty-state p{font-size:0.9rem;}

/* ── Tabs ── */
.ct-tabs{display:flex;gap:4px;background:var(--bg2);border-radius:12px;padding:5px;margin-bottom:28px;width:fit-content;}
.ct-tab{padding:9px 22px;border-radius:9px;border:none;background:transparent;color:var(--muted);font-weight:700;font-size:0.83rem;cursor:pointer;font-family:'Syne',sans-serif;transition:all 0.2s;white-space:nowrap;}
.ct-tab.active{background:var(--card);color:var(--text);box-shadow:0 2px 10px rgba(0,0,0,0.3);}

/* ── Copy trade start modal ── */
.start-trader-head{display:flex;align-items:center;gap:12px;margin-bottom:18px;}
.fee-note{font-size:0.73rem;color:var(--muted);margin-top:5px;display:flex;align-items:center;gap:4px;}

/* ── Responsive ── */
@media(max-width:600px){
  .trade-card-top{gap:10px;}
  .trade-amounts{gap:12px;}
  .modal-box{padding:24px 18px;}
  .ct-header{gap:10px;}
}
</style>
</head>
<body>
<div class="ct-wrap">

  <!-- Header -->
  <div class="ct-header">
    <div>
      <div class="ct-logo">Swift<span>Trade</span> <span style="font-size:0.72rem;color:var(--muted);font-weight:400;margin-left:4px;font-family:'DM Sans',sans-serif">Copy Trading</span></div>
      <div style="font-size:0.78rem;color:var(--muted);margin-top:2px;">Welcome back, <?= htmlspecialchars($user['name'] ?? 'Trader') ?></div>
    </div>
    <div class="wallet-pill">
      <div class="wallet-icon"><i class="bi bi-wallet2"></i></div>
      <div>
        <div style="font-size:0.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.4px;">Wallet Balance</div>
        <div class="bal" id="walletBalance">$<?= number_format($balance, 2) ?></div>
      </div>
    </div>
  </div>

  <!-- Stats strip -->
  <div class="stats-strip">
    <div class="stat-block s-blue">
      <div class="stat-label">Active Trades</div>
      <div class="stat-val" style="color:var(--accent)" id="activeCount"><?= count($myTrades) ?></div>
      <div class="stat-sub">Live copy trades</div>
    </div>
    <div class="stat-block s-green">
      <div class="stat-label">Total Invested</div>
      <div class="stat-val" style="color:var(--green)">$<?= number_format($totalInvested, 0) ?></div>
      <div class="stat-sub">Across all trades</div>
    </div>
    <div class="stat-block s-gold">
      <div class="stat-label">Total Profit</div>
      <div class="stat-val" style="color:var(--gold)" id="totalProfitStat">+$<?= number_format($totalProfit, 2) ?></div>
      <div class="stat-sub">Unrealized gains</div>
    </div>
    <div class="stat-block s-purple">
      <div class="stat-label">Available</div>
      <div class="stat-val" style="color:var(--purple)">$<?= number_format($balance, 2) ?></div>
      <div class="stat-sub">Ready to invest</div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="ct-tabs">
    <button class="ct-tab active" id="tab-explore-btn" onclick="switchTab('explore')"><i class="bi bi-grid me-1"></i>Explore Traders</button>
    <button class="ct-tab" id="tab-mytrades-btn" onclick="switchTab('mytrades')">
      <i class="bi bi-activity me-1"></i>My Trades
      <?php if (count($myTrades) > 0): ?><span style="background:var(--red);color:#fff;font-size:0.6rem;padding:2px 6px;border-radius:20px;margin-left:4px"><?= count($myTrades) ?></span><?php endif; ?>
    </button>
    <button class="ct-tab" id="tab-history-btn" onclick="switchTab('history')"><i class="bi bi-clock-history me-1"></i>History</button>
  </div>

  <!-- ── EXPLORE TAB ── -->
  <div id="tab-explore" class="tab-pane">
    <div class="section-head">
      <div class="section-title"><span class="dot"></span>Top Copy Traders</div>
      <div style="font-size:0.78rem;color:var(--muted)"><?= count($traders) ?> traders available</div>
    </div>
    <?php
    // Build a set of leader_ids user is already copying
    $copyingSet = array_column($myTrades, 'trader_id');
    ?>
    <div class="traders-grid">
      <?php foreach ($traders as $t):
        $risk = (int)$t['risk_score'];
        $bannerCls = $risk <= 3 ? 'low' : ($risk <= 6 ? 'med' : 'high');
        $isAlready = in_array($t['id'], $copyingSet);
      ?>
      <div class="trader-card">
        <div class="tc-banner <?= $bannerCls ?>"></div>
        <div class="tc-body">
          <div class="tc-head">
            <?php if (!empty($t['profile_image'])): ?>
              <img src="../<?= htmlspecialchars($t['profile_image']) ?>" class="tc-avatar">
            <?php else: ?>
              <div class="tc-avatar-ph"><i class="bi bi-person-circle"></i></div>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
              <div class="tc-name"><?= htmlspecialchars($t['display_name']) ?> <?php if ($t['verified']): ?><span class="badge-ver">✓ PRO</span><?php endif; ?></div>
              <div class="tc-cat"><?= htmlspecialchars($t['category'] ?? 'Trading') ?></div>
            </div>
          </div>
          <div class="tc-metrics">
            <div class="tc-metric">
              <div class="tc-metric-val" style="color:var(--green)"><?= (float)$t['monthly_return'] ?>%</div>
              <div class="tc-metric-label">Monthly</div>
            </div>
            <div class="tc-metric">
              <div class="tc-metric-val" style="color:var(--accent)"><?= (float)$t['win_rate'] ?>%</div>
              <div class="tc-metric-label">Win Rate</div>
            </div>
            <div class="tc-metric">
              <div class="tc-metric-val" style="color:<?= $risk<=3?'var(--green)':($risk<=6?'var(--gold)':'var(--red)') ?>"><?= $risk <= 3 ? 'Low' : ($risk <= 6 ? 'Med' : 'High') ?></div>
              <div class="tc-metric-label">Risk</div>
            </div>
          </div>
          <div class="tc-info-row">
            <div class="tc-info-item"><div class="val"><?= (int)$t['duration_days'] ?>d</div><span class="lbl">Duration</span></div>
            <div class="tc-info-item"><div class="val">$<?= number_format((float)$t['min_deposit'],0) ?></div><span class="lbl">Min. Dep</span></div>
            <div class="tc-info-item"><div class="val">$<?= number_format((float)$t['trading_fee'],0) ?></div><span class="lbl">Fee</span></div>
            <div class="tc-info-item"><div class="val"><?= number_format((int)$t['followers']) ?></div><span class="lbl">Followers</span></div>
          </div>
          <?php if ($isAlready): ?>
            <div class="already-copying"><i class="bi bi-check-circle-fill"></i> Already Copying</div>
          <?php else: ?>
            <button class="btn-copy" onclick='openStartModal(<?= json_encode([
              "id"=>$t['id'],"display_name"=>$t['display_name'],"profile_image"=>$t['profile_image']??'',
              "monthly_return"=>$t['monthly_return'],"min_deposit"=>$t['min_deposit'],
              "trading_fee"=>$t['trading_fee'],"duration_days"=>$t['duration_days'],"win_rate"=>$t['win_rate']
            ]) ?>)'>
              <i class="bi bi-play-fill me-1"></i> Start Copying
            </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($traders)): ?>
        <div class="empty-state" style="grid-column:1/-1"><i class="bi bi-people"></i><p>No traders available right now</p></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── MY TRADES TAB ── -->
  <div id="tab-mytrades" class="tab-pane" style="display:none">
    <div class="section-head">
      <div class="section-title"><span class="dot" style="background:var(--green)"></span>Active Copy Trades</div>
    </div>
    <div class="my-trades-list" id="myTradesList">
      <?php if (empty($myTrades)): ?>
        <div class="empty-state"><i class="bi bi-activity"></i><p>No active trades. Start copying a trader!</p></div>
      <?php else: ?>
      <?php foreach ($myTrades as $t):
        $days_running = max(1, (int)floor((time() - strtotime($t['created_at'])) / 86400));
        $total_days   = (int)($t['duration_days'] ?? 30);
        $pct          = min(100, round(($days_running / max(1,$total_days)) * 100));
        $auto_profit  = round((float)$t['invested_amount'] * ((float)$t['monthly_return'] / 100) * ($days_running / 30), 2);
        $show_profit  = ($t['manual_profit'] !== null && $t['manual_profit'] >= 0) ? (float)$t['manual_profit'] : $auto_profit;
        $risk         = (int)$t['risk_score'];
      ?>
      <div class="trade-card" id="trade-card-<?= (int)$t['trade_id'] ?>">
        <div class="trade-card-top">
          <div class="tc-trader-info">
            <?php if (!empty($t['profile_image'])): ?>
              <img src="../<?= htmlspecialchars($t['profile_image']) ?>" class="tc-trader-img">
            <?php else: ?>
              <div class="tc-trader-img-ph"><i class="bi bi-graph-up"></i></div>
            <?php endif; ?>
            <div>
              <div style="font-weight:700;font-size:0.95rem"><?= htmlspecialchars($t['display_name']) ?></div>
              <div style="font-size:0.72rem;color:var(--muted2);margin-top:2px"><?= htmlspecialchars($t['category'] ?? '') ?> &nbsp;·&nbsp; <?= (float)$t['monthly_return'] ?>% / mo</div>
              <div style="margin-top:5px"><span class="live-badge"><span class="pulse"></span>LIVE</span></div>
            </div>
          </div>
          <div class="trade-amounts">
            <div class="amount-block">
              <div class="amt" style="color:var(--text)" id="inv-<?= (int)$t['trade_id'] ?>">$<?= number_format((float)$t['invested_amount'],2) ?></div>
              <div class="lbl">Invested</div>
            </div>
            <div class="amount-block">
              <div class="amt" style="color:var(--green)" id="profit-<?= (int)$t['trade_id'] ?>">+$<?= number_format($show_profit,2) ?></div>
              <div class="lbl">Profit</div>
            </div>
            <div class="amount-block">
              <div class="amt" style="color:var(--gold)">$<?= number_format((float)$t['invested_amount'] + $show_profit, 2) ?></div>
              <div class="lbl">Total Value</div>
            </div>
          </div>
        </div>
        <div class="trade-card-body">
          <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?= $pct ?>%"></div></div>
          <div class="trade-meta">
            <span><i class="bi bi-calendar3"></i> Started <?= date('M d, Y', strtotime($t['created_at'])) ?></span>
            <span><i class="bi bi-clock"></i> <?= $days_running ?>/<?= $total_days ?> days (<?= $pct ?>%)</span>
            <span><i class="bi bi-cash-coin" style="color:var(--gold)"></i> $<?= number_format((float)$t['trading_fee'],2) ?> fee paid</span>
          </div>
          <div class="trade-actions">
            <button class="btn-trade-action btn-add" onclick="openAddFundsModal(<?= (int)$t['trade_id'] ?>, '<?= addslashes(htmlspecialchars($t['display_name'])) ?>', <?= (float)$t['invested_amount'] ?>)">
              <i class="bi bi-plus-circle"></i> Add Funds
            </button>
            <button class="btn-trade-action btn-withdraw" onclick="openWithdrawModal(<?= (int)$t['trade_id'] ?>, '<?= addslashes(htmlspecialchars($t['display_name'])) ?>', <?= $show_profit ?>)">
              <i class="bi bi-arrow-down-circle"></i> Withdraw Profit
            </button>
            <button class="btn-trade-action btn-stop-all" onclick="openStopModal(<?= (int)$t['trade_id'] ?>, '<?= addslashes(htmlspecialchars($t['display_name'])) ?>', <?= (float)$t['invested_amount'] ?>, <?= $show_profit ?>)">
              <i class="bi bi-stop-circle"></i> Stop & Withdraw All
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── HISTORY TAB ── -->
  <div id="tab-history" class="tab-pane" style="display:none">
    <div class="section-head">
      <div class="section-title"><span class="dot" style="background:var(--muted)"></span>Trade History</div>
    </div>
    <div class="history-table">
      <?php if (empty($stoppedTrades)): ?>
        <div class="empty-state"><i class="bi bi-clock-history"></i><p>No completed trades yet</p></div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Trader</th><th>Invested</th><th>Profit</th><th>Fee</th><th>Started</th><th>Ended</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($stoppedTrades as $s):
            $days = max(1,(int)floor((strtotime($s['stopped_at']) - strtotime($s['created_at'])) / 86400));
            $ap   = round((float)$s['invested_amount'] * ((float)$s['monthly_return'] / 100) * ($days / 30), 2);
            $pr   = ($s['manual_profit'] !== null && $s['manual_profit'] >= 0) ? (float)$s['manual_profit'] : $ap;
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <?php if (!empty($s['profile_image'])): ?>
                  <img src="../<?= htmlspecialchars($s['profile_image']) ?>" style="width:32px;height:32px;border-radius:8px;object-fit:cover">
                <?php else: ?>
                  <div style="width:32px;height:32px;border-radius:8px;background:var(--bg2);display:flex;align-items:center;justify-content:center;color:var(--muted)"><i class="bi bi-person"></i></div>
                <?php endif; ?>
                <span style="font-weight:600"><?= htmlspecialchars($s['display_name']) ?></span>
              </div>
            </td>
            <td style="font-weight:600">$<?= number_format((float)$s['invested_amount'],2) ?></td>
            <td style="color:var(--green);font-weight:600">+$<?= number_format($pr,2) ?></td>
            <td style="color:var(--gold)">$<?= number_format((float)$s['trading_fee'],2) ?></td>
            <td style="color:var(--muted);font-size:0.8rem"><?= date('M d, Y', strtotime($s['created_at'])) ?></td>
            <td style="color:var(--muted);font-size:0.8rem"><?= date('M d, Y', strtotime($s['stopped_at'])) ?></td>
            <td><span class="badge-stopped">Stopped</span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /ct-wrap -->

<div class="overlay" id="startOverlay">
  <div class="modal-box">
    <div class="modal-icon gold"><i class="bi bi-play-circle-fill"></i></div>
    <div style="text-align:center">
      <div class="modal-title">Start Copying</div>
      <div id="startTraderName" style="color:var(--gold);font-size:0.88rem;font-family:'Syne',sans-serif;font-weight:700;margin-bottom:6px"></div>
      <div class="modal-sub">
        Enter the amount you want to invest. The 
        <span style="color: #007bff; font-weight: 800; background: rgba(0, 123, 255, 0.1); padding: 2px 6px; border-radius: 4px;">
          trading fee of 20%
        </span> 
        will be calculated and  deducted from your wallet after trading.
      </div>
    </div>
    <input type="hidden" id="start_leader_id">
    <div class="form-field">
      <label>Investment Amount ($)</label>
      <input type="number" id="startAmount" placeholder="Enter amount…" step="0.01" min="0" oninput="updateStartCalc()">
      <div class="fee-note" id="startMinNote"><i class="bi bi-info-circle"></i> Minimum: <span id="startMinVal"></span></div>
    </div>

    <div class="info-box" id="startInfoBox" style="border: 1px solid rgba(0, 123, 255, 0.3); background: rgba(0, 123, 255, 0.02);">
      <div class="info-row">
        <span class="lbl">Investment</span>
        <span class="val" id="sc_invest">$0.00</span>
      </div>
      <div class="info-row">
        <span class="lbl">Trading Fee</span>
        <span class="val" style="color:#007bff; font-weight:900; font-size: 1.1rem; text-shadow: 0 0 8px rgba(0,123,255,0.4);" id="sc_fee">20%</span>
      </div>
      <div class="info-row" style="border-top:1px solid var(--border);padding-top:8px;margin-top:2px">
        <span class="lbl" style="font-weight:700;color:var(--text)">Total Deducted</span>
        <span class="val" style="color:var(--red)" id="sc_total">$0.00</span>
      </div>
      <div class="info-row">
        <span class="lbl">Wallet After</span>
        <span class="val" style="color:var(--green)" id="sc_after">$<?= number_format($balance,2) ?></span>
      </div>
    </div>

    <div class="modal-actions">
      <button class="btn-m-cancel" onclick="closeOverlay('startOverlay')">Cancel</button>
      <button class="btn-m-confirm-gold btn-m-confirm-blue" id="confirmStartBtn" onclick="confirmStart()">
        <i class="bi bi-play-fill me-1"></i>Start Trade
      </button>
    </div>
  </div>

</div>

<!-- ══════════ ADD FUNDS MODAL ══════════ -->
<div class="overlay" id="addFundsOverlay">
  <div class="modal-box">
    <div class="modal-icon blue"><i class="bi bi-plus-circle-fill"></i></div>
    <div style="text-align:center">
      <div class="modal-title">Add Funds</div>
      <div id="addTraderName" style="color:var(--accent);font-size:0.85rem;font-family:'Syne',sans-serif;font-weight:700;margin-bottom:6px"></div>
      <div class="modal-sub">Increase your investment in this active trade.</div>
    </div>
    <input type="hidden" id="add_trade_id">
    <div class="form-field">
      <label>Amount to Add ($)</label>
      <input type="number" id="addAmount" placeholder="Enter amount…" step="0.01" min="0.01" oninput="updateAddCalc()">
    </div>
    <div class="info-box">
      <div class="info-row"><span class="lbl">Current Invested</span><span class="val" id="add_current">$0.00</span></div>
      <div class="info-row"><span class="lbl">Adding</span><span class="val" style="color:var(--accent)" id="add_adding">$0.00</span></div>
      <div class="info-row" style="border-top:1px solid var(--border);padding-top:8px;margin-top:2px"><span class="lbl" style="font-weight:700;color:var(--text)">New Invested Total</span><span class="val" style="color:var(--green)" id="add_new_total">$0.00</span></div>
      <div class="info-row"><span class="lbl">Wallet After</span><span class="val" style="color:var(--muted2)" id="add_wallet_after">—</span></div>
    </div>
    <div class="modal-actions">
      <button class="btn-m-cancel" onclick="closeOverlay('addFundsOverlay')">Cancel</button>
      <button class="btn-m-confirm-blue" id="confirmAddBtn" onclick="confirmAddFunds()"><i class="bi bi-plus-lg me-1"></i>Add Funds</button>
    </div>
  </div>
</div>

<!-- ══════════ WITHDRAW PROFIT MODAL ══════════ -->
<div class="overlay" id="withdrawOverlay">
  <div class="modal-box">
    <div class="modal-icon green"><i class="bi bi-arrow-down-circle-fill"></i></div>
    <div style="text-align:center">
      <div class="modal-title">Withdraw Profit</div>
      <div id="withdrawTraderName" style="color:var(--green);font-size:0.85rem;font-family:'Syne',sans-serif;font-weight:700;margin-bottom:6px"></div>
      <div class="modal-sub">Your accrued profit will be sent directly to your wallet. Your invested amount stays active.</div>
    </div>
    <input type="hidden" id="withdraw_trade_id">
    <div class="info-box">
      <div class="info-row"><span class="lbl">Profit Available</span><span class="val" style="color:var(--green)" id="wd_profit">$0.00</span></div>
      <div class="info-row"><span class="lbl">Current Wallet</span><span class="val" id="wd_wallet">$<?= number_format($balance,2) ?></span></div>
      <div class="info-row" style="border-top:1px solid var(--border);padding-top:8px;margin-top:2px"><span class="lbl" style="font-weight:700;color:var(--text)">Wallet After</span><span class="val" style="color:var(--green)" id="wd_wallet_after">—</span></div>
    </div>
    <div style="font-size:0.75rem;color:var(--muted);margin-bottom:16px;text-align:center"><i class="bi bi-info-circle me-1"></i>The profit counter resets to zero after withdrawal.</div>
    <div class="modal-actions">
      <button class="btn-m-cancel" onclick="closeOverlay('withdrawOverlay')">Cancel</button>
      <button class="btn-m-confirm-green" id="confirmWithdrawBtn" onclick="confirmWithdraw()"><i class="bi bi-arrow-down me-1"></i>Withdraw Now</button>
    </div>
  </div>
</div>

<!-- ══════════ STOP & WITHDRAW ALL MODAL ══════════ -->
<div class="overlay" id="stopOverlay">
  <div class="modal-box">
    <div class="modal-icon red"><i class="bi bi-stop-circle-fill"></i></div>
    <div style="text-align:center">
      <div class="modal-title">Stop Trade & Withdraw All</div>
      <div id="stopTraderName" style="color:var(--red);font-size:0.85rem;font-family:'Syne',sans-serif;font-weight:700;margin-bottom:6px"></div>
      <div class="modal-sub">This will permanently stop your copy trade and return your invested amount + all profit to your wallet.</div>
    </div>
    <input type="hidden" id="stop_trade_id">
    <div class="warning-box"><i class="bi bi-exclamation-triangle-fill me-1"></i>This action cannot be undone. Your trade will be closed immediately.</div>
    <div class="info-box">
      <div class="info-row"><span class="lbl">Invested Amount</span><span class="val" id="stop_invested">$0.00</span></div>
      <div class="info-row"><span class="lbl">Profit</span><span class="val" style="color:var(--green)" id="stop_profit">$0.00</span></div>
      <div class="info-row" style="border-top:1px solid var(--border);padding-top:8px;margin-top:2px"><span class="lbl" style="font-weight:700;color:var(--text)">Total Returned</span><span class="val" style="color:var(--gold)" id="stop_total">$0.00</span></div>
    </div>
    <div class="modal-actions">
      <button class="btn-m-cancel" onclick="closeOverlay('stopOverlay')">Cancel</button>
      <button class="btn-m-confirm-red" id="confirmStopBtn" onclick="confirmStop()"><i class="bi bi-stop-circle me-1"></i>Stop & Withdraw</button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const fmt  = n => '$' + parseFloat(n || 0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
let currentBalance = <?= $balance ?>;
let startFee = 0, startMin = 0;

// ── Tabs ──
function switchTab(tab) {
  ['explore','mytrades','history'].forEach(t => {
    document.getElementById('tab-'+t).style.display = t===tab ? '' : 'none';
    document.getElementById('tab-'+t+'-btn').classList.toggle('active', t===tab);
  });
}

// ── Overlay helpers ──
function closeOverlay(id) { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.overlay').forEach(o => o.addEventListener('click', e => { if(e.target===o) o.classList.remove('show'); }));

function toast(msg, type='s') {
  const wrap = document.getElementById('toastWrap');
  const el   = document.createElement('div');
  el.className = `toast-item ${type}`;
  el.innerHTML = `<i class="bi bi-${type==='s'?'check-circle':'exclamation-circle'}" style="font-size:1.1rem;flex-shrink:0"></i><span>${msg}</span>`;
  wrap.appendChild(el);
  setTimeout(()=>{ el.style.transition='opacity .4s'; el.style.opacity=0; setTimeout(()=>el.remove(),420); },3800);
}

function updateWalletDisplay(newBal) {
  currentBalance = newBal;
  document.getElementById('walletBalance').textContent = fmt(newBal);
  document.querySelectorAll('[id^="wd_wallet"]:not([id$="_after"])').forEach(el => el.textContent = fmt(newBal));
}

// ══ START COPY MODAL ══
function openStartModal(data) {
  document.getElementById('start_leader_id').value = data.id;
  document.getElementById('startTraderName').textContent = data.display_name;
  document.getElementById('startAmount').value = '';
  startFee = parseFloat(data.trading_fee) || 0;
  startMin = parseFloat(data.min_deposit) || 0;
  document.getElementById('startMinNote').style.display = startMin > 0 ? 'flex' : 'none';
  document.getElementById('startMinVal').textContent = fmt(startMin) + (startFee>0?' + '+fmt(startFee)+' fee':'');
  document.getElementById('sc_fee').textContent = fmt(startFee);
  updateStartCalc();
  document.getElementById('startOverlay').classList.add('show');
  setTimeout(()=>document.getElementById('startAmount').focus(),200);
}

function updateStartCalc() {
  const amt   = parseFloat(document.getElementById('startAmount').value) || 0;
  const total = amt + startFee;
  document.getElementById('sc_invest').textContent = fmt(amt);
  document.getElementById('sc_fee').textContent    = fmt(startFee);
  document.getElementById('sc_total').textContent  = fmt(total);
  document.getElementById('sc_after').textContent  = fmt(Math.max(0, currentBalance - total));
}

async function confirmStart() {
  const leader_id = parseInt(document.getElementById('start_leader_id').value);
  const amount    = parseFloat(document.getElementById('startAmount').value);
  if (!amount || amount < startMin) { toast('Enter a valid amount (min '+fmt(startMin)+')', 'e'); return; }
  const btn = document.getElementById('confirmStartBtn');
  btn.disabled=true; btn.innerHTML='<i class="bi bi-hourglass-split me-1"></i> Starting…';
  try {
    const r = await fetch('', {method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'start_trade',leader_id,amount})});
    const d = await r.json();
    if (d.success) {
      closeOverlay('startOverlay');
      toast(d.message,'s');
      updateWalletDisplay(d.new_balance);
      setTimeout(()=>location.reload(), 800);
    } else { toast(d.message,'e'); }
  } catch(e) { toast('Connection error','e'); }
  btn.disabled=false; btn.innerHTML='<i class="bi bi-play-fill me-1"></i>Start Trade';
}

// ══ ADD FUNDS MODAL ══
let addCurrentInvested = 0;
function openAddFundsModal(tradeId, traderName, currentInvested) {
  addCurrentInvested = currentInvested;
  document.getElementById('add_trade_id').value = tradeId;
  document.getElementById('addTraderName').textContent = traderName;
  document.getElementById('addAmount').value = '';
  document.getElementById('add_current').textContent = fmt(currentInvested);
  document.getElementById('add_new_total').textContent = fmt(currentInvested);
  document.getElementById('add_wallet_after').textContent = fmt(currentBalance);
  document.getElementById('addFundsOverlay').classList.add('show');
  setTimeout(()=>document.getElementById('addAmount').focus(),200);
}

function updateAddCalc() {
  const amt = parseFloat(document.getElementById('addAmount').value)||0;
  document.getElementById('add_adding').textContent    = fmt(amt);
  document.getElementById('add_new_total').textContent = fmt(addCurrentInvested + amt);
  document.getElementById('add_wallet_after').textContent = fmt(Math.max(0, currentBalance - amt));
}

async function confirmAddFunds() {
  const trade_id = parseInt(document.getElementById('add_trade_id').value);
  const amount   = parseFloat(document.getElementById('addAmount').value);
  if (!amount || amount <= 0) { toast('Enter a valid amount','e'); return; }
  if (amount > currentBalance) { toast('Insufficient wallet balance','e'); return; }
  const btn = document.getElementById('confirmAddBtn');
  btn.disabled=true; btn.innerHTML='<i class="bi bi-hourglass-split me-1"></i> Adding…';
  try {
    const r = await fetch('', {method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'add_funds',trade_id,amount})});
    const d = await r.json();
    if (d.success) {
      closeOverlay('addFundsOverlay');
      toast(d.message,'s');
      const invEl = document.getElementById(`inv-${trade_id}`);
      if (invEl) invEl.textContent = fmt(d.new_invested);
      updateWalletDisplay(d.new_balance);
    } else { toast(d.message,'e'); }
  } catch(e) { toast('Connection error','e'); }
  btn.disabled=false; btn.innerHTML='<i class="bi bi-plus-lg me-1"></i>Add Funds';
}

// ══ WITHDRAW PROFIT MODAL ══
function openWithdrawModal(tradeId, traderName, profit) {
  document.getElementById('withdraw_trade_id').value = tradeId;
  document.getElementById('withdrawTraderName').textContent = traderName;
  document.getElementById('wd_profit').textContent = fmt(profit);
  document.getElementById('wd_wallet').textContent = fmt(currentBalance);
  document.getElementById('wd_wallet_after').textContent = fmt(currentBalance + profit);
  document.getElementById('withdrawOverlay').classList.add('show');
}

async function confirmWithdraw() {
  const trade_id = parseInt(document.getElementById('withdraw_trade_id').value);
  const btn = document.getElementById('confirmWithdrawBtn');
  btn.disabled=true; btn.innerHTML='<i class="bi bi-hourglass-split me-1"></i> Processing…';
  try {
    const r = await fetch('', {method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'withdraw_profit',trade_id})});
    const d = await r.json();
    if (d.success) {
      closeOverlay('withdrawOverlay');
      toast(d.message,'s');
      const profEl = document.getElementById(`profit-${trade_id}`);
      if (profEl) profEl.textContent = '+$0.00';
      updateWalletDisplay(d.new_balance);
    } else { toast(d.message,'e'); }
  } catch(e) { toast('Connection error','e'); }
  btn.disabled=false; btn.innerHTML='<i class="bi bi-arrow-down me-1"></i>Withdraw Now';
}

// ══ STOP & WITHDRAW ALL MODAL ══
function openStopModal(tradeId, traderName, invested, profit) {
  document.getElementById('stop_trade_id').value = tradeId;
  document.getElementById('stopTraderName').textContent = traderName;
  document.getElementById('stop_invested').textContent = fmt(invested);
  document.getElementById('stop_profit').textContent   = fmt(profit);
  document.getElementById('stop_total').textContent    = fmt(invested + profit);
  document.getElementById('stopOverlay').classList.add('show');
}

async function confirmStop() {
  const trade_id = parseInt(document.getElementById('stop_trade_id').value);
  const btn = document.getElementById('confirmStopBtn');
  btn.disabled=true; btn.innerHTML='<i class="bi bi-hourglass-split me-1"></i> Stopping…';
  try {
    const r = await fetch('', {method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'stop_and_withdraw',trade_id})});
    const d = await r.json();
    if (d.success) {
      closeOverlay('stopOverlay');
      toast(d.message,'s');
      updateWalletDisplay(d.new_balance);
      const card = document.getElementById(`trade-card-${trade_id}`);
      if (card) { card.style.transition='opacity .5s'; card.style.opacity=0; setTimeout(()=>card.remove(),520); }
      const cnt = document.getElementById('activeCount');
      if (cnt) cnt.textContent = Math.max(0, parseInt(cnt.textContent)-1);
    } else { toast(d.message,'e'); }
  } catch(e) { toast('Connection error','e'); }
  btn.disabled=false; btn.innerHTML='<i class="bi bi-stop-circle me-1"></i>Stop & Withdraw';
}
</script>
</body>
</html>