<?php
session_start();

require_once __DIR__ . "/../../php/config/database.php";

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "teacher"
) {
    header("Location: login.php");
    exit;
}

$teacherId = (int) $_SESSION["user_id"];
$teacherName = $_SESSION["name"] ?? "Teacher";

$apiBase = getApiBaseUrl() . "/teachers";

$errors = [];
$success = "";
$classes = [];
$assignmentsByClass = [];
$progressByAssignment = [];

/* ---------------- API HELPER ---------------- */

function teacherApiRequest(
    string $url,
    string $method = "GET",
    array $data = []
): array {
    if (!function_exists("curl_init")) {
        return [
            "success" => false,
            "message" => "PHP cURL is not enabled."
        ];
    }

    $apiKey = getEduTrackerApiKey();

    if ($apiKey === "") {
        return [
            "success" => false,
            "message" => "API authentication is not configured."
        ];
    }

    $curl = curl_init($url);

    if ($curl === false) {
        return [
            "success" => false,
            "message" => "Unable to initialize the API request."
        ];
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Accept: application/json",
            "x-api-key: " . $apiKey
        ]
    ];

    if (in_array(strtoupper($method), ["POST", "PATCH"], true)) {
        $json = json_encode($data);

        if ($json === false) {
            curl_close($curl);
            return [
                "success" => false,
                "message" => "Unable to encode request data."
            ];
        }

        $options[CURLOPT_POSTFIELDS] = $json;
    }

    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($response === false) {
        error_log("Teacher dashboard API error: " . curl_error($curl));
        curl_close($curl);

        return [
            "success" => false,
            "message" => "Cannot connect to the Express API."
        ];
    }

    curl_close($curl);

    $result = json_decode($response, true);

    if (!is_array($result)) {
        return [
            "success" => false,
            "message" => "Invalid response from the API."
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            "success" => false,
            "message" => $result["message"] ?? "API request failed."
        ];
    }

    return $result;
}

/* ---------------- HELPERS ---------------- */

function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function deadlineInfo(array $assignment): array {
    $due = strtotime($assignment["due_date"] ?? "");

    if (!$due) {
        return ["neutral", "Deadline unavailable"];
    }

    $seconds = $due - time();

    if ($seconds < 0) {
        return ["red", "Overdue"];
    }

    if ($seconds <= 172800) {
        return ["orange", "Due within 2 days"];
    }

    if ($seconds <= 604800) {
        return ["yellow", "Due within 7 days"];
    }

    return ["neutral", "Upcoming"];
}
/* ---------------- LOAD ASSIGNED CLASSES ---------------- */

$classResult = teacherApiRequest(
    $apiBase . "/classes?teacher_id=" . $teacherId
);

if (!empty($classResult["success"])) {
    $classes = $classResult["classes"] ?? [];
} else {
    $errors[] = $classResult["message"]
        ?? "Unable to load assigned classes.";
}

