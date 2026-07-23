<?php
session_start();
include 'db.php';

$user_id = $_SESSION['user_id'];
$plan_id = $_POST['plan_id'];
$amount = floatval($_POST['amount']);

// 1. Check User Balance
$user_query = $conn->prepare("SELECT balance FROM users WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_balance = $user_query->get_result()->fetch_assoc()['balance'];

if ($amount > $user_balance) {
    echo json_encode(['status' => 'error', 'message' => 'Insufficient balance in your wallet.']);
    exit();
}

// 2. Fetch Plan Details (to get ROI and Duration)
$plan_query = $conn->prepare("SELECT roi, duration FROM investment_plans WHERE id = ?");
$plan_query->bind_param("i", $plan_id);
$plan_query->execute();
$plan = $plan_query->get_result()->fetch_assoc();

// 3. Deduct Balance & Create Investment
$new_balance = $user_balance - $amount;
$conn->query("UPDATE users SET balance = $new_balance WHERE id = $user_id");

$stmt = $conn->prepare("INSERT INTO user_investments (user_id, plan_id, amount, status, start_date) VALUES (?, ?, ?, 'active', NOW())");
$stmt->bind_param("iid", $user_id, $plan_id, $amount);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}
?>