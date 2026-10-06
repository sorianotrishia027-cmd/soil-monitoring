<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

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
            SELECT id
            FROM users
            WHERE (username = ? OR email = ?)
            AND id != ?
            LIMIT 1
        ");

        $check->execute([
            $username,
            $email,
            $user_id
        ]);

        if ($check->fetch()) {

            $error = "Username or email is already in use.";

        } else {

            $update = $conn->prepare("
                UPDATE users
                SET username = ?,
                    fullname = ?,
                    email = ?,
                    contact_number = ?
                WHERE id = ?
            ");

            $update->execute([
                $username,
                $fullname,
                $email,
                $contact,
                $user_id
            ]);

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

        $stmt = $conn->prepare("
            SELECT password
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$user_id]);

        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {

            $error = "User account not found.";

        } elseif (!password_verify($current_password, $account['password'])) {

            $error = "Current password is incorrect.";

        } else {

            $hashed_password = password_hash(
                $new_password,
                PASSWORD_DEFAULT
            );

            $update = $conn->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ?
            ");

            $update->execute([
                $hashed_password,
                $user_id
            ]);

            $message = "Password changed successfully.";
        }
    }
}


/* =========================
   GET CURRENT USER
========================= */

$stmt = $conn->prepare("
    SELECT
        id,
        username,
        fullname,
        email,
        contact_number,
        role
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $error = "Unable to load your profile.";
}

$display_name = $user['fullname'] ?? $user['username'] ?? 'User';
$initial = strtoupper(substr($display_name, 0, 1));
?>

<style>
.profile-page {
    padding: 24px;
}

.profile-title {
    margin-bottom: 24px;
}

.profile-title h1 {
    margin: 0;
    font-size: 28px;
    color: #111;
}

.profile-title p {
    margin: 6px 0 0;
    color: #666;
}

.profile-alert {
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.profile-success {
    background: #e8f5e9;
    color: #1b5e20;
}

.profile-error {
    background: #ffebee;
    color: #b71c1c;
}

.profile-top {
    background: #fff;
    border: 1px solid #ccd4cc;
    border-radius: 14px;
    padding: 22px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 3px 10px rgba(0,0,0,.05);
}

.profile-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #0b8a47;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    font-weight: 700;
}

.profile-name {
    font-size: 21px;
    font-weight: 700;
    color: #111;
}

.profile-role {
    display: inline-block;
    margin-top: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    background: #e8f5e9;
    color: #0b8a47;
    font-size: 12px;
    font-weight: 600;
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
    box-shadow: 0 3px 10px rgba(0,0,0,.05);
}

.profile-card h2 {
    margin: 0 0 20px;
    font-size: 19px;
    color: #111;
}

.profile-field {
    margin-bottom: 16px;
}

.profile-field label {
    display: block;
    margin-bottom: 6px;
    font-size: 14px;
    font-weight: 600;
    color: #333;
}

.profile-field input {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 12px;
    border: 1px solid #ccd4cc;
    border-radius: 8px;
    font-size: 14px;
    outline: none;
}

.profile-field input:focus {
    border-color: #0b8a47;
}

.profile-button {
    width: 100%;
    border: none;
    border-radius: 8px;
    padding: 12px;
    background: #0b8a47;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}

.profile-button:hover {
    background: #1b5e20;
}

@media (max-width: 800px) {
    .profile-page {
        padding: 15px;
    }

    .profile-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="profile-page">

<div class="profile-title">
    <h1>My Profile</h1>
    <p>Manage your account information and security.</p>
</div>

<?php if ($message): ?>
    <div class="profile-alert profile-success">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="profile-alert profile-error">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($user): ?>

    <!-- PROFILE HEADER -->
    <div class="profile-top">

        <div class="profile-avatar">
            <?= htmlspecialchars($initial) ?>
        </div>

        <div>
            <div class="profile-name">
                <?= htmlspecialchars($display_name) ?>
            </div>

            <div class="profile-role">
                <?= htmlspecialchars(ucfirst($user['role'])) ?>
            </div>
        </div>

    </div>


    <div class="profile-grid">

        <!-- PERSONAL INFORMATION -->
        <div class="profile-card">

            <h2>Personal Information</h2>

            <form method="POST">

                <div class="profile-field">
                    <label>Username</label>
                    <input
                        type="text"
                        name="username"
                        value="<?= htmlspecialchars($user['username']) ?>"
                        required
                    >
                </div>

                <div class="profile-field">
                    <label>Full Name</label>
                    <input
                        type="text"
                        name="fullname"
                        value="<?= htmlspecialchars($user['fullname']) ?>"
                        required
                    >
                </div>

                <div class="profile-field">
                    <label>Email</label>
                    <input
                        type="email"
                        name="email"
                        value="<?= htmlspecialchars($user['email']) ?>"
                        required
                    >
                </div>

                <div class="profile-field">
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
                    name="save_profile"
                    class="profile-button"
                >
                    Save Changes
                </button>

            </form>

        </div>


        <!-- SECURITY -->
        <div class="profile-card">

            <h2>Security</h2>

            <form method="POST">

                <div class="profile-field">
                    <label>Current Password</label>
                    <input
                        type="password"
                        name="current_password"
                        required
                    >
                </div>

                <div class="profile-field">
                    <label>New Password</label>
                    <input
                        type="password"
                        name="new_password"
                        minlength="8"
                        required
                    >
                </div>

                <div class="profile-field">
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
                    class="profile-button"
                >
                    Change Password
                </button>

            </form>

        </div>

    </div>

<?php endif; ?>

</div>