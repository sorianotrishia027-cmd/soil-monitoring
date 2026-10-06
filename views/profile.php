<?php
// ============================================================
// PROFILE VIEW
// Compatible with dashboard.php + css/style.css
// Supports both ADMIN and FARMER
// Official contact field: users.contact_number
// CSRF PROTECTED
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db_connect.php';

// ============================================================
// AUTHENTICATION
// ============================================================
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

if ($user_id <= 0) {
    header("Location: ../login.php");
    exit;
}

// ============================================================
// CSRF TOKEN
// ============================================================
if (
    !isset($_SESSION['profile_csrf_token']) ||
    !is_string($_SESSION['profile_csrf_token']) ||
    strlen($_SESSION['profile_csrf_token']) < 32
) {
    $_SESSION['profile_csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['profile_csrf_token'];

// ============================================================
// HELPER: NORMALIZE PHILIPPINE CONTACT NUMBER
// ============================================================
function normalizeContact($value)
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    // Remove spaces, dashes, parentheses, etc.
    $value = preg_replace('/[^0-9+]/', '', $value);

    // +639XXXXXXXXX -> 09XXXXXXXXX
    if (strpos($value, '+63') === 0) {
        $value = '0' . substr($value, 3);
    }

    // 639XXXXXXXXX -> 09XXXXXXXXX
    elseif (strpos($value, '63') === 0 && strlen($value) === 12) {
        $value = '0' . substr($value, 2);
    }

    return $value;
}

// ============================================================
// LOAD CURRENT USER
// ============================================================
$stmt = $conn->prepare("
    SELECT
        id,
        username,
        email,
        fullname,
        role,
        contact_number
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    die("Database error.");
}

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

if (!$user) {
    session_unset();
    session_destroy();

    header("Location: ../login.php");
    exit;
}

// ============================================================
// ROLE
// ============================================================
$role = strtolower(trim($user['role'] ?? ''));

if ($role !== 'admin' && $role !== 'farmer') {
    http_response_code(403);
    exit("Access denied.");
}

// Keep session role synchronized with database.
$_SESSION['role'] = $role;

// ============================================================
// PAGE INFORMATION
// ============================================================
if ($role === 'admin') {
    $page_title = "Admin Profile";
    $page_description = "Manage your administrator account information and password.";
} else {
    $page_title = "My Profile";
    $page_description = "Manage your farmer account information and password.";
}

// ============================================================
// FORM VALUES
// ============================================================
$username = $user['username'] ?? '';
$email = $user['email'] ?? '';
$fullname = $user['fullname'] ?? '';
$contact_number = $user['contact_number'] ?? '';

// ============================================================
// MESSAGES
// ============================================================
$success_message = '';
$error_message = '';

// ============================================================
// HANDLE POST REQUESTS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ========================================================
    // CSRF VALIDATION
    // ========================================================
    $submitted_csrf = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submitted_csrf) ||
        !isset($_SESSION['profile_csrf_token']) ||
        !is_string($_SESSION['profile_csrf_token']) ||
        !hash_equals(
            $_SESSION['profile_csrf_token'],
            $submitted_csrf
        )
    ) {

        $error_message =
            "Security validation failed. Please refresh the page and try again.";

    } else {

        // ====================================================
        // UPDATE PROFILE
        // ====================================================
        if (isset($_POST['update_profile'])) {

            $new_username = trim($_POST['username'] ?? '');
            $new_email = trim($_POST['email'] ?? '');
            $new_fullname = trim($_POST['fullname'] ?? '');
            $new_contact = normalizeContact(
                $_POST['contact_number'] ?? ''
            );

            // ------------------------------------------------
            // BASIC VALIDATION
            // ------------------------------------------------
            if (
                $new_username === '' ||
                $new_email === '' ||
                $new_fullname === ''
            ) {

                $error_message =
                    "Username, email, and full name are required.";

            } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {

                $error_message =
                    "Please enter a valid email address.";

            } elseif (
                $new_contact !== '' &&
                !preg_match('/^09[0-9]{9}$/', $new_contact)
            ) {

                $error_message =
                    "Please enter a valid Philippine mobile number.";

            } else {

                // --------------------------------------------
                // CHECK DUPLICATE USERNAME
                // --------------------------------------------
                $duplicate_username = false;

                $stmt = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE username = ?
                      AND id != ?
                    LIMIT 1
                ");

                if ($stmt) {

                    $stmt->bind_param(
                        "si",
                        $new_username,
                        $user_id
                    );

                    $stmt->execute();

                    $duplicate_username =
                        $stmt->get_result()->num_rows > 0;

                    $stmt->close();
                }

                if ($duplicate_username) {

                    $error_message =
                        "Username is already being used by another account.";

                } else {

                    // ----------------------------------------
                    // CHECK DUPLICATE EMAIL
                    // ----------------------------------------
                    $duplicate_email = false;

                    $stmt = $conn->prepare("
                        SELECT id
                        FROM users
                        WHERE email = ?
                          AND id != ?
                        LIMIT 1
                    ");

                    if ($stmt) {

                        $stmt->bind_param(
                            "si",
                            $new_email,
                            $user_id
                        );

                        $stmt->execute();

                        $duplicate_email =
                            $stmt->get_result()->num_rows > 0;

                        $stmt->close();
                    }

                    if ($duplicate_email) {

                        $error_message =
                            "Email address is already being used by another account.";

                    } else {

                        // ------------------------------------
                        // CHECK DUPLICATE CONTACT NUMBER
                        // ------------------------------------
                        $duplicate_contact = false;

                        if ($new_contact !== '') {

                            $stmt = $conn->prepare("
                                SELECT id
                                FROM users
                                WHERE contact_number = ?
                                  AND id != ?
                                LIMIT 1
                            ");

                            if ($stmt) {

                                $stmt->bind_param(
                                    "si",
                                    $new_contact,
                                    $user_id
                                );

                                $stmt->execute();

                                $duplicate_contact =
                                    $stmt->get_result()->num_rows > 0;

                                $stmt->close();
                            }
                        }

                        if ($duplicate_contact) {

                            $error_message =
                                "Contact number is already registered to another account.";

                        } else {

                            // --------------------------------
                            // UPDATE PROFILE
                            // --------------------------------
                            if ($new_contact === '') {

                                $stmt = $conn->prepare("
                                    UPDATE users
                                    SET
                                        username = ?,
                                        email = ?,
                                        fullname = ?,
                                        contact_number = NULL
                                    WHERE id = ?
                                ");

                                if ($stmt) {

                                    $stmt->bind_param(
                                        "sssi",
                                        $new_username,
                                        $new_email,
                                        $new_fullname,
                                        $user_id
                                    );
                                }

                            } else {

                                $stmt = $conn->prepare("
                                    UPDATE users
                                    SET
                                        username = ?,
                                        email = ?,
                                        fullname = ?,
                                        contact_number = ?
                                    WHERE id = ?
                                ");

                                if ($stmt) {

                                    $stmt->bind_param(
                                        "ssssi",
                                        $new_username,
                                        $new_email,
                                        $new_fullname,
                                        $new_contact,
                                        $user_id
                                    );
                                }
                            }

                            if (!$stmt) {

                                $error_message =
                                    "Unable to prepare profile update.";

                            } elseif ($stmt->execute()) {

                                $success_message =
                                    "Profile information updated successfully.";

                                // Update local values.
                                $username = $new_username;
                                $email = $new_email;
                                $fullname = $new_fullname;
                                $contact_number = $new_contact;

                                // Update session values.
                                $_SESSION['username'] = $new_username;
                                $_SESSION['email'] = $new_email;
                                $_SESSION['fullname'] = $new_fullname;

                                // --------------------------------
                                // REGENERATE CSRF TOKEN
                                // --------------------------------
                                $_SESSION['profile_csrf_token'] =
                                    bin2hex(random_bytes(32));

                                $csrf_token =
                                    $_SESSION['profile_csrf_token'];

                            } else {

                                $error_message =
                                    "Unable to update your profile. Please try again.";
                            }

                            if ($stmt) {
                                $stmt->close();
                            }
                        }
                    }
                }
            }
        }

        // ====================================================
        // CHANGE PASSWORD
        // ====================================================
        elseif (isset($_POST['change_password'])) {

            $current_password =
                $_POST['current_password'] ?? '';

            $new_password =
                $_POST['new_password'] ?? '';

            $confirm_password =
                $_POST['confirm_password'] ?? '';

            // ------------------------------------------------
            // VALIDATION
            // ------------------------------------------------
            if (
                $current_password === '' ||
                $new_password === '' ||
                $confirm_password === ''
            ) {

                $error_message =
                    "Please complete all password fields.";

            } elseif ($new_password !== $confirm_password) {

                $error_message =
                    "New password and confirmation password do not match.";

            } elseif (strlen($new_password) < 8) {

                $error_message =
                    "New password must be at least 8 characters long.";

            } else {

                // --------------------------------------------
                // GET CURRENT PASSWORD HASH
                // --------------------------------------------
                $stmt = $conn->prepare("
                    SELECT password
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$stmt) {

                    $error_message =
                        "Unable to verify your current password.";

                } else {

                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();

                    $password_result =
                        $stmt->get_result();

                    $password_user =
                        $password_result->fetch_assoc();

                    $stmt->close();

                    if (!$password_user) {

                        $error_message =
                            "User account could not be found.";

                    } elseif (
                        !password_verify(
                            $current_password,
                            $password_user['password']
                        )
                    ) {

                        $error_message =
                            "Current password is incorrect.";

                    } else {

                        // ------------------------------------
                        // HASH NEW PASSWORD
                        // ------------------------------------
                        $new_password_hash =
                            password_hash(
                                $new_password,
                                PASSWORD_BCRYPT
                            );

                        $stmt = $conn->prepare("
                            UPDATE users
                            SET password = ?
                            WHERE id = ?
                        ");

                        if (!$stmt) {

                            $error_message =
                                "Unable to prepare password update.";

                        } else {

                            $stmt->bind_param(
                                "si",
                                $new_password_hash,
                                $user_id
                            );

                            if ($stmt->execute()) {

                                $success_message =
                                    "Password changed successfully.";

                                // --------------------------------
                                // REGENERATE CSRF TOKEN
                                // --------------------------------
                                $_SESSION['profile_csrf_token'] =
                                    bin2hex(random_bytes(32));

                                $csrf_token =
                                    $_SESSION['profile_csrf_token'];

                            } else {

                                $error_message =
                                    "Unable to change your password. Please try again.";
                            }

                            $stmt->close();
                        }
                    }
                }
            }
        }
    }
}

?>

<div class="sub-view-panel-container profile-view-container">

    <!-- =====================================================
         PROFILE HEADER
         ===================================================== -->
    <div class="view-panel-header">

        <h3>
            <?php echo htmlspecialchars(
                $page_title,
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </h3>

        <p>
            <?php echo htmlspecialchars(
                $page_description,
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </p>

    </div>


    <!-- =====================================================
         SUCCESS MESSAGE
         ===================================================== -->
    <?php if ($success_message !== ''): ?>

        <div class="alert success">
            <?php echo htmlspecialchars(
                $success_message,
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </div>

    <?php endif; ?>


    <!-- =====================================================
         ERROR MESSAGE
         ===================================================== -->
    <?php if ($error_message !== ''): ?>

        <div class="alert danger">
            <?php echo htmlspecialchars(
                $error_message,
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </div>

    <?php endif; ?>


    <!-- =====================================================
         PROFILE + PASSWORD GRID
         ===================================================== -->
    <div class="telemetry-detailed-grid profile-form-grid">

        <!-- =================================================
             ACCOUNT INFORMATION
             ================================================= -->
        <div class="data-metric-row-card profile-form-card">

            <h4>Account Information</h4>

            <form method="POST" action="">

                <!-- CSRF TOKEN -->
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars(
                        $csrf_token,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>"
                >

                <!-- FULL NAME -->
                <div class="profile-form-group">

                    <label for="fullname">
                        Full Name
                    </label>

                    <input
                        type="text"
                        id="fullname"
                        name="fullname"
                        value="<?php echo htmlspecialchars(
                            $fullname,
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                        required
                    >

                </div>


                <!-- USERNAME -->
                <div class="profile-form-group">

                    <label for="username">
                        Username
                    </label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        value="<?php echo htmlspecialchars(
                            $username,
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                        required
                    >

                </div>


                <!-- EMAIL -->
                <div class="profile-form-group">

                    <label for="email">
                        Email Address
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?php echo htmlspecialchars(
                            $email,
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                        required
                    >

                </div>


                <!-- CONTACT NUMBER -->
                <div class="profile-form-group">

                    <label for="contact_number">
                        Contact Number
                    </label>

                    <input
                        type="text"
                        id="contact_number"
                        name="contact_number"
                        value="<?php echo htmlspecialchars(
                            $contact_number,
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                        placeholder="09XXXXXXXXX"
                        maxlength="11"
                    >

                    <small>
                        This is the official contact number used
                        by the system for your registered account.
                    </small>

                </div>


                <!-- ROLE -->
                <div class="profile-form-group">

                    <label>
                        Account Role
                    </label>

                    <div class="profile-readonly-value">
                        <?php echo htmlspecialchars(
                            ucfirst($role),
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>
                    </div>

                </div>


                <!-- SAVE PROFILE -->
                <button
                    type="submit"
                    name="update_profile"
                    class="profile-primary-btn"
                >
                    Save Profile Changes
                </button>

            </form>

        </div>


        <!-- =================================================
             CHANGE PASSWORD
             ================================================= -->
        <div class="data-metric-row-card profile-form-card">

            <h4>Change Password</h4>

            <form method="POST" action="">

                <!-- CSRF TOKEN -->
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars(
                        $csrf_token,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>"
                >


                <!-- CURRENT PASSWORD -->
                <div class="profile-form-group">

                    <label for="current_password">
                        Current Password
                    </label>

                    <div class="password-input-wrapper">

                        <input
                            type="password"
                            id="current_password"
                            name="current_password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="password-eye-btn"
                            onclick="togglePassword(
                                'current_password',
                                this
                            )"
                            aria-label="Show password"
                            title="Show password"
                        >

                            <!-- CLOSED EYE -->
                            <svg
                                class="eye-icon eye-closed"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />

                                <circle
                                    cx="12"
                                    cy="12"
                                    r="2.7"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                />
                            </svg>


                            <!-- OPEN EYE / SLASH -->
                            <svg
                                class="eye-icon eye-open"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    d="M3 3l18 18"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                />

                                <path
                                    d="M10.6 6.2A10.5 10.5 0 0 1 12 6c6.5 0 10 6 10 6a17.6 17.6 0 0 1-3.1 3.5"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />

                                <path
                                    d="M6.1 9.1C3.7 10.5 2 12 2 12s3.5 6 10 6c1.4 0 2.7-.3 3.8-.8"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />

                                <path
                                    d="M9.8 9.8a3 3 0 0 0 4.4 4.4"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                />
                            </svg>

                        </button>

                    </div>

                </div>


                <!-- NEW PASSWORD -->
                <div class="profile-form-group">

                    <label for="new_password">
                        New Password
                    </label>

                    <div class="password-input-wrapper">

                        <input
                            type="password"
                            id="new_password"
                            name="new_password"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        >

                        <button
                            type="button"
                            class="password-eye-btn"
                            onclick="togglePassword(
                                'new_password',
                                this
                            )"
                            aria-label="Show password"
                            title="Show password"
                        >

                            <!-- CLOSED EYE -->
                            <svg
                                class="eye-icon eye-closed"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />

                                <circle
                                    cx="12"
                                    cy="12"
                                    r="2.7"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                />
                            </svg>


                            <!-- OPEN EYE / SLASH -->
                            <svg
                                class="eye-icon eye-open"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    d="M3 3l18 18"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                />

                                <path
                                    d="M10.6 6.2A10.5 10.5 0 0 1 12 6c6.5 0 10 6 10 6a17.6 17.6 0 0 1-3.1 3.5"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />

                                <path
                                    d="M6.1 9.1C3.7 10.5 2 12 2 12s3.5 6 10 6c1.4 0 2.7-.3 3.8-.8"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />

                                <path
                                    d="M9.8 9.8a3 3 0 0 0 4.4 4.4"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                />
                            </svg>

                        </button>

                    </div>

                    <small>
                        Password must contain at least 8 characters.
                    </small>

                </div>


                <!-- CONFIRM PASSWORD -->
                <div class="profile-form-group">

                    <label for="confirm_password">
                        Confirm New Password
                    </label>

                    <div class="password-input-wrapper">

                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        >

                        <button
                            type="button"
                            class="password-eye-btn"
                            onclick="togglePassword(
                                'confirm_password',
                                this
                            )"
                            aria-label="Show password"
                            title="Show password"
                        >

                            <!-- CLOSED EYE -->
                            <svg
                                class="eye-icon eye-closed"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />

                                <circle
                                    cx="12"
                                    cy="12"
                                    r="2.7"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                />
                            </svg>


                            <!-- OPEN EYE / SLASH -->
                            <svg
                                class="eye-icon eye-open"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    d="M3 3l18 18"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                />

                                <path
                                    d="M10.6 6.2A10.5 10.5 0 0 1 12 6c6.5 0 10 6 10 6a17.6 17.6 0 0 1-3.1 3.5"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />

                                <path
                                    d="M6.1 9.1C3.7 10.5 2 12 2 12s3.5 6 10 6c1.4 0 2.7-.3 3.8-.8"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />

                                <path
                                    d="M9.8 9.8a3 3 0 0 0 4.4 4.4"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                />
                            </svg>

                        </button>

                    </div>

                </div>


                <!-- CHANGE PASSWORD -->
                <button
                    type="submit"
                    name="change_password"
                    class="profile-secondary-btn"
                >
                    Change Password
                </button>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     PROFILE-SPECIFIC STYLE
     Uses SAME system colors and design language.
     ========================================================= -->
<style>

.profile-view-container {
    width: 100%;
}

.profile-form-grid {
    align-items: start;
}

.profile-form-card {
    background: #ffffff;
}

.profile-form-card h4 {
    font-size: 16px;
    color: var(--text-muted);
    margin-bottom: 20px;
    font-weight: 600;
}

.profile-form-group {
    margin-bottom: 18px;
}

.profile-form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #333333;
    margin-bottom: 7px;
}

.profile-form-group input {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #ccd4cc;
    border-radius: 10px;
    background: #f9f9f9;
    color: var(--text-color);
    font-family: inherit;
    font-size: 14px;
    outline: none;
    transition:
        border-color 0.2s ease,
        background-color 0.2s ease,
        box-shadow 0.2s ease;
}

.profile-form-group input:focus {
    border-color: var(--primary-color);
    background: #ffffff;
    box-shadow: 0 0 0 2px rgba(11, 138, 71, 0.08);
}

.profile-form-group small {
    display: block;
    margin-top: 6px;
    color: #777777;
    font-size: 12px;
    line-height: 1.4;
}

.profile-readonly-value {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #e0e6e0;
    border-radius: 10px;
    background: #f9f9f9;
    color: #555555;
    font-size: 14px;
    font-weight: 600;
}

/* =========================================================
   PASSWORD INPUT + EYE ICON
   ========================================================= */

.password-input-wrapper {
    position: relative;
    width: 100%;
}

.password-input-wrapper input {
    padding-right: 48px;
}

.password-eye-btn {
    position: absolute;
    top: 50%;
    right: 10px;
    transform: translateY(-50%);

    width: 34px;
    height: 34px;

    display: flex;
    align-items: center;
    justify-content: center;

    border: none;
    background: transparent;

    color: #666666;

    padding: 0;
    margin: 0;

    cursor: pointer;

    border-radius: 8px;

    transition:
        color 0.2s ease,
        background-color 0.2s ease;
}

.password-eye-btn:hover {
    color: var(--primary-color);
    background: #e8f5e9;
}

.password-eye-btn:focus {
    outline: 2px solid rgba(11, 138, 71, 0.25);
    outline-offset: 1px;
}

.eye-icon {
    width: 20px;
    height: 20px;
    display: block;
}

.eye-open {
    display: none;
}

.password-eye-btn.showing .eye-closed {
    display: none;
}

.password-eye-btn.showing .eye-open {
    display: block;
}

/* =========================================================
   BUTTONS
   ========================================================= */

.profile-primary-btn,
.profile-secondary-btn {
    width: 100%;
    border-radius: 10px;
    padding: 13px 16px;

    font-family: inherit;
    font-size: 14px;
    font-weight: 700;

    cursor: pointer;

    transition: all 0.2s ease;

    margin-top: 5px;
}

.profile-primary-btn {
    border: none;
    background: var(--primary-color);
    color: #ffffff;

    box-shadow:
        0 4px 10px rgba(11, 138, 71, 0.15);
}

.profile-primary-btn:hover {
    background: var(--primary-hover);
    transform: translateY(-1px);
}

.profile-secondary-btn {
    background: #f9f9f9;
    color: var(--primary-color);
    border: 1px solid #ccd4cc;
}

.profile-secondary-btn:hover {
    background: #e8f5e9;
    border-color: var(--primary-color);
}

.profile-form-card form {
    width: 100%;
}

/* =========================================================
   MOBILE
   ========================================================= */

@media (max-width: 768px) {

    .profile-form-grid {
        grid-template-columns: 1fr;
    }

    .profile-form-card {
        padding: 20px;
    }
}

</style>


<!-- =========================================================
     PASSWORD SHOW / HIDE SCRIPT
     ========================================================= -->
<script>

function togglePassword(inputId, button) {

    const input = document.getElementById(inputId);

    if (!input) {
        return;
    }

    if (input.type === "password") {

        input.type = "text";

        button.classList.add("showing");

        button.setAttribute(
            "aria-label",
            "Hide password"
        );

        button.setAttribute(
            "title",
            "Hide password"
        );

    } else {

        input.type = "password";

        button.classList.remove("showing");

        button.setAttribute(
            "aria-label",
            "Show password"
        );

        button.setAttribute(
            "title",
            "Show password"
        );
    }
}

</script>