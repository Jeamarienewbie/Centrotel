<?php
// Email sender for authentication OTP codes.
//
// Two transports, in order:
//   1. Authenticated SMTP (Gmail etc.) when SMTP_USER/SMTP_PASS are set
//      in config/db.php — works on localhost AND on hosting.
//   2. PHP mail() fallback when no SMTP credentials are configured
//      (needs a working local MTA, which plain XAMPP does not have).
define('EMAIL_FROM', 'centroteleventcenter@gmail.com');
define('EMAIL_FROM_NAME', 'Centrotel & Event Center');

/**
 * Send an OTP code email. Returns true on accepted-for-delivery.
 */
function sendOtpEmail($to, $otp, $valid_minutes = 5) {
    $to = trim((string)$to);
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        error_log("Email OTP: invalid recipient $to");
        return false;
    }

    $subject = "Your Centrotel verification code is $otp";
    $safe_otp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $body = <<<HTML
<html><body style="font-family:Arial,sans-serif;color:#222;">
  <h2 style="color:#2e003e;">Centrotel &amp; Event Center</h2>
  <p>Your verification code is:</p>
  <p style="font-size:28px;font-weight:bold;letter-spacing:8px;">$safe_otp</p>
  <p>It is valid for $valid_minutes minutes. Never share it with anyone.</p>
  <p style="color:#888;font-size:12px;">If you did not request this, please ignore this message or call 09568820066.</p>
</body></html>
HTML;

    // Prefer authenticated SMTP when configured
    if (defined('SMTP_USER') && SMTP_USER !== '' && defined('SMTP_PASS') && SMTP_PASS !== '') {
        if (smtpSendEmail($to, $subject, $body)) {
            return true;
        }
        error_log("Email OTP: SMTP send failed for $to, trying mail() fallback");
    }

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . EMAIL_FROM_NAME . ' <' . EMAIL_FROM . '>';
    $headers[] = 'Reply-To: ' . EMAIL_FROM;

    $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
    if (!$sent) {
        error_log("Email OTP: mail() failed for $to (no local mail server?)");
    }
    return (bool)$sent;
}

/**
 * Minimal authenticated SMTP client (STARTTLS + AUTH LOGIN).
 * Works with Gmail (smtp.gmail.com:587) using an App Password.
 */
function smtpSendEmail($to, $subject, $html_body) {
    $host = defined('SMTP_HOST') && SMTP_HOST !== '' ? SMTP_HOST : 'smtp.gmail.com';
    $port = defined('SMTP_PORT') && SMTP_PORT ? (int)SMTP_PORT : 587;
    $user = SMTP_USER;
    $pass = SMTP_PASS;
    $from = (defined('SMTP_FROM') && SMTP_FROM !== '') ? SMTP_FROM : $user;

    $readResponse = function ($fp, &$last) {
        $last = '';
        $line = '';
        $start = time();
        do {
            $chunk = fgets($fp, 512);
            if ($chunk === false) {
                return false;
            }
            $line = $chunk;
            $last .= $chunk;
            if (time() - $start > 15) {
                return false;
            }
        } while (strlen($line) >= 4 && $line[3] === '-');
        return substr($last, 0, 3);
    };

    $send = function ($fp, $cmd) use ($readResponse, &$last) {
        if ($cmd !== '') {
            fwrite($fp, $cmd . "\r\n");
        }
        return $readResponse($fp, $last);
    };

    $fp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 10);
    if (!$fp) {
        error_log("Email OTP SMTP: connect failed to $host:$port ($errstr)");
        return false;
    }
    stream_set_timeout($fp, 12);
    $last = '';

    $ok = true;
    $code = $readResponse($fp, $last);                       // 220 greeting
    $ok = $ok && ($code === '220');
    $code = $send($fp, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $ok = $ok && ($code === '250');                         // 250
    $code = $send($fp, 'STARTTLS');                         // 220
    $ok = $ok && ($code === '220');
    if ($ok) {
        $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }
    if ($ok) {
        $code = $send($fp, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $ok = ($code === '250');                            // 250
    }
    if ($ok) {
        $code = $send($fp, 'AUTH LOGIN');                   // 334
        $ok = ($code === '334');
    }
    if ($ok) {
        $code = $send($fp, base64_encode($user));           // 334
        $ok = ($code === '334');
    }
    if ($ok) {
        $code = $send($fp, base64_encode($pass));           // 235
        $ok = ($code === '235');
        if (!$ok) {
            error_log("Email OTP SMTP: AUTH failed for $user (check App Password). Server said: " . trim($last));
        }
    }
    if ($ok) {
        $code = $send($fp, "MAIL FROM:<$from>");            // 250
        $ok = ($code === '250');
    }
    if ($ok) {
        $code = $send($fp, "RCPT TO:<$to>");                // 250
        $ok = ($code === '250');
        if (!$ok) {
            error_log("Email OTP SMTP: recipient rejected <$to>. Server said: " . trim($last));
        }
    }
    if ($ok) {
        $code = $send($fp, 'DATA');                         // 354
        $ok = ($code === '354');
    }
    if ($ok) {
        $headers = "From: " . EMAIL_FROM_NAME . " <$from>\r\n"
            . "Reply-To: <$from>\r\n"
            . "To: <$to>\r\n"
            . "Subject: $subject\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n";
        $data = $headers . "\r\n" . preg_replace('/^\./m', '..', $html_body) . "\r\n.";
        $code = $send($fp, $data);                          // 250
        $ok = ($code === '250');
    }
    $send($fp, 'QUIT');
    fclose($fp);

    if (!$ok) {
        error_log("Email OTP SMTP: send failed for $to. Last server reply: " . trim($last));
    }
    return (bool)$ok;
}
