<?php
require_once 'config/db.php';

$email = 'admin@centrotel.com';
$password = 'admin123';
$hash = password_hash($password, PASSWORD_BCRYPT);
$name = 'Admin Centrotel';
$phone = '09568820066';
$role = 'admin';

try {
    // Check if exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, role = 'admin' WHERE email = ?");
        $stmt->execute([$hash, $email]);
        echo "Admin password updated to 'admin123' successfully!";
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (first_name, middle_initial, last_name, full_name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['Admin', '', 'Centrotel', $name, $email, $phone, $hash, $role]);
        echo "Admin account created with password 'admin123' successfully!";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
