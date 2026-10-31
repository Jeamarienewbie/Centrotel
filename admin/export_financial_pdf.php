<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Reporting Period: day / week / month / annual (matches the on-screen filter)
$period = strtolower($_GET['period'] ?? 'month');
if (!in_array($period, ['day', 'week', 'month', 'annual'], true)) {
    $period = 'month';
}
$today = new DateTime('today');
try {
    if ($period === 'day' || $period === 'week') {
        $anchor = new DateTime($_GET['date'] ?? $today->format('Y-m-d'));
    } elseif ($period === 'month') {
        $anchor = new DateTime(($_GET['month'] ?? $today->format('Y-m')) . '-01');
    } else {
        $year = max(2000, min(2100, (int)($_GET['year'] ?? $today->format('Y'))));
        $anchor = new DateTime($year . '-01-01');
    }
} catch (Exception $e) {
    $period = 'month';
    $anchor = new DateTime($today->format('Y-m-01'));
}

if ($period === 'day') {
    $pdf_start = (clone $anchor)->setTime(0, 0, 0);
    $pdf_end = (clone $anchor)->setTime(23, 59, 59);
    $month_name = $anchor->format('M d, Y');
    $report_kind = 'Daily';
} elseif ($period === 'week') {
    $dow = (int)$anchor->format('N');
    $pdf_start = (clone $anchor)->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
    $pdf_end = (clone $pdf_start)->modify('+6 days')->setTime(23, 59, 59);
    $month_name = $pdf_start->format('M d') . ' – ' . $pdf_end->format('M d, Y');
    $report_kind = 'Weekly';
} elseif ($period === 'annual') {
    $pdf_start = (clone $anchor)->setDate((int)$anchor->format('Y'), 1, 1)->setTime(0, 0, 0);
    $pdf_end = (clone $anchor)->setDate((int)$anchor->format('Y'), 12, 31)->setTime(23, 59, 59);
    $month_name = $anchor->format('Y');
    $report_kind = 'Annual';
} else {
    $pdf_start = (clone $anchor)->setDate((int)$anchor->format('Y'), (int)$anchor->format('m'), 1)->setTime(0, 0, 0);
    $pdf_end = (clone $pdf_start)->modify('last day of this month')->setTime(23, 59, 59);
    $month_name = $anchor->format('F Y');
    $report_kind = 'Monthly';
}
$days_in_period = max(1, (int)$pdf_start->diff($pdf_end)->format('%a') + 1);
$pdf_start_str = $pdf_start->format('Y-m-d H:i:s');
$pdf_end_str = $pdf_end->format('Y-m-d H:i:s');
$checkin_start = $pdf_start->format('Y-m-d');
$checkin_end = $pdf_end->format('Y-m-d');

// 1. Fetch Total Rooms
$total_rooms_stmt = $pdo->query("SELECT COUNT(*) FROM rooms");
$total_rooms = $total_rooms_stmt->fetchColumn() ?: 1;

