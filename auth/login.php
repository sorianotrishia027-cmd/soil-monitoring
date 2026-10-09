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
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body.login-full-body {
            margin: 0;
            padding: 0;
            width: 100vw;
            min-height: 100vh;
            background-color: #ffffff;
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .login-full-wrapper {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* =========================================================
           LEFT BRAND PANEL
           ========================================================= */
        .login-brand-panel {
            width: 48%;
            background-color: var(--primary-color, #143d2c);
            padding: 56px 64px;
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
            gap: 14px;
            z-index: 2;
        }

        .login-brand-icon {
            width: 42px;
            height: 42px;
            color: #86efac;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .login-brand-title {
            font-size: 27px;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -0.3px;
        }

        .login-brand-body {
            margin: auto 0;
            z-index: 2;
            max-width: 520px;
            padding: 40px 0;
        }

        .login-brand-headline {
            font-size: 38px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.25;
            letter-spacing: -0.6px;
        }

        .login-brand-subtext {
            font-size: 16.5px;
            color: #bbf7d0;
            line-height: 1.6;
            margin-top: 18px;
            font-weight: 500;
        }

        .login-watermark-leaf {
            position: absolute;
            bottom: 40px;
            left: -40px;
            width: 280px;
            height: 280px;
            color: rgba(255, 255, 255, 0.04);
            pointer-events: none;
            z-index: 1;
        }

        .login-brand-footer {
            z-index: 2;
        }

        .login-coop-name {
            font-size: 13.5px;
            color: #a8cdb9;
            font-weight: 600;
            line-height: 1.4;
        }

        .login-coop-location {
            font-size: 12px;
            color: #8bb29e;
            margin-top: 4px;
        }

        /* =========================================================
           RIGHT FORM PANEL
           ========================================================= */
        .login-form-panel {
            width: 52%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 48px;
            background: #ffffff;
            overflow-y: auto;
            position: relative;
        }

        .login-form-inner {
            width: 100%;
            max-width: 440px;
        }

        .login-form-title {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-heading, #0a1c13);
            margin: 0;
            letter-spacing: -0.5px;
            line-height: 1.2;
        }

        .login-form-subtitle {
            font-size: 15px;
            color: var(--text-muted, #4a6254);
            margin-top: 6px;
            margin-bottom: 28px;
            font-weight: 500;
        }

        .login-input-box {
            position: relative;
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1.5px solid #cbdcd0;
            border-radius: 10px;
            padding: 0 14px;
            margin-top: 6px;
            transition: all 0.2s ease;
            min-height: 48px;
        }

        .login-input-box:focus-within {
            border-color: var(--primary-color, #143d2c);
            box-shadow: 0 0 0 4px rgba(20, 61, 44, 0.12);
        }

        .login-input-icon {
            color: #5f7a6b;
            margin-right: 12px;
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }

        .login-input-box input {
            width: 100%;
            border: none;
            outline: none;
            background: transparent;
            padding: 12px 0;
            font-size: 15px;
            color: #0a1c13;
            font-family: inherit;
        }

        .login-input-box input::placeholder {
            color: #8fa597;
        }

        .login-bottom-hint {
            text-align: center;
            font-size: 13.5px;
            color: var(--text-muted, #4a6254);
            margin-top: 26px;
            font-weight: 500;
            line-height: 1.5;
        }

        .login-page-footer {
            margin-top: 32px;
            font-size: 12px;
            color: var(--text-subtle, #5f7a6b);
            text-align: center;
            font-weight: 500;
        }

        @media (max-width: 900px) {
            .login-full-wrapper {
                flex-direction: column;
                min-height: 100vh;
            }
            .login-brand-panel {
                width: 100%;
                padding: 36px 24px;
            }
            .login-brand-body {
                margin: 20px 0;
                padding: 10px 0;
            }
            .login-brand-headline {
                font-size: 26px;
            }
            .login-brand-subtext {
                font-size: 14.5px;
                margin-top: 10px;
            }
            .login-form-panel {
                width: 100%;
                padding: 36px 20px 48px;
            }
            .login-form-title {
                font-size: 26px;
            }
            .login-input-box input {
                font-size: 16px; /* Prevents auto-zoom on iOS */
            }
        }
    </style>
</head>
<body class="login-full-body">

    <div class="login-full-wrapper">
        
        <!-- LEFT BRAND PANEL -->
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
                    <div style="font-size: 18px; font-weight: 700; color: #ffffff;">Soil Monitor</div>
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

        <!-- RIGHT FORM PANEL -->
        <div class="login-form-panel">
            <div class="login-form-inner">
                <h1 class="login-form-title">Welcome back</h1>
                <p class="login-form-subtitle">Sign in to your cooperative account.</p>

                <?php if (!empty($message)): ?>
                    <div class="alert <?= $message_type === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 20px;">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST">
                    
                    <div class="form-group" style="margin-bottom: 18px;">
                        <label class="form-label" style="font-size: 13px; font-weight: 600; color: #2d3e34; margin-bottom: 6px;">Username or email</label>
                        <div class="login-input-box">
                            <span class="login-input-icon">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </span>
                            <input type="text" name="email" placeholder="Enter your username or email" required autofocus>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 24px;">
                        <label class="form-label" style="font-size: 13px; font-weight: 600; color: #2d3e34; margin-bottom: 6px;">Password</label>
                        <div class="login-input-box">
                            <span class="login-input-icon">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </span>
                            <input type="password" id="login-password" name="password" placeholder="Enter your password" required>
                            <button type="button" class="password-toggle-btn" onclick="toggleLoginPassword()" style="background:none; border:none; color:#6b7280; cursor:pointer; padding:4px;">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary" style="padding: 13px; font-size: 15px; border-radius: 10px; margin-top: 6px;">
                        Sign in &nbsp;→
                    </button>
                </form>

                <div class="login-bottom-hint">
                    Need an account? Contact your cooperative administrator.
                </div>
            </div>

            <div class="login-page-footer">
                SCC Soil Monitor · Cooperative field monitoring
            </div>
        </div>

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