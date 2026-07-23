<?php
/**
 * admin_copy_profits.php
 * Admin page — Set / update profit for active copy trades
 * Matches the dark finance theme of copy_trading.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/db.php';

// ── Basic admin guard (adjust to your own auth system) ──────────────────────
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$admin_id = (int)$_SESSION['user_id'];
// Optionally add: if (!$_SESSION['is_admin']) { http_response_code(403); exit('Forbidden'); }
// ────────────────────────────────────────────────────────────────────────────

// ── Handle AJAX: set profit ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $body  = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? '';

    if ($action === 'set_profit') {
        $trade_id = (int)($body['trade_id'] ?? 0);
        $profit   = (float)($body['profit']   ?? 0);
        $note     = $conn->real_escape_string(trim($body['note'] ?? ''));

        if ($trade_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid trade ID']); exit; }

        // Update manual_profit on copy_trades
        $conn->query("UPDATE copy_trades SET manual_profit = $profit WHERE id = $trade_id");

        // Upsert into profit schedule (one row per trade — replace existing)
        $conn->query("
            INSERT INTO copy_trade_profit_schedule (trade_id, profit_amount, note, set_by, set_at, applied)
            VALUES ($trade_id, $profit, '$note', $admin_id, NOW(), 0)
            ON DUPLICATE KEY UPDATE
              profit_amount = $profit,
              note          = '$note',
              set_by        = $admin_id,
              set_at        = NOW(),
              applied       = 0,
              applied_at    = NULL
        ");

        // Add unique key if not exists (run once; safe to repeat due to IF NOT EXISTS logic in SQL)
        // ALTER TABLE copy_trade_profit_schedule ADD UNIQUE KEY uq_trade_id (trade_id);
        // Handled below with INSERT … ON DUPLICATE KEY — requires the unique index.
        // If you haven't added it yet, the query above will insert duplicates.
        // To be safe, we do a check-then-insert approach:
        $conn->query("DELETE FROM copy_trade_profit_schedule WHERE trade_id = $trade_id");
        $conn->query("
            INSERT INTO copy_trade_profit_schedule (trade_id, profit_amount, note, set_by, set_at, applied)
            VALUES ($trade_id, $profit, '$note', $admin_id, NOW(), 0)
        ");

        echo json_encode(['success'=>true,'message'=>'Profit updated successfully']);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

// ── Fetch all active copy trades with user & trader info ─────────────────────
$trades = [];
$q = $conn->query("
    SELECT
        ct.id, ct.user_id, ct.leader_id, ct.invested_amount,
        ct.manual_profit, ct.status, ct.created_at,
        ct.trading_fee, ct.matured_at,
        t.display_name   AS trader_name,
        t.monthly_return, t.duration_days,
        t.profile_image,
        u.name           AS user_name,
        u.email          AS user_email,
        u.balance        AS user_balance,
        ps.profit_amount AS scheduled_profit,
        ps.set_at        AS profit_set_at,
        ps.note          AS profit_note,
        ps.applied       AS profit_applied
    FROM copy_trades ct
    JOIN copy_traders t ON ct.leader_id = t.id
    JOIN users        u ON ct.user_id   = u.id
    LEFT JOIN copy_trade_profit_schedule ps ON ps.trade_id = ct.id
    WHERE ct.status = 'active'
    ORDER BY ct.created_at DESC
");
if ($q) $trades = $q->fetch_all(MYSQLI_ASSOC);

// ── Stats ────────────────────────────────────────────────────────────────────
$total_active    = count($trades);
$total_invested  = array_sum(array_column($trades, 'invested_amount'));
$total_profit    = array_sum(array_column($trades, 'manual_profit'));
$profits_set     = count(array_filter($trades, fn($r) => $r['scheduled_profit'] !== null));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Copy Trade Profits</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#060d1a;--card:#0e1c30;--card2:#0a1525;
  --border:#1a2e48;--accent:#00d4ff;--accent2:#0099cc;
  --green:#00e676;--red:#ff3d57;--gold:#ffd700;
  --text:#e8f4ff;--muted:#5a7a99;--radius:16px;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh;}
h1,h2,h3,h4,h5{font-family:'Syne',sans-serif;}
.container{max-width:1280px;}

/* ── Header ── */
.page-header{padding:36px 0 24px;}
.page-header h2{font-size:1.9rem;font-weight:800;background:linear-gradient(135deg,var(--gold),#ff9800);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.page-header p{color:var(--muted);margin-top:5px;font-size:0.9rem;}
.admin-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,215,0,0.1);border:1px solid rgba(255,215,0,0.3);color:var(--gold);font-size:0.72rem;font-weight:700;padding:4px 12px;border-radius:20px;margin-bottom:10px;font-family:'Syne',sans-serif;letter-spacing:0.6px;}

