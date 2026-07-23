<?php
// 1. Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

// 2. Auth Guard
if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['action'])) {
        echo json_encode(['success' => false, 'error' => 'Session Expired']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$currentUserId = (int) $_SESSION['user_id'];
$db = $conn; 

// 3. Fetch User Data
$uStmt = $db->prepare("SELECT email, username, balance FROM users WHERE id = ?");
$uStmt->bind_param("i", $currentUserId);
$uStmt->execute();
$user = $uStmt->get_result()->fetch_assoc();
$uStmt->close();

// 4. Calculate Total Withdrawable Balance (Sum of Confirmed Deposits + Balance)
$balanceStmt = $db->prepare("SELECT IFNULL(SUM(amount), 0) as deposit_sum FROM deposits WHERE user_id = ? AND status = 'Confirmed'");
$balanceStmt->bind_param("i", $currentUserId);
$balanceStmt->execute();
$depositData = $balanceStmt->get_result()->fetch_assoc();
$balanceStmt->close();

$totalBalance = floatval(($user['balance'] ?? 0) + ($depositData['deposit_sum'] ?? 0));

// 5. Handle Withdrawal Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_withdrawal') {
    header('Content-Type: application/json');

    $asset   = trim($_POST['asset'] ?? '');
    $network = trim($_POST['network'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $amount  = floatval($_POST['amount'] ?? 0);
    $fullDetails = "Network: " . $network . " | Address: " . $address;

    if ($amount <= 0 || $amount > $totalBalance) {
        echo json_encode(['success' => false, 'error' => 'Insufficient funds. Max: $' . number_format($totalBalance, 2)]);
        exit;
    }

    $db->begin_transaction();
    try {
        $txn_id = 'WDR' . strtoupper(uniqid());
        $stmt = $db->prepare("INSERT INTO withdrawals (txn_id, user_id, amount, method, withdraw_details, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->bind_param("sidss", $txn_id, $currentUserId, $amount, $asset, $fullDetails);
        $stmt->execute();
        $withdrawal_id = $db->insert_id;

        // FIFO Deduction logic
        $remainingToDeduct = $amount;
        $depStmt = $db->prepare("SELECT id, amount FROM deposits WHERE user_id = ? AND status = 'Confirmed' AND amount > 0 ORDER BY created_at ASC");
        $depStmt->bind_param("i", $currentUserId);
        $depStmt->execute();
        $confirmedDeposits = $depStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($confirmedDeposits as $deposit) {
            if ($remainingToDeduct <= 0) break;
            $dId = (int)$deposit['id'];
            $dAmount = floatval($deposit['amount']);

            if ($dAmount <= $remainingToDeduct) {
                $remainingToDeduct -= $dAmount;
                $db->query("UPDATE deposits SET amount = 0 WHERE id = $dId");
            } else {
                $db->query("UPDATE deposits SET amount = amount - $remainingToDeduct WHERE id = $dId");
                $remainingToDeduct = 0;
            }
        }

        if ($remainingToDeduct > 0) {
            $db->query("UPDATE users SET balance = GREATEST(0, balance - $remainingToDeduct) WHERE id = $currentUserId");
        }

        $db->commit();

        // --- BRANDED EMAIL ---
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.hostinger.com'; // Use your real SMTP host
            $mail->SMTPAuth   = true;
            $mail->Username   = 'support@xmstockexchange.org';
            $mail->Password   = '@Xmstockexchange01';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('support@xmstockexchange.org', 'Xm Stock Exchange');
            $mail->addAddress($user['email'], $user['username']);
            $mail->isHTML(true);
            $mail->Subject = 'Withdrawal Request Received - ' . $txn_id;
            
            $mail->Body = "
            <div style='background-color: #f4f7f9; padding: 20px; font-family: sans-serif;'>
                <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e1e8ed;'>
                    <div style='background: #0d1420; padding: 30px; text-align: center;'>
                        <h1 style='color: #00d4ff; margin: 0; font-size: 24px;'>XM STOCK EXCHANGE</h1>
                    </div>
                    <div style='padding: 40px; color: #333;'>
                        <p>Hello <strong>{$user['username']}</strong>,</p>
                        <p>Your withdrawal request has been logged successfully and is awaiting manual verification.</p>
                        <div style='background: #f8fafc; padding: 20px; border-radius: 6px; margin: 20px 0;'>
                            <strong>ID:</strong> #{$txn_id}<br>
                            <strong>Amount:</strong> $".number_format($amount, 2)."<br>
                            <strong>Asset:</strong> {$asset} ({$network})
                        </div>
                        <p style='font-size: 12px; color: #666;'>Please ensure the 20% processing fee is settled to avoid delays.</p>
                    </div>
                </div>
            </div>";
            $mail->send();
        } catch (Exception $e) { error_log("Mail Error: " . $mail->ErrorInfo); }

        echo json_encode(['success' => true, 'withdrawal_id' => $withdrawal_id]);
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 6. Fetch History
$histStmt = $db->prepare("SELECT id, method, amount, withdraw_details, status, created_at FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$histStmt->bind_param("i", $currentUserId);
$histStmt->execute();
$withdrawalHistory = $histStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$histStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdraw — Xmstockexchange</title>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono&family=Syne:wght@400;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --bg: #080c14; --surface: #0d1420; --border: #1e2d45; --accent: #00d4ff; 
            --green: #10b981; --text: #e2e8f0; --muted: #64748b; --gold: #f59e0b; --red: #ef4444;
        }
        body { background: var(--bg); color: var(--text); font-family: 'Syne', sans-serif; padding: 15px; margin: 0; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 24px; max-width: 500px; margin: 20px auto; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 10px; color: var(--muted); margin-bottom: 8px; text-transform: uppercase; font-weight: 700; }
        input, select { width: 100%; padding: 14px; background: #111827; border: 1px solid var(--border); color: #fff; border-radius: 10px; outline: none; }
        .btn { width: 100%; padding: 16px; border: none; border-radius: 12px; font-weight: 800; cursor: pointer; background: linear-gradient(135deg, #7c3aed, #4f46e5); color: #fff; text-transform: uppercase; }
        .history-table { width: 100%; border-collapse: collapse; }
        .history-table th { font-size: 9px; color: var(--muted); padding: 10px; text-align: left; border-bottom: 1px solid var(--border); }
        .history-table td { padding: 12px 10px; font-size: 13px; border-bottom: 1px solid rgba(30,45,69,0.5); font-family: 'Space Mono'; }
        .pill { padding: 3px 10px; border-radius: 6px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .pill-pending { background: rgba(245,158,11,0.12); color: #fbbf24; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.92); display: none; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(8px); }
        .modal-overlay.open { display: flex; }
        .modal { background: var(--surface); padding: 25px; border-radius: 20px; border: 1px solid var(--border); width: 100%; max-width: 450px; }
        .highlight-box { background: rgba(245,158,11,0.08); border-left: 3px solid var(--gold); padding: 15px; margin: 15px 0; font-family: 'Space Mono'; }
        .pay-btn { display: block; background: var(--gold); color: #000; padding: 14px; border-radius: 8px; font-weight: 800; cursor: pointer; width: 100%; border: none; text-transform: uppercase; }
        .success-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.95); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .success-overlay.open { display: flex; }
        .success-box { background: var(--surface); border: 1px solid var(--green); border-radius: 20px; padding: 35px; text-align: center; max-width: 400px; width: 100%; }
        .checkmark { width: 50px; height: 50px; background: rgba(16,185,129,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: var(--green); }
    </style>
</head>
<body>

<div class="card">
    <h3 style="color:var(--accent);">WITHDRAW FUNDS</h3>
    <div style="background:rgba(16,185,129,0.05); padding:15px; border-radius:12px; margin-bottom:25px;">
        <small style="color:var(--muted);">Withdrawable Balance</small>
        <div style="font-size:24px; color:var(--green); font-weight:800;">$<?= number_format($totalBalance, 2) ?></div>
    </div>
    <div class="form-group"><label>Asset</label><select id="w-asset"><option value="BTC">Bitcoin (BTC)</option><option value="USDT">Tether (USDT)</option></select></div>
    <div class="form-group"><label>Network</label><input type="text" id="w-network" placeholder="e.g. TRC20"></div>
    <div class="form-group"><label>Wallet Address</label><input type="text" id="w-address" placeholder="Destination address"></div>
    <div class="form-group"><label>Amount (USD)</label><input type="number" id="w-amount" placeholder="0.00"></div>
    <button class="btn" type="button" onclick="openWithdrawModal()">Review Transaction →</button>
</div>

<div class="card">
    <h3 style="font-size:15px;">WITHDRAWAL HISTORY</h3>
    <div style="overflow-x:auto;">
        <table class="history-table">
            <thead><tr><th>Asset</th><th>Amount</th><th>Details</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
                <?php foreach ($withdrawalHistory as $hw): ?>
                <tr>
                    <td><?= htmlspecialchars($hw['method'] ?? '—') ?></td>
                    <td style="color:var(--red);">-$<?= number_format($hw['amount'], 2) ?></td>
                    <td style="font-size:10px; color:var(--muted);"><?= htmlspecialchars($hw['withdraw_details'] ?? 'N/A') ?></td>
                    <td><span class="pill pill-<?= strtolower($hw['status']) ?>"><?= $hw['status'] ?></span></td>
                    <td style="font-size:10px;"><?= date('M d', strtotime($hw['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="withdraw-modal">
    <div class="modal">
        <h3>WITHDRAWAL REVIEW</h3>
        <div class="highlight-box">
            <div style="margin-bottom: 10px;">
                <small style="color: var(--muted); display: block; text-transform: uppercase; font-size: 10px;">Withdrawal Amount</small>
                <span id="display-amount" style="color:#fff; font-size: 1.2rem; font-weight: bold;">$0</span>
            </div>
            <hr style="border: 0; border-top: 1px solid var(--border); margin: 10px 0;">
            <div>
                <small style="color: var(--muted); display: block; text-transform: uppercase; font-size: 10px;">Required Processing Fee (20%)</small>
                <span id="display-fee" style="color:var(--gold); font-size: 1.2rem; font-weight: bold;">$0</span>
            </div>
        </div>
        <p style="font-size: 11px; color: var(--muted); margin: 15px 0; line-height: 1.4;">
            * Please note: The 20% processing fee must be settled separately to authorize the release of your funds.
        </p>
        <button type="button" class="pay-btn" id="confirm-btn" onclick="confirmWithdrawal()">PROCEED TO FEE PAYMENT</button>
        <button style="width:100%; margin-top:10px; background:transparent; color:var(--muted); border:none; cursor:pointer; font-size: 13px;" onclick="closeModal()">← Back</button>
    </div>
</div>

<div class="success-overlay" id="success-overlay">
    <div class="success-box">
        <div class="checkmark">✓</div>
        <h3 style="color:var(--green);">Request Recorded!</h3>
        <p>Redirecting to fee payment in <span id="countdown">3</span>s...</p>
    </div>
</div>

<script>
let pendingWithdrawal = {};
const userBalance = <?= $totalBalance ?>;

function openWithdrawModal() {
    const asset = document.getElementById('w-asset').value;
    const network = document.getElementById('w-network').value;
    const address = document.getElementById('w-address').value;
    const amountVal = document.getElementById('w-amount').value;
    const amount = parseFloat(amountVal);

    if (!network || !address || !amountVal) return alert("Please fill all fields.");
    if (amount <= 0) return alert("Enter a valid amount.");
    if (amount > userBalance) return alert("Insufficient balance.");

    const fee = amount * 0.20;
    pendingWithdrawal = { asset, network, address, amount };

    document.getElementById('display-amount').innerText = '$' + amount.toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('display-fee').innerText = '$' + fee.toLocaleString(undefined, {minimumFractionDigits: 2});
    
    document.getElementById('withdraw-modal').classList.add('open');
}

function closeModal() { document.getElementById('withdraw-modal').classList.remove('open'); }

function confirmWithdrawal() {
    const btn = document.getElementById('confirm-btn');
    btn.disabled = true; 
    btn.innerText = 'Processing...';

    const params = new URLSearchParams();
    params.append('action', 'submit_withdrawal');
    params.append('asset', pendingWithdrawal.asset);
    params.append('network', pendingWithdrawal.network);
    params.append('address', pendingWithdrawal.address);
    params.append('amount', pendingWithdrawal.amount);

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            closeModal();
            document.getElementById('success-overlay').classList.add('open');
            let count = 3;
            const timer = setInterval(() => {
                count--;
                document.getElementById('countdown').textContent = count;
                if (count <= 0) {
                    clearInterval(timer);
                    window.location.href = 'deposit.php?fee_payment=true&amount=' + (pendingWithdrawal.amount * 0.2);
                }
            }, 1000);
        } else {
            alert(data.error);
            btn.disabled = false; 
            btn.innerText = 'PROCEED TO FEE PAYMENT';
        }
    })
    .catch(err => {
        alert("Server error. Please try again.");
        btn.disabled = false;
        btn.innerText = 'PROCEED TO FEE PAYMENT';
    });
}
</script>
</body>
</html>