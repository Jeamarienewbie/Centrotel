<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];

    // Verify user still exists in the database
    $user_check = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $user_check->execute([$user_id]);
    if (!$user_check->fetch()) {
        session_destroy();
        echo json_encode(['success' => false, 'message' => "Your session has expired. Please log in again.", 'redirect' => '../index.php']);
        exit();
    }

    $type = $_POST['type'];
    $item_id = $_POST['item_id'];
    $check_in = $_POST['check_in'];
    $check_out = $_POST['check_out'] ?? '';

    // ─── GUEST TYPE BREAKDOWN ─────────────────────────────────────────
    // The booking forms submit guest_breakdown[<guest_type_id>] = <count>
    // (e.g. 2 Male, 1 Female, 1 Children). Only known + active types are
    // accepted, and the total replaces the legacy flat `guests` value.
    $guest_types = $pdo->query("SELECT id, type_name FROM guest_types WHERE status = 'active' ORDER BY sort_order ASC, id ASC")->fetchAll();
    $type_map = [];
    foreach ($guest_types as $gt) {
        $type_map[(int)$gt['id']] = $gt['type_name'];
    }

    $breakdown = [];   // guest_type_id => count
    $posted_breakdown = $_POST['guest_breakdown'] ?? [];
    if (is_array($posted_breakdown)) {
        foreach ($posted_breakdown as $tid => $count) {
            $tid = (int)$tid;
            $count = (int)$count;
            if (!isset($type_map[$tid])) continue;   // unknown or deactivated type
            if ($count < 0) $count = 0;
            if ($count > 0) $breakdown[$tid] = $count;
        }
    }

    $guests = array_sum($breakdown);

    if ($guests < 1) {
        if (!empty($type_map)) {
            echo json_encode(['success' => false, 'message' => "Please enter the number of guests for your booking."]);
            exit();
        }
        // No guest types configured — fall back to the legacy flat count
        $guests = (int)($_POST['guests'] ?? 0);
        if ($guests < 1) {
            echo json_encode(['success' => false, 'message' => "Please enter the number of guests for your booking."]);
            exit();
        }
    }

    if ($type === 'room' && $guests > 20) {
        echo json_encode(['success' => false, 'message' => "A room reservation cannot exceed 20 guests. Please contact us for group arrangements."]);
        exit();
    }

    if (empty($check_in)) {
        echo json_encode(['success' => false, 'message' => "Please select a valid check-in date."]);
        exit();
    }

    $nights = 1;
    if ($type === 'room') {
        if (empty($check_out) || strtotime($check_out) <= strtotime($check_in)) {
            $check_out = date('Y-m-d', strtotime($check_in . ' +1 day'));
        }

        $nights = max(1, (int)round((strtotime($check_out) - strtotime($check_in)) / 86400));

        // ─── AVAILABILITY GUARD ─────────────────────────────────────────
        // Check for date overlap: new interval [check_in, check_out) vs existing bookings
        $chk_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM reservations
            WHERE item_id = ?
            AND booking_type = 'room'
            AND status NOT IN ('Rejected', 'Cancelled')
            AND ? < COALESCE(check_out_date, DATE_ADD(check_in_date, INTERVAL 1 DAY))
            AND ? > check_in_date
        ");
        $chk_stmt->execute([$item_id, $check_in, $check_out]);
        $existing_count = $chk_stmt->fetchColumn();

        if ($existing_count >= 1) {
            echo json_encode(['success' => false, 'message' => "Error: This room is already booked for all or part of the selected dates (" . date('M d', strtotime($check_in)) . " to " . date('M d, Y', strtotime($check_out)) . "). Please choose another date range."]);
            exit();
        }
    } else {
        // Event: handle date range
        if (empty($check_out) || strtotime($check_out) < strtotime($check_in)) {
            $check_out = $check_in; // Default to single day if no valid end date
        }

        $event_days = max(1, (int)round((strtotime($check_out) - strtotime($check_in)) / 86400) + 1);

        // Check for date overlap in the event date range
        $chk_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM reservations
            WHERE item_id = ?
            AND booking_type = 'event'
            AND status NOT IN ('Rejected', 'Cancelled')
            AND ? <= COALESCE(check_out_date, check_in_date)
            AND ? >= check_in_date
        ");
        $chk_stmt->execute([$item_id, $check_out, $check_in]);
        if ($chk_stmt->fetchColumn() >= 1) {
            $date_range_text = ($event_days > 1)
                ? date('M d', strtotime($check_in)) . " to " . date('M d, Y', strtotime($check_out))
                : date('M d, Y', strtotime($check_in));
            echo json_encode(['success' => false, 'message' => "Error: This event package is already booked for the selected date(s) ($date_range_text). Please choose another date range."]);
            exit();
        }
    }
    // ─────────────────────────────────────────────────────────────

    if ($type === 'room') {
        $stmt = $pdo->prepare("SELECT price_per_night FROM rooms WHERE id = ?");
        $stmt->execute([$item_id]);
        $base_price = (float)($stmt->fetchColumn() ?: 0);
        
        $price_per_night = $base_price;
        if ($guests >= 6) {
            $extra_guests = $guests - 5;
            $price_per_night += ($extra_guests * 200);
        }
        $total_price = $price_per_night * $nights;

        // ─── ROOM 30% DOWN PAYMENT REQUIREMENT ─────────────────────
        $down_required = (int)ceil($total_price * 0.30);
        $down_payment = (float)($_POST['down_payment'] ?? 0);
        $pay_method = trim($_POST['method'] ?? '');
        $is_arrival = (strtolower($pay_method) === 'pay upon arrival');

        if ($down_payment < $down_required) {
            echo json_encode(['success' => false, 'message' => "A down payment of at least 30% of the total price (₱" . number_format($down_required, 2) . ") is required before your reservation can proceed."]);
            exit();
        }

        // Handle down payment proof upload (required for online payments)
        $proof_dir = '../assets/img/payments/';
        if (!is_dir($proof_dir)) {
            mkdir($proof_dir, 0777, true);
        }
        $proof_path = '';
        if (isset($_FILES['down_payment_proof']) && $_FILES['down_payment_proof']['error'] === UPLOAD_ERR_OK) {
            $filename = 'downpay_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . pathinfo($_FILES['down_payment_proof']['name'], PATHINFO_EXTENSION);
            if (move_uploaded_file($_FILES['down_payment_proof']['tmp_name'], $proof_dir . $filename)) {
                $proof_path = 'assets/img/payments/' . $filename;
            }
        }

        if (!$is_arrival && empty($proof_path)) {
            echo json_encode(['success' => false, 'message' => "Please upload a valid proof of your online down payment."]);
            exit();
        }
        if ($is_arrival && empty($pay_method)) {
            echo json_encode(['success' => false, 'message' => "Please select a payment method."]);
            exit();
        }
    } else {
        $stmt = $pdo->prepare("SELECT base_price, capacity FROM event_packages WHERE id = ?");
        $stmt->execute([$item_id]);
        $pkg = $stmt->fetch() ?: [];
        $base_price = (float)($pkg['base_price'] ?? 0);
        $capacity = (int)($pkg['capacity'] ?? 0);

        if ($capacity > 0 && $guests > $capacity) {
            echo json_encode(['success' => false, 'message' => "The guest count ($guests pax) exceeds the package capacity of $capacity pax."]);
            exit();
        }

        $total_price = $base_price * $event_days;

        // ─── EVENT 30% DOWN PAYMENT REQUIREMENT ─────────────────────
        $down_required = (int)ceil($total_price * 0.30);
        $down_payment = (float)($_POST['down_payment'] ?? 0);
        $pay_method = trim($_POST['method'] ?? '');
        $is_arrival = (strtolower($pay_method) === 'pay upon arrival');

        if ($down_payment < $down_required) {
            echo json_encode(['success' => false, 'message' => "A down payment of at least 30% of the total price (₱" . number_format($down_required, 2) . ") is required before your reservation can proceed."]);
            exit();
        }

        // Handle down payment proof upload (required for online payments)
        $proof_dir = '../assets/img/payments/';
        if (!is_dir($proof_dir)) {
            mkdir($proof_dir, 0777, true);
        }
        $proof_path = '';
        if (isset($_FILES['down_payment_proof']) && $_FILES['down_payment_proof']['error'] === UPLOAD_ERR_OK) {
            $filename = 'downpay_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . pathinfo($_FILES['down_payment_proof']['name'], PATHINFO_EXTENSION);
            if (move_uploaded_file($_FILES['down_payment_proof']['tmp_name'], $proof_dir . $filename)) {
                $proof_path = 'assets/img/payments/' . $filename;
            }
        }

        if (!$is_arrival && empty($proof_path)) {
            echo json_encode(['success' => false, 'message' => "Please upload a valid proof of your online down payment."]);
            exit();
        }
        if ($is_arrival && empty($pay_method)) {
            echo json_encode(['success' => false, 'message' => "Please select a payment method."]);
            exit();
        }
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO reservations (user_id, booking_type, item_id, check_in_date, check_out_date, guest_count, total_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending Approval')");
        $stmt->execute([$user_id, $type, $item_id, $check_in, $check_out, $guests, $total_price]);
        $reservation_id = $pdo->lastInsertId();

        // Store the per-type guest composition (Male / Female / Children ...)
        if (!empty($breakdown)) {
            $gb_stmt = $pdo->prepare("INSERT INTO reservation_guests (reservation_id, guest_type_id, guest_count) VALUES (?, ?, ?)");
            foreach ($breakdown as $tid => $count) {
                $gb_stmt->execute([$reservation_id, $tid, $count]);
            }
        }

        // Record the down payment for reservations
        if ($type === 'room') {
            $record_method = $is_arrival ? 'Pay Upon Arrival' : ($pay_method ?: 'Down Payment');
            $pdo->prepare("INSERT INTO payments (reservation_id, amount, payment_method, proof_of_payment_url, status) VALUES (?, ?, ?, ?, 'Pending')")
                ->execute([$reservation_id, $down_payment, $record_method, $proof_path]);
        } elseif ($type === 'event') {
            $record_method = $is_arrival ? 'Pay Upon Arrival' : ($pay_method ?: 'Down Payment');
            $pdo->prepare("INSERT INTO payments (reservation_id, amount, payment_method, proof_of_payment_url, status) VALUES (?, ?, ?, ?, 'Pending')")
                ->execute([$reservation_id, $down_payment, $record_method, $proof_path]);
        }

        // Unit label so messages name the exact room/package (e.g. "Room 102")
        if ($type == 'room') {
            $unit_stmt = $pdo->prepare("SELECT room_number, room_type FROM rooms WHERE id = ?");
            $unit_stmt->execute([$item_id]);
            $unit_row = $unit_stmt->fetch() ?: [];
            $unit_label = 'Room ' . ($unit_row['room_number'] ?? $item_id);
            $unit_detail = $unit_row['room_type'] ?? '';
        } else {
            $unit_stmt = $pdo->prepare("SELECT package_name FROM event_packages WHERE id = ?");
            $unit_stmt->execute([$item_id]);
            $unit_label = ($unit_stmt->fetchColumn() ?: 'Event Package');
            $unit_detail = '';
        }
        $unit_full = $unit_label . ($unit_detail !== '' ? " ($unit_detail)" : '');

        // Guest composition, e.g. "2 Male, 1 Female"
        $breakdown_txt = '';
        if (!empty($breakdown)) {
            $parts = [];
            foreach ($breakdown as $tid => $count) {
                $parts[] = $count . ' ' . ($type_map[$tid] ?? 'Guest');
            }
            $breakdown_txt = ' — ' . implode(', ', $parts);
        }

        // Add a notification for the user
        if ($type == 'room') {
            $nights_text = $nights > 1 ? " ($nights nights)" : " (1 night)";
            $notif_msg = "Your $unit_full reservation request for " . date('M d, Y', strtotime($check_in)) . " to " . date('M d, Y', strtotime($check_out)) . "$nights_text has been submitted and is pending approval.";
        } else {
            $event_days_text = $event_days > 1 ? " ($event_days days)" : " (1 day)";
            $date_range_text = $event_days > 1
                ? date('M d', strtotime($check_in)) . " to " . date('M d, Y', strtotime($check_out))
                : date('M d, Y', strtotime($check_in));
            $notif_msg = "Your $unit_full event reservation request for " . $date_range_text . "$event_days_text has been submitted and is pending approval.";
        }
        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'info')")->execute([$user_id, $notif_msg]);

        // Notify the admin of the new reservation request
        $admin_stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
        $admin_id = $admin_stmt->fetchColumn();
        if ($admin_id) {
            if ($type == 'room') {
                $admin_msg = "New $unit_full booking request #" . str_pad($reservation_id, 5, '0', STR_PAD_LEFT) . " for " . date('M d, Y', strtotime($check_in)) . "$breakdown_txt. Please review.";
            } else {
                $admin_date_text = $event_days > 1
                    ? date('M d', strtotime($check_in)) . " to " . date('M d, Y', strtotime($check_out))
                    : date('M d, Y', strtotime($check_in));
                $admin_msg = "New $unit_full event booking request #" . str_pad($reservation_id, 5, '0', STR_PAD_LEFT) . " for " . $admin_date_text . "$breakdown_txt. Please review.";
            }
            $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'warning')")->execute([$admin_id, $admin_msg]);
        }

        // Send SMS Notification
        require_once '../config/sms_helper.php';
        $user_stmt = $pdo->prepare("SELECT full_name, phone FROM users WHERE id = ?");
        $user_stmt->execute([$user_id]);
        $user_row = $user_stmt->fetch();
        $phone = $user_row['phone'] ?? null;

        if ($phone) {
            $first_name = trim(explode(' ', $user_row['full_name'] ?? '')[0] ?? '');
            $greet = $first_name !== '' ? "Hi $first_name! " : "Hello! ";
            $ref = str_pad($reservation_id, 5, '0', STR_PAD_LEFT);
            $pay_method_txt = $is_arrival ? 'Pay Upon Arrival' : $pay_method;

            if ($type == 'room') {
                $stay_txt = $nights > 1 ? "$nights nights" : "1 night";
                $date_txt = date('M d', strtotime($check_in)) . '-' . date('M d, Y', strtotime($check_out));
                $sms_msg = "{$greet}Centrotel received $unit_full booking #$ref ($date_txt, $stay_txt, $guests pax$breakdown_txt). Total P" . number_format($total_price, 2)
                    . "; down P" . number_format($down_payment, 2) . " via $pay_method_txt recorded. Pending approval (usually within 24h) — track it in My Reservation.";
            } else {
                $stay_txt = $event_days > 1 ? "$event_days days" : "1 day";
                $date_txt = $event_days > 1
                    ? date('M d', strtotime($check_in)) . " to " . date('M d, Y', strtotime($check_out))
                    : date('M d, Y', strtotime($check_in));
                $sms_msg = "{$greet}Centrotel received $unit_full event booking #$ref ($date_txt, $stay_txt, $guests pax$breakdown_txt). Total P" . number_format($total_price, 2)
                    . "; down P" . number_format($down_payment, 2) . " via $pay_method_txt recorded. Pending approval (usually within 24h) — track it in My Reservation.";
            }
            sendSMS($phone, $sms_msg);
        }

        echo json_encode(['success' => true, 'message' => "Your reservation request has been successfully submitted!"]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => "Error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => "Unauthorized or invalid request."]);
}
