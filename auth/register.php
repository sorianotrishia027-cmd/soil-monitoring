<?php
require_once '../config/db_connect.php';
$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullname = trim($_POST['fullname']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = 'farmer';

    if (!empty($fullname) && !empty($username) && !empty($email) && !empty($password)) {
        if ($password !== $confirm_password) {
            $message = "❌ Passwords do not match.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            try {
                $stmt = $conn->prepare("INSERT INTO users (fullname, username, email, password, role) 
                                        VALUES (:fullname, :username, :email, :password, :role)");
                $stmt->execute([
                    ':fullname' => $fullname,
                    ':username' => $username,
                    ':email' => $email,
                    ':password' => $hashed_password,
                    ':role' => $role
                ]);
                header("Location: login.php?registered=1");
                exit;
            } catch(PDOException $e) {
                $message = "❌ Username or Email already exists.";
            }
        }
    } else {
        $message = "⚠️ Please fill all required fields.";
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
</head>
<body class="auth-body">
    <div class="mobile-phone-frame">
        <div class="auth-container mobile-nested-container">
            <div class="auth-logo">
                <svg width="45" height="45" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2C6.48 2 2 6.48 2 12C2 16.5 4.5 20.2 8.2 21.4C8.1 20.6 8 19.7 8 18.8C8 14.3 11.2 10.5 15.5 9.7C14.4 8.1 12.6 7 10.5 7C7.5 7 5 9.5 5 12.5C5 14.7 6.3 16.6 8.2 17.5C8.1 16.9 8 16.2 8 15.5C8 11.9 10.9 9 14.5 9C15.8 9 17.1 9.4 18.1 10.1C18.9 7.7 18.3 4.9 16.2 3.2C15 2.3 13.5 1.8 12 2ZM14.5 11C12 11 10 13 10 15.5C10 18 12 20 14.5 20C17 20 19 18 19 15.5C19 13 17 11 14.5 11Z" fill="#1b5e20"/>
                </svg>
                <h1 class="brand-title">cooperative</h1>
            </div>
            <div class="mockup-headline">
                <h2>IOT Soil Monitoring System</h2>
                <p>Register for rice farmers in the Philippines.</p>
            </div>

            <?php if(!empty($message)) echo "<p class='error'>$message</p>"; ?>
            
            <form action="register.php" method="POST" class="mockup-form">
                <div class="input-wrapper">
                    <input type="text" name="fullname" placeholder="Full Name" required>
                </div>
                <div class="input-wrapper">
                    <input type="email" name="email" placeholder="Email" required>
                </div>
                <div class="input-wrapper high-contrast-border">
                    <input type="text" name="username" placeholder="Username" required>
                </div>
                <div class="input-wrapper">
                    <input type="password" name="password" placeholder="Password" required>
                </div>
                <div class="input-wrapper">
                    <input type="password" name="confirm_password" placeholder="Confirm Password" required>
                </div>
                <button type="submit" class="mockup-register-btn">Register</button>
            </form>
            
            <div class="auth-footer-links">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        </div>
    </div>
</body>
</html>