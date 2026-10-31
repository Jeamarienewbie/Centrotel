<?php
session_start();
require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $res_id = $_POST['res_id'] ?? 0;
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['user_role'] ?? 'customer';

    try {
        // If client, ensure they own the reservation
        if ($role !== 'admin') {
            $check = $pdo->prepare("SELECT status FROM reservations WHERE id = ? AND user_id = ?");
            $check->execute([$res_id, $user_id]);
            $res = $check->fetch();
            
            if (!$res) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized or reservation not found.']);
                exit();
            }

            if ($res['status'] === 'Completed' || $res['status'] === 'Cancelled') {
                echo json_encode(['success' => false, 'message' => 'Cannot cancel a completed or already cancelled reservation.']);
                exit();
            }
        }

        // Update status to Cancelled
        $stmt = $pdo->prepare("UPDATE reservations SET status = 'Cancelled' WHERE id = ?");
        $stmt->execute([$res_id]);

        // If admin cancelled it, maybe notify the user (optional but good)
        if ($role === 'admin') {
            $unit_stmt = $pdo->prepare("SELECT r.user_id, r.booking_type, rm.room_number, ep.package_name
                FROM reservations r
                LEFT JOIN rooms rm ON rm.id = r.item_id AND r.booking_type = 'room'
                LEFT JOIN event_packages ep ON ep.id = r.item_id AND r.booking_type = 'event'
                WHERE r.id = ?");
            $unit_stmt->execute([$res_id]);
            $unit_row = $unit_stmt->fetch() ?: [];
            $unit_label = (($unit_row['booking_type'] ?? '') === 'event')
                ? ($unit_row['package_name'] ?: 'Event Package')
                : ('Room ' . ($unit_row['room_number'] ?? '?'));
            if (!empty($unit_row['user_id'])) {
                $notif = $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'danger')");
                $notif->execute([$unit_row['user_id'], "❄️ Your $unit_label booking #" . str_pad($res_id, 5, '0', STR_PAD_LEFT) . " has been CANCELLED by Admin."]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Reservation cancelled successfully.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
}
?>
