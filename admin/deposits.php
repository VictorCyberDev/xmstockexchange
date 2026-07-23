<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
include __DIR__ . '/../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

/* ============================================================
   1. AJAX ACTIONS (Approve/Reject)
   ============================================================ */
if (isset($_POST['ajax'], $_POST['deposit_id'], $_POST['action'])) {
    header('Content-Type: application/json');
    $deposit_id = (int)$_POST['deposit_id'];
    $action = $_POST['action'];

    $stmt = $conn->prepare("SELECT d.*, u.id AS uid FROM deposits d JOIN users u ON u.id=d.user_id WHERE d.id=?");
    $stmt->bind_param("i", $deposit_id);
    $stmt->execute();
    $deposit = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$deposit) exit(json_encode(['success' => false, 'error' => 'Deposit not found']));
    if (strtolower($deposit['status']) !== 'pending') 
        exit(json_encode(['success' => false, 'error' => 'Already processed']));

    $newStatus = ($action === 'approve') ? 'Approved' : 'Rejected';

    // Update Status
    $stmt = $conn->prepare("UPDATE deposits SET status=? WHERE id=?");
    $stmt->bind_param("si", $newStatus, $deposit_id);
    $stmt->execute();

    if ($newStatus === 'Approved') {
        $stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id=?");
        $stmt->bind_param("di", $deposit['amount'], $deposit['uid']);
        $stmt->execute();
    }

    // Notification
    $title = "Deposit $newStatus";
    $msg = "Your deposit of $" . number_format($deposit['amount'], 2) . " was $newStatus.";
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?,?,?)");
    $stmt->bind_param("iss", $deposit['uid'], $title, $msg);
    $stmt->execute();

    echo json_encode(['success' => true, 'status' => $newStatus]);
    exit;
}

/* ============================================================
   2. WALLET MANAGEMENT (Add/Update/Delete)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax'])) {
    // Save/Update
    if (isset($_POST['save_payment_method'])) {
        $id = $_POST['wallet_id'] ?? null;
        $currency = $_POST['currency'];
        $network = $_POST['network'];
        $address = $_POST['address'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($id) {
            $stmt = $conn->prepare("UPDATE deposit_wallets SET currency=?, network=?, address=?, is_active=? WHERE id=?");
            $stmt->bind_param("sssii", $currency, $network, $address, $is_active, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO deposit_wallets (currency, network, address, is_active) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $currency, $network, $address, $is_active);
        }
        $stmt->execute();
        header("Location: deposits.php?success=1"); exit;
    }

    // Delete
    if (isset($_POST['delete_wallet'])) {
        $id = $_POST['wallet_id'];
        $stmt = $conn->prepare("DELETE FROM deposit_wallets WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header("Location: deposits.php"); exit;
    }
}

/* ============================================================
   3. DATA FETCHING
   ============================================================ */
$total_pending = $conn->query("SELECT SUM(amount) as amt FROM deposits WHERE status='Pending'")->fetch_assoc()['amt'] ?? 0;
$total_approved = $conn->query("SELECT SUM(amount) as amt FROM deposits WHERE status='Approved'")->fetch_assoc()['amt'] ?? 0;

