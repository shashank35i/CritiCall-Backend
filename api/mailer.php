<?php
// api/mailer.php

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendMail($toEmail, $toName, $subject, $htmlBody, $textBody = ''): bool {
    $mail = new PHPMailer(true);

    $GLOBALS['MAILER_LAST_ERROR'] = null;

    try {
        //  hard time limit for PHP side too (prevents Apache closing early)
        @ini_set('default_socket_timeout', '60');
        @ini_set('max_execution_time', '60');
        @set_time_limit(60);

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;

        $mail->Username = 'sehatsethu@gmail.com';
        $mail->Password = 'hmzhjdfhynkjdpjv'; // app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        //  important: avoid long hangs
        $mail->Timeout = 60;
        $mail->SMTPKeepAlive = false;

        //  DEV only: helps on some Windows/XAMPP TLS issues
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ];

        // Optional debug to error_log (DEV only)
        // $mail->SMTPDebug = 2;
        // $mail->Debugoutput = function($str, $level) { error_log("SMTP[$level]: $str"); };

        $mail->setFrom('sehatsethu@gmail.com', 'SehatSethu');
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody ?: strip_tags($htmlBody);

        $ok = $mail->send();
        if (!$ok) {
            $GLOBALS['MAILER_LAST_ERROR'] = $mail->ErrorInfo ?: 'Unknown mailer error';
        }
        return $ok;

    } catch (Exception $e) {
        $GLOBALS['MAILER_LAST_ERROR'] = $mail->ErrorInfo ?: $e->getMessage();
        return false;
    }
}
