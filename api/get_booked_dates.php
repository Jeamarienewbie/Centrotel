<?php
require_once '../config/db.php';

$item_id = $_GET['item_id'] ?? 0;
$type = $_GET['type'] ?? 'room';

$booked_dates = [];

if ($type === 'room') {
    // Rooms: get all active bookings for THIS SPECIFIC room
    $stmt = $pdo->prepare("
        SELECT check_in_date, check_out_date
        FROM reservations
        WHERE item_id = ?
        AND booking_type = 'room'
        AND status NOT IN ('Rejected', 'Cancelled')
        AND check_in_date > '0000-00-00'
    ");
    $stmt->execute([$item_id]);
    $bookings = $stmt->fetchAll();

    foreach ($bookings as $booking) {
        $checkin = $booking['check_in_date'];
        $checkout = $booking['check_out_date'];

        // Expand each booking into individual dates
        if (!empty($checkout) && $checkout !== '0000-00-00' && $checkout !== $checkin) {
            // Multi-night: generate all nights spent from check_in up to check_out (exclusive of checkout day)
            $start = new DateTime($checkin);
            $end   = new DateTime($checkout); // DatePeriod end is exclusive, so nights spent are booked
            $period = new DatePeriod($start, new DateInterval('P1D'), $end);
            foreach ($period as $dt) {
                $booked_dates[] = $dt->format('Y-m-d');
            }
        } else {
            // Single-night: only the check-in date is booked
            $booked_dates[] = $checkin;
        }
    }
} else {
    // Events: expand date ranges into individual dates (same logic as rooms)
    $stmt = $pdo->prepare("
        SELECT check_in_date, check_out_date
        FROM reservations
        WHERE item_id = ?
        AND booking_type = 'event'
        AND status NOT IN ('Rejected', 'Cancelled')
        AND check_in_date > '0000-00-00'
    ");
    $stmt->execute([$item_id]);
    $bookings = $stmt->fetchAll();

    foreach ($bookings as $booking) {
        $checkin = $booking['check_in_date'];
        $checkout = $booking['check_out_date'];

        if (!empty($checkout) && $checkout !== '0000-00-00' && $checkout !== $checkin) {
            // Multi-day event: generate all days from check_in to check_out (inclusive)
            $start = new DateTime($checkin);
            $end   = new DateTime($checkout);
            $end->modify('+1 day'); // DatePeriod end is exclusive, so add 1 day to include checkout
            $period = new DatePeriod($start, new DateInterval('P1D'), $end);
            foreach ($period as $dt) {
                $booked_dates[] = $dt->format('Y-m-d');
            }
        } else {
            // Single-day event: only the check-in date is booked
            $booked_dates[] = $checkin;
        }
    }
}

// Deduplicate and sort
$booked_dates = array_values(array_unique(array_filter($booked_dates, fn($d) => $d > '0000-00-00')));
sort($booked_dates);

header('Content-Type: application/json');
echo json_encode($booked_dates);
?>
