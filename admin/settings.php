<?php
session_start();
require_once "../db.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin-login.php");
    exit();
}

/* ===== HANDLE FORM SUBMISSION ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST as $key => $value) {
        $stmt = $conn->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
        $stmt->close();
    }

    $message = "Settings updated successfully.";
}

/* ===== FETCH SETTINGS ===== */
$settings = [];
$result = $conn->query("SELECT * FROM settings");
while ($row = $result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Settings</title>
<style>
body { font-family: Arial, sans-serif; background:#f4f6f9; margin:0; }
.container { max-width:700px; margin:50px auto; background:#fff; padding:30px; border-radius:10px; }
h2 { margin-bottom:20px; }
input, select, textarea, button { width:100%; padding:10px; margin-top:10px; }
button { background: #2563eb; color:#fff; border:none; cursor:pointer; border-radius:6px; }
.success { background:#16a34a; color:#fff; padding:10px; border-radius:6px; margin-bottom:15px; }
</style>
</head>
<body>
<div class="container">
<h2>Platform Settings</h2>

<?php if(isset($message)) echo "<div class='success'>$message</div>"; ?>

<form method="post" enctype="multipart/form-data">

    <label>Site Name</label>
    <input type="text" name="site_name" value="<?= htmlspecialchars($settings['site_name'] ?? '') ?>" required>

    <label>Admin Email</label>
    <input type="email" name="admin_email" value="<?= htmlspecialchars($settings['admin_email'] ?? '') ?>" required>

    <label>Site Logo (Filename)</label>
    <input type="text" name="site_logo" value="<?= htmlspecialchars($settings['site_logo'] ?? '') ?>">

    <label>Theme Color</label>
    <input type="color" name="theme_color" value="<?= htmlspecialchars($settings['theme_color'] ?? '#2563eb') ?>">

    <label>Default Currency</label>
    <select name="currency">
        <?php 
        $currencies = ['USD','EUR','GBP','NGN','CAD'];
        foreach($currencies as $c): ?>
            <option value="<?= $c ?>" <?= ($settings['currency']??'')==$c?'selected':'' ?>><?= $c ?></option>
        <?php endforeach; ?>
    </select>

    <button type="submit">Save Settings</button>
</form>
</div>
</body>
</html>
