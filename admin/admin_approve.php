<?php
// Example of the logic used in the Admin Backend
if(isset($_GET['approve_id'])) {
    $deposit_id = $_GET['approve_id'];
    
    // Update the deposit to Approved
    $stmt = $conn->prepare("UPDATE deposits SET status = 'Approved' WHERE id = ?");
    $stmt->bind_param("i", $deposit_id);
    
    if($stmt->execute()) {
        echo "Deposit approved. The user will now see this in their balance.";
    }
}
?>