<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$msg = null;
$msg_type = 'success';

// Max swipeable photos per event gallery
define('PKG_MAX_PHOTOS', 10);
$allowed_pkg_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// Helper: save one uploaded image, returns relative path, false on bad type, null on failure
function savePkgImage($file) {
    global $allowed_pkg_ext;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_pkg_ext)) return false;
    $filename = uniqid('pkg_') . '.' . $ext;
    $target = '../assets/img/uploads/' . $filename;
    if (move_uploaded_file($file['tmp_name'], $target)) {
        return 'assets/img/uploads/' . $filename;
    }
    return null;
}

// Helper: collect uploaded gallery files (multi-input), capped at $slots
function collectPkgUploads($files_key, $slots, &$type_error) {
    $uploads = [];
    $type_error = false;
    if ($slots <= 0 || empty($_FILES[$files_key]['name'][0])) return $uploads;
    foreach ($_FILES[$files_key]['name'] as $i => $name) {
        if ($name === '' || count($uploads) >= $slots) continue;
        $f = ['name' => $name, 'tmp_name' => $_FILES[$files_key]['tmp_name'][$i], 'error' => $_FILES[$files_key]['error'][$i]];
        $saved = savePkgImage($f);
        if ($saved === false) { $type_error = true; continue; }
        if ($saved) $uploads[] = $saved;
    }
    return $uploads;
}

// Helper: persist gallery rows; event_packages.image_url always mirrors the first photo (cover)
function addPkgGallery($pdo, $package_id, $paths) {
    if (!$paths) return;
    $ord = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM pkg_images WHERE package_id = ?");
    $ord->execute([$package_id]);
    $next = (int)$ord->fetchColumn();
    $ins = $pdo->prepare("INSERT INTO pkg_images (package_id, image_url, sort_order) VALUES (?, ?, ?)");
    foreach ($paths as $p) {
        $ins->execute([$package_id, $p, $next++]);
    }
    syncPkgCover($pdo, $package_id);
}

function syncPkgCover($pdo, $package_id) {
    $cover = $pdo->prepare("SELECT image_url FROM pkg_images WHERE package_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
    $cover->execute([$package_id]);
    $c = $cover->fetchColumn();
    $pdo->prepare("UPDATE event_packages SET image_url = ? WHERE id = ?")->execute([$c ?: null, $package_id]);
}

function pkgPhotoCount($pdo, $package_id) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM pkg_images WHERE package_id = ?");
    $q->execute([$package_id]);
    return (int)$q->fetchColumn();
}

