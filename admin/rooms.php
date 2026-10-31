<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$msg = null;
$msg_type = 'success';

// Max swipeable photos per room gallery
define('ROOM_MAX_PHOTOS', 10);
$allowed_img_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// Helper: save one uploaded image, returns relative path, false on bad type, null on failure
function saveRoomImage($file) {
    global $allowed_img_ext;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_img_ext)) return false;
    $filename = uniqid('room_') . '.' . $ext;
    $target = '../assets/img/uploads/' . $filename;
    if (move_uploaded_file($file['tmp_name'], $target)) {
        return 'assets/img/uploads/' . $filename;
    }
    return null;
}

// Helper: collect uploaded gallery files (multi-input), capped at $slots
function collectGalleryUploads($files_key, $slots, &$type_error) {
    $uploads = [];
    $type_error = false;
    if ($slots <= 0 || empty($_FILES[$files_key]['name'][0])) return $uploads;
    foreach ($_FILES[$files_key]['name'] as $i => $name) {
        if ($name === '' || count($uploads) >= $slots) continue;
        $f = ['name' => $name, 'tmp_name' => $_FILES[$files_key]['tmp_name'][$i], 'error' => $_FILES[$files_key]['error'][$i]];
        $saved = saveRoomImage($f);
        if ($saved === false) { $type_error = true; continue; }
        if ($saved) $uploads[] = $saved;
    }
    return $uploads;
}

// Helper: persist gallery rows; rooms.image_url always mirrors the first photo (cover)
function addRoomGallery($pdo, $room_id, $paths) {
    if (!$paths) return;
    $ord = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM room_images WHERE room_id = ?");
    $ord->execute([$room_id]);
    $next = (int)$ord->fetchColumn();
    $ins = $pdo->prepare("INSERT INTO room_images (room_id, image_url, sort_order) VALUES (?, ?, ?)");
    foreach ($paths as $p) {
        $ins->execute([$room_id, $p, $next++]);
    }
    syncRoomCover($pdo, $room_id);
}

function syncRoomCover($pdo, $room_id) {
    $cover = $pdo->prepare("SELECT image_url FROM room_images WHERE room_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
    $cover->execute([$room_id]);
    $c = $cover->fetchColumn();
    $pdo->prepare("UPDATE rooms SET image_url = ? WHERE id = ?")->execute([$c ?: null, $room_id]);
}

function roomPhotoCount($pdo, $room_id) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM room_images WHERE room_id = ?");
    $q->execute([$room_id]);
    return (int)$q->fetchColumn();
}

