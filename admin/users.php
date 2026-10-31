<?php
session_start();
require_once '../config/db.php';

// Check if Admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Handle customer deletion
if (isset($_POST['delete_customer_id']) && $_POST['delete_customer_id']) {
    $customer_id = (int)$_POST['delete_customer_id'];
    
    // Check if customer has reservations
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reservations WHERE user_id = ?");
    $stmt->execute([$customer_id]);
    $has_reservations = $stmt->fetch()['count'] > 0;
    
    if ($has_reservations) {
        echo "<script>alert('Cannot delete customer: they have existing reservations.');</script>";
    } else {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'customer'");
        $stmt->execute([$customer_id]);
        echo "<script>alert('Customer deleted successfully'); window.location.href='users.php';</script>";
    }
    
    // Refresh user data after deletion (or just reload)
    // Since we already redirected, this won't execute, but just in case:
    $users = $pdo->query("SELECT * FROM users WHERE role = 'customer' ORDER BY created_at DESC")->fetchAll();
}
$users = $pdo->query("SELECT * FROM users WHERE role = 'customer' ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Records | Centrotel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>.sidebar { width: 280px; height: 100vh; position: fixed; background: var(--primary); color: white; padding: 20px; } .main-content { margin-left: 280px; padding: 40px; } .nav-link-admin { color: rgba(255,255,255,0.7); padding: 12px 15px; border-radius: 10px; margin-bottom: 5px; display: block; text-decoration: none; } .nav-link-admin:hover, .nav-link-admin.active { background: rgba(255,255,255,0.1); color: white; }</style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="d-flex align-items-center mb-5 px-3"><img src="../assets/img/logo.jpg" alt="Logo" height="40" class="me-2"><span class="fs-4 fw-bold">Admin Panel</span></div>
        <nav>
            <a href="dashboard.php" class="nav-link-admin"><i class="fas fa-chart-line me-2"></i> Dashboard</a>
            <a href="rooms.php" class="nav-link-admin"><i class="fas fa-bed me-2"></i> Manage Rooms</a>
            <a href="packages.php" class="nav-link-admin"><i class="fas fa-box me-2"></i> Event Packages</a>
            <a href="reservations.php" class="nav-link-admin"><i class="fas fa-calendar-alt me-2"></i> Bookings</a>
        <a href="reviews.php" class="nav-link-admin"><i class="fas fa-star me-2"></i> Reviews</a>
            <a href="users.php" class="nav-link-admin active"><i class="fas fa-users me-2"></i> Customers</a>
            <a href="guest_types.php" class="nav-link-admin"><i class="fas fa-venus-mars me-2"></i> Guest Types</a>
            <a href="reports.php" class="nav-link-admin"><i class="fas fa-file-invoice-dollar me-2"></i> Financial Reports</a>
            <a href="payment_settings.php" class="nav-link-admin"><i class="fas fa-credit-card me-2"></i> Payment Settings</a>
            <div class="mt-5 pt-5"><a href="../auth/logout.php" class="nav-link-admin text-danger" onclick="return confirm('Are you sure you want to logout?')"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></div>
        </nav>
    </div>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="main-content">
        <button class="btn btn-primary sidebar-toggle d-none mb-3" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
        <h2 class="fw-bold mb-4 mb-lg-5">Customer Records</h2>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Registered</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($users as $user): ?>
                        <tr>
                            <td class="ps-4 fw-bold"><?php echo $user['full_name']; ?></td>
                            <td><?php echo $user['email']; ?></td>
                            <td><?php echo $user['phone']; ?></td>
                            <td class="small"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-light rounded-circle me-1" onclick="viewCustomer(<?php echo $user['id']; ?>)"><i class="fas fa-eye text-primary"></i></button>
                                <button class="btn btn-sm btn-light rounded-circle" onclick="document.getElementById('deleteCustomerForm').querySelector('input').value = <?php echo $user['id']; ?>; document.getElementById('deleteCustomerForm').submit();"><i class="fas fa-trash text-danger"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
        
        // View customer details
        function viewCustomer(id) {
            fetch('../api/get_customer_detail.php?id=' + id)
                .then(res => {
                    console.log('API Response Status:', res.status);
                    console.log('API Response OK:', res.ok);
                    return res.json();
                })
                .then(data => {
                    console.log('API Data:', data);
                    if (data.success) {
                        const c = data.customer;
                        document.getElementById('modal-id').textContent = c.id;
                        document.getElementById('modal-name').textContent = c.name;
                        document.getElementById('modal-email').textContent = c.email;
                        document.getElementById('modal-phone').textContent = c.phone;
                        document.getElementById('modal-address').textContent = c.address || '—';
                        document.getElementById('modal-security').textContent = c.security_question;
                        document.getElementById('modal-registered').textContent = c.registered;
                        const modal = new bootstrap.Modal(document.getElementById('customerDetailModal'));
                        modal.show();
                    } else {
                        alert('API Error: ' + data.message);
                    }
                })
                .catch(err => {
                    console.error('Fetch error:', err);
                    alert('Error loading customer details. Please try again.');
                });
        }
        
    </script>
    
    <!-- Delete customer form (hidden) -->
<form id="deleteCustomerForm" action="?" method="POST" style="display: none;">
    <input type="hidden" name="delete_customer_id" value="">
</form>
    
    <!-- Customer Detail Modal -->
    <div class="modal fade" id="customerDetailModal" tabindex="-1" aria-labelledby="customerDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="customerDetailModalLabel">Customer Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <strong>ID:</strong> <span id="modal-id" class="text-secondary"></span>
                    </div>
                    <div class="mb-3">
                        <strong>Name:</strong> <span id="modal-name"></span>
                    </div>
                    <div class="mb-3">
                        <strong>Email:</strong> <span id="modal-email"></span>
                    </div>
                    <div class="mb-3">
                        <strong>Phone:</strong> <span id="modal-phone"></span>
                    </div>
                    <div class="mb-3">
                        <strong>Address:</strong> <span id="modal-address"></span>
                    </div>
                    <div class="mb-3">
                        <strong>Security Question:</strong> <span id="modal-security"></span>
                    </div>
                    <div class="mb-3">
                        <strong>Registered:</strong> <span id="modal-registered"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scroll-to-top arrow -->
    <button id="scrollTopBtn" class="scroll-top-btn" aria-label="Back to top" title="Back to top">
        <i class="fas fa-arrow-up"></i>
    </button>
    <script>
    // ── Scroll-to-top arrow ──
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