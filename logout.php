<?php
require_once 'config/database.php';

// Log logout activity if user is logged in
if (isLoggedIn()) {
    logActivity($_SESSION['user_id'], 'LOGOUT', 'User logged out');
}

// Destroy session
session_unset();
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
?>