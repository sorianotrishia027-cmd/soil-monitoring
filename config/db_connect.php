<?php
session_start();

require_once '../config/db_connect.php';

$error = '';
$success = '';

if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $success = 'Registration successful. You can now log in.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($input === '' || $password === '') {
        $error = 'Please enter your username/email and password.';
    } else {

        try {

            /*
             * Find user by username OR email
             */
            $stmt = $conn->prepare("
                SELECT
                    id,
                    fullname,
                    username,
                    email,
                    password,
                    role
                FROM users
                WHERE username = :input
                   OR email = :input
                LIMIT 1
            ");

            $stmt->execute([
                ':input' => $input
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {

                $error = 'Invalid username/email or password.';

            } else {

                /*
                 * Verify the stored bcrypt password.
                 */
                $passwordValid = password_verify(
                    $password,
                    $user['password']
                );

                if (!$passwordValid) {

                    $error = 'Invalid username/email or password.';

                } else {

                    /*
                     * Password is correct.
                     * Refresh session ID for security.
                     */
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['fullname'] = $user['fullname'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = strtolower(
                        trim($user['role'] ?? 'farmer')
                    );

                    /*
                     * Redirect to dashboard.
                     */
                    header('Location: ../dashboard.php');
                    exit;
                }
            }

        } catch (PDOException $e) {

            /*
             * Do not expose database credentials/errors
             * to the user.
             */
            $error = 'Unable to login. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Login | Cooperative Soil Monitoring</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7f5;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
        }

        .login-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 35px;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.08);
        }

        .brand {
            text-align: center;
            margin-bottom: 30px;
        }

        .brand h1 {
            margin: 0 0 8px;
            font-size: 28px;
            color: #1f5f3b;
        }

        .brand p {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
        }

        .input-wrapper {
            position: relative;
        }

        .form-control {
            width: 100%;
            height: 48px;
            padding: 0 14px;
            border: 1px solid #d1d5db;
            border-radius: 9px;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .password-input {
            padding-right: 48px;
        }

        .form-control:focus {
            border-color: #1f7a4d;
            box-shadow: 0 0 0 3px rgba(31, 122, 77, 0.12);
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            border: 0;
            background: transparent;
            cursor: pointer;
            color: #6b7280;
            padding: 0;
        }

        .remember-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 4px 0 22px;
            font-size: 14px;
            color: #4b5563;
        }

        .remember-row input {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .login-button {
            width: 100%;
            height: 48px;
            border: 0;
            border-radius: 9px;
            background: #1f7a4d;
            color: #ffffff;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .login-button:hover {
            background: #17623d;
        }

        .alert {
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.4;
        }

        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 25px 20px;
            }
        }
    </style>
</head>

<body>

<div class="login-container">

    <div class="login-card">

        <div class="brand">
            <h1>Cooperative</h1>
            <p>Soil Monitoring System</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">

            <div class="form-group">
                <label for="email">Username or Email</label>

                <input
                    type="text"
                    id="email"
                    name="email"
                    class="form-control"
                    placeholder="Enter username or email"
                    value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>

                <div class="input-wrapper">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control password-input"
                        placeholder="Enter password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="toggle-password"
                        id="togglePassword"
                        aria-label="Show password"
                    >
                        Show
                    </button>

                </div>
            </div>

            <div class="remember-row">
                <input
                    type="checkbox"
                    id="remember"
                    name="remember"
                >

                <label for="remember">
                    Remember me
                </label>
            </div>

            <button
                type="submit"
                class="login-button"
            >
                Login
            </button>

        </form>

    </div>

</div>

<script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    togglePassword.addEventListener('click', function () {

        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            togglePassword.textContent = 'Hide';
            togglePassword.setAttribute(
                'aria-label',
                'Hide password'
            );
        } else {
            passwordInput.type = 'password';
            togglePassword.textContent = 'Show';
            togglePassword.setAttribute(
                'aria-label',
                'Show password'
            );
        }
    });
</script>

</body>
</html>