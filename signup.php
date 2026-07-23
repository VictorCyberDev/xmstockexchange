<?php


// Establish Connection
try {
    $conn = new mysqli($host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        throw new Exception("Connection failed");
    }
} catch (Exception $e) {
    die("Database connection error. Please try again later.");
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = mysqli_real_escape_string($conn, $_POST['username']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Check if user already exists
    $check = $conn->query("SELECT id FROM users WHERE email='$email' OR username='$user'");
    if ($check->num_rows > 0) {
        $message = "Username or Email already registered!";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $user, $email, $pass);
        
        if ($stmt->execute()) {
            // Redirect to login page on success
            header("Location: login.php?signup=success");
            exit();
        } else {
            $message = "Error: Could not complete registration.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | XM Stock Exchange</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #00d2ff;
            --secondary: #3a7bd5;
            --dark: #0f172a;
            --glass: rgba(255, 255, 255, 0.05);
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: radial-gradient(circle at top right, #1e293b, #0f172a);
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .bg-decoration {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background-image: url('https://bitwinexchange.com/bit_files/how_it_work_bg.png');
            background-size: cover;
            opacity: 0.1;
            z-index: -1;
        }

        .signup-card {
            background: var(--glass);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 40px;
            border-radius: 24px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            text-align: center;
        }

        .logo { width: 60px; margin-bottom: 20px; }
        h2 { margin: 0 0 10px 0; font-weight: 600; }
        .subtitle { color: #94a3b8; font-size: 14px; margin-bottom: 30px; }

        .input-group { margin-bottom: 20px; text-align: left; }
        label { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 5px; text-transform: uppercase; }

        input {
            width: 100%; padding: 14px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            box-sizing: border-box;
        }

        input:focus { outline: none; border-color: var(--primary); }

        .btn-signup {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none; border-radius: 12px;
            color: white; font-weight: 600; cursor: pointer;
            transition: 0.3s ease;
        }

        .btn-signup:hover { transform: translateY(-2px); opacity: 0.9; }
        .error { color: #ff4b2b; font-size: 13px; margin-bottom: 15px; }
        .footer-text { margin-top: 25px; font-size: 14px; color: #94a3b8; }
        .footer-text a { color: var(--primary); text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>

<div class="bg-decoration"></div>

<div class="signup-card">
    <img src="https://bitwinexchange.com/bit_files/add-user.png" alt="Logo" class="logo">
    <h2>XM Stock Exchange</h2>
    <p class="subtitle">Join the future of stock trading.</p>

    <?php if($message): ?>
        <div class="error"><?php echo $message; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="input-group">
            <label>Username</label>
            <input type="text" name="username" placeholder="Username" required>
        </div>
        <div class="input-group">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="email@example.com" required>
        </div>
        <div class="input-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn-signup">Create Account</button>
    </form>

    <div class="footer-text">
        Already registered? <a href="login.php">Log In</a>
    </div>
</div>

</body>
</html>
