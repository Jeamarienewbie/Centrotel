<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$total_bookings = (int)$pdo->query("SELECT COUNT(*) FROM reservations")->fetchColumn();
$total_revenue = (float)($pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='Paid'")->fetchColumn() ?? 0);
$pending_approvals = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status='Pending Approval'")->fetchColumn();
$active_rooms = (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE status='available'")->fetchColumn();

// Recent reservations
$recent = $pdo->query("
    SELECT r.id, r.booking_type, r.check_in_date, r.total_price, r.status, r.created_at,
           u.full_name,
           rm.room_type, ep.package_name
    FROM reservations r 
    JOIN users u ON r.user_id = u.id 
    LEFT JOIN rooms rm ON r.item_id = rm.id AND r.booking_type = 'room'
    LEFT JOIN event_packages ep ON r.item_id = ep.id AND r.booking_type = 'event'
    ORDER BY r.created_at DESC LIMIT 5
")->fetchAll();

echo json_encode([
    'success' => true,
    'total_bookings' => $total_bookings,
    'total_revenue' => $total_revenue,
    'pending_approvals' => $pending_approvals,
    'active_rooms' => $active_rooms,
    'recent_reservations' => $recent
]);
?>
