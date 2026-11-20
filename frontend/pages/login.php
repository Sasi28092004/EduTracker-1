<?php
session_start();

require_once __DIR__ . "/../../php/config/database.php";
require_once __DIR__ . "/../php/validation/login-validation.php";

// Role -> dashboard page (all in this folder)
$dashboards = [
    "admin"   => "admin-dashboard.php",
    "student" => "student-dashboard.php",
    "teacher" => "teacher-dashboard.php"
];

// Already logged in? Go straight to the dashboard.
if (
    isset($_SESSION["user_id"], $_SESSION["role"]) &&
    isset($dashboards[$_SESSION["role"]])
) {
    header("Location: " . $dashboards[$_SESSION["role"]]);
    exit;
}

$errors = [];
$username = "";
$role = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $role = $_POST["role"] ?? "";
    $remember = !empty($_POST["remember"]);

    $errors = validateLogin($username, $password, $role);

    if (empty($errors)) {
        // PHP -> Express (server-to-server)
        $result = callExpressAPI(
            "/auth/login",
            [
                "username" => $username,
                "password" => $password,
                "role" => $role
            ],
            "POST"
        );

        $user = $result["user"] ?? null;

        if (
            !empty($result["success"]) &&
            is_array($user) &&
            ($user["role"] ?? "") === $role &&
            isset($dashboards[$role])
        ) {
            session_regenerate_id(true);

            $_SESSION["user_id"] = (int) $user["id"];
            $_SESSION["name"] = $user["name"] ?? "";
            $_SESSION["username"] = $user["username"] ?? "";
            $_SESSION["email"] = $user["email"] ?? "";
            $_SESSION["role"] = $user["role"];

            // "Remember me": keep the session cookie for 7 days.
            if ($remember) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    session_id(),
                    [
                        "expires" => time() + 7 * 24 * 60 * 60,
                        "path" => $params["path"],
                        "domain" => $params["domain"],
                        "secure" => $params["secure"],
                        "httponly" => true,
                        "samesite" => "Lax"
                    ]
                );
            }

            header("Location: " . $dashboards[$role]);
            exit;
        }

        $errors[] = $result["message"] ?? "Invalid username, password or role.";
    }
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>EduTracker | Login</title>

    <link rel="stylesheet" href="../css/style.css">
</head>

<body>

<div class="login-container">

    <div class="login-left">

        <div class="brand">E EduTracker</div>

        <h1>Welcome<br>Back.</h1>

        <p>
            Manage academic tasks, deadlines
            and student progress from one
            simple platform.
        </p>

        <div class="features">
            <div>✓ Smart task tracking</div>
            <div>✓ Deadline priority alerts</div>
            <div>✓ Teacher verification</div>
            <div>✓ Student progress monitoring</div>
        </div>

    </div>

    <div class="login-right">

        <h2>Sign in</h2>

        <p class="subtitle">
            Access your EduTracker workspace
        </p>

        <div
            id="errorMessage"
            class="error"
            role="alert"
            style="<?= empty($errors) ? "display:none;" : "" ?>"
        ><?= e(implode(" ", $errors)) ?></div>

        <form id="loginForm" method="POST" action="login.php" novalidate>

            <label>Login as</label>

            <div class="roles">

                <label class="role">
                    <input
                        type="radio"
                        name="role"
                        value="student"
                        required
                        <?= $role === "student" ? "checked" : "" ?>
                    >
                    Student
                </label>

                <label class="role">
                    <input
                        type="radio"
                        name="role"
                        value="teacher"
                        <?= $role === "teacher" ? "checked" : "" ?>
                    >
                    Teacher
                </label>

                <label class="role">
                    <input
                        type="radio"
                        name="role"
                        value="admin"
                        <?= $role === "admin" ? "checked" : "" ?>
                    >
                    Admin
                </label>

            </div>

            <label for="username">Username</label>

            <input
                type="text"
                id="username"
                name="username"
                placeholder="Enter your username"
                autocomplete="username"
                maxlength="100"
                value="<?= e($username) ?>"
                required
            >

            <label for="password">Password</label>

            <input
                type="password"
                id="password"
                name="password"
                placeholder="Enter your password"
                autocomplete="current-password"
                required
            >

            <div class="remember">

                <label>
                    <input
                        type="checkbox"
                        id="remember"
                        name="remember"
                        value="1"
                    >
                    Remember me
                </label>

                <a href="#" id="forgotPassword">
                    Forgot password?
                </a>

            </div>

            <button type="submit" id="loginButton">
                Sign In →
            </button>

        </form>

        <div class="account-info">
            Accounts are created by the EduTracker Admin.
        </div>

        <p style="margin-top:20px;text-align:center;">
            <a href="../index.html">← Back to Home</a>
        </p>

    </div>

</div>

<script>
// Small client-side checks only. The real login happens in PHP above.
const loginForm = document.getElementById("loginForm");
const loginButton = document.getElementById("loginButton");
const errorMessage = document.getElementById("errorMessage");

function showError(message) {
    errorMessage.textContent = message;
    errorMessage.style.display = "block";
}

loginForm.addEventListener("submit", function (event) {
    const selectedRole = document.querySelector('input[name="role"]:checked');
    const username = document.getElementById("username").value.trim();
    const password = document.getElementById("password").value;

    if (!selectedRole) {
        event.preventDefault();
        showError("Please select your login role.");
        return;
    }

    if (!username || !password) {
        event.preventDefault();
        showError("Username and password are required.");
        return;
    }

    loginButton.disabled = true;
    loginButton.textContent = "Signing in...";
});

document.getElementById("forgotPassword").addEventListener("click", function (event) {
    event.preventDefault();
    showError("Please contact your EduTracker administrator to reset your password.");
});
</script>

</body>
</html>
