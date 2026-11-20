<?php
session_start();

// Clear all session variables.
$_SESSION = [];

// Delete the session cookie if cookies are enabled.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the current session.
session_destroy();

// Redirect to the login page dynamically (works in both XAMPP subfolder and production root).
$baseDir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "")), "/");
$loginUrl = $baseDir . "/frontend/pages/login.php";

header("Location: " . $loginUrl);
exit;