/* ── Stat cards ── */
.stats-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:32px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;position:relative;overflow:hidden;}
.stat-card::after{content:'';position:absolute;top:-20px;right:-20px;width:80px;height:80px;border-radius:50%;opacity:0.06;}
.sc-g::after{background:var(--green);}
.sc-b::after{background:var(--accent);}
.sc-gold::after{background:var(--gold);}
.sc-r::after{background:var(--red);}
.stat-card .sc-val{font-size:1.6rem;font-weight:800;font-family:'Syne',sans-serif;line-height:1;}
.stat-card .sc-lbl{font-size:0.72rem;color:var(--muted);margin-top:5px;text-transform:uppercase;letter-spacing:0.5px;}
.stat-card .sc-icon{font-size:1.5rem;margin-bottom:10px;}

/* ── Filter / search ── */
.toolbar{display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap;}
.toolbar input{background:var(--card);border:1px solid var(--border);color:var(--text);border-radius:10px;padding:10px 14px;font-size:0.88rem;outline:none;min-width:220px;}
.toolbar input:focus{border-color:var(--accent);}
.filter-btn{background:var(--card);border:1px solid var(--border);color:var(--muted);border-radius:10px;padding:10px 16px;font-size:0.82rem;cursor:pointer;transition:all 0.2s;font-family:'Syne',sans-serif;font-weight:600;}
.filter-btn.active,.filter-btn:hover{border-color:var(--accent);color:var(--accent);background:rgba(0,212,255,0.07);}

/* ── Table ── */
.table-wrap{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:40px;}
.table-wrap table{width:100%;border-collapse:collapse;font-size:0.85rem;}
.table-wrap thead tr{background:var(--card2);border-bottom:1px solid var(--border);}
.table-wrap th{padding:13px 16px;font-size:0.7rem;text-transform:uppercase;letter-spacing:0.7px;color:var(--muted);font-weight:700;font-family:'Syne',sans-serif;white-space:nowrap;}
.table-wrap td{padding:13px 16px;border-bottom:1px solid rgba(255,255,255,0.04);vertical-align:middle;}
.table-wrap tr:last-child td{border-bottom:none;}
.table-wrap tr:hover td{background:rgba(0,212,255,0.03);}

.trader-cell{display:flex;align-items:center;gap:10px;}
.t-av{width:38px;height:38px;border-radius:8px;object-fit:cover;flex-shrink:0;}
.t-av-ph{width:38px;height:38px;border-radius:8px;background:linear-gradient(135deg,#0a2540,#0e3356);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--accent);flex-shrink:0;}
.t-name{font-weight:700;font-size:0.88rem;}
.t-sub{font-size:0.72rem;color:var(--muted);}

.badge-active{background:rgba(0,230,118,0.1);border:1px solid rgba(0,230,118,0.2);color:var(--green);font-size:0.67rem;padding:3px 9px;border-radius:20px;font-weight:700;}
.badge-set{background:rgba(255,215,0,0.1);border:1px solid rgba(255,215,0,0.25);color:var(--gold);font-size:0.67rem;padding:3px 9px;border-radius:20px;font-weight:700;}
.badge-pending{background:rgba(90,122,153,0.15);border:1px solid rgba(90,122,153,0.2);color:var(--muted);font-size:0.67rem;padding:3px 9px;border-radius:20px;font-weight:700;}
.badge-applied{background:rgba(0,212,255,0.1);border:1px solid rgba(0,212,255,0.22);color:var(--accent);font-size:0.67rem;padding:3px 9px;border-radius:20px;font-weight:700;}

.progress-mini{height:4px;background:rgba(255,255,255,0.07);border-radius:4px;overflow:hidden;margin-top:4px;width:80px;}
.progress-mini-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--green));border-radius:4px;}

