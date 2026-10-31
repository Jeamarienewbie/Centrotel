<?php
// Configuration for Database Connection

// Shared email + phone format validators (available to every endpoint)
require_once __DIR__ . '/validators.php';

// ── Private credentials ──────────────────────────────────────────────
// Real keys/passwords are NOT stored in this file (it is committed to Git).
// They live in config/secrets.local.php, which is git-ignored. To set up a
// new machine: copy config/secrets.example.php to config/secrets.local.php
// and fill in the values. Missing values simply fall back to empty strings.
$secrets = [
    'sms_token'      => '',
    'smtp_user'      => '',
    'smtp_pass'      => '',
    'db_host_remote' => '',
    'db_name_remote' => '',
    'db_user_remote' => '',
    'db_pass_remote' => '',
];
if (is_file(__DIR__ . '/secrets.local.php')) {
    $secrets = array_merge($secrets, (array) require __DIR__ . '/secrets.local.php');
}

// SMS Settings (PhilSMS)
define('SMS_TOKEN', $secrets['sms_token']);
define('SMS_SENDER', 'PhilSMS'); // Your approved Sender ID

// Email OTP Settings (authenticated SMTP, e.g. Gmail + App Password).
// Fill SMTP_USER + SMTP_PASS to make the Email code channel work —
// plain XAMPP has no mail server, so mail() alone always fails there.
// SMTP_FROM should normally equal SMTP_USER for Gmail.
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', $secrets['smtp_user']);
define('SMTP_PASS', $secrets['smtp_pass']);
define('SMTP_FROM', $secrets['smtp_user']);
define('SMS_FOOTER', '
If you have questions or concerns please call 09568820066');

// Check if we are on localhost (supports both Browser and CLI)
$host_name = $_SERVER['HTTP_HOST'] ?? 'localhost';
$is_localhost = ($host_name === 'localhost' || $host_name === '127.0.0.1' || php_sapi_name() === 'cli');

if ($is_localhost) {
    // Local Development Settings (XAMPP)
    $host = 'localhost';
    $db_name = 'centrotel_db'; // Matches existing local DB
    $username = 'root';
    $password = '';
} else {
    // Remote Production Settings (InfinityFree) — values come from secrets.local.php
    $host = $secrets['db_host_remote'];
    $db_name = $secrets['db_name_remote'];
    $username = $secrets['db_user_remote'];
    $password = $secrets['db_pass_remote'];
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name", $username, $password);
    // Set PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If local connection fails, maybe the DB name is different? 
    // We die with a helpful message
    if ($is_localhost) {
        die("Local Connection failed: " . $e->getMessage() . ". <br>Make sure XAMPP MySQL is running and you have created a database named 'centrotel'.");
    } else {
        die("Remote Connection failed: " . $e->getMessage());
    }
}

// ── Auto-migration: split-name columns on existing installs ──
// Keeps full_name as the canonical display value; backfills parts
// best-effort from it (first token → first, last token → last,
// first middle token initial → M.I.).
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS first_name VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS middle_initial VARCHAR(5) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_name VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS address VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE otp_codes ADD COLUMN IF NOT EXISTS purpose VARCHAR(20) DEFAULT 'reset'");
    $pdo->exec("UPDATE otp_codes SET purpose = 'reset' WHERE purpose IS NULL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS room_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_id INT NOT NULL,
        image_url VARCHAR(255) NOT NULL,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
    )");
    // Heal tables created before sort_order existed
    $pdo->exec("ALTER TABLE room_images ADD COLUMN IF NOT EXISTS sort_order INT DEFAULT 0");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pkg_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        package_id INT NOT NULL,
        image_url VARCHAR(255) NOT NULL,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (package_id) REFERENCES event_packages(id) ON DELETE CASCADE
    )");
    // One-time backfill: existing package covers become gallery photo #1
    $pdo->exec("INSERT INTO pkg_images (package_id, image_url, sort_order)
        SELECT id, image_url, 0 FROM event_packages
        WHERE image_url IS NOT NULL AND image_url != ''
        AND NOT EXISTS (SELECT 1 FROM pkg_images WHERE pkg_images.package_id = event_packages.id)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reservation_id INT NOT NULL UNIQUE,
        user_id INT NOT NULL,
        booking_type ENUM('room', 'event') NOT NULL,
        item_id INT NOT NULL,
        rating TINYINT NOT NULL,
        feedback TEXT NOT NULL,
        status ENUM('approved', 'hidden') DEFAULT 'approved',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
    )");
    $pdo->exec("ALTER TABLE reservations ADD COLUMN IF NOT EXISTS review_token VARCHAR(64) DEFAULT NULL");
    $pdo->exec("ALTER TABLE reservations ADD COLUMN IF NOT EXISTS review_token_expires DATETIME DEFAULT NULL");
    // ── Guest type catalog + per-reservation guest composition ──────
    $pdo->exec("CREATE TABLE IF NOT EXISTS guest_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type_name VARCHAR(50) NOT NULL UNIQUE,
        description VARCHAR(255) DEFAULT NULL,
        sort_order INT DEFAULT 0,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_guests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reservation_id INT NOT NULL,
        guest_type_id INT NOT NULL,
        guest_count INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
        FOREIGN KEY (guest_type_id) REFERENCES guest_types(id) ON DELETE CASCADE,
        UNIQUE KEY (reservation_id, guest_type_id),
        INDEX (guest_type_id)
    )");
    $pdo->exec("INSERT INTO guest_types (type_name, description, sort_order) VALUES
        ('Male', 'Male guests', 1),
        ('Female', 'Female guests', 2),
        ('Children', 'Child guests (below 12 years old)', 3)
        ON DUPLICATE KEY UPDATE type_name = type_name");
    // One-time backfill: existing cover photos become gallery photo #1
    $pdo->exec("INSERT INTO room_images (room_id, image_url, sort_order)
        SELECT id, image_url, 0 FROM rooms
        WHERE image_url IS NOT NULL AND image_url != ''
        AND NOT EXISTS (SELECT 1 FROM room_images WHERE room_images.room_id = rooms.id)");

    $unfilled = $pdo->query("SELECT id, full_name FROM users WHERE first_name IS NULL OR first_name = ''")->fetchAll();
    if ($unfilled) {
        $fill = $pdo->prepare("UPDATE users SET first_name = ?, middle_initial = ?, last_name = ? WHERE id = ?");
        foreach ($unfilled as $row) {
            $tokens = preg_split('/\s+/', trim((string)$row['full_name']));
            $tokens = array_values(array_filter($tokens));
            if (empty($tokens)) {
                continue;
            }
            $first = $tokens[0];
            $last = count($tokens) > 1 ? end($tokens) : '';
            $mi = '';
            if (count($tokens) > 2) {
                $mi = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $tokens[1]), 0, 1));
            }
            $fill->execute([$first, $mi, $last, $row['id']]);
        }
    }
} catch (PDOException $e) {
    error_log('Centrotel name-column migration skipped: ' . $e->getMessage());
}

// Define Global Constants
define('SITE_NAME', 'Centrotel & Event Center');
define('SITE_ADDRESS', 'Piat–Tuao–Pinukpuk Road, Tuao West, Cagayan Valley');
define('SITE_PHONE', '09568820066');
define('SITE_EMAIL', 'centroteleventcenter@gmail.com');
define('BASE_URL', '/');
?>