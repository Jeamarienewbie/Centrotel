<?php
session_start();
require_once '../config/db.php';

// Check if Admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Handle POST actions
$msg = '';
$msg_type = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['type_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if ($name === '') {
            $msg = "Type name is required.";
            $msg_type = 'danger';
        } else {
            $dup = $pdo->prepare("SELECT id FROM guest_types WHERE type_name = ?");
            $dup->execute([$name]);
            if ($dup->fetch()) {
                $msg = "A guest type named '<strong>" . htmlspecialchars($name) . "</strong>' already exists.";
                $msg_type = 'warning';
            } else {
                $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM guest_types")->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO guest_types (type_name, description, sort_order) VALUES (?, ?, ?)");
                $stmt->execute([$name, $desc !== '' ? $desc : null, $max + 1]);
                $msg = "Guest type '<strong>" . htmlspecialchars($name) . "</strong>' added successfully!";
            }
        }

    } elseif ($action === 'edit') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['type_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if ($name === '') {
            $msg = "Type name is required.";
            $msg_type = 'danger';
        } else {
            $dup = $pdo->prepare("SELECT id FROM guest_types WHERE type_name = ? AND id <> ?");
            $dup->execute([$name, $id]);
            if ($dup->fetch()) {
                $msg = "Another guest type already uses that name.";
                $msg_type = 'warning';
            } else {
                $stmt = $pdo->prepare("UPDATE guest_types SET type_name = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $desc !== '' ? $desc : null, $id]);
                $msg = "Guest type updated successfully!";
            }
        }

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $status = ($_POST['current_status'] ?? '') === 'active' ? 'inactive' : 'active';

        // Never deactivate the last active type — bookings need at least one
        $active = (int)$pdo->query("SELECT COUNT(*) FROM guest_types WHERE status = 'active'")->fetchColumn();
        if ($status === 'inactive' && $active <= 1) {
            $msg = "At least one guest type must stay active.";
            $msg_type = 'warning';
        } else {
            $stmt = $pdo->prepare("UPDATE guest_types SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            $msg = "Status updated.";
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $in_use_stmt = $pdo->prepare("SELECT COUNT(*) FROM reservation_guests WHERE guest_type_id = ?");
        $in_use_stmt->execute([$id]);
        $used = (int)$in_use_stmt->fetchColumn();

        if ($used > 0) {
            $msg = "This type is used by <strong>$used</strong> booking(s) and cannot be deleted. Deactivate it instead to keep historical records intact.";
            $msg_type = 'danger';
        } else {
            $stmt = $pdo->prepare("DELETE FROM guest_types WHERE id = ?");
            $stmt->execute([$id]);
            $msg = "Guest type deleted.";
            $msg_type = 'warning';
        }
    }
}

$types = $pdo->query("
    SELECT gt.*,
           (SELECT COALESCE(SUM(rg.guest_count), 0) FROM reservation_guests rg WHERE rg.guest_type_id = gt.id) AS total_pax,
           (SELECT COUNT(*) FROM reservation_guests rg WHERE rg.guest_type_id = gt.id AND rg.guest_count > 0) AS booking_count
    FROM guest_types gt
    ORDER BY gt.sort_order ASC, gt.id ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Types | Admin Centrotel</title>
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
        .card-type { border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.07); transition: 0.3s; }
        .card-type:hover { transform: translateY(-4px); box-shadow: 0 8px 30px rgba(0,0,0,0.12); }
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
        <a href="guest_types.php" class="nav-link-admin active"><i class="fas fa-venus-mars me-2"></i> Guest Types</a>
        <a href="reports.php" class="nav-link-admin"><i class="fas fa-file-invoice-dollar me-2"></i> Financial Reports</a>
        <a href="payment_settings.php" class="nav-link-admin"><i class="fas fa-credit-card me-2"></i> Payment Settings</a>
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
            <h2 class="fw-bold mb-0">Guest Types</h2>
            <p class="text-muted small mb-0">Manage the guest categories (e.g. Male, Female, Children) used in booking forms.</p>
        </div>
        <button class="btn btn-primary px-4 py-2 rounded-pill" data-bs-toggle="modal" data-bs-target="#addTypeModal">
            <i class="fas fa-plus me-2"></i>Add New Type
        </button>
    </div>

    <?php if($msg): ?>
    <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show" role="alert">
        <?php echo $msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card card-type mb-4">
        <div class="card-body p-4">
            <div class="row g-3 align-items-center">
                <div class="col-12 col-md-8">
                    <h6 class="fw-bold mb-1"><i class="fas fa-circle-info text-primary me-2"></i>How guest types work</h6>
                    <p class="text-muted small mb-0">
                        Active types appear as inputs on the room and event booking forms. Guests submit a breakdown
                        (e.g. 2 Male, 1 Female, 1 Children) and the totals are stored per booking so you can plan
                        occupancy, amenities and staffing. Inactive types are hidden from new bookings but keep
                        their historical records.
                    </p>
                </div>
                <div class="col-12 col-md-4">
                    <div class="d-flex gap-3 justify-content-md-end text-center">
                        <div>
                            <div class="h4 fw-bold mb-0"><?php echo count($types); ?></div>
                            <div class="small text-muted">Total Types</div>
                        </div>
                        <div>
                            <div class="h4 fw-bold mb-0"><?php echo count(array_filter($types, fn($t) => $t['status'] === 'active')); ?></div>
                            <div class="small text-muted">Active</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 g-lg-4">
        <?php foreach ($types as $t): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card card-type h-100 p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width:50px;height:50px;">
                        <i class="fas fa-users text-primary"></i>
                    </div>
                    <span class="badge rounded-pill <?php echo $t['status']==='active'?'badge-active':'badge-inactive'; ?> px-3 py-2">
                        <?php echo ucfirst($t['status']); ?>
                    </span>
                </div>

                <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($t['type_name']); ?></h5>
                <p class="text-muted small mb-3"><?php echo $t['description'] ? htmlspecialchars($t['description']) : 'No description.'; ?></p>

                <div class="d-flex gap-3 mb-3">
                    <div>
                        <div class="h5 fw-bold mb-0"><?php echo (int)$t['total_pax']; ?></div>
                        <div class="small text-muted">Total Pax</div>
                    </div>
                    <div>
                        <div class="h5 fw-bold mb-0"><?php echo (int)$t['booking_count']; ?></div>
                        <div class="small text-muted">Bookings</div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-auto">
                    <form method="POST" class="flex-grow-1">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                        <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($t['status']); ?>">
                        <button class="btn btn-sm w-100 <?php echo $t['status']==='active'?'btn-outline-danger':'btn-outline-success'; ?>">
                            <?php echo $t['status']==='active'?'Deactivate':'Activate'; ?>
                        </button>
                    </form>

                    <button class="btn btn-sm btn-outline-primary" title="Edit"
                        onclick="openEdit(<?php echo htmlspecialchars(json_encode($t)); ?>)">
                        <i class="fas fa-edit"></i>
                    </button>

                    <form method="POST" onsubmit="return confirm('Delete this guest type?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($types)): ?>
        <div class="col-12 text-center py-5 text-muted">
            <i class="fas fa-venus-mars fa-3x mb-3 opacity-25"></i>
            <p>No guest types yet. Click <strong>Add New Type</strong> to get started.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ADD Type Modal -->
<div class="modal fade" id="addTypeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Add Guest Type</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Type Name <span class="text-danger">*</span></label>
                        <input type="text" name="type_name" class="form-control" placeholder="e.g. Male, Female, Children" required maxlength="50">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Description <span class="text-muted">(Optional)</span></label>
                        <input type="text" name="description" class="form-control" placeholder="e.g. Child guests (below 12 years old)" maxlength="255">
                        <div class="form-text">Shown as a small hint next to the input on the booking form.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2 rounded-pill">
                        <i class="fas fa-plus me-2"></i>Create Guest Type
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- EDIT Type Modal -->
<div class="modal fade" id="editTypeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Edit Guest Type</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="editId">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Type Name <span class="text-danger">*</span></label>
                        <input type="text" name="type_name" id="editName" class="form-control" required maxlength="50">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Description <span class="text-muted">(Optional)</span></label>
                        <input type="text" name="description" id="editDescription" class="form-control" maxlength="255">
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
function openEdit(type) {
    document.getElementById('editId').value          = type.id;
    document.getElementById('editName').value        = type.type_name;
    document.getElementById('editDescription').value = type.description || '';

    new bootstrap.Modal(document.getElementById('editTypeModal')).show();
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
