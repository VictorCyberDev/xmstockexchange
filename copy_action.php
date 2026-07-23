<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();
include __DIR__ . '/db.php';

$response = ['success' => false, 'message' => 'Unknown error'];

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('You must be logged in.');
    }

    $user_id = (int)$_SESSION['user_id'];

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) throw new Exception('Invalid input format.');

    $leader_id = isset($data['leader_id']) ? (int)$data['leader_id'] : 0;
    $amount = isset($data['amount']) ? (float)$data['amount'] : 0;

    if ($leader_id <= 0 || $amount <= 0) {
        throw new Exception('Invalid leader or amount.');
    }

    // Validate trader
    $stmt = $conn->prepare("SELECT id, status FROM copy_traders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $leader_id);
    $stmt->execute();
    $leader = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$leader || $leader['status'] !== 'active') {
        throw new Exception('Trader not available or inactive.');
    }

    // Get user balance
    $stmt = $conn->prepare("SELECT balance FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) throw new Exception('User not found.');

    $balance = (float)$user['balance'];
    if ($balance < $amount) throw new Exception('Insufficient balance.');

    // Check if already copying
    $stmt = $conn->prepare("SELECT id FROM copy_trades WHERE leader_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param("ii", $leader_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        throw new Exception('You are already copying this trader.');
    }
    $stmt->close();

    // Start transaction
    $conn->begin_transaction();

    // Insert copy trade
    $stmt = $conn->prepare("
        INSERT INTO copy_trades (leader_id, user_id, invested_amount, status, created_at)
        VALUES (?, ?, ?, 'active', NOW())
    ");
    $stmt->bind_param("iid", $leader_id, $user_id, $amount);
    if (!$stmt->execute()) throw new Exception('Failed to insert trade.');
    $stmt->close();

    // Deduct balance
    $newBalance = $balance - $amount;
    $stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
    $stmt->bind_param("di", $newBalance, $user_id);
    if (!$stmt->execute()) throw new Exception('Failed to update balance.');
    $stmt->close();

    // Update followers (optional)
    $stmt = $conn->prepare("SELECT id FROM copy_follows WHERE leader_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $leader_id, $user_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if (!$exists) {
        $stmt = $conn->prepare("INSERT INTO copy_follows (leader_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $leader_id, $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE copy_traders SET followers = followers + 1 WHERE id = ?");
        $stmt->bind_param("i", $leader_id);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();

    $response = [
        'success' => true,
        'message' => '✅ Copy trade started successfully!',
        'new_balance' => number_format($newBalance, 2)
    ];

} catch (Exception $e) {
    if ($conn->errno) $conn->rollback();
    error_log("Copy Action Error: " . $e->getMessage());
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
