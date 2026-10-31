<?php
session_start();
require_once '../config/db.php';

// Check if Admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Ensure qr_code_url column exists (run once)
$pdo->exec("ALTER TABLE payment_methods ADD COLUMN IF NOT EXISTS qr_code_url VARCHAR(255) DEFAULT NULL;");

// Handle POST actions
$msg = '';
$msg_type = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name    = trim($_POST['method_name']);
        $details = trim($_POST['account_details']);
        $qr_url  = null;

        if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] === 0) {
            $upload_dir = '../assets/img/qr/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $ext      = strtolower(pathinfo($_FILES['qr_code']['name'], PATHINFO_EXTENSION));
            $allowed  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allowed)) {
                $filename = 'qr_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $upload_dir . $filename)) {
                    $qr_url = 'assets/img/qr/' . $filename;
                }
            }
        }
        $stmt = $pdo->prepare("INSERT INTO payment_methods (method_name, account_details, qr_code_url) VALUES (?, ?, ?)");
        $stmt->execute([$name, $details, $qr_url]);
        $msg = "Payment method '<strong>$name</strong>' added successfully!";

    } elseif ($action === 'edit') {
        $id      = (int)$_POST['id'];
        $name    = trim($_POST['method_name']);
        $details = trim($_POST['account_details']);

        // Fetch existing QR
        $current = $pdo->prepare("SELECT qr_code_url FROM payment_methods WHERE id = ?");
        $current->execute([$id]);
        $qr_url = $current->fetchColumn();

        // Handle new QR upload
        if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] === 0) {
            $upload_dir = '../assets/img/qr/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $ext     = strtolower(pathinfo($_FILES['qr_code']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allowed)) {
                $filename = 'qr_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $upload_dir . $filename)) {
                    $qr_url = 'assets/img/qr/' . $filename;
                }
            }
        }

        // Remove QR if requested
        if (isset($_POST['remove_qr']) && $_POST['remove_qr'] === '1') {
            $qr_url = null;
        }

        $stmt = $pdo->prepare("UPDATE payment_methods SET method_name = ?, account_details = ?, qr_code_url = ? WHERE id = ?");
        $stmt->execute([$name, $details, $qr_url, $id]);
        $msg = "Payment method updated successfully!";

    } elseif ($action === 'toggle') {
        $id = (int)$_POST['id'];
        // Protect Pay Upon Arrival from being deactivated
        $chk = $pdo->prepare("SELECT method_name FROM payment_methods WHERE id = ?");
        $chk->execute([$id]);
        $chk_name = $chk->fetchColumn();
        if (strtolower($chk_name) === 'pay upon arrival') {
            $msg = "'Pay Upon Arrival' is a default method and cannot be deactivated.";
            $msg_type = 'warning';
        } else {
            $status = $_POST['current_status'] === 'active' ? 'inactive' : 'active';
            $stmt = $pdo->prepare("UPDATE payment_methods SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            $msg = "Status updated.";
        }

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        // Protect Pay Upon Arrival from deletion
        $chk = $pdo->prepare("SELECT method_name FROM payment_methods WHERE id = ?");
        $chk->execute([$id]);
        $chk_name = $chk->fetchColumn();
        if (strtolower($chk_name) === 'pay upon arrival') {
            $msg = "'Pay Upon Arrival' is a system default and cannot be deleted.";
            $msg_type = 'danger';
        } else {
            $stmt = $pdo->prepare("DELETE FROM payment_methods WHERE id = ?");
            $stmt->execute([$id]);
            $msg = "Payment method deleted.";
            $msg_type = 'warning';
        }
    }
}

