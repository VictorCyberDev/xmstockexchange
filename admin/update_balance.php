<?php
session_start();
include __DIR__ . '/../db.php';
if(!isset($_SESSION['admin_id'])) exit('Unauthorized');

if($_SERVER['REQUEST_METHOD']=='POST'){
    $user_id = (int)$_POST['user_id'];
    $amount = (float)$_POST['amount'];

    $stmt = $conn->prepare("UPDATE users SET balance=? WHERE id=?");
    $stmt->bind_param("di", $amount, $user_id);
    if($stmt->execute()){
        echo "Balance updated successfully.";
    } else {
        echo "Failed to update balance.";
    }
    $stmt->close();
}
?>
