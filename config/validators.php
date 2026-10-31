<?php
// Shared email + phone format validators.
// Loaded automatically via config/db.php, so every endpoint can use them.

/**
 * Proper standard email format: trimmed, lowercased, must contain "@"
 * and a dot-suffix domain (e.g. name@example.com), fits VARCHAR(100).
 * Returns the normalized email string, or false when invalid.
 */
function normalizeEmail($email) {
    $email = strtolower(trim((string)$email));
    if ($email === '' || strlen($email) > 100) {
        return false;
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    // Standard shape only: local@domain.tld with a 2+ letter ending
    if (!preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}$/', $email)) {
        return false;
    }
    return $email;
}

/**
 * Proper PH mobile format. Accepts 09XXXXXXXXX, +639XXXXXXXXX and
 * 639XXXXXXXXX (spaces, dashes and parentheses are ignored).
 * Returns the normalized 09XXXXXXXXX form, or false when invalid.
 */
function normalizePhone($phone) {
    $digits = preg_replace('/[\s\-()]/', '', trim((string)$phone));
    if (strpos($digits, '+63') === 0) {
        $digits = '0' . substr($digits, 3);
    } elseif (preg_match('/^63\d{10}$/', $digits)) {
        $digits = '0' . substr($digits, 2);
    }
    if (!preg_match('/^09\d{9}$/', $digits)) {
        return false;
    }
    return $digits;
}

/**
 * Globe / TM mobile prefixes (4-digit 09XX). Only these carriers can
 * receive authentication OTPs.
 */
function globeTmPrefixes() {
    return ['0817','0905','0906','0915','0916','0917','0926','0927','0935','0936','0937',
            '0945','0953','0954','0955','0956','0965','0966','0967','0975','0976','0977',
            '0995','0996','0997'];
}

/**
 * True when the number (any accepted input shape) belongs to Globe or TM.
 */
function isGlobeTmNumber($phone) {
    $normalized = normalizePhone($phone);
    if ($normalized === false) {
        return false;
    }
    return in_array(substr($normalized, 0, 4), globeTmPrefixes(), true);
}

/**
 * Proper PH mobile format restricted to Globe/TM (OTP-capable).
 * Returns the normalized 09XXXXXXXXX form, or false when invalid
 * or on another carrier.
 */
function normalizeGlobeTmPhone($phone) {
    $normalized = normalizePhone($phone);
    if ($normalized === false) {
        return false;
    }
    if (!in_array(substr($normalized, 0, 4), globeTmPrefixes(), true)) {
        return false;
    }
    return $normalized;
}

/**
 * Mask an email for display: j***@example.com
 */
function maskEmail($email) {
    $parts = explode('@', (string)$email);
    if (count($parts) !== 2) {
        return 'your email';
    }
    $local = $parts[0];
    $shown = strlen($local) <= 2 ? substr($local, 0, 1) . '*' : substr($local, 0, 1) . '***';
    return $shown . '@' . $parts[1];
}

/**
 * Mask a phone for display: 0956 XXX 0066
 */
function maskPhone($phone) {
    $p = (string)$phone;
    if (strlen($p) < 7) {
        return 'your phone';
    }
    return substr($p, 0, 4) . ' XXX ' . substr($p, -4);
}

/**
 * Get-or-create the single-use guest survey token for a reservation.
 * Returns the token string (valid 30 days).
 */
function ensureReviewToken($pdo, $res_id) {
    $t = $pdo->prepare("SELECT review_token, review_token_expires FROM reservations WHERE id = ?");
    $t->execute([$res_id]);
    $row = $t->fetch() ?: [];
    if (!empty($row['review_token']) && !empty($row['review_token_expires']) && strtotime($row['review_token_expires']) > time()) {
        return $row['review_token'];
    }
    $tok = bin2hex(random_bytes(16));
    $exp = date('Y-m-d H:i:s', strtotime('+30 days'));
    $pdo->prepare("UPDATE reservations SET review_token = ?, review_token_expires = ? WHERE id = ?")->execute([$tok, $exp, $res_id]);
    return $tok;
}

