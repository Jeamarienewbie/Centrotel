<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$reservation_id = (int)($_GET['id'] ?? 0);

if ($reservation_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid reservation ID']);
    exit();
}

// Fetch reservation with full details
$stmt = $pdo->prepare("
    SELECT r.*, rm.room_type, rm.room_number, ep.package_name,
           p.id AS payment_id, p.amount AS payment_amount, p.status AS payment_status, p.payment_method, p.proof_of_payment_url,
           u.full_name, u.phone
    FROM reservations r 
    LEFT JOIN rooms rm ON r.item_id = rm.id AND r.booking_type = 'room'
    LEFT JOIN event_packages ep ON r.item_id = ep.id AND r.booking_type = 'event'
    LEFT JOIN payments p ON p.reservation_id = r.id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.id = ? AND r.user_id = ?
");
$stmt->execute([$reservation_id, $user_id]);
$reservation = $stmt->fetch();

if (!$reservation) {
    echo json_encode(['success' => false, 'message' => 'Reservation not found or access denied']);
    exit();
}

// Guest composition (Male / Female / Children ...) for this reservation
$breakdown = [];
$gb_stmt = $pdo->prepare("
    SELECT rg.guest_count, gt.type_name
    FROM reservation_guests rg
    JOIN guest_types gt ON gt.id = rg.guest_type_id
    WHERE rg.reservation_id = ? AND rg.guest_count > 0
    ORDER BY gt.sort_order ASC, gt.id ASC
");
$gb_stmt->execute([$reservation_id]);
foreach ($gb_stmt->fetchAll() as $g) {
    $breakdown[] = ['type_name' => $g['type_name'], 'guest_count' => (int)$g['guest_count']];
}

// Format dates
$check_in = date('M d, Y', strtotime($reservation['check_in_date']));
$check_out = '';
$nights = 0;
if (!empty($reservation['check_out_date']) && $reservation['check_out_date'] !== $reservation['check_in_date']) {
    $check_out = date('M d, Y', strtotime($reservation['check_out_date']));
    $n_nights = max(1, (int)round((strtotime($reservation['check_out_date']) - strtotime($reservation['check_in_date'])) / 86400));
    $nights = $n_nights;
}

// Build services included text
$services_text = '';
if ($reservation['booking_type'] === 'event' && !empty($reservation['services_included'])) {
    $svcs = array_map('trim', explode(',', $reservation['services_included']));
    $services_text = implode(', ', array_slice($svcs, 0, 3));
    if (count($svcs) > 3) {
        $services_text .= ', +' . (count($svcs) - 3) . ' more';
    }
}

echo json_encode([
    'success' => true,
    'reservation' => [
        'id' => $reservation['id'],
        'booking_type' => $reservation['booking_type'],
        'room_type' => $reservation['room_type'] ?? ($reservation['package_name'] ?? 'N/A'),
        'check_in_date' => $check_in,
        'check_out_date' => $check_out,
        'nights' => $nights,
        'guest_count' => $reservation['guest_count'],
        'guest_breakdown' => $breakdown,
        'total_price' => $reservation['total_price'],
        'status' => $reservation['status'],
        'payment_status' => $reservation['payment_status'],
        'payment_method' => $reservation['payment_method'],
        'services' => $services_text,
        'phone' => $reservation['phone'],
        'full_name' => $reservation['full_name'],
    ]
]);