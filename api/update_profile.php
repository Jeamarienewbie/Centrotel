<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'info') {
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
        $phone = normalizePhone($_POST['phone'] ?? '');

        if ($first_name === false || $last_name === false) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid first and last name (letters only).']);
            exit;
        }

        if ($middle_initial === false) {
            echo json_encode(['success' => false, 'message' => 'Middle initial must be a single letter (e.g. A).']);
            exit;
        }

        if ($phone === false) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid PH mobile number (e.g. 09568820066).']);
            exit;
        }

        if ($address === false) {
            echo json_encode(['success' => false, 'message' => 'Please enter your complete home address (min. 5 characters).']);
            exit;
        }

        $full_name = buildFullName($first_name, $middle_initial, $last_name);

        $stmt = $pdo->prepare("UPDATE users SET first_name = ?, middle_initial = ?, last_name = ?, full_name = ?, address = ?, phone = ? WHERE id = ?");
        if ($stmt->execute([$first_name, $middle_initial, $last_name, $full_name, $address, $phone, $user_id])) {
            $_SESSION['user_name'] = $full_name;
            echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Update failed.']);
        }
    } 
    
    elseif ($action === 'password') {
        $current = $_POST['current_password'];
        $new = trim($_POST['new_password'] ?? '');
        $confirm = trim($_POST['confirm_password'] ?? '');

        if ($new !== $confirm) {
            echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (password_verify($current, $user['password_hash'])) {
            $new_hash = password_hash($new, PASSWORD_BCRYPT);
            $update = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $update->execute([$new_hash, $user_id]);
            echo json_encode(['success' => true, 'message' => 'Password changed successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Current password incorrect.']);
        }
    }
}
?>
