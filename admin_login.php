<?php
session_start();

// Already signed in as admin → straight to the dashboard
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    header('Location: admin/dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal | Centrotel & Event Center</title>
    <meta name="description" content="Centrotel administrator sign-in.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #2e003e 0%, #4a0063 60%, #6a1b9a 100%);
            min-height: 100vh;
            min-height: 100dvh;
        }
        .admin-card {
            max-width: 430px;
            width: 100%;
            border-radius: 24px;
        }
        .admin-logo {
            width: 84px;
            height: 84px;
            object-fit: cover;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(46, 0, 62, 0.25);
        }
    </style>
</head>
<body>
    <div class="container d-flex align-items-center justify-content-center min-vh-100 px-3 py-4">
        <div class="card admin-card border-0 shadow-lg">
            <div class="card-body p-4 p-sm-5">
                <div class="text-center mb-4">
                    <img src="assets/img/logo.jpg" alt="Centrotel Logo" class="admin-logo mb-3">
                    <h3 class="fw-bold mb-1" style="color: var(--primary);">Admin Portal</h3>
                    <p class="text-muted small mb-0"><i class="fas fa-lock me-1"></i> Authorized staff only</p>
                </div>

                <div class="alert alert-danger d-none small" id="adminLoginError"></div>

                <form id="adminLoginForm">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Email Address</label>
                        <input type="email" name="email" class="form-control rounded-3" placeholder="admin@example.com" maxlength="100" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-bold">Password</label>
                        <div class="position-relative">
                            <input type="password" name="password" id="adminPassword" class="form-control rounded-3" placeholder="••••••••" required>
                            <span class="position-absolute top-50 end-0 translate-middle-y pe-3" style="cursor: pointer;" onclick="toggleAdminPw()">
                                <i class="fas fa-eye text-muted" id="adminPwIcon"></i>
                            </span>
                        </div>
                        <div class="text-end mt-2">
                            <a href="index.php?show_forgot=1" class="text-decoration-none small">Forgot Password?</a>
                        </div>
                    </div>
                    <input type="hidden" name="csrf_token" id="csrfTokenAdmin">
                    <button type="submit" class="btn btn-premium w-100 py-2" id="adminLoginBtn">
                        <i class="fas fa-sign-in-alt me-2"></i>Sign In to Dashboard
                    </button>
                </form>

                <hr class="my-4">
                <div class="d-flex justify-content-between small">
                    <a href="index.php" class="text-decoration-none fw-bold" style="color: var(--primary);"><i class="fas fa-arrow-left me-1"></i>Back to site</a>
                    <a href="index.php" class="text-decoration-none text-muted">Guest login</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleAdminPw() {
            const input = document.getElementById('adminPassword');
            const icon = document.getElementById('adminPwIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // CSRF token
        fetch('auth/process_auth.php?action=csrf_token', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'csrf_token=init' })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.token) {
                    document.getElementById('csrfTokenAdmin').value = data.token;
                }
            });

        // Login lockout countdown
        let adminCountdown = null;
        function startAdminCountdown(seconds) {
            const btn = document.getElementById('adminLoginBtn');
            if (adminCountdown) clearInterval(adminCountdown);
            let remaining = seconds;
            btn.disabled = true;
            btn.innerHTML = `<i class="fas fa-lock me-2"></i> Locked (${remaining}s)`;
            adminCountdown = setInterval(() => {
                remaining--;
                if (remaining <= 0) {
                    clearInterval(adminCountdown);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i>Sign In to Dashboard';
                } else {
                    btn.innerHTML = `<i class="fas fa-lock me-2"></i> Locked (${remaining}s)`;
                }
            }, 1000);
        }

        document.getElementById('adminLoginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('adminLoginBtn');
            const errorEl = document.getElementById('adminLoginError');
            errorEl.classList.add('d-none');
            const emailInput = this.querySelector('[name="email"]');
            emailInput.value = emailInput.value.trim();

            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Signing in...';

            fetch('auth/process_auth.php?action=admin_login', {
                method: 'POST',
                body: new FormData(this)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.href = data.redirect;
                } else {
                    errorEl.textContent = data.message;
                    errorEl.classList.remove('d-none');
                    if (data.locked && data.remaining) {
                        startAdminCountdown(data.remaining);
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                }
            })
            .catch(() => {
                errorEl.textContent = 'An error occurred. Please try again.';
                errorEl.classList.remove('d-none');
                btn.disabled = false;
                btn.innerHTML = originalText;
            });
        });
    </script>
</body>
</html>
