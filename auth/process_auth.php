<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

const MAX_LOGIN_ATTEMPTS = 3;
const LOGIN_LOCKOUT_SECONDS = 30;
const OTP_RESEND_COOLDOWN = 60;

// ── CSRF Helpers ──
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ── Input Sanitization ──
function cleanInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// ── Strong Password Validation ──
function validatePassword($password) {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must contain at least one special character.';
    }
    return null;
}

// ── Login Activity Logger ──
function logLoginAttempt($pdo, $email, $status, $user_id = null) {
    $stmt = $pdo->prepare("INSERT INTO login_logs (user_id, email, ip_address, user_agent, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $user_id,
        $email,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500),
        $status
    ]);
}

// ── Get User ID by Email ──
function getUserIdByEmail($pdo, $email) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ? $row['id'] : null;
}

// Helper: get admin phone from database
function getAdminPhone($pdo) {
    $stmt = $pdo->query("SELECT phone FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch();
    return $row ? $row['phone'] : null;
}

// ── Shared portal login with per-account lockout + activity logging ──
// $portal 'client' accepts customers only, 'admin' accepts admins only.
function attemptPortalLogin($pdo, $email, $password, $portal) {
    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Please enter both email and password.']);
        exit;
    }

    // ── Per-account lockout check (database-backed) ──
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM login_logs WHERE email = ? AND status IN ('failed','locked') AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$email, LOGIN_LOCKOUT_SECONDS]);
    $fail_count = (int)$stmt->fetch()['cnt'];

    // If at or over limit, get the earliest failure time to calculate remaining lockout
    if ($fail_count >= MAX_LOGIN_ATTEMPTS) {
        $stmt = $pdo->prepare("SELECT UNIX_TIMESTAMP(created_at) as ts FROM login_logs WHERE email = ? AND status IN ('failed','locked') AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND) ORDER BY id ASC LIMIT 1");
        $stmt->execute([$email, LOGIN_LOCKOUT_SECONDS]);
        $first = $stmt->fetch();
        if ($first) {
            $lockout_until = (int)$first['ts'] + LOGIN_LOCKOUT_SECONDS;
            $remaining = $lockout_until - time();
            if ($remaining > 0) {
                echo json_encode(['success' => false, 'message' => "Too many failed attempts. Please wait {$remaining} seconds.", 'locked' => true, 'remaining' => $remaining]);
                exit;
            }
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE BINARY email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        if ($portal === 'admin' && $user['role'] !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'This portal is for administrators only. Guests, please log in on the main site.']);
            exit;
        }
        if ($portal === 'client' && $user['role'] === 'admin') {
            echo json_encode(['success' => false, 'message' => 'This login is for guests. Administrators, please use the admin portal.', 'admin_portal' => true]);
            exit;
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];

        logLoginAttempt($pdo, $email, 'success', $user['id']);

        $redirect = ($portal === 'admin') ? 'admin/dashboard.php' : 'index.php';
        echo json_encode(['success' => true, 'redirect' => $redirect]);
    } else {
        if (!$user) {
            $message = 'Email not found.';
        } else {
            $message = 'Wrong password.';
        }

        logLoginAttempt($pdo, $email, 'failed', $user ? $user['id'] : null);

        // Re-count attempts AFTER logging this failure
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM login_logs WHERE email = ? AND status IN ('failed','locked') AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->execute([$email, LOGIN_LOCKOUT_SECONDS]);
        $total = (int)$stmt->fetch()['cnt'];
        $attempts_left = MAX_LOGIN_ATTEMPTS - $total;

        if ($total >= MAX_LOGIN_ATTEMPTS) {
            logLoginAttempt($pdo, $email, 'locked', $user ? $user['id'] : null);

            // Send warning SMS to admin's registered phone
            require_once '../config/sms_helper.php';
            $admin_phone = getAdminPhone($pdo);
            if ($admin_phone) {
                sendSMS($admin_phone, "Centrotel SECURITY: $total failed sign-ins on account $email. Locked for " . LOGIN_LOCKOUT_SECONDS . "s. If this wasn't you, please review the login logs.");
            }

            echo json_encode(['success' => false, 'message' => $message . " Account locked for " . LOGIN_LOCKOUT_SECONDS . " seconds. Admin has been notified.", 'locked' => true, 'remaining' => LOGIN_LOCKOUT_SECONDS]);
        } else {
            echo json_encode(['success' => false, 'message' => $message . " {$attempts_left} attempt(s) remaining.", 'attempts_left' => $attempts_left]);
        }
    }
}

