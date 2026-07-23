<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

function setupMailer() {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'support@xmstockexchange.com'; // CHANGE
    $mail->Password   = '@Xmstockexchange01';                // CHANGE (gmail app password)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('support@xmstockexchange.com', 'XM Stock Exchange');
    $mail->isHTML(true);

    return $mail;
}