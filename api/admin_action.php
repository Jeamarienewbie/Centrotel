<?php
session_start();
require_once '../config/db.php';
require_once '../config/sms_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if (!$id || !$action) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

// Get guest info for SMS
$get_guest = $pdo->prepare("SELECT u.phone, u.full_name FROM users u JOIN reservations r ON r.user_id = u.id WHERE r.id = ?");
$get_guest->execute([$id]);
$guest = $get_guest->fetch();

// Shared booking context so every message names the exact room/package, date and total
$detail_stmt = $pdo->prepare("SELECT r.user_id, r.booking_type, r.total_price, r.check_in_date, r.check_out_date,
        rm.room_number, rm.room_type, ep.package_name
    FROM reservations r
    LEFT JOIN rooms rm ON rm.id = r.item_id AND r.booking_type = 'room'
    LEFT JOIN event_packages ep ON ep.id = r.item_id AND r.booking_type = 'event'
    WHERE r.id = ?");
$detail_stmt->execute([$id]);
$detail = $detail_stmt->fetch() ?: [];
if (empty($detail)) {
    echo json_encode(['success' => false, 'message' => 'Reservation not found.']);
    exit();
}
$sms_ref = str_pad($id, 5, '0', STR_PAD_LEFT);
$sms_type = ucfirst($detail['booking_type'] ?? 'Room');
if (($detail['booking_type'] ?? '') === 'event') {
    $sms_unit = ($detail['package_name'] ?: 'Event Package');
} else {
    $sms_unit = 'Room ' . ($detail['room_number'] ?? '?');
    if (!empty($detail['room_type'])) {
        $sms_unit .= ' (' . $detail['room_type'] . ')';
    }
}
if (!empty($detail['check_in_date'])) {
    if (($detail['booking_type'] ?? '') === 'room' && !empty($detail['check_out_date']) && $detail['check_out_date'] !== $detail['check_in_date']) {
        $sms_date = date('M d', strtotime($detail['check_in_date'])) . '-' . date('M d, Y', strtotime($detail['check_out_date']));
    } else {
        $sms_date = date('M d, Y', strtotime($detail['check_in_date']));
    }
} else {
    $sms_date = '';
}
$sms_total = isset($detail['total_price']) ? number_format((float)$detail['total_price'], 2) : '0.00';

try {
    if ($action === 'approve') {
        // Check down payment requirement for both rooms and events
        $res_row = ['booking_type' => ($detail['booking_type'] ?? null), 'total_price' => ($detail['total_price'] ?? 0)];

        if ($res_row && ($res_row['booking_type'] === 'event' || $res_row['booking_type'] === 'room')) {
            $required = (int)ceil($res_row['total_price'] * 0.30);
            $dp_q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id = ? AND status = 'Paid'");
            $dp_q->execute([$id]);
            $paid = (float)$dp_q->fetchColumn();

            if ($paid < $required) {
                echo json_encode(['success' => false, 'message' => "Cannot approve yet. A confirmed down payment of at least ₱" . number_format($required, 2) . " (30%) is required."]);
                exit();
            }
        }

        $pdo->prepare("UPDATE reservations SET status = 'Approved' WHERE id = ?")->execute([$id]);
        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'success')")
            ->execute([$detail['user_id'], "✅ Your $sms_unit booking #$sms_ref ($sms_date) has been APPROVED! Please settle any remaining balance before check-in."]);

        if ($guest && $guest['phone']) {
            sendSMS($guest['phone'], "Hi " . $guest['full_name'] . "! Good news: your $sms_unit booking #$sms_ref ($sms_date, Total P$sms_total) is APPROVED. Please settle any remaining balance before check-in. Details in My Reservation.");
        }

        echo json_encode(['success' => true, 'message' => "Reservation #$id approved."]);

    } elseif ($action === 'reject') {
        $pdo->prepare("UPDATE reservations SET status = 'Rejected' WHERE id = ?")->execute([$id]);
        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'danger')")
            ->execute([$detail['user_id'], "❌ Your $sms_unit booking #$sms_ref ($sms_date) was not approved. Please book another date or contact us."]);

        if ($guest && $guest['phone']) {
            sendSMS($guest['phone'], "Hi " . $guest['full_name'] . ". Your $sms_unit booking #$sms_ref ($sms_date) was NOT approved (date unavailable or details incomplete). Please book another date. Any down payment stays on record — contact us about it.");
        }

        echo json_encode(['success' => true, 'message' => "Reservation #$id rejected."]);

    } elseif ($action === 'confirm_payment') {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        if (!$payment_id) {
            echo json_encode(['success' => false, 'message' => 'Missing payment_id']);
            exit();
        }

        $pdo->prepare("UPDATE payments SET status = 'Paid' WHERE id = ?")->execute([$payment_id]);

        // Check if fully paid
        $r_row = $pdo->prepare("SELECT total_price FROM reservations WHERE id = ?");
        $r_row->execute([$id]);
        $r_total = (float)($r_row->fetch()['total_price'] ?? 0);
        $sum_q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id = ? AND status = 'Paid'");
        $sum_q->execute([$id]);
        $sum_paid = (float)$sum_q->fetchColumn();

        if ($sum_paid >= $r_total) {
            $pdo->prepare("UPDATE reservations SET status = 'Completed' WHERE id = ?")->execute([$id]);
            $status_msg = "fully paid and marked as Completed";
        } else {
            $pdo->prepare("UPDATE reservations SET status = 'Approved' WHERE id = ?")->execute([$id]);
            $status_msg = "partially paid (₱" . number_format($sum_paid, 2) . " of ₱" . number_format($r_total, 2) . ")";
        }

        $pay_info_stmt = $pdo->prepare("SELECT amount, payment_method FROM payments WHERE id = ?");
        $pay_info_stmt->execute([$payment_id]);
        $pay_info = $pay_info_stmt->fetch() ?: [];
        $conf_amt = number_format((float)($pay_info['amount'] ?? 0), 2);
        $conf_method = $pay_info['payment_method'] ?? 'your payment method';
        if ($sum_paid >= $r_total) {
            $pay_notif = "💰 Payment of P$conf_amt for your $sms_unit booking #$sms_ref CONFIRMED — FULLY PAID. Thank you!";
        } else {
            $pay_notif = "💰 Payment of P$conf_amt for your $sms_unit booking #$sms_ref CONFIRMED. Balance P" . number_format($r_total - $sum_paid, 2) . " still due — see My Reservation.";
        }
        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'success')")
            ->execute([$detail['user_id'], $pay_notif]);

        if ($guest && $guest['phone']) {
            if ($sum_paid >= $r_total) {
                $guest_sms = "Hi " . $guest['full_name'] . "! Payment of P$conf_amt ($conf_method) for $sms_unit booking #$sms_ref CONFIRMED — you are now FULLY PAID (Total P" . number_format($r_total, 2) . "). Just present this SMS at check-in on $sms_date. Thank you!";
                sendSMS($guest['phone'], $guest_sms);
                // Automatic post-booking survey SMS (standalone message)
                sendSurveySms($pdo, $id, $guest['phone'], $guest['full_name'], $sms_unit, $sms_ref);
            } else {
                $guest_sms = "Hi " . $guest['full_name'] . "! Payment of P$conf_amt for $sms_unit booking #$sms_ref CONFIRMED. Paid P" . number_format($sum_paid, 2) . " of P" . number_format($r_total, 2) . "; balance P" . number_format($r_total - $sum_paid, 2) . " due on or before $sms_date. Details in My Reservation.";
                sendSMS($guest['phone'], $guest_sms);
            }
        }

        echo json_encode(['success' => true, 'message' => "Payment #$payment_id confirmed. Reservation #$id $status_msg."]);

    } elseif ($action === 'reject_payment') {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        if (!$payment_id) {
            echo json_encode(['success' => false, 'message' => 'Missing payment_id']);
            exit();
        }

        $pdo->prepare("UPDATE payments SET status = 'Refunded' WHERE id = ?")->execute([$payment_id]);
        $pdo->prepare("UPDATE reservations SET status = 'Approved' WHERE id = ?")->execute([$id]);

        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'warning')")
            ->execute([$detail['user_id'], "⚠️ Payment proof for your $sms_unit booking #$sms_ref was rejected. Please upload a clear screenshot."]);

        echo json_encode(['success' => true, 'message' => "Payment #$payment_id rejected."]);

    } elseif ($action === 'cancel') {
        $pdo->prepare("UPDATE reservations SET status = 'Cancelled' WHERE id = ?")->execute([$id]);
        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'danger')")
            ->execute([$detail['user_id'], "🚫 Your $sms_unit booking #$sms_ref has been CANCELLED by management."]);

        if ($guest && $guest['phone']) {
            sendSMS($guest['phone'], "Hi " . $guest['full_name'] . ". Your $sms_unit booking #$sms_ref ($sms_date, Total P$sms_total) has been CANCELLED by management. If you paid a down payment, please contact us about it. We apologize for the inconvenience.");
        }

        echo json_encode(['success' => true, 'message' => "Reservation #$id cancelled."]);

    } elseif ($action === 'send_survey') {
        // Manual survey send: only for Completed bookings with no review yet
        $st = $pdo->prepare("SELECT status FROM reservations WHERE id = ?");
        $st->execute([$id]);
        if (($st->fetchColumn() ?: '') !== 'Completed') {
            echo json_encode(['success' => false, 'message' => 'Surveys can only be sent for Completed bookings.']);
            exit();
        }
        $rv = $pdo->prepare("SELECT id FROM reviews WHERE reservation_id = ?");
        $rv->execute([$id]);
        if ($rv->fetch()) {
            echo json_encode(['success' => false, 'message' => 'This guest already submitted a review.']);
            exit();
        }
        if (!$guest || empty($guest['phone'])) {
            echo json_encode(['success' => false, 'message' => 'Guest has no phone number on record.']);
            exit();
        }

        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'info')")
            ->execute([$detail['user_id'], "⭐ We'd love your feedback on $sms_unit (booking #$sms_ref)! Use the survey link we texted you, or tap Rate in My Reservation."]);

        if (sendSurveySms($pdo, $id, $guest['phone'], $guest['full_name'], $sms_unit, $sms_ref)) {
            echo json_encode(['success' => true, 'message' => "Survey SMS sent to {$guest['full_name']} for booking #$sms_ref."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Could not send survey SMS (no phone or link unavailable). Notification bell message was still recorded.']);
        }

    } else {
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
