<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "../config/db_connect.php";

// =====================================================
// ACCESS CONTROL
// =====================================================
if (!isset($_SESSION['user_id'])) {
    echo "<p class='error'>Access denied. Please log in first.</p>";
    exit;
}

$user_id = intval($_SESSION['user_id']);
$user_role = strtolower($_SESSION['role'] ?? 'farmer');

$is_admin = ($user_role === 'admin');
$is_farmer = ($user_role === 'farmer');

// =====================================================
// PAGE LABELS BASED ON ROLE
// =====================================================
if ($is_admin) {
    $profile_title = "Admin Profile";
    $profile_subtitle = "Manage your administrator account information and security settings.";
    $account_badge = "Administrator Account";
} else {
    $profile_title = "Farmer's Profile";
    $profile_subtitle = "Manage your farmer account information and SMS alert contact details.";
    $account_badge = "Farmer Account";
}

// =====================================================
// MAKE SURE contact_number COLUMN EXISTS
// =====================================================
try {
    $conn->exec("ALTER TABLE users ADD COLUMN contact_number VARCHAR(20) DEFAULT NULL");
} catch (PDOException $e) {
    // Column already exists
}

// =====================================================
// UPDATE PROFILE
// =====================================================
$action_msg = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_action'])) {

    $action = $_POST['form_action'];

    // =================================================
    // UPDATE ACCOUNT INFORMATION
    // =================================================
    if ($action === 'update_profile') {

        $username = trim($_POST['username'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');

        if (empty($username)) {

            $action_msg = "
                <div class='alert danger'>
                    Username cannot be empty.
                </div>
            ";

        } else {

            try {

                // Check if username is already used by another account
                $check_stmt = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE username = ?
                    AND id != ?
                    LIMIT 1
                ");

                $check_stmt->execute([
                    $username,
                    $user_id
                ]);

                if ($check_stmt->fetch()) {

                    $action_msg = "
                        <div class='alert danger'>
                            Username is already being used by another account.
                        </div>
                    ";

                } else {

                    $stmt = $conn->prepare("
                        UPDATE users
                        SET username = ?,
                            contact_number = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $username,
                        $contact_number,
                        $user_id
                    ]);

                    // Update session username if used elsewhere
                    $_SESSION['username'] = $username;

                    $action_msg = "
                        <div class='alert success'>
                            ✅ Profile information updated successfully.
                        </div>
                    ";
                }

            } catch (PDOException $e) {

                $action_msg = "
                    <div class='alert danger'>
                        Profile update failed.
                    </div>
                ";
            }
        }
    }

    // =================================================
    // CHANGE PASSWORD
    // =================================================
    if ($action === 'change_password') {

        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (
            empty($current_password) ||
            empty($new_password) ||
            empty($confirm_password)
        ) {

            $action_msg = "
                <div class='alert warning'>
                    Please complete all password fields.
                </div>
            ";

        } elseif ($new_password !== $confirm_password) {

            $action_msg = "
                <div class='alert danger'>
                    New passwords do not match.
                </div>
            ";

        } elseif (strlen($new_password) < 6) {

            $action_msg = "
                <div class='alert warning'>
                    New password must contain at least 6 characters.
                </div>
            ";

        } else {

            try {

                // Get current password
                $stmt = $conn->prepare("
                    SELECT password
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->execute([$user_id]);

                $user_password = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user_password || !password_verify($current_password, $user_password['password'])) {

                    $action_msg = "
                        <div class='alert danger'>
                            Current password is incorrect.
                        </div>
                    ";

                } else {

                    $hashed_password = password_hash(
                        $new_password,
                        PASSWORD_BCRYPT
                    );

                    $update_stmt = $conn->prepare("
                        UPDATE users
                        SET password = ?
                        WHERE id = ?
                    ");

                    $update_stmt->execute([
                        $hashed_password,
                        $user_id
                    ]);

                    $action_msg = "
                        <div class='alert success'>
                            🔐 Password changed successfully.
                        </div>
                    ";
                }

            } catch (PDOException $e) {

                $action_msg = "
                    <div class='alert danger'>
                        Password update failed.
                    </div>
                ";
            }
        }
    }
}

// =====================================================
// GET CURRENT USER PROFILE
// =====================================================
try {

    $stmt = $conn->prepare("
        SELECT
            id,
            username,
            email,
            fullname,
            role,
            contact_number,
            created_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$user_id]);

    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        echo "
            <div class='alert danger'>
                User profile could not be found.
            </div>
        ";
        exit;
    }

} catch (PDOException $e) {

    echo "
        <div class='alert danger'>
            Unable to load profile information.
        </div>
    ";
    exit;
}

