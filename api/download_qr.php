<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

$method_id = isset($_GET['method_id']) ? (int)$_GET['method_id'] : 0;

if ($method_id <= 0) {
    http_response_code(400);
    exit('Invalid method ID');
}

try {
    $stmt = $pdo->prepare("SELECT qr_code_url, method_name FROM payment_methods WHERE id = ? AND status = 'active'");
    $stmt->execute([$method_id]);
    $method = $stmt->fetch();

    if (!$method || empty($method['qr_code_url'])) {
        http_response_code(404);
        exit('QR code not found');
    }

    $filePath = '../' . $method['qr_code_url'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        exit('QR code file not found');
    }

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
    ];
    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';

    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $method['method_name']) . '_QR_Code.' . $ext;

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($filePath);
    exit();
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database error');
}
