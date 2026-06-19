<?php
session_start();
require_once '../config/db_connect.php';

$message = "";

if (isset($_GET['registered'])) {
    $message = "✅ Registration successful! Please login.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input = trim($_POST['email']);
    $password = $_POST['password'];
    $selected_role = trim($_POST['login_role'] ?? 'farmer');

    if (!empty($input) && !empty($password)) {
        try {
            $stmt = $conn->prepare("SELECT id, fullname, username, email, password, role FROM users 
                                    WHERE username = :input OR email = :input LIMIT 1");
            $stmt->bindParam(':input', $input);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                if ($selected_role !== $user['role']) {
                    $message = "❌ Selected role does not match your account.";
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['fullname'] = $user['fullname'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    header("Location: ../dashboard.php");
                    exit;
                }
            } else {
                $message = "❌ Invalid username/email or password.";
            }
        } catch(PDOException $e) {
            $message = "⚠️ System error: " . $e->getMessage();
        }
    } else {
        $message = "⚠️ Please fill in both fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sto Cristo Cooperative</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
    .password-wrapper {
        position: relative;
        width: 100%;
    }
    .toggle-password {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .toggle-password svg {
        width: 18px;
        height: 18px;
        stroke: #888;
        stroke-width: 2;
        fill: none;
    }
    .toggle-password:hover svg {
        stroke: #666;
    }
    </style>
</head>
<body class="auth-body background-gradient-theme">
    <div class="auth-container premium-login-card">
        <div class="auth-logo login-logo-centered">
            <svg width="42" height="42" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2C6.48 2 2 6.48 2 12C2 16.5 4.5 20.2 8.2 21.4C8.1 20.6 8 19.7 8 18.8C8 14.3 11.2 10.5 15.5 9.7C14.4 8.1 12.6 7 10.5 7C7.5 7 5 9.5 5 12.5C5 14.7 6.3 16.6 8.2 17.5C8.1 16.9 8 16.2 8 15.5C8 11.9 10.9 9 14.5 9C15.8 9 17.1 9.4 18.1 10.1C18.9 7.7 18.3 4.9 16.2 3.2C15 2.3 13.5 1.8 12 2ZM14.5 11C12 11 10 13 10 15.5C10 18 12 20 14.5 20C17 20 19 18 19 15.5C19 13 17 11 14.5 11Z" fill="#1b5e20"/>
            </svg>
            <h1 class="brand-title">cooperative</h1>
        </div>

        <?php if(!empty($message)) echo "<p class='".(strpos($message, '✅') !== false ? "success" : "error")."'>$message</p>"; ?>
        
        <form action="login.php" method="POST" class="mockup-form">
            <div class="input-wrapper-login active-focus-border">
                <span class="field-icon">👤</span>
                <input type="text" name="email" placeholder="Username or Email" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>" required>
            </div>

            <div class="input-wrapper-login password-wrapper">
                <span class="field-icon">🔑</span>
                <input type="password" name="password" id="loginPassword" placeholder="Enter your password" required>
                <span class="toggle-password" onclick="togglePassword('loginPassword')">
                    <svg viewBox="0 0 24 24">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                </span>
            </div>

            <div class="role-selection-group grid-two-columns">
                <label class="role-radio-label selector-card">
                    <input type="radio" name="login_role" value="farmer" checked>
                    <span>Farmer</span>
                </label>
                <label class="role-radio-label selector-card">
                    <input type="radio" name="login_role" value="admin">
                    <span>Admin</span>
                </label>
            </div>

            <div class="remember-me-container checkbox-left-align">
                <input type="checkbox" id="remember_me" name="remember_me">
                <label for="remember_me">Remember me</label>
            </div>

            <button type="submit" class="mockup-login-btn">Login</button>
        </form>
        
        <div class="auth-footer-links layout-top-spacing">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>

    <script>
    function togglePassword(fieldId) {
        const field = document.getElementById(fieldId);
        const icon = event.currentTarget.querySelector('svg');
        if (field.type === "password") {
            field.type = "text";
            icon.innerHTML = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>`;
        } else {
            field.type = "password";
            icon.innerHTML = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
        }
    }
    </script>
</body>
</html>