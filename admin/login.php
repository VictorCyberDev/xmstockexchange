<?php
session_start();
include __DIR__ . '/../db.php'; // adjust path if needed

// Redirect if already logged in
if (isset($_SESSION['admin_id'])) {
    header("Location: admin_dashboard.php");
    exit();
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Email and password are required.";
    } else {
        // Fetch admin by email
        $stmt = $conn->prepare(
            "SELECT id, name, email, password FROM admin_users WHERE email = ? LIMIT 1"
        );

        if (!$stmt) {
            die("Database error: " . $conn->error);
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 1) {
            $admin = $res->fetch_assoc();

            if (password_verify($password, $admin['password'])) {
                // Login success
                $_SESSION['admin_id']   = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];

                header("Location: admin_dashboard.php");
                exit();
            } else {
                $error = "Incorrect password.";
            }
        } else {
            $error = "Admin account not found.";
        }

        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Login - Dominion Funding</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body {
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, #0b2b4a, #1a4a78);
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    margin: 0;
}
.login-container {
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(15px);
    padding: 50px 40px;
    border-radius: 16px;
    width: 100%;
    max-width: 400px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    text-align: center;
    color: #fff;
    transition: all 0.3s ease;
}
.login-container:hover {
    transform: translateY(-5px);
}
.login-container h2 {
    margin-bottom: 25px;
    font-size: 28px;
}
input[type="email"],
input[type="password"] {
    width: 100%;
    padding: 14px;
    margin: 10px 0;
    border-radius: 8px;
    border: none;
    outline: none;
    font-size: 16px;
    background: rgba(255,255,255,0.1);
    color: #fff;
    transition: background 0.3s ease, transform 0.2s ease;
}
input:focus {
    background: rgba(255,255,255,0.2);
    transform: scale(1.02);
}
input::placeholder {
    color: #ddd;
}
button {
    width: 100%;
    padding: 14px;
    margin-top: 15px;
    background: #ff9900;
    border: none;
    border-radius: 8px;
    font-size: 18px;
    font-weight: 700;
    cursor: pointer;
    color: #0b2b4a;
    transition: background 0.3s ease, transform 0.2s ease;
}
button:hover {
    background: #e68a00;
    transform: scale(1.02);
}
.error {
    background: #ff4d4d;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 15px;
}
</style>
</head>
<body>
<div class="login-container">
    <h2>Admin Login</h2>

    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Login</button>
    </form>
</div>
</body>
</html>
