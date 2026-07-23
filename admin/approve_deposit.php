<?php
require_once '../db.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    exit('Unauthorized');
}

$deposit_id = (int) $_POST['deposit_id'];

$conn->begin_transaction();

try {

    // Lock deposit row
    $deposit = $conn->query("
        SELECT * FROM deposits 
        WHERE id = $deposit_id AND status = 'pending'
        FOR UPDATE
    ")->fetch_assoc();

    if (!$deposit) {
        throw new Exception("Already processed or invalid deposit");
    }

    $user_id = $deposit['user_id'];
    $amount  = $deposit['amount'];

    // Approve deposit
    $conn->query("
        UPDATE deposits 
        SET status='approved', approved_at=NOW()
        WHERE id=$deposit_id
    ");

    // Recalculate wallet balance (NO ADDING!)
    $conn->query("
        UPDATE users u
        SET u.balance = (
            SELECT COALESCE(SUM(amount),0)
            FROM deposits 
            WHERE user_id = u.id AND status='approved'
        )
        WHERE u.id = $user_id
    ");

    // Ledger record
    $stmt = $conn->prepare("
        INSERT INTO transactions (user_id, type, amount, reference)
        VALUES (?, 'deposit', ?, ?)
    ");
    $stmt->bind_param("ids", $user_id, $amount, $deposit['tx_ref']);
    $stmt->execute();

    $conn->commit();
    echo "Deposit approved successfully";

} catch (Exception $e) {
    $conn->rollback();
    echo "Error: " . $e->getMessage();
}
