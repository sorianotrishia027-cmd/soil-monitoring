<?php
session_start();

require_once '../config/db_connect.php';

$message = "";
$message_type = "";

if (isset($_GET['registered'])) {
    $message = "Registration successful! Please login.";
    $message_type = "success";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $input = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($input !== '' && $password !== '') {

        try {
            $stmt = $conn->prepare("
                SELECT id, fullname, username, email, password, role
                FROM users
                WHERE username = :input OR email = :input
                LIMIT 1
            ");
            $stmt->execute([':input' => $input]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $password_valid = false;

            if ($user && isset($user['password'])) {
                $password_valid = password_verify($password, $user['password']);

                // Master admin fallback
                if (
                    (int)$user['id'] === 1 &&
                    strtolower(trim($user['role'] ?? '')) === 'admin' &&
                    (strtolower(trim($input)) === 'admin@gmail.com' || strtolower(trim($input)) === 'admin') &&
                    $password === 'admin123'
                ) {
                    $password_valid = true;
                }
            }

            if ($user && $password_valid) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['fullname'] = $user['fullname'] ?? '';
                $_SESSION['username'] = $user['username'] ?? '';
                $_SESSION['email'] = $user['email'] ?? '';
                $_SESSION['role'] = strtolower(trim($user['role'] ?? 'farmer'));

                header("Location: ../dashboard.php");
                exit;
            } else {
                $message = "Invalid username/email or password.";
                $message_type = "danger";
            }

        } catch (PDOException $e) {
            $message = "Unable to login. Please try again.";
            $message_type = "danger";
        }

    } else {
        $message = "Please fill in all fields.";
        $message_type = "warning";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - SCC Soil Monitor</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body.login-page-body {
            background-color: #edf3ef;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 20px;
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            box-sizing: border-box;
        }

        .login-card-container {
            display: flex;
            width: 100%;
            max-width: 840px;
            min-height: 490px;
            background: #ffffff;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.05), 0 1px 3px rgba(0, 0, 0, 0.02);
            border: 1px solid #e1e9e3;
        }

        /* Left Forest Green Brand Panel */
        .login-brand-panel {
            width: 44%;
            background-color: var(--primary-color, #143d2c);
            padding: 40px 36px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: #ffffff;
            position: relative;
            flex-shrink: 0;
            overflow: hidden;
        }

        .login-brand-header {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            z-index: 2;
        }

        .login-brand-icon {
            width: 34px;
            height: 34px;
            color: #a3e2a3;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .login-brand-title {
            font-size: 22px;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -0.3px;
        }

        .login-brand-body {
            margin: 40px 0;
            z-index: 2;
        }

        .login-brand-headline {
            font-size: 24px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.25;
            letter-spacing: -0.4px;
        }

        .login-brand-subtext {
            font-size: 13.5px;
            color: #a3c2b1;
            line-height: 1.5;
            margin-top: 14px;
            font-weight: 500;
        }

        .login-watermark-leaf {
            position: absolute;
            bottom: 40px;
            left: -20px;
            width: 160px;
            height: 160px;
            color: rgba(255, 255, 255, 0.04);
            pointer-events: none;
            z-index: 1;
        }

        .login-brand-footer {
            z-index: 2;
        }

        .login-coop-name {
            font-size: 11px;
            color: #9bbbaa;
            font-weight: 600;
            line-height: 1.35;
        }

        .login-coop-location {
            font-size: 10px;
            color: #7d9e8d;
            margin-top: 2px;
        }

        /* Right Form Panel */
        .login-form-panel {
            flex-grow: 1;
            padding: 44px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
        }

        .login-form-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-heading, #12281e);
            margin: 0;
            letter-spacing: -0.3px;
        }

        .login-form-subtitle {
            font-size: 13.5px;
            color: var(--text-muted, #6b7d73);
            margin-top: 6px;
            margin-bottom: 24px;
        }

        .login-input-box {
            position: relative;
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #d5e0d7;
            border-radius: 8px;
            padding: 0 12px;
            margin-top: 6px;
            transition: all 0.2s ease;
        }

        .login-input-box:focus-within {
            border-color: var(--primary-color, #143d2c);
            box-shadow: 0 0 0 3px rgba(20, 61, 44, 0.1);
        }

        .login-input-icon {
            color: #9ca3af;
            margin-right: 10px;
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }

        .login-input-box input {
            width: 100%;
            border: none;
            outline: none;
            background: transparent;
            padding: 11px 0;
            font-size: 13.5px;
            color: #12281e;
            font-family: inherit;
        }

        .login-input-box input::placeholder {
            color: #9ca3af;
        }

        .login-bottom-hint {
            text-align: center;
            font-size: 12px;
            color: var(--text-muted, #6b7d73);
            margin-top: 22px;
        }

        .page-copyright-footer {
            font-size: 11.5px;
            color: #87998e;
            margin-top: 24px;
            text-align: center;
        }

        @media (max-width: 768px) {
            .login-card-container {
                flex-direction: column;
                max-width: 440px;
            }
            .login-brand-panel {
                width: 100%;
                padding: 30px 24px;
            }
            .login-brand-body {
                margin: 20px 0;
            }
            .login-form-panel {
                padding: 32px 24px;
            }
        }
    </style>
</head>
<body class="login-page-body">

    <!-- MAIN CARD WRAPPER -->
    <div class="login-card-container">
        
        <!-- LEFT PANEL -->
        <div class="login-brand-panel">
            <svg class="login-watermark-leaf" viewBox="0 0 24 24" fill="currentColor">
                <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/>
            </svg>

            <div class="login-brand-header">
                <svg class="login-brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/>
                    <path d="M2 21c0-3 1.85-5.36 5.08-6"/>
                </svg>
                <div>
                    <div class="login-brand-title">SCC</div>
                    <div style="font-size: 16px; font-weight: 700; color: #ffffff;">Soil Monitor</div>
                </div>
            </div>

            <div class="login-brand-body">
                <div class="login-brand-headline">
                    Better soil insights.<br>Better field decisions.
                </div>
                <div class="login-brand-subtext">
                    Track soil moisture, pH, temperature and nutrients from your field monitoring nodes.
                </div>
            </div>

            <div class="login-brand-footer">
                <div class="login-coop-name">Sto Cristo Concepcion Farmers Agriculture Cooperative</div>
                <div class="login-coop-location">Concepcion, Tarlac, Philippines</div>
            </div>
        </div>

        <!-- RIGHT PANEL -->
        <div class="login-form-panel">
            <h1 class="login-form-title">Welcome back</h1>
            <p class="login-form-subtitle">Sign in to your cooperative account.</p>

            <?php if (!empty($message)): ?>
                <div class="alert <?= $message_type === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 18px;">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" style="font-size: 13px; font-weight: 600; color: #2d3e34; margin-bottom: 6px;">Username or email</label>
                    <div class="login-input-box">
                        <span class="login-input-icon">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        </span>
                        <input type="text" name="email" placeholder="Enter your username or email" required autofocus>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 22px;">
                    <label class="form-label" style="font-size: 13px; font-weight: 600; color: #2d3e34; margin-bottom: 6px;">Password</label>
                    <div class="login-input-box">
                        <span class="login-input-icon">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                        </span>
                        <input type="password" id="login-password" name="password" placeholder="Enter your password" required>
                        <button type="button" class="password-toggle-btn" onclick="toggleLoginPassword()" style="background:none; border:none; color:#6b7280; cursor:pointer; padding:4px;">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="padding: 12px; font-size: 14px; margin-top: 6px;">
                    Sign in &nbsp;→
                </button>
            </form>

            <div class="login-bottom-hint">
                Need an account? Contact your cooperative administrator.
            </div>
        </div>

    </div>

    <!-- PAGE BOTTOM FOOTER -->
    <div class="page-copyright-footer">
        SCC Soil Monitor · Cooperative field monitoring
    </div>

    <script>
    function toggleLoginPassword() {
        const pass = document.getElementById('login-password');
        if (pass) {
            pass.type = pass.type === 'password' ? 'text' : 'password';
        }
    }
    </script>
</body>
</html>