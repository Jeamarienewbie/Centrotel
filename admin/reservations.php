<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    $success = false;

    // Helper for SMS in this file
    require_once '../config/sms_helper.php';
    $get_guest = $pdo->prepare("SELECT u.phone, u.full_name FROM users u JOIN reservations r ON r.user_id = u.id WHERE r.id = ?");
    $get_guest->execute([$id]);
    $guest = $get_guest->fetch();

    // Shared booking context so every SMS names the exact room/package, date and total
    $detail_stmt = $pdo->prepare("SELECT r.user_id, r.booking_type, r.total_price, r.check_in_date, r.check_out_date, r.guest_count,
            rm.room_number, rm.room_type, ep.package_name
        FROM reservations r
        LEFT JOIN rooms rm ON rm.id = r.item_id AND r.booking_type = 'room'
        LEFT JOIN event_packages ep ON ep.id = r.item_id AND r.booking_type = 'event'
        WHERE r.id = ?");
    $detail_stmt->execute([$id]);
    $detail = $detail_stmt->fetch() ?: [];
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

    if (empty($detail)) {
        header('Location: reservations.php');
        exit();
    }

    if ($action === 'approve') {
        // Require a confirmed down payment of at least 30% before approval (rooms and events)
        $res_row = ['booking_type' => ($detail['booking_type'] ?? null), 'total_price' => ($detail['total_price'] ?? 0)];

        if ($res_row && ($res_row['booking_type'] === 'event' || $res_row['booking_type'] === 'room')) {
            $required = (int)ceil($res_row['total_price'] * 0.30);
            $dp_q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id = ? AND status = 'Paid'");
            $dp_q->execute([$id]);
            $paid = (float)$dp_q->fetchColumn();

            if ($paid < $required) {
                $type_label = ucfirst($res_row['booking_type']);
                $_SESSION['msg'] = "Cannot approve $type_label reservation #$id yet. A confirmed down payment of at least ₱" . number_format($required, 2) . " (30%) is required."; $_SESSION['msg_type'] = 'warning';
                header('Location: reservations.php');
                exit();
            }
        }

        $pdo->prepare("UPDATE reservations SET status = 'Approved' WHERE id = ?")->execute([$id]);
        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'success')")
            ->execute([$detail['user_id'], "✅ Your $sms_unit booking #$sms_ref ($sms_date) has been APPROVED! Please settle any remaining balance before check-in."]);

        if ($guest && $guest['phone']) {
            $sms = "Hi " . $guest['full_name'] . "! Good news: your $sms_unit booking #$sms_ref ($sms_date, Total P$sms_total) is APPROVED. Please settle any remaining balance before check-in. Details in My Reservation.";
            sendSMS($guest['phone'], $sms);
        }

        $_SESSION['msg'] = "Reservation #$id approved."; $_SESSION['msg_type'] = 'success';
        $success = true;

    } elseif ($action === 'reject') {
        $pdo->prepare("UPDATE reservations SET status = 'Rejected' WHERE id = ?")->execute([$id]);
        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'danger')")
            ->execute([$detail['user_id'], "❌ Your $sms_unit booking #$sms_ref ($sms_date) was not approved. Please book another date or contact us."]);

        if ($guest && $guest['phone']) {
            $sms = "Hi " . $guest['full_name'] . ". Your $sms_unit booking #$sms_ref ($sms_date) was NOT approved (date unavailable or details incomplete). Please book another date. Any down payment stays on record — contact us about it.";
            sendSMS($guest['phone'], $sms);
        }

        $_SESSION['msg'] = "Reservation #$id rejected."; $_SESSION['msg_type'] = 'warning';
        $success = true;

    } elseif ($action === 'confirm_payment') {
        $payment_id = (int)$_GET['payment_id'];
        $pdo->prepare("UPDATE payments SET status = 'Paid' WHERE id = ?")->execute([$payment_id]);

        // Determine reservation status based on how much has been fully paid
        $r_row = $pdo->prepare("SELECT total_price FROM reservations WHERE id = ?");
        $r_row->execute([$id]);
        $r_total = (float)($r_row->fetch()['total_price'] ?? 0);
        $sum_q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE reservation_id = ? AND status = 'Paid'");
        $sum_q->execute([$id]);
        $sum_paid = (float)$sum_q->fetchColumn();

        if ($sum_paid >= $r_total) {
            $pdo->prepare("UPDATE reservations SET status = 'Completed' WHERE id = ?")->execute([$id]);
            $new_status = 'COMPLETED';
        } else {
            $pdo->prepare("UPDATE reservations SET status = 'Approved' WHERE id = ?")->execute([$id]);
            $new_status = 'APPROVED (partial payment)';
        }
        $pay_row = $pdo->prepare("SELECT amount, payment_method FROM payments WHERE id = ?");
        $pay_row->execute([$payment_id]);
        $pay_info = $pay_row->fetch() ?: [];
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
                $sms = "Hi " . $guest['full_name'] . "! Payment of P$conf_amt ($conf_method) for $sms_unit booking #$sms_ref CONFIRMED — you are now FULLY PAID (Total P" . number_format($r_total, 2) . "). Just present this SMS at check-in on $sms_date. Thank you!";
                sendSMS($guest['phone'], $sms);
                // Automatic post-booking survey SMS (standalone message)
                sendSurveySms($pdo, $id, $guest['phone'], $guest['full_name'], $sms_unit, $sms_ref);
            } else {
                $balance = number_format($r_total - $sum_paid, 2);
                $sms = "Hi " . $guest['full_name'] . "! Payment of P$conf_amt for $sms_unit booking #$sms_ref CONFIRMED. Paid P" . number_format($sum_paid, 2) . " of P" . number_format($r_total, 2) . "; balance P$balance due on or before $sms_date. Details in My Reservation.";
                sendSMS($guest['phone'], $sms);
            }
        }

        $_SESSION['msg'] = "Payment confirmed. Reservation #$id status: $new_status."; $_SESSION['msg_type'] = 'success';
        $success = true;

    } elseif ($action === 'reject_payment') {
        $payment_id = (int)$_GET['payment_id'];
        $pdo->prepare("UPDATE payments SET status = 'Refunded' WHERE id = ?")->execute([$payment_id]);
        $pdo->prepare("UPDATE reservations SET status = 'Approved' WHERE id = ?")->execute([$id]);
        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'warning')")
            ->execute([$detail['user_id'], "⚠️ Payment proof for your $sms_unit booking #$sms_ref was rejected. Please re-upload a clear screenshot."]);

        if ($guest && $guest['phone']) {
            $sms = "Hi " . $guest['full_name'] . ". Your payment proof for $sms_unit booking #$sms_ref was REJECTED (blurry or invalid receipt). Please re-upload a clear screenshot via My Reservation > Upload Payment. Your booking stays reserved meanwhile.";
            sendSMS($guest['phone'], $sms);
        }

        $_SESSION['msg'] = "Payment proof rejected."; $_SESSION['msg_type'] = 'warning';
        $success = true;

    } elseif ($action === 'cancel') {
        $pdo->prepare("UPDATE reservations SET status = 'Cancelled' WHERE id = ?")->execute([$id]);
        $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'danger')")
            ->execute([$detail['user_id'], "❄️ Your $sms_unit booking #$sms_ref has been CANCELLED by Admin."]);

        if ($guest && $guest['phone']) {
            $sms = "Hi " . $guest['full_name'] . ". Your $sms_unit booking #$sms_ref ($sms_date, Total P$sms_total) has been CANCELLED by management. If you paid a down payment, please contact us about it. We apologize for the inconvenience.";
            sendSMS($guest['phone'], $sms);
        }

        $_SESSION['msg'] = "Reservation #$id has been cancelled."; $_SESSION['msg_type'] = 'danger';
        $success = true;
    }

    if ($success) {
        header('Location: reservations.php');
        exit();
    }
}