$wallets = $conn->query("SELECT * FROM deposit_wallets ORDER BY id DESC");
$deposits = $conn->query("SELECT d.*, u.name, u.email FROM deposits d JOIN users u ON u.id = d.user_id ORDER BY d.created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Deposits | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --accent: #3b82f6; --bg-dark: #0b0f19; --card-bg: #161b2c; }
        body { background: var(--bg-dark); color: #f3f4f6; padding-bottom: 80px; }
        .sidebar { width: 260px; background: #111827; position: fixed; height: 100vh; border-right: 1px solid #1f2937; display: none; }
        .content-area { padding: 20px; width: 100%; }
        @media (min-width: 992px) { .sidebar { display: block; } .content-area { margin-left: 260px; width: calc(100% - 260px); } }
        .glass-card { background: var(--card-bg); border: 1px solid rgba(255,255,255,.05); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .status-pill { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: bold; }
        .pending { background: #f59e0b15; color: #f59e0b; }
        .approved { background: #10b98115; color: #10b981; }
        .rejected { background: #ef444415; color: #ef4444; }
        .form-control { background: #1f2937; border: 1px solid #374151; color: white; }
        .mobile-nav { position: fixed; bottom: 0; left: 0; right: 0; background: #111827; display: flex; justify-content: space-around; padding: 12px; border-top: 1px solid #1f2937; z-index: 1000; }
        @media (min-width: 992px) { .mobile-nav { display: none; } }
    </style>
</head>
<body>

<div class="sidebar p-4">
    <h4 class="text-white mb-5"><i class="fa fa-gem me-2"></i>Admin Panel</h4>
    <div class="nav flex-column gap-2">
        <a href="admin_dashboard.php" class="nav-link text-white opacity-50"><i class="fa fa-th-large me-2"></i> Dashboard</a>
        <a href="deposits.php" class="nav-link text-white active"><i class="fa fa-wallet me-2"></i> Deposits</a>
        <a href="logout.php" class="nav-link text-danger mt-4"><i class="fa fa-sign-out me-2"></i> Logout</a>
    </div>
</div>

<div class="content-area">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-0">Deposits</h2>
            <small class="text-muted">Manage wallet methods and funding requests</small>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="glass-card m-0 border-start border-warning border-4">
                <small class="text-muted d-block">Pending</small>
                <span class="fs-4 fw-bold text-warning">$<?= number_format($total_pending, 2) ?></span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="glass-card m-0 border-start border-success border-4">
                <small class="text-muted d-block">Approved</small>
                <span class="fs-4 fw-bold text-success">$<?= number_format($total_approved, 2) ?></span>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>Payment Methods</h5>
        <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#addNewMethod">
            <i class="fa fa-plus"></i> Add New
        </button>
    </div>

    <div class="collapse mb-4" id="addNewMethod">
        <div class="glass-card border border-primary">
            <form method="post" class="row g-3">
                <div class="col-md-3"><input type="text" name="currency" class="form-control" placeholder="BTC" required></div>
                <div class="col-md-3"><input type="text" name="network" class="form-control" placeholder="BEP20" required></div>
                <div class="col-md-4"><input type="text" name="address" class="form-control" placeholder="Address" required></div>
                <div class="col-md-2"><button type="submit" name="save_payment_method" class="btn btn-success w-100">Save</button></div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-5">
        <?php while($w = $wallets->fetch_assoc()): ?>
        <div class="col-md-4">
            <div class="glass-card h-100">
                <form method="post">
                    <input type="hidden" name="wallet_id" value="<?= $w['id'] ?>">
                    <div class="d-flex justify-content-between mb-2">
                        <input type="text" name="currency" class="form-control form-control-sm w-50 fw-bold border-0 bg-transparent text-white" value="<?= $w['currency'] ?>">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" <?= $w['is_active'] ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <input type="text" name="network" class="form-control form-control-sm mb-2" value="<?= $w['network'] ?>">
                    <input type="text" name="address" class="form-control form-control-sm mb-3 text-info" value="<?= $w['address'] ?>">
                    <div class="d-flex justify-content-between">
                        <button type="submit" name="delete_wallet" class="btn btn-link text-danger p-0" onclick="return confirm('Delete?')"><i class="fa fa-trash"></i></button>
                        <button type="submit" name="save_payment_method" class="btn btn-sm btn-outline-success">Update</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endwhile; ?>
    </div>

    <h5 class="mb-3">Recent Requests</h5>
    <div class="glass-card p-0 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0">
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
                    <?php while($d = $deposits->fetch_assoc()): ?>
                    <tr id="deposit-row-<?= $d['id'] ?>">
                        <td>
                            <div class="fw-bold"><?= $d['name'] ?></div>
                            <small class="text-muted"><?= $d['email'] ?></small>
                        </td>
                        <td class="fw-bold text-success">$<?= number_format($d['amount'], 2) ?></td>
                        <td><?= $d['payment_method'] ?></td>
                        <td>
                            <span class="status-pill <?= strtolower($d['status']) ?>" id="status-<?= $d['id'] ?>">
                                <?= $d['status'] ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewDep<?= $d['id'] ?>">View</button>
                        </td>
                    </tr>

                    <div class="modal fade" id="viewDep<?= $d['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content bg-dark border-secondary">
                                <div class="modal-header border-secondary">
                                    <h5 class="modal-title">Deposit Details</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body text-start">
                                    <p><strong>TXN ID:</strong> <span class="text-info"><?= $d['txn_id'] ?></span></p>
                                    <p><strong>Amount:</strong> $<?= number_format($d['amount'], 2) ?></p>
                                    <hr class="border-secondary">
                                    <h6>Proof Image:</h6>
                                    <?php if($d['proof_img']): ?>
                                        <a href="../<?= $d['proof_img'] ?>" target="_blank">
                                            <img src="../<?= $d['proof_img'] ?>" class="img-fluid rounded border border-secondary">
                                        </a>
                                    <?php else: ?>
                                        <p class="text-muted">No proof uploaded</p>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer border-secondary" id="footer-<?= $d['id'] ?>">
                                    <?php if(strtolower($d['status']) === 'pending'): ?>
                                        <button class="btn btn-danger btn-sm action-btn" data-id="<?= $d['id'] ?>" data-action="reject">Reject</button>
                                        <button class="btn btn-success btn-sm action-btn" data-id="<?= $d['id'] ?>" data-action="approve">Approve</button>
                                    <?php else: ?>
                                        <span class="text-muted">Processed (<?= $d['status'] ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mobile-nav">
    <a href="admin_dashboard.php"><i class="fa fa-home"></i></a>
    <a href="deposits.php" class="active"><i class="fa fa-wallet"></i></a>
    <a href="users.php"><i class="fa fa-users"></i></a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.action-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const action = this.dataset.action;
        if (!confirm(`Confirm ${action}?`)) return;

        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        fetch('deposits.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax=1&deposit_id=${id}&action=${action}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById(`status-${id}`).textContent = data.status;
                document.getElementById(`status-${id}`).className = `status-pill ${data.status.toLowerCase()}`;
                document.getElementById(`footer-${id}`).innerHTML = `<span class="text-success">Processed as ${data.status}</span>`;
                setTimeout(() => { location.reload(); }, 1000);
            } else {
                alert(data.error);
                this.disabled = false;
                this.innerHTML = action;
            }
        });
    });
});
</script>
</body>
</html>