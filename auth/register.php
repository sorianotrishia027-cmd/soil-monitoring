<?php
require_once '../config/db_connect.php';
$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = 'farmer';

    if (!empty($fullname) && !empty($username) && !empty($email) && !empty($contact_number) && !empty($password)) {
        if ($password !== $confirm_password) {
            $message = "Passwords do not match.";
        } else {
            $hashed_pw = password_hash($password, PASSWORD_BCRYPT);
            try {
                $stmt = $conn->prepare("
                    INSERT INTO users (fullname, username, email, contact_number, password, role)
                    VALUES (:fullname, :username, :email, :contact_number, :password, :role)
                ");
                $stmt->execute([
                    ':fullname' => $fullname,
                    ':username' => $username,
                    ':email' => $email,
                    ':contact_number' => $contact_number,
                    ':password' => $hashed_pw,
                    ':role' => $role
                ]);
                header("Location: login.php?registered=1");
                exit;
            } catch (PDOException $e) {
                $message = "Username or Email already exists.";
            }
        }
    } else {
        $message = "Please fill in all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - SCC Soil Monitor</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= time() ?>">
    <style>
        body.login-page-body {
            background-color: #edf3ef;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 24px 16px;
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            box-sizing: border-box;
            -webkit-font-smoothing: antialiased;
        }

        .login-card-container {
            display: flex;
            width: 100%;
            max-width: 900px;
            min-height: 540px;
            background: #ffffff;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.06), 0 2px 6px rgba(0, 0, 0, 0.03);
            border: 1px solid #dbe5de;
        }

        .login-brand-panel {
            width: 42%;
            background-color: var(--primary-color, #143d2c);
            padding: 44px 38px;
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
            width: 36px;
            height: 36px;
            color: #86efac;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .login-brand-title {
            font-size: 24px;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -0.3px;
        }

        .login-brand-body {
            margin: 30px 0;
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
            font-size: 14px;
            color: #bbf7d0;
            line-height: 1.55;
            margin-top: 12px;
            font-weight: 500;
        }

        .login-watermark-leaf {
            position: absolute;
            bottom: 40px;
            left: -20px;
            width: 180px;
            height: 180px;
            color: rgba(255, 255, 255, 0.04);
            pointer-events: none;
            z-index: 1;
        }

        .login-brand-footer {
            z-index: 2;
        }

        .login-coop-name {
            font-size: 12px;
            color: #a8cdb9;
            font-weight: 600;
            line-height: 1.35;
        }

        .login-coop-location {
            font-size: 11px;
            color: #8bb29e;
            margin-top: 3px;
        }

        .login-form-panel {
            flex-grow: 1;
            padding: 40px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
        }

        .login-form-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-heading, #0a1c13);
            margin: 0;
        }

        .login-form-subtitle {
            font-size: 14px;
            color: var(--text-muted, #4a6254);
            margin-top: 4px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .login-input-box {
            position: relative;
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1.5px solid #cbdcd0;
            border-radius: 8px;
            padding: 0 14px;
            margin-top: 5px;
            transition: all 0.2s ease;
            min-height: 44px;
        }

        .login-input-box:focus-within {
            border-color: var(--primary-color, #143d2c);
            box-shadow: 0 0 0 3.5px rgba(20, 61, 44, 0.12);
        }

        .login-input-box input {
            width: 100%;
            border: none;
            outline: none;
            background: transparent;
            padding: 10px 0;
            font-size: 14.5px;
            color: #0a1c13;
            font-family: inherit;
        }

        .login-input-box input::placeholder {
            color: #8fa597;
        }

        @media (max-width: 768px) {
            .login-card-container {
                flex-direction: column;
                max-width: 480px;
            }
            .login-brand-panel {
                width: 100%;
                padding: 28px 24px;
            }
            .login-brand-body {
                margin: 16px 0;
            }
            .login-form-panel {
                padding: 28px 20px;
            }
            .login-input-box input {
                font-size: 16px;
            }
        }
    </style>
</head>
<body class="login-page-body">

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
                    Join the Cooperative Network.
                </div>
                <div class="login-brand-subtext">
                    Create your farmer profile to start streaming live telemetry data from assigned field nodes.
                </div>
            </div>

            <div class="login-brand-footer">
                <div class="login-coop-name">Sto Cristo Concepcion Farmers Agriculture Cooperative</div>
                <div class="login-coop-location">Concepcion, Tarlac, Philippines</div>
            </div>
        </div>

        <!-- RIGHT PANEL -->
        <div class="login-form-panel">
            <h1 class="login-form-title">Create Account</h1>
            <p class="login-form-subtitle">Register a new farmer profile.</p>

            <?php if (!empty($message)): ?>
                <div class="alert danger" style="margin-bottom: 14px;">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form action="register.php" method="POST">
                <div class="form-group" style="margin-bottom: 12px;">
                    <div class="login-input-box">
                        <input type="text" name="fullname" placeholder="Full Name (e.g. Juan Dela Cruz)" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 12px;">
                    <div class="login-input-box">
                        <input type="text" name="username" placeholder="Username" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 12px;">
                    <div class="login-input-box">
                        <input type="email" name="email" placeholder="Email Address" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 12px;">
                    <div class="login-input-box">
                        <input type="text" name="contact_number" placeholder="Contact Number (e.g., 09XXXXXXXXX)" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 12px;">
                    <div class="login-input-box">
                        <input type="password" id="reg-pass" name="password" placeholder="Create Password" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 18px;">
                    <div class="login-input-box">
                        <input type="password" id="reg-conf-pass" name="confirm_password" placeholder="Confirm Password" required>
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="padding: 11px; font-size: 14px;">
                    Register Profile
                </button>
            </form>

            <div style="text-align: center; font-size: 12.5px; color: #6b7d73; margin-top: 16px;">
                Already registered? <a href="login.php" style="color: var(--primary-color); font-weight: 700; text-decoration: none;">Sign in here</a>
            </div>
        </div>

    </div>
</body>
</html>