/* ── Edit button ── */
.edit-btn{background:rgba(0,212,255,0.08);border:1px solid rgba(0,212,255,0.22);color:var(--accent);padding:7px 14px;border-radius:9px;font-size:0.78rem;font-weight:700;cursor:pointer;font-family:'Syne',sans-serif;transition:all 0.2s;white-space:nowrap;}
.edit-btn:hover{background:rgba(0,212,255,0.18);}

/* ── Modal ── */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(6px);}
.overlay.show{display:flex;}
.modal-box{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:30px;width:94%;max-width:460px;position:relative;animation:popIn 0.3s cubic-bezier(0.175,0.885,0.32,1.275);}
@keyframes popIn{0%{opacity:0;transform:scale(0.85);}100%{opacity:1;transform:scale(1);}}
.modal-close{position:absolute;top:16px;right:18px;background:var(--card2);border:1px solid var(--border);color:var(--muted);width:30px;height:30px;border-radius:50%;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;}
.modal-close:hover{color:var(--text);}
.modal-box h4{font-size:1.1rem;font-weight:700;margin-bottom:18px;color:var(--gold);}

.info-pill{border-radius:10px;padding:11px 14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;}
.ip-blue{background:rgba(0,212,255,0.07);border:1px solid rgba(0,212,255,0.2);}
.ip-green{background:rgba(0,230,118,0.07);border:1px solid rgba(0,230,118,0.2);}
.ip-gold{background:rgba(255,215,0,0.06);border:1px solid rgba(255,215,0,0.2);}
.info-pill .label{font-size:0.72rem;color:var(--muted);margin-bottom:2px;}
.info-pill .value{font-size:1rem;font-weight:700;font-family:'Syne',sans-serif;}
.val-green{color:var(--green);}
.val-blue{color:var(--accent);}
.val-gold{color:var(--gold);}

.form-group{margin-bottom:14px;}
.form-group label{font-size:0.75rem;color:var(--muted);display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;}
.form-group input,.form-group textarea{width:100%;background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:10px;padding:11px 14px;font-size:0.92rem;outline:none;transition:border-color 0.2s;font-family:'DM Sans',sans-serif;}
.form-group textarea{resize:vertical;height:72px;}
.form-group input:focus,.form-group textarea:focus{border-color:var(--gold);}
.hint-note{font-size:0.72rem;color:var(--muted);margin-top:4px;}
.hint-note span{color:var(--gold);}