/**
 * Send the standalone post-booking survey SMS (rating + feedback link).
 * Token is auto-created/refreshed. Returns true when actually sent.
 */
function sendSurveySms($pdo, $res_id, $guest_phone, $guest_name, $sms_unit, $sms_ref) {
    if (empty($guest_phone)) {
        return false;
    }
    $base = site_url();
    if ($base === '') {
        return false;
    }
    require_once __DIR__ . '/sms_helper.php';
    $tok = ensureReviewToken($pdo, $res_id);
    $msg = "Hi $guest_name! ⭐ How was your stay at $sms_unit (booking #$sms_ref)? Please rate us & leave feedback here: $base/review.php?token=$tok Thank you for choosing Centrotel!";
    return sendSMS($guest_phone, $msg);
}

/**
 * Absolute URL of the app root (for clickable links in SMS).
 * Derives from the current request; strips /admin, /api, /auth suffixes.
 */
function site_url() {
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        foreach (['/admin', '/api', '/auth'] as $suffix) {
            if (substr($dir, -strlen($suffix)) === $suffix) {
                $dir = substr($dir, 0, -strlen($suffix));
                break;
            }
        }
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;
    }
    return '';
}

/**
 * Render a 1–5 star display (supports halves) for an average rating.
 */
function starsHtml($avg) {
    $avg = max(0, min(5, (float)$avg));
    $full = (int)floor($avg);
    $out = '<span class="stars" style="color:#f59e0b;letter-spacing:2px;">';
    for ($i = 1; $i <= 5; $i++) {
        $out .= ($i <= $full)
            ? '★'
            : '<span style="color:#e2e8f0;">★</span>';
    }
    return $out . '</span>';
}

/**
 * Proper person-name format: letters (incl. ñ), spaces, apostrophes,
 * hyphens and periods; collapsed spacing; max 50 chars.
 * Returns the cleaned name, or false when invalid.
 */
function cleanPersonName($name) {
    $name = trim(preg_replace('/\s+/', ' ', (string)$name));
    if ($name === '' || strlen($name) > 50) {
        return false;
    }
    if (!preg_match("/^[\p{L}\s.'-]+$/u", $name)) {
        return false;
    }
    return $name;
}

/**
 * Middle initial: optional single letter. Accepts "A" or "A.".
 * Returns the uppercase letter, '' when left blank, or false when invalid.
 */
function normalizeMiddleInitial($mi) {
    $mi = trim((string)$mi);
    if ($mi === '') {
        return '';
    }
    $letter = preg_replace('/[^A-Za-z]/', '', $mi);
    if (strlen($letter) !== 1) {
        return false;
    }
    return strtoupper($letter);
}

/**
 * Title Case for names: first letter big ("juan dela cruz" → "Juan Dela Cruz",
 * "o'brien" → "O'Brien", "anne-marie" → "Anne-Marie").
 */
function titlePersonName($name) {
    $lower = mb_strtolower((string)$name, 'UTF-8');
    return preg_replace_callback(
        "/(^|[\s\-'])(\p{L})/u",
        function ($m) { return $m[1] . mb_strtoupper($m[2], 'UTF-8'); },
        $lower
    );
}

/**
 * Canonical display name: "First M. Last" (or "First Last" without M.I.).
 */
function buildFullName($first_name, $middle_initial, $last_name) {
    $full = $first_name;
    if ($middle_initial !== '') {
        $full .= ' ' . $middle_initial . '.';
    }
    $full .= ' ' . $last_name;
    return $full;
}

/**
 * Proper address format: tags stripped, collapsed spacing,
 * 5–255 chars so it fits users.address VARCHAR(255).
 * Returns the cleaned address, or false when invalid.
 */
function cleanAddress($address) {
    $address = trim(preg_replace('/\s+/', ' ', strip_tags((string)$address)));
    if (strlen($address) < 5 || strlen($address) > 255) {
        return false;
    }
    return $address;
}
