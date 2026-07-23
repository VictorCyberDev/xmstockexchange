<?php
session_start();
// Database credentials
$host = "localhost";
$db_user = "u239040674_xmstockexchang";
$db_pass = "Xmstockexchange01";
$db_name = "u239040674_xmstockexchang";

$conn = new mysqli($host, $db_user, $db_pass, $db_name);
$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_input = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];

    // Check if user exists
    $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $user_input, $user_input);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password'])) {
            // Success: Create session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            
            // Redirect to Dashboard
            header("Location: dashboard.php");
            exit();
        } else {
            $message = "Invalid password.";
        }
    } else {
        $message = "No account found with that username.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | XM Stock Exchange</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #00d2ff; --secondary: #3a7bd5; --glass: rgba(255, 255, 255, 0.05); }
        body { margin: 0; font-family: 'Inter', sans-serif; background: radial-gradient(circle at top right, #1e293b, #0f172a); color: white; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-card { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.1); padding: 40px; border-radius: 24px; width: 100%; max-width: 400px; text-align: center; }
        .logo { width: 60px; margin-bottom: 20px; }
        input { width: 100%; padding: 14px; background: rgba(0, 0, 0, 0.2); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; margin-bottom: 20px; box-sizing: border-box; }
        .btn-login { width: 100%; padding: 14px; background: linear-gradient(135deg, var(--primary), var(--secondary)); border: none; border-radius: 12px; color: white; font-weight: 600; cursor: pointer; }
        .error { color: #ff4b2b; font-size: 13px; margin-bottom: 15px; }
        a { color: var(--primary); text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
    <div class="login-card">
        <img src="https://bitwinexchange.com/bit_files/add-user.png" alt="Logo" class="logo">
        <h2>Welcome Back</h2>
        <?php if($message) echo "<p class='error'>$message</p>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Username or Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" class="btn-login">Login to Exchange</button>
        </form>
        <p style="margin-top:20px;">New here? <a href="signup.php">Create Account</a></p>
    </div>
</body>
</html>