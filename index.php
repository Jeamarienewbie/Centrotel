<?php
session_start();
require_once 'config/db.php';

// If Admin, redirect to admin panel
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    header('Location: admin/dashboard.php');
    exit();
}

$isLoggedIn = isset($_SESSION['user_id']);
$userName   = $_SESSION['user_name'] ?? '';
$userPhone  = '';

if ($isLoggedIn) {
    $u_stmt = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
    $u_stmt->execute([$_SESSION['user_id']]);
    $userPhone = $u_stmt->fetchColumn();
}

// Fetch user info if logged in
$reservations = [];
$notifications = [];
$unread_count = 0;
if ($isLoggedIn) {
    $stmt = $pdo->prepare("SELECT r.*, rm.room_type, ep.package_name, rv.id AS review_id
                          FROM reservations r
                          LEFT JOIN rooms rm ON r.item_id = rm.id AND r.booking_type = 'room'
                          LEFT JOIN event_packages ep ON r.item_id = ep.id AND r.booking_type = 'event'
                          LEFT JOIN reviews rv ON rv.reservation_id = r.id
                          WHERE r.user_id = ?
                          ORDER BY r.created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $reservations = $stmt->fetchAll();

    // Guest composition (Male / Female / Children ...) for each reservation
    $reservation_breakdown = [];
    try {
        $gb_stmt = $pdo->prepare("
            SELECT rg.reservation_id, rg.guest_count, gt.type_name
            FROM reservation_guests rg
            JOIN guest_types gt ON gt.id = rg.guest_type_id
            WHERE rg.reservation_id IN (SELECT id FROM reservations WHERE user_id = ?)
              AND rg.guest_count > 0
            ORDER BY gt.sort_order ASC, gt.id ASC
        ");
        $gb_stmt->execute([$_SESSION['user_id']]);
        foreach ($gb_stmt->fetchAll() as $g) {
            $reservation_breakdown[(int)$g['reservation_id']][] = $g['guest_count'] . ' ' . $g['type_name'];
        }
    } catch (PDOException $e) {
        $reservation_breakdown = [];
    }

    // Fetch notifications
    $n_stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $n_stmt->execute([$_SESSION['user_id']]);
    $notifications = $n_stmt->fetchAll();
    $unread_count = count(array_filter($notifications, fn($n) => $n['is_read'] == 0));

    // Mark all as read when page loads
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$_SESSION['user_id']]);
}

