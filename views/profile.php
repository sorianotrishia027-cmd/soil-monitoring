<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

$user_id = (int) $_SESSION['user_id'];
$message = '';
$error = '';

/* =========================
   UPDATE PROFILE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');

    if ($username === '' || $fullname === '' || $email === '') {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif ($contact !== '' && !preg_match('/^09[0-9]{9}$/', $contact)) {
        $error = "Contact number must be 11 digits and start with 09.";
    } else {
        $check = $conn->prepare("
            SELECT id FROM users
            WHERE (username = ? OR email = ?) AND id != ?
            LIMIT 1
        ");
        $check->execute([$username, $email, $user_id]);

        if ($check->fetch()) {
            $error = "Username or email is already in use.";
        } else {
            $update = $conn->prepare("
                UPDATE users
                SET username = ?, fullname = ?, email = ?, contact_number = ?
                WHERE id = ?
            ");
            $update->execute([$username, $fullname, $email, $contact, $user_id]);

            $_SESSION['username'] = $username;
            $_SESSION['fullname'] = $fullname;
            $_SESSION['email'] = $email;
            $_SESSION['contact_number'] = $contact;

            $message = "Profile updated successfully.";
        }
    }
}

/* =========================
   CHANGE PASSWORD
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $error = "Please complete all password fields.";
    } elseif (strlen($new_password) < 8) {
        $error = "New password must be at least 8 characters.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } else {
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account || !password_verify($current_password, $account['password'])) {
            $error = "Current password is incorrect.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update->execute([$hashed_password, $user_id]);

            $message = "Password changed successfully.";
        }
    }
}

/* =========================
   GET CURRENT USER
========================= */
$stmt = $conn->prepare("
    SELECT id, username, fullname, email, contact_number, role
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$display_name = $user['fullname'] ?? $user['username'] ?? 'User';
$initial = strtoupper(substr($display_name, 0, 1));
?>

<div class="sub-view-panel-container">

    <div class="view-panel-header">
        <h3>User Profile & Credentials</h3>
        <p>Manage your account settings, mobile notifications contact, and security passwords.</p>
    </div>

    <?php if ($message): ?>
        <div class="alert success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- User Header Banner Card -->
    <div class="card-panel" style="display: flex; align-items: center; gap: 20px; padding: 20px 24px;">
        <div class="user-avatar-circle" style="width: 56px; height: 56px; font-size: 22px;">
            <?= $initial ?>
        </div>
        <div>
            <h2 style="font-size: 20px; font-weight: 800; color: var(--text-heading); margin: 0; line-height: 1.2;">
                <?= htmlspecialchars($display_name) ?>
            </h2>
            <div style="margin-top: 6px; display: flex; align-items: center; gap: 10px;">
                <span class="badge-pill optimal" style="font-size: 11.5px;">
                    <?= ucfirst(htmlspecialchars($user['role'] ?? 'Farmer')) ?>
                </span>
                <span style="font-size: 13px; color: var(--text-muted);">
                    @<?= htmlspecialchars($user['username'] ?? '') ?>
                </span>
            </div>
        </div>
    </div>

    <!-- 2-Column Split: Profile Details & Password Change -->
    <div class="insights-dashboard-split-row">
        
        <!-- Profile Details Form -->
        <div class="card-panel">
            <h3 style="font-size: 16px; font-weight: 700; color: var(--text-heading); margin-bottom: 16px;">
                Profile Details
            </h3>

            <form action="dashboard.php?page=profile" method="POST">
                <input type="hidden" name="save_profile" value="1">

                <div class="form-group">
                    <label class="form-label">Full Name:</label>
                    <div class="input-field-wrapper">
                        <input type="text" name="fullname" value="<?= htmlspecialchars($user['fullname'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Username:</label>
                    <div class="input-field-wrapper">
                        <input type="text" name="username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address:</label>
                    <div class="input-field-wrapper">
                        <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Contact Number (SMS Alerts):</label>
                    <div class="input-field-wrapper">
                        <input type="text" name="contact_number" value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>" placeholder="09123456789">
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 8px;">
                    Save Profile Changes
                </button>
            </form>
        </div>

        <!-- Password Change Form -->
        <div class="card-panel">
            <h3 style="font-size: 16px; font-weight: 700; color: var(--text-heading); margin-bottom: 16px;">
                Security & Password
            </h3>

            <form action="dashboard.php?page=profile" method="POST">
                <input type="hidden" name="change_password" value="1">

                <div class="form-group">
                    <label class="form-label">Current Password:</label>
                    <div class="input-field-wrapper">
                        <input type="password" name="current_password" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">New Password:</label>
                    <div class="input-field-wrapper">
                        <input type="password" name="new_password" placeholder="At least 8 characters" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm New Password:</label>
                    <div class="input-field-wrapper">
                        <input type="password" name="confirm_password" required>
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 8px;">
                    Update Password
                </button>
            </form>
        </div>

    </div>

</div>