<?php

declare(strict_types=1);

namespace MoodSwings\Mail;

use MoodSwings\Config;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    /**
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public function sendVerificationEmail(string $toEmail, string $toName, string $verificationUrl): void
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = Config::get('SMTP_HOST', '');
        $mail->Port = (int) Config::get('SMTP_PORT', '587');
        $mail->SMTPAuth = true;
        $mail->Username = Config::get('SMTP_USERNAME', '');
        $mail->Password = Config::get('SMTP_PASSWORD', '');
        $mail->SMTPSecure = Config::get('SMTP_ENCRYPTION', PHPMailer::ENCRYPTION_STARTTLS);
        // Encryption is explicit via SMTP_ENCRYPTION above; disable opportunistic
        // auto-STARTTLS so behavior doesn't depend on what the server advertises.
        $mail->SMTPAutoTLS = false;

        $mail->setFrom(Config::get('SMTP_FROM_ADDRESS', ''), Config::get('SMTP_FROM_NAME', 'MoodSwings'));
        $mail->addAddress($toEmail, $toName);

        $mail->Subject = 'Verify your MoodSwings account';
        $mail->Body = "Hi {$toName},\n\n"
            . "Please verify your email address by visiting the link below:\n\n"
            . "{$verificationUrl}\n\n"
            . "If you didn't create this account, you can ignore this email.";

        $mail->send();
    }
}
