<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$reservations = $pdo->query("
    SELECT r.*,
           u.full_name, u.email, u.phone,
           rm.room_number, rm.room_type,
           ep.package_name,
           p.id AS payment_id,
           p.payment_method,
           p.proof_of_payment_url,
           p.status AS payment_status,
           p.transaction_date,
           p.amount AS payment_amount,
           rv.id AS review_id
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.item_id = rm.id AND r.booking_type = 'room'
    LEFT JOIN event_packages ep ON r.item_id = ep.id AND r.booking_type = 'event'
    LEFT JOIN payments p ON p.reservation_id = r.id
    LEFT JOIN reviews rv ON rv.reservation_id = r.id
    ORDER BY r.created_at DESC
")->fetchAll();

// Guest composition per reservation (Male / Female / Children ...)
$breakdown_map = [];
try {
    $gb_stmt = $pdo->query("
        SELECT rg.reservation_id, rg.guest_count, gt.type_name
        FROM reservation_guests rg
        JOIN guest_types gt ON gt.id = rg.guest_type_id
        WHERE rg.guest_count > 0
        ORDER BY gt.sort_order ASC, gt.id ASC
    ");
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

// Stats
$total_revenue   = (float)$pdo->query("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.status = 'Paid'")->fetchColumn();
$pending_count   = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status='Pending Approval'")->fetchColumn();
$payment_pending = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'Pending'")->fetchColumn();
$completed       = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'Completed'")->fetchColumn();

echo json_encode([
    'success' => true,
    'reservations' => $reservations,
    'stats' => [
        'total_revenue' => $total_revenue,
        'pending_count' => $pending_count,
        'payment_pending' => $payment_pending,
        'completed' => $completed
    ]
]);
?>
