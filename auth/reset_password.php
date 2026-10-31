<?php
session_start();
require_once '../config/db.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    header('Location: ../index.php');
    exit();
}

// Validate token
$stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()");
$stmt->execute([$token]);
$reset = $stmt->fetch();

if (!$reset) {
    $invalid = true;
}

$message = '';
$error = '';

// Handle password reset submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($reset)) {
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $hashed = password_hash($new_password, PASSWORD_BCRYPT);

        // Update password
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $stmt->execute([$hashed, $reset['email']]);

        // Delete the token
        $stmt = $pdo->prepare("DELETE FROM password_resets WHERE token = ?");
        $stmt->execute([$token]);

        $message = 'Password has been reset successfully! You can now login.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Centrotel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-light">
    <div class="container d-flex align-items-center justify-content-center min-vh-100 px-3 py-4">
        <div class="card border-0 shadow-lg rounded-4" style="max-width: 450px; width: 100%;">
            <div class="card-body p-4 p-sm-5">
                <?php if (isset($invalid)): ?>
                    <div class="text-center mb-4">
                        <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px;height:80px;">
                            <i class="fas fa-times fa-2x"></i>
                        </div>
                        <h3 class="fw-bold">Link Expired</h3>
                        <p class="text-muted">This reset link is invalid or has expired. Please request a new one.</p>
                        <a href="../index.php" class="btn btn-premium w-100 py-2 mt-3">Back to Home</a>
                    </div>

                <?php elseif ($message): ?>
                    <div class="text-center mb-4">
                        <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px;height:80px;">
                            <i class="fas fa-check fa-2x"></i>
                        </div>
                        <h3 class="fw-bold">Success!</h3>
                        <p class="text-muted"><?php echo $message; ?></p>
                        <a href="../index.php" class="btn btn-premium w-100 py-2 mt-3">Go to Login</a>
                    </div>

                <?php else: ?>
                    <div class="text-center mb-4">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px;height:80px;">
                            <i class="fas fa-key fa-2x"></i>
                        </div>
                        <h3 class="fw-bold">Reset Password</h3>
                        <p class="text-muted small">Enter your new password for <strong><?php echo htmlspecialchars($reset['email']); ?></strong></p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger small"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">New Password</label>
                            <div class="position-relative">
                                <input type="password" name="new_password" id="rpNewPassword" class="form-control rounded-3" required minlength="6" placeholder="Min 6 characters">
                                <span class="position-absolute top-50 end-0 translate-middle-y pe-3" style="cursor: pointer;" onclick="togglePwd('rpNewPassword', this)">
                                    <i class="fas fa-eye text-muted"></i>
                                </span>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold">Confirm New Password</label>
                            <div class="position-relative">
                                <input type="password" name="confirm_password" id="rpConfirmPassword" class="form-control rounded-3" required minlength="6" placeholder="Repeat password">
                                <span class="position-absolute top-50 end-0 translate-middle-y pe-3" style="cursor: pointer;" onclick="togglePwd('rpConfirmPassword', this)">
                                    <i class="fas fa-eye text-muted"></i>
                                </span>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-premium w-100 py-2">Reset Password</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
<script>
    function togglePwd(inputId, icon) {
        const input = document.getElementById(inputId);
        const iconElem = icon.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            iconElem.classList.remove('fa-eye');
            iconElem.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            iconElem.classList.remove('fa-eye-slash');
            iconElem.classList.add('fa-eye');
        }
    }
</script>
</html>
