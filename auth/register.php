<?php
require_once '../config/db_connect.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullname = trim($_POST['fullname']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    // Hardcoded role to ensure nobody can manipulate or spoof an admin registration
    $role = 'farmer'; 

    if (!empty($fullname) && !empty($username) && !empty($email) && !empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        try {
            $stmt = $conn->prepare("INSERT INTO users (fullname, username, email, password, role) VALUES (:fullname, :username, :email, :password, :role)");
            $stmt->bindParam(':fullname', $fullname);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':role', $role);
            
            if ($stmt->execute()) {
                header("Location: login.php?registered=true");
                exit();
            }
        } catch(PDOException $e) {
            $message = "Registration failed: " . $e->getMessage();
        }
    } else {
        $message = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register - Soil Monitoring</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="auth-container">
        <h2>Create Account</h2>
        <?php if(!empty($message)) echo "<p class='error'>$message</p>"; ?>
        <form action="register.php" method="POST">
            <input type="text" name="fullname" placeholder="Full Name" required>
            <input type="text" name="username" placeholder="Username" required>
            <input type="email" name="email" placeholder="Email Address" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Register as Farmer</button>
        </form>
        <p>Already have an account? <a href="login.php">Login here</a></p>
    </div>
</body>
</html>