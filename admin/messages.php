<?php
// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
session_start();
require_once "../db.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin-login.php");
    exit();
}

/* SEND NOTIFICATION */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = intval($_POST['user_id']);
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);
    $type = $_POST['type'];

    if ($user_id && $title && $message) {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $user_id, $title, $message, $type);
        $stmt->execute();
        $stmt->close();
    }
}

$users = $conn->query("SELECT id, name, email FROM users ORDER BY name ASC");
?>
<!DOCTYPE html>
<html>
<head>
<title>Send User Notification</title>
<style>
body { font-family: Arial; background:#f4f6f9; }
.box { width:500px; margin:50px auto; background:#fff; padding:25px; border-radius:10px; }
input, textarea, select, button {
    width:100%; padding:10px; margin-top:10px;
}
button {
    background:#2563eb; color:#fff; border:none; cursor:pointer;
}
</style>
</head>
<body>

<div class="box">
<h2>Send Notification</h2>

<form method="post">
    <select name="user_id" required>
        <option value="">Select User</option>
        <?php while($u = $users->fetch_assoc()): ?>
            <option value="<?= $u['id'] ?>">
                <?= htmlspecialchars($u['name']) ?> (<?= $u['email'] ?>)
            </option>
        <?php endwhile; ?>
    </select>

    <input type="text" name="title" placeholder="Notification title" required>

    <textarea name="message" rows="5" placeholder="Message..." required></textarea>

    <select name="type">
        <option value="info">Info</option>
        <option value="success">Success</option>
        <option value="warning">Warning</option>
        <option value="danger">Alert</option>
    </select>

    <button type="submit">Send Notification</button>
</form>
</div>

</body>
</html>

