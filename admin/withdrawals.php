<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
include __DIR__ . '/../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

// ── AJAX HANDLER ──────────────────────────────────────────────────
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $withdraw_id = (int)$_POST['withdraw_id'];
    $action      = $_POST['action'] ?? '';

    $stmt = $conn->prepare("SELECT w.*, u.id AS uid FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.id = ?");
    $stmt->bind_param("i", $withdraw_id);
    $stmt->execute();
    $withdraw = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$withdraw) {
        echo json_encode(['success' => false, 'error' => 'Record not found']);
        exit;
    }

    $newStatus = ($action === 'approve') ? 'Completed' : 'Rejected';

    $upd = $conn->prepare("UPDATE withdrawals SET status = ?, processed_at = NOW() WHERE id = ?");
    $upd->bind_param("si", $newStatus, $withdraw_id);
    $upd->execute();
    $upd->close();

    if ($newStatus === 'Rejected') {
        $ref = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $ref->bind_param("di", $withdraw['amount'], $withdraw['uid']);
        $ref->execute();
        $ref->close();
    }

    $title = "Withdrawal " . $newStatus;
    $msg   = "Your withdrawal of $" . number_format($withdraw['amount'], 2) . " has been " . strtolower($newStatus) . ".";
    $notif = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
    $notif->bind_param("iss", $withdraw['uid'], $title, $msg);
    $notif->execute();
    $notif->close();

    echo json_encode(['success' => true, 'status' => $newStatus]);
    exit;
}

