<?php
/**
 * Xmstockexchange - Contact Form Processor
 * Securely handles support tickets and sends them to the administrator.
 */

// 1. SET YOUR EMAIL HERE
$admin_email = "support@xmstockexchange.com"; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 2. CLEAN THE DATA (Security)
    $name    = filter_var(strip_tags(trim($_POST["name"])), FILTER_SANITIZE_STRING);
    $email   = filter_var(trim($_POST["email"]), FILTER_SANITIZE_EMAIL);
    $subject = filter_var(strip_tags(trim($_POST["subject"])), FILTER_SANITIZE_STRING);
    $message = filter_var(strip_tags(trim($_POST["message"])), FILTER_SANITIZE_STRING);

    // 3. VALIDATION
    if (empty($name) || empty($message) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Redirect back with error if fields are empty
        header("Location: support.html?status=error");
        exit;
    }

    // 4. CONSTRUCT EMAIL CONTENT
    $email_subject = "New Support Ticket: $subject";
    $email_body = "You have received a new message from your website contact form.\n\n".
                  "--- Contact Details ---\n".
                  "Name: $name\n".
                  "Email: $email\n\n".
                  "--- Message ---\n".
                  "$message\n\n".
                  "-----------------------\n".
                  "User IP: " . $_SERVER['REMOTE_ADDR'];

    // 5. EMAIL HEADERS
    $headers = "From: $name <$email>\r\n";
    $headers .= "Reply-To: $email\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    // 6. SEND EMAIL
    if (mail($admin_email, $email_subject, $email_body, $headers)) {
        $success = true;
    } else {
        $success = false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=1400">
    <title>Message Sent - Xmstockexchange</title>
    <link rel="stylesheet" href="./bit_files/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/ionicons/2.0.0/css/ionicons.min.css">
    <style>
        body { background: #f8f9ff; font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .confirmation-card { background: white; padding: 50px; border-radius: 20px; box-shadow: 0 15px 40px rgba(32,18,111,0.1); text-align: center; max-width: 500px; }
        .icon-circle { width: 80px; height: 80px; background: #22c55e; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; margin: 0 auto 20px; }
        .error-circle { background: #ef4444; }
        h2 { color: #20126f; font-weight: 700; }
        p { color: #666; margin-bottom: 30px; }
        .btn-back { background: #20126f; color: white; padding: 12px 30px; border-radius: 30px; text-decoration: none; font-weight: 600; transition: 0.3s; }
        .btn-back:hover { background: #2563eb; color: white; }
    </style>
</head>
<body>

    <div class="confirmation-card">
        <?php if($success): ?>
            <div class="icon-circle"><i class="ion-checkmark"></i></div>
            <h2>Message Sent!</h2>
            <p>Thank you, <strong><?php echo $name; ?></strong>. Your support ticket has been received. Our team will contact you at <strong><?php echo $email; ?></strong> shortly.</p>
        <?php else: ?>
            <div class="icon-circle error-circle"><i class="ion-close"></i></div>
            <h2>Oops!</h2>
            <p>Something went wrong while sending your message. Please try again or use live chat.</p>
        <?php endif; ?>
        
        <a href="support.html" class="btn-back">Return to Support</a>
    </div>

</body>
</html>