/* ---------------- FORM HANDLING ---------------- */

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    /* GRADE A DIGITAL ASSIGNMENT */
if ($action === "grade_assignment") {
    $assignmentId = (int) ($_POST["assignment_id"] ?? 0);
    $studentId = (int) ($_POST["student_id"] ?? 0);

    $marks = trim($_POST["marks_obtained"] ?? "");
    $maxMarks = trim($_POST["max_marks"] ?? "100");
    $feedback = trim($_POST["teacher_feedback"] ?? "");

    if (
        $assignmentId <= 0 ||
        $studentId <= 0 ||
        $marks === "" ||
        !is_numeric($marks) ||
        $maxMarks === "" ||
        !is_numeric($maxMarks) ||
        (float) $maxMarks <= 0 ||
        (float) $marks < 0 ||
        (float) $marks > (float) $maxMarks
    ) {
        $errors[] = "Enter valid marks within the maximum marks.";
    } else {
        $result = teacherApiRequest(
            $apiBase . "/assignments/" . $assignmentId .
            "/students/" . $studentId . "/grade",
            "PATCH",
            [
                "teacher_id" => $teacherId,
                "marks_obtained" => (float) $marks,
                "max_marks" => (float) $maxMarks,
                "teacher_feedback" => $feedback
            ]
        );

        if (!empty($result["success"])) {
            header("Location: teacher-dashboard.php?graded=1");
            exit;
        }

        $errors[] = $result["message"]
            ?? "Unable to save assignment evaluation.";
    }
}

    /* CREATE ASSIGNMENT */
    if ($action === "create_assignment") {
        $classId = (int) ($_POST["class_id"] ?? 0);
        $title = trim($_POST["title"] ?? "");
        $description = trim($_POST["description"] ?? "");
        $dueDate = trim($_POST["due_date"] ?? "");

        if ($classId <= 0 || $title === "" || $dueDate === "") {
            $errors[] = "Select a class, enter a title and choose a deadline.";
        } elseif (strlen($title) > 200) {
            $errors[] = "Assignment title must be 200 characters or fewer.";
        } else {
            $date = DateTime::createFromFormat("!Y-m-d\TH:i", $dueDate);

            if (
                !$date ||
                $date->format("Y-m-d\TH:i") !== $dueDate
            ) {
                $errors[] = "Enter a valid assignment deadline.";
            } else {
                // Only allow classes returned by this teacher's class list.
                $ownsClass = false;

                foreach ($classes as $class) {
                    if ((int) $class["id"] === $classId) {
                        $ownsClass = true;
                        break;
                    }
                }

                if (!$ownsClass) {
                    $errors[] = "You cannot create assignments for this class.";
                } elseif ($date->getTimestamp() <= time()) {
                    $errors[] = "The deadline must be in the future.";
                } else {
                    $result = teacherApiRequest(
                        $apiBase . "/assignments",
                        "POST",
                        [
                            "teacher_id" => $teacherId,
                            "class_id" => $classId,
                            "title" => $title,
                            "description" => $description,
                            "due_date" => $date->format("Y-m-d H:i:s")
                        ]
                    );

                    if (!empty($result["success"])) {
                        header("Location: teacher-dashboard.php?created=1");
                        exit;
                    }

                    $errors[] = $result["message"]
                        ?? "Could not create the assignment.";
                }
            }
        }
    }

    /* RETRAIN THE AI RISK MODEL */
    if ($action === "retrain_ai") {
        $result = teacherApiRequest(getApiBaseUrl() . "/ai/train", "POST", []);

        if (!empty($result["success"])) {
            header("Location: teacher-dashboard.php?retrained=1#ai-insights");
            exit;
        }

        $errors[] = $result["message"] ?? "Could not retrain the AI model.";
    }

    /* TEACHER CONFIRMS PHYSICAL COMPLETION */
    if ($action === "verify_submission") {
        $assignmentId = (int) ($_POST["assignment_id"] ?? 0);
        $studentId = (int) ($_POST["student_id"] ?? 0);
        $confirmed = ($_POST["confirm_completion"] ?? "") === "1";

        if ($assignmentId <= 0 || $studentId <= 0) {
            $errors[] = "Invalid assignment or student.";
        } elseif (!$confirmed) {
            $errors[] = "Tick the checkbox to confirm completion.";
        } else {
            $result = teacherApiRequest(
                $apiBase . "/assignments/" . $assignmentId .
                "/students/" . $studentId . "/status",
                "PATCH",
                [
                    "teacher_id" => $teacherId,
                    "status" => "verified"
                ]
            );

            if (!empty($result["success"])) {
                header("Location: teacher-dashboard.php?verified=1");
                exit;
            }

            $errors[] = $result["message"]
                ?? "Could not save the completion status.";
        }
    }
}

if (isset($_GET["created"])) {
    $success = "Assignment created successfully.";
}