// Handle Room Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Room (first uploaded photo becomes the cover)
    if (isset($_POST['add_room'])) {
        $room_number = $_POST['room_number'];
        $room_type = $_POST['room_type'];
        $price = $_POST['price'];
        $description = $_POST['description'];
        $amenities = $_POST['amenities'];
        $type_error = false;
        $uploads = collectGalleryUploads('room_images', ROOM_MAX_PHOTOS, $type_error);

        if ($type_error) {
            $msg = "Only JPG, PNG, GIF or WebP photos are allowed.";
            $msg_type = "danger";
        } elseif (empty($uploads)) {
            $msg = "Please upload at least 1 photo (up to " . ROOM_MAX_PHOTOS . ").";
            $msg_type = "danger";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO rooms (room_number, room_type, price_per_night, description, amenities, image_url) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$room_number, $room_type, $price, $description, $amenities, $uploads[0]]);
                $new_id = $pdo->lastInsertId();
                addRoomGallery($pdo, $new_id, $uploads);
                $msg = "Room $room_number added with " . count($uploads) . " photo(s)!";
            } catch (PDOException $e) {
                $msg = "Error adding room: " . $e->getMessage();
                $msg_type = "danger";
            }
        }
    }

    // Update Room
    if (isset($_POST['update_room'])) {
        $id = $_POST['room_id'];
        $room_number = $_POST['room_number'];
        $room_type = $_POST['room_type'];
        $price = $_POST['price'];
        $description = $_POST['description'];
        $amenities = $_POST['amenities'];
        $status = $_POST['status'];

        try {
            $stmt = $pdo->prepare("UPDATE rooms SET room_number=?, room_type=?, price_per_night=?, description=?, amenities=?, status=? WHERE id=?");
            $stmt->execute([$room_number, $room_type, $price, $description, $amenities, $status, $id]);

            // Append new gallery photos (cap total at ROOM_MAX_PHOTOS)
            $slots = max(0, ROOM_MAX_PHOTOS - roomPhotoCount($pdo, $id));
            $type_error = false;
            $uploads = collectGalleryUploads('room_images', $slots, $type_error);
            if ($uploads) addRoomGallery($pdo, $id, $uploads);
            $msg = "Room $room_number updated successfully!" . ($uploads ? " (+" . count($uploads) . " photo(s))" : "");
            if ($type_error) { $msg .= " Some files were skipped: only JPG, PNG, GIF or WebP allowed."; $msg_type = "warning"; }
            elseif ($slots === 0 && !empty($_FILES['room_images']['name'][0])) { $msg .= " Gallery is full (" . ROOM_MAX_PHOTOS . " photos max) — delete one first."; $msg_type = "warning"; }
        } catch (PDOException $e) {
            $msg = "Error updating room: " . $e->getMessage();
            $msg_type = "danger";
        }
    }

    // Delete a single gallery photo (re-syncs cover to the next photo)
    if (isset($_POST['delete_image'])) {
        $img_id = (int)($_POST['image_id'] ?? 0);
        $room_id = (int)($_POST['room_id'] ?? 0);
        try {
            $row = $pdo->prepare("SELECT image_url FROM room_images WHERE id = ? AND room_id = ?");
            $row->execute([$img_id, $room_id]);
            if ($path = $row->fetchColumn()) {
                $pdo->prepare("DELETE FROM room_images WHERE id = ?")->execute([$img_id]);
                $disk = '../' . $path;
                if (is_file($disk)) @unlink($disk);
                syncRoomCover($pdo, $room_id);
                $msg = "Photo deleted. (" . roomPhotoCount($pdo, $room_id) . "/" . ROOM_MAX_PHOTOS . " photos)";
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
        $room_id = (int)($_POST['room_id'] ?? 0);
        try {
            $row = $pdo->prepare("SELECT image_url FROM room_images WHERE id = ? AND room_id = ?");
            $row->execute([$img_id, $room_id]);
            if ($url = $row->fetchColumn()) {
                $pdo->prepare("UPDATE room_images SET sort_order = 1 WHERE room_id = ? AND id != ? AND sort_order = 0")->execute([$room_id, $img_id]);
                $pdo->prepare("UPDATE room_images SET sort_order = 0 WHERE id = ?")->execute([$img_id]);
                $pdo->prepare("UPDATE rooms SET image_url = ? WHERE id = ?")->execute([$url, $room_id]);
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

    // Delete Room (plus its gallery files)
    if (isset($_POST['delete_room'])) {
        $id = $_POST['room_id'];
        try {
            $imgs = $pdo->prepare("SELECT image_url FROM room_images WHERE room_id = ?");
            $imgs->execute([$id]);
            $paths = $imgs->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
            foreach ($paths as $p) {
                $disk = '../' . $p;
                if (is_file($disk)) @unlink($disk);
            }
            $msg = "Room deleted successfully!";
        } catch (PDOException $e) {
            $msg = "Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

$rooms = $pdo->query("SELECT * FROM rooms ORDER BY room_number ASC")->fetchAll();

// Gallery grouped per room (ordered: cover first)
$gallery = [];
if ($rooms) {
    $ids = array_column($rooms, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $g = $pdo->prepare("SELECT * FROM room_images WHERE room_id IN ($ph) ORDER BY room_id ASC, sort_order ASC, id ASC");
    $g->execute($ids);
    foreach ($g->fetchAll() as $img) {
        $gallery[$img['room_id']][] = $img;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Rooms | Centrotel Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .sidebar { width: 280px; height: 100vh; position: fixed; background: var(--primary); color: white; padding: 20px; }
        .main-content { margin-left: 280px; padding: 40px; }
        .nav-link-admin { color: rgba(255,255,255,0.7); padding: 12px 15px; border-radius: 10px; margin-bottom: 5px; display: block; text-decoration: none; }
        .nav-link-admin:hover, .nav-link-admin.active { background: rgba(255,255,255,0.1); color: white; }
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
            <a href="rooms.php" class="nav-link-admin active"><i class="fas fa-bed me-2"></i> Manage Rooms</a>
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
            <h2 class="fw-bold mb-0">Manage Rooms</h2>
            <button class="btn btn-premium" data-bs-toggle="modal" data-bs-target="#addRoomModal">
                <i class="fas fa-plus me-2"></i> Add New Room
            </button>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle me-2"></i> <?php echo $msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Preview</th>
                                <th>Room #</th>
                                <th>Type</th>
                                <th>Price</th>
                                <th>Status</th>
                                <th>Amenities</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rooms as $room): ?>
                            <?php $room_imgs = $gallery[$room['id']] ?? []; ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="position-relative d-inline-block">
                                        <img src="../<?php echo $room['image_url'] ?: 'assets/img/placeholder.jpg'; ?>" class="rounded-3" style="width: 60px; height: 40px; object-fit: cover;">
                                        <?php if (count($room_imgs) > 1): ?>
                                        <span class="position-absolute bottom-0 end-0 badge bg-dark rounded-pill" style="font-size: 0.6rem;"><i class="fas fa-images me-1"></i><?php echo count($room_imgs); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="fw-bold"><?php echo $room['room_number']; ?></td>
                                <td><span class="badge bg-primary bg-opacity-10 text-primary px-3"><?php echo $room['room_type']; ?></span></td>
                                <td>₱<?php echo number_format($room['price_per_night'], 2); ?></td>
                                <td>
                                    <?php 
                                    $s_class = ($room['status'] == 'available') ? 'bg-success' : (($room['status'] == 'occupied') ? 'bg-warning' : 'bg-danger');
                                    ?>
                                    <span class="badge <?php echo $s_class; ?>"><?php echo ucfirst($room['status']); ?></span>
                                </td>
                                <td class="small text-muted"><?php echo $room['amenities']; ?></td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-light rounded-circle me-1"
                                            onclick="openEditModal(<?php echo htmlspecialchars(json_encode($room)); ?>, <?php echo htmlspecialchars(json_encode($room_imgs)); ?>)">
                                        <i class="fas fa-edit text-primary"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Archive/Delete this room?')">
                                        <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                        <button type="submit" name="delete_room" class="btn btn-sm btn-light rounded-circle">
                                            <i class="fas fa-trash text-danger"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Room Modal -->
    <div class="modal fade" id="addRoomModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Add New Room</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Room Number</label>
                                <input type="text" name="room_number" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Room Type</label>
                                <input type="text" name="room_type" class="form-control" placeholder="e.g. Deluxe Suite" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Price per Night</label>
                                <input type="number" name="price" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Room Photos (1–10, swipeable gallery)</label>
                                <input type="file" name="room_images[]" class="form-control" accept="image/*" multiple required>
                                <div class="form-text">Upload 1 to 10 photos. The first photo becomes the cover shown on cards.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Description</label>
                                <textarea name="description" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Amenities (comma separated)</label>
                                <input type="text" name="amenities" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_room" class="btn btn-premium">Save Room</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Room Modal -->
    <div class="modal fade" id="editRoomModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Room Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="room_id" id="edit_room_id">
                    <input type="hidden" name="image_id" id="gallery_image_id" value="">
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Room Number</label>
                                <input type="text" name="room_number" id="edit_room_number" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Room Type</label>
                                <input type="text" name="room_type" id="edit_room_type" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Price Nightly (₱)</label>
                                <input type="number" name="price" id="edit_price" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Status</label>
                                <select name="status" id="edit_status" class="form-select">
                                    <option value="available">Available</option>
                                    <option value="occupied">Occupied</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Photo Gallery (<span id="galleryCount">0</span>/10 swipeable)</label>
                                <div id="editGalleryGrid" class="d-flex flex-wrap gap-2 mb-2"></div>
                                <label class="form-label small fw-bold">Add More Photos</label>
                                <input type="file" name="room_images[]" class="form-control" accept="image/*" multiple>
                                <div class="form-text">Max 10 photos total. Starred photo is the cover.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Description</label>
                                <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Amenities</label>
                                <input type="text" name="amenities" id="edit_amenities" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_room" class="btn btn-premium">Update Room Info</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const editModal = new bootstrap.Modal(document.getElementById('editRoomModal'));
        function openEditModal(room, images) {
            document.getElementById('edit_room_id').value = room.id;
            document.getElementById('edit_room_number').value = room.room_number;
            document.getElementById('edit_room_type').value = room.room_type;
            document.getElementById('edit_price').value = room.price_per_night;
            document.getElementById('edit_status').value = room.status;
            document.getElementById('edit_description').value = room.description;
            document.getElementById('edit_amenities').value = room.amenities;
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
                    <img src="../${im.image_url}" alt="Room photo" style="width:80px;height:60px;object-fit:cover;border-radius:8px;border:${idx === 0 ? '3px solid var(--primary)' : '2px solid #eee'};">
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