// Check for session messages
$msg = null;
$msg_type = null;

if (isset($_SESSION['msg'])) {
    $msg = $_SESSION['msg'];
    $msg_type = $_SESSION['msg_type'];
    unset($_SESSION['msg'], $_SESSION['msg_type']);
}

// Handle Custom Message POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $u_id  = (int)$_POST['target_user_id'];
    $m_text = trim($_POST['message_text']);
    $r_id  = $_POST['target_res_id'];
    if (!empty($m_text)) {
        // Unit label for this booking (e.g. "Room 102")
        $msg_unit_stmt = $pdo->prepare("SELECT r.booking_type, rm.room_number, ep.package_name
            FROM reservations r
            LEFT JOIN rooms rm ON rm.id = r.item_id AND r.booking_type = 'room'
            LEFT JOIN event_packages ep ON ep.id = r.item_id AND r.booking_type = 'event'
            WHERE r.id = ?");
        $msg_unit_stmt->execute([$r_id]);
        $msg_unit_row = $msg_unit_stmt->fetch() ?: [];
        $msg_unit = (($msg_unit_row['booking_type'] ?? '') === 'event')
            ? ($msg_unit_row['package_name'] ?: 'Event Package')
            : ('Room ' . ($msg_unit_row['room_number'] ?? '?'));

        // Notification in DB
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'info')");
        $stmt->execute([$u_id, "💬 Message from Centrotel (Re: $msg_unit booking #$r_id): " . $m_text]);

        // SMS Notification
        require_once '../config/sms_helper.php';
        $u_stmt = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
        $u_stmt->execute([$u_id]);
        $u_phone = $u_stmt->fetchColumn();
        if ($u_phone) {
            $sms_msg = "Centrotel re $msg_unit booking #" . str_pad($r_id, 5, '0', STR_PAD_LEFT) . ": " . $m_text;
            sendSMS($u_phone, $sms_msg);
        }

        $msg = "Message sent to guest successfully via System & SMS!";
        $msg_type = 'success';
    }
}

// ─── Create notifications table if not exists ─────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    type ENUM('success','warning','danger','info') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// ─── Fetch Reservations with Payment Info ─────────────────────────
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

// ─── Guest composition per reservation (Male / Female / Children ...) ──
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

// ─── Stats ────────────────────────────────────────────────────────
$total_revenue   = $pdo->query("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.status = 'Paid'")->fetchColumn();
$pending_count   = $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'Pending Approval'")->fetchColumn();
$payment_pending = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'Pending'")->fetchColumn();
$completed       = $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'Completed'")->fetchColumn();

