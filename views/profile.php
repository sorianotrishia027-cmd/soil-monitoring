<?php
// ============================================================
// PROFILE VIEW
// Compatible with dashboard.php + css/style.css
// ADMIN + FARMER
// UI follows the existing system design
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
// NORMALIZE CONTACT NUMBER
// ============================================================
function normalizeContact($value)
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[^0-9+]/', '', $value);

    if (strpos($value, '+63') === 0) {
        $value = '0' . substr($value, 3);
    } elseif (
        strpos($value, '63') === 0 &&
        strlen($value) === 12
    ) {
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

$_SESSION['role'] = $role;

// ============================================================
// PAGE INFORMATION
// ============================================================
if ($role === 'admin') {
    $page_title = "My Profile";
    $page_description = "View and manage your administrator account information.";
} else {
    $page_title = "My Profile";
    $page_description = "View and manage your farmer account information.";
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
// HANDLE POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ========================================================
    // CSRF
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

                // ====================================================
                // CHECK USERNAME
                // ====================================================
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

                    // ====================================================
                    // CHECK EMAIL
                    // ====================================================
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

                        // ====================================================
                        // CHECK CONTACT
                        // ====================================================
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

                            // ====================================================
                            // UPDATE
                            // ====================================================
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

                                $username = $new_username;
                                $email = $new_email;
                                $fullname = $new_fullname;
                                $contact_number = $new_contact;

                                $_SESSION['username'] = $new_username;
                                $_SESSION['email'] = $new_email;
                                $_SESSION['fullname'] = $new_fullname;

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

<div class="profile-page">

<!-- =====================================================
     PAGE HEADER
     ===================================================== -->
<div class="profile-page-header">

    <div>
        <span class="profile-page-label">ACCOUNT</span>

        <h2>
            <?php echo htmlspecialchars(
                $page_title,
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </h2>

        <p>
            <?php echo htmlspecialchars(
                $page_description,
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </p>
    </div>

    <div class="profile-role-badge">
        <?php echo htmlspecialchars(
            ucfirst($role),
            ENT_QUOTES,
            'UTF-8'
        ); ?>
    </div>

</div>


<!-- =====================================================
     ALERTS
     ===================================================== -->
<?php if ($success_message !== ''): ?>

    <div class="profile-alert profile-alert-success">
        <span class="profile-alert-icon">✓</span>

        <span>
            <?php echo htmlspecialchars(
                $success_message,
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </span>
    </div>

<?php endif; ?>


<?php if ($error_message !== ''): ?>

    <div class="profile-alert profile-alert-error">
        <span class="profile-alert-icon">!</span>

        <span>
            <?php echo htmlspecialchars(
                $error_message,
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </span>
    </div>

<?php endif; ?>


<!-- =====================================================
     PROFILE CONTENT
     ===================================================== -->
<div class="profile-content-grid">

    <!-- =================================================
         PERSONAL INFORMATION
         ================================================= -->
    <section class="profile-card">

        <div class="profile-card-header">

            <div>
                <h3>Personal Information</h3>

                <p>
                    Update your basic account information.
                </p>
            </div>

        </div>


        <form method="POST" action="" class="profile-form">

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
            <div class="profile-field">

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
            <div class="profile-field">

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
            <div class="profile-field">

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


            <!-- CONTACT -->
            <div class="profile-field">

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
                    Official contact number registered to this account.
                </small>

            </div>


            <!-- ROLE -->
            <div class="profile-field">

                <label>
                    Account Role
                </label>

                <div class="profile-static-field">
                    <?php echo htmlspecialchars(
                        ucfirst($role),
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>
                </div>

            </div>


            <button
                type="submit"
                name="update_profile"
                class="profile-save-btn"
            >
                Save Changes
            </button>

        </form>

    </section>


    <!-- =================================================
         PASSWORD
         ================================================= -->
    <section class="profile-card">

        <div class="profile-card-header">

            <div>
                <h3>Security</h3>

                <p>
                    Change your account password.
                </p>
            </div>

        </div>


        <form method="POST" action="" class="profile-form">

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
            <div class="profile-field">

                <label for="current_password">
                    Current Password
                </label>

                <div class="profile-password-wrapper">

                    <input
                        type="password"
                        id="current_password"
                        name="current_password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="profile-eye-btn"
                        onclick="toggleProfilePassword('current_password', this)"
                        aria-label="Show password"
                        title="Show password"
                    >

                        <svg
                            class="profile-eye-visible"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M2.5 12C2.5 12 6 6 12 6C18 6 21.5 12 21.5 12C21.5 12 18 18 12 18C6 18 2.5 12 2.5 12Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />

                            <circle
                                cx="12"
                                cy="12"
                                r="2.5"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                        </svg>

                    </button>

                </div>

            </div>


            <!-- NEW PASSWORD -->
            <div class="profile-field">

                <label for="new_password">
                    New Password
                </label>

                <div class="profile-password-wrapper">

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
                        class="profile-eye-btn"
                        onclick="toggleProfilePassword('new_password', this)"
                        aria-label="Show password"
                        title="Show password"
                    >

                        <svg
                            class="profile-eye-visible"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M2.5 12C2.5 12 6 6 12 6C18 6 21.5 12 21.5 12C21.5 12 18 18 12 18C6 18 2.5 12 2.5 12Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />

                            <circle
                                cx="12"
                                cy="12"
                                r="2.5"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                        </svg>

                    </button>

                </div>

                <small>
                    Password must contain at least 8 characters.
                </small>

            </div>


            <!-- CONFIRM PASSWORD -->
            <div class="profile-field">

                <label for="confirm_password">
                    Confirm New Password
                </label>

                <div class="profile-password-wrapper">

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
                        class="profile-eye-btn"
                        onclick="toggleProfilePassword('confirm_password', this)"
                        aria-label="Show password"
                        title="Show password"
                    >

                        <svg
                            class="profile-eye-visible"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M2.5 12C2.5 12 6 6 12 6C18 6 21.5 12 21.5 12C21.5 12 18 18 12 18C6 18 2.5 12 2.5 12Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />

                            <circle
                                cx="12"
                                cy="12"
                                r="2.5"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                        </svg>

                    </button>

                </div>

            </div>


            <button
                type="submit"
                name="change_password"
                class="profile-password-btn"
            >
                Change Password
            </button>

        </form>

    </section>

</div>

</div>


<style>

/* ============================================================
   PROFILE PAGE
   FOLLOWS EXISTING SYSTEM GREEN / WHITE DESIGN
   ============================================================ */

.profile-page {
    width: 100%;
    max-width: 100%;
}


/* ============================================================
   HEADER
   ============================================================ */

.profile-page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;

    background: #ffffff;

    border: 1px solid var(--border-color);

    border-radius: 18px;

    padding: 24px 26px;

    margin-bottom: 20px;

    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04);
}

.profile-page-label {
    display: block;

    color: var(--primary-color);

    font-size: 11px;

    font-weight: 800;

    letter-spacing: 1px;

    margin-bottom: 6px;
}

.profile-page-header h2 {
    margin: 0;

    color: var(--text-color);

    font-size: 24px;

    font-weight: 700;
}

.profile-page-header p {
    margin: 7px 0 0;

    color: var(--text-muted);

    font-size: 13px;

    line-height: 1.5;
}

.profile-role-badge {
    flex-shrink: 0;

    padding: 8px 14px;

    border-radius: 999px;

    background: #e8f5e9;

    color: var(--primary-color);

    border: 1px solid #c8e6c9;

    font-size: 12px;

    font-weight: 700;
}


/* ============================================================
   ALERTS
   ============================================================ */

.profile-alert {
    display: flex;

    align-items: center;

    gap: 10px;

    padding: 13px 16px;

    border-radius: 12px;

    margin-bottom: 18px;

    font-size: 13px;

    font-weight: 600;
}

.profile-alert-success {
    background: #edf8f0;

    color: #216e39;

    border: 1px solid #cde8d3;
}

.profile-alert-error {
    background: #fff1f1;

    color: #b42318;

    border: 1px solid #f0caca;
}

.profile-alert-icon {
    width: 22px;

    height: 22px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: currentColor;

    color: #ffffff;

    font-size: 12px;

    font-weight: 800;
}


/* ============================================================
   CONTENT GRID
   ============================================================ */

.profile-content-grid {
    display: grid;

    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);

    gap: 20px;

    align-items: start;
}


/* ============================================================
   CARDS
   ============================================================ */

.profile-card {
    background: #ffffff;

    border: 1px solid var(--border-color);

    border-radius: 18px;

    padding: 24px;

    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04);
}

.profile-card-header {
    display: flex;

    justify-content: space-between;

    padding-bottom: 18px;

    margin-bottom: 20px;

    border-bottom: 1px solid #edf0ed;
}

.profile-card-header h3 {
    margin: 0;

    color: var(--text-color);

    font-size: 17px;

    font-weight: 700;
}

.profile-card-header p {
    margin: 5px 0 0;

    color: var(--text-muted);

    font-size: 12px;

    line-height: 1.4;
}


/* ============================================================
   FORM
   ============================================================ */

.profile-form {
    width: 100%;
}

.profile-field {
    margin-bottom: 18px;
}

.profile-field label {
    display: block;

    margin-bottom: 7px;

    color: #333333;

    font-size: 13px;

    font-weight: 600;
}

.profile-field input {
    width: 100%;

    box-sizing: border-box;

    padding: 11px 13px;

    border: 1px solid var(--border-color);

    border-radius: 10px;

    background: #fafbfa;

    color: var(--text-color);

    font-family: inherit;

    font-size: 14px;

    outline: none;

    transition:
        border-color 0.2s ease,
        background 0.2s ease,
        box-shadow 0.2s ease;
}

.profile-field input:hover {
    border-color: #b8c3b8;
}

.profile-field input:focus {
    border-color: var(--primary-color);

    background: #ffffff;

    box-shadow: 0 0 0 3px rgba(11, 138, 71, 0.08);
}

.profile-field small {
    display: block;

    margin-top: 6px;

    color: #777777;

    font-size: 11px;

    line-height: 1.4;
}


/* ============================================================
   READONLY ROLE
   ============================================================ */

.profile-static-field {
    width: 100%;

    box-sizing: border-box;

    padding: 11px 13px;

    border: 1px solid #e0e6e0;

    border-radius: 10px;

    background: #f5f7f5;

    color: #555555;

    font-size: 14px;

    font-weight: 600;
}


/* ============================================================
   PASSWORD
   ============================================================ */

.profile-password-wrapper {
    position: relative;

    width: 100%;
}

.profile-password-wrapper input {
    padding-right: 48px;
}

.profile-eye-btn {
    position: absolute;

    top: 50%;

    right: 8px;

    transform: translateY(-50%);

    width: 34px;

    height: 34px;

    display: flex;

    align-items: center;

    justify-content: center;

    padding: 0;

    margin: 0;

    border: none;

    border-radius: 8px;

    background: transparent;

    color: #777777;

    cursor: pointer;

    transition:
        color 0.2s ease,
        background 0.2s ease;
}

.profile-eye-btn:hover {
    color: var(--primary-color);

    background: #edf7ef;
}

.profile-eye-btn:focus {
    outline: 2px solid rgba(11, 138, 71, 0.2);

    outline-offset: 1px;
}

.profile-eye-visible {
    width: 19px;

    height: 19px;

    display: block;
}


/* ============================================================
   BUTTONS
   ============================================================ */

.profile-save-btn,
.profile-password-btn {
    width: 100%;

    min-height: 44px;

    padding: 11px 16px;

    border-radius: 10px;

    font-family: inherit;

    font-size: 13px;

    font-weight: 700;

    cursor: pointer;

    transition:
        background 0.2s ease,
        border-color 0.2s ease,
        transform 0.2s ease,
        box-shadow 0.2s ease;
}

.profile-save-btn {
    border: none;

    background: var(--primary-color);

    color: #ffffff;

    box-shadow: 0 4px 10px rgba(11, 138, 71, 0.14);
}

.profile-save-btn:hover {
    background: var(--primary-hover);

    transform: translateY(-1px);

    box-shadow: 0 5px 12px rgba(11, 138, 71, 0.18);
}

.profile-password-btn {
    border: 1px solid var(--primary-color);

    background: #ffffff;

    color: var(--primary-color);
}

.profile-password-btn:hover {
    background: #eaf6ed;

    transform: translateY(-1px);
}


/* ============================================================
   RESPONSIVE
   ============================================================ */

@media (max-width: 900px) {

    .profile-content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 600px) {

    .profile-page-header {
        flex-direction: column;

        padding: 20px;
    }

    .profile-role-badge {
        align-self: flex-start;
    }

    .profile-card {
        padding: 20px;
    }

    .profile-page-header h2 {
        font-size: 21px;
    }
}

</style>


<script>

function toggleProfilePassword(inputId, button) {

    const input = document.getElementById(inputId);

    if (!input) {
        return;
    }

    if (input.type === "password") {

        input.type = "text";

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