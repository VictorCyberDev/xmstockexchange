<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
include('db.php');
$userId = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Transactions - Dominion Funding</title>
<link rel="stylesheet" href="css/bootstrap.min.css">
<style>
body { background:#0b0b0b; color:#fff; font-family:'Poppins',sans-serif; }
.container { padding:40px; }
</style>
</head>
<body>
<?php include('header.php'); ?>
<div class="container">
  <h2 class="text-warning mb-4 text-center">Transaction History</h2>
  <table class="table table-dark table-striped">
    <thead>
      <tr><th>Date</th><th>Type</th><th>Amount</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php if ($transactions): foreach ($transactions as $tr): ?>
      <tr>
        <td><?= $tr['created_at'] ?></td>
        <td><?= $tr['type'] ?></td>
        <td>$<?= number_format($tr['amount'], 2) ?></td>
        <td><?= $tr['status'] ?></td>
      </tr>
      <?php endforeach; else: ?>
      <tr><td colspan="4" class="text-center text-muted">No transactions found</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>
