<?php
session_start();

// Unset all session variables
session_unset();

// Destroy the active session completely
session_destroy();

// Redirect straight to login.php since they live in the same auth/ directory
header("Location: login.php");
exit();
?>