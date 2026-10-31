<?php
session_start();
require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $res_id = (int)($_POST['res_id'] ?? 0);
    $method = $_POST['method'] ?? 'Not Specified';
    $amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? (float)$_POST['amount'] : null;

    // The reservation must belong to the session user (admins may act on any)
    $owner_stmt = $pdo->prepare("SELECT user_id FROM reservations WHERE id = ?");
    $owner_stmt->execute([$res_id]);
    $owner_id = $owner_stmt->fetchColumn();
    $is_admin = ($_SESSION['user_role'] ?? '') === 'admin';

    if ($owner_id === false || (!$is_admin && (int)$owner_id !== (int)$_SESSION['user_id'])) {
        header('Location: ../index.php?error=payment');
        exit();
    }

    // Directory for uploads
    $uploadDir = '../assets/img/payments/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $isUponArrival = (strtolower($method) === 'pay upon arrival');
    $dbPath = '';

    if (isset($_FILES['proof']) && $_FILES['proof']['error'] === 0) {
        $filename = 'pay_' . $res_id . '_' . time() . '.' . pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION);
        $targetFile = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['proof']['tmp_name'], $targetFile)) {
            $dbPath = 'assets/img/payments/' . $filename;
        } else {
            die("Error moving file.");
        }
    } elseif (!$isUponArrival) {
        // If it's NOT upon arrival and NO file was uploaded, throw error
        die("Invalid file upload. Please provide a screenshot of your payment.");
    }

    // Record payment in database (use provided amount, otherwise full total)
    try {
        if ($amount !== null) {
            $stmt = $pdo->prepare("INSERT INTO payments (reservation_id, amount, payment_method, proof_of_payment_url, status) VALUES (?, ?, ?, ?, 'Pending')");
            $stmt->execute([$res_id, $amount, $method, $dbPath]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO payments (reservation_id, amount, payment_method, proof_of_payment_url, status) 
                                  SELECT id, total_price, ?, ?, 'Pending' FROM reservations WHERE id = ?");
            $stmt->execute([$method, $dbPath, $res_id]);
        }

        // Determine amount for the notification
        if ($amount === null) {
            $amt_stmt = $pdo->prepare("SELECT total_price FROM reservations WHERE id = ?");
            $amt_stmt->execute([$res_id]);
            $amount = (float)$amt_stmt->fetchColumn();
        }

        // Notify the admin of the payment submission
        $admin_stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
        $admin_id = $admin_stmt->fetchColumn();
        if ($admin_id) {
            $pay_msg = "Payment of ₱" . number_format($amount, 2) . " submitted for reservation #" . str_pad($res_id, 5, '0', STR_PAD_LEFT) . " via " . $method . ". Awaiting confirmation.";
            $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'info')")->execute([$admin_id, $pay_msg]);
        }

        header('Location: ../index.php?success=payment');
        exit();
    } catch (PDOException $e) {
        die("Database Error: " . $e->getMessage());
    }
}
?>
