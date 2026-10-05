<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "../config/db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$role = strtolower(trim($_SESSION['role'] ?? ''));

if ($role !== 'admin') {
    http_response_code(403);
    exit("Access denied.");
}

$message = "";
$message_type = "";

function cleanInput($value) {
    return trim($value ?? '');
}

function normalizeContact($number) {
    $number = preg_replace('/[\s\-\(\)]/', '', trim($number));

    if ($number === '') {
        return '';
    }

    if (strpos($number, '+63') === 0) {
        return $number;
    }

    if (strpos($number, '63') === 0 && strlen($number) === 12) {
        return '+' . $number;
    }

    if (strpos($number, '09') === 0 && strlen($number) === 11) {
        return '+63' . substr($number, 1);
    }

    return $number;
}

/*
|--------------------------------------------------------------------------
| GET CURRENT ADMIN INFORMATION
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT id, username, email, fullname, role, contact_number
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| UPDATE PROFILE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {

        $username = cleanInput($_POST['username'] ?? '');
        $email = cleanInput($_POST['email'] ?? '');
        $fullname = cleanInput($_POST['fullname'] ?? '');
        $contact_number = normalizeContact($_POST['contact_number'] ?? '');

        if ($username === '' || $email === '' || $fullname === '') {

            $message = "Username, email, and full name are required.";
            $message_type = "error";

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $message = "Please enter a valid email address.";
            $message_type = "error";

        } else {

            /*
            |--------------------------------------------------------------------------
            | CHECK DUPLICATE USERNAME
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                AND id != ?
                LIMIT 1
            ");

            $stmt->execute([$username, $user_id]);

            if ($stmt->fetch()) {

                $message = "The username is already being used by another account.";
                $message_type = "error";

            } else {

                /*
                |--------------------------------------------------------------------------
                | CHECK DUPLICATE EMAIL
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE LOWER(email) = LOWER(?)
                    AND id != ?
                    LIMIT 1
                ");

                $stmt->execute([$email, $user_id]);

                if ($stmt->fetch()) {

                    $message = "The email address is already being used by another account.";
                    $message_type = "error";

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK DUPLICATE CONTACT NUMBER
                    |--------------------------------------------------------------------------
                    */

                    if ($contact_number !== '') {

                        $stmt = $conn->prepare("
                            SELECT id
                            FROM users
                            WHERE contact_number = ?
                            AND id != ?
                            LIMIT 1
                        ");

                        $stmt->execute([$contact_number, $user_id]);

                        if ($stmt->fetch()) {

                            $message = "The contact number is already being used by another account.";
                            $message_type = "error";

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
                                $contact_number,
                                $user_id
                            ]);

                            $message = "Your profile has been updated successfully.";
                            $message_type = "success";

                            $admin['username'] = $username;
                            $admin['email'] = $email;
                            $admin['fullname'] = $fullname;
                            $admin['contact_number'] = $contact_number;

                            $_SESSION['username'] = $username;
                            $_SESSION['email'] = $email;
                            $_SESSION['fullname'] = $fullname;
                        }

                    } else {

                        $stmt = $conn->prepare("
                            UPDATE users
                            SET username = ?,
                                email = ?,
                                fullname = ?,
                                contact_number = NULL
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $username,
                            $email,
                            $fullname,
                            $user_id
                        ]);

                        $message = "Your profile has been updated successfully.";
                        $message_type = "success";

                        $admin['username'] = $username;
                        $admin['email'] = $email;
                        $admin['fullname'] = $fullname;
                        $admin['contact_number'] = '';

                        $_SESSION['username'] = $username;
                        $_SESSION['email'] = $email;
                        $_SESSION['fullname'] = $fullname;
                    }
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CHANGE PASSWORD
    |--------------------------------------------------------------------------
    */

    if ($action === 'change_password') {

        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($current_password === '' || $new_password === '' || $confirm_password === '') {

            $message = "All password fields are required.";
            $message_type = "error";

        } elseif (strlen($new_password) < 8) {

            $message = "The new password must contain at least 8 characters.";
            $message_type = "error";

        } elseif ($new_password !== $confirm_password) {

            $message = "The new passwords do not match.";
            $message_type = "error";

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

                $message = "Account could not be found.";
                $message_type = "error";

            } elseif (!password_verify($current_password, $account['password'])) {

                $message = "The current password is incorrect.";
                $message_type = "error";

            } else {

                $new_password_hash = password_hash(
                    $new_password,
                    PASSWORD_BCRYPT
                );

                $stmt = $conn->prepare("
                    UPDATE users
                    SET password = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $new_password_hash,
                    $user_id
                ]);

                $message = "Your password has been changed successfully.";
                $message_type = "success";
            }
        }
    }
}

?>

<div class="admin-profile-page">

<div class="profile-header">
    <div>
        <h2>Admin Profile</h2>
        <p>Manage your administrator account information and password.</p>
    </div>
</div>

<?php if ($message !== ""): ?>

    <div class="profile-message <?php echo htmlspecialchars($message_type); ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>

<?php endif; ?>


