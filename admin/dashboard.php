<?php
session_start();
require_once '../config/db.php';

// Check if admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Fetch stats
$total_bookings = $pdo->query("SELECT COUNT(*) FROM reservations")->fetchColumn();
$total_revenue = $pdo->query("SELECT SUM(amount) FROM payments WHERE status='Paid'")->fetchColumn() ?? 0;
$pending_approvals = $pdo->query("SELECT COUNT(*) FROM reservations WHERE status='Pending Approval'")->fetchColumn();
$active_rooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='available'")->fetchColumn();

// Fetch admin notifications
$notif_stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
$notif_stmt->execute([$_SESSION['user_id']]);
$notifications = $notif_stmt->fetchAll();
$unread_count = count(array_filter($notifications, fn($n) => $n['is_read'] == 0));

// Mark all as read when the page loads
$pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$_SESSION['user_id']]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Centrotel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            width: 280px;
            height: 100vh;
            position: fixed;
            background: var(--primary);
            color: white;
            padding: 20px;
        }
        .main-content {
            margin-left: 280px;
            padding: 40px;
        }
        .nav-link-admin {
            color: rgba(255,255,255,0.7);
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 5px;
            display: block;
            text-decoration: none;
            transition: var(--transition);
        }
        .nav-link-admin:hover, .nav-link-admin.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .stat-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            transition: var(--transition);
        }
        .stat-card:hover { transform: translateY(-5px); }
    </style>
