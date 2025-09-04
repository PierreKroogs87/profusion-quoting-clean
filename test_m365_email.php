<?php
require 'vendor/PHPMailer/PHPMailer.php';
require 'vendor/PHPMailer/SMTP.php';
require 'vendor/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
try {
    // Server settings
    $mail->isSMTP();
    $mail->Host = 'smtp.office365.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'newbusiness@profusionum.com';
    $mail->Password = 'Pr0fu$10n0902087!!!'; // Replace with password or App Password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // Recipients
    $mail->setFrom('newbusiness@profusionum.com', 'Profusion Insurance');
    $mail->addAddress('newbusiness@profusionum.com');

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Test Microsoft 365 Email';
    $mail->Body = 'This is a test email sent from the quoting subdomain using Microsoft 365 SMTP.';
    $mail->AltBody = 'This is a test email sent from the quoting subdomain using Microsoft 365 SMTP.';

    $mail->send();
    echo 'Test email sent successfully!';
} catch (Exception $e) {
    echo "Test email failed: {$mail->ErrorInfo}";
}
?>