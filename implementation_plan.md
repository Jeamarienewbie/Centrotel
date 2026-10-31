# Implementation Plan - Centrotel & Event Center Reservation System

This document outlines the development roadmap for the web-based reservation management system.

## 1. Database Schema (MySQL)

### Tables:
- `users`: id, full_name, email, phone, password_hash, role (admin/customer), security_question, security_answer, created_at.
- `rooms`: id, room_number, type, description, price_per_night, amenities (JSON), status (available/maintenance), image_url.
- `event_packages`: id, package_name, description, base_price, capacity, services_included (JSON), status.
- `reservations`: id, user_id, type (room/event), item_id (room_id or event_package_id), check_in_date, check_out_date, guest_count, total_price, status (pending_approval, approved, rejected, completed, cancelled), created_at.
- `payments`: id, reservation_id, amount, payment_method, proof_of_payment_url, status (pending, partial, paid, refunded), transaction_date.

## 2. Technology Stack & Tools
- **Backend**: PHP 8.x
- **Database**: MySQL (XAMPP)
- **Frontend**: Tailwind CSS (for layout), Bootstrap (for rapid components/modals), JavaScript (ES6+ & AJAX).
- **Security**: Password hashing (bcrypt), session management.

## 3. Development Phases

### Phase 1: Foundation
- [ ] Initialize directory structure.
- [ ] Create `config/db.php` for database connection.
- [ ] Create `assets/css/style.css` with a premium theme.

### Phase 2: Authentication
- [ ] Login/Registration modals.
- [ ] Forgot Password flow.

### Phase 3: Public Landing Page
- [ ] Hero section.
- [ ] Browse Rooms & Event Packages.

### Phase 4: Admin & Customer Portals
- [ ] Admin Dashboard (Management & Reports).
- [ ] Customer Dashboard (Bookings & Payments).
