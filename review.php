<?php
session_start();
require_once 'config/db.php';

// Resolve the booking under review: SMS token OR logged-in owner (?res_id=).
$token = trim($_GET['token'] ?? '');
$res_id = (int)($_GET['res_id'] ?? 0);
$reservation = null;

if ($token !== '') {
    $stmt = $pdo->prepare("SELECT r.*, u.full_name,
            rm.room_number, rm.room_type, ep.package_name
        FROM reservations r
        JOIN users u ON u.id = r.user_id
        LEFT JOIN rooms rm ON rm.id = r.item_id AND r.booking_type = 'room'
        LEFT JOIN event_packages ep ON ep.id = r.item_id AND r.booking_type = 'event'
        WHERE r.review_token = ? AND r.review_token_expires > NOW() AND r.status = 'Completed'");
    $stmt->execute([$token]);
    $reservation = $stmt->fetch();
} elseif ($res_id > 0 && isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT r.*, u.full_name,
            rm.room_number, rm.room_type, ep.package_name
        FROM reservations r
        JOIN users u ON u.id = r.user_id
        LEFT JOIN rooms rm ON rm.id = r.item_id AND r.booking_type = 'room'
        LEFT JOIN event_packages ep ON ep.id = r.item_id AND r.booking_type = 'event'
        WHERE r.id = ? AND r.user_id = ? AND r.status = 'Completed'");
    $stmt->execute([$res_id, $_SESSION['user_id']]);
    $reservation = $stmt->fetch();
}

$error = '';
$done = false;

if ($reservation) {
    if ($reservation['booking_type'] === 'event') {
        $unit_label = $reservation['package_name'] ?: 'Event Package';
    } else {
        $unit_label = 'Room ' . ($reservation['room_number'] ?? '?');
        if (!empty($reservation['room_type'])) {
            $unit_label .= ' (' . $reservation['room_type'] . ')';
        }
    }

    // Already reviewed?
    $chk = $pdo->prepare("SELECT id FROM reviews WHERE reservation_id = ?");
    $chk->execute([$reservation['id']]);
    if ($chk->fetch()) {
        $done = true;
        $error = '';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rating = (int)($_POST['rating'] ?? 0);
        $feedback = trim(preg_replace('/\s+/', ' ', strip_tags($_POST['feedback'] ?? '')));

        if ($rating < 1 || $rating > 5) {
            $error = 'Please tap a star rating from 1 to 5.';
        } elseif (strlen($feedback) < 10 || strlen($feedback) > 1000) {
            $error = 'Please write a short feedback (10–1000 characters).';
        } else {
            try {
                $ins = $pdo->prepare("INSERT INTO reviews (reservation_id, user_id, booking_type, item_id, rating, feedback, status) VALUES (?, ?, ?, ?, ?, ?, 'approved')");
                $ins->execute([$reservation['id'], $reservation['user_id'], $reservation['booking_type'], $reservation['item_id'], $rating, $feedback]);
                $done = true;
            } catch (PDOException $e) {
                // Duplicate (already reviewed in another tab/click)
                $done = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Your Stay | Centrotel & Event Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .star-btn { background: none; border: none; font-size: 2.4rem; color: #e2e8f0; cursor: pointer; padding: 0 4px; transition: transform 0.15s; }
        .star-btn.lit { color: #f59e0b; }
        .star-btn:active { transform: scale(1.2); }
    </style>
</head>
<body class="bg-light">
    <div class="container d-flex align-items-center justify-content-center min-vh-100 px-3 py-4">
        <div class="card border-0 shadow-lg rounded-4" style="max-width: 520px; width: 100%;">
            <div class="card-body p-4 p-sm-5">
                <?php if (!$reservation): ?>
                    <div class="text-center">
                        <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px;height:80px;">
                            <i class="fas fa-link-slash fa-2x"></i>
                        </div>
                        <h3 class="fw-bold">Link Expired</h3>
                        <p class="text-muted">This survey link is invalid, already used, or the booking is no longer eligible for review.</p>
                        <a href="index.php" class="btn btn-premium w-100 py-2 mt-3">Back to Home</a>
                    </div>
                <?php elseif ($done): ?>
                    <div class="text-center">
                        <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px;height:80px;">
                            <i class="fas fa-check fa-2x"></i>
                        </div>
                        <h3 class="fw-bold">Thank You!</h3>
                        <p class="text-muted">Your rating for <strong><?php echo htmlspecialchars($unit_label); ?></strong> has been recorded and helps future guests.</p>
                        <a href="index.php" class="btn btn-premium w-100 py-2 mt-3">Back to Home</a>
                    </div>
                <?php else: ?>
                    <div class="text-center mb-4">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px;height:70px;">
                            <i class="fas fa-star fa-2x"></i>
                        </div>
                        <h3 class="fw-bold">How was <?php echo htmlspecialchars($unit_label); ?>?</h3>
                        <p class="text-muted small mb-0">Booking #<?php echo str_pad($reservation['id'], 5, '0', STR_PAD_LEFT); ?> · <?php echo date('M d, Y', strtotime($reservation['check_in_date'])); ?></p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger small"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3 text-center">
                            <label class="form-label small fw-bold d-block">Tap to rate (1–5 stars)</label>
                            <div id="starRow" role="radiogroup" aria-label="Star rating">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <button type="button" class="star-btn" data-val="<?php echo $s; ?>" aria-label="<?php echo $s; ?> star<?php echo $s > 1 ? 's' : ''; ?>">★</button>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="rating" id="ratingInput" value="0">
                            <div class="small text-muted mt-1" id="ratingHint">No rating selected</div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold">Your feedback</label>
                            <textarea name="feedback" class="form-control rounded-3" rows="4" required minlength="10" maxlength="1000" placeholder="What did you enjoy? What can we improve?"><?php echo htmlspecialchars($_POST['feedback'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-premium w-100 py-2"><i class="fas fa-paper-plane me-2"></i>Submit Review</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        (function() {
            const stars = document.querySelectorAll('#starRow .star-btn');
            const input = document.getElementById('ratingInput');
            const hint = document.getElementById('ratingHint');
            const labels = ['', 'Poor', 'Fair', 'Good', 'Very Good', 'Excellent'];
            if (!stars.length || !input) return;
            function paint(n) {
                stars.forEach(s => s.classList.toggle('lit', parseInt(s.dataset.val, 10) <= n));
                hint.textContent = n > 0 ? (n + ' / 5 — ' + labels[n]) : 'No rating selected';
            }
            stars.forEach(s => s.addEventListener('click', function() {
                input.value = this.dataset.val;
                paint(parseInt(this.dataset.val, 10));
            }));
        })();
    </script>
</body>
</html>
