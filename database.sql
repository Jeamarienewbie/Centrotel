

-- Users table for both Admin and Customers
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) DEFAULT NULL,
    middle_initial VARCHAR(5) DEFAULT NULL,
    last_name VARCHAR(50) DEFAULT NULL,
    full_name VARCHAR(100) NOT NULL,
    address VARCHAR(255) DEFAULT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'customer') DEFAULT 'customer',
    security_question VARCHAR(255),
    security_answer VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Room gallery (up to 10 swipeable photos per room; first = cover fallback)
CREATE TABLE IF NOT EXISTS room_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
);

-- Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Rooms table
CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(10) NOT NULL UNIQUE,
    room_type VARCHAR(50) NOT NULL,
    description TEXT,
    price_per_night DECIMAL(10, 2) NOT NULL,
    amenities TEXT, -- JSON or comma-separated string
    status ENUM('available', 'occupied', 'maintenance') DEFAULT 'available',
    image_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Event Packages table
CREATE TABLE IF NOT EXISTS event_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_name VARCHAR(100) NOT NULL,
    description TEXT,
    base_price DECIMAL(10, 2) NOT NULL,
    capacity INT NOT NULL,
    services_included TEXT, -- JSON or comma-separated
    status ENUM('active', 'inactive') DEFAULT 'active',
    image_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Event gallery (up to 10 swipeable photos per package; first = cover)
CREATE TABLE IF NOT EXISTS pkg_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (package_id) REFERENCES event_packages(id) ON DELETE CASCADE
);

-- Reservations table
CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    booking_type ENUM('room', 'event') NOT NULL,
    item_id INT NOT NULL, -- room_id or package_id
    check_in_date DATE NOT NULL,
    check_out_date DATE, -- null for events unless multi-day
    guest_count INT NOT NULL,
    total_price DECIMAL(10, 2) NOT NULL,
    status ENUM('Pending Approval', 'Approved', 'Rejected', 'Completed', 'Cancelled') DEFAULT 'Pending Approval',
    review_token VARCHAR(64) DEFAULT NULL,
    review_token_expires DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Guest type catalog (admin managed): e.g. Male, Female, Children
CREATE TABLE IF NOT EXISTS guest_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    sort_order INT DEFAULT 0,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Guest composition per reservation (how many of each guest type)
CREATE TABLE IF NOT EXISTS reservation_guests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    guest_type_id INT NOT NULL,
    guest_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    FOREIGN KEY (guest_type_id) REFERENCES guest_types(id) ON DELETE CASCADE,
    UNIQUE KEY (reservation_id, guest_type_id),
    INDEX (guest_type_id)
);

-- Seed default guest types
INSERT INTO guest_types (type_name, description, sort_order) VALUES 
('Male', 'Male guests', 1),
('Female', 'Female guests', 2),
('Children', 'Child guests (below 12 years old)', 3)
ON DUPLICATE KEY UPDATE type_name = type_name;

-- Guest ratings & feedback (one per completed booking; auto-approved, admin can hide)
CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL UNIQUE,
    user_id INT NOT NULL,
    booking_type ENUM('room', 'event') NOT NULL,
    item_id INT NOT NULL,
    rating TINYINT NOT NULL,
    feedback TEXT NOT NULL,
    status ENUM('approved', 'hidden') DEFAULT 'approved',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    INDEX (booking_type, item_id, status)
);

-- Payments table
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(50),
    proof_of_payment_url VARCHAR(255),
    status ENUM('Pending', 'Partial', 'Paid', 'Refunded') DEFAULT 'Pending',
    transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
);

-- Seed initial rooms
REPLACE INTO rooms (room_number, room_type, price_per_night, description, amenities, image_url) VALUES 
('101', 'Family Triple Suite', 2400.00, '1 Queen Bed + 1 Single Bed. Designed for families and travelers.', 'Air conditioning, Internet access, Econotel-style stay', 'assets/img/Queen+1.jpg'),
('102', 'Deluxe Queen Room', 1800.00, '1 Queen Bed. Clean and comfortable econotel-style stay.', 'Air conditioning, Internet access, Econotel-style stay', 'assets/img/Queen.jpg'),
('103', 'Standard Double Room', 1600.00, '1 Double Bed. Design for budget-conscious guests.', 'Air conditioning, Internet access, Econotel-style stay', 'assets/img/double.jpg');

-- Seed initial admin user (password remains 'admin123')
INSERT INTO users (full_name, email, phone, password_hash, role, security_question, security_answer) 
SELECT 'Admin Centrotel', 'admin@centrotel.com', '09568820066', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'What is the name of this hotel?', 'Centrotel'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@centrotel.com');
-- Password Reset Tokens
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (email),
    INDEX (token)
);
-- OTP Codes
CREATE TABLE IF NOT EXISTS otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    otp VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    purpose VARCHAR(20) DEFAULT 'reset',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (email),
    INDEX (otp)
);
-- Payment Methods table (Admin Managed)
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    method_name VARCHAR(50) NOT NULL,
    account_details TEXT, -- optional: GCash # or Bank Acct
    qr_code_url VARCHAR(255), -- optional: QR code image
    status ENUM('active', 'inactive') DEFAULT 'active'
);

-- Seed Payment Methods
REPLACE INTO payment_methods (method_name, account_details) VALUES 
('GCash', '09568820066 (Centrotel Official)'),
('Bank Transfer', 'BDO: 0012345678 (Centrotel & Event)'),
('Pay Upon Arrival', 'Proceed to Front Desk for payment upon check-in.');

-- Login Activity Logs
CREATE TABLE IF NOT EXISTS login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    email VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500),
    status ENUM('success', 'failed', 'locked') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (email),
    INDEX (status),
    INDEX (created_at)
);

-- Payments table update... (existing)