// 2. Fetch Room Revenue & Occupied Room Count (within the period)
$room_stats_stmt = $pdo->prepare("
    SELECT
        SUM(total_price) as revenue,
        COUNT(*) as room_nights
    FROM reservations
    WHERE booking_type = 'room'
    AND status IN ('Approved', 'Completed')
    AND DATE(check_in_date) BETWEEN ? AND ?
");
$room_stats_stmt->execute([$checkin_start, $checkin_end]);
$room_stats = $room_stats_stmt->fetch();

$room_revenue = $room_stats['revenue'] ?: 0;
$occupied_rooms = $room_stats['room_nights'] ?: 0;

// 3. Fetch Event Revenue (within the period)
$event_revenue_stmt = $pdo->prepare("
    SELECT SUM(total_price) as revenue
    FROM reservations
    WHERE booking_type = 'event'
    AND status IN ('Approved', 'Completed')
    AND DATE(check_in_date) BETWEEN ? AND ?
");
$event_revenue_stmt->execute([$checkin_start, $checkin_end]);
$event_revenue = $event_revenue_stmt->fetchColumn() ?: 0;

// 4. Calculate KPIs
$room_nights_available = $total_rooms * $days_in_period;
$occupancy_rate = $room_nights_available > 0 ? ($occupied_rooms / $room_nights_available) * 100 : 0;
$adr = $occupied_rooms > 0 ? ($room_revenue / $occupied_rooms) : 0;
$revpar = $room_nights_available > 0 ? ($room_revenue / $room_nights_available) : 0;

$total_gross_revenue = $room_revenue + $event_revenue;

// 5. Fetch Recent Transactions (within the period)
$transactions_stmt = $pdo->prepare("
    SELECT p.*, r.booking_type
    FROM payments p
    JOIN reservations r ON p.reservation_id = r.id
    WHERE p.transaction_date BETWEEN ? AND ?
    ORDER BY p.transaction_date DESC
");
$transactions_stmt->execute([$pdf_start_str, $pdf_end_str]);
$transactions = $transactions_stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $report_kind; ?> Financial Report - <?php echo $month_name; ?></title>
    <style>
        body { font-family: 'Times New Roman', Times, serif; color: #333; line-height: 1.6; padding: 40px; background: white; }
        .report-header { text-align: center; border-bottom: 2px solid #444; padding-bottom: 20px; margin-bottom: 30px; }
        .hotel-name { font-size: 24px; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; }
        .report-title { font-size: 18px; color: #666; }
        
        .section-title { font-size: 16px; font-weight: bold; text-transform: uppercase; background: #f4f4f4; padding: 8px 15px; margin: 30px 0 15px 0; border-left: 4px solid #1e1b4b; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { border: 1px solid #eee; padding: 15px; text-align: center; }
        .stat-label { font-size: 12px; color: #888; text-transform: uppercase; margin-bottom: 5px; }
        .stat-value { font-size: 18px; font-weight: bold; color: #1e1b4b; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { text-align: left; background: #1e1b4b; color: white; padding: 10px; font-size: 14px; }
        td { padding: 10px; border-bottom: 1px solid #eee; font-size: 13px; }
        .row-total { font-weight: bold; background: #fafafa; }
        .text-right { text-align: right; }
        
        .footer { margin-top: 50px; font-size: 12px; color: #999; text-align: center; border-top: 1px solid #eee; padding-top: 20px; }
        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 100px; margin-top: 80px; }
        .sig-line { border-top: 1px solid #333; text-align: center; padding-top: 10px; font-size: 14px; }

        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #1e1b4b; color: white; border: none; border-radius: 5px;">Print / Download as PDF</button>
        <button onclick="window.history.back()" style="padding: 10px 20px; cursor: pointer; background: #eee; border: 1px solid #ccc; border-radius: 5px; margin-left: 10px;">Go Back</button>
    </div>

    <div class="report-header">
        <div class="hotel-name">Centrotel & Event Center</div>
        <div class="report-title"><?php echo $report_kind; ?> Financial Performance Report</div>
        <div style="font-size: 14px; margin-top: 10px;">Reporting Period: <?php echo $month_name; ?></div>
    </div>

    <div class="section-title">Key Performance Indicators (Hotel Metrics)</div>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Occupancy Rate</div>
            <div class="stat-value"><?php echo number_format($occupancy_rate, 1); ?>%</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Average Daily Rate (ADR)</div>
            <div class="stat-value">₱<?php echo number_format($adr, 2); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">RevPAR</div>
            <div class="stat-value">₱<?php echo number_format($revpar, 2); ?></div>
        </div>
    </div>

    <div class="section-title">Revenue Summary (USALI Standards)</div>
    <table>
        <thead>
            <tr>
                <th>Department / Source</th>
                <th class="text-right">Current Period</th>
                <th class="text-right">% of Revenue</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Rooms Revenue</td>
                <td class="text-right">₱<?php echo number_format($room_revenue, 2); ?></td>
                <td class="text-right"><?php echo $total_gross_revenue > 0 ? number_format(($room_revenue / $total_gross_revenue) * 100, 1) : 0; ?>%</td>
            </tr>
            <tr>
                <td>Event & Function Revenue</td>
                <td class="text-right">₱<?php echo number_format($event_revenue, 2); ?></td>
                <td class="text-right"><?php echo $total_gross_revenue > 0 ? number_format(($event_revenue / $total_gross_revenue) * 100, 1) : 0; ?>%</td>
            </tr>
            <tr class="row-total">
                <td>Total Gross Operating Revenue</td>
                <td class="text-right">₱<?php echo number_format($total_gross_revenue, 2); ?></td>
                <td class="text-right">100.0%</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Recent Transaction Log</div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Method</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($transactions as $tx): ?>
            <tr>
                <td><?php echo date('M d, Y', strtotime($tx['transaction_date'])); ?></td>
                <td style="text-transform: capitalize;"><?php echo $tx['booking_type']; ?></td>
                <td><?php echo $tx['payment_method']; ?></td>
                <td class="text-right">₱<?php echo number_format($tx['amount'], 2); ?></td>
            </tr>
            <?php endforeach; if(empty($transactions)) echo "<tr><td colspan='4' style='text-align:center;'>No transactions recorded in this period.</td></tr>"; ?>
        </tbody>
    </table>

    <div class="signature-grid">
        <div class="sig-line">
            Prepared By: <strong><?php echo htmlspecialchars($_GET['prepared_by'] ?? '____________________'); ?></strong><br>
            <span style="font-size: 11px; color: #777;">Finance Officer</span>
        </div>
        <div class="sig-line">
            Approved By: <strong><?php echo htmlspecialchars($_GET['approved_by'] ?? '____________________'); ?></strong><br>
            <span style="font-size: 11px; color: #777;">General Manager</span>
        </div>
    </div>

    <div class="footer">
        Generated on <?php echo date('F d, Y h:i A'); ?> | Official Centrotel Internal Document<br>
        Confidential Financial Record
    </div>

    <script>
        // Auto-show print dialog if coming from export button
        window.onload = function() {
            // window.print();
        }
    </script>
</body>
</html>
