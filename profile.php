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
$success = $error = "";

/* FETCH USER */
$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

/* UPDATE PROFILE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone'] ?? '');
    $country = trim($_POST['country'] ?? '');

    $stmt = $conn->prepare("
        UPDATE users 
        SET name=?, phone=?, country=?
        WHERE id=?
    ");
    $stmt->bind_param("sssi", $name, $phone, $country, $user_id);
    $stmt->execute();

    $success = "Profile updated successfully.";
}

/* CHANGE PASSWORD */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    if (!password_verify($current, $user['password'])) {
        $error = "Current password is incorrect.";
    } elseif ($new !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($new) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param("si", $hashed, $user_id);
        $stmt->execute();
        $success = "Password updated successfully.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Profile</title>
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
    max-width:1100px;
    margin:40px auto;
    padding:20px;
}
.grid{
    display:grid;
    grid-template-columns:1fr 1.3fr;
    gap:25px;
}
.card{
    background:#020617;
    border-radius:20px;
    padding:30px;
    box-shadow:0 25px 60px rgba(0,0,0,.7);
    animation:fadeUp .6s ease;
}
@keyframes fadeUp{
    from{opacity:0;transform:translateY(20px)}
    to{opacity:1;transform:translateY(0)}
}
h2{margin-top:0}
label{
    display:block;
    margin:14px 0 6px;
    font-size:14px;
    color:#94a3b8;
}
input, select{
    width:100%;
    padding:13px;
    border-radius:12px;
    border:1px solid #1e293b;
    background:#020617;
    color:#fff;
}
button{
    margin-top:18px;
    padding:14px;
    width:100%;
    border:none;
    border-radius:14px;
    background:linear-gradient(135deg,#3b82f6,#2563eb);
    color:#fff;
    font-weight:600;
    cursor:pointer;
}
button:hover{opacity:.9}

.status{
    display:inline-block;
    padding:6px 14px;
    border-radius:20px;
    font-size:13px;
}
.active{background:#22c55e;color:#022c22}
.pending{background:#eab308;color:#3b2f00}
.rejected{background:#ef4444;color:#3b0000}

.alert{
    padding:14px;
    border-radius:12px;
    margin-bottom:15px;
}
.success{background:#022c22;color:#22c55e}
.error{background:#3b0000;color:#f87171}

.avatar{
    width:90px;
    height:90px;
    border-radius:50%;
    background:linear-gradient(135deg,#3b82f6,#22c55e);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:32px;
    font-weight:700;
    margin-bottom:15px;
}

@media(max-width:900px){
    .grid{grid-template-columns:1fr}
}
</style>
</head>

<body>
<div class="container">

<?php if($success): ?><div class="alert success"><?= $success ?></div><?php endif; ?>
<?php if($error): ?><div class="alert error"><?= $error ?></div><?php endif; ?>

<div class="grid">

<!-- PROFILE SUMMARY -->
<div class="card">
    <div class="avatar"><?= strtoupper(substr($user['name'],0,1)) ?></div>
    <h2><?= htmlspecialchars($user['name']) ?></h2>
    <p><?= htmlspecialchars($user['email']) ?></p>

    <p>
        Account Status:
        <span class="status active"><?= ucfirst($user['status'] ?? 'active') ?></span>
    </p>

    <p>
        KYC Status:
        <span class="status <?= $user['kyc_status'] ?? 'pending' ?>">
            <?= ucfirst($user['kyc_status'] ?? 'Pending') ?>
        </span>
    </p>

    <p style="color:#94a3b8;font-size:14px">
        Joined <?= date('d M Y', strtotime($user['created_at'])) ?>
    </p>
</div>

<!-- EDIT PROFILE -->
<div class="card">
<h2>Edit Profile</h2>
<form method="post">
    <label>Full Name</label>
    <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>

    <label>Phone</label>
    <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">

    <label>Country</label>
    <input type="text" name="country" value="<?= htmlspecialchars($user['country'] ?? '') ?>">

    <button name="update_profile">Update Profile</button>
</form>

<hr style="border-color:#1e293b;margin:30px 0">

<h2>Change Password</h2>
<form method="post">
    <label>Current Password</label>
    <input type="password" name="current_password" required>

    <label>New Password</label>
    <input type="password" name="new_password" required>

    <label>Confirm New Password</label>
    <input type="password" name="confirm_password" required>

    <button name="change_password">Change Password</button>
</form>
</div>

</div>
</div>
</body>
</html>