.submit-btn{width:100%;padding:13px;border:none;border-radius:12px;font-weight:700;font-size:0.95rem;font-family:'Syne',sans-serif;cursor:pointer;transition:opacity 0.2s;}
.submit-btn:hover{opacity:0.85;}
.submit-btn:disabled{opacity:0.45;cursor:not-allowed;}
.btn-gold{background:linear-gradient(90deg,var(--gold),#ff9800);color:#000;}

/* Quick amount buttons */
.quick-amts{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;}
.qa-btn{background:var(--card2);border:1px solid var(--border);color:var(--muted);font-size:0.75rem;font-weight:600;padding:6px 12px;border-radius:8px;cursor:pointer;transition:all 0.2s;}
.qa-btn:hover{border-color:var(--gold);color:var(--gold);background:rgba(255,215,0,0.06);}

/* Toast */
.toast-wrap{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;}
.toast-item{background:var(--card);border:1px solid var(--border);color:var(--text);padding:12px 18px;border-radius:12px;font-size:0.88rem;display:flex;align-items:center;gap:10px;box-shadow:0 4px 20px rgba(0,0,0,0.4);animation:tin 0.35s ease forwards;}
.toast-item.s{border-left:3px solid var(--green);}
.toast-item.e{border-left:3px solid var(--red);}
@keyframes tin{from{opacity:0;transform:translateX(60px);}to{opacity:1;transform:translateX(0);}}

.empty-state{text-align:center;padding:48px 20px;color:var(--muted);}
.empty-state i{font-size:3rem;display:block;margin-bottom:12px;opacity:0.4;}

@media(max-width:768px){
  .table-wrap{overflow-x:auto;}
  .toolbar{flex-direction:column;align-items:stretch;}
  .toolbar input{min-width:unset;}
}
</style>
</head>
<body>
<div class="container py-4 px-3 px-md-4">

  <div class="page-header">
    <div class="admin-badge"><i class="bi bi-shield-fill-check"></i> ADMIN PANEL</div>
    <h2>Copy Trade Profit Manager</h2>
    <p>Set and track profit amounts for all active copy trades. Changes reflect immediately on user dashboards.</p>
  </div>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-card sc-b">
      <div class="sc-icon" style="color:var(--accent)"><i class="bi bi-activity"></i></div>
      <div class="sc-val" style="color:var(--accent)"><?= $total_active ?></div>
      <div class="sc-lbl">Active Trades</div>
    </div>
    <div class="stat-card sc-g">
      <div class="sc-icon" style="color:var(--green)"><i class="bi bi-wallet2"></i></div>
      <div class="sc-val" style="color:var(--green)">$<?= number_format($total_invested, 2) ?></div>
      <div class="sc-lbl">Total Invested</div>
    </div>
    <div class="stat-card sc-gold">
      <div class="sc-icon" style="color:var(--gold)"><i class="bi bi-graph-up-arrow"></i></div>
      <div class="sc-val" style="color:var(--gold)">$<?= number_format($total_profit, 2) ?></div>
      <div class="sc-lbl">Total Profit Allocated</div>
    </div>
    <div class="stat-card sc-r">
      <div class="sc-icon" style="color:#ff9800"><i class="bi bi-check2-circle"></i></div>
      <div class="sc-val" style="color:#ff9800"><?= $profits_set ?> / <?= $total_active ?></div>
      <div class="sc-lbl">Profits Set</div>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="toolbar">
    <input type="text" id="searchInput" placeholder="🔍  Search by user, trader, email…">
    <button class="filter-btn active" onclick="filterStatus('all',this)">All</button>
    <button class="filter-btn" onclick="filterStatus('set',this)">Profit Set</button>
    <button class="filter-btn" onclick="filterStatus('pending',this)">Pending</button>
    <button class="filter-btn" onclick="filterStatus('applied',this)">Auto-credited</button>
  </div>

  <!-- Table -->
  <div class="table-wrap">
    <?php if (empty($trades)): ?>
      <div class="empty-state"><i class="bi bi-graph-up-arrow"></i><p>No active copy trades found.</p></div>
    <?php else: ?>
    <table id="tradeTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Trader</th>
          <th>User</th>
          <th>Invested</th>
          <th>Manual Profit</th>
          <th>Duration</th>
          <th>Progress</th>
          <th>Profit Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($trades as $row):
          $days_running = max(0, (int)floor((time() - strtotime($row['created_at'])) / 86400));
          $total_days   = (int)($row['duration_days'] ?? 30);
          $pct          = min(100, $total_days > 0 ? round($days_running / $total_days * 100) : 0);
          $auto_profit  = round($row['invested_amount'] * ($row['monthly_return'] / 100) * ($days_running / 30), 2);
          $cur_profit   = $row['manual_profit'] !== null ? (float)$row['manual_profit'] : $auto_profit;
          $profit_set   = $row['scheduled_profit'] !== null;
          $profit_applied = !empty($row['profit_applied']);
          $img          = !empty($row['profile_image']) ? htmlspecialchars($row['profile_image']) : '';

          // Row status for filtering
          $row_status = $profit_applied ? 'applied' : ($profit_set ? 'set' : 'pending');
        ?>
        <tr class="trade-row"
            data-status="<?= $row_status ?>"
            data-search="<?= strtolower($row['user_name'].' '.$row['user_email'].' '.$row['trader_name']) ?>">
          <td style="color:var(--muted);font-size:0.78rem">#<?= $row['id'] ?></td>
          <td>
            <div class="trader-cell">
              <?php if ($img): ?>
                <img src="<?= $img ?>" class="t-av" alt="">
              <?php else: ?>
                <div class="t-av-ph"><i class="bi bi-person-circle"></i></div>
              <?php endif; ?>
              <div>
                <div class="t-name"><?= htmlspecialchars($row['trader_name']) ?></div>
                <div class="t-sub"><?= $row['monthly_return'] ?>% mo.</div>
              </div>
            </div>
          </td>
          <td>
            <div class="t-name"><?= htmlspecialchars($row['user_name']) ?></div>
            <div class="t-sub"><?= htmlspecialchars($row['user_email']) ?></div>
          </td>
          <td style="font-weight:700;color:var(--accent);font-family:'Syne',sans-serif">
            $<?= number_format($row['invested_amount'], 2) ?>
          </td>
          <td>
            <?php if ($row['manual_profit'] !== null): ?>
              <span style="color:var(--green);font-weight:700;font-family:'Syne',sans-serif">
                +$<?= number_format((float)$row['manual_profit'], 2) ?>
              </span>
            <?php else: ?>
              <span style="color:var(--muted);font-size:0.8rem">
                Auto: +$<?= number_format($auto_profit, 2) ?>
              </span>
            <?php endif; ?>
          </td>
          <td style="color:var(--muted);font-size:0.82rem">
            <?= $days_running ?>/<?= $total_days ?>d
          </td>
          <td>
            <div style="font-size:0.75rem;color:var(--muted);margin-bottom:3px"><?= $pct ?>%</div>
            <div class="progress-mini"><div class="progress-mini-fill" style="width:<?= $pct ?>%"></div></div>
          </td>
          <td>
            <?php if ($profit_applied): ?>
              <span class="badge-applied"><i class="bi bi-check-circle me-1"></i>Credited</span>
            <?php elseif ($profit_set): ?>
              <span class="badge-set"><i class="bi bi-clock me-1"></i>Scheduled</span>
              <div style="font-size:0.68rem;color:var(--muted);margin-top:3px">
                <?= date('M d H:i', strtotime($row['profit_set_at'])) ?>
              </div>
            <?php else: ?>
              <span class="badge-pending">Pending</span>
            <?php endif; ?>
          </td>
          <td>
            <button class="edit-btn" onclick="openEditModal(
              <?= (int)$row['id'] ?>,
              '<?= addslashes(htmlspecialchars($row['user_name'])) ?>',
              '<?= addslashes(htmlspecialchars($row['trader_name'])) ?>',
              <?= (float)$row['invested_amount'] ?>,
              <?= (float)$cur_profit ?>,
              '<?= addslashes($row['profit_note'] ?? '') ?>'
            )">
              <i class="bi bi-pencil-square me-1"></i>
              <?= $profit_set ? 'Edit Profit' : 'Set Profit' ?>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div><!-- /container -->

<!-- ════ EDIT PROFIT MODAL ════ -->
<div class="overlay" id="editOverlay">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal()">✕</button>
    <h4><i class="bi bi-graph-up-arrow me-2"></i>Set Trade Profit</h4>

    <div class="info-pill ip-blue">
      <div>
        <div class="label">User</div>
        <div class="value val-blue" id="m_user">—</div>
      </div>
      <i class="bi bi-person-circle" style="color:var(--accent);font-size:1.4rem"></i>
    </div>

    <div class="info-pill ip-green">
      <div>
        <div class="label">Trader · Principal</div>
        <div class="value val-green" id="m_trader_inv">—</div>
      </div>
      <i class="bi bi-wallet2" style="color:var(--green);font-size:1.4rem"></i>
    </div>

    <!-- Quick amount shortcuts -->
    <div class="form-group">
      <label>Quick % Presets (of principal)</label>
      <div class="quick-amts" id="quickAmts"></div>
    </div>

    <div class="form-group">
      <label>Profit Amount (USD)</label>
      <input type="number" id="m_profit" placeholder="0.00" min="0" step="0.01" oninput="recalcModal()">
      <div class="hint-note">
        This overrides auto-calculated profit.
        Auto estimate: <span id="m_auto_est">$0.00</span>
      </div>
    </div>

    <div class="info-pill ip-gold" id="m_total_pill">
      <div>
        <div class="label">Total Portfolio Value (Principal + Profit)</div>
        <div class="value val-gold" id="m_total_val">$0.00</div>
      </div>
      <i class="bi bi-graph-up" style="color:var(--gold);font-size:1.4rem"></i>
    </div>

    <div class="form-group">
      <label>Admin Note (optional)</label>
      <textarea id="m_note" placeholder="e.g. Q3 bonus allocation, manual adjustment…"></textarea>
    </div>

    <input type="hidden" id="m_trade_id">
    <input type="hidden" id="m_invested">

    <button class="submit-btn btn-gold" id="saveBtn" onclick="saveProfit()">
      <i class="bi bi-check-circle-fill me-1"></i> Save Profit
    </button>
  </div>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
// ── Search ───────────────────────────────────────────────────────────────────
document.getElementById('searchInput').addEventListener('keyup', e => {
  const t = e.target.value.toLowerCase();
  document.querySelectorAll('.trade-row').forEach(r => {
    r.style.display = r.dataset.search.includes(t) ? '' : 'none';
  });
});

// ── Filter by status ─────────────────────────────────────────────────────────
function filterStatus(status, btn) {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.trade-row').forEach(r => {
    r.style.display = (status === 'all' || r.dataset.status === status) ? '' : 'none';
  });
}