// Fetch all packages for the event section (with approved rating averages)
$packages = $pdo->query("SELECT ep.*,
        (SELECT AVG(rating) FROM reviews WHERE booking_type = 'event' AND item_id = ep.id AND status = 'approved') AS avg_rating,
        (SELECT COUNT(*) FROM reviews WHERE booking_type = 'event' AND item_id = ep.id AND status = 'approved') AS review_count
    FROM event_packages ep WHERE ep.status = 'active'")->fetchAll();

// Swipeable event galleries grouped per package (cover first, capped at 10)
$pkg_gallery = [];
if ($packages) {
    $pkg_ids = array_column($packages, 'id');
    $ph = implode(',', array_fill(0, count($pkg_ids), '?'));
    $pg_stmt = $pdo->prepare("SELECT package_id, image_url FROM pkg_images WHERE package_id IN ($ph) ORDER BY package_id ASC, sort_order ASC, id ASC");
    $pg_stmt->execute($pkg_ids);
    foreach ($pg_stmt->fetchAll() as $pg_img) {
        $pkg_gallery[$pg_img['package_id']][] = $pg_img['image_url'];
    }
}

// Fetch all available rooms from the database (so admin-added rooms appear too)
$rooms = $pdo->query("SELECT rm.*,
        (SELECT AVG(rating) FROM reviews WHERE booking_type = 'room' AND item_id = rm.id AND status = 'approved') AS avg_rating,
        (SELECT COUNT(*) FROM reviews WHERE booking_type = 'room' AND item_id = rm.id AND status = 'approved') AS review_count
    FROM rooms rm WHERE rm.status != 'maintenance' ORDER BY rm.room_number ASC")->fetchAll();

// Latest approved feedback per unit (top 3) for the detail modals
$room_feedback = [];
$pkg_feedback = [];
try {
    $fb_stmt = $pdo->query("SELECT rv.booking_type, rv.item_id, rv.rating, rv.feedback, rv.created_at, u.first_name
        FROM reviews rv JOIN users u ON u.id = rv.user_id
        WHERE rv.status = 'approved' ORDER BY rv.created_at DESC");
    foreach ($fb_stmt->fetchAll() as $fb) {
        $entry = ['name' => ($fb['first_name'] ?: 'Guest'), 'rating' => (int)$fb['rating'], 'feedback' => $fb['feedback'], 'date' => date('M d, Y', strtotime($fb['created_at']))];
        if ($fb['booking_type'] === 'event') {
            if (count($pkg_feedback[$fb['item_id']] ?? []) < 3) {
                $pkg_feedback[$fb['item_id']][] = $entry;
            }
        } else {
            if (count($room_feedback[$fb['item_id']] ?? []) < 3) {
                $room_feedback[$fb['item_id']][] = $entry;
            }
        }
    }
} catch (PDOException $e) {
    // reviews table not migrated yet — page still renders without ratings
}

// Swipeable galleries grouped per room (cover first, capped at 10)
$room_gallery = [];
if ($rooms) {
    $room_ids = array_column($rooms, 'id');
    $ph = implode(',', array_fill(0, count($room_ids), '?'));
    $g_stmt = $pdo->prepare("SELECT room_id, image_url FROM room_images WHERE room_id IN ($ph) ORDER BY room_id ASC, sort_order ASC, id ASC");
    $g_stmt->execute($room_ids);
    foreach ($g_stmt->fetchAll() as $g_img) {
        $room_gallery[$g_img['room_id']][] = $g_img['image_url'];
    }
}

// Fetch all active payment methods (used in payment + event booking modals)
$active_methods = $pdo->query("SELECT * FROM payment_methods WHERE status = 'active' ORDER BY id ASC")->fetchAll();

// Fetch active guest types (Male / Female / Children ...) for the booking forms
$active_guest_types = [];
try {
    $active_guest_types = $pdo->query("SELECT * FROM guest_types WHERE status = 'active' ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (PDOException $e) {
    $active_guest_types = [];
}
$guest_type_labels = [];
foreach ($active_guest_types as $gt) {
    $guest_type_labels[(int)$gt['id']] = $gt['type_name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centrotel & Event Center | Premium Room & Event Reservations</title>
    <meta name="description" content="Experience luxury and comfort at Centrotel & Event Center. Book your Airbnb-style rooms and special events in Tuao, Cagayan.">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <img src="assets/img/logo.jpg" alt="Centrotel Logo" height="50" class="me-2">
            </a>
            <button class="navbar-toggler shadow-none border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mx-auto align-items-center">
                    <li class="nav-item"><a class="nav-link px-3" href="#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link px-3" href="#rooms">Rooms</a></li>
                    <li class="nav-item"><a class="nav-link px-3" href="#events">Events</a></li>
                    <li class="nav-item"><a class="nav-link px-3" href="#contact">Contact</a></li>

                    <?php if ($isLoggedIn): ?>
                        <li class="nav-item"><a class="nav-link px-3" href="#" data-bs-toggle="modal" data-bs-target="#myReservationModal">My Reservation</a></li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav align-items-center">
                    <?php if ($isLoggedIn): ?>
                        <!-- Notification Bell -->
                        <li class="nav-item mt-2 mt-lg-0">
                            <div class="dropdown">
                                <button id="notifDropdown" class="btn btn-sm btn-light position-relative rounded-circle" style="width:40px;height:40px;" data-bs-toggle="dropdown">
                                    <i class="fas fa-bell"></i>
                                    <?php if ($unread_count > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.6rem;"><?php echo $unread_count; ?></span>
                                    <?php endif; ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg p-2" style="max-width:90vw;width:320px;max-height:350px;overflow-y:auto;">
                                    <li class="fw-bold small text-muted px-2 pb-2 border-bottom d-flex justify-content-between align-items-center">
                                        Notifications
                                        <a href="#" class="text-decoration-none x-small fw-normal" data-bs-toggle="modal" data-bs-target="#profileModal">View All History</a>
                                    </li>
                                    <?php if (empty($notifications)): ?>
                                    <li class="text-center text-muted small py-3">No notifications yet</li>
                                    <?php else: ?>
                                    <?php foreach ($notifications as $notif): ?>
                                    <li class="py-2 px-2 border-bottom notification-item" 
                                        style="cursor:pointer;" 
                                        onclick="showNotification('<?php echo addslashes($notif['message']); ?>', '<?php echo date('M d, Y | g:i A', strtotime($notif['created_at'])); ?>', '<?php echo $notif['type']; ?>')">
                                        <div class="small d-flex align-items-start gap-2">
                                            <i class="fas fa-circle mt-1 text-<?php echo $notif['type'] ?? 'info'; ?>" style="font-size:0.5rem;"></i>
                                            <div>
                                                <div class="text-dark fw-medium"><?php echo $notif['message']; ?></div>
                                                <div class="x-small text-muted mt-1"><?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?></div>
                                            </div>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                            <div class="dropdown">
                                <button class="btn btn-premium dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fas fa-user-circle me-2"></i><?php echo explode(' ', $userName)[0]; ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg">
                                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="fas fa-id-card me-2"></i>Profile</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item text-danger" href="auth/logout.php" onclick="return confirm('Are you sure you want to logout?')"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                                </ul>
                            </div>
                        </li>
                    <?php else: ?>
                        <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                            <button class="btn btn-premium" data-bs-toggle="modal" data-bs-target="#loginModal">
                                <i class="fas fa-sign-in-alt me-2"></i>Login
                            </button>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero" id="home">
        <div class="container">
            <div class="hero-content animate-up">
                <span class="badge border border-white text-white mb-3 px-3 py-2 rounded-pill fw-bold text-uppercase" style="letter-spacing: 2px; background: rgba(255,255,255,0.1);">Premium Stay Experience</span>
                <h1>Elegance & Comfort <br> Simplified.</h1>
                <p>Welcome to Centrotel & Event Center, your premier destination for luxury room stays and unforgettable celebrations in Cagayan Valley.</p>
                <div class="d-flex flex-column flex-sm-row justify-content-center align-items-stretch align-items-sm-center gap-3">
                    <a href="#rooms" class="btn btn-premium btn-lg">Browse Rooms</a>
                    <a href="#events" class="btn btn-outline btn-lg border-white text-white">Plan an Event</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Features/Rooms Section -->
    <section class="py-5" id="rooms">
        <div class="container py-5">
            <div class="text-center mb-5">
                <h2 class="fw-800" style="color: var(--primary);">Our Airbnb-Style Rooms</h2>
                <div class="mx-auto" style="width: 50px; height: 3px; background: var(--accent);"></div>
                <p class="text-muted mt-3">Equipped with Smart TV, WiFi, Aircon, and relaxation-focused amenities.</p>
            </div>
            
            <div class="row g-4">
                <?php foreach ($rooms as $room): ?>
                <?php
                    $room_amenities = array_map('trim', explode(',', $room['amenities'] ?? ''));
                    // Cover first, then gallery photos, capped at 10 (swipeable in details)
                    $room_photos = $room_gallery[$room['id']] ?? [];
                    if (!empty($room['image_url']) && !in_array($room['image_url'], $room_photos)) {
                        array_unshift($room_photos, $room['image_url']);
                    }
                    $room_photos = array_slice(array_values($room_photos), 0, 10);
                    if (empty($room_photos)) {
                        $room_photos = ['assets/img/placeholder.jpg'];
                    }
                    $room_cover = $room_photos[0];
                ?>
                <div class="col-md-4 col-sm-6 col-12">
                    <div class="card-premium h-100">
                        <div class="card-img-container position-relative">
                            <?php if (count($room_photos) > 1): ?>
                            <div id="cardCarousel<?php echo $room['id']; ?>" class="carousel slide h-100" data-bs-touch="true" data-bs-interval="false">
                                <div class="carousel-inner h-100">
                                    <?php foreach ($room_photos as $p_idx => $p_src): ?>
                                    <div class="carousel-item h-100<?php echo $p_idx === 0 ? ' active' : ''; ?>">
                                        <img src="<?php echo htmlspecialchars($p_src); ?>" class="d-block w-100 h-100 object-fit-cover" alt="<?php echo htmlspecialchars($room['room_type']); ?> photo <?php echo $p_idx + 1; ?>">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button class="carousel-control-prev card-carousel-btn" type="button" data-bs-target="#cardCarousel<?php echo $room['id']; ?>" data-bs-slide="prev" aria-label="Previous photo">
                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                </button>
                                <button class="carousel-control-next card-carousel-btn" type="button" data-bs-target="#cardCarousel<?php echo $room['id']; ?>" data-bs-slide="next" aria-label="Next photo">
                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                </button>
                            </div>
                            <?php else: ?>
                            <img src="<?php echo htmlspecialchars($room_cover); ?>" alt="<?php echo htmlspecialchars($room['room_type']); ?>">
                            <?php endif; ?>
                            <span class="position-absolute top-0 end-0 m-3 badge bg-white text-dark shadow">₱<?php echo number_format($room['price_per_night'], 0); ?>/stay</span>
                            <?php if (count($room_photos) > 1): ?>
                            <span class="position-absolute bottom-0 start-0 m-3 badge bg-dark bg-opacity-75 text-white shadow"><i class="fas fa-images me-1"></i><?php echo count($room_photos); ?> photos · swipe</span>
                            <?php endif; ?>
                        </div>
                        <div class="p-4">
                            <h4 class="fw-700"><?php echo htmlspecialchars($room['room_type']); ?></h4>
                            <p class="text-muted small">Room #<?php echo htmlspecialchars($room['room_number']); ?> — <?php echo htmlspecialchars($room['description']); ?></p>
                            <div class="d-flex flex-wrap gap-2 gap-sm-3 text-muted mb-2 small">
                                <?php foreach (array_slice($room_amenities, 0, 3) as $amenity): ?>
                                <span><i class="fas fa-check me-1"></i> <?php echo htmlspecialchars($amenity); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="small mb-3">
                                <?php if (!empty($room['review_count'])): ?>
                                    <?php echo starsHtml($room['avg_rating']); ?>
                                    <span class="fw-bold"><?php echo number_format((float)$room['avg_rating'], 1); ?></span>
                                    <span class="text-muted">(<?php echo (int)$room['review_count']; ?> review<?php echo (int)$room['review_count'] > 1 ? 's' : ''; ?>)</span>
                                <?php else: ?>
                                    <span class="text-muted">No ratings yet — be the first!</span>
                                <?php endif; ?>
                            </div>
                            <button class="btn btn-premium w-100 view-room-details"
                                data-id="<?php echo $room['id']; ?>"
                                data-title="<?php echo htmlspecialchars($room['room_type']); ?>"
                                data-price="<?php echo number_format($room['price_per_night'], 0); ?>"
                                data-img="<?php echo htmlspecialchars($room_cover); ?>"
                                data-images="<?php echo htmlspecialchars(json_encode($room_photos)); ?>"
                                data-desc="<?php echo htmlspecialchars($room['description']); ?>"
                                data-reviews="<?php echo htmlspecialchars(json_encode($room_feedback[$room['id']] ?? [])); ?>"
                                data-amenities='<?php echo json_encode($room_amenities); ?>'>
                                View Details
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Event Center Section -->
    <section class="py-5 bg-white" id="events">
        <div class="container py-5 text-center">
            <h2 class="fw-800 mb-2" style="color: var(--primary);">Celebrate Your Moments</h2>
            <p class="text-muted mb-5">Choose the perfect package for your special occasion.</p>
            
            <div class="row g-4 text-start">
                <?php foreach($packages as $pkg): ?>
                <?php
                    // Cover first, then gallery photos, capped at 10 (swipeable in details)
                    $pkg_photos = $pkg_gallery[$pkg['id']] ?? [];
                    $pkg_fallback = $pkg['image_url'] ?: 'assets/img/hero-event.jpg';
                    if (!empty($pkg['image_url']) && !in_array($pkg['image_url'], $pkg_photos)) {
                        array_unshift($pkg_photos, $pkg['image_url']);
                    }
                    $pkg_photos = array_slice(array_values($pkg_photos), 0, 10);
                    if (empty($pkg_photos)) {
                        $pkg_photos = [$pkg_fallback];
                    }
                    $pkg_cover = $pkg_photos[0];
                ?>
                <div class="col-md-4 col-sm-6 col-12">
                    <div class="card-premium h-100 shadow-sm border-0">
                        <div class="card-img-container position-relative">
                            <?php if (count($pkg_photos) > 1): ?>
                            <div id="cardPkgCarousel<?php echo $pkg['id']; ?>" class="carousel slide h-100" data-bs-touch="true" data-bs-interval="false">
                                <div class="carousel-inner h-100">
                                    <?php foreach ($pkg_photos as $pp_idx => $pp_src): ?>
                                    <div class="carousel-item h-100<?php echo $pp_idx === 0 ? ' active' : ''; ?>">
                                        <img src="<?php echo htmlspecialchars($pp_src); ?>" class="d-block w-100 h-100 object-fit-cover rounded-top-4" style="height: 200px;" alt="<?php echo htmlspecialchars($pkg['package_name']); ?> photo <?php echo $pp_idx + 1; ?>">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button class="carousel-control-prev card-carousel-btn" type="button" data-bs-target="#cardPkgCarousel<?php echo $pkg['id']; ?>" data-bs-slide="prev" aria-label="Previous photo">
                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                </button>
                                <button class="carousel-control-next card-carousel-btn" type="button" data-bs-target="#cardPkgCarousel<?php echo $pkg['id']; ?>" data-bs-slide="next" aria-label="Next photo">
                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                </button>
                            </div>
                            <?php else: ?>
                            <img src="<?php echo htmlspecialchars($pkg_cover); ?>" alt="<?php echo htmlspecialchars($pkg['package_name']); ?>" class="rounded-top-4 w-100 object-fit-cover" style="height: 200px;">
                            <?php endif; ?>
                            <span class="position-absolute top-0 end-0 m-3 badge bg-white text-dark shadow">Starts at ₱<?php echo number_format($pkg['base_price']); ?></span>
                            <?php if (count($pkg_photos) > 1): ?>
                            <span class="position-absolute bottom-0 start-0 m-3 badge bg-dark bg-opacity-75 text-white shadow"><i class="fas fa-images me-1"></i><?php echo count($pkg_photos); ?> photos · swipe</span>
                            <?php endif; ?>
                        </div>
                        <div class="p-4">
                            <h4 class="fw-700"><?php echo $pkg['package_name']; ?></h4>
                            <p class="text-muted small"><?php echo $pkg['description']; ?></p>
                            <div class="fw-bold small mb-2"><i class="fas fa-users me-2"></i> Capacity: Up to <?php echo $pkg['capacity']; ?> Pax</div>
                            <div class="small mb-3">
                                <?php if (!empty($pkg['review_count'])): ?>
                                    <?php echo starsHtml($pkg['avg_rating']); ?>
                                    <span class="fw-bold"><?php echo number_format((float)$pkg['avg_rating'], 1); ?></span>
                                    <span class="text-muted">(<?php echo (int)$pkg['review_count']; ?> review<?php echo (int)$pkg['review_count'] > 1 ? 's' : ''; ?>)</span>
                                <?php else: ?>
                                    <span class="text-muted">No ratings yet — be the first!</span>
                                <?php endif; ?>
                            </div>
                            <button class="btn btn-premium w-100 view-package-details"
                                data-id="<?php echo $pkg['id']; ?>"
                                data-title="<?php echo htmlspecialchars($pkg['package_name']); ?>"
                                data-price="<?php echo number_format($pkg['base_price']); ?>"
                                data-raw-price="<?php echo $pkg['base_price']; ?>"
                                data-img="<?php echo htmlspecialchars($pkg_cover); ?>"
                                data-images="<?php echo htmlspecialchars(json_encode($pkg_photos)); ?>"
                                data-desc="<?php echo htmlspecialchars($pkg['description']); ?>"
                                data-capacity="<?php echo $pkg['capacity']; ?>"
                                data-reviews="<?php echo htmlspecialchars(json_encode($pkg_feedback[$pkg['id']] ?? [])); ?>"
                                data-services='<?php echo json_encode(array_map("trim", explode(",", $pkg["services_included"]))); ?>'>
                                View & Reserve Package
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="py-5" style="background: var(--primary); color: white;" id="contact">
        <div class="container">
            <div class="row g-4 g-lg-5">
                <div class="col-12 col-lg-4">
                    <div class="d-flex align-items-center mb-4">
                        <img src="assets/img/logo.jpg" alt="Centrotel Logo" height="60" width="60" class="footer-logo">
                    </div>
                    <p class="opacity-75">Your getaway to the stunning views and modern comforts of Tuao, Cagayan Valley. Experience service like no other.</p>
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <h5 class="mb-4">Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-white text-decoration-none opacity-75 hover-opacity-100">About Us</a></li>
                        <li><a href="#rooms" class="text-white text-decoration-none opacity-75">Rooms</a></li>
                        <li><a href="#events" class="text-white text-decoration-none opacity-75">Events</a></li>
                        <li><a href="#" class="text-white text-decoration-none opacity-75" data-bs-toggle="modal" data-bs-target="#termsModal">Terms & Conditions</a></li>
                        <li><a href="#" class="text-white text-decoration-none opacity-75">Privacy Policy</a></li>
                    </ul>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <h5 class="mb-4">Contact Info</h5>
                    <ul class="list-unstyled opacity-75">
                        <li class="mb-2"><i class="fas fa-map-marker-alt me-2"></i> Piat–Tuao–Pinukpuk Road, Tuao West, Cagayan Valley</li>
                        <li class="mb-2"><i class="fas fa-phone me-2"></i> 09568820066</li>
                        <li class="mb-2 text-break"><i class="fas fa-envelope me-2"></i> centroteleventcenter@gmail.com</li>
                    </ul>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <h5 class="mb-4">Stay Connected</h5>
                    <div class="d-flex gap-3">
                        <a href="#" class="btn btn-outline-light rounded-circle"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="btn btn-outline-light rounded-circle"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="btn btn-outline-light rounded-circle"><i class="fab fa-twitter"></i></a>
                    </div>
                </div>
            </div>
            <hr class="my-5 opacity-25">
            <p class="text-center opacity-50 mb-0">&copy; 2026 Centrotel & Event Center. All Rights Reserved.</p>
        </div>
    </footer>

    <!-- MODALS -->

    <!-- Login Modal -->
    <div class="modal fade" id="loginModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-body p-4 p-sm-5">
                    <div class="text-center mb-4">
                        <img src="assets/img/logo.jpg" alt="Logo" height="70" class="mb-3">
                        <h3 class="fw-bold">Welcome Back</h3>
                        <p class="text-muted">Login to manage your reservations</p>
                    </div>
                    <form id="loginForm">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Email Address</label>
                            <input type="email" name="email" class="form-control rounded-pill px-4 py-2" placeholder="name@example.com" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold">Password</label>
                            <div class="position-relative">
                                <input type="password" name="password" id="loginPassword" class="form-control rounded-pill px-4 py-2" placeholder="••••••••" required>
                                <span class="position-absolute top-50 end-0 translate-middle-y pe-3" style="cursor: pointer;" onclick="togglePassword('loginPassword', this)">
                                    <i class="fas fa-eye text-muted"></i>
                                </span>
                            </div>
                            <div class="text-end mt-2">
                                <a href="#" class="text-decoration-none small text-accent" data-bs-toggle="modal" data-bs-target="#forgotPwModal">Forgot Password?</a>
                            </div>
                        </div>
                        <input type="hidden" name="csrf_token" id="csrfTokenLogin">
                        <button type="submit" class="btn btn-premium w-100 py-2">Sign In</button>
                        <p class="text-center mt-4 small">Don't have an account? <a href="#" class="text-decoration-none fw-bold" style="color: var(--primary);" data-bs-toggle="modal" data-bs-target="#registerModal">Register Now</a></p>
                        <p class="text-center mt-2 x-small text-muted">Are you an admin? <a href="admin_login.php" class="text-decoration-none fw-bold">Sign in to the admin portal</a></p>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal (OTP Flow) -->
    <div class="modal fade" id="forgotPwModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-body p-4 p-sm-5">
                    <div class="text-center mb-4">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px;height:70px;">
                            <i class="fas fa-key fa-2x"></i>
                        </div>
                        <h3 class="fw-bold">Forgot Password?</h3>
                        <p class="text-muted small" id="forgotPwDesc">Enter your email address to reset your password.</p>
                    </div>

                    <!-- Step 1: Enter Email -->
                    <div id="forgotStep1">
                        <form id="forgotEmailForm">
                            <div class="mb-4">
                                <label class="form-label small fw-bold">Email Address</label>
                                <input type="email" name="email" class="form-control rounded-3" placeholder="name@example.com" required>
                            </div>
                            <div class="alert alert-danger d-none small mb-3" id="forgotEmailError"></div>
                            <input type="hidden" name="csrf_token" id="csrfTokenForgot1">
                            <button type="submit" class="btn btn-premium w-100 py-2" id="forgotEmailBtn">Continue</button>
                        </form>
                    </div>

                    <!-- Step 1b: Choose where to receive the code (SMS and/or Email) -->
                    <div id="forgotStepChannel" style="display:none;">
                        <div class="text-center mb-3">
                            <p class="text-muted small mb-0">Where should we send your 6-digit verification code?</p>
                        </div>
                        <div class="d-grid gap-2 mb-3">
                            <button class="btn btn-outline-primary text-start py-3 px-4 rounded-3" id="forgotChannelSmsBtn">
                                <i class="fas fa-sms me-2"></i><span class="fw-bold">Text message (SMS)</span>
                                <small class="d-block text-muted fw-normal mt-1" id="forgotChannelSmsTo"></small>
                            </button>
                            <button class="btn btn-outline-primary text-start py-3 px-4 rounded-3" id="forgotChannelEmailBtn">
                                <i class="fas fa-envelope me-2"></i><span class="fw-bold">Email</span>
                                <small class="d-block text-muted fw-normal mt-1" id="forgotChannelEmailTo"></small>
                            </button>
                        </div>
                        <div class="alert alert-danger d-none small mb-3" id="forgotChannelError"></div>
                    </div>

                    <!-- Step 2: Enter the OTP (same input for SMS and Email codes) -->
                    <div id="forgotStep2" style="display:none;">
                        <div id="forgotStep2Otp">
                            <form id="forgotOtpForm">
                                <div class="text-center mb-3">
                                    <p class="text-muted small mb-0" id="forgotOtpDesc">Enter the 6-digit code</p>
                                </div>
                                <div class="mb-3">
                                    <input type="text" name="otp" class="form-control rounded-3 text-center fw-bold" style="font-size:1.5rem; letter-spacing:8px;" maxlength="6" placeholder="000000" required autocomplete="off" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'')">
                                </div>
                                <div class="alert alert-danger d-none small mb-3" id="forgotOtpError"></div>
                                <div class="alert alert-success d-none small mb-3" id="forgotOtpSuccess"></div>
                                <input type="hidden" name="csrf_token" id="csrfTokenForgot2">
                                <button type="submit" class="btn btn-premium w-100 py-2 mb-3" id="forgotOtpBtn">Verify Code</button>
                                <div class="text-center">
                                    <a href="#" class="text-decoration-none small fw-bold" id="resendOtpBtn">Resend Code</a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Step 3: Reset Password -->
                    <div id="forgotStep3" style="display:none;">
                        <form id="forgotResetForm">
                            <div class="text-center mb-3">
                                <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width:50px;height:50px;">
                                    <i class="fas fa-check"></i>
                                </div>
                                <p class="text-muted small mb-0">Code verified! Set your new password.</p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">New Password</label>
                                <div class="position-relative">
                                    <input type="password" name="new_password" id="forgotPassword" class="form-control rounded-3" required placeholder="Min 8 chars with A-Z, a-z, 0-9, special char">
                                    <span class="position-absolute top-50 end-0 translate-middle-y pe-3" style="cursor: pointer;" onclick="togglePassword('forgotPassword', this)">
                                        <i class="fas fa-eye text-muted"></i>
                                    </span>
                                </div>
                                <div class="mt-1">
                                    <div class="progress" style="height: 4px;">
                                        <div class="progress-bar" id="forgotPwStrengthBar" role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <small class="text-muted" id="forgotPwStrengthText"></small>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label small fw-bold">Confirm New Password</label>
                                <div class="position-relative">
                                    <input type="password" name="confirm_password" id="forgotConfirmPassword" class="form-control rounded-3" required placeholder="Repeat password">
                                    <span class="position-absolute top-50 end-0 translate-middle-y pe-3" style="cursor: pointer;" onclick="togglePassword('forgotConfirmPassword', this)">
                                        <i class="fas fa-eye text-muted"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="alert alert-danger d-none small mb-3" id="forgotResetError"></div>
                            <input type="hidden" name="csrf_token" id="csrfTokenForgot3">
                            <button type="submit" class="btn btn-premium w-100 py-2" id="forgotResetBtn">Reset Password</button>
                        </form>
                    </div>

                    <!-- Step 4: Success -->
                    <div id="forgotStep4" style="display:none;">
                        <div class="text-center py-3">
                            <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px;height:70px;">
                                <i class="fas fa-check fa-2x"></i>
                            </div>
                            <h5 class="fw-bold">Password Reset!</h5>
                            <p class="text-muted small mb-4">Your password has been updated. You can now login with your new password.</p>
                            <button class="btn btn-premium w-100 py-2" data-bs-dismiss="modal">Done</button>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="text-center">
                        <a href="#" class="text-decoration-none small fw-bold" data-bs-toggle="modal" data-bs-target="#loginModal" onclick="resetForgotModal(); bootstrap.Modal.getInstance(document.getElementById('forgotPwModal')).hide();">Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Register Modal -->
    <div class="modal fade" id="registerModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-body p-4 p-sm-5">
                    <!-- Step 1: Account details -->
                    <div id="regStep1">
                    <h3 class="fw-bold mb-1 text-center">Create Account</h3>
                    <p class="text-muted small text-center mb-4">Fill in your details below — it takes less than a minute.</p>
                    <form id="registerForm">
                        <div class="row g-3 mb-3">
                            <div class="col-12 col-sm-5">
                                <label class="form-label small fw-bold">First Name</label>
                                <input type="text" name="first_name" class="form-control rounded-3" required maxlength="50" placeholder="e.g. Juan" title="Letters, spaces, apostrophes and hyphens only" oninput="this.value=toTitleCaseName(this.value)">
                            </div>
                            <div class="col-12 col-sm-5">
                                <label class="form-label small fw-bold">Last Name</label>
                                <input type="text" name="last_name" class="form-control rounded-3" required maxlength="50" placeholder="e.g. Dela Cruz" title="Letters, spaces, apostrophes and hyphens only" oninput="this.value=toTitleCaseName(this.value)">
                            </div>
                            <div class="col-12 col-sm-2">
                                <label class="form-label small fw-bold">M.I.</label>
                                <input type="text" name="middle_initial" class="form-control rounded-3 text-uppercase" maxlength="3" placeholder="A" title="Middle initial — one letter (optional)" oninput="this.value=this.value.toUpperCase()">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Email</label>
                            <input type="email" name="email" class="form-control rounded-3" required maxlength="100" placeholder="e.g. name@example.com" title="Enter a valid email address (e.g. name@example.com)">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Phone Number</label>
                            <input type="tel" name="phone" class="form-control rounded-3" required inputmode="tel" maxlength="14" placeholder="e.g. 09568820066" title="Enter a valid PH mobile number (e.g. 09568820066)" oninput="this.value=this.value.replace(/[^\d+\s\-()]/g,'')">
                            <div class="form-text x-small">PH mobile format: 09XXXXXXXXX (e.g. 09568820066).</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Send verification code via</label>
                            <div class="d-flex flex-column flex-sm-row gap-2">
                                <div class="form-check border rounded-3 px-4 py-2 flex-fill">
                                    <input class="form-check-input" type="radio" name="otp_channel" id="regChannelSms" value="sms" checked>
                                    <label class="form-check-label small fw-bold" for="regChannelSms"><i class="fas fa-sms me-1"></i>SMS <span class="text-muted fw-normal">(Globe/TM)</span></label>
                                </div>
                                <div class="form-check border rounded-3 px-4 py-2 flex-fill">
                                    <input class="form-check-input" type="radio" name="otp_channel" id="regChannelEmail" value="email">
                                    <label class="form-check-label small fw-bold" for="regChannelEmail"><i class="fas fa-envelope me-1"></i>Email</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Home Address</label>
                            <textarea name="address" class="form-control rounded-3" rows="2" required maxlength="255" minlength="5" placeholder="e.g. Piat–Tuao–Pinukpuk Road, Tuao West, Cagayan Valley" title="Enter your complete home address (min. 5 characters)"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Password</label>
                            <div class="position-relative">
                                <input type="password" name="password" id="registerPassword" class="form-control rounded-3" required placeholder="Min 8 chars with A-Z, a-z, 0-9, special char">
                                <span class="position-absolute top-50 end-0 translate-middle-y pe-3" style="cursor: pointer;" onclick="togglePassword('registerPassword', this)">
                                    <i class="fas fa-eye text-muted"></i>
                                </span>
                            </div>
                            <div class="mt-1">
                                <div class="progress" style="height: 4px;">
                                    <div class="progress-bar" id="regPwStrengthBar" role="progressbar" style="width: 0%"></div>
                                </div>
                                <small class="text-muted" id="regPwStrengthText"></small>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold">Security Question</label>
                            <select name="security_question" class="form-select rounded-3">
                                <option>What is your mother's maiden name?</option>
                                <option>What was your first pet's name?</option>
                                <option>In what city were you born?</option>
                            </select>
                            <input type="text" name="security_answer" class="form-control mt-2 rounded-3" placeholder="Your Answer" required>
                        </div>
                        <div class="terms-consent-box mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="fw-bold mb-0"><i class="fas fa-file-contract text-primary me-2"></i>Terms & Conditions</h6>
                                <a href="#" class="text-decoration-none small fw-bold text-accent" data-bs-toggle="modal" data-bs-target="#termsModal">Read Full Terms</a>
                            </div>
                            <div class="border rounded-3 bg-white p-3" style="max-height: 140px; overflow-y: auto; font-size: 0.85rem; color: #495057;">
                                <ul class="ps-3 mb-0">
                                    <li>Rooms are rented <strong>per night</strong>; base price covers up to 5 guests (₱200/head from the 6th guest).</li>
                                    <li>Room and event reservations require a <strong>minimum 30% down payment</strong> before approval.</li>
                                    <li>All reservations are subject to review and approval by management.</li>
                                    <li>Down payments are <strong>non-refundable</strong> on client cancellation.</li>
                                </ul>
                            </div>
                        </div>
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" name="terms_agree" id="registerTermsAgree" value="1" required>
                            <label class="form-check-label small" for="registerTermsAgree">
                                I have read and agree to the <a href="#" class="text-decoration-none fw-bold text-accent" data-bs-toggle="modal" data-bs-target="#termsModal">Terms & Conditions</a>.
                            </label>
                        </div>
                        <input type="hidden" name="csrf_token" id="csrfTokenRegister">
                        <button type="submit" id="registerSubmitBtn" class="btn btn-premium w-100 py-3 mt-2" disabled>Send Verification Code</button>
                    </form>
                    </div><!-- /regStep1 -->

                    <!-- Step 2: Phone OTP verification -->
                    <div id="regStep2" style="display:none;">
                        <div class="text-center mb-4">
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:70px;height:70px;">
                                <i class="fas fa-mobile-alt fa-2x"></i>
                            </div>
                            <h3 class="fw-bold">Verify Your Phone</h3>
                            <p class="text-muted small mb-0" id="regOtpDesc">Enter the 6-digit code we sent you.</p>
                        </div>
                        <form id="registerOtpForm">
                            <div class="mb-3">
                                <input type="text" name="otp" class="form-control rounded-3 text-center fw-bold" style="font-size:1.5rem; letter-spacing:8px;" maxlength="6" placeholder="000000" required autocomplete="off" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'')">
                            </div>
                            <div class="alert alert-danger d-none small mb-3" id="regOtpError"></div>
                            <div class="alert alert-success d-none small mb-3" id="regOtpSuccess"></div>
                            <input type="hidden" name="csrf_token" id="csrfTokenRegOtp">
                            <button type="submit" class="btn btn-premium w-100 py-2" id="regOtpBtn">Verify & Create Account</button>
                            <div class="d-flex justify-content-between mt-3">
                                <a href="#" class="text-decoration-none small fw-bold" id="regBackBtn">← Edit details</a>
                                <a href="#" class="text-decoration-none small fw-bold" id="regResendOtpBtn">Resend Code</a>
                            </div>
                        </form>
                    </div><!-- /regStep2 -->
                </div>
            </div>
        </div>
    </div>

    <!-- My Reservation Modal -->
    <div class="modal fade" id="myReservationModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-primary text-white p-4">
                    <h5 class="modal-title fw-bold"><i class="fas fa-list-check me-2"></i> My Reservations</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 p-lg-5">
                    <?php if (empty($reservations)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-calendar-times fa-4x text-muted opacity-25 mb-4"></i>
                            <h4 class="fw-bold">No Active Reservations</h4>
                            <p class="text-muted">You haven't made any bookings yet. Browse our rooms to get started!</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Type</th>
                                        <th>Room/Event</th>
                                        <th>Check-in</th>
                                        <th>Guests</th>
                                        <th>Total Price</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reservations as $res): ?>
                                        <tr>
                                            <td class="small fw-bold text-muted">#<?php echo str_pad($res['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                            <td><span class="badge bg-light text-primary"><?php echo ($res['booking_type'] === 'event') ? 'Room event' : ucfirst($res['booking_type']); ?></span></td>
                                            <td class="fw-bold">
                                                <?php 
                                                    if ($res['booking_type'] == 'room') {
                                                        echo $res['room_type'] ?? "<span class='text-danger'>Room #".$res['item_id']." (Not Found)</span>";
                                                    } else {
                                                        echo $res['package_name'] ?? "<span class='text-danger'>Package #".$res['item_id']."</span>";
                                                    }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    echo date('M d, Y', strtotime($res['check_in_date'])); 
                                                    if ($res['booking_type'] == 'room' && !empty($res['check_out_date']) && $res['check_out_date'] !== $res['check_in_date']) {
                                                        $n_nights = max(1, (int)round((strtotime($res['check_out_date']) - strtotime($res['check_in_date'])) / 86400));
                                                        echo "<br><small class='text-muted'>to " . date('M d, Y', strtotime($res['check_out_date'])) . " (" . $n_nights . " night" . ($n_nights > 1 ? 's' : '') . ")</small>";
                                                    }
                                                ?>
                                            </td>
                                            <td>
                                                <?php echo $res['guest_count']; ?> Pax
                                                <?php if (!empty($reservation_breakdown[(int)$res['id']])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars(implode(', ', $reservation_breakdown[(int)$res['id']])); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold text-primary">₱<?php echo number_format($res['total_price'], 2); ?></td>
                                            <td>
                                                <?php 
                                                $status_class = match($res['status']) {
                                                    'Approved' => 'bg-success',
                                                    'Pending Approval' => 'bg-warning text-dark',
                                                    'Rejected' => 'bg-danger',
                                                    default => 'bg-secondary'
                                                };
                                                ?>
                                                <span class="badge <?php echo $status_class; ?> rounded-pill"><?php echo $res['status']; ?></span>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="uploadPaymentFor(<?php echo $res['id']; ?>)">
                                                        <i class="fas fa-file-invoice-dollar"></i> Upload Payment
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-secondary" onclick="viewReservationDetail(<?php echo $res['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View Details
                                                    </button>
                                                    <?php if ($res['status'] === 'Completed' && empty($res['review_id'])): ?>
                                                    <a class="btn btn-sm btn-warning" href="review.php?res_id=<?php echo $res['id']; ?>">
                                                        <i class="fas fa-star"></i> Rate your stay
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Terms & Conditions Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-header border-0 pb-0">
                    <h4 class="modal-title fw-bold" style="color: var(--primary);"><i class="fas fa-file-contract me-2"></i>Terms & Conditions</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small">Please read these terms carefully before booking. By proceeding with a reservation, you agree to the following conditions.</p>
                    <h6 class="fw-bold mt-3"><i class="fas fa-bed text-accent me-2"></i>Room Rentals</h6>
                    <ul class="small text-muted ps-4">
                        <li>Rooms are rented <strong>per room</strong>, not per hour.</li>
                        <li>Room pricing is calculated <strong>per night</strong> based on your selected check-in and check-out dates.</li>
                        <li>The number of available rooms is <strong>fixed</strong>. A date is considered unavailable once the room has a confirmed or pending booking.</li>
                        <li>Base room price covers up to <strong>5 guests</strong>. An additional charge of ₱200 per head applies from the 6th guest onwards.</li>
                        <li>Check-in and check-out times are strictly enforced. Late check-out may be subject to additional charges.</li>
                        <li>Guests are responsible for any damage caused to the room and its amenities.</li>
                    </ul>
                    <h6 class="fw-bold mt-3"><i class="fas fa-calendar-check text-accent me-2"></i>Event Bookings</h6>
                    <ul class="small text-muted ps-4">
                        <li>Event reservations require a <strong>minimum of 30% down payment</strong> before the reservation can be approved.</li>
                        <li>Full payment of the remaining balance is required on or before the event date.</li>
                        <li>Down payments are non-refundable in case of cancellation by the client.</li>
                        <li>Guest count must not exceed the selected package's maximum capacity.</li>
                    </ul>
                    <h6 class="fw-bold mt-3"><i class="fas fa-shield-alt text-accent me-2"></i>General</h6>
                    <ul class="small text-muted ps-4">
                        <li>All reservations are subject to review and approval by management.</li>
                        <li>Management reserves the right to reject or cancel a reservation for valid reasons.</li>
                        <li>Smoking is strictly prohibited inside the rooms. Violations may incur penalties.</li>
                        <li>Pets are not allowed inside the rooms unless previously arranged.</li>
                        <li>By booking, you agree to receive SMS notifications regarding your reservation.</li>
                    </ul>
                    <div class="alert bg-accent-soft text-primary small border-0 py-3 mt-3 mb-0">
                        <i class="fas fa-info-circle me-2"></i> For questions or clarifications, please contact us at <strong>09568820066</strong>.
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button class="btn btn-premium w-100 py-2" data-bs-dismiss="modal">I Understand</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Event Rules / Terms & Conditions Modal -->
    <div class="modal fade" id="eventTermsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-header border-0 pb-0">
                    <h4 class="modal-title fw-bold" style="color: var(--primary);"><i class="fas fa-gavel me-2"></i>Rules of Events</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small">Please read these rules carefully. By submitting an event reservation, you agree to the following.</p>
                    <h6 class="fw-bold mt-3"><i class="fas fa-money-bill-wave text-accent me-2"></i>Payment Policy</h6>
                    <ul class="small text-muted ps-4">
                        <li>A <strong>minimum of 30% down payment</strong> of the package price is required before the reservation can be approved.</li>
                        <li>You may pay your down payment online (GCash / Bank Transfer) and upload proof, or choose <strong>Pay Upon Arrival</strong> and settle the 30% in person at the venue.</li>
                        <li>The <strong>full balance</strong> must be settled on or before the event date.</li>
                        <li>Down payments are <strong>non-refundable</strong> in case of cancellation by the client.</li>
                        <li>Unpaid balance may be subject to a 24-hour hold before the event date.</li>
                    </ul>
                    <h6 class="fw-bold mt-3"><i class="fas fa-users text-accent me-2"></i>Event Requirements</h6>
                    <ul class="small text-muted ps-4">
                        <li>Guest count must not exceed the selected package's maximum capacity.</li>
                        <li>Any additional guest beyond capacity must be coordinated with management in advance.</li>
                        <li>Event time includes setup and cleanup. Overtime may incur additional charges.</li>
                    </ul>
                    <h6 class="fw-bold mt-3"><i class="fas fa-clipboard-check text-accent me-2"></i>Venue Rules</h6>
                    <ul class="small text-muted ps-4">
                        <li>No nails, staples, or permanent fixtures may be used on walls and ceilings for decorations.</li>
                        <li>Fireworks, pyrotechnics, and open flames are strictly prohibited.</li>
                        <li>The client is responsible for the behavior of guests and for any damage caused during the event.</li>
                        <li>Smoking is prohibited inside the venue.</li>
                        <li>The venue must be cleaned by the client or a cleanup fee will be applied.</li>
                        <li>Management reserves the right to stop any activity that endangers the venue or its guests.</li>
                    </ul>
                    <h6 class="fw-bold mt-3"><i class="fas fa-calendar-times text-accent me-2"></i>Cancellation & Rescheduling</h6>
                    <ul class="small text-muted ps-4">
                        <li>Cancellations after the down payment is made are non-refundable.</li>
                        <li>Rescheduling is subject to availability and must be requested at least 48 hours before the event date.</li>
                        <li>Reservations are subject to review and final approval by management.</li>
                    </ul>
                    <div class="alert bg-accent-soft text-primary small border-0 py-3 mt-3 mb-0">
                        <i class="fas fa-info-circle me-2"></i> For clarifications, contact us at <strong>09568820066</strong>.
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button class="btn btn-premium w-100 py-2" data-bs-dismiss="modal">I Understand</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Booking Modal -->
    <div class="modal fade" id="newBookingModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-header border-0 pb-0">
                    <h4 class="modal-title fw-bold" style="color: var(--primary);">Reserve This Room</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="mainBookingForm" action="api/make_reservation.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="type" value="room">
                        <input type="hidden" name="item_id" id="bookingItemId" value="">
                        <input type="hidden" id="bookingItemBasePrice" value="0">
                        <input type="hidden" name="csrf_token" id="csrfTokenBooking" value="">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Guest Name</label>
                            <input type="text" class="form-control" value="<?php echo $userName; ?>" disabled>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Contact Number</label>
                            <input type="text" class="form-control" value="<?php echo $userPhone; ?>">
                            <div class="form-text small">Change this in your <a href="#" data-bs-toggle="modal" data-bs-target="#profileModal" class="text-decoration-none fw-bold">Account Settings</a> if incorrect.</div>
                        </div>
                        
                        <div class="row g-3 mb-3">
                            <div class="col-md-6 col-sm-6 col-12">
                                <label class="form-label small fw-bold">Check-in Date</label>
                                <input type="text" name="check_in" id="checkInDate" class="form-control bg-white" placeholder="Select Check-in Date" required readonly>
                                <div class="form-text x-small text-danger"><i class="fas fa-circle me-1"></i>Red dates unavailable.</div>
                            </div>
                            <div class="col-md-6 col-sm-6 col-12">
                                <label class="form-label small fw-bold">Check-out Date</label>
                                <input type="text" name="check_out" id="checkOutDate" class="form-control bg-white" placeholder="Select Check-out Date" required readonly>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6 col-sm-6 col-12">
                                <label class="form-label small fw-bold">Stay Duration</label>
                                <select id="stayDurationSelect" class="form-select" onchange="onDurationSelectChange()">
                                    <?php for($i=1; $i<=30; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?> Night<?php echo $i > 1 ? 's' : ''; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-6 col-sm-6 col-12">
                                <label class="form-label small fw-bold">Guest Breakdown</label>
                                <div class="row g-2">
                                    <?php foreach ($active_guest_types as $gt): ?>
                                    <div class="col-6">
                                        <label class="form-label x-small text-muted mb-1" for="roomGuestType<?php echo (int)$gt['id']; ?>"><?php echo htmlspecialchars($gt['type_name']); ?></label>
                                        <input type="number" id="roomGuestType<?php echo (int)$gt['id']; ?>"
                                               class="form-control form-control-sm guest-breakdown-input"
                                               name="guest_breakdown[<?php echo (int)$gt['id']; ?>]"
                                               data-guest-type="<?php echo (int)$gt['id']; ?>"
                                               value="0" min="0" max="20" step="1"
                                               oninput="syncGuestTotal('room')" onchange="syncGuestTotal('room')">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="guests" id="guestCountInput" value="0">
                                <div class="form-text x-small">Total: <strong id="roomGuestTotal">0</strong> pax <span id="roomGuestHint"></span></div>
                            </div>
                        </div>

                        <div class="bg-light p-3 rounded-3 mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold">Estimated Total:</span>
                                <span class="h4 m-0 text-primary fw-800">₱<span id="realTimeTotal">0.00</span></span>
                            </div>
                            <div class="small text-muted text-end" id="realTimeBreakdown">₱0.00 / night × 1 night</div>
                        </div>

                        <div class="alert bg-accent-soft text-primary small border-0 py-3 mb-4">
                            <i class="fas fa-info-circle me-2"></i> Note: Base price covers up to 5 guests. An additional ₱200/head applies for the 6th guest onwards per night.
                        </div>

                        <div class="alert alert-warning small border-0 py-3 mb-4">
                            <i class="fas fa-money-bill-wave me-2"></i> A <strong>minimum of 30% down payment</strong> is required before your room reservation can be approved.
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold">Down Payment Amount (₱)</label>
                                <input type="number" name="down_payment" id="roomDownPayment" class="form-control" min="0" step="0.01" required>
                                <div class="form-text x-small text-danger" id="roomDownPaymentHint"></div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold">How will you pay the down payment?</label>
                                <select name="method" id="roomPaymentMethod" class="form-select" required>
                                    <option value="">-- Choose a payment method --</option>
                                    <?php foreach ($active_methods as $pm): ?>
                                    <option value="<?php echo htmlspecialchars($pm['method_name']); ?>">
                                        <?php echo htmlspecialchars($pm['method_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text x-small">Online payments require proof. "Pay Upon Arrival" = settle the 30% at the venue.</div>
                            </div>
                        </div>

                        <!-- Dynamic method info (online = QR/instructions + proof; arrival = no proof) -->
                        <div id="roomMethodDetails" class="mb-4" style="display:none;"></div>
                        <div id="roomProofUpload" class="mb-4" style="display:none;">
                            <label class="form-label small fw-bold">Upload Down Payment Proof</label>
                            <input type="file" name="down_payment_proof" id="roomDownPaymentProof" class="form-control" accept="image/*,application/pdf">
                            <div class="form-text x-small">Screenshot or photo of your GCash/bank transfer reference.</div>
                        </div>

                        <button type="submit" class="btn btn-premium w-100 py-3">Confirm Reservation Request</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Booking Confirmation Summary Modal -->
    <div class="modal fade" id="bookingConfirmModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-primary text-white p-4">
                    <h5 class="modal-title fw-bold"><i class="fas fa-check-circle me-2"></i> Confirm Your Booking</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center mb-4">
                        <div class="display-6 fw-bold text-primary mb-1">₱<span id="confirmTotal">0.00</span></div>
                        <p class="text-muted small">Final Booking Summary</p>
                    </div>
                    
                    <div class="list-group list-group-flush rounded-3 border mb-4">
                        <div class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted" id="confirmUnitLabel"><i class="fas fa-bed me-2"></i> Room Type:</span>
                            <span class="fw-bold" id="confirmRoom">Family Suite</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted" id="confirmDateInLabel"><i class="fas fa-calendar-alt me-2"></i> Check-in Date:</span>
                            <span class="fw-bold" id="confirmDate">June 25, 2026</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted" id="confirmDateOutLabel"><i class="fas fa-calendar-check me-2"></i> Check-out Date:</span>
                            <span class="fw-bold" id="confirmCheckOutDate">June 26, 2026</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted" id="confirmDurationLabel"><i class="fas fa-moon me-2"></i> Stay Duration:</span>
                            <span class="fw-bold" id="confirmDuration">1 Night(s)</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted"><i class="fas fa-users me-2"></i> Guest Count:</span>
                            <span class="fw-bold" id="confirmGuests">1 Person</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted"><i class="fas fa-venus-mars me-2"></i> Guest Composition:</span>
                            <span class="fw-bold text-end" id="confirmGuestBreakdown">—</span>
                        </div>
                    </div>

                    <div class="alert bg-light border-0 small mb-4">
                        <i class="fas fa-shield-alt text-primary me-2"></i> Your request will be sent to the admin for approval. You will receive a notification once reviewed.
                    </div>

                    <div class="terms-consent-box mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0" id="confirmTermsTitle"><i class="fas fa-file-contract text-primary me-2"></i>Terms & Conditions</h6>
                            <a href="#" class="text-decoration-none small fw-bold text-accent" id="confirmTermsReadLink" data-bs-toggle="modal" data-bs-target="#termsModal">Read Full Terms</a>
                        </div>
                        <div class="border rounded-3 bg-white p-3" style="max-height: 130px; overflow-y: auto; font-size: 0.8rem; color: #495057;">
                            <ul class="ps-3 mb-0" id="confirmTermsList">
                                <li>A minimum of <strong>30% down payment</strong> is required before the reservation is accepted.</li>
                                <li>Room pricing is calculated <strong>per night</strong> based on selected check-in and check-out dates.</li>
                                <li>The number of rooms is <strong>fixed</strong>. A date is unavailable once the room has a pending or confirmed booking.</li>
                                <li>Base price covers up to 5 guests. Additional ₱200/head applies from the 6th guest onwards per night.</li>
                                <li>All reservations are subject to review and approval by management.</li>
                                <li>Down payments are non-refundable on client cancellation.</li>
                                <li>Guests are responsible for any damage caused to the room.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" id="termsAgree" required>
                        <label class="form-check-label small" for="termsAgree">
                            I have read and agree to the <a href="#" class="text-decoration-none fw-bold text-accent" id="confirmTermsAgreeLink" data-bs-toggle="modal" data-bs-target="#termsModal">Terms & Conditions</a>.
                        </label>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <button class="btn btn-light w-100 py-3 rounded-pill" id="confirmGoBackBtn">Go Back</button>
                        </div>
                        <div class="col-6">
                            <button class="btn btn-premium w-100 py-3 rounded-pill" id="finalConfirmBtn" disabled>
                                <i class="fas fa-paper-plane me-2"></i> Confirm Now
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Thank You / Success Modal -->
    <div class="modal fade" id="thankYouModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg text-center p-4 p-sm-5">
                <div class="mb-4">
                    <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm" style="width:100px; height:100px;">
                        <i class="fas fa-check fa-3x"></i>
                    </div>
                </div>
                <h2 class="fw-800 mb-2" style="color: var(--primary);">Thank You!</h2>
                <p class="text-muted mb-4" id="thankYouMessage">Your request has been successfully submitted. Our team will review it shortly.</p>
                
                <div class="bg-light p-3 rounded-4 mb-4 small text-start shadow-sm">
                    <div class="d-flex mb-2">
                        <i class="fas fa-bell text-primary me-3 mt-1"></i>
                        <span>Track your status via the <b>Notification Bell</b> in the navbar.</span>
                    </div>
                    <div class="d-flex">
                        <i class="fas fa-clock text-primary me-3 mt-1"></i>
                        <span>Most requests are reviewed within 24 hours.</span>
                    </div>
                </div>

                <button class="btn btn-premium w-100 py-3 rounded-pill" data-bs-dismiss="modal">Back to Home</button>
            </div>
        </div>
    </div>

    <!-- Room Detail Modular -->
    <div class="modal fade" id="roomDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg overflow-hidden">
                <div class="row g-0">
                    <div class="col-md-5 col-12">
                        <div id="roomGalleryCarousel" class="carousel slide h-100" data-bs-touch="true" data-bs-interval="false">
                            <div class="carousel-indicators" id="roomGalleryIndicators"></div>
                            <div class="carousel-inner h-100" id="roomGalleryInner"></div>
                            <button class="carousel-control-prev" type="button" data-bs-target="#roomGalleryCarousel" data-bs-slide="prev" id="roomGalleryPrev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Previous photo</span>
                            </button>
                            <button class="carousel-control-next" type="button" data-bs-target="#roomGalleryCarousel" data-bs-slide="next" id="roomGalleryNext">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Next photo</span>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-7 col-12">
                        <div class="modal-header border-0 pb-0">
                            <h3 class="modal-title fw-800" id="modalRoomTitle" style="color: var(--primary);"></h3>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <h4 class="mb-3" style="color: var(--accent);">₱<span id="modalRoomPrice"></span> <small class="text-muted fs-6 fw-normal">/ night</small></h4>
                            <p class="text-muted mb-4" id="modalRoomDesc"></p>
                            
                            <h6 class="fw-bold mb-3">Amenities Included:</h6>
                            <ul class="list-unstyled row g-2 mb-4" id="modalRoomAmenities">
                                <!-- Amenities will be injected here -->
                            </ul>

                            <h6 class="fw-bold mb-2">Guest Reviews <span class="text-muted small fw-normal" id="modalRoomReviewCount"></span></h6>
                            <div id="modalRoomReviews" class="mb-4"></div>

                            <div class="d-grid gap-2">
                                <?php if ($isLoggedIn): ?>
                                    <button class="btn btn-premium py-3" data-bs-toggle="modal" data-bs-target="#newBookingModal">
                                        <i class="fas fa-calendar-alt me-2"></i> Confirm Selection & Book
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-premium py-3" data-bs-toggle="modal" data-bs-target="#loginModal">
                                        <i class="fas fa-lock me-2"></i> Login to Reserve
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-outline-secondary py-2" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Package Detail Modular -->
    <div class="modal fade" id="packageDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg overflow-hidden">
                <div class="row g-0">
                    <div class="col-md-5 col-12">
                        <div id="pkgGalleryCarousel" class="carousel slide h-100" data-bs-touch="true" data-bs-interval="false">
                            <div class="carousel-indicators" id="pkgGalleryIndicators"></div>
                            <div class="carousel-inner h-100" id="pkgGalleryInner"></div>
                            <button class="carousel-control-prev" type="button" data-bs-target="#pkgGalleryCarousel" data-bs-slide="prev" id="pkgGalleryPrev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Previous photo</span>
                            </button>
                            <button class="carousel-control-next" type="button" data-bs-target="#pkgGalleryCarousel" data-bs-slide="next" id="pkgGalleryNext">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Next photo</span>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-7 col-12">
                        <div class="modal-header border-0 pb-0">
                            <h3 class="modal-title fw-800" id="modalPkgTitle" style="color: var(--primary);"></h3>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <h4 class="mb-3" style="color: var(--accent);">₱<span id="modalPkgPrice"></span> <small class="text-muted fs-6 fw-normal">/ base package</small></h4>
                            <p class="text-muted mb-3" id="modalPkgDesc"></p>
                            
                            <div class="alert bg-light border-0 py-2 small mb-4">
                                <i class="fas fa-users me-2"></i> <strong>Maximum Capacity:</strong> <span id="modalPkgCapacity"></span> Pax
                            </div>

                            <h6 class="fw-bold mb-3">Services Included:</h6>
                            <ul class="list-unstyled row g-2 mb-4" id="modalPkgServices">
                                <!-- Services will be injected here -->
                            </ul>

                            <h6 class="fw-bold mb-2">Guest Reviews <span class="text-muted small fw-normal" id="modalPkgReviewCount"></span></h6>
                            <div id="modalPkgReviews" class="mb-4"></div>

                            <div class="d-grid gap-2">
                                <?php if ($isLoggedIn): ?>
                                    <button class="btn btn-premium py-3" data-bs-toggle="modal" data-bs-target="#newEventBookingModal">
                                        <i class="fas fa-calendar-check me-2"></i> Confirm Package & Plan Event
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-premium py-3" data-bs-toggle="modal" data-bs-target="#loginModal">
                                        <i class="fas fa-lock me-2"></i> Login to Plan Event
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-outline-secondary py-2" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Event Booking Modal -->
    <div class="modal fade" id="newEventBookingModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0 p-4">
                    <h4 class="modal-title fw-bold" style="color: var(--primary);">Event Reservation</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="eventBookingForm" action="api/make_reservation.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="type" value="event">
                        <input type="hidden" name="item_id" id="eventItemId" value="">
                        <input type="hidden" id="eventItemBasePrice" value="0">
                        <input type="hidden" id="eventMaxCapacity" value="0">
                        <input type="hidden" name="csrf_token" id="csrfTokenEventBooking" value="">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Host Name</label>
                            <input type="text" class="form-control" value="<?php echo $userName; ?>" disabled>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Contact Number</label>
                            <input type="text" class="form-control" value="<?php echo $userPhone; ?>">
                            <div class="form-text small">Change this in your <a href="#" data-bs-toggle="modal" data-bs-target="#profileModal" class="text-decoration-none fw-bold">Account Settings</a> if incorrect.</div>
                        </div>
                        
                        <div class="row g-3 mb-4">
                            <div class="col-md-6 col-sm-6 col-12">
                                <label class="form-label small fw-bold">Event Start Date</label>
                                <input type="text" name="check_in" id="eventStartDate" class="form-control bg-white" placeholder="Select Start Date" required readonly>
                                <div class="form-text x-small text-danger"><i class="fas fa-circle me-1"></i>Dates in red are unavailable.</div>
                            </div>
                            <div class="col-md-6 col-sm-6 col-12">
                                <label class="form-label small fw-bold">Event End Date</label>
                                <input type="text" name="check_out" id="eventEndDate" class="form-control bg-white" placeholder="Select End Date" required readonly>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6 col-sm-6 col-12">
                                <label class="form-label small fw-bold">Event Duration</label>
                                <div class="form-control bg-light" id="eventDurationDisplay">1 Day(s)</div>
                                <input type="hidden" name="event_days" id="eventDaysInput" value="1">
                            </div>
                            <div class="col-md-6 col-sm-6 col-12">
                                <label class="form-label small fw-bold">Guest Breakdown</label>
                                <div class="row g-2">
                                    <?php foreach ($active_guest_types as $gt): ?>
                                    <div class="col-6">
                                        <label class="form-label x-small text-muted mb-1" for="eventGuestType<?php echo (int)$gt['id']; ?>"><?php echo htmlspecialchars($gt['type_name']); ?></label>
                                        <input type="number" id="eventGuestType<?php echo (int)$gt['id']; ?>"
                                               class="form-control form-control-sm guest-breakdown-input"
                                               name="guest_breakdown[<?php echo (int)$gt['id']; ?>]"
                                               data-guest-type="<?php echo (int)$gt['id']; ?>"
                                               value="0" min="0" step="1"
                                               oninput="syncGuestTotal('event')" onchange="syncGuestTotal('event')">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="guests" id="eventGuestInput" value="0">
                                <div class="form-text x-small">Total: <strong id="eventGuestTotal">0</strong> pax <span id="capacityHint">Must not exceed package capacity.</span></div>
                            </div>
                        </div>

                        <div class="bg-light p-3 rounded-3 mb-4 d-flex justify-content-between align-items-center border">
                            <span class="fw-bold">Package Price:</span>
                            <span class="h4 m-0 text-primary fw-800">₱<span id="eventRealTimeTotal">0.00</span></span>
                        </div>

                        <div class="alert alert-warning small border-0 py-3 mb-4">
                            <i class="fas fa-money-bill-wave me-2"></i> A <strong>minimum of 30% down payment</strong> is required before your event reservation can be approved.
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold">Down Payment Amount (₱)</label>
                                <input type="number" name="down_payment" id="eventDownPayment" class="form-control" min="0" step="0.01" required>
                                <div class="form-text x-small text-danger" id="eventDownPaymentHint"></div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold">How will you pay the down payment?</label>
                                <select name="method" id="eventPaymentMethod" class="form-select" required>
                                    <option value="">-- Choose a payment method --</option>
                                    <?php foreach ($active_methods as $pm): ?>
                                    <option value="<?php echo htmlspecialchars($pm['method_name']); ?>">
                                        <?php echo htmlspecialchars($pm['method_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text x-small">Online payments require proof. "Pay Upon Arrival" = settle the 30% at the venue.</div>
                            </div>
                        </div>

                        <!-- Dynamic method info (online = QR/instructions + proof; arrival = no proof) -->
                        <div id="eventMethodDetails" class="mb-4" style="display:none;"></div>
                        <div id="eventProofUpload" class="mb-4" style="display:none;">
                            <label class="form-label small fw-bold">Upload Down Payment Proof</label>
                            <input type="file" name="down_payment_proof" id="eventDownPaymentProof" class="form-control" accept="image/*,application/pdf">
                            <div class="form-text x-small">Screenshot or photo of your GCash/bank transfer reference.</div>
                        </div>

                        <!-- Event Rules Consent -->
                        <div class="border rounded-3 p-3 mb-4 bg-white">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="fw-bold mb-0"><i class="fas fa-gavel text-primary me-2"></i>Rules of Events</h6>
                                <a href="#" class="text-decoration-none small fw-bold text-accent" data-bs-toggle="modal" data-bs-target="#eventTermsModal">Read Full Rules</a>
                            </div>
                            <div class="border rounded-3 bg-white p-3 mb-3" style="max-height: 110px; overflow-y: auto; font-size: 0.8rem; color: #495057;">
                                <ul class="ps-3 mb-0">
                                    <li>A minimum of <strong>30% down payment</strong> is required before the reservation is accepted.</li>
                                    <li>Full balance must be settled on or before the event date.</li>
                                    <li>Down payments are non-refundable on client cancellation.</li>
                                    <li>Guest count must not exceed the package capacity.</li>
                                </ul>
                            </div>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" id="eventTermsAgree" required>
                                <label class="form-check-label small" for="eventTermsAgree">
                                    I have read and agree to the <a href="#" class="text-decoration-none fw-bold text-accent" data-bs-toggle="modal" data-bs-target="#eventTermsModal">Rules of Events</a>.
                                </label>
                            </div>
                        </div>

                        <button type="submit" id="eventSubmitBtn" class="btn btn-premium w-100 py-3 rounded-pill fw-bold" disabled>Submit Event Proposal</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Detail Modal -->
    <div class="modal fade" id="notificationDetailModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg overflow-hidden" style="border-radius: 20px;">
                <div id="notifHeader" class="p-4 text-white d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                        <i id="notifIcon" class="fas fa-bell fa-lg"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0">System Notification</h5>
                        <span id="notifDate" class="x-small opacity-75"></span>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 p-sm-5">
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold text-uppercase">Message Detail</label>
                        <p id="notifMessage" class="fs-6 fs-sm-5 text-dark lh-base fw-medium text-break"></p>
                    </div>
                    <div class="mt-5 pt-4 border-top">
                        <button class="btn btn-premium w-100 py-3 rounded-pill" data-bs-dismiss="modal">I Understand</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- My Reservation Detail Modal (filled by viewReservationDetail) -->
    <div class="modal fade" id="reservationDetailModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-primary text-white p-3 p-sm-4">
                    <h5 class="modal-title fw-bold mb-0"><i class="fas fa-receipt me-2"></i>Booking <span id="resDetailId"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center mb-3">
                        <span id="resDetailStatus" class="badge rounded-pill bg-secondary">—</span>
                    </div>
                    <div class="list-group list-group-flush rounded-3 border mb-4">
                        <div class="list-group-item d-flex justify-content-between gap-3 py-2"><span class="text-muted small">Type</span><span class="fw-bold small text-end" id="resDetailType">—</span></div>
                        <div class="list-group-item d-flex justify-content-between gap-3 py-2"><span class="text-muted small">Room / Package</span><span class="fw-bold small text-end" id="resDetailUnit">—</span></div>
                        <div class="list-group-item d-flex justify-content-between gap-3 py-2"><span class="text-muted small">Check-in</span><span class="fw-bold small text-end" id="resDetailCheckIn">—</span></div>
                        <div class="list-group-item d-flex justify-content-between gap-3 py-2"><span class="text-muted small">Check-out</span><span class="fw-bold small text-end" id="resDetailCheckOut">—</span></div>
                        <div class="list-group-item d-flex justify-content-between gap-3 py-2"><span class="text-muted small">Guests</span><span class="fw-bold small text-end" id="resDetailGuests">—</span></div>
                        <div class="list-group-item d-flex justify-content-between gap-3 py-2"><span class="text-muted small">Guest Composition</span><span class="fw-bold small text-end" id="resDetailGuestBreakdown">—</span></div>
                        <div class="list-group-item d-flex justify-content-between gap-3 py-2"><span class="text-muted small">Total Price</span><span class="fw-bold small text-end text-primary" id="resDetailTotal">—</span></div>
                        <div class="list-group-item d-flex justify-content-between gap-3 py-2"><span class="text-muted small">Payment</span><span class="fw-bold small text-end" id="resDetailPay">—</span></div>
                        <div class="list-group-item d-flex justify-content-between gap-3 py-2"><span class="text-muted small">Method</span><span class="fw-bold small text-end" id="resDetailMethod">—</span></div>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-premium w-100 py-2" id="resDetailPayBtn"><i class="fas fa-file-invoice-dollar me-2"></i>Upload Payment</button>
                        <button class="btn btn-light w-100 py-2" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Modals -->
    <?php foreach ($reservations as $res): ?>
    <div class="modal fade" id="paymentModal<?php echo $res['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Upload Payment for Booking #<?php echo str_pad($res['id'],5,'0',STR_PAD_LEFT); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Total Due -->
                    <div class="bg-light rounded-3 p-3 text-center mb-4">
                        <div class="text-muted small mb-1">Total Amount Due</div>
                        <div class="h3 fw-bold text-primary mb-0">₱<?php echo number_format($res['total_price'], 2); ?></div>
                    </div>

                    <form action="api/upload_payment.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="res_id" value="<?php echo $res['id']; ?>">

                        <!-- Payment Method Selector -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Select Payment Method</label>
                            <select name="method" class="form-select" id="methodSelect<?php echo $res['id']; ?>"
                                onchange="showMethodDetails(this, '<?php echo $res['id']; ?>')">
                                <option value="">-- Choose a method --</option>
                                <?php foreach ($active_methods as $pm): ?>
                                <option value="<?php echo htmlspecialchars($pm['method_name']); ?>">
                                    <?php echo htmlspecialchars($pm['method_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Dynamic Method Details Panel (JS-driven) -->
                        <div id="methodDetails<?php echo $res['id']; ?>" class="mb-4" style="display:none;">
                            <!-- JS fills this in -->
                        </div>

                        <!-- Proof Upload -->
                        <div id="proofUpload<?php echo $res['id']; ?>" class="mb-4">
                            <label class="form-label small fw-bold">Upload Payment Screenshot / Proof</label>
                            <input type="file" name="proof" id="proofInput<?php echo $res['id']; ?>" class="form-control" accept="image/*">
                            <div class="form-text">Screenshot of your GCash, bank transfer, or receipt.</div>
                        </div>

                        <button type="submit" id="submitBtn<?php echo $res['id']; ?>" class="btn btn-premium w-100 py-3 rounded-pill">
                            <i class="fas fa-paper-plane me-2"></i>Submit Payment for Review
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Payment Methods Data (embedded for JS) -->
    <script>
    const paymentMethodsData = <?php
        $pm_all = $pdo->query("SELECT * FROM payment_methods WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($pm_all);
    ?>;

    function showMethodDetails(selectEl, resId) {
        const selected = selectEl.value;
        const panel       = document.getElementById('methodDetails' + resId);
        const proofUpload = document.getElementById('proofUpload' + resId);
        const proofInput  = document.getElementById('proofInput' + resId);
        const submitBtn   = document.getElementById('submitBtn' + resId);

        if (!selected) {
            panel.style.display = 'none';
            panel.innerHTML = '';
            return;
        }

        const method = paymentMethodsData.find(m => m.method_name === selected);
        if (!method) { panel.style.display = 'none'; return; }

        // EXACT match only — prevents 'GCash' from matching 'cash'
        const isArrival = method.method_name.trim().toLowerCase() === 'pay upon arrival';

        if (isArrival) {
            // Hide proof upload, no screenshot required
            proofUpload.style.display = 'none';
            proofInput.required = false;
            submitBtn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Confirm Reservation (Pay at Arrival)';
        } else {
            // Show proof upload for online payments
            proofUpload.style.display = 'block';
            proofInput.required = true;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Submit Payment for Review';
        }

        // Build method info panel
        let html = `<div class="border rounded-3 p-3 ${isArrival ? 'bg-warning bg-opacity-10 border-warning' : 'bg-light'}">`;
        if (isArrival) {
            html += `<div class="fw-bold mb-1 text-warning"><i class="fas fa-concierge-bell me-2"></i>Pay Upon Arrival</div>`;
            html += `<p class="small mb-0">${method.account_details ? method.account_details.replace(/\n/g,'<br>') : 'Please proceed to the front desk upon check-in to settle your payment.'}</p>`;
        } else {
            html += `<div class="fw-bold mb-1"><i class="fas fa-info-circle me-2 text-primary"></i>${method.method_name} Instructions</div>`;
            if (method.account_details) {
                html += `<p class="small mb-2 text-muted">${method.account_details.replace(/\n/g,'<br>')}</p>`;
            }
            if (method.qr_code_url) {
                html += `<div class="text-center mt-2">
                            <img src="${method.qr_code_url}" alt="QR Code" style="max-height:160px; border-radius:10px; border:2px solid #ddd;">
                            <div class="small text-muted mt-1"><i class="fas fa-qrcode me-1"></i>Scan QR to pay, then upload screenshot above.</div>
                            <a href="api/download_qr.php?method_id=${method.id}" class="btn btn-sm btn-outline-primary mt-2" download>
                                <i class="fas fa-download me-1"></i>Download QR Code
                            </a>
                         </div>`;
            }
        }
        html += `</div>`;

        panel.innerHTML = html;
        panel.style.display = 'block';
    }
    </script>
    <!-- Profile Modal -->
    <?php if ($isLoggedIn): 
        $user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $user_stmt->execute([$_SESSION['user_id']]);
        $userData = $user_stmt->fetch();
    ?>
    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-primary text-white p-3 p-sm-4">
                    <h5 class="modal-title fw-bold"><i class="fas fa-user-cog me-2"></i> My Account Settings</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 p-sm-5">
                    <div class="row g-4 g-lg-5">
                        <!-- Profile Column -->
                        <div class="col-lg-6 border-end profile-col">
                            <h6 class="fw-bold text-uppercase small text-muted mb-4">Personal Information</h6>
                            <form id="updateProfileForm">
                                <div class="row g-3 mb-3">
                                    <div class="col-12">
                                        <label class="form-label small fw-bold">First Name</label>
                                        <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($userData['first_name'] ?? ''); ?>" required maxlength="50" oninput="this.value=toTitleCaseName(this.value)">
                                    </div>
                                    <div class="col-8">
                                        <label class="form-label small fw-bold">Last Name</label>
                                        <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($userData['last_name'] ?? ''); ?>" required maxlength="50" oninput="this.value=toTitleCaseName(this.value)">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold">M.I.</label>
                                        <input type="text" name="middle_initial" class="form-control text-uppercase" value="<?php echo htmlspecialchars($userData['middle_initial'] ?? ''); ?>" maxlength="3" placeholder="A" oninput="this.value=this.value.toUpperCase()">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Email Address</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo $userData['email']; ?>" disabled>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label small fw-bold">Phone Number</label>
                                    <input type="tel" name="phone" class="form-control" value="<?php echo $userData['phone']; ?>" required inputmode="tel" maxlength="14" placeholder="e.g. 09568820066" title="Enter a valid PH mobile number (e.g. 09568820066)" oninput="this.value=this.value.replace(/[^\d+\s\-()]/g,'')">
                                    <div class="form-text x-small">PH mobile format: 09XXXXXXXXX (e.g. 09568820066).</div>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label small fw-bold">Home Address</label>
                                    <textarea name="address" class="form-control" rows="2" required maxlength="255" minlength="5" placeholder="e.g. Piat–Tuao–Pinukpuk Road, Tuao West, Cagayan Valley"><?php echo htmlspecialchars($userData['address'] ?? ''); ?></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary w-100 py-2">Update Information</button>
                            </form>
                        </div>
                        
                        <!-- Password Column -->
                        <div class="col-lg-6">
                            <h6 class="fw-bold text-uppercase small text-muted mb-4">Security & Password</h6>
                            <form id="updatePasswordForm">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Current Password</label>
                                    <div class="position-relative">
                                        <input type="password" name="current_password" id="currentP" class="form-control" required>
                                        <span class="position-absolute top-50 end-0 translate-middle-y pe-3" style="cursor: pointer;" onclick="togglePassword('currentP', this)">
                                            <i class="fas fa-eye text-muted"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">New Password</label>
                                    <div class="position-relative">
                                        <input type="password" name="new_password" id="newP" class="form-control" required>
                                        <span class="position-absolute top-50 end-0 translate-middle-y pe-3" style="cursor: pointer;" onclick="togglePassword('newP', this)">
                                            <i class="fas fa-eye text-muted"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label small fw-bold">Confirm New Password</label>
                                    <div class="position-relative">
                                        <input type="password" name="confirm_password" id="confirmP" class="form-control" required>
                                        <span class="position-absolute top-50 end-0 translate-middle-y pe-3" style="cursor: pointer;" onclick="togglePassword('confirmP', this)">
                                            <i class="fas fa-eye text-muted"></i>
                                        </span>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-outline-primary w-100 py-2">Change Password</button>
                            </form>
                        </div>
                    </div>

                    <!-- Notification History Section -->
                    <div class="row mt-5 pt-4 border-top">
                        <div class="col-12">
                            <h6 class="fw-bold text-uppercase small text-muted mb-4"><i class="fas fa-history me-2"></i> Message History</h6>
                            <div class="notification-history-container" style="max-height: 350px; overflow-y: auto; padding-right: 5px;">
                                <?php if (empty($notifications)): ?>
                                    <div class="text-center py-4 bg-light rounded-3">
                                        <p class="text-muted small mb-0">No messages to display.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="list-group list-group-flush rounded-3 border">
                                        <?php foreach ($notifications as $h_notif): ?>
                                            <div class="list-group-item py-3 px-4" 
                                                 style="cursor: pointer; transition: 0.2s;" 
                                                 onmouseover="this.style.backgroundColor='#f8fafc'" 
                                                 onmouseout="this.style.backgroundColor='transparent'"
                                                 onclick="showNotification('<?php echo addslashes($h_notif['message']); ?>', '<?php echo date('M d, Y | g:i A', strtotime($h_notif['created_at'])); ?>', '<?php echo $h_notif['type'] ?? 'info'; ?>')">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <span class="badge bg-<?php echo $h_notif['type'] ?? 'info'; ?> bg-opacity-10 text-<?php echo $h_notif['type'] ?? 'info'; ?> x-small fw-bold border border-<?php echo $h_notif['type'] ?? 'info'; ?> border-opacity-25 px-2">
                                                        <?php echo strtoupper($h_notif['type'] ?? 'INFO'); ?>
                                                    </span>
                                                    <span class="x-small text-muted"><?php echo date('M d, Y | g:i A', strtotime($h_notif['created_at'])); ?></span>
                                                </div>
                                                <p class="mb-0 small text-dark fw-semibold"><?php echo $h_notif['message']; ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Navbar change on scroll
        window.addEventListener('scroll', function() {
            if (window.scrollY > 50) {
                document.querySelector('.navbar').classList.add('scrolled');
            } else {
                document.querySelector('.navbar').classList.remove('scrolled');
            }
        });

        // ── CSRF Token Loading ──
        function loadCSRFToken() {
            fetch('auth/process_auth.php?action=csrf_token', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'csrf_token=init' })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.token) {
                        document.getElementById('csrfTokenLogin').value = data.token;
                        document.getElementById('csrfTokenRegister').value = data.token;
                        document.getElementById('csrfTokenRegOtp').value = data.token;
                        document.getElementById('csrfTokenForgot1').value = data.token;
                        document.getElementById('csrfTokenForgot2').value = data.token;
                        document.getElementById('csrfTokenForgot3').value = data.token;
                        document.getElementById('csrfTokenBooking').value = data.token;
                        document.getElementById('csrfTokenEventBooking').value = data.token;
                    }
                });
        }
        loadCSRFToken();

        // ── Password Strength Checker ──
        function checkPasswordStrength(password) {
            let score = 0;
            if (password.length >= 8) score++;
            if (password.length >= 12) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[a-z]/.test(password)) score++;
            if (/[0-9]/.test(password)) score++;
            if (/[^A-Za-z0-9]/.test(password)) score++;
            return score;
        }

        function updateStrengthUI(password, barId, textId) {
            const bar = document.getElementById(barId);
            const text = document.getElementById(textId);
            if (!bar || !text) return;
            if (!password) {
                bar.style.width = '0%';
                bar.className = 'progress-bar';
                text.textContent = '';
                return;
            }
            const score = checkPasswordStrength(password);
            const levels = [
                { max: 2, width: '25%', color: 'bg-danger', label: 'Weak' },
                { max: 4, width: '50%', color: 'bg-warning', label: 'Fair' },
                { max: 5, width: '75%', color: 'bg-info', label: 'Good' },
                { max: 6, width: '100%', color: 'bg-success', label: 'Strong' }
            ];
            const level = levels.find(l => score <= l.max) || levels[levels.length - 1];
            bar.style.width = level.width;
            bar.className = 'progress-bar ' + level.color;
            text.textContent = 'Strength: ' + level.label;
        }

        document.getElementById('registerPassword')?.addEventListener('input', function() {
            updateStrengthUI(this.value, 'regPwStrengthBar', 'regPwStrengthText');
        });
        document.getElementById('forgotPassword')?.addEventListener('input', function() {
            updateStrengthUI(this.value, 'forgotPwStrengthBar', 'forgotPwStrengthText');
        });

        // Shared PH mobile format check (mirrors server-side normalizePhone):
        // accepts 09XXXXXXXXX, +639XXXXXXXXX, 639XXXXXXXXX; returns 09XXXXXXXXX or ''
        function normalizePhonePH(phone) {
            let d = String(phone || '').replace(/[\s\-()]/g, '');
            if (d.startsWith('+63')) d = '0' + d.slice(3);
            else if (/^63\d{10}$/.test(d)) d = '0' + d.slice(2);
            return /^09\d{9}$/.test(d) ? d : '';
        }

        // Title Case for names (mirrors server-side titlePersonName):
        // first letter big — "juan dela cruz" becomes "Juan Dela Cruz"
        function toTitleCaseName(v) {
            return String(v || '').toLowerCase().replace(/(^|[\s\-'])(\S)/gu, (m, p1, ch) => p1 + ch.toUpperCase());
        }

        // Globe/TM carrier check for OTP SMS (mirrors server-side list)
        const GLOBE_TM_PREFIXES = ['0817','0905','0906','0915','0916','0917','0926','0927','0935','0936','0937','0945','0953','0954','0955','0956','0965','0966','0967','0975','0976','0977','0995','0996','0997'];
        function isGlobeTmPH(phone) {
            const n = normalizePhonePH(phone);
            return n !== '' && GLOBE_TM_PREFIXES.includes(n.slice(0, 4));
        }

        // Enable Register only after agreeing to the Terms & Conditions
        document.getElementById('registerTermsAgree')?.addEventListener('change', function() {
            document.getElementById('registerSubmitBtn').disabled = !this.checked;
        });

        // AJAX Registration
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!document.getElementById('registerTermsAgree')?.checked) {
                alert('Please read and agree to the Terms & Conditions to register.');
                return;
            }
            const namePattern = /^[\p{L}\s.'-]+$/u;
            const firstInput = this.querySelector('[name="first_name"]');
            const lastInput = this.querySelector('[name="last_name"]');
            const miInput = this.querySelector('[name="middle_initial"]');
            firstInput.value = firstInput.value.trim().replace(/\s+/g, ' ');
            lastInput.value = lastInput.value.trim().replace(/\s+/g, ' ');
            if (!firstInput.value || firstInput.value.length > 50 || !namePattern.test(firstInput.value)) {
                alert('Please enter a valid first name (letters only).');
                firstInput.focus();
                return;
            }
            if (!lastInput.value || lastInput.value.length > 50 || !namePattern.test(lastInput.value)) {
                alert('Please enter a valid last name (letters only).');
                lastInput.focus();
                return;
            }
            const miClean = miInput.value.replace(/[^A-Za-z]/g, '').toUpperCase();
            if (miInput.value.trim() !== '' && miClean.length !== 1) {
                alert('Middle initial must be a single letter (e.g. A).');
                miInput.focus();
                return;
            }
            miInput.value = miClean;
            const emailInput = this.querySelector('[name="email"]');
            emailInput.value = emailInput.value.trim().toLowerCase();
            if (!/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}$/.test(emailInput.value) || emailInput.value.length > 100) {
                alert('Please enter a standard email address with @ and a domain ending (e.g. name@example.com).');
                emailInput.focus();
                return;
            }
            const phoneInput = this.querySelector('[name="phone"]');
            const normalized = normalizePhonePH(phoneInput.value);
            if (!normalized) {
                alert('Please enter a valid PH mobile number (e.g. 09568820066).');
                phoneInput.focus();
                return;
            }
            phoneInput.value = normalized;
            const otpChannel = (this.querySelector('[name="otp_channel"]:checked') || {}).value || 'sms';
            if (otpChannel === 'sms' && !isGlobeTmPH(normalized)) {
                alert('SMS codes are sent to Globe and TM numbers only. Please use a Globe/TM number or choose Email instead.');
                document.getElementById('regChannelEmail').checked = true;
                phoneInput.focus();
                return;
            }
            const addressInput = this.querySelector('[name="address"]');
            addressInput.value = addressInput.value.replace(/\s+/g, ' ').trim();
            if (addressInput.value.length < 5 || addressInput.value.length > 255) {
                alert('Please enter your complete home address (min. 5 characters).');
                addressInput.focus();
                return;
            }
            const formData = new FormData(this);
            const btn = document.getElementById('registerSubmitBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Sending code...';
            fetch('auth/process_auth.php?action=register_send_otp', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                btn.disabled = !document.getElementById('registerTermsAgree')?.checked;
                if(data.success) {
                    document.getElementById('regStep1').style.display = 'none';
                    document.getElementById('regStep2').style.display = 'block';
                    const desc = document.getElementById('regOtpDesc');
                    if (desc) desc.textContent = data.message + ' It is valid for 5 minutes.';
                    document.getElementById('regOtpError')?.classList.add('d-none');
                    const ok = document.getElementById('regOtpSuccess');
                    if (ok) { ok.textContent = data.message; ok.classList.remove('d-none'); }
                } else {
                    if (data.needs_channel === 'email') {
                        document.getElementById('regChannelEmail').checked = true;
                    }
                    alert(data.message);
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                btn.disabled = !document.getElementById('registerTermsAgree')?.checked;
                alert('An error occurred. Please try again.');
            });
        });

        // ── REGISTRATION — Step 2: Verify OTP → account is created ──
        document.getElementById('registerOtpForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('regOtpBtn');
            const errorEl = document.getElementById('regOtpError');
            const successEl = document.getElementById('regOtpSuccess');
            errorEl.classList.add('d-none');
            successEl.classList.add('d-none');

            const formData = new FormData(this);
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Verifying...';

            fetch('auth/process_auth.php?action=register_verify_otp', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = 'Verify & Create Account';
                if (data.success) {
                    alert(data.message);
                    bootstrap.Modal.getInstance(document.getElementById('registerModal'))?.hide();
                    location.reload();
                } else {
                    errorEl.textContent = data.message;
                    errorEl.classList.remove('d-none');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = 'Verify & Create Account';
                errorEl.textContent = 'An error occurred. Please try again.';
                errorEl.classList.remove('d-none');
            });
        });

        // ── REGISTRATION — Resend OTP (60s cooldown like forgot-password) ──
        document.getElementById('regResendOtpBtn')?.addEventListener('click', function(e) {
            e.preventDefault();
            const errorEl = document.getElementById('regOtpError');
            const successEl = document.getElementById('regOtpSuccess');
            errorEl.classList.add('d-none');
            successEl.classList.add('d-none');

            this.textContent = 'Sending...';
            this.style.pointerEvents = 'none';

            const resendData = new URLSearchParams();
            resendData.append('csrf_token', document.getElementById('csrfTokenRegOtp')?.value || '');

            fetch('auth/process_auth.php?action=register_resend_otp', { method: 'POST', body: resendData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    successEl.textContent = data.message;
                    successEl.classList.remove('d-none');
                } else {
                    errorEl.textContent = data.message;
                    errorEl.classList.remove('d-none');
                }
                let seconds = 60;
                this.textContent = 'Resend Code (' + seconds + 's)';
                const interval = setInterval(() => {
                    seconds--;
                    if (seconds <= 0) {
                        clearInterval(interval);
                        this.textContent = 'Resend Code';
                        this.style.pointerEvents = 'auto';
                    } else {
                        this.textContent = 'Resend Code (' + seconds + 's)';
                    }
                }, 1000);
            })
            .catch(() => {
                errorEl.textContent = 'An error occurred. Please try again.';
                errorEl.classList.remove('d-none');
                this.textContent = 'Resend Code';
                this.style.pointerEvents = 'auto';
            });
        });

        // ── REGISTRATION — Back to details / reset OTP state on close ──
        document.getElementById('regBackBtn')?.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('regStep2').style.display = 'none';
            document.getElementById('regStep1').style.display = 'block';
        });
        document.getElementById('registerModal')?.addEventListener('hidden.bs.modal', function() {
            document.getElementById('regStep2').style.display = 'none';
            document.getElementById('regStep1').style.display = 'block';
            document.getElementById('registerOtpForm')?.reset();
            ['regOtpError', 'regOtpSuccess'].forEach(id => document.getElementById(id)?.classList.add('d-none'));
        });

        // AJAX Login
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Signing in...';

            const loginEmail = this.querySelector('[name="email"]');
            if (loginEmail) loginEmail.value = loginEmail.value.trim();
            const formData = new FormData(this);
            fetch('auth/process_auth.php?action=login', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    window.location.href = data.redirect;
                } else {
                    if (data.admin_portal) {
                        if (confirm(data.message + '\n\nGo to the admin portal now?')) {
                            window.location.href = 'admin_login.php';
                        }
                    } else {
                        alert(data.message);
                    }
                    if (data.locked && data.remaining) {
                        startLoginCountdown(data.remaining);
                    }
                }
                btn.disabled = false;
                btn.innerHTML = originalText;
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = originalText;
            });
        });

        // Login lockout countdown
        let loginCountdownInterval = null;
        function startLoginCountdown(seconds) {
            const btn = document.querySelector('#loginForm button[type="submit"]');
            if (loginCountdownInterval) clearInterval(loginCountdownInterval);
            let remaining = seconds;
            btn.disabled = true;
            btn.innerHTML = `<i class="fas fa-lock me-2"></i> Locked (${remaining}s)`;
            loginCountdownInterval = setInterval(() => {
                remaining--;
                if (remaining <= 0) {
                    clearInterval(loginCountdownInterval);
                    btn.disabled = false;
                    btn.innerHTML = 'Sign In';
                } else {
                    btn.innerHTML = `<i class="fas fa-lock me-2"></i> Locked (${remaining}s)`;
                }
            }, 1000);
        }

        // ── FORGOT PASSWORD — Step 1: Send OTP (admin) or get security question (customer) ──
        document.getElementById('forgotEmailForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('forgotEmailBtn');
            const errorEl = document.getElementById('forgotEmailError');
            errorEl.classList.add('d-none');

            const formData = new FormData(this);
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Verifying...';

            fetch('auth/process_auth.php?action=send_otp', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = 'Continue';
                if (data.success) {
                    // Step 1b: let the guest pick SMS and/or Email
                    document.getElementById('forgotStep1').style.display = 'none';
                    document.getElementById('forgotStepChannel').style.display = 'block';
                    const smsBtn = document.getElementById('forgotChannelSmsBtn');
                    document.getElementById('forgotChannelSmsTo').textContent = data.channel_sms
                        ? 'to ' + data.channel_sms_to + ' (Globe/TM)'
                        : (data.channel_sms_note || 'Not available for this account');
                    smsBtn.disabled = !data.channel_sms;
                    smsBtn.classList.toggle('disabled', !data.channel_sms);
                    document.getElementById('forgotChannelEmailTo').textContent = 'to ' + data.channel_email_to;
                    document.getElementById('forgotChannelError')?.classList.add('d-none');
                } else {
                    errorEl.textContent = data.message;
                    errorEl.classList.remove('d-none');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = 'Continue';
                errorEl.textContent = 'An error occurred. Please try again.';
                errorEl.classList.remove('d-none');
            });
        });

        // ── FORGOT PASSWORD — Step 1b: Send the code via the chosen channel ──
        function requestForgotCode(channel, btn) {
            const errorEl = document.getElementById('forgotChannelError');
            errorEl.classList.add('d-none');
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Sending...';

            const codeData = new URLSearchParams();
            codeData.append('csrf_token', document.getElementById('csrfTokenForgot2')?.value || document.getElementById('csrfTokenForgot1')?.value || '');
            codeData.append('channel', channel);

            fetch('auth/process_auth.php?action=send_otp_code', { method: 'POST', body: codeData })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = original;
                if (data.success) {
                    document.getElementById('forgotStepChannel').style.display = 'none';
                    document.getElementById('forgotStep2').style.display = 'block';
                    document.getElementById('forgotOtpDesc').textContent = data.message;
                    const successEl = document.getElementById('forgotOtpSuccess');
                    successEl.textContent = data.message;
                    successEl.classList.remove('d-none');
                } else {
                    errorEl.textContent = data.message;
                    errorEl.classList.remove('d-none');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = original;
                errorEl.textContent = 'An error occurred. Please try again.';
                errorEl.classList.remove('d-none');
            });
        }
        document.getElementById('forgotChannelSmsBtn')?.addEventListener('click', function() {
            if (this.disabled) return;
            requestForgotCode('sms', this);
        });
        document.getElementById('forgotChannelEmailBtn')?.addEventListener('click', function() {
            requestForgotCode('email', this);
        });

        // ── FORGOT PASSWORD — Step 2: Verify OTP ──
        document.getElementById('forgotOtpForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('forgotOtpBtn');
            const errorEl = document.getElementById('forgotOtpError');
            const successEl = document.getElementById('forgotOtpSuccess');
            errorEl.classList.add('d-none');
            successEl.classList.add('d-none');

            const formData = new FormData(this);
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Verifying...';

            fetch('auth/process_auth.php?action=verify_otp', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = 'Verify Code';
                if (data.success) {
                    document.getElementById('forgotStep2').style.display = 'none';
                    document.getElementById('forgotStep3').style.display = 'block';
                } else {
                    errorEl.textContent = data.message;
                    errorEl.classList.remove('d-none');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = 'Verify Code';
                errorEl.textContent = 'An error occurred. Please try again.';
                errorEl.classList.remove('d-none');
            });
        });

        // ── FORGOT PASSWORD — Resend OTP (same channel as before) ──
        document.getElementById('resendOtpBtn').addEventListener('click', function(e) {
            e.preventDefault();
            const errorEl = document.getElementById('forgotOtpError');
            const successEl = document.getElementById('forgotOtpSuccess');
            errorEl.classList.add('d-none');
            successEl.classList.add('d-none');

            this.textContent = 'Sending...';
            this.style.pointerEvents = 'none';

            const resendData = new URLSearchParams();
            resendData.append('csrf_token', document.getElementById('csrfTokenForgot2')?.value || '');

            fetch('auth/process_auth.php?action=resend_otp', { method: 'POST', body: resendData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    successEl.textContent = data.message;
                    successEl.classList.remove('d-none');
                } else {
                    errorEl.textContent = data.message;
                    errorEl.classList.remove('d-none');
                }
                // Cooldown (60s to match server-side)
                let seconds = 60;
                this.textContent = 'Resend Code (' + seconds + 's)';
                const interval = setInterval(() => {
                    seconds--;
                    if (seconds <= 0) {
                        clearInterval(interval);
                        this.textContent = 'Resend Code';
                        this.style.pointerEvents = 'auto';
                    } else {
                        this.textContent = 'Resend Code (' + seconds + 's)';
                    }
                }, 1000);
            });
        });

        // ── FORGOT PASSWORD — Step 3: Reset Password ──
        document.getElementById('forgotResetForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('forgotResetBtn');
            const errorEl = document.getElementById('forgotResetError');
            errorEl.classList.add('d-none');

            const formData = new FormData(this);
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Resetting...';

            fetch('auth/process_auth.php?action=reset_forgot_password', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = 'Reset Password';
                if (data.success) {
                    document.getElementById('forgotStep3').style.display = 'none';
                    document.getElementById('forgotStep4').style.display = 'block';
                } else {
                    errorEl.textContent = data.message;
                    errorEl.classList.remove('d-none');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = 'Reset Password';
                errorEl.textContent = 'An error occurred. Please try again.';
                errorEl.classList.remove('d-none');
            });
        });

        // Reset forgot modal state
        function resetForgotModal() {
            document.getElementById('forgotStep1').style.display = 'block';
            document.getElementById('forgotStepChannel').style.display = 'none';
            document.getElementById('forgotStep2').style.display = 'none';
            document.getElementById('forgotStep3').style.display = 'none';
            document.getElementById('forgotStep4').style.display = 'none';
            document.getElementById('forgotEmailForm').reset();
            document.getElementById('forgotOtpForm')?.reset();
            document.getElementById('forgotResetForm')?.reset();
            ['forgotEmailError','forgotChannelError','forgotOtpError','forgotOtpSuccess','forgotResetError'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.classList.add('d-none');
            });
            // Reset password strength indicators
            const bar = document.getElementById('forgotPwStrengthBar');
            const text = document.getElementById('forgotPwStrengthText');
            if (bar) { bar.style.width = '0%'; bar.className = 'progress-bar'; }
            if (text) text.textContent = '';
        }
        // Shared mini review renderer for the detail modals (valid = approved only)
        function reviewStars(n) {
            let out = '';
            for (let i = 1; i <= 5; i++) {
                out += i <= n ? '★' : '<span style="color:#e2e8f0;">★</span>';
            }
            return `<span style="color:#f59e0b;letter-spacing:2px;">${out}</span>`;
        }
        function renderMiniReviews(boxId, countId, reviewsJson) {
            let list = [];
            try {
                const parsed = JSON.parse(reviewsJson || '[]');
                if (Array.isArray(parsed)) list = parsed.slice(0, 3);
            } catch (e) { list = []; }
            const box = document.getElementById(boxId);
            const countEl = document.getElementById(countId);
            if (!box) return;
            if (!list.length) {
                if (countEl) countEl.textContent = '';
                box.innerHTML = '<p class="text-muted small mb-0">No reviews yet — be the first to share your experience!</p>';
                return;
            }
            if (countEl) countEl.textContent = `(${list.length} shown)`;
            box.innerHTML = list.map(r => {
                const name = String(r.name || 'Guest').replace(/[<>&"]/g, '');
                const fb = String(r.feedback || '').replace(/[<>&"]/g, '');
                const date = String(r.date || '').replace(/[<>&"]/g, '');
                return `<div class="border rounded-3 p-3 mb-2 bg-light">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fw-bold small">${name}</span>
                        <span class="small">${reviewStars(parseInt(r.rating, 10) || 0)}</span>
                    </div>
                    <p class="small mb-1">${fb}</p>
                    <div class="x-small text-muted">${date} · verified stay</div>
                </div>`;
            }).join('');
        }

        // Swipeable 1–10 photo galleries (rooms + events): prefix maps to
        // <prefix>Carousel / <prefix>Inner / <prefix>Indicators / <prefix>Prev / <prefix>Next
        const galleryCarousels = {};
        function showGallery(prefix, imgClass, label, images, fallback) {
            let imgs = [];
            try {
                const parsed = JSON.parse(images || '[]');
                if (Array.isArray(parsed)) imgs = parsed.filter(src => typeof src === 'string' && src !== '');
            } catch (e) { imgs = []; }
            if (!imgs.length && fallback) imgs = [fallback];
            imgs = imgs.slice(0, 10);

            document.getElementById(prefix + 'Inner').innerHTML = imgs.map((src, i) => `
                <div class="carousel-item${i === 0 ? ' active' : ''}">
                    <img src="${src.replace(/"/g, '')}" class="d-block w-100 ${imgClass}" alt="${label} photo ${i + 1}">
                </div>`).join('');
            document.getElementById(prefix + 'Indicators').innerHTML = imgs.map((_, i) => `
                <button type="button" data-bs-target="#${prefix}Carousel" data-bs-slide-to="${i}"${i === 0 ? ' class="active" aria-current="true"' : ''} aria-label="Photo ${i + 1}"></button>`).join('');

            const multi = imgs.length > 1;
            document.getElementById(prefix + 'Indicators').style.display = multi ? '' : 'none';
            document.getElementById(prefix + 'Prev').style.display = multi ? '' : 'none';
            document.getElementById(prefix + 'Next').style.display = multi ? '' : 'none';

            const el = document.getElementById(prefix + 'Carousel');
            if (galleryCarousels[prefix]) galleryCarousels[prefix].dispose();
            galleryCarousels[prefix] = new bootstrap.Carousel(el, { touch: true, interval: false });
            galleryCarousels[prefix].to(0);
        }
        function showRoomGallery(images, fallback) {
            showGallery('roomGallery', 'room-gallery-img', 'Room', images, fallback);
        }
        function showPkgGallery(images, fallback) {
            showGallery('pkgGallery', 'pkg-gallery-img', 'Package', images, fallback);
        }

        // Room Details Modal Logic (swipeable 1–10 photo gallery)
        const roomDetailModal = new bootstrap.Modal(document.getElementById('roomDetailModal'));
        document.querySelectorAll('.view-room-details').forEach(button => {
            button.addEventListener('click', function() {
                const title = this.getAttribute('data-title');
                const price = this.getAttribute('data-price');
                const img = this.getAttribute('data-img');
                const desc = this.getAttribute('data-desc');
                const id = this.getAttribute('data-id');
                const amenities = JSON.parse(this.getAttribute('data-amenities'));

                document.getElementById('modalRoomTitle').innerText = title;
                document.getElementById('modalRoomPrice').innerText = price;
                showRoomGallery(this.getAttribute('data-images'), img);
                document.getElementById('modalRoomDesc').innerText = desc;
                
                // Pass the ID and Base Price to the booking form
                document.getElementById('bookingItemId').value = id;
                document.getElementById('bookingItemBasePrice').value = price.replace(',', '');
                
                // Reset to Room Type
                document.getElementById('mainBookingForm').querySelector('[name="type"]').value = 'room';

                // Reset the guest breakdown (1 pax on the first type)
                resetGuestBreakdown('room', 1);

                // Initialize Calculation
                calculateRealTimePrice();
                
                // Initialize Flatpickr for disabled dates
                initFlatpickr(id, 'room', '#checkInDate');

                const amenitiesList = document.getElementById('modalRoomAmenities');
                amenitiesList.innerHTML = '';
                amenities.forEach(amenity => {
                    amenitiesList.innerHTML += `<li class="col-6"><i class="fas fa-check text-success me-2"></i>${amenity}</li>`;
                });

                renderMiniReviews('modalRoomReviews', 'modalRoomReviewCount', this.getAttribute('data-reviews'));

                roomDetailModal.show();
            });
        });

        // Event Package Details Modal Logic
        const packageDetailModal = new bootstrap.Modal(document.getElementById('packageDetailModal'));
        document.querySelectorAll('.view-package-details').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const title = this.getAttribute('data-title');
                const price_fmt = this.getAttribute('data-price');
                const raw_price = this.getAttribute('data-raw-price');
                const img = this.getAttribute('data-img');
                const desc = this.getAttribute('data-desc');
                const capacity = this.getAttribute('data-capacity');
                const services = JSON.parse(this.getAttribute('data-services'));

                document.getElementById('modalPkgTitle').innerText = title;
                document.getElementById('modalPkgPrice').innerText = price_fmt;
                showPkgGallery(this.getAttribute('data-images'), img);
                document.getElementById('modalPkgDesc').innerText = desc;
                document.getElementById('modalPkgCapacity').innerText = capacity;
                
                // Populate event booking form hidden fields
                document.getElementById('eventItemId').value = id;
                document.getElementById('eventItemBasePrice').value = raw_price;
                document.getElementById('eventMaxCapacity').value = capacity;
                document.getElementById('eventRealTimeTotal').innerText = price_fmt;

                // Reset the guest breakdown and show the capacity limit
                resetGuestBreakdown('event', 0);

                // Set the 30% down payment requirement (will be recalculated on date range change)
                const initDownRequired = Math.ceil((parseFloat(raw_price) || 0) * 0.30);
                const dpInput = document.getElementById('eventDownPayment');
                dpInput.value = initDownRequired;
                dpInput.min = initDownRequired;
                document.getElementById('eventDownPaymentHint').innerText = `Minimum required: ₱${initDownRequired.toLocaleString()} (30% of base package)`;
                dpInput.setAttribute('data-min', initDownRequired);

                // Reset event form consent / payment state
                const evtAgree = document.getElementById('eventTermsAgree');
                if (evtAgree) evtAgree.checked = false;
                const evtSubmit = document.getElementById('eventSubmitBtn');
                if (evtSubmit) evtSubmit.disabled = true;
                const evtMethod = document.getElementById('eventPaymentMethod');
                if (evtMethod) { evtMethod.value = ''; showEventPaymentMethod(evtMethod); }
                const evtProof = document.getElementById('eventDownPaymentProof');
                if (evtProof) evtProof.value = '';
                
                // Initialize Flatpickr for event dates
                initFlatpickr(id, 'event', '#eventStartDate');

                const servicesList = document.getElementById('modalPkgServices');
                servicesList.innerHTML = '';
                services.forEach(service => {
                    servicesList.innerHTML += `<li class="col-6"><i class="fas fa-star text-accent me-2"></i>${service}</li>`;
                });

                renderMiniReviews('modalPkgReviews', 'modalPkgReviewCount', this.getAttribute('data-reviews'));

                packageDetailModal.show();
            });
        });

        // AJAX Update Profile
        document.getElementById('updateProfileForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const namePattern = /^[\p{L}\s.'-]+$/u;
            const firstInput = this.querySelector('[name="first_name"]');
            const lastInput = this.querySelector('[name="last_name"]');
            const miInput = this.querySelector('[name="middle_initial"]');
            if (firstInput) {
                firstInput.value = firstInput.value.trim().replace(/\s+/g, ' ');
                if (!firstInput.value || firstInput.value.length > 50 || !namePattern.test(firstInput.value)) {
                    alert('Please enter a valid first name (letters only).');
                    firstInput.focus();
                    return;
                }
            }
            if (lastInput) {
                lastInput.value = lastInput.value.trim().replace(/\s+/g, ' ');
                if (!lastInput.value || lastInput.value.length > 50 || !namePattern.test(lastInput.value)) {
                    alert('Please enter a valid last name (letters only).');
                    lastInput.focus();
                    return;
                }
            }
            if (miInput) {
                const miClean = miInput.value.replace(/[^A-Za-z]/g, '').toUpperCase();
                if (miInput.value.trim() !== '' && miClean.length !== 1) {
                    alert('Middle initial must be a single letter (e.g. A).');
                    miInput.focus();
                    return;
                }
                miInput.value = miClean;
            }
            const phoneInput = this.querySelector('[name="phone"]');
            if (phoneInput) {
                const normalized = normalizePhonePH(phoneInput.value);
                if (!normalized) {
                    alert('Please enter a valid PH mobile number (e.g. 09568820066).');
                    phoneInput.focus();
                    return;
                }
                phoneInput.value = normalized;
            }
            const addressInput = this.querySelector('[name="address"]');
            if (addressInput) {
                addressInput.value = addressInput.value.replace(/\s+/g, ' ').trim();
                if (addressInput.value.length < 5 || addressInput.value.length > 255) {
                    alert('Please enter your complete home address (min. 5 characters).');
                    addressInput.focus();
                    return;
                }
            }
            const formData = new FormData(this);
            fetch('api/update_profile.php?action=info', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if(data.success) location.reload();
            });
        });

        // AJAX Change Password
        document.getElementById('updatePasswordForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('api/update_profile.php?action=password', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if(data.success) location.reload();
            });
        });

        // Password Toggle Logic
        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            const iconElem = icon.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                iconElem.classList.remove('fa-eye');
                iconElem.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                iconElem.classList.remove('fa-eye-slash');
                iconElem.classList.add('fa-eye');
            }
        }
        // ── Guest type breakdown (Male / Female / Children ...) ─────────
        const GUEST_TYPE_LABELS = <?php echo json_encode($guest_type_labels); ?>;

        function getGuestBreakdown(mode) {
            const form = document.getElementById(mode === 'event' ? 'eventBookingForm' : 'mainBookingForm');
            if (!form) return [];
            const out = [];
            form.querySelectorAll('.guest-breakdown-input').forEach(inp => {
                const count = Math.max(0, parseInt(inp.value) || 0);
                out.push({ type_id: parseInt(inp.dataset.guestType) || 0, count: count });
            });
            return out;
        }

        function getGuestTotal(mode) {
            return getGuestBreakdown(mode).reduce((sum, g) => sum + g.count, 0);
        }

        function guestBreakdownText(mode) {
            const parts = getGuestBreakdown(mode)
                .filter(g => g.count > 0)
                .map(g => `${g.count} ${GUEST_TYPE_LABELS[g.type_id] || 'Guest'}`);
            return parts.length ? parts.join(', ') : '—';
        }

        // Sum the per-type inputs, mirror the total into the hidden
        // `guests` field and refresh dependent UI (price / capacity).
        function syncGuestTotal(mode) {
            const isEvent = mode === 'event';
            const total = getGuestTotal(mode);

            const hidden = document.getElementById(isEvent ? 'eventGuestInput' : 'guestCountInput');
            if (hidden) hidden.value = total;

            const totalEl = document.getElementById(isEvent ? 'eventGuestTotal' : 'roomGuestTotal');
            if (totalEl) totalEl.textContent = total;

            if (isEvent) {
                const capacity = parseInt(document.getElementById('eventMaxCapacity')?.value) || 0;
                const hint = document.getElementById('capacityHint');
                if (hint) {
                    if (capacity > 0 && total > capacity) {
                        hint.classList.add('text-danger');
                        hint.innerHTML = `Guest limit: ${capacity} Pax — you entered ${total}.`;
                    } else {
                        hint.classList.remove('text-danger');
                        hint.innerHTML = capacity > 0 ? `Guest limit: ${capacity} Pax` : 'Must not exceed package capacity.';
                    }
                }
            } else {
                // Rooms cap at 20 pax
                const hint = document.getElementById('roomGuestHint');
                if (hint) {
                    if (total > 20) {
                        hint.classList.add('text-danger');
                        hint.innerHTML = `— exceeds the 20 pax limit for a room.`;
                    } else {
                        hint.classList.remove('text-danger');
                        hint.innerHTML = '';
                    }
                }
                calculateRealTimePrice();
            }
            return total;
        }

        // Clear the breakdown; `defaultCount` seeds the first type.
        function resetGuestBreakdown(mode, defaultCount) {
            const form = document.getElementById(mode === 'event' ? 'eventBookingForm' : 'mainBookingForm');
            if (!form) return;
            const inputs = form.querySelectorAll('.guest-breakdown-input');
            inputs.forEach((inp, i) => {
                inp.value = (i === 0 && defaultCount > 0) ? defaultCount : 0;
            });
            syncGuestTotal(mode);
        }

        // Real-time Pricing Logic
        function calculateRealTimePrice() {
            const basePrice = parseFloat(document.getElementById('bookingItemBasePrice').value) || 0;
            const guests = parseInt(document.getElementById('guestCountInput').value) || 0;
            
            const checkInVal = document.getElementById('checkInDate')?.value;
            const checkOutVal = document.getElementById('checkOutDate')?.value;
            let nights = parseInt(document.getElementById('stayDurationSelect')?.value) || 1;

            if (checkInVal && checkOutVal) {
                const d1 = new Date(checkInVal);
                const d2 = new Date(checkOutVal);
                const diffDays = Math.round((d2 - d1) / (1000 * 60 * 60 * 24));
                if (diffDays > 0) nights = diffDays;
            }

            let ratePerNight = basePrice;
            if (guests >= 6) {
                const extraGuests = guests - 5;
                ratePerNight += (extraGuests * 200);
            }
            
            const total = ratePerNight * nights;

            const totalEl = document.getElementById('realTimeTotal');
            if (totalEl) totalEl.innerText = total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            const breakdownEl = document.getElementById('realTimeBreakdown');
            if (breakdownEl) {
                let text = `₱${ratePerNight.toLocaleString('en-US', {minimumFractionDigits: 2})} / night × ${nights} night${nights > 1 ? 's' : ''}`;
                if (guests >= 6) {
                    text += ` (incl. ₱${((guests - 5) * 200).toLocaleString()} extra guest fee/night)`;
                }
                breakdownEl.innerText = text;
            }

            // Update down payment hint
            const dpInput = document.getElementById('roomDownPayment');
            if (dpInput) {
                const downRequired = Math.ceil(total * 0.30);
                dpInput.min = downRequired;
                dpInput.setAttribute('data-min', downRequired);
                document.getElementById('roomDownPaymentHint').innerText = `Minimum required: ₱${downRequired.toLocaleString()} (30%)`;
                if (parseFloat(dpInput.value) < downRequired || !dpInput.value) {
                    dpInput.value = downRequired;
                }
            }
        }

        function onDurationSelectChange() {
            const inVal = document.getElementById('checkInDate').value;
            const nights = parseInt(document.getElementById('stayDurationSelect').value) || 1;
            if (inVal && fpOutInstance) {
                const d1 = new Date(inVal);
                d1.setDate(d1.getDate() + nights);
                fpOutInstance.setDate(d1, true);
            }
            calculateRealTimePrice();
        }

        // Room payment method selector (online = proof required, arrival = pay in person)
        function showRoomPaymentMethod(selectEl) {
            const selected = selectEl.value;
            const panel = document.getElementById('roomMethodDetails');
            const proofUpload = document.getElementById('roomProofUpload');
            const proofInput = document.getElementById('roomDownPaymentProof');

            if (!selected) {
                panel.style.display = 'none';
                proofUpload.style.display = 'none';
                if (proofInput) proofInput.required = false;
                return;
            }

            const method = paymentMethodsData.find(m => m.method_name === selected);
            if (!method) { panel.style.display = 'none'; return; }

            const isArrival = method.method_name.trim().toLowerCase() === 'pay upon arrival';

            if (isArrival) {
                proofUpload.style.display = 'none';
                if (proofInput) proofInput.required = false;
            } else {
                proofUpload.style.display = 'block';
                if (proofInput) proofInput.required = true;
            }

            let html = `<div class="border rounded-3 p-3 ${isArrival ? 'bg-warning bg-opacity-10 border-warning' : 'bg-light'}">`;
            if (isArrival) {
                html += `<div class="fw-bold mb-1 text-warning"><i class="fas fa-concierge-bell me-2"></i>Pay Upon Arrival (Visit the Area)</div>`;
                html += `<p class="small mb-0">Please visit Centrotel & Event Center to settle your <strong>30% down payment</strong> in person. Our team will assist you.</p>`;
            } else {
                html += `<div class="fw-bold mb-1"><i class="fas fa-info-circle me-2 text-primary"></i>${method.method_name} Instructions</div>`;
                if (method.account_details) {
                    html += `<p class="small mb-2 text-muted">${method.account_details.replace(/\n/g,'<br>')}</p>`;
                }
                if (method.qr_code_url) {
                    html += `<div class="text-center mt-2">
                                <img src="${method.qr_code_url}" alt="QR Code" style="max-height:160px; border-radius:10px; border:2px solid #ddd;">
                                <div class="small text-muted mt-1"><i class="fas fa-qrcode me-1"></i>Scan QR to pay, then upload your proof above.</div>
                                <a href="api/download_qr.php?method_id=${method.id}" class="btn btn-sm btn-outline-primary mt-2" download>
                                    <i class="fas fa-download me-1"></i>Download QR Code
                                </a>
                             </div>`;
                }
            }
            html += `</div>`;
            panel.innerHTML = html;
            panel.style.display = 'block';
        }
        document.getElementById('roomPaymentMethod')?.addEventListener('change', function() {
            showRoomPaymentMethod(this);
        });

