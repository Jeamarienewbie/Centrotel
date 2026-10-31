<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// ── Period filter: day / week / month / annual ──
$period = strtolower($_GET['period'] ?? 'month');
if (!in_array($period, ['day', 'week', 'month', 'annual'], true)) {
    $period = 'month';
}
$today = new DateTime('today');
try {
    if ($period === 'day' || $period === 'week') {
        $anchor_raw = $_GET['date'] ?? $today->format('Y-m-d');
        $anchor = new DateTime($anchor_raw);
    } elseif ($period === 'month') {
        $anchor = new DateTime(($_GET['month'] ?? $today->format('Y-m')) . '-01');
    } else {
        $year_raw = (int)($_GET['year'] ?? $today->format('Y'));
        $year_raw = max(2000, min(2100, $year_raw));
        $anchor = new DateTime($year_raw . '-01-01');
    }
} catch (Exception $e) {
    $period = 'month';
    $anchor = new DateTime($today->format('Y-m-01'));
}

if ($period === 'day') {
    $range_start = (clone $anchor)->setTime(0, 0, 0);
    $range_end = (clone $anchor)->setTime(23, 59, 59);
    $period_label = $anchor->format('M d, Y');
    $period_title = 'Daily';
} elseif ($period === 'week') {
    $dow = (int)$anchor->format('N'); // 1 = Monday
    $range_start = (clone $anchor)->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
    $range_end = (clone $range_start)->modify('+6 days')->setTime(23, 59, 59);
    $period_label = $range_start->format('M d') . ' – ' . $range_end->format('M d, Y');
    $period_title = 'Weekly';
} elseif ($period === 'annual') {
    $range_start = (clone $anchor)->setDate((int)$anchor->format('Y'), 1, 1)->setTime(0, 0, 0);
    $range_end = (clone $anchor)->setDate((int)$anchor->format('Y'), 12, 31)->setTime(23, 59, 59);
    $period_label = $anchor->format('Y');
    $period_title = 'Annual';
} else {
    $range_start = (clone $anchor)->setDate((int)$anchor->format('Y'), (int)$anchor->format('m'), 1)->setTime(0, 0, 0);
    $range_end = (clone $range_start)->modify('last day of this month')->setTime(23, 59, 59);
    $period_label = $anchor->format('F Y');
    $period_title = 'Monthly';
}
$start_str = $range_start->format('Y-m-d H:i:s');
$end_str = $range_end->format('Y-m-d H:i:s');

// Keep form controls sticky
$form_date = $anchor->format('Y-m-d');
$form_month = $anchor->format('Y-m');
$form_year = $anchor->format('Y');

// ── Period totals (collected Paid payments) ──
$tot_stmt = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'Paid' AND transaction_date BETWEEN ? AND ?");
$tot_stmt->execute([$start_str, $end_str]);
$tot_row = $tot_stmt->fetch();
$period_total = (float)($tot_row['total'] ?? 0);
$period_count = (int)($tot_row['cnt'] ?? 0);

// ── Breakdown rows (zero-filled) ──
$breakdown = [];
$breakdown_label_col = 'Period';
if ($period === 'day') {
    $breakdown_label_col = 'Payment Method';
    $b_stmt = $pdo->prepare("SELECT payment_method, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'Paid' AND transaction_date BETWEEN ? AND ? GROUP BY payment_method ORDER BY total DESC");
    $b_stmt->execute([$start_str, $end_str]);
    foreach ($b_stmt->fetchAll() as $b) {
        $breakdown[] = ['label' => ($b['payment_method'] ?: 'N/A'), 'count' => (int)$b['cnt'], 'total' => (float)$b['total']];
    }
} elseif ($period === 'annual') {
    $breakdown_label_col = 'Month';
    $by_month = [];
    $b_stmt = $pdo->prepare("SELECT MONTH(transaction_date) as m, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'Paid' AND transaction_date BETWEEN ? AND ? GROUP BY MONTH(transaction_date)");
    $b_stmt->execute([$start_str, $end_str]);
    foreach ($b_stmt->fetchAll() as $b) {
        $by_month[(int)$b['m']] = $b;
    }
    for ($m = 1; $m <= 12; $m++) {
        $b = $by_month[$m] ?? ['cnt' => 0, 'total' => 0];
        $breakdown[] = ['label' => date('F', mktime(0, 0, 0, $m, 1)), 'count' => (int)$b['cnt'], 'total' => (float)$b['total']];
    }
} else {
    // week: 7 days Mon–Sun · month: every day of the month
    $breakdown_label_col = 'Date';
    $by_day = [];
    $b_stmt = $pdo->prepare("SELECT DATE(transaction_date) as d, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'Paid' AND transaction_date BETWEEN ? AND ? GROUP BY DATE(transaction_date)");
    $b_stmt->execute([$start_str, $end_str]);
    foreach ($b_stmt->fetchAll() as $b) {
        $by_day[$b['d']] = $b;
    }
    $cursor = clone $range_start;
    while ($cursor <= $range_end) {
        $key = $cursor->format('Y-m-d');
        $b = $by_day[$key] ?? ['cnt' => 0, 'total' => 0];
        $breakdown[] = ['label' => $cursor->format('D, M d'), 'count' => (int)$b['cnt'], 'total' => (float)$b['total']];
        $cursor->modify('+1 day');
    }
}

