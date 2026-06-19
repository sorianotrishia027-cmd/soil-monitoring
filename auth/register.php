<?php
require_once '../config/db_connect.php';
$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = 'farmer'; // Fixed: only farmer accounts

    if (!empty($fullname) && !empty($username) && !empty($email) && !empty($password)) {
        if ($password !== $confirm_password) {
            $message = "❌ Passwords do not match.";
        } else {
            $hashed_pw = password_hash($password, PASSWORD_BCRYPT);
            try {
                $stmt = $conn->prepare("INSERT INTO users (fullname, username, email, password, role)
                                        VALUES (:fullname, :username, :email, :password, :role)");
                $stmt->execute([
                    ':fullname' => $fullname,
                    ':username' => $username,
                    ':email' => $email,
                    ':password' => $hashed_pw,
                    ':role' => $role
                ]);
                header("Location: login.php?registered=1");
                exit;
            } catch (PDOException $e) {
                $message = "❌ Username or Email already exists.";
            }
        }
    } else {
        $message = "⚠️ Fill all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Sto Cristo Cooperative</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
    .password-wrapper { position: relative; width: 100%; }
    .toggle-password {
        position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
        cursor: pointer; display: flex; align-items: center;
    }
    .toggle-password svg { width: 18px; height: 18px; stroke: #888; stroke-width: 2; fill: none; }
    </style>
</head>
<body class="auth-body">
    <div class="auth-container">
        <div class="auth-logo">
            <svg width="45" height="45" viewBox="0 0 24 24" fill="none">
                <path d="M12 2C6.48 2 2 6.48 2 12C2 16.5 4.5 20.2 8.2 21.4C8.1 20.6 8 19.7 8 18.8C8 14.3 11.2 10.5 15.5 9.7C14.4 8.1 12.6 7 10.5 7C7.5 7 5 9.5 5 12.5C5 14.7 6.3 16.6 8.2 17.5C8.1 16.9 8 16.2 8 15.5C8 11.9 10.9 9 14.5 9C15.8 9 17.1 9.4 18.1 10.1C18.9 7.7 18.3 4.9 16.2 3.2C15 2.3 13.5 1.8 12 2Z" fill="#1b5e20"/>
            </svg>
            <h1 class="brand-title">cooperative</h1>
        </div>

        <?php if (!empty($message)) echo "<p class='error'>$message</p>"; ?>

        <form action="register.php" method="POST" class="mockup-form">
            <div class="input-wrapper">
                <input type="text" name="fullname" placeholder="Full Name" required>
            </div>
            <div class="input-wrapper">
                <input type="email" name="email" placeholder="Email" required>
            </div>
            <div class="input-wrapper">
                <input type="text" name="username" placeholder="Username" required>
            </div>

            <div class="input-wrapper password-wrapper">
                <input type="password" name="password" id="regPass" placeholder="Password" required>
                <span class="toggle-password" onclick="togglePassword('regPass')">
                    <svg viewBox="0 0 24 24">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                </span>
            </div>

            <div class="input-wrapper password-wrapper">
                <input type="password" name="confirm_password" id="regConfPass" placeholder="Confirm Password" required>
                <span class="toggle-password" onclick="togglePassword('regConfPass')">
                    <svg viewBox="0 0 24 24">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                </span>
            </div>

            <button type="submit" class="mockup-register-btn">Register as Farmer</button>
        </form>

        <div class="auth-footer-links">
            Already have an account? <a href="login.php">Login here</a>
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