$methods = $pdo->query("SELECT * FROM payment_methods ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Settings | Admin Centrotel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root { --primary: #2e003e; --accent: #9c27b0; }
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar { width: 280px; min-height: 100vh; background: var(--primary); color: white; position: fixed; padding: 20px; }
        .main-content { margin-left: 280px; padding: 40px; }
        .nav-link-admin { color: rgba(255,255,255,0.7); padding: 12px 15px; border-radius: 10px; margin-bottom: 5px; display: block; text-decoration: none; transition: 0.2s; }
        .nav-link-admin:hover, .nav-link-admin.active { background: rgba(255,255,255,0.15); color: white; }
        .card-method { border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.07); transition: 0.3s; }
        .card-method:hover { transform: translateY(-4px); box-shadow: 0 8px 30px rgba(0,0,0,0.12); }
        .qr-preview { max-width: 120px; max-height: 120px; border-radius: 10px; border: 2px dashed #ddd; }
        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #fee2e2; color: #7f1d1d; }
    </style>
</head>
<body>

<div class="sidebar" id="sidebar">
    <div class="d-flex align-items-center mb-4 px-2">
        <i class="fas fa-hotel fa-lg me-2 opacity-75"></i>
        <span class="fs-5 fw-bold">Admin Panel</span>
    </div>
    <nav>
        <a href="dashboard.php" class="nav-link-admin"><i class="fas fa-chart-line me-2"></i> Dashboard</a>
        <a href="rooms.php" class="nav-link-admin"><i class="fas fa-bed me-2"></i> Manage Rooms</a>
        <a href="packages.php" class="nav-link-admin"><i class="fas fa-box me-2"></i> Event Packages</a>
        <a href="reservations.php" class="nav-link-admin"><i class="fas fa-calendar-alt me-2"></i> Bookings</a>
        <a href="reviews.php" class="nav-link-admin"><i class="fas fa-star me-2"></i> Reviews</a>
        <a href="users.php" class="nav-link-admin"><i class="fas fa-users me-2"></i> Customers</a>
        <a href="reports.php" class="nav-link-admin"><i class="fas fa-file-invoice-dollar me-2"></i> Financial Reports</a>
        <a href="payment_settings.php" class="nav-link-admin active"><i class="fas fa-credit-card me-2"></i> Payment Settings</a>
        <div class="mt-5">
            <a href="../auth/logout.php" class="nav-link-admin text-danger" onclick="return confirm('Are you sure you want to logout?')"><i class="fas fa-sign-out-alt me-2"></i> Logout</a>
        </div>
    </nav>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="main-content">
    <button class="btn btn-primary sidebar-toggle d-none mb-3" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>
    <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-0">Payment Settings</h2>
            <p class="text-muted small mb-0">Add account details and QR codes for each payment method.</p>
        </div>
        <button class="btn btn-primary px-4 py-2 rounded-pill" data-bs-toggle="modal" data-bs-target="#addMethodModal">
            <i class="fas fa-plus me-2"></i>Add New Method
        </button>
    </div>

    <?php if($msg): ?>
    <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show" role="alert">
        <?php echo $msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-3 g-lg-4">
        <?php foreach ($methods as $m): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card card-method h-100 p-4">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width:50px;height:50px;">
                        <i class="fas fa-<?php echo ($m['method_name']==='Pay Upon Arrival') ? 'concierge-bell' : 'qrcode'; ?> text-primary"></i>
                    </div>
                    <span class="badge rounded-pill <?php echo $m['status']==='active'?'badge-active':'badge-inactive'; ?> px-3 py-2">
                        <?php echo ucfirst($m['status']); ?>
                    </span>
                </div>

                <!-- Info -->
                <?php $isDefault = (strtolower($m['method_name']) === 'pay upon arrival'); ?>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <h5 class="fw-bold mb-0"><?php echo htmlspecialchars($m['method_name']); ?></h5>
                    <?php if ($isDefault): ?>
                    <span class="badge bg-secondary rounded-pill" style="font-size:0.65rem;"><i class="fas fa-lock me-1"></i>Default</span>
                    <?php endif; ?>
                </div>
                <p class="text-muted small mb-3"><?php echo nl2br(htmlspecialchars($m['account_details'])); ?></p>

                <!-- QR Code Preview — only for non-default methods -->
                <?php if (!$isDefault): ?>
                <?php if (!empty($m['qr_code_url'])): ?>
                <div class="text-center mb-3 p-2 bg-light rounded-3">
                    <img src="../<?php echo htmlspecialchars($m['qr_code_url']); ?>"
                         alt="QR Code" class="qr-preview">
                    <div class="small text-muted mt-1"><i class="fas fa-qrcode me-1"></i>QR Code Available</div>
                </div>
                <?php else: ?>
                <div class="text-center mb-3 p-3 bg-light rounded-3 text-muted small">
                    <i class="fas fa-qrcode fa-2x mb-1 opacity-25"></i><br>No QR Code uploaded
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Actions -->
                <div class="d-flex gap-2 mt-auto">
                    <?php if (!$isDefault): ?>
                    <form method="POST" class="flex-grow-1">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                        <input type="hidden" name="current_status" value="<?php echo $m['status']; ?>">
                        <button class="btn btn-sm w-100 <?php echo $m['status']==='active'?'btn-outline-danger':'btn-outline-success'; ?>">
                            <?php echo $m['status']==='active'?'Deactivate':'Activate'; ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <button class="btn btn-sm btn-light flex-grow-1" disabled title="Default method, always active">
                        <i class="fas fa-lock me-1"></i> Always Active
                    </button>
                    <?php endif; ?>

                    <button class="btn btn-sm btn-outline-primary" title="Edit"
                        onclick="openEdit(<?php echo htmlspecialchars(json_encode($m)); ?>)">
                        <i class="fas fa-edit"></i>
                    </button>

                    <?php if (!$isDefault): ?>
                    <form method="POST" onsubmit="return confirm('Delete this payment method?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($methods)): ?>
        <div class="col-12 text-center py-5 text-muted">
            <i class="fas fa-credit-card fa-3x mb-3 opacity-25"></i>
            <p>No payment methods yet. Click <strong>Add New Method</strong> to get started.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ADD Method Modal -->
<div class="modal fade" id="addMethodModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Add Payment Method</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Method Name <span class="text-danger">*</span></label>
                        <input type="text" name="method_name" class="form-control" placeholder="e.g. GCash, Maya, Pay Upon Arrival" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Account Details / Instructions <span class="text-danger">*</span></label>
                        <textarea name="account_details" class="form-control" rows="3"
                            placeholder="e.g. GCash: 0917-154-4511 (Centrotel Official)" required></textarea>
                        <div class="form-text">These details will be shown to customers on the payment screen.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">QR Code Image <span class="text-muted">(Optional)</span></label>
                        <input type="file" name="qr_code" class="form-control" accept="image/png,image/jpeg,image/gif,image/webp">
                        <div class="form-text">Upload a QR code so customers can scan and pay directly.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2 rounded-pill">
                        <i class="fas fa-plus me-2"></i>Create Payment Method
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- EDIT Method Modal -->
<div class="modal fade" id="editMethodModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Edit Payment Method</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="editId">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Method Name <span class="text-danger">*</span></label>
                        <input type="text" name="method_name" id="editName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Account Details / Instructions <span class="text-danger">*</span></label>
                        <textarea name="account_details" id="editDetails" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Current QR Code</label>
                        <div id="currentQrPreview" class="text-center p-2 bg-light rounded mb-2"></div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="remove_qr" value="1" id="removeQr">
                            <label class="form-check-label small text-danger" for="removeQr">Remove existing QR code</label>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Upload New QR Code <span class="text-muted">(Optional)</span></label>
                        <input type="file" name="qr_code" class="form-control" accept="image/png,image/jpeg,image/gif,image/webp">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2 rounded-pill">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openEdit(method) {
    document.getElementById('editId').value      = method.id;
    document.getElementById('editName').value    = method.method_name;
    document.getElementById('editDetails').value = method.account_details;

    const qrPreview = document.getElementById('currentQrPreview');
    if (method.qr_code_url) {
        qrPreview.innerHTML = `<img src="../${method.qr_code_url}" style="max-height:100px;border-radius:8px;" alt="QR">
                               <p class="small text-muted mt-1 mb-0">Current QR</p>`;
    } else {
        qrPreview.innerHTML = `<span class="text-muted small">No QR code uploaded</span>`;
    }

    new bootstrap.Modal(document.getElementById('editMethodModal')).show();
}

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