// ─── Notifications for real-time bell ──────────────────────────────
$notifications = $pdo->query("
    SELECT n.* FROM notifications n
    WHERE n.user_id = {$_SESSION['user_id']}
    ORDER BY n.created_at DESC LIMIT 20
")->fetchAll();
$unread_count = $pdo->query("
    SELECT COUNT(*) FROM notifications
    WHERE user_id = {$_SESSION['user_id']} AND is_read = 0
")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bookings &amp; Payments | Centrotel Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root { 
            --primary: #2e003e; 
            --accent: #9c27b0;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --bg-card: rgba(255, 255, 255, 0.95);
        }
        body { background: #f4f6f9; font-family: 'Inter', sans-serif; }
        .sidebar { width: 280px; height: 100vh; position: fixed; background: var(--primary); color: white; padding: 20px; overflow-y: auto; z-index: 1000; }
        .main-content { margin-left: 280px; padding: 40px; }
        .nav-link-admin { color: rgba(255,255,255,0.7); padding: 12px 15px; border-radius: 12px; margin-bottom: 5px; display: block; text-decoration: none; transition: 0.3s; }
        .nav-link-admin:hover, .nav-link-admin.active { background: rgba(255,255,255,0.15); color: white; transform: translateX(5px); }
        
        .stat-card { border: none; border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); transition: 0.3s; background: white; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.1); }

        /* Table Styling */
        .table-card { border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.08); background: white; }
        .table thead th { background: #f8fafc; color: #64748b; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px; padding: 1.5rem 1rem; border-bottom: 1px solid #e2e8f0; }
        .table tbody td { padding: 1.25rem 1rem; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
        
        tbody tr.clickable-row { cursor: pointer; transition: 0.2s ease; }
        tbody tr.clickable-row:hover { background-color: #f8fafc !important; transform: scale(1.002); }

        /* Status Badges */
        .status-badge { padding: 6px 12px; border-radius: 30px; font-weight: 600; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 6px; }
        .status-pending { background: #fff7ed; color: #c2410c; }
        .status-approved { background: #f0fdf4; color: #15803d; }
        .status-rejected { background: #fef2f2; color: #b91c1c; }
        .status-completed { background: #eff6ff; color: #1d4ed8; }
        .status-cancelled { background: #f9fafb; color: #374151; }

        /* Premium Modal */
        .modal-blur { backdrop-filter: blur(8px); background: rgba(46, 0, 62, 0.4); }
        .modal-premium .modal-content { border-radius: 24px; border: none; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        .modal-premium .modal-header { background: linear-gradient(135deg, #2e003e 0%, #4a0063 100%); color: white; padding: 2rem; border: none; }
        .modal-premium .modal-body { padding: 2.5rem; background: #fff; }
        .modal-premium .modal-footer { padding: 1.5rem 2.5rem; background: #f8fafc; border-top: 1px solid #e2e8f0; }

        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 2rem; }
        .info-group { margin-bottom: 1.5rem; }
        .info-label { display: block; font-size: 0.7rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.5rem; }
        .info-value { display: block; font-size: 1rem; font-weight: 600; color: #1e293b; }
        .info-icon { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #f1f5f9; color: var(--primary); font-size: 1.2rem; margin-bottom: 1rem; }

        .action-bar { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-action { padding: 10px 20px; border-radius: 14px; font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; border: none; transition: 0.3s; }
        .btn-action:hover { transform: translateY(-2px); }
        
        .btn-approve { background: #10b981; color: white; }
        .btn-reject { background: #ef4444; color: white; }
        .btn-message { background: #3b82f6; color: white; }
        .btn-cancel { background: #64748b; color: white; }
        .btn-confirm { background: #8b5cf6; color: white; }

        /* Icon-only Buttons with Tooltips */
        .action-btn {
            width: 42px; height: 42px;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 10px; border: none;
            font-size: 0.9rem; cursor: pointer; transition: 0.2s;
            background: #f1f5f9; color: #475569;
            position: relative;
            z-index: 10;
        }
        .action-btn:hover { background: var(--primary); color: white; transform: translateY(-3px); }
        .action-btn-approve:hover { background: var(--success); color: white; }
        .action-btn-reject:hover  { background: var(--danger); color: white; }
        .action-btn-confirm:hover { background: var(--info); color: white; }
        .action-btn-cancel:hover  { background: var(--warning); color: white; }
        .action-btn-msg:hover     { background: var(--accent); color: white; }

        /* Tooltip Bubble */
        .action-btn[data-tip]::before {
            content: attr(data-tip);
            position: absolute;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%) translateY(10px);
            background: #1e293b;
            color: white;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: 0.2s ease;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            z-index: 100;
            pointer-events: none;
        }
        .action-btn[data-tip]::after {
            content: '';
            position: absolute;
            bottom: 110%;
            left: 50%;
            transform: translateX(-50%) translateY(10px);
            border: 6px solid transparent;
            border-top-color: #1e293b;
            opacity: 0;
            visibility: hidden;
            transition: 0.2s ease;
            z-index: 100;
            pointer-events: none;
        }
        .action-btn[data-tip]:hover::before,
        .action-btn[data-tip]:hover::after {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0);
        }

        .proof-container { border-radius: 16px; overflow: hidden; border: 1px solid #e2e8f0; margin-top: 1.5rem; position: relative; }
        .proof-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; opacity: 0; transition: 0.3s; cursor: pointer; }
        .proof-container:hover .proof-overlay { opacity: 1; }
        .proof-img-modal { width: 100%; height: auto; display: block; }
        .proof-thumb { width: 36px; height: 36px; object-fit: cover; border-radius: 8px; cursor: pointer; border: 2px solid #e2e8f0; transition: 0.2s; }
        .proof-thumb:hover { border-color: var(--primary); transform: scale(1.1); }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
    </style>
</head>
<body>
        <div class="sidebar" id="sidebar">
            <div class="d-flex align-items-center mb-5 px-3">
                <img src="../assets/img/logo.jpg" alt="Logo" height="40" class="me-2">
                <span class="fs-5 fw-bold">Admin Panel</span>
            </div>
            <nav>
                <a href="dashboard.php" class="nav-link-admin"><i class="fas fa-chart-line me-2"></i> Dashboard</a>
                <a href="rooms.php" class="nav-link-admin"><i class="fas fa-bed me-2"></i> Manage Rooms</a>
                <a href="packages.php" class="nav-link-admin"><i class="fas fa-box me-2"></i> Event Packages</a>
                <a href="reservations.php" class="nav-link-admin active"><i class="fas fa-calendar-alt me-2"></i> Bookings</a>
                <a href="reviews.php" class="nav-link-admin"><i class="fas fa-star me-2"></i> Reviews</a>
                <a href="users.php" class="nav-link-admin"><i class="fas fa-users me-2"></i> Customers</a>
                <a href="guest_types.php" class="nav-link-admin"><i class="fas fa-venus-mars me-2"></i> Guest Types</a>
                <a href="reports.php" class="nav-link-admin"><i class="fas fa-file-invoice-dollar me-2"></i> Financial Reports</a>
                <a href="payment_settings.php" class="nav-link-admin"><i class="fas fa-credit-card me-2"></i> Payment Settings</a>
                <div class="mt-5 pt-5">
                    <a href="../auth/logout.php" class="nav-link-admin text-danger" onclick="return confirm('Are you sure you want to logout?')"><i class="fas fa-sign-out-alt me-2"></i> Logout</a>
                </div>
            </nav>
        </div>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <div class="main-content">
            <button class="btn btn-premium sidebar-toggle d-none mb-3" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
        <h2 class="fw-bold mb-1">Bookings &amp; Payments</h2>
        <p class="text-muted mb-4">Review reservations, approve bookings, and confirm client payments.</p>

        <!-- Stats Row -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-md-3">
                <div class="stat-card card p-4">
                    <div class="small text-muted mb-1">Total Revenue</div>
                    <div class="h4 fw-bold text-success mb-0 text-break">₱<?php echo number_format($total_revenue, 2); ?></div>
                    <div class="small text-muted mt-1">from confirmed payments</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="stat-card card p-4">
                    <div class="small text-muted mb-1">Pending Approval</div>
                    <div class="h4 fw-bold text-warning mb-0"><?php echo $pending_count; ?></div>
                    <div class="small text-muted mt-1">reservations to review</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="stat-card card p-4">
                    <div class="small text-muted mb-1">Payments to Confirm</div>
                    <div class="h4 fw-bold text-primary mb-0"><?php echo $payment_pending; ?></div>
                    <div class="small text-muted mt-1">proofs awaiting review</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="stat-card card p-4">
                    <div class="small text-muted mb-1">Completed</div>
                    <div class="h4 fw-bold text-success mb-0"><?php echo $completed; ?></div>
                    <div class="small text-muted mt-1">fully paid bookings</div>
                </div>
            </div>
        </div>

        <?php if ($msg): ?>
        <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show">
            <?php echo $msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Reservations Table -->
        <div class="table-card animate-up">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Booking ID</th>
                                <th>Guest</th>
                                <th>Room / Event</th>
                                <th>Check-in</th>
                                <th>Total</th>
                                <th>Booking Status</th>
                                <th>Payment</th>
                                <th class="text-center pe-4">Actions<div class="text-muted fw-normal" style="font-size:0.65rem;letter-spacing:0;text-transform:none;">click row for details</div></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($reservations as $res):
                            $booking_label = ($res['booking_type'] === 'room')
                                ? 'Room ' . ($res['room_number'] ?? '?')
                                : ($res['package_name'] ?? 'Event');
                            $status_color = match($res['status']) {
                                'Approved'         => 'success',
                                'Pending Approval' => 'warning',
                                'Rejected'         => 'danger',
                                'Completed'        => 'primary',
                                default            => 'secondary'
                            };
                            $pay_color = match($res['payment_status'] ?? '') {
                                'Paid'     => 'success',
                                'Pending'  => 'warning',
                                'Refunded' => 'danger',
                                default    => 'secondary'
                            };

                            // Build JSON data for the detail modal
                            $proof_url   = !empty($res['proof_of_payment_url']) ? '../' . htmlspecialchars($res['proof_of_payment_url']) : '';
                            $detail_data = json_encode([
                                'id'            => str_pad($res['id'], 5, '0', STR_PAD_LEFT),
                                'raw_id'        => $res['id'],
                                'full_name'     => $res['full_name'],
                                'email'         => $res['email'],
                                'phone'         => $res['phone'],
                                'booking_type'  => ucfirst($res['booking_type']),
                                'booking_label' => $booking_label,
                                'room_type'     => $res['room_type'] ?? '—',
                                'guest_count'   => (int)$res['guest_count'],
                                'guest_breakdown' => $breakdown_map[(int)$res['id']] ?? [],
                                'check_in'      => date('M d, Y', strtotime($res['check_in_date'])),
                                'check_out'     => !empty($res['check_out_date']) ? date('M d, Y', strtotime($res['check_out_date'])) : '—',
                                'total'         => number_format($res['total_price'], 2),
                                'status'        => $res['status'],
                                'status_color'  => $status_color,
                                'pay_status'    => $res['payment_status'] ?? 'None',
                                'pay_color'     => $pay_color,
                                'pay_method'    => $res['payment_method'] ?? '—',
                                'pay_amount'    => !empty($res['payment_amount']) ? number_format($res['payment_amount'], 2) : '—',
                                'pay_date'      => !empty($res['transaction_date']) ? date('M d, Y', strtotime($res['transaction_date'])) : '—',
                                'proof_url'     => $proof_url,
                                'created_at'    => date('M d, Y h:i A', strtotime($res['created_at'])),
                                'payment_id'    => $res['payment_id'],
                                'user_id'       => $res['user_id'],
                                'review_id'     => $res['review_id'] ?? null,
                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                        ?>
                        <tr class="clickable-row" onclick='openDetailModal(<?php echo $detail_data; ?>)'>
                            <td class="ps-4 fw-bold small">#<?php echo str_pad($res['id'], 5, '0', STR_PAD_LEFT); ?></td>
                            <td>
                                <div class="fw-semibold"><?php echo htmlspecialchars($res['full_name']); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($res['phone']); ?></div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($booking_label); ?></span></td>
                            <td class="small"><?php echo date('M d, Y', strtotime($res['check_in_date'])); ?></td>
                            <td class="fw-bold">₱<?php echo number_format($res['total_price'], 2); ?></td>
                            <td><span class="badge bg-<?php echo $status_color; ?>"><?php echo $res['status']; ?></span></td>
                            <td>
                                <?php if ($res['payment_id']): ?>
                                    <div><span class="badge bg-<?php echo $pay_color; ?>"><?php echo $res['payment_status']; ?></span>
                                    <?php if ($res['payment_status'] === 'Pending' && $res['status'] === 'Pending Approval'): ?>
                                        <span class="badge bg-danger rounded-pill ms-1" style="font-size:0.55rem; animation: pulse 1.5s infinite;">NEEDS CONFIRM</span>
                                    <?php endif; ?>
                                    </div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($res['payment_method'] ?? ''); ?></div>
                                <?php else: ?>
                                    <span class="small text-muted">No payment yet</span>
                                <?php endif; ?>
                            </td>
                            <!-- ── Icon-only Actions ───────────────────────── -->
                            <td class="text-center pe-4">
                                <div class="d-flex gap-1 justify-content-center align-items-center">

                                    <?php if ($res['status'] === 'Pending Approval'): ?>
                                        <!-- Confirm Down Payment (must confirm payment BEFORE approval) -->
                                        <?php if ($res['payment_id'] && $res['payment_status'] === 'Pending'): ?>
                                        <button class="action-btn action-btn-confirm" data-tip="Confirm Down Payment"
                                                onclick="event.stopPropagation(); triggerConfirm('confirm_payment','Confirm Down Payment','Confirm the down payment for booking #<?php echo str_pad($res['id'],5,'0',STR_PAD_LEFT); ?>? This is required before approval.','info','fa-check-double','?action=confirm_payment&id=<?php echo $res['id']; ?>&payment_id=<?php echo $res['payment_id']; ?>')">
                                            <i class="fas fa-check-double"></i>
                                        </button>
                                        <button class="action-btn action-btn-reject" data-tip="Reject Proof"
                                                onclick="event.stopPropagation(); triggerConfirm('reject_payment','Reject Payment Proof','Reject the payment proof for booking #<?php echo str_pad($res['id'],5,'0',STR_PAD_LEFT); ?>?','danger','fa-ban','?action=reject_payment&id=<?php echo $res['id']; ?>&payment_id=<?php echo $res['payment_id']; ?>')">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                        <?php endif; ?>
                                        <!-- Approve (only works if down payment is confirmed as Paid) -->
                                        <button class="action-btn action-btn-approve" data-tip="Approve"
                                                onclick="event.stopPropagation(); triggerConfirm('approve','Approve Reservation','Are you sure you want to approve booking #<?php echo str_pad($res['id'],5,'0',STR_PAD_LEFT); ?>?','success','fa-check-circle','?action=approve&id=<?php echo $res['id']; ?>')">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <!-- Reject -->
                                        <button class="action-btn action-btn-reject" data-tip="Reject"
                                                onclick="event.stopPropagation(); triggerConfirm('reject','Reject Reservation','Are you sure you want to reject booking #<?php echo str_pad($res['id'],5,'0',STR_PAD_LEFT); ?>?','danger','fa-times-circle','?action=reject&id=<?php echo $res['id']; ?>')">
                                            <i class="fas fa-times"></i>
                                        </button>

                                    <?php elseif ($res['status'] === 'Approved' && $res['payment_id'] && $res['payment_status'] === 'Pending'): ?>
                                        <!-- Confirm Payment -->
                                        <button class="action-btn action-btn-confirm" data-tip="Confirm Payment"
                                                onclick="event.stopPropagation(); triggerConfirm('confirm_payment','Confirm Payment','Confirm payment and mark booking #<?php echo str_pad($res['id'],5,'0',STR_PAD_LEFT); ?> as Completed?','info','fa-check-double','?action=confirm_payment&id=<?php echo $res['id']; ?>&payment_id=<?php echo $res['payment_id']; ?>')">
                                            <i class="fas fa-check-double"></i>
                                        </button>
                                        <!-- Reject Proof -->
                                        <button class="action-btn action-btn-reject" data-tip="Reject Proof"
                                                onclick="event.stopPropagation(); triggerConfirm('reject_payment','Reject Payment Proof','Reject the payment proof for booking #<?php echo str_pad($res['id'],5,'0',STR_PAD_LEFT); ?>?','danger','fa-ban','?action=reject_payment&id=<?php echo $res['id']; ?>&payment_id=<?php echo $res['payment_id']; ?>')">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    <?php endif; ?>

                                    <?php if (!in_array($res['status'], ['Cancelled', 'Completed', 'Rejected'])): ?>
                                        <!-- Cancel -->
                                        <button class="action-btn action-btn-cancel" data-tip="Cancel Booking"
                                                onclick="event.stopPropagation(); triggerConfirm('cancel','Cancel Booking','Are you sure you want to cancel booking #<?php echo str_pad($res['id'],5,'0',STR_PAD_LEFT); ?>? This cannot be undone.','warning','fa-exclamation-triangle','?action=cancel&id=<?php echo $res['id']; ?>')">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($res['status'] === 'Completed' && empty($res['review_id'])): ?>
                                    <!-- Manually send the rating survey SMS -->
                                    <button class="action-btn action-btn-msg" data-tip="Send Survey SMS"
                                            onclick="event.stopPropagation(); triggerConfirm('send_survey','Send Feedback Survey','Send the rating survey SMS to <?php echo htmlspecialchars($res['full_name'], ENT_QUOTES); ?> for booking #<?php echo str_pad($res['id'],5,'0',STR_PAD_LEFT); ?>?','info','fa-star','?action=send_survey&id=<?php echo $res['id']; ?>')">
                                        <i class="fas fa-star"></i>
                                    </button>
                                    <?php endif; ?>

                                    <!-- Message Guest -->
                                    <button class="action-btn action-btn-msg" data-tip="Message Guest"
                                            onclick="event.stopPropagation(); openMessageModal(<?php echo $res['user_id']; ?>, '<?php echo str_pad($res['id'],5,'0',STR_PAD_LEFT); ?>', '<?php echo htmlspecialchars($res['full_name'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-comment-alt"></i>
                                    </button>

                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Premium Detail Modal ──────────────────────────────────────── -->
    <div class="modal fade modal-premium modal-blur" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="d-flex align-items-center gap-3">
                        <div class="info-icon" style="background: rgba(255,255,255,0.2); color: white; margin-bottom: 0;">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div>
                            <h4 class="modal-title fw-bold mb-0" id="detailBookingIdDisp">Booking Details</h4>
                            <span class="small opacity-75" id="detailCreatedDisp"></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 g-md-4">
                        <!-- Guest Info -->
                        <div class="col-12 col-md-6">
                            <div class="info-group">
                                <div class="info-icon"><i class="fas fa-user"></i></div>
                                <span class="info-label">Guest Information</span>
                                <div class="mb-2">
                                    <span class="info-label">Full Name</span>
                                    <span class="info-value" id="detailName"></span>
                                </div>
                                <div class="mb-2">
                                    <span class="info-label">Contact Details</span>
                                    <span class="info-value"><i class="fas fa-envelope me-2 text-muted fw-normal" style="font-size:0.8rem;"></i> <span id="detailEmail"></span></span>
                                    <span class="info-value"><i class="fas fa-phone me-2 text-muted fw-normal" style="font-size:0.8rem;"></i> <span id="detailPhone"></span></span>
                                </div>
                            </div>
                        </div>

                        <!-- Booking Info -->
                        <div class="col-12 col-md-6">
                            <div class="info-group">
                                <div class="info-icon"><i class="fas fa-calendar-check"></i></div>
                                <span class="info-label">Stay Details</span>
                                <div class="mb-2">
                                    <span class="info-label">Unit / Package</span>
                                    <span class="info-value text-primary" id="detailRoom"></span>
                                </div>
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <span class="info-label">Check-in</span>
                                        <span class="info-value" id="detailCheckIn"></span>
                                    </div>
                                    <div class="col-6">
                                        <span class="info-label">Check-out</span>
                                        <span class="info-value" id="detailCheckOut"></span>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <span class="info-label">Guest Count</span>
                                    <span class="info-value" id="detailGuestCount">—</span>
                                </div>
                                <div class="mb-2">
                                    <span class="info-label">Guest Composition</span>
                                    <span class="info-value" id="detailGuestBreakdown">—</span>
                                </div>
                            </div>
                        </div>

                        <!-- Status & Payment -->
                        <div class="col-12">
                            <hr class="my-4 opacity-5">
                            <div class="row g-3">
                                <div class="col-6 col-md-4">
                                    <span class="info-label mb-3">Booking Status</span>
                                    <div id="detailStatusBadge"></div>
                                </div>
                                <div class="col-6 col-md-4">
                                    <span class="info-label mb-3">Payment Status</span>
                                    <div id="detailPayStatusBadge"></div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="info-label">Total Amount</span>
                                    <span class="info-value fs-4 text-success text-break" id="detailTotal"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Evidence -->
                        <div class="col-12" id="proofSection">
                            <hr class="my-4 opacity-5">
                            <span class="info-label mb-3">Proof of Payment</span>
                            <div class="row g-3 align-items-center">
                                <div class="col-12 col-md-7">
                                    <div class="proof-container">
                                        <img id="detailProofImg" src="" class="proof-img-modal" alt="Proof">
                                        <div class="proof-overlay" onclick="window.open(document.getElementById('detailProofImg').src,'_blank')">
                                            <span class="text-white fw-bold"><i class="fas fa-expand me-2"></i> View Full Image</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-5">
                                    <div class="p-3 bg-light rounded-4">
                                        <div class="mb-3">
                                            <span class="info-label">Method</span>
                                            <span class="info-value" id="detailPayMethod"></span>
                                        </div>
                                        <div class="mb-3">
                                            <span class="info-label">Reference / Date</span>
                                            <span class="info-value" id="detailPayDate"></span>
                                        </div>
                                        <div>
                                            <span class="info-label">Captured Amount</span>
                                            <span class="info-value" id="detailPayAmount"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="d-flex w-100 justify-content-between align-items-center">
                        <span class="text-muted small"><i class="fas fa-info-circle me-1"></i> Use actions to update records</span>
                        <div class="action-bar" id="detailActionBtns">
                            <!-- JS injected buttons -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Confirm Action Modal ──────────────────────────────────────── -->
    <div class="modal fade" id="confirmModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
                <div class="modal-body p-4 text-center">
                    <div class="confirm-icon-wrap mb-1" id="confirmIconWrap">
                        <i class="fas" id="confirmIcon"></i>
                    </div>
                    <h5 class="fw-bold mb-1" id="confirmTitle"></h5>
                    <p class="text-muted small mb-4" id="confirmMessage"></p>
                    <div class="d-flex gap-2">
                        <button class="btn btn-light flex-fill rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <a id="confirmActionBtn" href="#" class="btn flex-fill rounded-pill fw-bold text-white d-none">Confirm</a>
                        <button id="confirmAjaxBtn" class="btn flex-fill rounded-pill fw-bold text-white d-none" onclick="executeAjaxAction()">Confirm</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Proof Preview Modal ───────────────────────────────────────── -->
    <div class="modal fade" id="proofModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Payment Proof</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <p class="text-muted small mb-2" id="proofGuest"></p>
                    <p class="fw-bold mb-3" id="proofAmount"></p>
                    <img id="proofImgFull" src="" alt="Proof" style="max-width:100%; border-radius:12px;">
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Message Modal ─────────────────────────────────────────────── -->
    <div class="modal fade" id="messageModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-info text-white p-4">
                    <h5 class="modal-title fw-bold"><i class="fas fa-paper-plane me-2"></i> Send Notification to Guest</h5>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form method="POST">
                        <input type="hidden" name="action" value="send_message">
                        <input type="hidden" name="target_user_id" id="targetUserId">
                        <input type="hidden" name="target_res_id"  id="targetResId">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">To Guest:</label>
                            <input type="text" id="targetGuestName" class="form-control bg-light border-0 fw-bold" readonly>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold">Message Text</label>
                            <textarea name="message_text" class="form-control rounded-3" rows="4"
                                      placeholder="Type your message about their request or additional details here..." required></textarea>
                            <div class="form-text small">This will appear in the guest's notification bell panel immediately.</div>
                        </div>
                        <button type="submit" class="btn btn-info text-white w-100 py-3 rounded-pill fw-bold">
                            <i class="fas fa-bell me-2"></i> Send Notification
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // ── View Detail Modal ──────────────────────────────────────────────
    function openDetailModal(d) {
        // Headers
        document.getElementById('detailBookingIdDisp').textContent = 'Booking #' + d.id;
        document.getElementById('detailCreatedDisp').textContent   = 'Placed on ' + d.created_at;

        // Guest
        document.getElementById('detailName').textContent    = d.full_name;
        document.getElementById('detailEmail').textContent   = d.email;
        document.getElementById('detailPhone').textContent   = d.phone;

        // Stay
        document.getElementById('detailRoom').textContent    = d.booking_label + (d.room_type !== '—' ? ' (' + d.room_type + ')' : '');
        document.getElementById('detailCheckIn').textContent = d.check_in;
        document.getElementById('detailCheckOut').textContent= d.check_out;
        document.getElementById('detailTotal').textContent   = '₱' + d.total;

        // Guest composition
        document.getElementById('detailGuestCount').textContent = (d.guest_count || 0) + ' Pax';
        const breakdownTxt = (d.guest_breakdown || [])
            .map(g => `${g.guest_count} ${g.type_name}`)
            .join(', ');
        document.getElementById('detailGuestBreakdown').textContent = breakdownTxt || '—';

        // Payment Details
        document.getElementById('detailPayMethod').textContent = d.pay_method;
        document.getElementById('detailPayAmount').textContent = d.pay_amount !== '—' ? '₱' + d.pay_amount : 'No payment data';
        document.getElementById('detailPayDate').textContent   = d.pay_date;

        // Status Badges
        const statusMap = {
            'Pending Approval': 'status-pending',
            'Approved':         'status-approved',
            'Rejected':         'status-rejected',
            'Completed':        'status-completed',
            'Cancelled':        'status-cancelled'
        };
        const payMap = {
            'Paid':     'status-approved',
            'Pending':  'status-pending',
            'Refunded': 'status-rejected',
            'None':     'status-cancelled'
        };

        document.getElementById('detailStatusBadge').innerHTML = 
            `<span class="status-badge ${statusMap[d.status] || ''}">${d.status}</span>`;
        document.getElementById('detailPayStatusBadge').innerHTML = 
            `<span class="status-badge ${payMap[d.pay_status] || ''}">${d.pay_status}</span>`;

        // Proof
        const proofSec = document.getElementById('proofSection');
        const proofImg = document.getElementById('detailProofImg');
        if (d.proof_url) {
            proofImg.src = d.proof_url;
            proofSec.style.display = 'block';
        } else {
            proofSec.style.display = 'none';
        }

        // Action Buttons
        const btns = document.getElementById('detailActionBtns');
        btns.innerHTML = '';
        const padId = d.id;

        if (d.status === 'Pending Approval') {
            // Show confirm payment button first if payment is pending
            if (d.payment_id && d.pay_status === 'Pending') {
                btns.innerHTML += `
                    <button class="btn-action btn-confirm" onclick="confirmAndHide('confirm_payment', 'Confirm Down Payment', 'Confirm the down payment for #${padId}? This is required before approval.', 'info', 'fa-check-double', '?action=confirm_payment&id=${d.raw_id}&payment_id=${d.payment_id}')">
                        <i class="fas fa-check-double"></i> Confirm Payment
                    </button>
                    <button class="btn-action btn-reject" onclick="confirmAndHide('reject_payment', 'Reject Payment Proof', 'Reject the payment proof for #${padId}?', 'danger', 'fa-ban', '?action=reject_payment&id=${d.raw_id}&payment_id=${d.payment_id}')">
                        <i class="fas fa-ban"></i> Reject Proof
                    </button>
                `;
            }
            btns.innerHTML += `
                <button class="btn-action btn-approve" onclick="confirmAndHide('approve', 'Approve Booking', 'Approve Booking #${padId}?', 'success', 'fa-check-circle', '?action=approve&id=${d.raw_id}')">
                    <i class="fas fa-check"></i> Approve
                </button>
                <button class="btn-action btn-reject" onclick="confirmAndHide('reject', 'Reject Booking', 'Reject Booking #${padId}?', 'danger', 'fa-times-circle', '?action=reject&id=${d.raw_id}')">
                    <i class="fas fa-times"></i> Reject
                </button>
            `;
        } else if (d.status === 'Approved' && d.payment_id && d.pay_status === 'Pending') {
            btns.innerHTML += `
                <button class="btn-action btn-confirm" onclick="confirmAndHide('confirm_payment', 'Confirm Payment', 'Confirm payment for #${padId}?', 'info', 'fa-check-double', '?action=confirm_payment&id=${d.raw_id}&payment_id=${d.payment_id}')">
                    <i class="fas fa-check-double"></i> Confirm Payment
                </button>
                <button class="btn-action btn-reject" onclick="confirmAndHide('reject_payment', 'Invalid Proof', 'Reject this payment proof?', 'danger', 'fa-ban', '?action=reject_payment&id=${d.raw_id}&payment_id=${d.payment_id}')">
                    <i class="fas fa-ban"></i> Reject Proof
                </button>
            `;
        }

        if (!['Cancelled','Completed','Rejected'].includes(d.status)) {
            btns.innerHTML += `
                <button class="btn-action btn-cancel" onclick="confirmAndHide('cancel', 'Cancel Booking', 'Cancel Booking #${padId}?', 'warning', 'fa-exclamation-triangle', '?action=cancel&id=${d.raw_id}')">
                    <i class="fas fa-minus-circle"></i> Cancel
                </button>
            `;
        }

        if (d.status === 'Completed' && !d.review_id) {
            btns.innerHTML += `
                <button class="btn-action btn-message" onclick="confirmAndHide('send_survey', 'Send Survey', 'Send the rating survey SMS to the guest for #${padId}?', 'info', 'fa-star', '?action=send_survey&id=${d.raw_id}')">
                    <i class="fas fa-star"></i> Send Survey
                </button>
            `;
        }

        btns.innerHTML += `
            <button class="btn-action btn-message" onclick="messageAndHide(${d.user_id}, '${padId}', '${d.full_name.replace(/'/g,"\\'")}')">
                <i class="fas fa-comment-dots"></i> Message
            </button>
        `;

        new bootstrap.Modal(document.getElementById('detailModal')).show();
    }

    function confirmAndHide(type, title, msg, color, icon, href) {
        bootstrap.Modal.getInstance(document.getElementById('detailModal')).hide();
        setTimeout(() => triggerConfirm(type, title, msg, color, icon, href), 400);
    }

    function messageAndHide(uid, rid, name) {
        bootstrap.Modal.getInstance(document.getElementById('detailModal')).hide();
        setTimeout(() => openMessageModal(uid, rid, name), 400);
    }

    // ── Confirm Action Modal ───────────────────────────────────────────
    let pendingAction = { type: '', id: 0, payment_id: 0 };

    function triggerConfirm(type, title, message, color, icon, href) {
        const colorMap = {
            success: { bg:'#dcfce7', icon:'#16a34a', btn:'#16a34a' },
            danger:  { bg:'#fee2e2', icon:'#dc2626', btn:'#dc2626' },
            warning: { bg:'#fef9c3', icon:'#ca8a04', btn:'#b45309' },
            info:    { bg:'#e0f2fe', icon:'#0891b2', btn:'#0891b2' },
        };
        const c = colorMap[color] || colorMap.info;

        const wrap = document.getElementById('confirmIconWrap');
        wrap.style.background = c.bg;
        const iconEl = document.getElementById('confirmIcon');
        iconEl.className = 'fas ' + icon;
        iconEl.style.color = c.icon;

        document.getElementById('confirmTitle').textContent   = title;
        document.getElementById('confirmMessage').textContent = message;

        // Parse action from query string (e.g. "?action=approve&id=123")
        const params = new URLSearchParams(href.replace(/^\?/, ''));
        const action = params.get('action') || type;
        const id = parseInt(params.get('id') || '0');
        const payment_id = parseInt(params.get('payment_id') || '0');

        pendingAction = { type: action, id: id, payment_id: payment_id };

        const legacyBtn = document.getElementById('confirmActionBtn');
        const ajaxBtn = document.getElementById('confirmAjaxBtn');
        if (legacyBtn) legacyBtn.classList.add('d-none');
        if (ajaxBtn) {
            ajaxBtn.classList.remove('d-none');
            ajaxBtn.style.background = c.btn;
            ajaxBtn.style.borderColor = c.btn;
        }

        new bootstrap.Modal(document.getElementById('confirmModal')).show();
    }

    function executeAjaxAction() {
        const btn = document.getElementById('confirmAjaxBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing...';

        const formData = new FormData();
        formData.append('action', pendingAction.type);
        formData.append('id', pendingAction.id);
        if (pendingAction.payment_id) formData.append('payment_id', pendingAction.payment_id);

        fetch('../api/admin_action.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
                btn.disabled = false;
                btn.innerHTML = 'Confirm';

                if (data.success) {
                    showToast(data.message, 'success');
                    // Immediately refresh the table
                    refreshAdminReservations();
                } else {
                    showToast(data.message || 'Action failed.', 'danger');
                }
            })
            .catch(err => {
                console.error('AJAX action error:', err);
                bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
                btn.disabled = false;
                btn.innerHTML = 'Confirm';
                showToast('An error occurred. Please try again.', 'danger');
            });
    }

    function showToast(message, type) {
        let container = document.getElementById('adminToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'adminToastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
        }
        const bg = {success:'bg-success',warning:'bg-warning',danger:'bg-danger',info:'bg-info'}[type]||'bg-info';
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white ${bg} border-0 show`;
        toast.innerHTML = `<div class="d-flex"><div class="toast-body small">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
        container.appendChild(toast);
        setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 5000);
    }

    // ── Message Modal ──────────────────────────────────────────────────
    function openMessageModal(userId, resId, guestName) {
        document.getElementById('targetUserId').value    = userId;
        document.getElementById('targetResId').value     = resId;
        document.getElementById('targetGuestName').value = guestName;
        new bootstrap.Modal(document.getElementById('messageModal')).show();
    }

    // ── Proof Preview (from proof column image click if still visible) ──
    function showProof(url, guest, amount) {
        document.getElementById('proofImgFull').src              = url;
        document.getElementById('proofGuest').textContent        = 'Guest: ' + guest;
        document.getElementById('proofAmount').textContent       = '₱' + amount;
        new bootstrap.Modal(document.getElementById('proofModal')).show();
    }

    // ─── REAL-TIME POLLING ─────────────────────────────────────────
    let lastResNotifId = <?php echo $unread_count > 0 ? ($notifications[0]['id'] ?? 0) : 0; ?>;

    function refreshAdminReservations() {
        fetch('../api/get_admin_reservations.php')
            .then(res => res.json())
            .then(data => {
                if (!data.success) return;

                // Update stats
                const statEls = document.querySelectorAll('.stat-card h3, .stat-number');
                if (data.stats) {
                    // Try to find stat elements by nearby text
                    document.querySelectorAll('.stat-card').forEach(card => {
                        const h3 = card.querySelector('h3');
                        const label = card.querySelector('p')?.textContent?.trim() || '';
                        if (label.includes('Revenue') && h3) h3.textContent = '₱' + data.stats.total_revenue.toLocaleString('en-US', {minimumFractionDigits:2});
                        else if (label.includes('Pending') && h3) h3.textContent = data.stats.pending_count;
                        else if (label.includes('Payment') && h3) h3.textContent = data.stats.payment_pending;
                        else if (label.includes('Completed') && h3) h3.textContent = data.stats.completed;
                    });
                }

                // Update reservations table
                const tbody = document.querySelector('.table tbody');
                if (!tbody || !data.reservations.length) return;

                tbody.innerHTML = data.reservations.map(r => {
                    const statusColors = {
                        'Pending Approval': 'warning', 'Approved': 'success', 'Rejected': 'danger',
                        'Completed': 'primary', 'Cancelled': 'secondary'
                    };
                    const payStatusColors = {
                        'Pending': 'warning', 'Paid': 'success', 'Refunded': 'danger'
                    };
                    const statusColor = statusColors[r.status] || 'secondary';
                    const payColor = payStatusColors[r.payment_status] || 'secondary';
                    const padId = String(r.id).padStart(5, '0');
                    const bookingLabel = r.booking_type === 'room'
                        ? (r.room_type || 'Room') + (r.room_number ? ' #' + r.room_number : '')
                        : (r.package_name || 'Event');
                    const checkin = new Date(r.check_in_date).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
                    const proofUrl = r.proof_of_payment_url ? `'${r.proof_of_payment_url}'` : 'null';

                    const detailData = JSON.stringify({
                        raw_id: r.id, id: padId, full_name: r.full_name, email: r.email, phone: r.phone,
                        booking_type: r.booking_type, booking_label: bookingLabel,
                        room_type: r.room_type || '—', check_in: checkin,
                        guest_count: parseInt(r.guest_count) || 0,
                        guest_breakdown: r.guest_breakdown || [],
                        check_out: r.check_out_date ? new Date(r.check_out_date).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}) : '—',
                        total: parseFloat(r.total_price).toLocaleString('en-US', {minimumFractionDigits:2}),
                        status: r.status, status_color: statusColor,
                        pay_status: r.payment_status || 'None', pay_color: payColor,
                        pay_method: r.payment_method || '—',
                        pay_amount: r.payment_amount ? parseFloat(r.payment_amount).toLocaleString('en-US', {minimumFractionDigits:2}) : '—',
                        pay_date: r.transaction_date ? new Date(r.transaction_date).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}) : '—',
                        proof_url: r.proof_of_payment_url || '', created_at: new Date(r.created_at).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}),
                        payment_id: r.payment_id, user_id: r.user_id, review_id: r.review_id || null
                    });

                    let actionBtns = '';
                    if (r.status === 'Pending Approval') {
                        // Show confirm payment button first if payment is pending
                        if (r.payment_id && r.payment_status === 'Pending') {
                            actionBtns += `<button class="action-btn action-btn-confirm" data-tip="Confirm Down Payment" onclick="event.stopPropagation(); triggerConfirm('confirm_payment','Confirm Down Payment','Confirm the down payment for #${padId}? This is required before approval.','info','fa-check-double','?action=confirm_payment&id=${r.id}&payment_id=${r.payment_id}')"><i class="fas fa-check-double"></i></button>`;
                            actionBtns += `<button class="action-btn action-btn-reject" data-tip="Reject Proof" onclick="event.stopPropagation(); triggerConfirm('reject_payment','Reject Payment Proof','Reject payment proof for #${padId}?','danger','fa-ban','?action=reject_payment&id=${r.id}&payment_id=${r.payment_id}')"><i class="fas fa-ban"></i></button>`;
                        }
                        actionBtns += `<button class="action-btn action-btn-approve" data-tip="Approve" onclick="event.stopPropagation(); triggerConfirm('approve','Approve Booking','Approve booking #${padId}?','success','fa-check','?action=approve&id=${r.id}')"><i class="fas fa-check"></i></button>`;
                        actionBtns += `<button class="action-btn action-btn-reject" data-tip="Reject" onclick="event.stopPropagation(); triggerConfirm('reject','Reject Booking','Reject booking #${padId}?','danger','fa-times','?action=reject&id=${r.id}')"><i class="fas fa-times"></i></button>`;
                    } else if (r.status === 'Approved' && r.payment_id && r.payment_status === 'Pending') {
                        actionBtns = `<button class="action-btn action-btn-confirm" data-tip="Confirm Payment" onclick="event.stopPropagation(); triggerConfirm('confirm_payment','Confirm Payment','Confirm payment for #${padId}?','info','fa-check-double','?action=confirm_payment&id=${r.id}&payment_id=${r.payment_id}')"><i class="fas fa-check-double"></i></button>`;
                        actionBtns += `<button class="action-btn action-btn-reject" data-tip="Reject Proof" onclick="event.stopPropagation(); triggerConfirm('reject_payment','Reject Payment','Reject payment for #${padId}?','warning','fa-undo','?action=reject_payment&id=${r.id}&payment_id=${r.payment_id}')"><i class="fas fa-undo"></i></button>`;
                    }
                    if (r.status !== 'Cancelled' && r.status !== 'Rejected' && r.status !== 'Completed') {
                        actionBtns += `<button class="btn-action btn-cancel" onclick="event.stopPropagation(); triggerConfirm('cancel','Cancel Booking','Cancel booking #${padId}? This cannot be undone.','danger','fa-ban','?action=cancel&id=${r.id}')"><i class="fas fa-ban"></i></button>`;
                    }
                    if (r.status === 'Completed' && !r.review_id) {
                        actionBtns += `<button class="action-btn action-btn-msg" data-tip="Send Survey SMS" onclick="event.stopPropagation(); triggerConfirm('send_survey','Send Feedback Survey','Send the rating survey SMS to the guest for #${padId}?','info','fa-star','?action=send_survey&id=${r.id}')"><i class="fas fa-star"></i></button>`;
                    }

                    const proofCol = r.proof_of_payment_url
                        ? `<img src="../${r.proof_of_payment_url}" class="proof-thumb" onclick="event.stopPropagation(); showProof('../${r.proof_of_payment_url}', '${r.full_name.replace(/'/g,"\\'")}', '${r.payment_amount || 0}')" title="View Proof">`
                        : '<span class="small text-muted">—</span>';

                    const payBadge = r.payment_id
                        ? `<div><span class="badge bg-${payColor}">${r.payment_status}</span>${r.payment_status === 'Pending' && r.status === 'Pending Approval' ? '<span class="badge bg-danger rounded-pill ms-1" style="font-size:0.55rem; animation: pulse 1.5s infinite;">NEEDS CONFIRM</span>' : ''}</div><div class="small text-muted">${r.payment_method||''}</div>`
                        : '<span class="small text-muted">No payment yet</span>';

                    return `<tr class="clickable-row" onclick='openDetailModal(${detailData})'>
                        <td class="ps-4 fw-bold small">#${padId}</td>
                        <td><div class="fw-semibold">${r.full_name}</div><div class="small text-muted">${r.phone}</div></td>
                        <td><span class="badge bg-light text-dark border">${bookingLabel}</span></td>
                        <td class="small">${checkin}</td>
                        <td class="fw-bold">₱${parseFloat(r.total_price).toLocaleString('en-US', {minimumFractionDigits:2})}</td>
                        <td><span class="badge bg-${statusColor}">${r.status}</span></td>
                        <td>${payBadge}</td>
                        <td>${proofCol}</td>
                        <td class="text-end pe-4"><div class="action-btns">${actionBtns}</div></td>
                    </tr>`;
                }).join('');
            })
            .catch(err => console.error('Admin reservations refresh error:', err));
    }

    function pollAdminReservations() {
        // Poll notifications
        fetch(`../api/get_notifications.php?last_id=${lastResNotifId}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success) return;
                if (data.notifications.length > 0) {
                    data.notifications.forEach(n => {
                        lastResNotifId = Math.max(lastResNotifId, parseInt(n.id));
                        showToast(n.message, n.type);
                    });
                }
            })
            .catch(() => {});
    }

    setInterval(pollAdminReservations, 10000);
    setInterval(refreshAdminReservations, 15000);

    // Sidebar toggle for mobile
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        });
    }
    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
    }
    </script>

    <!-- Scroll-to-top arrow (own script block AFTER the markup so the
         button exists when looked up) -->
    <button id="scrollTopBtn" class="scroll-top-btn" aria-label="Back to top" title="Back to top">
        <i class="fas fa-arrow-up"></i>
    </button>
    <script>
    (function() {
        const btn = document.getElementById('scrollTopBtn');
        if (!btn) return;
        const toggle = () => btn.classList.toggle('show', window.scrollY > 300);
        window.addEventListener('scroll', toggle, { passive: true });
        toggle();
        btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    })();
    </script>
</body>
</html>
