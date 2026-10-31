<?php
require_once 'config/db.php';

echo "<h2>Centrotel Database Synchronizer</h2>";

try {
    // 1. Ensure tables exist
    $sql = file_get_contents('database.sql');
    $pdo->exec($sql);
    echo "<p style='color: green;'>✓ Database schema verified.</p>";

    // 2. Surgical Clear - Remove ALL conflicting data
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("DELETE FROM payments;");
    $pdo->exec("DELETE FROM reservations;");
    $pdo->exec("DELETE FROM rooms;");
    $pdo->exec("DELETE FROM event_packages;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // 3. Seed Rooms - 2 per type (Inventory of 2)
    $rooms = [
        [1, '101', 'Family Triple Suite', '1 Queen Bed + 1 Single Bed. Designed for families and group travelers.', 2400.00, 'TV, WiFi, AC', 'assets/img/Queen+1.jpg'],
        [2, '102', 'Deluxe Queen Room', '1 comfortable Queen Bed. Perfect for couples or solo travelers.', 1800.00, 'TV, WiFi, AC', 'assets/img/Queen.jpg'],
        [3, '103', 'Standard Double Room', '1 Double Bed. A budget-friendly choice for savvy travelers.', 1600.00, 'TV, WiFi, AC', 'assets/img/double.jpg'],
        [4, '104', 'Family Triple Suite', 'Second Family Suite Unit.', 2400.00, 'TV, WiFi, AC', 'assets/img/Queen+1.jpg'],
        [5, '105', 'Deluxe Queen Room', 'Second Deluxe Queen Unit.', 1800.00, 'TV, WiFi, AC', 'assets/img/Queen.jpg'],
        [6, '106', 'Standard Double Room', 'Second Standard Double Unit.', 1600.00, 'TV, WiFi, AC', 'assets/img/double.jpg']
    ];

    $stmt = $pdo->prepare("REPLACE INTO rooms (id, room_number, room_type, description, price_per_night, amenities, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($rooms as $r) {
        $stmt->execute($r);
    }
    echo "<p style='color: green;'>✓ 6 Rooms synchronized (Inventory of 2 per type).</p>";

    // 5. Seed Payment Methods
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        method_name VARCHAR(50) NOT NULL,
        account_details TEXT,
        qr_code_url VARCHAR(255),
        status ENUM('active', 'inactive') DEFAULT 'active'
    );");
    
    $check_methods = $pdo->query("SELECT COUNT(*) FROM payment_methods")->fetchColumn();
    if ($check_methods == 0) {
        $p_methods = [
            ['GCash', '09568820066 (Centrotel Official)'],
            ['Bank Transfer', 'BDO: 0012345678 (Centrotel & Event)'],
            ['Pay Upon Arrival', 'Proceed to Front Desk for payment upon check-in.']
        ];
        $m_stmt = $pdo->prepare("INSERT INTO payment_methods (method_name, account_details) VALUES (?, ?)");
        foreach ($p_methods as $pm) { $m_stmt->execute($pm); }
        echo "<p style='color: green;'>✓ Payment Methods synchronized.</p>";
    }

    echo "<hr><p><b>Success!</b> Your database is now perfectly matched with the website's buttons and settings.</p>";
    echo "<a href='index.php' style='padding: 10px 20px; background: #2e003e; color: white; text-decoration: none; border-radius: 5px;'>Return to Website</a>";

} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