if (isset($_GET["verified"])) {
    $success = "Student completion status saved successfully.";
}

if (isset($_GET["retrained"])) {
    $success = "AI risk model retrained on the latest submission data.";
}

if (isset($_GET["graded"])) {
    $success = "Assignment evaluated successfully.";
}

/* ---------------- LOAD ASSIGNED CLASSES ---------------- */

$classResult = teacherApiRequest(
    $apiBase . "/classes?teacher_id=" . $teacherId
);

if (!empty($classResult["success"])) {
    $classes = $classResult["classes"] ?? [];
} else {
    $errors[] = $classResult["message"] ?? "Unable to load assigned classes.";
}

/* ---------------- LOAD ASSIGNMENTS ---------------- */

foreach ($classes as $class) {
    $classId = (int) $class["id"];

    $result = teacherApiRequest(
        $apiBase . "/classes/" . $classId .
        "/assignments?teacher_id=" . $teacherId
    );

    $assignmentsByClass[$classId] = !empty($result["success"])
        ? ($result["assignments"] ?? [])
        : [];

    if (empty($result["success"])) {
        $errors[] = $result["message"] ?? "Unable to load class assignments.";
    }
}

/* ---------------- LOAD STUDENT PROGRESS ---------------- */

foreach ($assignmentsByClass as $classAssignments) {
    foreach ($classAssignments as $assignment) {
        $assignmentId = (int) $assignment["id"];

        $result = teacherApiRequest(
            $apiBase . "/assignments/" . $assignmentId .
            "/progress?teacher_id=" . $teacherId
        );

        $progressByAssignment[$assignmentId] =
            !empty($result["success"])
                ? ($result["students"] ?? [])
                : [];

        if (empty($result["success"])) {
            $errors[] = $result["message"] ?? "Unable to load student progress.";
        }
    }
}

$totalAssignments = 0;
$totalStudents = 0;
$totalCompleted = 0;
$totalPending = 0;

foreach ($assignmentsByClass as $classAssignments) {
    foreach ($classAssignments as $assignment) {
        $totalAssignments++;

        $students = $progressByAssignment[(int) $assignment["id"]] ?? [];

        foreach ($students as $student) {
            $totalStudents++;

            if (($student["status"] ?? "pending") === "verified") {
                $totalCompleted++;
            } else {
                $totalPending++;
            }
        }
    }
}

/* ---------------- AI: DEADLINE RISK PREDICTIONS ---------------- */

$aiModel = null;
$aiPredictions = [];
$aiCounts = ["high" => 0, "medium" => 0, "low" => 0];
$aiError = "";

$aiResult = teacherApiRequest(
    getApiBaseUrl() . "/ai/teacher-risk?teacher_id=" . $teacherId
);

if (!empty($aiResult["success"])) {
    $aiModel = $aiResult["model"] ?? null;
    $aiPredictions = is_array($aiResult["predictions"] ?? null) ? $aiResult["predictions"] : [];
    $aiCounts = array_merge($aiCounts, $aiResult["counts"] ?? []);
} else {
    $aiError = $aiResult["message"] ?? "AI insights are unavailable.";
}

$aiAtRisk = array_values(array_filter(
    $aiPredictions,
    fn ($p) => in_array($p["level"] ?? "", ["high", "medium"], true)
));

