<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* MARK AS READ */
if (isset($_GET['read'])) {
    $id = (int)$_GET['read'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
}

/* FETCH NOTIFICATIONS */
$stmt = $conn->prepare("
    SELECT * FROM notifications
    WHERE user_id=?
    ORDER BY is_read ASC, created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Notifications</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

<style>
body{
    margin:0;
    font-family:'Inter',sans-serif;
    background:linear-gradient(135deg,#020617,#0f172a);
    color:#e5e7eb;
}
.container{
    max-width:900px;
    margin:40px auto;
    padding:20px;
}
.card{
    background:#020617;
    border-radius:18px;
    padding:30px;
    box-shadow:0 20px 50px rgba(0,0,0,.6);
}
h1{margin-top:0}

.notification{
    display:flex;
    gap:15px;
    padding:18px;
    border-radius:14px;
    margin-bottom:15px;
    background:#020617;
    border-left:5px solid #334155;
    transition:.3s;
}
.notification.unread{
    background:#020617;
    border-left-color:#3b82f6;
}
.notification:hover{
    transform:translateY(-2px);
}

.icon{
    font-size:22px;
    margin-top:4px;
}

.system{color:#38bdf8}
.admin{color:#f97316}
.transaction{color:#22c55e}
.kyc{color:#eab308}
.investment{color:#a78bfa}

.content h3{
    margin:0;
    font-size:16px;
}
.content p{
    margin:6px 0 0;
    color:#cbd5f5;
    font-size:14px;
}
.time{
    font-size:12px;
    color:#94a3b8;
    margin-top:6px;
}

.read-btn{
    margin-left:auto;
    align-self:center;
    text-decoration:none;
    font-size:13px;
    color:#60a5fa;
}
.empty{
    text-align:center;
    color:#94a3b8;
    padding:40px;
}

@media(max-width:600px){
    .notification{flex-direction:column}
    .read-btn{margin-left:0}
}
</style>
</head>

<body>
<div class="container">
<div class="card">

<h1>Notifications</h1>

<?php if ($notifications->num_rows === 0): ?>
    <div class="empty">No notifications yet.</div>
<?php endif; ?>

<?php while ($n = $notifications->fetch_assoc()): ?>
<div class="notification <?= $n['is_read'] ? '' : 'unread' ?>">
    <div class="icon <?= $n['type'] ?>">●</div>

    <div class="content">
        <h3><?= htmlspecialchars($n['title']) ?></h3>
        <p><?= htmlspecialchars($n['message']) ?></p>
        <div class="time"><?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></div>
    </div>

    <?php if (!$n['is_read']): ?>
        <a class="read-btn" href="?read=<?= $n['id'] ?>">Mark as read</a>
    <?php endif; ?>
</div>
<?php endwhile; ?>

</div>
</div>
</body>
</html>
