<?php
include __DIR__ . '/db.php';
session_start();

$data = json_decode(file_get_contents("php://input"), true);

$ref = $data['ref'] ?? '';
$otp = $data['otp'] ?? '';

if (!$ref || !$otp) {
    echo json_encode(['success'=>false,'message'=>'Invalid input']); exit;
}

$stmt = $conn->prepare("
    SELECT * FROM withdrawals 
    WHERE txn_id=? AND otp_code=? AND is_verified=0
");
$stmt->bind_param("ss", $ref, $otp);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if (!$res) {
    echo json_encode(['success'=>false,'message'=>'Invalid or used code']); exit;
}

if (strtotime($res['otp_expires']) < time()) {
    echo json_encode(['success'=>false,'message'=>'OTP expired']); exit;
}

// ✅ Mark verified
$update = $conn->prepare("UPDATE withdrawals SET is_verified=1 WHERE txn_id=?");
$update->bind_param("s", $ref);
$update->execute();

// Fetch user email
$user = $conn->query("SELECT email,name FROM users WHERE id=".$res['user_id'])->fetch_assoc();

$user_email = $user['email'];
$user_name  = $user['name'];
$amount     = $res['amount'];

$fmt_amount = "$" . number_format($amount,2);


// 📧 RECEIPT EMAIL
$subject = "Withdrawal Confirmed — {$ref}";

$email_html = "
<!DOCTYPE html>
<html>
<body style='background:#0b1220;color:#fff;font-family:Arial;padding:20px'>
<div style='max-width:600px;margin:auto;background:#111c33;padding:30px;border-radius:12px'>

<h2 style='color:#00e676'>Withdrawal Confirmed ✅</h2>

<p>Hello <b>{$user_name}</b>,</p>

<p>Your withdrawal has been successfully authorized and is now being processed.</p>

<div style='background:#0a1525;padding:20px;border-radius:10px'>
<p><b>Reference:</b> {$ref}</p>
<p><b>Amount:</b> {$fmt_amount}</p>
<p><b>Status:</b> Processing</p>
</div>

<p style='margin-top:15px;color:#aaa'>
Processing time: 24–48 hours
</p>

<hr style='border:1px solid #1a2e48'>

<p style='font-size:12px;color:#777'>
XM Stock Exchange — Secure Transaction System
</p>

</div>
</body>
</html>
";

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: XM Stock Exchange <noreply@xmstockexchange.com>\r\n";

@mail($user_email, $subject, $email_html, $headers);

echo json_encode([
    'success'=>true,
    'message'=>'Withdrawal confirmed successfully'
]);