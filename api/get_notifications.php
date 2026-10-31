<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

$last_id = (int)($_GET['last_id'] ?? 0);

// Fetch notifications newer than last_id
if ($last_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND id > ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$user_id, $last_id]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$user_id]);
}
$notifications = $stmt->fetchAll();

// Count unread
$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_stmt->execute([$user_id]);
$unread_count = (int)$unread_stmt->fetchColumn();

// Mark all as read
$pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$user_id]);

echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'unread_count' => $unread_count
]);
?>
