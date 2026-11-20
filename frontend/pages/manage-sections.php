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
$classesUrl = getApiBaseUrl() . "/classes";
$departmentsUrl = getApiBaseUrl() . "/departments";

$message = "";
$messageType = "info";
$departments = [];
$classes = [];

function sectionApiRequest(
    string $url,
    string $apiKey,
    string $method = "GET",
    array $data = []
): array {
    if (!function_exists("curl_init")) {
        return ["success" => false, "message" => "PHP cURL is not enabled."];
    }

    if ($apiKey === "") {
        return ["success" => false, "message" => "API key missing. Check server/.env."];
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
        $json = json_encode($data);

        if ($json === false) {
            curl_close($ch);
            return ["success" => false, "message" => "Unable to encode section data."];
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
        error_log("Section API error: " . $error);

        return [
            "success" => false,
            "message" => "Cannot connect to Express. Check that Node.js is running on port 3000."
        ];
    }

    curl_close($ch);
    $result = json_decode($response, true);

    if (!is_array($result)) {
        return ["success" => false, "message" => "Invalid response from Express API."];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            "success" => false,
            "message" => $result["message"] ?? "API request failed (HTTP $httpCode)."
        ];
    }

    return $result;
}

function sectionEscape($value): string {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

// Create a section.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $department = trim($_POST["department"] ?? "");
    $className = trim($_POST["class_name"] ?? "");
    $sectionCode = trim($_POST["section_code"] ?? "");
    $subject = trim($_POST["subject"] ?? "");
    $capacity = filter_var($_POST["capacity"] ?? null, FILTER_VALIDATE_INT);

    if ($department === "" || $className === "" ||
        $sectionCode === "" || $subject === "") {
        $message = "Please complete all fields.";
        $messageType = "error";
    } elseif ($capacity === false || $capacity < 1 || $capacity > 60) {
        $message = "Capacity must be between 1 and 60.";
        $messageType = "error";
    } else {
        $result = sectionApiRequest(
            $classesUrl,
            $apiKey,
            "POST",
            [
                "department" => $department,
                "class_name" => $className,
                "section_code" => $sectionCode,
                "subject" => $subject,
                "capacity" => $capacity,
                "admin_id" => $_SESSION["user_id"]
            ]
        );

        if (($result["success"] ?? false) === true) {
            $_SESSION["section_flash"] = [
                "message" => $result["message"] ?? "Section created successfully.",
                "type" => "success"
            ];

            header("Location: manage-sections.php");
            exit;
        }

        $message = $result["message"] ?? "Could not create the section.";
        $messageType = "error";
    }
}

if (isset($_SESSION["section_flash"])) {
    $flash = $_SESSION["section_flash"];
    unset($_SESSION["section_flash"]);

    $message = $flash["message"] ?? $message;
    $messageType = $flash["type"] ?? $messageType;
}

// Load departments.
$departmentResult = sectionApiRequest($departmentsUrl, $apiKey);

if (($departmentResult["success"] ?? false) === true) {
    $departments = $departmentResult["departments"]
        ?? $departmentResult["data"]
        ?? [];
} elseif ($message === "") {
    $message = $departmentResult["message"] ?? "Could not load departments.";
    $messageType = "error";
}

// Load existing classes/sections.
$classResult = sectionApiRequest($classesUrl, $apiKey);

if (($classResult["success"] ?? false) === true) {
    $classes = $classResult["classes"] ?? $classResult["data"] ?? [];
} elseif ($message === "") {
    $message = $classResult["message"] ?? "Could not load sections.";
    $messageType = "error";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Sections | EduTracker</title>
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
input,select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:7px;background:white;font-size:15px}
button{margin-top:18px;padding:12px 18px;background:#2563eb;color:white;border:0;border-radius:7px;cursor:pointer;font-size:15px}
button:disabled{opacity:.5;cursor:not-allowed}
.message{padding:13px 15px;border-radius:7px;margin-bottom:20px;overflow-wrap:anywhere}
.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}.info{background:#dbeafe;color:#1e40af}
.table-wrapper{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th,td{padding:13px 12px;text-align:left;border-bottom:1px solid #e5e7eb;white-space:nowrap}
th{background:#f8fafc}.hint,.empty{color:#64748b;font-size:13px}
@media(max-width:650px){.form-grid{grid-template-columns:1fr}.panel{padding:17px}}
</style>
</head>
<body>
<header>
    <h1>EduTracker — Section Management</h1>
    <a href="admin-dashboard.php">← Admin Dashboard</a>
</header>

<main>
<?php if ($message !== ""): ?>
<div class="message <?= sectionEscape($messageType) ?>">
    <?= sectionEscape($message) ?>
</div>
<?php endif; ?>

<section class="panel">
<h2>Create Section</h2>
<form method="POST" action="manage-sections.php">
<div class="form-grid">
    <div>
        <label for="department">Department</label>
        <select id="department" name="department" required>
            <option value="">Select department</option>
            <?php foreach ($departments as $dept): ?>
            <option value="<?= sectionEscape($dept["department_name"] ?? "") ?>">
                <?= sectionEscape($dept["department_name"] ?? "") ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php if (!$departments): ?>
        <p class="hint">Add a department first in Department Management.</p>
        <?php endif; ?>
    </div>

    <div>
        <label for="class_name">Class Name</label>
        <input id="class_name" name="class_name" maxlength="100"
               placeholder="Example: CSE" required>
    </div>

    <div>
        <label for="section_code">Section Code</label>
        <select id="section_code" name="section_code" required>
            <option value="">Select section</option>
            <option value="Core">Core</option>
            <option value="FS">FS</option>
            <option value="DS">DS</option>
        </select>
    </div>

    <div>
        <label for="subject">Subject</label>
        <input id="subject" name="subject" maxlength="100"
               placeholder="Example: Data Structures" required>
    </div>

    <div>
        <label for="capacity">Student Capacity</label>
        <input id="capacity" name="capacity" type="number"
               min="1" max="60" value="60" required>
        <p class="hint">Maximum 60 students.</p>
    </div>
</div>

<button type="submit" <?= !$departments ? "disabled" : "" ?>>
    Create Section
</button>
</form>
</section>

<section class="panel">
<h2>Existing Sections</h2>
<?php if ($classes): ?>
<div class="table-wrapper">
<table>
<thead>
<tr>
<th>ID</th><th>Department</th><th>Class</th>
<th>Section</th><th>Subject</th><th>Capacity</th><th>Enrolled</th>
</tr>
</thead>
<tbody>
<?php foreach ($classes as $class): ?>
<tr>
<td><?= sectionEscape($class["id"] ?? "") ?></td>
<td><?= sectionEscape($class["department"] ?? "") ?></td>
<td><?= sectionEscape($class["class_name"] ?? "") ?></td>
<td><?= sectionEscape($class["section_code"] ?? "") ?></td>
<td><?= sectionEscape($class["subject"] ?? "") ?></td>
<td><?= sectionEscape($class["capacity"] ?? "") ?></td>
<td><?= sectionEscape($class["enrolled_students"] ?? $class["enrolled_count"] ?? 0) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<p class="empty">No sections were returned by the API.</p>
<?php endif; ?>
</section>
</main>
</body>
</html>