function pct($value): string {
    return $value === null ? "—" : round(((float) $value) * 100) . "%";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EduTracker | Teacher Dashboard</title>
<link rel="stylesheet" href="../css/style.css">

<style>
* { box-sizing: border-box; }

/* Teacher dashboard header */
.top-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 16px 5%;
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    box-shadow: 0 4px 18px rgba(25,35,60,.04);
}
.header-brand, .header-actions { display: flex; align-items: center; gap: 14px; }
.brand-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 40px; height: 40px; border-radius: 12px;
    background: linear-gradient(135deg,#4f46e5,#2563eb); color: #fff;
    font-size: 23px; font-weight: 800;
    box-shadow: 0 4px 12px rgba(37,99,235,.22);
}
.brand-name { color: #243b80; font-size: 22px; font-weight: 800; letter-spacing: -.4px; }
.teacher-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-height: 42px; padding: 10px 22px; border-radius: 12px;
    background: linear-gradient(135deg,#4f46e5,#2563eb); color: #fff;
    font-size: 14px; font-weight: 800; letter-spacing: 1px;
    box-shadow: 0 5px 14px rgba(37,99,235,.24);
}
.logout-button {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 12px 22px; border-radius: 9px; background: #4f46e5;
    color: #fff; text-decoration: none; font-weight: 700; transition: .2s;
}
.logout-button:hover { background: #3730a3; transform: translateY(-1px); }


.teacher-content {
    width: min(1200px, 94%);
    margin: 32px auto;
    color: #172033;
}

.teacher-card {
    background: #fff;
    padding: 24px;
    margin: 22px 0;
    border-radius: 14px;
    box-shadow: 0 5px 24px rgba(25,35,60,.07);
}

.teacher-card h2 { margin-top: 0; }

.muted { color: #64748b; }

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 16px;
    margin: 22px 0;
}

.stat {
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(25,35,60,.06);
}

.stat strong {
    display: block;
    font-size: 27px;
    margin-top: 8px;
}

/* AI risk insights */
.ai-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.ai-tag { display: inline-block; font-size: 11px; font-weight: 800; letter-spacing: .5px; color: #4b5de2; background: #eef0ff; padding: 4px 9px; border-radius: 20px; margin-bottom: 6px; }
.ai-model { font-size: 13px; color: #64748b; margin: 4px 0 0; line-height: 1.6; }
.ai-counts { display: flex; gap: 10px; margin: 16px 0; flex-wrap: wrap; }
.ai-count { padding: 10px 14px; border-radius: 10px; font-size: 13px; font-weight: 700; }
.ai-count strong { font-size: 20px; margin-right: 6px; }
.ai-count.high { background: #fee2e2; color: #991b1b; }
.ai-count.medium { background: #fef3c7; color: #92400e; }
.ai-count.low { background: #dcfce7; color: #166534; }
.risk-pill { display: inline-block; min-width: 58px; text-align: center; padding: 5px 9px; border-radius: 20px; font-size: 12px; font-weight: 800; }
.risk-pill.high { background: #fee2e2; color: #991b1b; }
.risk-pill.medium { background: #fef3c7; color: #92400e; }
.risk-bar { height: 6px; background: #eef1f6; border-radius: 6px; margin-top: 5px; overflow: hidden; }
.risk-bar span { display: block; height: 100%; background: #d94d62; }
.risk-bar span.medium { background: #ed9b3d; }
.ai-reasons { margin: 0; padding-left: 16px; font-size: 12px; color: #475569; }
.ai-note { font-size: 12px; color: #8792a8; margin-top: 12px; }

.teacher-form {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 16px;
}

.full-width { grid-column: 1 / -1; }

.teacher-form label {
    display: block;
    font-weight: 600;
}

.teacher-form input,
.teacher-form textarea,
.teacher-form select {
    width: 100%;
    padding: 12px;
    margin-top: 6px;
    border: 1px solid #d5dbea;
    border-radius: 8px;
    font: inherit;
}

.teacher-form textarea {
    min-height: 90px;
    resize: vertical;
}

button {
    border: 0;
    border-radius: 8px;
    padding: 11px 16px;
    background: #4b5de2;
    color: white;
    cursor: pointer;
    font: inherit;
}

button:hover { background: #3948c4; }

.notice {
    padding: 13px 16px;
    margin: 14px 0;
    border-radius: 8px;
}

.notice.success { background: #dcfce7; color: #166534; }
.notice.error { background: #fee2e2; color: #991b1b; }

.class-card {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 16px;
    margin: 12px 0;
}

.priority, .status-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
}

.priority.red, .status-badge.overdue {
    background: #fee2e2;
    color: #b91c1c;
}

.priority.orange {
    background: #ffedd5;
    color: #9a3412;
}

.priority.yellow, .status-badge.pending {
    background: #fef3c7;
    color: #92400e;
}

.priority.neutral {
    background: #eef0f5;
    color: #4b5563;
}

.status-badge.completed {
    background: #dcfce7;
    color: #166534;
}

.table-wrap { overflow-x: auto; }

.progress-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 14px;
}

.progress-table th,
.progress-table td {
    padding: 12px 10px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}

.progress-table th { background: #f8fafc; }

.complete-form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.complete-form label {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 14px;
}

.complete-form input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #16a34a;
}

.completed-text {
    color: #15803d;
    font-weight: 700;
}

@media(max-width: 800px) {
    .stats { grid-template-columns: repeat(2,minmax(0,1fr)); }
}

@media(max-width: 600px) {
    .top-header { padding: 12px 4%; gap: 12px; flex-wrap: wrap; }
    .brand-name { font-size: 18px; }
    .header-brand, .header-actions { gap: 8px; }
    .teacher-badge { padding: 9px 12px; font-size: 12px; }
    .logout-button { padding: 10px 14px; }
    .teacher-form, .stats { grid-template-columns: 1fr; }
    .full-width { grid-column: auto; }
    .teacher-card { padding: 16px; }
}
</style>
</head>

<body>

<header class="top-header">
    <div class="header-brand">
        <span class="brand-icon">E</span>
        <span class="brand-name">EduTracker</span>
    </div>

    <div class="header-actions">
        <span class="teacher-badge">
            <?= htmlspecialchars(strtoupper($_SESSION["name"] ?? "Teacher"), ENT_QUOTES, "UTF-8") ?>
        </span>

        <a href="../../logout.php" class="logout-button">Logout</a>
    </div>
</header>


<main class="teacher-content">
    <h1>Teacher Dashboard</h1>
    <p class="muted">
        Welcome, <?= h($teacherName) ?>.
        Manage your classes, assignments and student completion.
    </p>

    <?php if ($success !== ""): ?>
        <div class="notice success"><?= h($success) ?></div>
    <?php endif; ?>

    <?php foreach (array_unique($errors) as $error): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <section class="stats">
        <div class="stat">
            <span>Assigned classes</span>
            <strong><?= count($classes) ?></strong>
        </div>
        <div class="stat">
            <span>Assignments</span>
            <strong><?= $totalAssignments ?></strong>
        </div>
        <div class="stat">
            <span>Completed assessments</span>
            <strong><?= $totalCompleted ?></strong>
        </div>
        <div class="stat">
            <span>Awaiting completion</span>
            <strong><?= $totalPending ?></strong>
        </div>
    </section>

    <section class="teacher-card" id="ai-insights">
        <div class="ai-head">
            <div>
                <span class="ai-tag">✦ AI INSIGHTS</span>
                <h2 style="margin:0">Deadline Risk Prediction</h2>
                <?php if ($aiModel): ?>
                    <p class="ai-model">
                        <?php if (($aiModel["mode"] ?? "") === "trained"): ?>
                            Logistic regression trained on <?= (int) ($aiModel["samples"] ?? 0) ?> past student–assignment records.
                            Hold-out accuracy <?= h(pct($aiModel["metrics"]["accuracy"] ?? null)) ?>,
                            ROC-AUC <?= isset($aiModel["metrics"]["auc"]) ? h(number_format((float) $aiModel["metrics"]["auc"], 2)) : "—" ?>.
                        <?php else: ?>
                            Starting model (expert prior weights). It switches to a trained model automatically once enough deadlines have passed.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            <form method="POST" action="teacher-dashboard.php">
                <input type="hidden" name="action" value="retrain_ai">
                <button type="submit">Retrain model</button>
            </form>
        </div>

        <?php if ($aiError !== ""): ?>
            <p class="muted"><?= h($aiError) ?></p>
        <?php else: ?>
            <div class="ai-counts">
                <div class="ai-count high"><strong><?= (int) $aiCounts["high"] ?></strong>high risk</div>
                <div class="ai-count medium"><strong><?= (int) $aiCounts["medium"] ?></strong>medium risk</div>
                <div class="ai-count low"><strong><?= (int) $aiCounts["low"] ?></strong>low risk</div>
            </div>

            <?php if (empty($aiAtRisk)): ?>
                <p class="muted">No student is currently predicted to be at risk on your open assignments.</p>
            <?php else: ?>
                <div style="overflow-x:auto">
                <table class="progress-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Assignment</th>
                            <th>Due</th>
                            <th>Risk of missing</th>
                            <th>Why</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($aiAtRisk, 0, 15) as $p): $prob = (float) ($p["probability"] ?? 0); $lvl = $p["level"] ?? "medium"; ?>
                        <tr>
                            <td><strong><?= h($p["student_name"] ?? "Student") ?></strong><br><span class="muted"><?= h($p["roll_number"] ?? "") ?></span></td>
                            <td><?= h($p["title"] ?? "") ?></td>
                            <td><?= ($t = strtotime((string) ($p["due_date"] ?? ""))) ? h(date("d M, h:i A", $t)) : "—" ?></td>
                            <td>
                                <span class="risk-pill <?= h($lvl) ?>"><?= round($prob * 100) ?>%</span>
                                <div class="risk-bar"><span class="<?= h($lvl) ?>" style="width:<?= max(0, min(100, round($prob * 100))) ?>%"></span></div>
                            </td>
                            <td>
                                <ul class="ai-reasons">
                                    <?php foreach (($p["reasons"] ?? []) as $reason): ?>
                                        <li><?= h($reason) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
            <p class="ai-note">Predictions cover students who have not yet submitted an open assignment. High-risk students automatically receive an extra reminder about 72 hours before the deadline. Use this as a prompt to check in, not as a judgement of the student.</p>
        <?php endif; ?>
    </section>

    <section class="teacher-card">
        <h2>My Assigned Classes</h2>

        <?php if (empty($classes)): ?>
            <p class="muted">
                No classes are currently assigned to you.
                Ask the administrator to assign your classes.
            </p>
        <?php else: ?>
            <?php foreach ($classes as $class): ?>
                <div class="class-card">
                    <h3><?= h($class["class_name"] ?? "Class") ?></h3>
                    <p>
                        <strong>Section:</strong>
                        <?= h($class["section_code"] ?? $class["class_name"] ?? "—") ?><br>
                        <strong>Subject:</strong>
                        <?= h($class["subject"] ?? "—") ?><br>
                        <strong>Department:</strong>
                        <?= h($class["department"] ?? "—") ?><br>
                        <strong>Students:</strong>
                        <?= (int) ($class["student_count"] ?? $class["enrolled_students"] ?? 0) ?>
                    </p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="teacher-card" id="create-assignment">
        <h2>Create Assignment</h2>

        <?php if (empty($classes)): ?>
            <p class="muted">You need an assigned class before creating an assignment.</p>
        <?php else: ?>
            <form method="POST" class="teacher-form">
                <input type="hidden" name="action" value="create_assignment">

                <div class="full-width">
                    <label for="class_id">Class and subject</label>
                    <select id="class_id" name="class_id" required>
                        <option value="">Choose a class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= (int) $class["id"] ?>">
                                <?= h($class["class_name"] ?? "Class") ?>
                                — <?= h($class["subject"] ?? "Subject") ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="full-width">
                    <label for="title">Assignment title</label>
                    <input
                        id="title"
                        name="title"
                        maxlength="200"
                        required
                        placeholder="e.g. React Components Assignment"
                    >
                </div>

                <div class="full-width">
                    <label for="description">Instructions</label>
                    <textarea
                        id="description"
                        name="description"
                        placeholder="Enter assignment instructions"
                    ></textarea>
                </div>

                <div>
                    <label for="due_date">Deadline</label>
                    <input
                        id="due_date"
                        type="datetime-local"
                        name="due_date"
                        required
                    >
                </div>

                <div style="align-self:end">
                    <button type="submit">Create Assignment</button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="teacher-card">
        <h2>Assignments and Student Progress</h2>

        <?php
        $hasAssignments = false;
        foreach ($assignmentsByClass as $items) {
            if (!empty($items)) {
                $hasAssignments = true;
                break;
            }
        }
        ?>

        <?php if (!$hasAssignments): ?>
            <p class="muted">
                No assignments have been created yet.
                Create one above to see student progress.
            </p>
        <?php else: ?>

            <?php foreach ($classes as $class): ?>
                <?php
                $classId = (int) $class["id"];
                $items = $assignmentsByClass[$classId] ?? [];
                ?>

                <?php if (!empty($items)): ?>
                    <h3>
                        <?= h($class["class_name"] ?? "Class") ?>
                        — <?= h($class["subject"] ?? "Subject") ?>
                    </h3>

                    <?php foreach ($items as $assignment): ?>
                        <?php
                        $assignmentId = (int) $assignment["id"];
                        $students = $progressByAssignment[$assignmentId] ?? [];
                        [$priorityClass, $priorityLabel] = deadlineInfo($assignment);
                        $verified = 0;

                        foreach ($students as $student) {
                            if (($student["status"] ?? "pending") === "verified") {
                                $verified++;
                            }
                        }
                        ?>

                        <div class="class-card">
                            <h3><?= h($assignment["title"]) ?></h3>

                            <?php if (!empty($assignment["description"])): ?>
                                <p><?= nl2br(h($assignment["description"])) ?></p>
                            <?php endif; ?>

                            <p>
                                <strong>Deadline:</strong>
                                <?php
                                $dueTimestamp = strtotime($assignment["due_date"] ?? "");
                                echo $dueTimestamp
                                    ? h(date("d M Y, h:i A", $dueTimestamp))
                                    : "Not available";
                                ?>
                            </p>

                            <span class="priority <?= h($priorityClass) ?>">
                                <?= h($priorityLabel) ?>
                            </span>

                            <p>
                                <strong>Teacher-verified:</strong>
                                <?= $verified ?> / <?= count($students) ?> students
                            </p>

                            <?php if (!empty($students)): ?>
                                <div class="table-wrap">
                                    <table class="progress-table">
                                        <thead>
    <tr>
        <th>Student</th>
        <th>Roll number</th>
        <th>Status</th>
        <th>Digital Answer</th>
        <th>Marks & Feedback</th>
        <th>Teacher Action</th>
    </tr>
</thead>
                                        <tbody>
                                        <?php foreach ($students as $student): ?>
                                            <?php
                                            $status = $student["status"] ?? "pending";
                                            $isCompleted = $status === "verified";
                                            $isOverdue = !$isCompleted
                                                && !empty($dueTimestamp)
                                                && $dueTimestamp < time();
                                            ?>

                                            <tr>
                                                <td><?= h($student["name"] ?? "Student") ?></td>
                                                <td><?= h($student["roll_number"] ?? "—") ?></td>

                                                <!-- Student status -->
                                                <td>
                                                    <?php if ($isCompleted): ?>
                                                        <span class="status-badge completed">Completed</span>
                                                    <?php elseif ($isOverdue): ?>
                                                        <span class="status-badge overdue">Overdue</span>
                                                    <?php else: ?>
                                                        <span class="status-badge pending">Pending</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Student file / answer -->
                                                <td>
                                                    <?php if (!empty($student["file_path"])): ?>
                                                        <a
                                                            href="<?= h(getFileViewUrl($student["file_path"])) ?>"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            style="display:inline-block;color:#4b5de2;font-weight:700;"
                                                        >
                                                            View / Download PDF
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="muted">No PDF uploaded</span>
                                                    <?php endif; ?>

                                                    <?php if (!empty($student["answer_text"]) || !empty($student["file_path"])): ?>
                                                        <details style="margin-top:10px;">
                                                            <summary style="cursor:pointer;color:#4b5de2;font-weight:600;">
                                                                View text answer
                                                            </summary>
                                                            <div style="margin-top:10px;padding:12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;white-space:pre-wrap;overflow-wrap:anywhere;"><?= h($student["answer_text"]) ?></div>
                                                        </details>
                                                    <?php endif; ?>

                                                    <?php if (!empty($student["submitted_at"])): ?>
                                                        <small class="muted" style="display:block;margin-top:8px;">
                                                            Submitted: <?= h(date("d M Y, h:i A", strtotime($student["submitted_at"]))) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Marks and feedback -->
                                                <td>
                                                    <?php if (!empty($student["answer_text"])): ?>
                                                        <form method="POST" class="teacher-form" style="display:block;min-width:190px;">
                                                            <input type="hidden" name="action" value="grade_assignment">
                                                            <input type="hidden" name="assignment_id" value="<?= (int) $assignmentId ?>">
                                                            <input type="hidden" name="student_id" value="<?= (int) $student["student_id"] ?>">

                                                            <label>
                                                                Marks obtained
                                                                <input type="number" name="marks_obtained" min="0" step="0.01" max="<?= h($student["max_marks"] ?? 100) ?>" value="<?= h($student["marks_obtained"] ?? "") ?>" required>
                                                            </label>

                                                            <label style="display:block;margin-top:10px;">
                                                                Maximum marks
                                                                <input type="number" name="max_marks" min="0.01" step="0.01" value="<?= h($student["max_marks"] ?? 100) ?>" required>
                                                            </label>

                                                            <label style="display:block;margin-top:10px;">
                                                                Teacher feedback
                                                                <textarea name="teacher_feedback" placeholder="Enter feedback for the student" style="width:100%;min-height:75px;margin-top:6px;"><?= h($student["teacher_feedback"] ?? "") ?></textarea>
                                                            </label>

                                                            <?php if (isset($student["marks_obtained"]) && $student["marks_obtained"] !== null): ?>
                                                                <p style="margin:8px 0;">
                                                                    <strong>Current grade:</strong>
                                                                    <?= h($student["marks_obtained"]) ?> / <?= h($student["max_marks"] ?? 100) ?>
                                                                </p>
                                                            <?php endif; ?>

                                                            <button type="submit" style="margin-top:10px;">Save Grade &amp; Feedback</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="muted">Waiting for submission</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Teacher action -->
                                                <td>
                                                    <?php if (!$isCompleted): ?>
                                                        <form method="POST" class="complete-form">
                                                            <input type="hidden" name="action" value="verify_submission">
                                                            <input type="hidden" name="assignment_id" value="<?= (int) $assignmentId ?>">
                                                            <input type="hidden" name="student_id" value="<?= (int) $student["student_id"] ?>">
                                                            <label>
                                                                <input type="checkbox" name="confirm_completion" value="1" required>
                                                                Mark completed
                                                            </label>
                                                            <button type="submit">Save</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="completed-text">✓ Completed</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="muted">
                                    No students are enrolled in this class yet.
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>

        <?php endif; ?>
    </section>
</main>

<script>
const deadlineInput = document.getElementById("due_date");

if (deadlineInput) {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    deadlineInput.min = now.toISOString().slice(0, 16);
}
</script>

</body>
</html>
