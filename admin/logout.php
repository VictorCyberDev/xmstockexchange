<?php
session_start();

/* ===== DESTROY ALL SESSION DATA ===== */
$_SESSION = []; // Clear session array
session_unset(); // Free all session variables
session_destroy(); // Destroy session

/* ===== OPTIONAL: Clear session cookie ===== */
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

/* ===== REDIRECT TO LOGIN PAGE ===== */
header("Location: admin-login.php");
exit();
