<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

include __DIR__ . '/db.php';
$user_id = $_SESSION['user_id'];
$message = "";

/* ======================
    FETCH USER DATA
====================== */
$stmt_u = $conn->prepare("SELECT name, balance FROM users WHERE id = ?");
$stmt_u->bind_param("i", $user_id);
$stmt_u->execute();
$user_data = $stmt_u->get_result()->fetch_assoc();
$balance = $user_data['balance'] ?? 0.00;

/* ======================
    HANDLE DEPOSIT
====================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = floatval($_POST['amount']);
    $wallet_id = (int)$_POST['wallet_id'];
    $txn = trim($_POST['txn_id']);
    
    // File Upload Logic
    $proof_path = "";
    if (isset($_FILES['proof_img']) && $_FILES['proof_img']['error'] == 0) {
        $upload_dir = 'uploads/proofs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $ext = pathinfo($_FILES['proof_img']['name'], PATHINFO_EXTENSION);
        $filename = "proof_" . time() . "_" . $user_id . "." . $ext;
        $proof_path = $upload_dir . $filename;
        move_uploaded_file($_FILES['proof_img']['tmp_name'], $proof_path);
    }

    if ($amount <= 0 || !$wallet_id || !$txn || empty($proof_path)) {
        $message = "<div class='alert alert-danger border-0 bg-danger text-white'>Please fill all fields and upload proof.</div>";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO deposits (user_id, amount, payment_method, txn_id, proof_img, status, created_at)
            SELECT ?, ?, CONCAT(currency,' ',network), ?, ?, 'Pending', NOW()
            FROM deposit_wallets WHERE id=?
        ");
        $stmt->bind_param("idssi", $user_id, $amount, $txn, $proof_path, $wallet_id);

        if ($stmt->execute()) {
            $message = "<div class='alert alert-success border-0 bg-success text-white'>Deposit submitted! Awaiting verification.</div>";
        } else {
            $message = "<div class='alert alert-danger border-0 bg-danger text-white'>Error: " . $conn->error . "</div>";
        }
    }
}

$wallets = $conn->query("SELECT * FROM deposit_wallets WHERE is_active=1 ORDER BY currency ASC");
$deps = $conn->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC");
$deps->bind_param("i", $user_id);
$deps->execute();
$deposits = $deps->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fund Account | Dominion Elite</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --bg: #030508; --card-bg: rgba(15, 22, 36, 0.7); --accent: #d4af37; --text-muted: #64748b; --border: rgba(255, 255, 255, 0.08); }
        body { background: var(--bg); color: #fff; font-family: 'Plus Jakarta Sans', sans-serif; }
        
        /* Sidebar Styles (Matching Dashboard) */
        .sidebar { width: 280px; background: #070a11; position: fixed; height: 100vh; border-right: 1px solid var(--border); padding: 30px 20px; z-index: 1000; }
        .sidebar a { display: flex; align-items: center; gap: 15px; padding: 14px 20px; color: var(--text-muted); text-decoration: none; border-radius: 12px; margin-bottom: 8px; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { background: rgba(212, 175, 55, 0.1); color: var(--accent); }
        .sidebar a.active { background: var(--accent); color: #000; font-weight: 700; }

        .main-content { margin-left: 280px; padding: 40px; }
        .glass-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 24px; padding: 30px; backdrop-filter: blur(10px); }
        
        .form-control { background: rgba(255,255,255,0.05); border: 1px solid var(--border); color: #fff; border-radius: 12px; padding: 12px; }
        .form-control:focus { background: rgba(255,255,255,0.08); color: #fff; border-color: var(--accent); box-shadow: none; }
        
        .wallet-pill { background: rgba(212, 175, 55, 0.05); border: 1px solid var(--border); border-radius: 16px; padding: 20px; transition: 0.3s; }
        .wallet-pill:hover { border-color: var(--accent); }

        .status-badge { padding: 4px 12px; border-radius: 50px; font-size: 12px; font-weight: 700; }
        .status-Pending { background: rgba(234, 179, 8, 0.1); color: #eab308; }
        .status-Approved { background: rgba(34, 197, 94, 0.1); color: #22c55e; }

        @media (max-width: 992px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 20px; padding-bottom: 100px; }
            .bottom-nav { display: flex; position: fixed; bottom: 0; width: 100%; background: #070a11; border-top: 1px solid var(--border); justify-content: space-around; padding: 15px; z-index: 1000; }
        }
        .bottom-nav { display: none; }
        .bottom-nav a { color: var(--text-muted); font-size: 20px; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="text-center mb-5"><h2 style="color:var(--accent); letter-spacing: 2px;">DOMINION</h2></div>
    <nav>
        <a href="dashboard.php"><i class="fa fa-home"></i> Overview</a>
        <a href="deposit.php" class="active"><i class="fa fa-vault"></i> Deposit</a>
        <a href="invest.php"><i class="fa fa-chart-line"></i> Investments</a>
        <a href="notification.php"><i class="fa fa-bell"></i> Notifications</a>
        <a href="logout.php" class="text-danger"><i class="fa fa-power-off"></i> Logout</a>
    </nav>
</div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="fw-bold">Deposit Funds</h1>
            <p class="text-muted">Available Balance: <span class="text-white fw-bold">$<?= number_format($balance, 2) ?></span></p>
        </div>
    </div>

    <?= $message ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <h5 class="mb-3 text-accent"><i class="fa fa-wallet me-2 text-warning"></i> 1. Select Asset</h5>
            <div class="d-flex flex-column gap-3">
                <?php while($w = $wallets->fetch_assoc()): ?>
                <div class="wallet-pill">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong class="text-warning"><?= $w['currency'] ?></strong>
                        <span class="badge bg-secondary opacity-50"><?= $w['network'] ?></span>
                    </div>
                    <code class="d-block text-white mb-2" id="addr_<?= $w['id'] ?>"><?= $w['address'] ?></code>
                    <button onclick="copyAddr('addr_<?= $w['id'] ?>')" class="btn btn-sm btn-outline-light border-0 opacity-50"><i class="fa fa-copy me-1"></i> Copy Address</button>
                </div>
                <?php endwhile; ?>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="glass-card">
                <h5 class="mb-4"><i class="fa fa-paper-plane me-2 text-warning"></i> 2. Submit Transaction</h5>
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="text-muted small mb-2">Target Wallet</label>
                        <select name="wallet_id" class="form-control" required>
                            <option value="">Select the wallet you paid into</option>
                            <?php $wallets->data_seek(0); while($w=$wallets->fetch_assoc()): ?>
                            <option value="<?= $w['id'] ?>"><?= $w['currency'] ?> (<?= $w['network'] ?>)</option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small mb-2">Amount ($)</label>
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small mb-2">Transaction Hash/ID</label>
                            <input type="text" name="txn_id" class="form-control" placeholder="Paste hash here" required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="text-muted small mb-2">Upload Proof (Screenshot)</label>
                        <input type="file" name="proof_img" class="form-control" accept="image/*" required>
                    </div>

                    <button class="btn btn-warning w-100 fw-bold py-3 shadow" style="background: var(--accent); color: #000;">CONFIRM DEPOSIT</button>
                </form>
            </div>
        </div>

        <div class="col-12 mt-4">
            <div class="glass-card overflow-hidden">
                <h5 class="mb-4">Recent Deposit History</h5>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle">
                        <thead class="text-muted">
                            <tr>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Transaction ID</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($d = $deposits->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($d['payment_method']) ?></td>
                                <td class="fw-bold">$<?= number_format($d['amount'], 2) ?></td>
                                <td class="small opacity-50"><?= substr($d['txn_id'], 0, 15) ?>...</td>
                                <td><span class="status-badge status-<?= $d['status'] ?>"><?= $d['status'] ?></span></td>
                                <td class="text-muted"><?= date('M d, Y', strtotime($d['created_at'])) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="bottom-nav">
    <a href="dashboard.php"><i class="fa fa-home"></i></a>
    <a href="deposit.php" style="color:var(--accent)"><i class="fa fa-plus-circle"></i></a>
    <a href="invest.php"><i class="fa fa-chart-line"></i></a>
    <a href="notification.php"><i class="fa fa-bell"></i></a>
</div>


<script>
    function copyAddr(id) {
        let text = document.getElementById(id).innerText;
        navigator.clipboard.writeText(text).then(() => {
            alert('Address copied to clipboard!');
        });
    }
</script>

</body>
</html>