<div class="profile-grid">

    <!-- PROFILE INFORMATION -->

    <div class="profile-card">

        <div class="card-header">
            <h3>Profile Information</h3>
            <p>Update your administrator account information.</p>
        </div>

        <form method="POST" autocomplete="off">

            <input type="hidden" name="action" value="update_profile">

            <div class="form-group">

                <label for="fullname">Full Name</label>

                <input
                    type="text"
                    id="fullname"
                    name="fullname"
                    value="<?php echo htmlspecialchars($admin['fullname'] ?? ''); ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label for="username">Username</label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?php echo htmlspecialchars($admin['username'] ?? ''); ?>"
                    required
                >

                <small>
                    Username must be unique.
                </small>

            </div>


            <div class="form-group">

                <label for="email">Email Address</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>"
                    required
                >

                <small>
                    Email address must be unique.
                </small>

            </div>


            <div class="form-group">

                <label for="contact_number">Contact Number</label>

                <input
                    type="text"
                    id="contact_number"
                    name="contact_number"
                    value="<?php echo htmlspecialchars($admin['contact_number'] ?? ''); ?>"
                    placeholder="09XXXXXXXXX"
                >

                <small>
                    Contact number must be unique.
                </small>

            </div>


            <div class="form-group">

                <label>Account Role</label>

                <input
                    type="text"
                    value="Administrator"
                    disabled
                >

            </div>


            <button type="submit" class="save-btn">
                Save Profile Changes
            </button>

        </form>

    </div>


    <!-- PASSWORD -->

    <div class="profile-card">

        <div class="card-header">
            <h3>Change Password</h3>
            <p>Update your administrator account password.</p>
        </div>

        <form method="POST" autocomplete="off">

            <input type="hidden" name="action" value="change_password">

            <div class="form-group">

                <label for="current_password">Current Password</label>

                <div class="password-wrapper">

                    <input
                        type="password"
                        id="current_password"
                        name="current_password"
                        required
                        autocomplete="current-password"
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        onclick="togglePassword('current_password', this)"
                        aria-label="Show password"
                    >
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>

                </div>

            </div>


            <div class="form-group">

                <label for="new_password">New Password</label>

                <div class="password-wrapper">

                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        onclick="togglePassword('new_password', this)"
                        aria-label="Show password"
                    >
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>

                </div>

                <small>
                    Password must contain at least 8 characters.
                </small>

            </div>


            <div class="form-group">

                <label for="confirm_password">Confirm New Password</label>

                <div class="password-wrapper">

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        onclick="togglePassword('confirm_password', this)"
                        aria-label="Show password"
                    >
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>

                </div>

            </div>


            <button type="submit" class="password-btn">
                Change Password
            </button>

        </form>

    </div>

</div>

</div>


<style>

.admin-profile-page {
    width: 100%;
    padding: 10px;
    box-sizing: border-box;
}

.profile-header {
    margin-bottom: 24px;
}

.profile-header h2 {
    margin: 0 0 6px;
    font-size: 28px;
    font-weight: 700;
}

.profile-header p {
    margin: 0;
    color: #6b7280;
    font-size: 14px;
}

.profile-message {
    width: 100%;
    padding: 13px 16px;
    margin-bottom: 20px;
    border-radius: 8px;
    box-sizing: border-box;
    font-size: 14px;
}

.profile-message.success {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #047857;
}

.profile-message.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
}

.profile-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px;
}

.profile-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 24px;
    box-sizing: border-box;
}

.card-header {
    margin-bottom: 22px;
}

.card-header h3 {
    margin: 0 0 6px;
    font-size: 20px;
    font-weight: 700;
}

.card-header p {
    margin: 0;
    color: #6b7280;
    font-size: 13px;
}

.form-group {
    margin-bottom: 18px;
}

.form-group label {
    display: block;
    margin-bottom: 7px;
    font-size: 14px;
    font-weight: 600;
}

.form-group input {
    width: 100%;
    height: 44px;
    padding: 0 13px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    background: #ffffff;
    color: #111827;
    font-size: 14px;
    box-sizing: border-box;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-group input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
}

.form-group input:disabled {
    background: #f3f4f6;
    color: #6b7280;
    cursor: not-allowed;
}

.form-group small {
    display: block;
    margin-top: 6px;
    color: #6b7280;
    font-size: 12px;
}

.password-wrapper {
    position: relative;
    width: 100%;
}

.password-wrapper input {
    padding-right: 48px;
}

.password-toggle {
    position: absolute;
    top: 50%;
    right: 10px;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    border: none;
    background: transparent;
    color: #6b7280;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    padding: 0;
}

.password-toggle:hover {
    color: #111827;
}

.save-btn,
.password-btn {
    width: 100%;
    height: 44px;
    border: none;
    border-radius: 7px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.2s;
}

.save-btn {
    background: #2563eb;
    color: #ffffff;
}

.password-btn {
    background: #111827;
    color: #ffffff;
}

.save-btn:hover,
.password-btn:hover {
    opacity: 0.9;
}

@media (max-width: 850px) {

    .profile-grid {
        grid-template-columns: 1fr;
    }

}

@media (max-width: 500px) {

    .admin-profile-page {
        padding: 5px;
    }

    .profile-card {
        padding: 18px;
    }

    .profile-header h2 {
        font-size: 24px;
    }

}

</style>


<script>

function togglePassword(inputId, button) {

    const input = document.getElementById(inputId);

    if (!input) {
        return;
    }

    if (input.type === "password") {

        input.type = "text";
        button.setAttribute("aria-label", "Hide password");

    } else {

        input.type = "password";
        button.setAttribute("aria-label", "Show password");

    }
}

</script>