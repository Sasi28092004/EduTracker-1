<?php
session_start();

require_once __DIR__ . "/../../php/config/database.php";
require_once __DIR__ . "/../php/validation/teacher-validation.php";

// Only admins can create teacher accounts.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: login.php");
    exit;
}

$errors = [];
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $employeeId = trim($_POST["employee_id"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $department = trim($_POST["department"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    // Validate the submitted teacher details.
    $errors = validateTeacher(
        $name,
        $employeeId,
        $email,
        $username,
        $password,
        $confirmPassword
    );

    // Send valid data to the Express API.
    if (empty($errors)) {
        $result = callExpressAPI(
            getApiBaseUrl() . "/teachers",
            [
                "name" => $name,
                "employee_id" => $employeeId,
                "email" => $email,
                "department" => $department,
                "username" => $username,
                "password" => $password
            ]
        );

        if (
            isset($result["success"]) &&
            $result["success"] === true
        ) {
            $success = "Teacher account created successfully.";

            // Clear form values after successful creation.
            $_POST = [];
        } else {
            $errors[] = $result["message"]
                ?? "Unable to create teacher account.";
        }
    }
}

function fieldValue(string $field): string
{
    return htmlspecialchars(
        $_POST[$field] ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Teacher | EduTracker</title>

    <link rel="stylesheet" href="../css/style.css">
</head>

<body>

<header class="navbar">
    <div class="brand blue">E EduTracker</div>

    <a href="../../logout.php" class="logout">Logout</a>
</header>

<main class="form-container">

    <h1>Create Teacher Account</h1>

    <p class="subtitle">
        Only the administrator can create teacher accounts.
    </p>

    <?php if (!empty($errors)): ?>
        <div class="error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li>
                        <?= htmlspecialchars(
                            $error,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="success" role="status">
            <?= htmlspecialchars(
                $success,
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="" autocomplete="on">

        <label for="name">Teacher Name</label>
        <input
            type="text"
            id="name"
            name="name"
            value="<?= fieldValue("name") ?>"
            required
            autocomplete="name"
        >

        <label for="employee_id">Employee ID</label>
        <input
            type="text"
            id="employee_id"
            name="employee_id"
            value="<?= fieldValue("employee_id") ?>"
            required
        >

        <label for="email">Email</label>
        <input
            type="email"
            id="email"
            name="email"
            value="<?= fieldValue("email") ?>"
            required
            autocomplete="email"
        >

        <label for="department">Department</label>
        <input
            type="text"
            id="department"
            name="department"
            value="<?= fieldValue("department") ?>"
            required
        >

        <label for="username">Username</label>
        <input
            type="text"
            id="username"
            name="username"
            value="<?= fieldValue("username") ?>"
            minlength="4"
            required
            autocomplete="username"
        >

        <label for="password">Password</label>
        <input
            type="password"
            id="password"
            name="password"
            minlength="8"
            required
            autocomplete="new-password"
        >

        <label for="confirm_password">Confirm Password</label>
        <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            minlength="8"
            required
            autocomplete="new-password"
        >

        <button type="submit">Create Teacher</button>

    </form>

    <a href="admin-dashboard.php" class="back">
        &larr; Back to Dashboard
    </a>

</main>

<script src="../js/app.js"></script>
</body>
</html>