// ── Modal ─────────────────────────────────────────────────────────────────────
let _m = { tradeId: 0, invested: 0 };

function openEditModal(tradeId, userName, traderName, invested, currentProfit, note) {
  _m = { tradeId, invested };

  document.getElementById('m_trade_id').value = tradeId;
  document.getElementById('m_invested').value = invested;
  document.getElementById('m_user').textContent         = userName;
  document.getElementById('m_trader_inv').textContent   = traderName + ' · $' + parseFloat(invested).toLocaleString('en-US',{minimumFractionDigits:2});
  document.getElementById('m_profit').value             = currentProfit > 0 ? currentProfit : '';
  document.getElementById('m_note').value               = note;

  // Quick presets: 5%, 10%, 15%, 20%, 25%, 30% of principal
  const presets = [5, 10, 15, 20, 25, 30];
  const wrap = document.getElementById('quickAmts');
  wrap.innerHTML = presets.map(p => {
    const amt = (invested * p / 100).toFixed(2);
    return `<button class="qa-btn" onclick="setQuickAmt(${amt})">${p}% · $${parseFloat(amt).toLocaleString('en-US',{minimumFractionDigits:2})}</button>`;
  }).join('');

  recalcModal();
  document.getElementById('editOverlay').classList.add('show');
}

