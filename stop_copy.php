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
$trade_id = intval($input['id'] ?? 0);
 
if (!$trade_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid trade ID.']);
    exit;
}
 
// Verify ownership and active status
$stmt = $conn->prepare("SELECT id, invested_amount FROM copy_trades WHERE id = ? AND user_id = ? AND status = 'active'");
$stmt->bind_param("ii", $trade_id, $user_id);
$stmt->execute();
$trade = $stmt->get_result()->fetch_assoc();
 
if (!$trade) {
    echo json_encode(['success' => false, 'message' => 'Trade not found or already stopped.']);
    exit;
}
 
// Return invested amount to wallet
$invested = (float)$trade['invested_amount'];
if ($invested > 0) {
    $wq = $conn->prepare("SELECT balance FROM users WHERE id = ?");
    $wq->bind_param("i", $user_id);
    $wq->execute();
    $wrow = $wq->get_result()->fetch_assoc();
    $newBalance = (float)($wrow['balance'] ?? 0) + $invested;
 
    $upd = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
    $upd->bind_param("di", $newBalance, $user_id);
    $upd->execute();
}
 
// Mark trade as stopped
$stop = $conn->prepare("UPDATE copy_trades SET status = 'stopped', stopped_at = NOW() WHERE id = ?");
$stop->bind_param("i", $trade_id);
$stop->execute();
 
echo json_encode([
    'success' => true,
    'message' => 'Trade stopped. $' . number_format($invested, 2) . ' has been returned to your wallet.'
]);
 