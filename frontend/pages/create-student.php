<?php
session_start();

require_once __DIR__ . "/../../php/config/database.php";
require_once __DIR__ . "/../php/validation/student-validation.php";

// Allow only administrators.
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
    $rollNumber = trim($_POST["roll_number"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $department = trim($_POST["department"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    // Validate student details.
    $errors = validateStudent(
        $name,
        $rollNumber,
        $email,
        $username,
        $password,
        $confirmPassword
    );

    if (empty($errors)) {
        try {
            $result = callExpressAPI(
                getApiBaseUrl() . "/students",
                [
                    "name" => $name,
                    "roll_number" => $rollNumber,
                    "email" => $email,
                    "username" => $username,
                    "password" => $password,
                    "department" => $department
                ]
            );

            if (
                isset($result["success"]) &&
                $result["success"] === true
            ) {
                $success = "Student account created successfully.";
                $_POST = [];
            } else {
                $errors[] = $result["message"]
                    ?? "Unable to create student account.";
            }
        } catch (Throwable $error) {
            error_log("Create student error: " . $error->getMessage());
            $errors[] = "Unable to process the request. Please try again.";
        }
    }
}

// Safely retain submitted form values.
function studentField(string $field): string
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
    <title>EduTracker | Create Student</title>
    <link rel="stylesheet" href="../css/style.css">
</head>

<body>
    <header class="navbar">
        <div class="brand blue">E EduTracker</div>
        <a href="../../logout.php" class="logout">Logout</a>
    </header>

    <main class="form-container">
        <h1>Create Student Account</h1>

        <p class="subtitle">
            Only the administrator can create student accounts.
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

        <form method="POST" action="">
            <label for="name">Student Name</label>
            <input
                type="text"
                id="name"
                name="name"
                value="<?= studentField("name") ?>"
                autocomplete="name"
                required
            >

            <label for="roll_number">Roll Number</label>
            <input
                type="text"
                id="roll_number"
                name="roll_number"
                value="<?= studentField("roll_number") ?>"
                required
            >

            <label for="email">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                value="<?= studentField("email") ?>"
                autocomplete="email"
                required
            >

            <label for="department">Department</label>
            <input
                type="text"
                id="department"
                name="department"
                value="<?= studentField("department") ?>"
                required
            >

            <label for="username">Username</label>
            <input
                type="text"
                id="username"
                name="username"
                value="<?= studentField("username") ?>"
                minlength="4"
                autocomplete="username"
                required
            >

            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                minlength="8"
                autocomplete="new-password"
                required
            >

            <label for="confirm_password">Confirm Password</label>
            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                minlength="8"
                autocomplete="new-password"
                required
            >

            <button type="submit">Create Student</button>
        </form>

        <a href="admin-dashboard.php" class="back">
            &larr; Back to Dashboard
        </a>
    </main>
<script src="../js/app.js"></script>
</body>
</html>
