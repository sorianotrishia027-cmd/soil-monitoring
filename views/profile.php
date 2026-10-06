<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once '../config/db_connect.php';

$user_id = (int)$_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

/* GET USER */
$user = $currentUser ?? null;

if (!$user) {
    $stmt = $conn->prepare("
        SELECT id, username, email, fullname, role, contact_number, password
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$user) {
    $error = "User account not found.";
}

/* UPDATE PROFILE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $fullname = trim($_POST['fullname'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');

        if ($username === '' || $email === '' || $fullname === '') {
            $error = "Please fill in all required fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif ($contact !== '' && !preg_match('/^09\d{9}$/', $contact)) {
            $error = "Contact number must be 11 digits and start with 09.";
        } else {

            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE (username = ? OR email = ?)
                AND id != ?
                LIMIT 1
            ");
            $stmt->execute([$username, $email, $user_id]);

            if ($stmt->fetch()) {
                $error = "Username or email is already being used.";
            } else {

                $stmt = $conn->prepare("
                    UPDATE users
                    SET username = ?,
                        email = ?,
                        fullname = ?,
                        contact_number = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $username,
                    $email,
                    $fullname,
                    $contact,
                    $user_id
                ]);

                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;
                $_SESSION['fullname'] = $fullname;
                $_SESSION['contact_number'] = $contact;

                $user['username'] = $username;
                $user['email'] = $email;
                $user['fullname'] = $fullname;
                $user['contact_number'] = $contact;

                $message = "Profile updated successfully.";
            }
        }
    }
}

/* CHANGE PASSWORD */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {

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

            $stmt = $conn->prepare("
                SELECT password
                FROM users
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$user_id]);

            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account || !password_verify($current_password, $account['password'])) {
                $error = "Current password is incorrect.";
            } else {

                $hashed = password_hash($new_password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("
                    UPDATE users
                    SET password = ?
                    WHERE id = ?
                ");

                $stmt->execute([$hashed, $user_id]);

                $message = "Password changed successfully.";
            }
        }
    }
}
?>

<style>
.profile-page {
    padding: 24px;
}

.profile-header {
    margin-bottom: 24px;
}

.profile-header h1 {
    margin: 0;
    color: #111;
    font-size: 28px;
}

.profile-header p {
    margin: 6px 0 0;
    color: #666;
}

.profile-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.profile-card {
    background: #fff;
    border: 1px solid #ccd4cc;
    border-radius: 14px;
    padding: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,.05);
}

.profile-card h2 {
    margin: 0 0 20px;
    font-size: 20px;
    color: #111;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: #333;
}

.form-group input {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 13px;
    border: 1px solid #ccd4cc;
    border-radius: 8px;
    font-size: 14px;
    outline: none;
}

.form-group input:focus {
    border-color: #0b8a47;
}

.profile-btn {
    width: 100%;
    border: 0;
    padding: 12px;
    border-radius: 8px;
    background: #0b8a47;
    color: white;
    font-weight: 600;
    cursor: pointer;
}

.profile-btn:hover {
    background: #1b5e20;
}

.alert {
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-success {
    background: #e8f5e9;
    color: #1b5e20;
}

.alert-error {
    background: #ffebee;
    color: #b71c1c;
}

.profile-info {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 24px;
}

.profile-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: #0b8a47;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: bold;
}

.profile-info h3 {
    margin: 0;
    font-size: 20px;
}

.role-badge {
    display: inline-block;
    margin-top: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    background: #e8f5e9;
    color: #0b8a47;
    font-size: 12px;
    font-weight: 600;
}

@media (max-width: 800px) {
    .profile-grid {
        grid-template-columns: 1fr;
    }

    .profile-page {
        padding: 15px;
    }
}
</style>

<div class="profile-page">

<div class="profile-header">
    <h1>My Profile</h1>
    <p>Manage your account information and password.</p>
</div>

<?php if ($message): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($user): ?>

    <div class="profile-card" style="margin-bottom:20px;">
        <div class="profile-info">

            <div class="profile-avatar">
                <?= strtoupper(substr($user['fullname'] ?: $user['username'], 0, 1)) ?>
            </div>

            <div>
                <h3>
                    <?= htmlspecialchars($user['fullname'] ?: $user['username']) ?>
                </h3>

                <span class="role-badge">
                    <?= htmlspecialchars(ucfirst($user['role'])) ?>
                </span>
            </div>

        </div>
    </div>

    <div class="profile-grid">

        <!-- PERSONAL INFORMATION -->
        <div class="profile-card">

            <h2>Personal Information</h2>

            <form method="POST">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="form-group">
                    <label>Username</label>
                    <input
                        type="text"
                        name="username"
                        value="<?= htmlspecialchars($user['username']) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Full Name</label>
                    <input
                        type="text"
                        name="fullname"
                        value="<?= htmlspecialchars($user['fullname']) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input
                        type="email"
                        name="email"
                        value="<?= htmlspecialchars($user['email']) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Contact Number</label>
                    <input
                        type="text"
                        name="contact_number"
                        value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>"
                        placeholder="09XXXXXXXXX"
                        maxlength="11"
                    >
                </div>

                <button
                    type="submit"
                    name="update_profile"
                    class="profile-btn"
                >
                    Save Changes
                </button>

            </form>

        </div>

        <!-- SECURITY -->
        <div class="profile-card">

            <h2>Change Password</h2>

            <form method="POST">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="form-group">
                    <label>Current Password</label>
                    <input
                        type="password"
                        name="current_password"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>New Password</label>
                    <input
                        type="password"
                        name="new_password"
                        minlength="8"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input
                        type="password"
                        name="confirm_password"
                        minlength="8"
                        required
                    >
                </div>

                <button
                    type="submit"
                    name="change_password"
                    class="profile-btn"
                >
                    Change Password
                </button>

            </form>

        </div>

    </div>

<?php endif; ?>

</div>