<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT r.*, rm.room_type, rm.room_number, ep.package_name,
           p.id AS payment_id, p.amount AS payment_amount, p.status AS payment_status, p.payment_method,
           rv.id AS review_id
    FROM reservations r
    LEFT JOIN rooms rm ON r.item_id = rm.id AND r.booking_type = 'room'
    LEFT JOIN event_packages ep ON r.item_id = ep.id AND r.booking_type = 'event'
    LEFT JOIN payments p ON p.reservation_id = r.id
    LEFT JOIN reviews rv ON rv.reservation_id = r.id
    WHERE r.user_id = ? 
    ORDER BY r.created_at DESC
");
$stmt->execute([$user_id]);
$reservations = $stmt->fetchAll();

// Guest composition (Male / Female / Children ...) per reservation
$breakdown_map = [];
try {
    $gb_stmt = $pdo->prepare("
        SELECT rg.reservation_id, rg.guest_count, gt.type_name
        FROM reservation_guests rg
        JOIN guest_types gt ON gt.id = rg.guest_type_id
        WHERE rg.reservation_id IN (SELECT id FROM reservations WHERE user_id = ?)
          AND rg.guest_count > 0
        ORDER BY gt.sort_order ASC, gt.id ASC
    ");
    $gb_stmt->execute([$user_id]);
    foreach ($gb_stmt->fetchAll() as $g) {
        $breakdown_map[(int)$g['reservation_id']][] = [
            'type_name'   => $g['type_name'],
            'guest_count' => (int)$g['guest_count'],
        ];
    }
} catch (PDOException $e) {
    $breakdown_map = [];
}

foreach ($reservations as &$res) {
    $res['guest_breakdown'] = $breakdown_map[(int)$res['id']] ?? [];
}
unset($res);

echo json_encode([
    'success' => true,
    'reservations' => $reservations
]);
?>
