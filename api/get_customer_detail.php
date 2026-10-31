<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get customer ID from request
$customer_id = (int)($_GET['id'] ?? 0);

if ($customer_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
    exit();
}

// Fetch customer details
$stmt = $pdo->prepare("
    SELECT id, first_name, middle_initial, last_name, full_name, address, email, phone, security_question, security_answer, created_at
    FROM users WHERE id = ? AND role = 'customer'
");
$stmt->execute([$customer_id]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Customer not found']);
    exit();
}

// Format date
$created = date('M d, Y', strtotime($user['created_at']));

echo json_encode([
    'success' => true,
    'customer' => [
        'id' => $user['id'],
        'name' => $user['full_name'],
        'first_name' => $user['first_name'] ?? '',
        'middle_initial' => $user['middle_initial'] ?? '',
        'last_name' => $user['last_name'] ?? '',
        'address' => $user['address'] ?? '',
        'email' => $user['email'],
        'phone' => $user['phone'],
        'security_question' => $user['security_question'],
        'registered' => $created
    ]
]);