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

function enrollmentApi(string $url, string $apiKey, string $method = "GET", array $data = []): array {
    if (!function_exists("curl_init")) {
        return ["success" => false, "message" => "PHP cURL is not enabled."];
    }

    if ($apiKey === "") {
        return ["success" => false, "message" => "API key missing in server/.env."];
    }

    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Accept: application/json",
            "x-api-key: " . $apiKey
        ]
    ];

    if ($method === "POST") {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    } else {
        $options[CURLOPT_HTTPGET] = true;
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log("Enrollment API error: " . $error);
        return ["success" => false, "message" => "Cannot connect to the EduTracker API."];
    }

    curl_close($ch);
    $result = json_decode($response, true);

    if (!is_array($result)) {
        return ["success" => false, "message" => "Invalid API response."];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            "success" => false,
            "message" => $result["message"] ?? "API request failed (HTTP $httpCode)."
        ];
    }

    return $result;
}

function enrollmentEscape($value): string {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$baseUrl = getApiBaseUrl() . "/students";
$message = "";
$messageType = "info";
$students = [];
$classes = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $studentId = filter_var($_POST["student_id"] ?? null, FILTER_VALIDATE_INT);
    $classId = filter_var($_POST["class_id"] ?? null, FILTER_VALIDATE_INT);

    if (!$studentId || !$classId) {
        $message = "Please select both a student and a section.";
        $messageType = "error";
    } else {
        $result = enrollmentApi(
            $baseUrl . "/enroll",
            $apiKey,
            "POST",
            [
                "student_id" => $studentId,
                "class_id" => $classId
            ]
        );

        $message = $result["message"] ??
            (($result["success"] ?? false) ? "Enrollment successful." : "Enrollment failed.");

        $messageType = ($result["success"] ?? false) ? "success" : "error";
    }
}

$studentResult = enrollmentApi($baseUrl, $apiKey);
if (($studentResult["success"] ?? false) === true) {
    $students = $studentResult["students"] ?? [];
} elseif ($message === "") {
    $message = $studentResult["message"] ?? "Unable to load students.";
    $messageType = "error";
}

$classResult = enrollmentApi(
    getApiBaseUrl() . "/classes",
    $apiKey
);
if (($classResult["success"] ?? false) === true) {
    $classes = $classResult["classes"] ?? [];
} elseif ($message === "") {
    $message = $classResult["message"] ?? "Unable to load sections.";
    $messageType = "error";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Enrollment | EduTracker</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:#f4f6fb;color:#172033}
header{background:#172554;color:white;padding:20px 6%;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
header h1{font-size:23px;margin:0}
header a{color:white;background:#334b85;padding:10px 14px;border-radius:7px;text-decoration:none}
main{width:min(1100px,92%);margin:32px auto}
.panel{background:white;border-radius:12px;padding:24px;margin-bottom:24px;box-shadow:0 5px 20px #14234612}
h2{margin-top:0;font-size:21px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
label{display:block;font-weight:600;margin-bottom:8px}
select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:7px;background:white;font-size:15px}
button{margin-top:18px;padding:12px 18px;background:#2563eb;color:white;border:0;border-radius:7px;cursor:pointer;font-size:15px}
button:disabled{opacity:.5;cursor:not-allowed}
.message{padding:13px 15px;border-radius:7px;margin-bottom:20px;overflow-wrap:anywhere}
.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}.info{background:#dbeafe;color:#1e40af}
.table-wrapper{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th,td{padding:13px 12px;text-align:left;border-bottom:1px solid #e5e7eb;white-space:nowrap}
th{background:#f8fafc}
.hint,.empty{color:#64748b;font-size:13px}
@media(max-width:650px){.form-grid{grid-template-columns:1fr}.panel{padding:17px}}
</style>
</head>
<body>
<header>
    <h1>EduTracker — Student Enrollment</h1>
    <a href="admin-dashboard.php">← Admin Dashboard</a>
</header>

<main>
<?php if ($message !== ""): ?>
<div class="message <?= enrollmentEscape($messageType) ?>">
    <?= enrollmentEscape($message) ?>
</div>
<?php endif; ?>

<section class="panel">
<h2>Enroll Student in a Section</h2>

<?php if (!$students): ?>
<p class="hint">No registered students were returned. Register a student first.</p>
<?php endif; ?>

<?php if (!$classes): ?>
<p class="hint">No sections were returned. Create a section first.</p>
<?php endif; ?>

<form method="POST" action="enroll-students.php">
<div class="form-grid">
    <div>
        <label for="student_id">Student</label>
        <select name="student_id" id="student_id" required>
            <option value="">Select student</option>
            <?php foreach ($students as $student): ?>
            <option value="<?= enrollmentEscape($student["id"] ?? "") ?>">
                <?= enrollmentEscape($student["roll_number"] ?? "") ?>
                — <?= enrollmentEscape($student["name"] ?? "") ?>
                (<?= enrollmentEscape($student["department"] ?? "") ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="class_id">Section</label>
        <select name="class_id" id="class_id" required>
            <option value="">Select section</option>
            <?php foreach ($classes as $class): ?>
            <?php
                $capacity = (int)($class["capacity"] ?? 60);
                $enrolled = (int)($class["enrolled_count"] ?? 0);
                $available = max(0, $capacity - $enrolled);
            ?>
            <option
                value="<?= enrollmentEscape($class["id"] ?? "") ?>"
                <?= $available < 1 ? "disabled" : "" ?>>
                <?= enrollmentEscape($class["class_name"] ?? "") ?>
                — <?= enrollmentEscape($class["section_code"] ?? "") ?>
                — <?= enrollmentEscape($class["subject"] ?? "") ?>
                (<?= $available ?> seats available)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<button type="submit" <?= (!$students || !$classes) ? "disabled" : "" ?>>
    Enroll Student
</button>
</form>
</section>

<section class="panel">
<h2>Section Capacity</h2>
<?php if ($classes): ?>
<div class="table-wrapper">
<table>
<thead>
<tr>
<th>Department</th>
<th>Class</th>
<th>Section</th>
<th>Subject</th>
<th>Enrolled</th>
<th>Capacity</th>
<th>Available</th>
</tr>
</thead>
<tbody>
<?php foreach ($classes as $class): ?>
<?php
    $capacity = (int)($class["capacity"] ?? 60);
    $enrolled = (int)($class["enrolled_students"] ?? 0);
    $available = max(0, $capacity - $enrolled);
?>
<tr>
<td><?= enrollmentEscape($class["department"] ?? "") ?></td>
<td><?= enrollmentEscape($class["class_name"] ?? "") ?></td>
<td><?= enrollmentEscape($class["section_code"] ?? "") ?></td>
<td><?= enrollmentEscape($class["subject"] ?? "") ?></td>
<td><?= $enrolled ?></td>
<td><?= $capacity ?></td>
<td><?= $available ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<p class="empty">No sections are available.</p>
<?php endif; ?>
</section>
</main>
</body>
</html>