$stats = $conn->query("
    SELECT 
        COUNT(*) AS total_count,
        SUM(CASE WHEN LOWER(status) = 'pending'   THEN amount ELSE 0 END) AS pending_sum,
        SUM(CASE WHEN LOWER(status) = 'completed' THEN amount ELSE 0 END) AS paid_sum,
        COUNT(CASE WHEN LOWER(status) = 'pending' THEN 1 END) AS pending_count
    FROM withdrawals
")->fetch_assoc();

$withdrawals = $conn->query("
    SELECT 
        w.id, w.method, w.amount, w.withdraw_details, w.status, w.created_at,
        u.username AS name,
        u.email,
        u.balance AS user_curr_balance
    FROM withdrawals w
    JOIN users u ON u.id = w.user_id
    ORDER BY w.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payouts — Xmstockexchange</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap');
        :root { --primary: #6366f1; --bg: #030712; --card: #111827; --border: #1f2937; }

        body { background: var(--bg); color: #f3f4f6; font-family: 'Plus Jakarta Sans', sans-serif; min-height: 100vh; margin: 0; }

        /* Sidebar Logic */
        .sidebar { width: 260px; background: #0b0f1a; position: fixed; top: 0; left: 0; height: 100vh; border-right: 1px solid var(--border); padding: 2rem 1.5rem; z-index: 1050; transition: transform 0.3s ease; }
        .sidebar .logo { display: flex; align-items: center; gap: 12px; margin-bottom: 2.5rem; }
        .sidebar .logo .icon { background: var(--primary); width: 38px; height: 38px; border-radius: 10px; display:flex; align-items:center; justify-content:center; }
        .sidebar .nav-link { display: flex; align-items: center; gap: 10px; color: #6b7280; padding: 12px 14px; border-radius: 10px; font-size: 14px; font-weight: 600; text-decoration: none; transition: 0.2s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(99,102,241,0.1); color: #a5b4fc; }
        .sidebar .nav-link.active { color: var(--primary); }

        /* Mobile Header */
        .mobile-header { display: none; background: #0b0f1a; padding: 1rem; border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 1000; }

        /* Content Area */
        .content-area { margin-left: 260px; padding: 2.5rem; transition: margin 0.3s ease; }

        .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 18px; padding: 1.5rem; height: 100%; }
        .stat-card small { font-size: 0.7rem; letter-spacing: 1px; color: #6b7280; text-transform: uppercase; }
        .stat-card h2 { font-size: 1.5rem; font-weight: 800; margin: 6px 0 0; }

        .table-container { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 1.5rem; margin-top: 2rem; }
        .table { color: #f3f4f6; margin: 0; }
        .table thead th { background: transparent; color: #6b7280; font-size: 0.7rem; letter-spacing: 1px; text-transform: uppercase; border-bottom: 1px solid var(--border); padding: 1rem 0.75rem; white-space: nowrap; }
        .table tbody tr { border-bottom: 1px solid rgba(31,41,55,0.6); }
        .table td { padding: 1rem 0.75rem; vertical-align: middle; border: none; }

        .status-pill { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .status-pill.pending   { background: rgba(245,158,11,0.1);  color: #fbbf24; border: 1px solid rgba(245,158,11,0.25); }
        .status-pill.completed { background: rgba(16,185,129,0.1);  color: #34d399; border: 1px solid rgba(16,185,129,0.25); }
        .status-pill.rejected  { background: rgba(239,68,68,0.1);   color: #f87171; border: 1px solid rgba(239,68,68,0.25); }

        /* Responsive Breakpoints */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .content-area { margin-left: 0; padding: 1.5rem; }
            .mobile-header { display: flex; justify-content: space-between; align-items: center; }
            .desktop-only-table { display: none; }
            .mobile-cards { display: block; }
        }

        @media (min-width: 993px) {
            .mobile-cards { display: none; }
        }

        /* Mobile specific card list */
        .mobile-payout-card { background: var(--card); border: 1px solid var(--border); border-radius: 15px; padding: 1rem; margin-bottom: 1rem; }

        .modal-content { background: #0f172a; border: 1px solid #1e293b; border-radius: 24px; color: #f1f5f9; }
        .wallet-box { background: #060b14; border: 1px solid var(--border); border-radius: 12px; padding: 12px; word-break: break-all; }
        .btn-approve { background: var(--primary); color: #fff; border: none; border-radius: 12px; padding: 10px; font-weight: 700; }
        .btn-reject  { background: transparent; color: #f87171; border: 1px solid rgba(239,68,68,0.3); border-radius: 12px; padding: 10px; font-weight: 700; }
        
        .sidebar-overlay { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index: 1040; }
        .sidebar-overlay.show { display: block; }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="mobile-header">
    <div class="d-flex align-items-center gap-2">
        <div style="background: var(--primary); width: 30px; height: 30px; border-radius: 8px; display:flex; align-items:center; justify-content:center;">
            <i class="fa fa-bolt text-white" style="font-size:12px;"></i>
        </div>
        <span class="fw-800" style="font-size:14px;">Xmstock</span>
    </div>
    <button class="btn text-white p-0" id="menuToggle"><i class="fa fa-bars fa-lg"></i></button>
</div>

<div class="sidebar" id="sidebar">
    <div class="logo">
        <div class="icon"><i class="fa fa-bolt text-white" style="font-size:16px;"></i></div>
        <h5 class="fw-800 mb-0" style="font-size:15px;">Xmstockexchange</h5>
    </div>
    <nav class="d-flex flex-column gap-1">
        <a href="admin_dashboard.php" class="nav-link"><i class="fa fa-gauge-high"></i> Overview</a>
        <a href="withdrawals.php" class="nav-link active"><i class="fa fa-receipt"></i> Payouts</a>
        <a href="users.php" class="nav-link"><i class="fa fa-users"></i> Customers</a>
    </nav>
</div>

<div class="content-area">
    <div class="mb-4">
        <h1 class="fw-800 mb-1" style="font-size:1.5rem;">Withdrawals</h1>
        <p class="text-muted small mb-0">
            <span class="text-primary fw-bold"><?= (int)$stats['pending_count'] ?> pending</span> requests.
        </p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <small>Paid Out</small>
                <h2 class="text-success">$<?= number_format($stats['paid_sum'] ?? 0, 0) ?></h2>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <small>Pending</small>
                <h2 class="text-warning">$<?= number_format($stats['pending_sum'] ?? 0, 0) ?></h2>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stat-card">
                <small>Total Requests</small>
                <h2 class="text-primary"><?= (int)$stats['total_count'] ?></h2>
            </div>
        </div>
    </div>

    <div class="table-container desktop-only-table">
        <table class="table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $withdrawals->data_seek(0);
                $rows = [];
                while ($w = $withdrawals->fetch_assoc()): 
                    $rows[] = $w;
                    $statusClass = strtolower($w['status']);
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($w['name']) ?></strong><br>
                        <small class="text-muted"><?= htmlspecialchars($w['email']) ?></small>
                    </td>
                    <td class="text-danger fw-bold">-$<?= number_format($w['amount'], 2) ?></td>
                    <td><small><?= htmlspecialchars($w['method'] ?? '') ?></small></td>
                    <td><span class="status-pill <?= $statusClass ?>"><?= $w['status'] ?></span></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-dark rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#modal-<?= $w['id'] ?>">Review</button>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div class="mobile-cards">
        <?php foreach ($rows as $w): $statusClass = strtolower($w['status']); ?>
            <div class="mobile-payout-card">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars($w['name']) ?></div>
                        <div class="text-muted small"><?= date('M d, Y', strtotime($w['created_at'])) ?></div>
                    </div>
                    <span class="status-pill <?= $statusClass ?>"><?= $w['status'] ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <div class="text-danger fw-bold" style="font-size: 1.1rem;">-$<?= number_format($w['amount'], 2) ?></div>
                    <button class="btn btn-sm btn-outline-light rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#modal-<?= $w['id'] ?>">Review</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php foreach ($rows as $w): $statusClass = strtolower($w['status']); ?>
<div class="modal fade" id="modal-<?= $w['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm modal-md">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h6 class="fw-bold mb-0">Payout #<?= $w['id'] ?></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <small class="text-muted d-block mb-1">USER</small>
                    <strong><?= htmlspecialchars($w['name']) ?></strong>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="wallet-box">
                            <small class="text-muted d-block mb-1">AMOUNT</small>
                            <strong class="text-danger">$<?= number_format($w['amount'], 2) ?></strong>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="wallet-box">
                            <small class="text-muted d-block mb-1">METHOD</small>
                            <strong class="small"><?= htmlspecialchars($w['method'] ?? '—') ?></strong>
                        </div>
                    </div>
                </div>
                <div class="wallet-box">
                    <small class="text-muted d-block mb-1">DETAILS</small>
                    <code class="small" style="color:#94a3b8;"><?= htmlspecialchars($w['withdraw_details'] ?? 'N/A') ?></code>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <?php if ($statusClass === 'pending'): ?>
                    <button class="btn btn-reject flex-grow-1 action-trigger" data-id="<?= $w['id'] ?>" data-action="reject">Reject</button>
                    <button class="btn btn-approve flex-grow-1 action-trigger" data-id="<?= $w['id'] ?>" data-action="approve">Approve</button>
                <?php else: ?>
                    <button class="btn btn-secondary w-100 rounded-pill" disabled><?= strtoupper($w['status']) ?></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function toggleMenu() {
        sidebar.classList.toggle('show');
        overlay.classList.toggle('show');
    }

    menuToggle.addEventListener('click', toggleMenu);
    overlay.addEventListener('click', toggleMenu);

    document.querySelectorAll('.action-trigger').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const action = this.dataset.action;
            const self = this;
            self.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            self.disabled = true;

            const params = new URLSearchParams();
            params.append('ajax_action', '1');
            params.append('withdraw_id', id);
            params.append('action', action);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                    self.disabled = false;
                    self.textContent = action === 'approve' ? 'Approve' : 'Reject';
                }
            });
        });
    });
</script>
</body>
</html>