// ── CSRF token endpoint ──
if ($action === 'csrf_token') {
    echo json_encode(['success' => true, 'token' => generateCSRFToken()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── CSRF validation (skip for csrf_token endpoint) ──
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit;
    }

    // ── REGISTER — Step 1: Validate details → send phone OTP ──
    if ($action === 'register_send_otp' || $action === 'register') {
        $first_name = cleanPersonName($_POST['first_name'] ?? '');
        $last_name = cleanPersonName($_POST['last_name'] ?? '');
        // Names are always stored in Title Case (first letter big)
        if ($first_name !== false) {
            $first_name = titlePersonName($first_name);
        }
        if ($last_name !== false) {
            $last_name = titlePersonName($last_name);
        }
        $middle_initial = normalizeMiddleInitial($_POST['middle_initial'] ?? '');
        $address = cleanAddress($_POST['address'] ?? '');
        $email = normalizeEmail($_POST['email'] ?? '');
        $phone = normalizePhone($_POST['phone'] ?? '');
        $raw_password = trim($_POST['password'] ?? '');
        $security_question = cleanInput($_POST['security_question'] ?? '');
        $security_answer = cleanInput($_POST['security_answer'] ?? '');

        if ($first_name === false || $last_name === false || empty($_POST['email'] ?? '') || empty($_POST['phone'] ?? '') || empty($_POST['address'] ?? '') || empty($raw_password)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit;
        }

        if ($address === false) {
            echo json_encode(['success' => false, 'message' => 'Please enter your complete home address (min. 5 characters).']);
            exit;
        }

        if ($middle_initial === false) {
            echo json_encode(['success' => false, 'message' => 'Middle initial must be a single letter (e.g. A).']);
            exit;
        }

        $full_name = buildFullName($first_name, $middle_initial, $last_name);

        if (empty($_POST['terms_agree'])) {
            echo json_encode(['success' => false, 'message' => 'Please read and agree to the Terms & Conditions to register.']);
            exit;
        }

        if ($email === false) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address (e.g. name@example.com).']);
            exit;
        }

        if ($phone === false) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid PH mobile number (e.g. 09568820066).']);
            exit;
        }

        $pw_error = validatePassword($raw_password);
        if ($pw_error) {
            echo json_encode(['success' => false, 'message' => $pw_error]);
            exit;
        }

        // Email / phone must be unique BEFORE spending an OTP SMS
        $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1");
        $dup->execute([$email, $phone]);
        if ($dup->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email or Phone already exists.']);
            exit;
        }

        // OTP channel chosen by the guest: SMS (Globe/TM handsets) or Email
        $otp_channel = strtolower(trim($_POST['otp_channel'] ?? 'sms'));
        if (!in_array($otp_channel, ['sms', 'email'], true)) {
            $otp_channel = 'sms';
        }
        if ($otp_channel === 'sms' && !isGlobeTmNumber($phone)) {
            echo json_encode(['success' => false, 'message' => 'SMS codes are sent to Globe and TM numbers only. Please use a Globe/TM number or choose Email instead.', 'needs_channel' => 'email']);
            exit;
        }

        // Hold the validated details server-side; the account is created
        // only after the OTP is verified (register_verify_otp).
        $_SESSION['reg_pending'] = [
            'first_name' => $first_name,
            'middle_initial' => $middle_initial,
            'last_name' => $last_name,
            'full_name' => $full_name,
            'address' => $address,
            'email' => $email,
            'phone' => $phone,
            'password_hash' => password_hash($raw_password, PASSWORD_BCRYPT),
            'security_question' => $security_question,
            'security_answer' => $security_answer,
            'otp_channel' => $otp_channel,
        ];
        $_SESSION['reg_verified'] = false;

        // Invalidate older unused registration OTPs for this email
        $stmt = $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE email = ? AND used = 0 AND purpose = 'register'");
        $stmt->execute([$email]);

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $stmt = $pdo->prepare("INSERT INTO otp_codes (email, otp, expires_at, purpose) VALUES (?, ?, ?, 'register')");
        $stmt->execute([$email, $otp, $expires]);

        $_SESSION['reg_otp_last_sent'] = time();

        if ($otp_channel === 'email') {
            require_once '../config/email_helper.php';
            $sent = sendOtpEmail($email, $otp, 5);
            $dest = maskEmail($email);
        } else {
            require_once '../config/sms_helper.php';
            $sent = sendSMS($phone, "Your Centrotel registration code is $otp. Valid for 5 minutes — never share it with anyone.");
            $dest = maskPhone($phone);
        }

        if ($sent) {
            echo json_encode(['success' => true, 'otp_channel' => $otp_channel, 'message' => "Verification code sent to $dest."]);
        } else {
            $hint = $otp_channel === 'email'
                ? 'Failed to send the email code. Please check the address or try SMS instead.'
                : 'Failed to send verification code. Please try again.';
            echo json_encode(['success' => false, 'message' => $hint]);
        }
    }

    // ── REGISTER — Step 2: Verify OTP → create the account ──
    elseif ($action === 'register_verify_otp') {
        $otp_input = preg_replace('/\s+/', '', $_POST['otp'] ?? '');

        if (strlen($otp_input) !== 6 || !ctype_digit($otp_input)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid 6-digit code.']);
            exit;
        }

        if (empty($_SESSION['reg_pending']) || empty($_SESSION['reg_pending']['email'])) {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please start registration again.']);
            exit;
        }

        $pending = $_SESSION['reg_pending'];
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("SELECT * FROM otp_codes WHERE email = ? AND otp = ? AND used = 0 AND purpose = 'register' AND expires_at > ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$pending['email'], $otp_input, $now]);
        $otp_record = $stmt->fetch();

        if (!$otp_record) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired code. Please try again or resend.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE id = ?");
        $stmt->execute([$otp_record['id']]);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (first_name, middle_initial, last_name, full_name, address, email, phone, password_hash, security_question, security_answer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$pending['first_name'], $pending['middle_initial'], $pending['last_name'], $pending['full_name'], $pending['address'], $pending['email'], $pending['phone'], $pending['password_hash'], $pending['security_question'], $pending['security_answer']]);

            require_once '../config/sms_helper.php';
            sendSMS($pending['phone'], "Hi " . $pending['full_name'] . "! Your Centrotel account is verified — welcome! Log in to book rooms & event packages anytime.");

            unset($_SESSION['reg_pending'], $_SESSION['reg_verified'], $_SESSION['reg_otp_last_sent']);

            echo json_encode(['success' => true, 'message' => 'Verified! Registration successful — you can now login.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Email or Phone already exists.']);
        }
    }

    // ── REGISTER — Resend OTP ──
    elseif ($action === 'register_resend_otp') {
        if (empty($_SESSION['reg_pending']) || empty($_SESSION['reg_pending']['email'])) {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please start registration again.']);
            exit;
        }

        if (isset($_SESSION['reg_otp_last_sent']) && (time() - $_SESSION['reg_otp_last_sent']) < OTP_RESEND_COOLDOWN) {
            $wait = OTP_RESEND_COOLDOWN - (time() - $_SESSION['reg_otp_last_sent']);
            echo json_encode(['success' => false, 'message' => "Please wait {$wait} seconds before requesting a new code."]);
            exit;
        }

        $pending = $_SESSION['reg_pending'];

        $stmt = $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE email = ? AND used = 0 AND purpose = 'register'");
        $stmt->execute([$pending['email']]);

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $stmt = $pdo->prepare("INSERT INTO otp_codes (email, otp, expires_at, purpose) VALUES (?, ?, ?, 'register')");
        $stmt->execute([$pending['email'], $otp, $expires]);

        $_SESSION['reg_otp_last_sent'] = time();

        $resend_channel = $pending['otp_channel'] ?? 'sms';
        if ($resend_channel === 'email') {
            require_once '../config/email_helper.php';
            $sent = sendOtpEmail($pending['email'], $otp, 5);
            $dest = maskEmail($pending['email']);
        } else {
            require_once '../config/sms_helper.php';
            $sent = sendSMS($pending['phone'], "Your Centrotel registration code is $otp. Valid for 5 minutes — never share it with anyone.");
            $dest = maskPhone($pending['phone']);
        }

        if ($sent) {
            echo json_encode(['success' => true, 'message' => "New code sent to $dest."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send verification code. Please try again.']);
        }
    }

    // ── LOGIN (with per-account lockout + activity logging) ──
    // ── CLIENT login (index.php guests only; admins are redirected) ──
    elseif ($action === 'login') {
        $email = cleanInput($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        attemptPortalLogin($pdo, $email, $password, 'client');
    }

    // ── ADMIN login (admin_login.php administrators only) ──
    elseif ($action === 'admin_login') {
        $email = cleanInput($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        attemptPortalLogin($pdo, $email, $password, 'admin');
    }

    // ── FORGOT PASSWORD — Step 1: Send OTP to registered phone ──
    elseif ($action === 'send_otp') {
        $email_raw = trim($_POST['email'] ?? '');

        if ($email_raw === '') {
            echo json_encode(['success' => false, 'message' => 'Please enter your email address.']);
            exit;
        }

        $email = normalizeEmail($email_raw);
        if ($email === false) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address (e.g. name@example.com).']);
            exit;
        }

        // Rate limit: check cooldown
        if (isset($_SESSION['otp_last_sent']) && (time() - $_SESSION['otp_last_sent']) < OTP_RESEND_COOLDOWN) {
            $wait = OTP_RESEND_COOLDOWN - (time() - $_SESSION['otp_last_sent']);
            echo json_encode(['success' => false, 'message' => "Please wait {$wait} seconds before requesting a new code."]);
            exit;
        }

        // Lookup user by email only
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'No account found with that email address.']);
            exit;
        }

        $user_email = $user['email'];
        $phone = $user['phone'];
        $role = $user['role'];

        // Store in session; the guest now picks SMS or Email for the code
        $_SESSION['forgot_email'] = $user_email;
        $_SESSION['forgot_role'] = $role;
        $_SESSION['forgot_verified'] = false;
        $_SESSION['forgot_channel'] = null;

        $sms_ok = isGlobeTmNumber($phone);
        echo json_encode([
            'success' => true,
            'role' => $role,
            'is_customer' => ($role !== 'admin'),
            'channel_sms' => $sms_ok,
            'channel_sms_to' => $sms_ok ? maskPhone($phone) : null,
            'channel_sms_note' => $sms_ok ? null : 'SMS codes go to Globe/TM numbers only — this account has a different carrier.',
            'channel_email_to' => maskEmail($user_email),
        ]);
    }

    // ── FORGOT PASSWORD — Step 1b: Send the code via the chosen channel ──
    elseif ($action === 'send_otp_code') {
        if (empty($_SESSION['forgot_email'])) {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please start again.']);
            exit;
        }

        if (isset($_SESSION['otp_last_sent']) && (time() - $_SESSION['otp_last_sent']) < OTP_RESEND_COOLDOWN) {
            $wait = OTP_RESEND_COOLDOWN - (time() - $_SESSION['otp_last_sent']);
            echo json_encode(['success' => false, 'message' => "Please wait {$wait} seconds before requesting a new code."]);
            exit;
        }

        $email = $_SESSION['forgot_email'];
        $channel = strtolower(trim($_POST['channel'] ?? 'sms'));
        if (!in_array($channel, ['sms', 'email'], true)) {
            $channel = 'sms';
        }

        $stmt = $pdo->prepare("SELECT phone FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $phone = $stmt->fetchColumn();

        if ($channel === 'sms' && !isGlobeTmNumber($phone)) {
            echo json_encode(['success' => false, 'message' => 'SMS codes go to Globe and TM numbers only. Please choose Email instead.']);
            exit;
        }

        // Invalidate older unused password-reset OTPs (registration OTPs untouched)
        $stmt = $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE email = ? AND used = 0 AND purpose = 'reset'");
        $stmt->execute([$email]);

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $stmt = $pdo->prepare("INSERT INTO otp_codes (email, otp, expires_at, purpose) VALUES (?, ?, ?, 'reset')");
        $stmt->execute([$email, $otp, $expires]);

        $_SESSION['otp_last_sent'] = time();
        $_SESSION['forgot_channel'] = $channel;

        if ($channel === 'email') {
            require_once '../config/email_helper.php';
            $sent = sendOtpEmail($email, $otp, 5);
            $dest = maskEmail($email);
        } else {
            require_once '../config/sms_helper.php';
            $sent = sendSMS($phone, "Your Centrotel verification code is $otp. Valid for 5 minutes — never share it with anyone.");
            $dest = maskPhone($phone);
        }

        if ($sent) {
            echo json_encode(['success' => true, 'channel' => $channel, 'message' => "Code sent to $dest. Valid for 5 minutes."]);
        } else {
            $hint = $channel === 'email'
                ? 'Failed to send the email code. Please try SMS instead.'
                : 'Failed to send OTP. Please try again.';
            echo json_encode(['success' => false, 'message' => $hint]);
        }
    }

    // ── FORGOT PASSWORD — Step 2: Verify OTP ──
    elseif ($action === 'verify_otp') {
        $otp_input = preg_replace('/\s+/', '', $_POST['otp'] ?? '');

        if (strlen($otp_input) !== 6 || !ctype_digit($otp_input)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid 6-digit code.']);
            exit;
        }

        if (!isset($_SESSION['forgot_email'])) {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please start again.']);
            exit;
        }

        $email = $_SESSION['forgot_email'];
        $now = date('Y-m-d H:i:s');

        // Find valid (unused, not expired) password-reset OTP
        $stmt = $pdo->prepare("SELECT * FROM otp_codes WHERE email = ? AND otp = ? AND used = 0 AND purpose = 'reset' AND expires_at > ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$email, $otp_input, $now]);
        $otp_record = $stmt->fetch();

        if ($otp_record) {
            $stmt = $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE id = ?");
            $stmt->execute([$otp_record['id']]);

            $_SESSION['forgot_verified'] = true;
            echo json_encode(['success' => true, 'message' => 'OTP verified! You may now set a new password.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP. Please try again.']);
        }
    }

    // ── FORGOT PASSWORD — Customer: Verify Security Answer ──
    elseif ($action === 'verify_security_answer') {
        $answer = trim($_POST['security_answer'] ?? '');

        if (empty($answer)) {
            echo json_encode(['success' => false, 'message' => 'Please enter your security answer.']);
            exit;
        }

        if (!isset($_SESSION['forgot_email']) || $_SESSION['forgot_role'] !== 'customer') {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please start again.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT security_answer FROM users WHERE email = ?");
        $stmt->execute([$_SESSION['forgot_email']]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Account not found.']);
            exit;
        }

        // Decode stored value (may contain HTML entities if saved via cleanInput)
        $stored_answer = html_entity_decode($user['security_answer'], ENT_QUOTES, 'UTF-8');

        // Case-insensitive comparison
        if (strcasecmp(trim($answer), trim($stored_answer)) === 0) {
            $_SESSION['forgot_verified'] = true;
            echo json_encode(['success' => true, 'message' => 'Answer verified! You may now set a new password.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Incorrect security answer. Please try again.']);
        }
    }

    // ── FORGOT PASSWORD — Resend OTP via the previously chosen channel ──
    elseif ($action === 'resend_otp') {
        if (!isset($_SESSION['forgot_email'])) {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please start again.']);
            exit;
        }

        // Rate limit: check cooldown
        if (isset($_SESSION['otp_last_sent']) && (time() - $_SESSION['otp_last_sent']) < OTP_RESEND_COOLDOWN) {
            $wait = OTP_RESEND_COOLDOWN - (time() - $_SESSION['otp_last_sent']);
            echo json_encode(['success' => false, 'message' => "Please wait {$wait} seconds before requesting a new code."]);
            exit;
        }

        $email = $_SESSION['forgot_email'];
        $channel = $_SESSION['forgot_channel'] ?? 'sms';

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Account not found.']);
            exit;
        }

        if ($channel === 'sms' && !isGlobeTmNumber($user['phone'])) {
            echo json_encode(['success' => false, 'message' => 'SMS codes go to Globe and TM numbers only. Please restart and choose Email instead.']);
            exit;
        }

        // Invalidate old password-reset OTPs
        $stmt = $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE email = ? AND used = 0 AND purpose = 'reset'");
        $stmt->execute([$email]);

        // Generate new OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $stmt = $pdo->prepare("INSERT INTO otp_codes (email, otp, expires_at, purpose) VALUES (?, ?, ?, 'reset')");
        $stmt->execute([$email, $otp, $expires]);

        $_SESSION['otp_last_sent'] = time();

        if ($channel === 'email') {
            require_once '../config/email_helper.php';
            $sent = sendOtpEmail($email, $otp, 5);
            $dest = maskEmail($email);
        } else {
            require_once '../config/sms_helper.php';
            $sent = sendSMS($user['phone'], "Your Centrotel verification code is $otp. Valid for 5 minutes — never share it with anyone.");
            $dest = maskPhone($user['phone']);
        }

        if ($sent) {
            echo json_encode(['success' => true, 'message' => "New code sent to $dest."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send code. Please try again.']);
        }
    }

    // ── FORGOT PASSWORD — Step 3: Reset password (after OTP verified) ──
    elseif ($action === 'reset_forgot_password') {
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        if (!isset($_SESSION['forgot_verified']) || $_SESSION['forgot_verified'] !== true || !isset($_SESSION['forgot_email'])) {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please start again.']);
            exit;
        }

        if ($new_password !== $confirm_password) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
            exit;
        }

        $pw_error = validatePassword($new_password);
        if ($pw_error) {
            echo json_encode(['success' => false, 'message' => $pw_error]);
            exit;
        }

        $hashed = password_hash($new_password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $stmt->execute([$hashed, $_SESSION['forgot_email']]);

        // Cleanup all OTPs for this email
        $stmt = $pdo->prepare("DELETE FROM otp_codes WHERE email = ?");
        $stmt->execute([$_SESSION['forgot_email']]);

        unset($_SESSION['forgot_email'], $_SESSION['forgot_role'], $_SESSION['forgot_verified'], $_SESSION['otp_last_sent'], $_SESSION['forgot_sec_question']);

        echo json_encode(['success' => true, 'message' => 'Password has been reset successfully! You can now login.']);
    }
}
?>
