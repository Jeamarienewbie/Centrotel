<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$msg = null;
$msg_type = 'success';

// Moderate actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $review_id = (int)($_POST['review_id'] ?? 0);
    if ($review_id > 0) {
        try {
            if (isset($_POST['hide_review'])) {
                $pdo->prepare("UPDATE reviews SET status = 'hidden' WHERE id = ?")->execute([$review_id]);
                $msg = "Review #$review_id hidden from guests.";
            } elseif (isset($_POST['unhide_review'])) {
                $pdo->prepare("UPDATE reviews SET status = 'approved' WHERE id = ?")->execute([$review_id]);
                $msg = "Review #$review_id is visible to guests again.";
            } elseif (isset($_POST['delete_review'])) {
                $pdo->prepare("DELETE FROM reviews WHERE id = ?")->execute([$review_id]);
                $msg = "Review #$review_id deleted permanently.";
            }
        } catch (PDOException $e) {
            $msg = "Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Filters
$filter_type = $_GET['type'] ?? 'all';
if (!in_array($filter_type, ['all', 'room', 'event'], true)) {
    $filter_type = 'all';
}
$filter_status = $_GET['status'] ?? 'all';
if (!in_array($filter_status, ['all', 'approved', 'hidden'], true)) {
    $filter_status = 'all';
}

// Stats
$stat_total = (int)$pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
$stat_avg = (float)($pdo->query("SELECT COALESCE(AVG(rating), 0) FROM reviews WHERE status = 'approved'")->fetchColumn());
$stat_hidden = (int)$pdo->query("SELECT COUNT(*) FROM reviews WHERE status = 'hidden'")->fetchColumn();

// List
$where = [];
$params = [];
if ($filter_type !== 'all') {
    $where[] = "rv.booking_type = ?";
    $params[] = $filter_type;
}
if ($filter_status !== 'all') {
    $where[] = "rv.status = ?";
    $params[] = $filter_status;
}
$sql = "SELECT rv.*, u.full_name,
        rm.room_number, rm.room_type, ep.package_name
    FROM reviews rv
    JOIN users u ON u.id = rv.user_id
    LEFT JOIN rooms rm ON rm.id = rv.item_id AND rv.booking_type = 'room'
    LEFT JOIN event_packages ep ON ep.id = rv.item_id AND rv.booking_type = 'event'"
    . ($where ? " WHERE " . implode(' AND ', $where) : "") .
    " ORDER BY rv.created_at DESC";
$list_stmt = $pdo->prepare($sql);
$list_stmt->execute($params);
$reviews = $list_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Reviews | Centrotel Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .sidebar { width: 280px; height: 100vh; position: fixed; background: var(--primary); color: white; padding: 20px; }
        .main-content { margin-left: 280px; padding: 40px; }
        .nav-link-admin { color: rgba(255,255,255,0.7); padding: 12px 15px; border-radius: 10px; margin-bottom: 5px; display: block; text-decoration: none; }
        .nav-link-admin:hover, .nav-link-admin.active { background: rgba(255,255,255,0.1); color: white; }
        .stat-card { border: none; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
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
            <a href="reservations.php" class="nav-link-admin"><i class="fas fa-calendar-alt me-2"></i> Bookings</a>
            <a href="reviews.php" class="nav-link-admin active"><i class="fas fa-star me-2"></i> Reviews</a>
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
        <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-0">Guest Reviews & Ratings</h2>
                <p class="text-muted small mb-0">Only approved reviews appear on the site. One review per completed booking.</p>
            </div>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle me-2"></i> <?php echo htmlspecialchars($msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3 g-lg-4 mb-4">
            <div class="col-12 col-sm-4">
                <div class="stat-card card p-4">
                    <div class="small text-muted mb-1">Total Reviews</div>
                    <div class="h3 fw-bold mb-0"><?php echo $stat_total; ?></div>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="stat-card card p-4">
                    <div class="small text-muted mb-1">Average Rating</div>
                    <div class="h3 fw-bold mb-0 text-warning"><?php echo number_format($stat_avg, 1); ?> ★</div>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="stat-card card p-4">
                    <div class="small text-muted mb-1">Hidden</div>
                    <div class="h3 fw-bold mb-0 text-muted"><?php echo $stat_hidden; ?></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-3 p-lg-4">
                <form method="GET" class="d-flex flex-wrap align-items-end gap-2 gap-lg-3">
                    <div>
                        <label class="form-label small fw-bold mb-1">Type</label>
                        <select name="type" class="form-select">
                            <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>Rooms + Events</option>
                            <option value="room" <?php echo $filter_type === 'room' ? 'selected' : ''; ?>>Rooms</option>
                            <option value="event" <?php echo $filter_type === 'event' ? 'selected' : ''; ?>>Events</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label small fw-bold mb-1">Status</label>
                        <select name="status" class="form-select">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="hidden" <?php echo $filter_status === 'hidden' ? 'selected' : ''; ?>>Hidden</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-premium"><i class="fas fa-filter me-2"></i>Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Date</th>
                                <th>Guest</th>
                                <th>Unit</th>
                                <th>Rating</th>
                                <th style="min-width: 220px;">Feedback</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reviews as $rv):
                                if ($rv['booking_type'] === 'event') {
                                    $unit = $rv['package_name'] ?: 'Event Package';
                                } else {
                                    $unit = 'Room ' . ($rv['room_number'] ?? '?');
                                }
                            ?>
                            <tr>
                                <td class="ps-4 small"><?php echo date('M d, Y', strtotime($rv['created_at'])); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($rv['full_name']); ?></div>
                                    <div class="small text-muted">Booking #<?php echo str_pad($rv['reservation_id'], 5, '0', STR_PAD_LEFT); ?></div>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($unit); ?></span></td>
                                <td class="text-nowrap"><?php echo starsHtml($rv['rating']); ?> <span class="fw-bold small"><?php echo (int)$rv['rating']; ?>/5</span></td>
                                <td class="small" style="overflow-wrap:anywhere;"><?php echo nl2br(htmlspecialchars($rv['feedback'])); ?></td>
                                <td>
                                    <?php if ($rv['status'] === 'approved'): ?>
                                        <span class="badge bg-success">Approved</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Hidden</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="review_id" value="<?php echo $rv['id']; ?>">
                                        <?php if ($rv['status'] === 'approved'): ?>
                                            <button type="submit" name="hide_review" class="btn btn-sm btn-light rounded-circle me-1" title="Hide from site"><i class="fas fa-eye-slash text-warning"></i></button>
                                        <?php else: ?>
                                            <button type="submit" name="unhide_review" class="btn btn-sm btn-light rounded-circle me-1" title="Show on site"><i class="fas fa-eye text-success"></i></button>
                                        <?php endif; ?>
                                        <button type="submit" name="delete_review" class="btn btn-sm btn-light rounded-circle" title="Delete permanently" onclick="return confirm('Delete this review permanently?')"><i class="fas fa-trash text-danger"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($reviews)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-5">No reviews found. Completed bookings trigger an SMS survey to guests.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