$username = $profile['username'] ?? '';
$email = $profile['email'] ?? '';
$fullname = $profile['fullname'] ?? '';
$contact_number = $profile['contact_number'] ?? '';
$role = strtolower($profile['role'] ?? '');
$created_at = $profile['created_at'] ?? '';

?>

<div class="sub-view-panel-container">

<!-- =================================================
     PROFILE HEADER
================================================== -->
<div class="view-panel-header">

    <h3><?= htmlspecialchars($profile_title) ?></h3>

    <p>
        <?= htmlspecialchars($profile_subtitle) ?>
    </p>

</div>

<?= $action_msg ?>

<!-- =================================================
     ACCOUNT SUMMARY
================================================== -->
<div class="insights-dashboard-split-row" style="margin-bottom: 30px;">

    <div
        class="action-alert-panel-card"
        style="
            background: #ffffff;
            border: 1px solid #ccd4cc;
        "
    >

        <h3
            style="
                margin-bottom: 15px;
                color: var(--primary-color);
            "
        >
            Account Information
        </h3>

        <div
            style="
                display: grid;
                gap: 12px;
            "
        >

            <div>
                <span
                    style="
                        display: block;
                        font-size: 12px;
                        color: #777;
                        margin-bottom: 3px;
                    "
                >
                    Full Name
                </span>

                <strong>
                    <?= htmlspecialchars($fullname ?: 'Not provided') ?>
                </strong>
            </div>

            <div>
                <span
                    style="
                        display: block;
                        font-size: 12px;
                        color: #777;
                        margin-bottom: 3px;
                    "
                >
                    Email Address
                </span>

                <strong>
                    <?= htmlspecialchars($email) ?>
                </strong>
            </div>

            <div>
                <span
                    style="
                        display: block;
                        font-size: 12px;
                        color: #777;
                        margin-bottom: 3px;
                    "
                >
                    Account Role
                </span>

                <span
                    class="status-pill"
                    style="
                        display: inline-block;
                        margin-top: 3px;
                        background: <?= $is_admin ? '#e3f2fd' : '#e8f5e9' ?>;
                        color: <?= $is_admin ? '#0d47a1' : '#2e7d32' ?>;
                    "
                >
                    <?= ucfirst(htmlspecialchars($role)) ?>
                </span>
            </div>

            <div>
                <span
                    style="
                        display: block;
                        font-size: 12px;
                        color: #777;
                        margin-bottom: 3px;
                    "
                >
                    Member Since
                </span>

                <strong>
                    <?= htmlspecialchars($created_at ?: 'Not available') ?>
                </strong>
            </div>

        </div>

    </div>

    <!-- =================================================
         ROLE NOTICE
    ================================================== -->
    <div
        class="action-alert-panel-card"
        style="
            background: #ffffff;
            border: 1px solid #ccd4cc;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        "
    >

        <div>

            <h3 style="margin-bottom: 15px;">
                <?= htmlspecialchars($account_badge) ?>
            </h3>

            <?php if ($is_farmer): ?>

                <p
                    style="
                        font-size: 14px;
                        line-height: 1.6;
                        color: var(--text-muted);
                    "
                >
                    Your mobile number is used by the soil monitoring
                    system for SMS alerts when critical soil conditions
                    are detected.
                </p>

                <p
                    style="
                        font-size: 13px;
                        line-height: 1.6;
                        color: #0b8a47;
                        margin-top: 12px;
                    "
                >
                    📱 <strong>SMS Alerts:</strong>
                    Make sure your mobile number is correct so you can
                    receive important soil monitoring notifications.
                </p>

            <?php else: ?>

                <p
                    style="
                        font-size: 14px;
                        line-height: 1.6;
                        color: var(--text-muted);
                    "
                >
                    You are currently signed in using an administrator
                    account. You can manage your administrator account
                    information and security settings from this page.
                </p>

                <p
                    style="
                        font-size: 13px;
                        line-height: 1.6;
                        color: #1565c0;
                        margin-top: 12px;
                    "
                >
                    🛡️ <strong>Administrator Access:</strong>
                    Your account has administrative access to the
                    cooperative management system.
                </p>

            <?php endif; ?>

        </div>

    </div>

</div>

<!-- =================================================
     EDIT PROFILE
================================================== -->
<div class="view-panel-header">

    <h3>Update Profile Information</h3>

</div>

<div
    class="action-alert-panel-card"
    style="
        background: #ffffff;
        border: 1px solid #ccd4cc;
        margin-bottom: 30px;
    "
