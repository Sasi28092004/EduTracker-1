<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "admin"
) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../../php/config/database.php";

$apiKey = getEduTrackerApiKey();
$baseUrl = getApiBaseUrl();
$message = "";
$messageType = "info";
$teachers = [];
$classes = [];
$assignments = [];

function assignApiRequest(
    string $url,
    string $apiKey,
    string $method = "GET",
    array $data = []
): array {
    if (!function_exists("curl_init")) {
        return ["success" => false, "message" => "PHP cURL is not enabled."];
    }

    if ($apiKey === "") {
        return ["success" => false, "message" => "API key missing from server/.env."];
    }

    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            "Accept: application/json",
            "Content-Type: application/json",
            "x-api-key: " . $apiKey
        ]
    ];

    if ($method === "POST") {
        $json = json_encode($data);
        if ($json === false) {
            curl_close($ch);
            return ["success" => false, "message" => "Unable to encode request."];
        }
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $json;
    } else {
        $options[CURLOPT_HTTPGET] = true;
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log("Teacher assignment API error: " . $error);
        return [
            "success" => false,
            "message" => "Cannot connect to Express. Check that Node.js is running."
        ];
    }

    curl_close($ch);
    $result = json_decode($response, true);

    if (!is_array($result)) {
        return ["success" => false, "message" => "Invalid response from Express."];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            "success" => false,
            "message" => $result["message"] ?? "API request failed (HTTP $httpCode)."
        ];
    }

    return $result;
}

function assignEscape($value): string {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

// Submit teacher-to-section assignments.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $teacherId = filter_var(
        $_POST["teacher_id"] ?? null,
        FILTER_VALIDATE_INT
    );

    $classIds = $_POST["class_ids"] ?? [];

    if (
        !$teacherId ||
        !is_array($classIds) ||
        count($classIds) === 0
    ) {
        $message = "Select a teacher and at least one section.";
        $messageType = "error";
    } else {
        $validIds = [];

        foreach ($classIds as $id) {
            $validId = filter_var($id, FILTER_VALIDATE_INT);

            if ($validId && $validId > 0) {
                $validIds[] = $validId;
            }
        }

        $validIds = array_values(array_unique($validIds));

        if (count($validIds) === 0) {
            $message = "Select valid sections.";
            $messageType = "error";
        } else {
            $result = assignApiRequest(
                $baseUrl . "/teachers/assign-sections",
                $apiKey,
                "POST",
                [
                    "teacher_id" => $teacherId,
                    "class_ids" => $validIds
                ]
            );

            $message = $result["message"]
                ?? "Unable to save teacher assignments.";

            $messageType = ($result["success"] ?? false)
                ? "success"
                : "error";
        }
    }
}

// Load teachers.
$teacherResult = assignApiRequest(
    $baseUrl . "/teachers",
    $apiKey
);

if (($teacherResult["success"] ?? false) === true) {
    $teachers = $teacherResult["teachers"]
        ?? $teacherResult["data"]
        ?? [];
} elseif ($message === "") {
    $message = $teacherResult["message"] ?? "Unable to load teachers.";
    $messageType = "error";
}

// Load sections/classes.
$classResult = assignApiRequest(
    $baseUrl . "/classes",
    $apiKey
);

if (($classResult["success"] ?? false) === true) {
    $classes = $classResult["classes"]
        ?? $classResult["data"]
        ?? [];
} elseif ($message === "") {
    $message = $classResult["message"] ?? "Unable to load sections.";
    $messageType = "error";
}

