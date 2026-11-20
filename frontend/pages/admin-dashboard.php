<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: login.php");
    exit;
}

$adminName = htmlspecialchars(
    $_SESSION["name"] ?? "Admin",
    ENT_QUOTES,
    "UTF-8"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduTracker | Admin Dashboard</title>
    <link rel="stylesheet" href="../css/style.css">

    <style>
        .management-section { margin-top: 30px; }
        .management-section > h2 { margin-bottom: 16px; }
        .management-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }
        .management-card {
            background: #fff;
            padding: 24px;
            border-radius: 14px;
            box-shadow: 0 8px 25px rgba(20, 35, 80, .07);
        }
        .management-card h3 { margin: 0 0 10px; }
        .management-card p { line-height: 1.6; }
        .management-card .button {
            display: inline-block;
            margin-top: 8px;
        }
        @media (max-width: 700px) {
            .management-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<header class="navbar">
    <div class="brand blue">E EduTracker</div>
    <a href="../../logout.php" class="logout">Logout</a>
</header>

<main class="dashboard">
    <h1>Admin Dashboard</h1>
    <p class="welcome">Welcome, <?= $adminName ?></p>

    <div class="cards">
        <div class="card">
            <h2>🎓 Students</h2>
            <p>Create student accounts and manage student details.</p>
            <a href="create-student.php" class="button">Create Student</a>
        </div>

        <div class="card">
            <h2>👨‍🏫 Teachers</h2>
            <p>Create teacher accounts and manage teacher details.</p>
            <a href="create-teacher.php" class="button">Create Teacher</a>
        </div>
    </div>

    <section class="management-section">
        <h2>Academic Management</h2>

        <div class="management-grid">

            <article class="management-card">
                <h3>🏢 Department Management</h3>
                <p>Create and manage departments through the API-connected page.</p>
                <a href="manage-departments.php" class="button">
                    Manage Departments
                </a>
            </article>

            <article class="management-card">
                <h3>📚 Section Management</h3>
                <p>Create classes, select departments, set subjects and capacity.</p>
                <a href="manage-sections.php" class="button">
                    Manage Sections
                </a>
            </article>

            <article class="management-card">
                <h3>👨‍🏫 Teacher–Section Assignment</h3>
                <p>Assign Ram to FS and Jaya to CORE.</p>
                <a href="assign-teachers.php" class="button">
                    Assign Teachers
                </a>
            </article>

            <article class="management-card">
                <h3>🎓 Student Enrollment</h3>
                <p>Enroll students in the correct classes and check enrollment capacity.</p>
                <a href="enroll-students.php" class="button">
                    Enroll Students
                </a>
            </article>

        </div>
    </section>
</main>

</body>
</html>
