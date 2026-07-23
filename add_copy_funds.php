<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
 
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/db.php';
 
header('Content-Type: application/json');
 
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
 
$user_id = (int)$_SESSION['user_id'];
$input   = json_decode(file_get_contents('php://input'), true);
 
$trade_id = intval($input['trade_id'] ?? 0);
$amount   = floatval($input['amount']   ?? 0);
 
if (!$trade_id || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input.']);
    exit;
}
 
// Verify the trade belongs to this user and is active
$stmt = $conn->prepare("SELECT id, invested_amount FROM copy_trades WHERE id = ? AND user_id = ? AND status = 'active'");
$stmt->bind_param("ii", $trade_id, $user_id);
$stmt->execute();
$trade = $stmt->get_result()->fetch_assoc();
 
if (!$trade) {
    echo json_encode(['success' => false, 'message' => 'Trade not found or not active.']);
    exit;
}
 
// Check user wallet balance
$wq   = $conn->prepare("SELECT balance FROM users WHERE id = ?");
$wq->bind_param("i", $user_id);
$wq->execute();
$wrow = $wq->get_result()->fetch_assoc();
$balance = (float)($wrow['balance'] ?? 0);
 
if ($amount > $balance) {
    echo json_encode(['success' => false, 'message' => "Insufficient balance. Your wallet has $$balance but you need $$amount."]);
    exit;
}
 
// Deduct from wallet
$newBalance = $balance - $amount;
$upd = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
$upd->bind_param("di", $newBalance, $user_id);
$upd->execute();
 
// Add to invested_amount
$newInvested = (float)$trade['invested_amount'] + $amount;
$upd2 = $conn->prepare("UPDATE copy_trades SET invested_amount = ? WHERE id = ?");
$upd2->bind_param("di", $newInvested, $trade_id);
$upd2->execute();
 
echo json_encode([
    'success' => true,
    'message' => '$' . number_format($amount, 2) . ' added. New invested amount: $' . number_format($newInvested, 2) . '.'
]);