// Load current teacher-section assignments from the database-backed API.
foreach ($teachers as $teacher) {
    $teacherId = (int)($teacher["id"] ?? 0);

    if ($teacherId <= 0) {
        continue;
    }

    // This endpoint lists only the classes of a specified teacher.
    $result = assignApiRequest(
        $baseUrl . "/teachers/classes?teacher_id=" . $teacherId,
        $apiKey
    );

    if (($result["success"] ?? false) === true) {
        $assignments[$teacherId] = $result["classes"] ?? [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teacher–Section Assignment | EduTracker</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:#f4f6fb;color:#172033}
header{background:#172554;color:white;padding:20px 6%;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
header h1{margin:0;font-size:23px}
header a{color:white;background:#334b85;padding:10px 14px;border-radius:7px;text-decoration:none}
main{width:min(1100px,92%);margin:32px auto}
.panel{background:white;border-radius:12px;padding:24px;margin-bottom:24px;box-shadow:0 5px 20px #14234612}
h2{margin-top:0;font-size:21px}
label{display:block;font-weight:600;margin-bottom:8px}
select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:7px;background:white;font-size:15px}
.teacher-select{margin-bottom:22px}
.section-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:10px}
.section-option{display:flex;gap:10px;align-items:flex-start;padding:14px;border:1px solid #dbe2ee;border-radius:8px}
.section-option input{margin-top:3px}
.section-option strong{display:block;margin-bottom:5px}
.muted{color:#64748b;font-size:13px}
button{margin-top:20px;padding:12px 18px;background:#2563eb;color:white;border:0;border-radius:7px;cursor:pointer;font-size:15px}
button:disabled{opacity:.5;cursor:not-allowed}
.message{padding:13px 15px;border-radius:7px;margin-bottom:20px;overflow-wrap:anywhere}
.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}.info{background:#dbeafe;color:#1e40af}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:12px;border-bottom:1px solid #e5e7eb;vertical-align:top}
th{background:#f8fafc}
@media(max-width:650px){.section-list{grid-template-columns:1fr}.panel{padding:17px}}
</style>
</head>
<body>
<header>
    <h1>EduTracker — Teacher Assignment</h1>
    <a href="admin-dashboard.php">← Admin Dashboard</a>
</header>

<main>
<?php if ($message !== ""): ?>
<div class="message <?= assignEscape($messageType) ?>">
    <?= assignEscape($message) ?>
</div>
<?php endif; ?>

<section class="panel">
<h2>Assign Teacher to Sections</h2>

<?php if (!$teachers): ?>
<p class="muted">No teachers found. Create a teacher account first.</p>
<?php elseif (!$classes): ?>
<p class="muted">No sections found. Create sections first.</p>
<?php else: ?>
<form method="POST" action="assign-teachers.php">
    <div class="teacher-select">
        <label for="teacher_id">Select Teacher</label>
        <select name="teacher_id" id="teacher_id" required>
            <option value="">Choose a teacher</option>
            <?php foreach ($teachers as $teacher): ?>
            <option value="<?= assignEscape($teacher["id"] ?? "") ?>">
                <?= assignEscape($teacher["name"] ?? "Teacher") ?>
                <?php if (!empty($teacher["employee_id"])): ?>
                    — <?= assignEscape($teacher["employee_id"]) ?>
                <?php endif; ?>
                <?php if (!empty($teacher["department"])): ?>
                    (<?= assignEscape($teacher["department"]) ?>)
                <?php endif; ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <label>Select Sections</label>
    <p class="muted">Choose one or more sections for this teacher.</p>

    <div class="section-list">
    <?php foreach ($classes as $class): ?>
        <label class="section-option">
            <input
                type="checkbox"
                name="class_ids[]"
                value="<?= assignEscape($class["id"] ?? "") ?>"
            >
            <span>
                <strong>
                    <?= assignEscape($class["class_name"] ?? "") ?>
                    — <?= assignEscape($class["section_code"] ?? "") ?>
                </strong>
                <span class="muted">
                    Department: <?= assignEscape($class["department"] ?? "—") ?>
                    <br>
                    Subject: <?= assignEscape($class["subject"] ?? "—") ?>
                    <br>
                    Capacity: <?= assignEscape($class["capacity"] ?? "—") ?>
                </span>
            </span>
        </label>
    <?php endforeach; ?>
    </div>

    <button type="submit">Save Assignments</button>
</form>
<?php endif; ?>
</section>

<section class="panel">
<h2>Current Teacher Assignments</h2>
<?php if ($teachers): ?>
<div class="table-wrap">
<table>
<thead>
<tr><th>Teacher</th><th>Department</th><th>Assigned Sections</th></tr>
</thead>
<tbody>
<?php foreach ($teachers as $teacher): ?>
<tr>
<td><?= assignEscape($teacher["name"] ?? "") ?></td>
<td><?= assignEscape($teacher["department"] ?? "—") ?></td>
<td>
<?php
$teacherId = (int)($teacher["id"] ?? 0);
$assignedClasses = $assignments[$teacherId] ?? [];
?>
<?php if ($assignedClasses): ?>
    <?php foreach ($assignedClasses as $assignedClass): ?>
        <div>
            <?= assignEscape($assignedClass["class_name"] ?? "") ?>
            — <?= assignEscape($assignedClass["section_code"] ?? "") ?>
            (<?= assignEscape($assignedClass["subject"] ?? "") ?>)
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <span class="muted">No sections assigned</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<p class="muted">No teacher records are available.</p>
<?php endif; ?>
</section>
</main>
</body>
</html>