>

    <form
        action=""
        method="POST"
    >

        <input
            type="hidden"
            name="form_action"
            value="update_profile"
        >

        <div
            class="insights-dashboard-split-row"
            style="gap: 20px;"
        >

            <!-- USERNAME -->
            <div>

                <label
                    class="chip-label"
                    style="
                        text-align: left;
                        display: block;
                        margin-bottom: 5px;
                    "
                >
                    Username
                </label>

                <div
                    class="input-wrapper"
                    style="background: #f4f6f4;"
                >

                    <input
                        type="text"
                        name="username"
                        value="<?= htmlspecialchars($username) ?>"
                        placeholder="Username"
                        required
                    >

                </div>

            </div>

            <!-- FULL NAME -->
            <div>

                <label
                    class="chip-label"
                    style="
                        text-align: left;
                        display: block;
                        margin-bottom: 5px;
                    "
                >
                    Full Name
                </label>

                <div
                    class="input-wrapper"
                    style="background: #f4f6f4;"
                >

                    <input
                        type="text"
                        value="<?= htmlspecialchars($fullname) ?>"
                        placeholder="Full Name"
                        readonly
                    >

                </div>

            </div>

        </div>

        <!-- EMAIL -->
        <div style="margin-top: 15px;">

            <label
                class="chip-label"
                style="
                    text-align: left;
                    display: block;
                    margin-bottom: 5px;
                "
            >
                Email Address
            </label>

            <div
                class="input-wrapper"
                style="background: #eeeeee;"
            >

                <input
                    type="email"
                    value="<?= htmlspecialchars($email) ?>"
                    readonly
                >

            </div>

        </div>

        <!-- CONTACT NUMBER -->
        <div style="margin-top: 15px;">

            <label
                class="chip-label"
                style="
                    text-align: left;
                    display: block;
                    margin-bottom: 5px;
                "
            >
                <?php if ($is_farmer): ?>
                    Mobile Number for SMS Alerts
                <?php else: ?>
                    Contact Number
                <?php endif; ?>
            </label>

            <div
                class="input-wrapper"
                style="background: #f4f6f4;"
            >

                <input
                    type="tel"
                    name="contact_number"
                    value="<?= htmlspecialchars($contact_number) ?>"
                    placeholder="09XXXXXXXXX"
                    maxlength="20"
                >

            </div>

            <?php if ($is_farmer): ?>

                <small
                    style="
                        display: block;
                        margin-top: 6px;
                        color: #777;
                    "
                >
                    Example: 09123456789
                </small>

            <?php endif; ?>

        </div>

        <button
            type="submit"
            class="mockup-login-btn"
            style="margin-top: 20px;"
        >
            Save Profile Changes
        </button>

    </form>

</div>

<!-- =================================================
     CHANGE PASSWORD
================================================== -->
<div class="view-panel-header">

    <h3>Security Settings</h3>

</div>

<div
    class="action-alert-panel-card"
    style="
        background: #ffffff;
        border: 1px solid #ccd4cc;
    "
>

    <h3
        style="
            margin-bottom: 15px;
            color: var(--primary-color);
        "
    >
        Change Password
    </h3>

    <form
        action=""
        method="POST"
    >

        <input
            type="hidden"
            name="form_action"
            value="change_password"
        >

        <!-- CURRENT PASSWORD -->
        <div>

            <label
                class="chip-label"
                style="
                    text-align: left;
                    display: block;
                    margin-bottom: 5px;
                "
            >
                Current Password
            </label>

            <div
                class="input-wrapper"
                style="background: #f4f6f4;"
            >

                <input
                    type="password"
                    name="current_password"
                    placeholder="Enter current password"
                    required
                >

            </div>

        </div>

        <!-- NEW PASSWORD -->
        <div style="margin-top: 15px;">

            <label
                class="chip-label"
                style="
                    text-align: left;
                    display: block;
                    margin-bottom: 5px;
                "
            >
                New Password
            </label>

            <div
                class="input-wrapper"
                style="background: #f4f6f4;"
            >

                <input
                    type="password"
                    name="new_password"
                    placeholder="Enter new password"
                    minlength="6"
                    required
                >

            </div>

        </div>

        <!-- CONFIRM PASSWORD -->
        <div style="margin-top: 15px;">

            <label
                class="chip-label"
                style="
                    text-align: left;
                    display: block;
                    margin-bottom: 5px;
                "
            >
                Confirm New Password
            </label>

            <div
                class="input-wrapper"
                style="background: #f4f6f4;"
            >

                <input
                    type="password"
                    name="confirm_password"
                    placeholder="Confirm new password"
                    minlength="6"
                    required
                >

            </div>

        </div>

        <button
            type="submit"
            class="mockup-login-btn"
            style="margin-top: 20px;"
        >
            Change Password
        </button>

    </form>

</div>

</div>