function setQuickAmt(amt) {
  document.getElementById('m_profit').value = amt;
  recalcModal();
}

function recalcModal() {
  const invested = parseFloat(document.getElementById('m_invested').value) || 0;
  const profit   = parseFloat(document.getElementById('m_profit').value)   || 0;
  const total    = invested + profit;
  const autoEst  = (invested * 0.08).toFixed(2); // rough 8% estimate for hint

  document.getElementById('m_total_val').textContent  = '$' + total.toLocaleString('en-US',{minimumFractionDigits:2});
  document.getElementById('m_auto_est').textContent   = '$' + parseFloat(autoEst).toLocaleString('en-US',{minimumFractionDigits:2});
}

function closeModal() {
  document.getElementById('editOverlay').classList.remove('show');
}
document.getElementById('editOverlay').addEventListener('click', e => {
  if (e.target === document.getElementById('editOverlay')) closeModal();
});

// ── Save ──────────────────────────────────────────────────────────────────────
async function saveProfit() {
  const tradeId = parseInt(document.getElementById('m_trade_id').value);
  const profit  = parseFloat(document.getElementById('m_profit').value);
  const note    = document.getElementById('m_note').value.trim();

  if (isNaN(profit) || profit < 0) { toast('Enter a valid profit amount (0 or above)', 'e'); return; }

  const btn = document.getElementById('saveBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Saving…';

  try {
    const res  = await fetch('admin_copy_profits.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body:    JSON.stringify({ action: 'set_profit', trade_id: tradeId, profit, note })
    });
    const data = await res.json();

    if (data.success) {
      toast('Profit saved successfully!', 's');
      closeModal();
      setTimeout(() => location.reload(), 1200);
    } else {
      toast(data.message || 'Failed to save', 'e');
    }
  } catch (e) {
    toast('Connection error', 'e');
  }

  btn.disabled = false;
  btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Save Profit';
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function toast(msg, type = 's') {
  const wrap = document.getElementById('toastWrap');
  const el   = document.createElement('div');
  el.className = `toast-item ${type}`;
  el.innerHTML = `<i class="bi bi-${type === 's' ? 'check-circle' : 'exclamation-circle'}"></i> ${msg}`;
  wrap.appendChild(el);
  setTimeout(() => el.remove(), 3500);
}
</script>
</body>
</html>