// Event Booking Form Handler → review Terms/Rules + summary BEFORE final booking
        document.getElementById('eventBookingForm').addEventListener('submit', function(e) {
            e.preventDefault();
            openBookingConfirm('event');
        });

        // Event payment method selector (online = proof required, arrival = pay in person)
        function showEventPaymentMethod(selectEl) {
            const selected = selectEl.value;
            const panel = document.getElementById('eventMethodDetails');
            const proofUpload = document.getElementById('eventProofUpload');
            const proofInput = document.getElementById('eventDownPaymentProof');

            if (!selected) {
                panel.style.display = 'none';
                proofUpload.style.display = 'none';
                proofInput.required = false;
                return;
            }

            const method = paymentMethodsData.find(m => m.method_name === selected);
            if (!method) { panel.style.display = 'none'; return; }

            const isArrival = method.method_name.trim().toLowerCase() === 'pay upon arrival';

            if (isArrival) {
                proofUpload.style.display = 'none';
                proofInput.required = false;
            } else {
                proofUpload.style.display = 'block';
                proofInput.required = true;
            }

            let html = `<div class="border rounded-3 p-3 ${isArrival ? 'bg-warning bg-opacity-10 border-warning' : 'bg-light'}">`;
            if (isArrival) {
                html += `<div class="fw-bold mb-1 text-warning"><i class="fas fa-concierge-bell me-2"></i>Pay Upon Arrival (Visit the Area)</div>`;
                html += `<p class="small mb-0">Please visit Centrotel & Event Center to settle your <strong>30% down payment</strong> in person. Our team will assist you.</p>`;
            } else {
                html += `<div class="fw-bold mb-1"><i class="fas fa-info-circle me-2 text-primary"></i>${method.method_name} Instructions</div>`;
                if (method.account_details) {
                    html += `<p class="small mb-2 text-muted">${method.account_details.replace(/\n/g,'<br>')}</p>`;
                }
                if (method.qr_code_url) {
                    html += `<div class="text-center mt-2">
                                <img src="${method.qr_code_url}" alt="QR Code" style="max-height:160px; border-radius:10px; border:2px solid #ddd;">
                                <div class="small text-muted mt-1"><i class="fas fa-qrcode me-1"></i>Scan QR to pay, then upload your proof above.</div>
                                <a href="api/download_qr.php?method_id=${method.id}" class="btn btn-sm btn-outline-primary mt-2" download>
                                    <i class="fas fa-download me-1"></i>Download QR Code
                                </a>
                             </div>`;
                }
            }
            html += `</div>`;
            panel.innerHTML = html;
            panel.style.display = 'block';
        }
        document.getElementById('eventPaymentMethod')?.addEventListener('change', function() {
            showEventPaymentMethod(this);
        });

        // Enable submit only after agreeing to the Rules of Events
        document.getElementById('eventTermsAgree')?.addEventListener('change', function() {
            document.getElementById('eventSubmitBtn').disabled = !this.checked;
        });

        // Open the Rules of Events modal ON TOP of the event booking form, then return to it
        const eventTermsModalEl = document.getElementById('eventTermsModal');
        document.addEventListener('click', function(e) {
            const link = e.target.closest('[data-bs-target="#eventTermsModal"]');
            if (!link) return;
            const eventModalEl = document.getElementById('newEventBookingModal');
            if (eventModalEl && eventModalEl.classList.contains('show')) {
                e.preventDefault();
                bootstrap.Modal.getInstance(eventModalEl)?.hide();
                const etModal = new bootstrap.Modal(eventTermsModalEl);
                etModal.show();
                eventTermsModalEl.addEventListener('hidden.bs.modal', function handler() {
                    eventTermsModalEl.removeEventListener('hidden.bs.modal', handler);
                    new bootstrap.Modal(eventModalEl).show();
                });
                return;
            }
            // Same swap when "Read Full Rules" is clicked inside the final
            // booking-confirm step (returns there, consent box untouched)
            const confirmEl = document.getElementById('bookingConfirmModal');
            if (confirmEl && confirmEl.classList.contains('show')) {
                e.preventDefault();
                bootstrap.Modal.getInstance(confirmEl)?.hide();
                const etModal = new bootstrap.Modal(eventTermsModalEl);
                etModal.show();
                eventTermsModalEl.addEventListener('hidden.bs.modal', function handler() {
                    eventTermsModalEl.removeEventListener('hidden.bs.modal', handler);
                    new bootstrap.Modal(confirmEl).show();
                });
            }
        });

        let fpInInstance = null;
        let fpOutInstance = null;
        let fpEventStartInstance = null;
        let fpEventEndInstance = null;
        let roomBookedSet = null;
        let eventBookedSet = null;

        function updateEventDuration() {
            const startVal = document.getElementById('eventStartDate')?.value;
            const endVal = document.getElementById('eventEndDate')?.value;
            const durDisplay = document.getElementById('eventDurationDisplay');
            const daysInput = document.getElementById('eventDaysInput');
            const priceEl = document.getElementById('eventRealTimeTotal');
            const basePrice = parseFloat(document.getElementById('eventItemBasePrice')?.value) || 0;

            let days = 1;
            if (startVal && endVal) {
                const d1 = new Date(startVal);
                const d2 = new Date(endVal);
                days = Math.round((d2 - d1) / (1000 * 60 * 60 * 24)) + 1;
                if (days < 1) days = 1;
            }

            const totalPrice = basePrice * days;
            if (durDisplay) durDisplay.innerText = days + ' Day(s)';
            if (daysInput) daysInput.value = days;
            if (priceEl) priceEl.innerText = totalPrice.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            // Recalculate down payment (30% of total)
            const dpInput = document.getElementById('eventDownPayment');
            if (dpInput) {
                const downRequired = Math.ceil(totalPrice * 0.30);
                dpInput.min = downRequired;
                dpInput.setAttribute('data-min', downRequired);
                document.getElementById('eventDownPaymentHint').innerText = `Minimum required: ₱${downRequired.toLocaleString()} (30%)`;
                if (parseFloat(dpInput.value) < downRequired) {
                    dpInput.value = downRequired;
                }
            }
        }

        function initFlatpickr(itemId, type, selector) {
            if (type === 'event') {
                if (fpEventStartInstance) fpEventStartInstance.destroy();
                if (fpEventEndInstance) fpEventEndInstance.destroy();
                const startEl = document.getElementById('eventStartDate');
                const endEl = document.getElementById('eventEndDate');
                if (startEl) startEl.value = "";
                if (endEl) endEl.value = "";
                const durDisplay = document.getElementById('eventDurationDisplay');
                if (durDisplay) durDisplay.innerText = '1 Day(s)';
                const daysInput = document.getElementById('eventDaysInput');
                if (daysInput) daysInput.value = "1";

                fetch(`api/get_booked_dates.php?item_id=${itemId}&type=${type}`)
                    .then(res => res.json())
                    .then(disableDates => {
                        eventBookedSet = new Set(disableDates);

                        function getEventRangeClass(dayElem) {
                            const ds = dayElem.dateObj.toISOString().slice(0, 10);
                            if (!eventBookedSet.has(ds)) return;
                            const prev = new Date(dayElem.dateObj); prev.setDate(prev.getDate() - 1);
                            const next = new Date(dayElem.dateObj); next.setDate(next.getDate() + 1);
                            const prevBooked = eventBookedSet.has(prev.toISOString().slice(0, 10));
                            const nextBooked = eventBookedSet.has(next.toISOString().slice(0, 10));
                            if (!prevBooked && !nextBooked) return 'booked-range-single';
                            if (!prevBooked && nextBooked) return 'booked-range-start';
                            if (prevBooked && nextBooked) return 'booked-range-mid';
                            if (prevBooked && !nextBooked) return 'booked-range-end';
                        }

                        fpEventStartInstance = flatpickr("#eventStartDate", {
                            minDate: "today",
                            disable: disableDates,
                            dateFormat: "Y-m-d",
                            theme: "material_blue",
                            onDayCreate: function(dObj, dStr, fp, dayElem) {
                                const dateStr = dayElem.dateObj.toISOString().slice(0, 10);
                                if (eventBookedSet.has(dateStr)) {
                                    dayElem.classList.add('booked-date');
                                    const rc = getEventRangeClass(dayElem);
                                    if (rc) dayElem.classList.add(rc);
                                    dayElem.setAttribute('title', 'This date is already booked');
                                }
                            },
                            onChange: function(selectedDates) {
                                if (selectedDates.length > 0) {
                                    const nextDay = new Date(selectedDates[0]);
                                    nextDay.setDate(nextDay.getDate() + 1);
                                    if (fpEventEndInstance) {
                                        fpEventEndInstance.set("minDate", nextDay);
                                        fpEventEndInstance.clear();
                                    }
                                }
                                updateEventDuration();
                            }
                        });

                        fpEventEndInstance = flatpickr("#eventEndDate", {
                            minDate: "today",
                            disable: disableDates,
                            dateFormat: "Y-m-d",
                            theme: "material_blue",
                            onDayCreate: function(dObj, dStr, fp, dayElem) {
                                const dateStr = dayElem.dateObj.toISOString().slice(0, 10);
                                if (eventBookedSet.has(dateStr)) {
                                    dayElem.classList.add('booked-date');
                                    const rc = getEventRangeClass(dayElem);
                                    if (rc) dayElem.classList.add(rc);
                                    dayElem.setAttribute('title', 'This date is already booked');
                                }
                            },
                            onChange: function() {
                                updateEventDuration();
                            }
                        });
                    });
            } else {
                if (fpInInstance) fpInInstance.destroy();
                if (fpOutInstance) fpOutInstance.destroy();
                
                const inEl = document.getElementById('checkInDate');
                const outEl = document.getElementById('checkOutDate');
                const durEl = document.getElementById('stayDurationSelect');
                if (inEl) inEl.value = "";
                if (outEl) outEl.value = "";
                if (durEl) durEl.value = "1";

                fetch(`api/get_booked_dates.php?item_id=${itemId}&type=room`)
                    .then(res => res.json())
                    .then(disableDates => {
                        roomBookedSet = new Set(disableDates);

                        function getRoomRangeClass(dayElem) {
                            const ds = dayElem.dateObj.toISOString().slice(0, 10);
                            if (!roomBookedSet.has(ds)) return;
                            const prev = new Date(dayElem.dateObj); prev.setDate(prev.getDate() - 1);
                            const next = new Date(dayElem.dateObj); next.setDate(next.getDate() + 1);
                            const prevBooked = roomBookedSet.has(prev.toISOString().slice(0, 10));
                            const nextBooked = roomBookedSet.has(next.toISOString().slice(0, 10));
                            if (!prevBooked && !nextBooked) return 'booked-range-single';
                            if (!prevBooked && nextBooked) return 'booked-range-start';
                            if (prevBooked && nextBooked) return 'booked-range-mid';
                            if (prevBooked && !nextBooked) return 'booked-range-end';
                        }

                        fpInInstance = flatpickr("#checkInDate", {
                            minDate: "today",
                            disable: disableDates,
                            dateFormat: "Y-m-d",
                            theme: "material_blue",
                            onDayCreate: function(dObj, dStr, fp, dayElem) {
                                const dateStr = dayElem.dateObj.toISOString().slice(0, 10);
                                if (roomBookedSet.has(dateStr)) {
                                    dayElem.classList.add('booked-date');
                                    const rc = getRoomRangeClass(dayElem);
                                    if (rc) dayElem.classList.add(rc);
                                    dayElem.setAttribute('title', 'This date is already booked');
                                }
                            },
                            onChange: function(selectedDates) {
                                if (selectedDates.length > 0) {
                                    const nextDay = new Date(selectedDates[0]);
                                    nextDay.setDate(nextDay.getDate() + 1);
                                    if (fpOutInstance) fpOutInstance.set("minDate", nextDay);

                                    const nights = parseInt(document.getElementById('stayDurationSelect').value) || 1;
                                    const targetOut = new Date(selectedDates[0]);
                                    targetOut.setDate(targetOut.getDate() + nights);
                                    if (fpOutInstance) fpOutInstance.setDate(targetOut, true);
                                }
                                calculateRealTimePrice();
                            }
                        });

                        fpOutInstance = flatpickr("#checkOutDate", {
                            minDate: "today",
                            disable: disableDates,
                            dateFormat: "Y-m-d",
                            theme: "material_blue",
                            onDayCreate: function(dObj, dStr, fp, dayElem) {
                                const dateStr = dayElem.dateObj.toISOString().slice(0, 10);
                                if (roomBookedSet.has(dateStr)) {
                                    dayElem.classList.add('booked-date');
                                    const rc = getRoomRangeClass(dayElem);
                                    if (rc) dayElem.classList.add(rc);
                                    dayElem.setAttribute('title', 'This date is already booked');
                                }
                            },
                            onChange: function(selectedDates) {
                                const inVal = document.getElementById('checkInDate').value;
                                if (inVal && selectedDates.length > 0) {
                                    const d1 = new Date(inVal);
                                    const d2 = selectedDates[0];
                                    const diffDays = Math.round((d2 - d1) / (1000 * 60 * 60 * 24));
                                    if (diffDays >= 1) {
                                        const durSelect = document.getElementById('stayDurationSelect');
                                        if (durSelect) durSelect.value = Math.min(diffDays, 30);
                                    }
                                }
                                calculateRealTimePrice();
                            }
                        });
                    });
            }
        }

        function cancelBooking(resId) {
            if(!confirm('Are you sure you want to cancel this reservation? This cannot be undone.')) return;
            
            const formData = new FormData();
            formData.append('res_id', resId);
            
            fetch('api/cancel_reservation.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if(data.success) location.reload();
            });
        }

        // Booking Confirmation Modal Interceptor:
        // NOTHING is booked until the guest reviews the summary + Terms
        // in #bookingConfirmModal and clicks Confirm Now.
        const mainBookingForm = document.getElementById('mainBookingForm');
        const bookingConfirmModal = new bootstrap.Modal(document.getElementById('bookingConfirmModal'));
        let pendingBookingType = 'room'; // 'room' | 'event'

        const ROOM_TERMS_LIST = `
            <li>A minimum of <strong>30% down payment</strong> is required before the reservation is accepted.</li>
            <li>Room pricing is calculated <strong>per night</strong> based on selected check-in and check-out dates.</li>
            <li>The number of rooms is <strong>fixed</strong>. A date is unavailable once the room has a pending or confirmed booking.</li>
            <li>Base price covers up to 5 guests. Additional ₱200/head applies from the 6th guest onwards per night.</li>
            <li>All reservations are subject to review and approval by management.</li>
            <li>Down payments are non-refundable on client cancellation.</li>
            <li>Guests are responsible for any damage caused to the room.</li>`;
        const EVENT_RULES_LIST = `
            <li>A minimum of <strong>30% down payment</strong> is required before the reservation is accepted.</li>
            <li>Full balance must be settled on or before the event date.</li>
            <li>Down payments are non-refundable on client cancellation.</li>
            <li>Guest count must not exceed the package capacity.</li>
            <li>No nails, staples, or open flames; the client is liable for damage.</li>
            <li>Rescheduling needs 48-hour notice and is subject to availability.</li>`;

        function fmtConfirmDate(ymd) {
            if (!ymd) return '—';
            const d = new Date(ymd + 'T00:00:00');
            if (isNaN(d)) return ymd;
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        // Shared payment-details validation for the pending booking form
        function validateBookingPayment(type) {
            const isEvent = type === 'event';

            // Guest breakdown must be filled in and within capacity
            const totalGuests = getGuestTotal(type);
            if (totalGuests < 1) {
                alert('Please enter the number of guests for your booking.');
                return false;
            }
            if (isEvent) {
                const capacity = parseInt(document.getElementById('eventMaxCapacity')?.value) || 0;
                const capacityExceeded = capacity > 0 && total > capacity;
                if (capacityExceeded) {
                    alert(`Guest count (${total}) exceeds the package capacity of ${capacity} pax.`);
                    return false;
                }
            } else if (total > 20) {
                alert('A room reservation cannot exceed 20 guests. Please contact us for group arrangements.');
                return false;
            }

            const dpInput = isEvent ? document.getElementById('eventDownPayment') : document.getElementById('roomDownPayment');
            const minDp = parseFloat(dpInput?.getAttribute('data-min') || dpInput?.min || '0');
            const dpVal = parseFloat(dpInput?.value) || 0;
            if (dpVal < minDp) {
                alert(`Down payment must be at least ₱${minDp.toLocaleString()} (30% of total price).`);
                return false;
            }
            const methodSel = isEvent ? document.getElementById('eventPaymentMethod') : document.getElementById('roomPaymentMethod');
            if (!methodSel || !methodSel.value) {
                alert('Please select a payment method for your down payment.');
                return false;
            }
            const isArrival = methodSel.value.trim().toLowerCase() === 'pay upon arrival';
            const proofInput = isEvent ? document.getElementById('eventDownPaymentProof') : document.getElementById('roomDownPaymentProof');
            if (!isArrival && proofInput && proofInput.files.length === 0) {
                alert('Please upload a screenshot/photo of your online down payment.');
                return false;
            }
            return true;
        }

        // Fill the confirm modal (summary + correct Terms/Rules) and show it.
        // This is the ONLY path to a final booking.
        function openBookingConfirm(type) {
            if (!validateBookingPayment(type)) return;
            pendingBookingType = type;
            const isEvent = type === 'event';

            if (isEvent) {
                document.getElementById('confirmUnitLabel').innerHTML = '<i class="fas fa-box me-2"></i> Event Package:';
                document.getElementById('confirmDateInLabel').innerHTML = '<i class="fas fa-calendar-alt me-2"></i> Event Start Date:';
                document.getElementById('confirmDateOutLabel').innerHTML = '<i class="fas fa-calendar-check me-2"></i> Event End Date:';
                document.getElementById('confirmDurationLabel').innerHTML = '<i class="fas fa-clock me-2"></i> Event Duration:';
                document.getElementById('confirmRoom').textContent = document.getElementById('modalPkgTitle')?.textContent || 'Event Package';
                document.getElementById('confirmDate').textContent = fmtConfirmDate(document.getElementById('eventStartDate')?.value);
                document.getElementById('confirmCheckOutDate').textContent = fmtConfirmDate(document.getElementById('eventEndDate')?.value);
                document.getElementById('confirmDuration').textContent = document.getElementById('eventDurationDisplay')?.textContent || '1 Day(s)';
                document.getElementById('confirmGuests').textContent = (document.getElementById('eventGuestInput')?.value || '—') + ' Pax';
                document.getElementById('confirmGuestBreakdown').textContent = guestBreakdownText('event');
                document.getElementById('confirmTotal').textContent = document.getElementById('eventRealTimeTotal')?.textContent || '0.00';
                document.getElementById('confirmTermsTitle').innerHTML = '<i class="fas fa-gavel text-primary me-2"></i>Rules of Events';
                document.getElementById('confirmTermsList').innerHTML = EVENT_RULES_LIST;
                const readLink = document.getElementById('confirmTermsReadLink');
                readLink.textContent = 'Read Full Rules';
                readLink.setAttribute('data-bs-target', '#eventTermsModal');
                const agreeLink = document.getElementById('confirmTermsAgreeLink');
                agreeLink.textContent = 'Rules of Events';
                agreeLink.setAttribute('data-bs-target', '#eventTermsModal');
            } else {
                document.getElementById('confirmUnitLabel').innerHTML = '<i class="fas fa-bed me-2"></i> Room Type:';
                document.getElementById('confirmDateInLabel').innerHTML = '<i class="fas fa-calendar-alt me-2"></i> Check-in Date:';
                document.getElementById('confirmDateOutLabel').innerHTML = '<i class="fas fa-calendar-check me-2"></i> Check-out Date:';
                document.getElementById('confirmDurationLabel').innerHTML = '<i class="fas fa-moon me-2"></i> Stay Duration:';
                document.getElementById('confirmRoom').textContent = document.getElementById('modalRoomTitle')?.textContent || 'Room';
                document.getElementById('confirmDate').textContent = fmtConfirmDate(document.getElementById('checkInDate')?.value);
                document.getElementById('confirmCheckOutDate').textContent = fmtConfirmDate(document.getElementById('checkOutDate')?.value);
                const nights = parseInt(document.getElementById('stayDurationSelect')?.value) || 1;
                document.getElementById('confirmDuration').textContent = nights + ' Night' + (nights > 1 ? 's' : '');
                document.getElementById('confirmGuests').textContent = (document.getElementById('guestCountInput')?.value || '1') + ' Pax';
                document.getElementById('confirmGuestBreakdown').textContent = guestBreakdownText('room');
                document.getElementById('confirmTotal').textContent = document.getElementById('realTimeTotal')?.textContent || '0.00';
                document.getElementById('confirmTermsTitle').innerHTML = '<i class="fas fa-file-contract text-primary me-2"></i>Terms & Conditions';
                document.getElementById('confirmTermsList').innerHTML = ROOM_TERMS_LIST;
                const readLink = document.getElementById('confirmTermsReadLink');
                readLink.textContent = 'Read Full Terms';
                readLink.setAttribute('data-bs-target', '#termsModal');
                const agreeLink = document.getElementById('confirmTermsAgreeLink');
                agreeLink.textContent = 'Terms & Conditions';
                agreeLink.setAttribute('data-bs-target', '#termsModal');
            }

            // Fresh consent every time: unchecked box, disabled button
            const agreeBox = document.getElementById('termsAgree');
            if (agreeBox) agreeBox.checked = false;
            const confirmBtn = document.getElementById('finalConfirmBtn');
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Confirm Now';

            const formModalEl = document.getElementById(isEvent ? 'newEventBookingModal' : 'newBookingModal');
            bootstrap.Modal.getInstance(formModalEl)?.hide();
            bookingConfirmModal.show();
        }

        // Room form submit → summary + Terms review (no direct booking)
        mainBookingForm.addEventListener('submit', function(e) {
            e.preventDefault();
            openBookingConfirm('room');
        });

        // Enable "Confirm Now" only when terms are agreed
        document.getElementById('termsAgree')?.addEventListener('change', function() {
            document.getElementById('finalConfirmBtn').disabled = !this.checked;
        });

        // Go Back returns to the booking form (keeps entered details)
        document.getElementById('confirmGoBackBtn')?.addEventListener('click', function() {
            const isEvent = pendingBookingType === 'event';
            bookingConfirmModal.hide();
            const formModalEl = document.getElementById(isEvent ? 'newEventBookingModal' : 'newBookingModal');
            const formModalObj = bootstrap.Modal.getInstance(formModalEl);
            if (formModalObj) formModalObj.show();
            else new bootstrap.Modal(formModalEl).show();
        });

        // THE single final-booking handler: only fires from Confirm Now,
        // only after the Terms/Rules box is ticked.
        document.getElementById('finalConfirmBtn').addEventListener('click', function() {
            const agreeBox = document.getElementById('termsAgree');
            if (!agreeBox || !agreeBox.checked) {
                alert('Please read and agree to the Terms & Conditions to proceed.');
                return;
            }

            const isEvent = pendingBookingType === 'event';
            const form = isEvent ? document.getElementById('eventBookingForm') : mainBookingForm;
            const modalToHide = document.getElementById(isEvent ? 'newEventBookingModal' : 'newBookingModal');

            if (!validateBookingPayment(pendingBookingType)) return;

            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Sending...';

            const formData = new FormData(form);
            fetch('api/make_reservation.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    bookingConfirmModal.hide();
                    if (modalToHide) bootstrap.Modal.getInstance(modalToHide)?.hide();
                    new bootstrap.Modal(document.getElementById('thankYouModal')).show();
                    document.getElementById('thankYouMessage').innerText = data.message;
                } else {
                    alert(data.message);
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Confirm Now';
                }
            })
            .catch(err => {
                console.error(err);
                alert("An error occurred during booking. Please try again.");
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Confirm Now';
            });
        });

        // Open the full Terms modal ON TOP of the booking confirm form, then return to it
        const termsModalEl = document.getElementById('termsModal');
        document.addEventListener('click', function(e) {
            const link = e.target.closest('[data-bs-target="#termsModal"]');
            if (!link) return;
            const confirmEl = document.getElementById('bookingConfirmModal');
            if (confirmEl && confirmEl.classList.contains('show')) {
                e.preventDefault();
                bootstrap.Modal.getInstance(confirmEl)?.hide();
                const tModal = new bootstrap.Modal(termsModalEl);
                tModal.show();
                termsModalEl.addEventListener('hidden.bs.modal', function handler() {
                    termsModalEl.removeEventListener('hidden.bs.modal', handler);
                    new bootstrap.Modal(confirmEl).show();
                });
                return;
            }
            // Same swap for the registration form so the terms don't stack
            // two backdrops on small screens (form state is preserved)
            const regEl = document.getElementById('registerModal');
            if (regEl && regEl.classList.contains('show')) {
                e.preventDefault();
                bootstrap.Modal.getInstance(regEl)?.hide();
                const tModal = new bootstrap.Modal(termsModalEl);
                tModal.show();
                termsModalEl.addEventListener('hidden.bs.modal', function handler() {
                    termsModalEl.removeEventListener('hidden.bs.modal', handler);
                    new bootstrap.Modal(regEl).show();
                });
            }
        });

        // Show Success Modal from URL
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const success = urlParams.get('success');
            
            if (success) {
                const thankModal = new bootstrap.Modal(document.getElementById('thankYouModal'));
                const msg = document.getElementById('thankYouMessage');
                
                if (success === 'booking') {
                    msg.innerHTML = "Your <b>reservation request</b> has been sent! Check your notifications later for approval.";
                } else if (success === 'payment') {
                    msg.innerHTML = "Your <b>payment proof</b> has been uploaded! We will verify it and update your booking status soon.";
                }
                
                thankModal.show();

                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }

            // Deep link from the admin portal: open Forgot Password directly
            if (urlParams.get('show_forgot') === '1') {
                resetForgotModal();
                new bootstrap.Modal(document.getElementById('forgotPwModal')).show();
                urlParams.delete('show_forgot');
                const clean = urlParams.toString()
                    ? window.location.pathname + '?' + urlParams.toString()
                    : window.location.pathname;
                window.history.replaceState({}, document.title, clean);
            }
        });

        // Notification Popup Logic
        function showNotification(msg, date, type) {
            const modalEl = document.getElementById('notificationDetailModal');
            const header = document.getElementById('notifHeader');
            const icon = document.getElementById('notifIcon');
            const message = document.getElementById('notifMessage');
            const dateEl = document.getElementById('notifDate');

            const typeConfig = {
                success: { bg: 'bg-success', icon: 'fa-check-circle' },
                warning: { bg: 'bg-warning', icon: 'fa-exclamation-triangle' },
                danger:  { bg: 'bg-danger', icon: 'fa-times-circle' },
                info:    { bg: 'bg-info', icon: 'fa-info-circle' }
            };

            const config = typeConfig[type] || typeConfig.info;
            
            header.className = `p-4 text-white d-flex align-items-center gap-3 ${config.bg}`;
            icon.className = `fas ${config.icon} fa-lg`;
            message.innerHTML = msg;
            dateEl.textContent = date;

            // Hide profile modal if it was open (to avoid stacking backdrop issues)
            const profileModalEl = document.getElementById('profileModal');
            const profileModalObj = bootstrap.Modal.getInstance(profileModalEl);
            if(profileModalObj) profileModalObj.hide();

            // Open the detail modal
            new bootstrap.Modal(modalEl).show();
        }

        // ── Reservation Detail (My Reservations → View Details) ──
        function viewReservationDetail(resId) {
            fetch(`api/get_reservation_detail.php?id=${resId}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        alert(data.message || 'Could not load reservation details.');
                        return;
                    }
                    const r = data.reservation;
                    const statusColors = {
                        'Approved': 'bg-success', 'Pending Approval': 'bg-warning text-dark',
                        'Rejected': 'bg-danger', 'Completed': 'bg-primary', 'Cancelled': 'bg-secondary'
                    };
                    document.getElementById('resDetailId').textContent = '#' + String(r.id).padStart(5, '0');
                    const statusEl = document.getElementById('resDetailStatus');
                    statusEl.textContent = r.status || '—';
                    statusEl.className = 'badge rounded-pill ' + (statusColors[r.status] || 'bg-secondary');
                    document.getElementById('resDetailType').textContent = r.booking_type ? r.booking_type.charAt(0).toUpperCase() + r.booking_type.slice(1) : '—';
                    document.getElementById('resDetailUnit').textContent = r.room_type || '—';
                    document.getElementById('resDetailCheckIn').textContent = r.check_in_date || '—';
                    document.getElementById('resDetailCheckOut').textContent = r.check_out_date || '—';
                    document.getElementById('resDetailGuests').textContent = (r.guest_count || '—') + (r.guest_count ? ' Pax' : '');
                    const resBreakdown = (r.guest_breakdown || []).map(g => `${g.guest_count} ${g.type_name}`).join(', ');
                    document.getElementById('resDetailGuestBreakdown').textContent = resBreakdown || '—';
                    document.getElementById('resDetailTotal').textContent = r.total_price !== null && r.total_price !== undefined
                        ? '₱' + parseFloat(r.total_price).toLocaleString('en-US', {minimumFractionDigits: 2}) : '—';
                    document.getElementById('resDetailPay').textContent = r.payment_status || 'No payment yet';
                    document.getElementById('resDetailMethod').textContent = r.payment_method || '—';
                    document.getElementById('resDetailPayBtn').onclick = function() { uploadPaymentFor(r.id); };

                    // Avoid stacked backdrops on small screens: hide list modal first
                    const listEl = document.getElementById('myReservationModal');
                    const listObj = listEl ? bootstrap.Modal.getInstance(listEl) : null;
                    if (listObj) listObj.hide();

                    new bootstrap.Modal(document.getElementById('reservationDetailModal')).show();
                })
                .catch(() => alert('Could not load reservation details. Please try again.'));
        }

        // Open the per-reservation upload-payment modal (falls back to reload if stale)
        function uploadPaymentFor(resId) {
            const target = document.getElementById('paymentModal' + resId);
            if (target) {
                ['myReservationModal', 'reservationDetailModal'].forEach(mid => {
                    const el = document.getElementById(mid);
                    const obj = el ? bootstrap.Modal.getInstance(el) : null;
                    if (obj) obj.hide();
                });
                new bootstrap.Modal(target).show();
            } else {
                // List/modal markup is stale (e.g. after background refresh) — reload to sync
                location.reload();
            }
        }

        // ─── REAL-TIME POLLING ─────────────────────────────────────────
        let lastNotifId = <?php echo $unread_count > 0 ? ($notifications[0]['id'] ?? 0) : 0; ?>;
        let notifPollInterval = null;

        function pollNotifications() {
            fetch(`api/get_notifications.php?last_id=${lastNotifId}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) return;

                    // Update badge count
                    const badge = document.querySelector('#notifDropdown .badge rounded-pill.bg-danger');
                    if (data.unread_count > 0) {
                        if (badge) {
                            badge.textContent = data.unread_count;
                        } else {
                            const btn = document.querySelector('#notifDropdown');
                            if (btn) {
                                const span = document.createElement('span');
                                span.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                                span.style.fontSize = '0.6rem';
                                span.textContent = data.unread_count;
                                btn.appendChild(span);
                            }
                        }
                    } else if (badge) {
                        badge.remove();
                    }

                    // Add new notifications to dropdown
                    if (data.notifications.length > 0) {
                        const dropdown = document.querySelector('#notifDropdown + .dropdown-menu');
                        if (dropdown) {
                            data.notifications.forEach(n => {
                                lastNotifId = Math.max(lastNotifId, parseInt(n.id));

                                // Remove "no notifications" placeholder if present
                                const placeholder = dropdown.querySelector('.text-center.text-muted');
                                if (placeholder) placeholder.remove();

                                const li = document.createElement('li');
                                li.className = 'py-2 px-2 border-bottom notification-item';
                                li.style.cursor = 'pointer';
                                li.setAttribute('onclick', `showNotification('${n.message.replace(/'/g, "\\'")}', '${new Date(n.created_at).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'})}', '${n.type}')`);
                                li.innerHTML = `
                                    <i class="fas fa-circle mt-1 text-${n.type || 'info'}" style="font-size:0.5rem;"></i>
                                    <div class="text-dark fw-medium small">${n.message}</div>
                                    <div class="x-small text-muted mt-1">${new Date(n.created_at).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'})}</div>
                                `;
                                dropdown.insertBefore(li, dropdown.children[1] || null);

                                // Show toast notification for new items
                                showToast(n.message, n.type);
                            });
                        }
                    }
                })
                .catch(err => console.error('Notification poll error:', err));
        }

        function showToast(message, type) {
            const toastContainer = document.getElementById('toastContainer') || createToastContainer();
            const typeConfig = {
                success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger', info: 'bg-info'
            };
            const bgClass = typeConfig[type] || 'bg-info';

            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white ${bgClass} border-0 show`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body small">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            toastContainer.appendChild(toast);
            setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 5000);
        }

        function createToastContainer() {
            const container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
            return container;
        }

        // Poll notifications every 10 seconds
        notifPollInterval = setInterval(pollNotifications, 10000);

        // Refresh My Reservations table when modal opens
        document.getElementById('myReservationModal')?.addEventListener('show.bs.modal', function() {
            refreshMyReservations();
        });

        function escHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
        }

        function refreshMyReservations() {
            fetch('api/get_reservations.php')
                .then(res => res.json())
                .then(data => {
                    if (!data.success) return;
                    const tbody = document.querySelector('#myReservationModal tbody');
                    if (!tbody) return;

                    if (data.reservations.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-inbox fa-3x mb-3 d-block opacity-25"></i>No Active Reservations</td></tr>';
                        return;
                    }

                    tbody.innerHTML = data.reservations.map(r => {
                        const statusColors = {
                            'Pending Approval': 'warning text-dark', 'Approved': 'success', 'Rejected': 'danger',
                            'Completed': 'primary', 'Cancelled': 'secondary'
                        };
                        const statusColor = statusColors[r.status] || 'secondary';
                        const item_name = r.booking_type === 'room' ? (r.room_type || '—') : (r.package_name || '—');
                        const checkin = new Date(r.check_in_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
                        let dateDisplay = checkin;
                        if (r.booking_type === 'room' && r.check_out_date && r.check_out_date !== r.check_in_date) {
                            const checkout = new Date(r.check_out_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
                            const nights = Math.max(1, Math.round((new Date(r.check_out_date) - new Date(r.check_in_date)) / 86400000));
                            dateDisplay = `${checkin}<br><small class="text-muted">to ${checkout} (${nights} night${nights > 1 ? 's' : ''})</small>`;
                        }
                        const typeLabel = r.booking_type === 'room' ? 'Room' : 'Event';

                        return `<tr>
                            <td class="small fw-bold text-muted">#${String(r.id).padStart(5, '0')}</td>
                            <td><span class="badge bg-light text-primary">${typeLabel}</span></td>
                            <td class="fw-bold">${escHtml(item_name)}</td>
                            <td class="small">${dateDisplay}</td>
                            <td>${escHtml(r.guest_count)} Pax</td>
                            <td class="fw-bold text-primary">₱${parseFloat(r.total_price).toLocaleString('en-US', {minimumFractionDigits:2})}</td>
                            <td><span class="badge bg-${statusColor} rounded-pill">${escHtml(r.status)}</span></td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    <button class="btn btn-sm btn-outline-primary" onclick="uploadPaymentFor(${parseInt(r.id, 10)})"><i class="fas fa-file-invoice-dollar"></i> Upload Payment</button>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="viewReservationDetail(${parseInt(r.id, 10)})"><i class="fas fa-eye"></i> View Details</button>
                                    ${(r.status === 'Completed' && !r.review_id) ? `<a class="btn btn-sm btn-warning" href="review.php?res_id=${parseInt(r.id, 10)}"><i class="fas fa-star"></i> Rate your stay</a>` : ''}
                                </div>
                            </td>
                        </tr>`;
                    }).join('');
                })
                .catch(err => console.error('Reservations refresh error:', err));
        }

        // Refresh booked dates on calendar every 30 seconds
        let calendarRefreshInterval = setInterval(() => {
            if (fpInInstance) {
                const currentItemId = document.getElementById('bookingItemId')?.value;
                if (currentItemId) {
                    fetch(`api/get_booked_dates.php?item_id=${currentItemId}&type=room`)
                        .then(res => res.json())
                        .then(dates => {
                            roomBookedSet = new Set(dates);
                            if (fpInInstance) fpInInstance.set('disable', dates);
                            if (fpOutInstance) fpOutInstance.set('disable', dates);
                        });
                }
            }
        }, 30000);
    </script>

    <!-- Scroll-to-top arrow (own script block AFTER the markup so the
         button exists when looked up, and it works even if the main
         script above throws) -->
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