</head>
<body>

    <div class="sidebar" id="sidebar">
        <div class="d-flex align-items-center mb-5 px-3">
            <img src="../assets/img/logo.jpg" alt="Logo" height="40" class="me-2">
            <span class="fs-5 fw-bold">Admin Panel</span>
        </div>
        <nav>
            <a href="dashboard.php" class="nav-link-admin active"><i class="fas fa-chart-line me-2"></i> Dashboard</a>
            <a href="rooms.php" class="nav-link-admin"><i class="fas fa-bed me-2"></i> Manage Rooms</a>
            <a href="packages.php" class="nav-link-admin"><i class="fas fa-box me-2"></i> Event Packages</a>
            <a href="reservations.php" class="nav-link-admin"><i class="fas fa-calendar-alt me-2"></i> Bookings</a>
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
        <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center mb-4 mb-lg-5">
            <div>
                <h2 class="fw-bold">Welcome, Admin</h2>
                <p class="text-muted">Here's what's happening at Centrotel today.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 gap-sm-3 align-items-center">
                <div class="dropdown">
                    <button id="adminNotifDropdown" class="btn btn-white shadow-sm px-4 py-2 rounded-pill position-relative" data-bs-toggle="dropdown">
                        <i class="fas fa-bell me-2"></i> Notifications
                        <?php if ($unread_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.6rem;"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg p-2" style="max-width:90vw;width:340px;max-height:400px;overflow-y:auto;">
                        <li class="fw-bold small text-muted px-2 pb-2 border-bottom">Notifications</li>
                        <?php if (empty($notifications)): ?>
                        <li class="text-center text-muted small py-4">No notifications yet</li>
                        <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                        <li class="py-2 px-2 border-bottom">
                            <div class="d-flex align-items-start gap-2">
                                <span class="text-primary flex-shrink-0">
                                    <?php
                                    $icon = 'info-circle';
                                    if ($notif['type'] == 'success') $icon = 'check-circle';
                                    elseif ($notif['type'] == 'warning') $icon = 'exclamation-triangle';
                                    elseif ($notif['type'] == 'danger') $icon = 'times-circle';
                                    ?>
                                    <i class="fas fa-<?php echo $icon; ?>"></i>
                                </span>
                                <div>
                                    <div class="small"><?php echo $notif['message']; ?></div>
                                    <div class="x-small text-muted mt-1"><?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?></div>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="profile-img rounded-circle bg-white shadow-sm p-1">
                    <img src="https://ui-avatars.com/api/?name=Admin&background=1a365d&color=fff" class="rounded-circle" width="40" alt="Admin">
                </div>
            </div>
        </div>

        <div class="row g-3 g-lg-4 mb-4 mb-lg-5">
            <div class="col-12 col-sm-6 col-md-3">
                <div class="stat-card card p-4">
                    <div class="d-flex justify-content-between mb-3">
                        <div class="bg-primary bg-opacity-10 p-3 rounded-3 text-primary">
                            <i class="fas fa-calendar-check fa-lg"></i>
                        </div>
                        <span class="text-success small fw-bold">+12% <i class="fas fa-arrow-up"></i></span>
                    </div>
                    <h3 class="fw-bold mb-0 text-break"><?php echo $total_bookings; ?></h3>
                    <p class="text-muted small mb-0">Total Reservations</p>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="stat-card card p-4">
                    <div class="d-flex justify-content-between mb-3">
                        <div class="bg-success bg-opacity-10 p-3 rounded-3 text-success">
                            <i class="fas fa-money-bill-wave fa-lg"></i>
                        </div>
                        <span class="text-success small fw-bold">+8% <i class="fas fa-arrow-up"></i></span>
                    </div>
                    <h3 class="fw-bold mb-0 text-break">₱<?php echo number_format($total_revenue, 2); ?></h3>
                    <p class="text-muted small mb-0">Total Revenue</p>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="stat-card card p-4">
                    <div class="d-flex justify-content-between mb-3">
                        <div class="bg-warning bg-opacity-10 p-3 rounded-3 text-warning">
                            <i class="fas fa-clock fa-lg"></i>
                        </div>
                        <span class="text-warning small fw-bold">Manual Review</span>
                    </div>
                    <h3 class="fw-bold mb-0"><?php echo $pending_approvals; ?></h3>
                    <p class="text-muted small mb-0">Pending Approvals</p>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <div class="stat-card card p-4">
                    <div class="d-flex justify-content-between mb-3">
                        <div class="bg-info bg-opacity-10 p-3 rounded-3 text-info">
                            <i class="fas fa-door-open fa-lg"></i>
                        </div>
                        <span class="text-info small fw-bold">Daily Check</span>
                    </div>
                    <h3 class="fw-bold mb-0"><?php echo $active_rooms; ?></h3>
                    <p class="text-muted small mb-0">Available Rooms</p>
                </div>
            </div>
        </div>

        <div class="row g-3 g-lg-4">
            <div class="col-12 col-lg-8">
                <div class="card stat-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="fw-bold mb-0">Recent Reservations</h5>
                        <a href="reservations.php" class="text-decoration-none small fw-bold">View All</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Guest</th>
                                    <th>Booking</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt = $pdo->query("SELECT r.*, u.full_name FROM reservations r JOIN users u ON r.user_id = u.id ORDER BY r.created_at DESC LIMIT 5");
                                while ($row = $stmt->fetch()) {
                                    $status_class = match($row['status']) {
                                        'Approved' => 'bg-success',
                                        'Pending Approval' => 'bg-warning',
                                        'Rejected' => 'bg-danger',
                                        default => 'bg-secondary'
                                    };
                                    echo "<tr>
                                        <td>
                                            <div class='fw-bold'>{$row['full_name']}</div>
                                        </td>
                                        <td><span class='badge bg-primary bg-opacity-10 text-primary'>{$row['booking_type']}</span></td>
                                        <td class='small'>".date('M d, Y', strtotime($row['check_in_date']))."</td>
                                        <td class='fw-bold'>₱".number_format($row['total_price'], 2)."</td>
                                        <td><span class='badge {$status_class}'>{$row['status']}</span></td>
                                    </tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="card stat-card p-4">
                    <h5 class="fw-bold mb-4">Booking Distribution</h5>
                    <canvas id="bookingChart" height="300"></canvas>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const ctx = document.getElementById('bookingChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Rooms', 'Events'],
                datasets: [{
                    data: [65, 35],
                    backgroundColor: ['#1a365d', '#d4af37'],
                    borderWidth: 0
                }]
            },
            options: {
                plugins: {
                    legend: { position: 'bottom' }
                },
                cutout: '70%'
            }
        });

        // ─── REAL-TIME POLLING ─────────────────────────────────────────
        let lastAdminNotifId = <?php echo $unread_count > 0 ? ($notifications[0]['id'] ?? 0) : 0; ?>;

        function pollAdminDashboard() {
            // Poll notifications
            fetch(`../api/get_notifications.php?last_id=${lastAdminNotifId}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) return;
                    const badge = document.querySelector('#adminNotifDropdown .badge.rounded-pill.bg-danger');
                    if (data.unread_count > 0) {
                        if (badge) badge.textContent = data.unread_count;
                        else {
                            const btn = document.getElementById('adminNotifDropdown');
                            if (btn) {
                                const span = document.createElement('span');
                                span.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                                span.style.fontSize = '0.6rem';
                                span.textContent = data.unread_count;
                                btn.appendChild(span);
                            }
                        }
                    } else if (badge) badge.remove();

                    if (data.notifications.length > 0) {
                        const dropdown = document.querySelector('#adminNotifDropdown + .dropdown-menu');
                        if (dropdown) {
                            data.notifications.forEach(n => {
                                lastAdminNotifId = Math.max(lastAdminNotifId, parseInt(n.id));
                                const placeholder = dropdown.querySelector('.text-center');
                                if (placeholder) placeholder.remove();
                                const li = document.createElement('li');
                                li.className = 'py-2 px-2 border-bottom';
                                const icons = { success: 'check-circle', warning: 'exclamation-triangle', danger: 'times-circle', info: 'info-circle' };
                                li.innerHTML = `<div class="d-flex align-items-start gap-2"><span class="text-primary flex-shrink-0"><i class="fas fa-${icons[n.type] || 'info-circle'}"></i></span><div><div class="small">${n.message}</div><div class="x-small text-muted mt-1">${new Date(n.created_at).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'})}</div></div></div>`;
                                dropdown.insertBefore(li, dropdown.children[1] || null);
                                showAdminToast(n.message, n.type);
                            });
                        }
                    }
                })
                .catch(err => console.error('Admin notif poll error:', err));

            // Poll stats
            fetch('../api/get_admin_stats.php')
                .then(res => res.json())
                .then(data => {
                    if (!data.success) return;
                    const cards = document.querySelectorAll('.stat-card h3');
                    if (cards.length >= 4) {
                        cards[0].textContent = data.total_bookings;
                        cards[1].textContent = '₱' + data.total_revenue.toLocaleString('en-US', {minimumFractionDigits:2});
                        cards[2].textContent = data.pending_approvals;
                        cards[3].textContent = data.active_rooms;
                    }
                })
                .catch(err => console.error('Admin stats poll error:', err));
        }

        function showAdminToast(message, type) {
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

        setInterval(pollAdminDashboard, 10000);

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
