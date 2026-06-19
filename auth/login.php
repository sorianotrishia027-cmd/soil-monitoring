<?php
session_start();
require_once '../config/db_connect.php';

$message = "";

if (isset($_GET['registered'])) {
    $message = "Registration successful! Please login.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (!empty($email) && !empty($password)) {
        try {
            $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email");
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // ------ TEMPORARY LIVE DEBUGGING BOX ------
            echo "<div style='background: #fff; color: #000; padding: 20px; border: 3px solid red; font-family: monospace; position: relative; z-index: 9999;'>";
            echo "<h3>⚙️ Login Debug Diagnostics:</h3>";
            echo "<strong>1. Typed Email:</strong> [" . htmlspecialchars($email) . "]<br>";
            echo "<strong>2. Typed Password:</strong> [" . htmlspecialchars($password) . "]<br>";
            
            if (!$user) {
                echo "<br><span style='color: red; font-weight: bold;'>❌ Error: No matching user record found in the database for this email!</span><br>";
            } else {
                echo "<br><span style='color: green; font-weight: bold;'>✔ User Found in DB!</span><br>";
                echo "<strong>Database Username:</strong> " . htmlspecialchars($user['username'] ?? 'N/A') . "<br>";
                echo "<strong>Database Role:</strong> " . htmlspecialchars($user['role'] ?? 'N/A') . "<br>";
                echo "<strong>Stored Hash Value:</strong> <span style='color: blue;'>" . htmlspecialchars($user['password'] ?? 'EMPTY') . "</span><br>";
                echo "<strong>Hash Length:</strong> " . strlen($user['password'] ?? '') . " characters<br>";
                
                $verifyCheck = password_verify($password, $user['password']);
                echo "<strong>3. PHP Password Verification Result:</strong> " . ($verifyCheck ? "<span style='color: green; font-weight: bold;'>TRUE</span>" : "<span style='color: red; font-weight: bold;'>FALSE</span>") . "<br>";
            }
            echo "</div>";
            // ------------------------------------------

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role']; 
                
                header("Location: ../dashboard.php");
                exit();
            } else {
                $message = "Invalid email or password.";
            }
        } catch(PDOException $e) {
            $message = "Error: " . $e->getMessage();
        }
    } else {
        $message = "Please enter both email and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Soil Monitoring</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="auth-container">
        <h2>Login</h2>
        <?php if(!empty($message)) echo "<p class='info'>$message</p>"; ?>
        <form action="login.php" method="POST">
            <input type="email" name="email" placeholder="Email Address" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
        <p>Don't have an account? <a href="register.php">Register here</a></p>
    </div>
</body>
</html>