// Handle Package Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Package (first uploaded photo becomes the cover)
    if (isset($_POST['add_package'])) {
        $package_name = $_POST['package_name'];
        $price = $_POST['price'];
        $capacity = $_POST['capacity'];
        $description = $_POST['description'];
        $services = $_POST['services'];
        $services = implode(',', array_map('trim', explode(',', $services)));
        $type_error = false;
        $uploads = collectPkgUploads('pkg_images', PKG_MAX_PHOTOS, $type_error);

        if ($type_error) {
            $msg = "Only JPG, PNG, GIF or WebP photos are allowed.";
            $msg_type = 'danger';
        } elseif (empty($uploads)) {
            $msg = "Please upload at least 1 photo (up to " . PKG_MAX_PHOTOS . ").";
            $msg_type = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO event_packages (package_name, base_price, capacity, description, services_included, image_url) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$package_name, $price, $capacity, $description, $services, $uploads[0]]);
                $new_id = $pdo->lastInsertId();
                addPkgGallery($pdo, $new_id, $uploads);
                $msg = "Package '$package_name' added with " . count($uploads) . " photo(s)!";
            } catch (PDOException $e) {
                $msg = "Error: " . $e->getMessage();
                $msg_type = 'danger';
            }
        }
    }

    // Update Package
    if (isset($_POST['update_package'])) {
        $id = $_POST['package_id'];
        $package_name = $_POST['package_name'];
        $price = $_POST['price'];
        $capacity = $_POST['capacity'];
        $description = $_POST['description'];
        $services = $_POST['services'];
        $services = implode(',', array_map('trim', explode(',', $services)));

        try {
            $stmt = $pdo->prepare("UPDATE event_packages SET package_name=?, base_price=?, capacity=?, description=?, services_included=? WHERE id=?");
            $stmt->execute([$package_name, $price, $capacity, $description, $services, $id]);

            // Append new gallery photos (cap total at PKG_MAX_PHOTOS)
            $slots = max(0, PKG_MAX_PHOTOS - pkgPhotoCount($pdo, $id));
            $type_error = false;
            $uploads = collectPkgUploads('pkg_images', $slots, $type_error);
            if ($uploads) addPkgGallery($pdo, $id, $uploads);
            $msg = "Package updated successfully!" . ($uploads ? " (+" . count($uploads) . " photo(s))" : "");
            if ($type_error) { $msg .= " Some files were skipped: only JPG, PNG, GIF or WebP allowed."; $msg_type = "warning"; }
            elseif ($slots === 0 && !empty($_FILES['pkg_images']['name'][0])) { $msg .= " Gallery is full (" . PKG_MAX_PHOTOS . " photos max) — delete one first."; $msg_type = "warning"; }
        } catch (PDOException $e) {
            $msg = "Error: " . $e->getMessage();
            $msg_type = 'danger';
        }
    }

    // Delete a single gallery photo (re-syncs cover to the next photo)
    if (isset($_POST['delete_image'])) {
        $img_id = (int)($_POST['image_id'] ?? 0);
        $package_id = (int)($_POST['package_id'] ?? 0);
        try {
            $row = $pdo->prepare("SELECT image_url FROM pkg_images WHERE id = ? AND package_id = ?");
            $row->execute([$img_id, $package_id]);
            if ($path = $row->fetchColumn()) {
                $pdo->prepare("DELETE FROM pkg_images WHERE id = ?")->execute([$img_id]);
                $disk = '../' . $path;
                if (is_file($disk)) @unlink($disk);
                syncPkgCover($pdo, $package_id);
                $msg = "Photo deleted. (" . pkgPhotoCount($pdo, $package_id) . "/" . PKG_MAX_PHOTOS . " photos)";
            } else {
                $msg = "Photo not found.";
                $msg_type = "danger";
            }
        } catch (PDOException $e) {
            $msg = "Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }

    // Set a gallery photo as the cover
    if (isset($_POST['set_cover'])) {
        $img_id = (int)($_POST['image_id'] ?? 0);
        $package_id = (int)($_POST['package_id'] ?? 0);
        try {
            $row = $pdo->prepare("SELECT image_url FROM pkg_images WHERE id = ? AND package_id = ?");
            $row->execute([$img_id, $package_id]);
            if ($url = $row->fetchColumn()) {
                $pdo->prepare("UPDATE pkg_images SET sort_order = 1 WHERE package_id = ? AND id != ? AND sort_order = 0")->execute([$package_id, $img_id]);
                $pdo->prepare("UPDATE pkg_images SET sort_order = 0 WHERE id = ?")->execute([$img_id]);
                $pdo->prepare("UPDATE event_packages SET image_url = ? WHERE id = ?")->execute([$url, $package_id]);
                $msg = "Cover photo updated.";
            } else {
                $msg = "Photo not found.";
                $msg_type = "danger";
            }
        } catch (PDOException $e) {
            $msg = "Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }

    // Delete Package (plus its gallery files)
    if (isset($_POST['delete_package'])) {
        $id = $_POST['package_id'];
        try {
            $imgs = $pdo->prepare("SELECT image_url FROM pkg_images WHERE package_id = ?");
            $imgs->execute([$id]);
            $paths = $imgs->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare("DELETE FROM event_packages WHERE id = ?")->execute([$id]);
            foreach ($paths as $p) {
                $disk = '../' . $p;
                if (is_file($disk)) @unlink($disk);
            }
            $msg = "Package deleted successfully!";
        } catch (PDOException $e) {
            $msg = "Error: " . $e->getMessage();
            $msg_type = 'danger';
        }
    }
}

$packages = $pdo->query("SELECT * FROM event_packages ORDER BY id DESC")->fetchAll();

// Gallery grouped per package (ordered: cover first)
$pkg_gallery = [];
if ($packages) {
    $ids = array_column($packages, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $g = $pdo->prepare("SELECT * FROM pkg_images WHERE package_id IN ($ph) ORDER BY package_id ASC, sort_order ASC, id ASC");
    $g->execute($ids);
    foreach ($g->fetchAll() as $img) {
        $pkg_gallery[$img['package_id']][] = $img;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Event Packages | Centrotel Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .sidebar { width: 280px; height: 100vh; position: fixed; background: var(--primary); color: white; padding: 20px; }
        .main-content { margin-left: 280px; padding: 40px; }
        .nav-link-admin { color: rgba(255,255,255,0.7); padding: 12px 15px; border-radius: 10px; margin-bottom: 5px; display: block; text-decoration: none; }
        .nav-link-admin:hover, .nav-link-admin.active { background: rgba(255,255,255,0.1); color: white; }
        .package-card { transition: 0.3s; border: none; overflow: hidden; }
        .package-card:hover { transform: translateY(-5px); }
        .package-img { height: 160px; width: 100%; object-fit: cover; }
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
            <a href="packages.php" class="nav-link-admin active"><i class="fas fa-box me-2"></i> Event Packages</a>
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
            <h2 class="fw-bold mb-0">Event Packages</h2>
            <button class="btn btn-premium" data-bs-toggle="modal" data-bs-target="#addPackageModal">
                <i class="fas fa-plus me-2"></i> Add New Package
            </button>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle me-2"></i> <?php echo $msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (empty($packages)): ?>
            <div class="card border-0 shadow-sm rounded-4 p-5 text-center">
                <i class="fas fa-box-open fa-4x mb-3 text-muted opacity-25"></i>
                <h4>No Packages Defined</h4>
                <p class="text-muted">Start by adding your first event package (Wedding, Debut, etc.)</p>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($packages as $pkg): ?>
                <?php $pkg_imgs = $pkg_gallery[$pkg['id']] ?? []; ?>
                    <div class="col-md-4 col-sm-6 col-12">
                        <div class="card package-card shadow-sm rounded-4 h-100">
                            <div class="position-relative">
                                <img src="../<?php echo $pkg['image_url'] ?: 'assets/img/placeholder.jpg'; ?>" class="package-img" alt="Package">
                                <?php if (count($pkg_imgs) > 1): ?>
                                <span class="position-absolute bottom-0 start-0 m-2 badge bg-dark rounded-pill" style="font-size: 0.65rem;"><i class="fas fa-images me-1"></i><?php echo count($pkg_imgs); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <h5 class="fw-bold mb-0"><?php echo $pkg['package_name']; ?></h5>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light rounded-circle" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end border-0 shadow">
                                            <li>
                                                <button class="dropdown-item" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($pkg)); ?>, <?php echo htmlspecialchars(json_encode($pkg_imgs)); ?>)">
                                                    <i class="fas fa-edit me-2 text-primary"></i> Edit
                                                </button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" onsubmit="return confirm('Delete this package?')">
                                                    <input type="hidden" name="package_id" value="<?php echo $pkg['id']; ?>">
                                                    <button type="submit" name="delete_package" class="dropdown-item text-danger"><i class="fas fa-trash me-2"></i>Delete</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="h4 text-primary fw-bold mb-3">₱<?php echo number_format($pkg['base_price'], 2); ?></div>
                                <p class="small text-muted mb-0"><i class="fas fa-users me-2"></i>Capacity: <?php echo $pkg['capacity']; ?> Pax</p>
                                <hr class="my-3 opacity-25">
                                <p class="small mb-3 text-truncate-2"><?php echo $pkg['description']; ?></p>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php 
                                    $svcs = explode(',', $pkg['services_included']);
                                    foreach(array_slice($svcs, 0, 3) as $s): ?>
                                        <span class="badge bg-light text-muted fw-normal"><?php echo trim($s); ?></span>
                                    <?php endforeach; 
                                    if(count($svcs) > 3) echo '<span class="badge bg-light text-muted fw-normal">+'.(count($svcs)-3).' more</span>';
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Package Modal -->
    <div class="modal fade" id="addPackageModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0 px-4 pt-4">
                    <h4 class="modal-title fw-bold" style="color: var(--primary);">Add Event Package</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Package Name</label>
                            <input type="text" name="package_name" class="form-control" placeholder="e.g. Wedding Platinum" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Base Price (₱)</label>
                                <input type="number" name="price" class="form-control" placeholder="0.00" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Guest Capacity</label>
                                <input type="number" name="capacity" class="form-control" placeholder="e.g. 100" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Package Photos (1–10, swipeable gallery)</label>
                            <input type="file" name="pkg_images[]" class="form-control" accept="image/*" multiple required>
                            <div class="form-text">Upload 1 to 10 photos. The first photo becomes the cover.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Describe the package benefits..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Included Services (comma separated)</label>
                            <input type="text" name="services" class="form-control" placeholder="e.g. Catering, Decorations, Sounds & Lights">
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_package" class="btn btn-premium rounded-pill px-4">Create Package</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Package Modal -->
    <div class="modal fade" id="editPackageModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0 px-4 pt-4">
                    <h4 class="modal-title fw-bold" style="color: var(--primary);">Edit Package</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="package_id" id="edit_pkg_id">
                    <input type="hidden" name="image_id" id="gallery_image_id" value="">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Package Name</label>
                            <input type="text" name="package_name" id="edit_pkg_name" class="form-control" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Base Price (₱)</label>
                                <input type="number" name="price" id="edit_pkg_price" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Guest Capacity</label>
                                <input type="number" name="capacity" id="edit_pkg_capacity" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Photo Gallery (<span id="galleryCount">0</span>/10 swipeable)</label>
                            <div id="editGalleryGrid" class="d-flex flex-wrap gap-2 mb-2"></div>
                            <label class="form-label small fw-bold">Add More Photos</label>
                            <input type="file" name="pkg_images[]" class="form-control" accept="image/*" multiple>
                            <div class="form-text">Max 10 photos total. Starred photo is the cover.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Description</label>
                            <textarea name="description" id="edit_pkg_desc" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Included Services</label>
                            <input type="text" name="services" id="edit_pkg_services" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_package" class="btn btn-premium rounded-pill px-4">Update Package</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const editModal = new bootstrap.Modal(document.getElementById('editPackageModal'));
        function openEditModal(pkg, images) {
            document.getElementById('edit_pkg_id').value = pkg.id;
            document.getElementById('edit_pkg_name').value = pkg.package_name;
            document.getElementById('edit_pkg_price').value = pkg.base_price;
            document.getElementById('edit_pkg_capacity').value = pkg.capacity;
            document.getElementById('edit_pkg_desc').value = pkg.description;
            document.getElementById('edit_pkg_services').value = pkg.services_included;
            renderEditGallery(images || []);
            editModal.show();
        }

        function renderEditGallery(images) {
            const grid = document.getElementById('editGalleryGrid');
            document.getElementById('galleryCount').textContent = images.length;
            if (!images.length) {
                grid.innerHTML = '<span class="text-muted small">No photos yet — add some below.</span>';
                return;
            }
            grid.innerHTML = images.map((im, idx) => `
                <div class="position-relative">
                    <img src="../${im.image_url}" alt="Package photo" style="width:80px;height:60px;object-fit:cover;border-radius:8px;border:${idx === 0 ? '3px solid var(--primary)' : '2px solid #eee'};">
                    ${idx === 0
                        ? '<span class="position-absolute top-0 start-0 badge bg-primary" style="font-size:0.55rem;">COVER</span>'
                        : `<button type="submit" name="set_cover" class="btn btn-sm btn-light position-absolute top-0 start-0 p-1" title="Set as cover" onclick="document.getElementById('gallery_image_id').value='${im.id}'"><i class="fas fa-star text-warning" style="font-size:0.65rem;"></i></button>`}
                    <button type="submit" name="delete_image" class="btn btn-sm btn-danger position-absolute bottom-0 end-0 p-1" title="Delete photo" onclick="document.getElementById('gallery_image_id').value='${im.id}';return confirm('Delete this photo?')"><i class="fas fa-trash" style="font-size:0.65rem;"></i></button>
                </div>`).join('');
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