// ── Revenue by category, scoped to the period (collected payments) ──
$cat_stmt = $pdo->prepare("SELECT r.booking_type, COUNT(*) as cnt, COALESCE(SUM(p.amount), 0) as total
    FROM payments p JOIN reservations r ON p.reservation_id = r.id
    WHERE p.status = 'Paid' AND p.transaction_date BETWEEN ? AND ?
    GROUP BY r.booking_type");
$cat_stmt->execute([$start_str, $end_str]);
$revenue_by_type = $cat_stmt->fetchAll();

// ── Guest composition (Male / Female / Children ...) for the period ──
$guest_pax_by_type = [];
try {
    $gt_stmt = $pdo->prepare("
        SELECT gt.type_name, COALESCE(SUM(rg.guest_count), 0) AS pax, COUNT(DISTINCT rg.reservation_id) AS bookings
        FROM reservation_guests rg
        JOIN guest_types gt ON gt.id = rg.guest_type_id
        JOIN reservations r ON r.id = rg.reservation_id
        WHERE rg.guest_count > 0 AND r.created_at BETWEEN ? AND ?
        GROUP BY gt.id, gt.type_name
        ORDER BY gt.sort_order ASC, gt.id ASC
    ");
    $gt_stmt->execute([$start_str, $end_str]);
    $guest_pax_by_type = $gt_stmt->fetchAll();
} catch (PDOException $e) {
    $guest_pax_by_type = [];
}

// ── Transaction history scoped to the period ──
$tx_stmt = $pdo->prepare("SELECT * FROM payments WHERE transaction_date BETWEEN ? AND ? ORDER BY transaction_date DESC");
$tx_stmt->execute([$start_str, $end_str]);
$transactions = $tx_stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports | Centrotel Admin</title>
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
            <a href="rooms.php" class="nav-link-admin"><i class="fas fa-bed me-2"></i> Manage Rooms</a>
            <a href="packages.php" class="nav-link-admin"><i class="fas fa-box me-2"></i> Event Packages</a>
            <a href="reservations.php" class="nav-link-admin"><i class="fas fa-calendar-alt me-2"></i> Bookings</a>
            <a href="reviews.php" class="nav-link-admin"><i class="fas fa-star me-2"></i> Reviews</a>
            <a href="users.php" class="nav-link-admin"><i class="fas fa-users me-2"></i> Customers</a>
            <a href="guest_types.php" class="nav-link-admin"><i class="fas fa-venus-mars me-2"></i> Guest Types</a>
            <a href="reports.php" class="nav-link-admin active"><i class="fas fa-file-invoice-dollar me-2"></i> Financial Reports</a>
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
            <h2 class="fw-bold mb-0">Financial Reports</h2>
            <button class="btn btn-premium" data-bs-toggle="modal" data-bs-target="#exportReportModal">
                <i class="fas fa-file-pdf me-2"></i> Export PDF Report
            </button>
        </div>

        <!-- Period filter -->
        <div class="card border-0 shadow-sm p-3 p-lg-4 rounded-4 mb-4">
            <form method="GET" class="d-flex flex-wrap align-items-end gap-2 gap-lg-3" id="periodFilterForm">
                <div>
                    <label class="form-label small fw-bold mb-1">Period</label>
                    <select name="period" id="periodSelect" class="form-select">
                        <option value="day" <?php echo $period === 'day' ? 'selected' : ''; ?>>Day</option>
                        <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Week</option>
                        <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Month</option>
                        <option value="annual" <?php echo $period === 'annual' ? 'selected' : ''; ?>>Annual / Yearly</option>
                    </select>
                </div>
                <div id="pickDayWeek" style="<?php echo ($period === 'day' || $period === 'week') ? '' : 'display:none;'; ?>">
                    <label class="form-label small fw-bold mb-1" id="datePickLabel"><?php echo $period === 'week' ? 'Any date in the week' : 'Date'; ?></label>
                    <input type="date" name="date" class="form-control" value="<?php echo $form_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                </div>
                <div id="pickMonth" style="<?php echo $period === 'month' ? '' : 'display:none;'; ?>">
                    <label class="form-label small fw-bold mb-1">Month</label>
                    <input type="month" name="month" class="form-control" value="<?php echo $form_month; ?>">
                </div>
                <div id="pickYear" style="<?php echo $period === 'annual' ? '' : 'display:none;'; ?>">
                    <label class="form-label small fw-bold mb-1">Year</label>
                    <input type="number" name="year" class="form-control" value="<?php echo $form_year; ?>" min="2000" max="2100">
                </div>
                <div>
                    <button type="submit" class="btn btn-premium"><i class="fas fa-filter me-2"></i>Apply Filter</button>
                </div>
                <div class="ms-lg-auto align-self-center">
                    <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2"><i class="fas fa-calendar-alt me-2"></i><?php echo $period_title; ?>: <?php echo $period_label; ?></span>
                </div>
            </form>
        </div>

        <!-- Period summary chips -->
        <div class="row g-3 g-lg-4 mb-4">
            <div class="col-12 col-sm-6">
                <div class="card border-0 shadow-sm p-4 rounded-4">
                    <div class="small text-muted mb-1"><?php echo $period_title; ?> Revenue <span class="fw-semibold">(<?php echo $period_label; ?>)</span></div>
                    <div class="h3 fw-bold text-success mb-0">₱<?php echo number_format($period_total, 2); ?></div>
                    <div class="small text-muted mt-1">collected (Paid) payments</div>
                </div>
            </div>
            <div class="col-12 col-sm-6">
                <div class="card border-0 shadow-sm p-4 rounded-4">
                    <div class="small text-muted mb-1">Transactions</div>
                    <div class="h3 fw-bold text-primary mb-0"><?php echo $period_count; ?></div>
                    <div class="small text-muted mt-1">confirmed payments in period</div>
                </div>
            </div>
        </div>

        <div class="row g-3 g-lg-4 mb-4 mb-lg-5">
            <div class="col-12 col-md-6">
                <div class="card border-0 shadow-sm p-4 rounded-4">
                    <h5 class="fw-bold mb-4"><?php echo $period_title; ?> Breakdown</h5>
                    <div class="table-responsive">
                        <table class="table hover align-middle">
                            <thead>
                                <tr><th><?php echo $breakdown_label_col; ?></th><th class="text-center">Txns</th><th class="text-end">Total Revenue</th></tr>
                            </thead>
                            <tbody>
                                <?php $bt = 0; foreach($breakdown as $row): $bt += $row['total']; ?>
                                    <tr><td><?php echo htmlspecialchars($row['label']); ?></td><td class="text-center"><?php echo $row['count']; ?></td><td class="fw-bold text-end">₱<?php echo number_format($row['total'], 2); ?></td></tr>
                                <?php endforeach; if(empty($breakdown)) echo "<tr><td colspan='3' class='text-center'>No data available</td></tr>"; ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold"><td>Total</td><td class="text-center"><?php echo $period_count; ?></td><td class="text-end text-success">₱<?php echo number_format($bt, 2); ?></td></tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="card border-0 shadow-sm p-4 rounded-4">
                    <h5 class="fw-bold mb-4">Revenue by Category <span class="fw-normal text-muted small">(<?php echo $period_label; ?>)</span></h5>
                    <div class="table-responsive">
                        <table class="table hover align-middle">
                            <thead>
                                <tr><th>Category</th><th class="text-center">Txns</th><th class="text-end">Total Revenue</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($revenue_by_type as $row): ?>
                                    <tr><td><?php echo ucfirst($row['booking_type']); ?></td><td class="text-center"><?php echo $row['cnt']; ?></td><td class="fw-bold text-end">₱<?php echo number_format($row['total'], 2); ?></td></tr>
                                <?php endforeach; if(empty($revenue_by_type)) echo "<tr><td colspan='3' class='text-center'>No data available</td></tr>"; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm p-4 rounded-4 mb-4 mb-lg-5">
            <h5 class="fw-bold mb-4">Guest Composition <span class="fw-normal text-muted small">(<?php echo $period_label; ?>)</span></h5>
            <div class="table-responsive">
                <table class="table hover align-middle">
                    <thead>
                        <tr><th>Guest Type</th><th class="text-center">Bookings</th><th class="text-end">Total Pax</th></tr>
                    </thead>
                    <tbody>
                        <?php $tp = 0; foreach($guest_pax_by_type as $row): $tp += (int)$row['pax']; ?>
                            <tr><td><?php echo htmlspecialchars($row['type_name']); ?></td><td class="text-center"><?php echo (int)$row['bookings']; ?></td><td class="fw-bold text-end"><?php echo (int)$row['pax']; ?></td></tr>
                        <?php endforeach; if(empty($guest_pax_by_type)) echo "<tr><td colspan='3' class='text-center'>No guest data available</td></tr>"; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold"><td>Total</td><td class="text-center">—</td><td class="text-end text-success"><?php echo $tp; ?></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm p-4 rounded-4">
            <h5 class="fw-bold mb-1">Transaction History</h5>
            <p class="text-muted small mb-4"><?php echo $period_title; ?>: <?php echo $period_label; ?> (<?php echo count($transactions); ?> record(s))</p>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="bg-light">
                        <tr>
                            <th>Date</th>
                            <th>Reservation ID</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($transactions as $pay):
                            $pay_class = match($pay['status'] ?? '') {
                                'Paid' => 'bg-success',
                                'Pending' => 'bg-warning text-dark',
                                default => 'bg-secondary'
                            };
                        ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($pay['transaction_date'])); ?></td>
                                <td>#<?php echo $pay['reservation_id']; ?></td>
                                <td><?php echo htmlspecialchars($pay['payment_method'] ?: 'N/A'); ?></td>
                                <td class="fw-bold">₱<?php echo number_format($pay['amount'], 2); ?></td>
                                <td><span class="badge <?php echo $pay_class; ?>"><?php echo htmlspecialchars($pay['status']); ?></span></td>
                            </tr>
                        <?php endforeach; if(empty($transactions)) echo "<tr><td colspan='5' class='text-center text-muted py-4'>No transactions in this period.</td></tr>"; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Export Report Modal -->
    <div class="modal fade" id="exportReportModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0 px-4 pt-4">
                    <h5 class="modal-title fw-bold text-primary">Report Signatories</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="export_financial_pdf.php" method="GET" target="_blank">
                    <input type="hidden" name="period" value="<?php echo $period; ?>">
                    <input type="hidden" name="date" value="<?php echo $form_date; ?>">
                    <input type="hidden" name="month" value="<?php echo $form_month; ?>">
                    <input type="hidden" name="year" value="<?php echo $form_year; ?>">
                    <div class="modal-body p-4">
                        <p class="text-muted small mb-4">Exports the currently filtered report (<strong><?php echo $period_title; ?>: <?php echo $period_label; ?></strong>). Please provide the names of the individuals who will sign this official document.</p>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Prepared By</label>
                            <input type="text" name="prepared_by" class="form-control" placeholder="e.g. Maria Clara" required>
                            <div class="form-text">Typically the Finance Officer or Accountant.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Approved By</label>
                            <input type="text" name="approved_by" class="form-control" placeholder="e.g. Juan Dela Cruz" required>
                            <div class="form-text">Typically the Resort Manager or Owner.</div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-premium rounded-pill px-4">Generate & Download</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Period filter: show only the picker for the chosen period
        const periodSelect = document.getElementById('periodSelect');
        function syncPeriodPickers() {
            const p = periodSelect.value;
            document.getElementById('pickDayWeek').style.display = (p === 'day' || p === 'week') ? '' : 'none';
            document.getElementById('pickMonth').style.display = (p === 'month') ? '' : 'none';
            document.getElementById('pickYear').style.display = (p === 'annual') ? '' : 'none';
            document.getElementById('datePickLabel').textContent = (p === 'week') ? 'Any date in the week' : 'Date';
        }
        if (periodSelect) {
            periodSelect.addEventListener('change', syncPeriodPickers);
        }
    </script>
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
