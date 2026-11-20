<?php
session_start();

/*
|--------------------------------------------------------------------------
| EduTracker Department Management
|--------------------------------------------------------------------------
| File: frontend/pages/manage-departments.php
| API:  http://localhost:3000/api/departments
*/

// Only logged-in administrators can access this page.
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "admin"
) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../../php/config/database.php";

$apiUrl = getApiBaseUrl() . "/departments";

$message = "";
$messageType = "info";
$departments = [];

$apiKey = getEduTrackerApiKey();

/*
|--------------------------------------------------------------------------
| Call the Express API
|--------------------------------------------------------------------------
*/

function requestDepartmentApi(
    string $url,
    string $apiKey,
    string $method = "GET",
    array $data = []
): array {

    if (!function_exists("curl_init")) {
        return [
            "success" => false,
            "message" => "PHP cURL is not enabled in XAMPP."
        ];
    }

    if ($apiKey === "") {
        return [
            "success" => false,
            "message" => "API key is missing. Check server/.env."
        ];
    }

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            "success" => false,
            "message" => "Unable to initialize the API request."
        ];
    }

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

            return [
                "success" => false,
                "message" => "Unable to encode department data."
            ];
        }

        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $json;
    } elseif ($method !== "GET") {
        curl_close($ch);

        return [
            "success" => false,
            "message" => "Unsupported API request method."
        ];
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        error_log("EduTracker API connection error: " . $error);

        return [
            "success" => false,
            "message" => "Cannot connect to Express. Check that Node.js is running on port 3000."
        ];
    }

    curl_close($ch);

    $result = json_decode($response, true);

    if (!is_array($result)) {
        return [
            "success" => false,
            "message" => "The Express API returned an invalid response."
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            "success" => false,
            "message" => $result["message"]
                ?? "The department API request failed (HTTP "
                . $httpCode . ")."
        ];
    }

    return $result;
}

/*
|--------------------------------------------------------------------------
| Get API key
|--------------------------------------------------------------------------
*/

$apiKey = getEduTrackerApiKey();

/*
|--------------------------------------------------------------------------
| Add department
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $departmentName = trim(
        $_POST["department_name"] ?? ""
    );

    if ($departmentName === "") {
        $message = "Please enter a department name.";
        $messageType = "error";
    } elseif (mb_strlen($departmentName) > 100) {
        $message = "Department name cannot exceed 100 characters.";
        $messageType = "error";
    } else {
        $result = requestDepartmentApi(
            $apiUrl,
            $apiKey,
            "POST",
            [
                "department_name" => $departmentName
            ]
        );

        if (($result["success"] ?? false) === true) {
            // Prevent duplicate submission on browser refresh.
            $_SESSION["department_flash"] = [
                "message" => $result["message"]
                    ?? "Department added successfully.",
                "type" => "success"
            ];

            header("Location: manage-departments.php");
            exit;
        }

        $message = $result["message"]
            ?? "Unable to add the department.";
        $messageType = "error";
    }
}

/*
|--------------------------------------------------------------------------
| Display one-time messages
|--------------------------------------------------------------------------
*/

if (isset($_SESSION["department_flash"])) {
    $flash = $_SESSION["department_flash"];
    unset($_SESSION["department_flash"]);

    $message = $flash["message"] ?? "";
    $messageType = $flash["type"] ?? "info";
}

/*
|--------------------------------------------------------------------------
| Load departments
|--------------------------------------------------------------------------
*/

$listResult = requestDepartmentApi(
    $apiUrl,
    $apiKey,
    "GET"
);

if (($listResult["success"] ?? false) === true) {
    // Support common response shapes from the Express controller.
    $rows = $listResult["departments"]
        ?? $listResult["data"]
        ?? [];

    if (is_array($rows)) {
        $departments = $rows;
    }
} elseif ($message === "") {
    $message = $listResult["message"]
        ?? "Unable to load departments.";
    $messageType = "error";
}

function departmentEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
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
    <title>Manage Departments | EduTracker</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6fb;
            color: #172033;
        }

        header {
            background: #172554;
            color: white;
            padding: 20px 6%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        header h1 {
            margin: 0;
            font-size: 23px;
        }

        header a {
            color: white;
            text-decoration: none;
            background: #334b85;
            padding: 10px 14px;
            border-radius: 7px;
        }

        main {
            width: min(1000px, 92%);
            margin: 32px auto;
        }

        .panel {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 5px 20px rgba(20, 35, 70, 0.07);
        }

        h2 {
            margin-top: 0;
            font-size: 21px;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 15px;
            margin-bottom: 14px;
        }

        button {
            border: 0;
            background: #2563eb;
            color: white;
            padding: 12px 18px;
            border-radius: 7px;
            cursor: pointer;
            font-size: 15px;
        }

        button:hover {
            background: #1d4ed8;
        }

        .message {
            padding: 13px 15px;
            border-radius: 7px;
            margin-bottom: 20px;
            overflow-wrap: anywhere;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .info {
            background: #dbeafe;
            color: #1e40af;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 13px 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: #f8fafc;
        }

        .empty {
            color: #64748b;
            padding: 18px 0;
        }

        @media (max-width: 600px) {
            .panel {
                padding: 17px;
            }

            header h1 {
                font-size: 20px;
            }
        }
    </style>
</head>

<body>
<header>
    <h1>EduTracker — Department Management</h1>
    <a href="admin-dashboard.php">← Admin Dashboard</a>
</header>

<main>

    <?php if ($message !== ""): ?>
        <div class="message <?= departmentEscape($messageType) ?>">
            <?= departmentEscape($message) ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2>Add Department</h2>

        <form method="POST" action="manage-departments.php">
            <label for="department_name">Department Name</label>

            <input
                type="text"
                id="department_name"
                name="department_name"
                maxlength="100"
                placeholder="Example: CSE"
                required
            >

            <button type="submit">Add Department</button>
        </form>
    </section>

    <section class="panel">
        <h2>Existing Departments</h2>

        <?php if (count($departments) > 0): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Department Name</th>
                            <th>Created At</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($departments as $department): ?>
                            <tr>
                                <td>
                                    <?= departmentEscape($department["id"] ?? "") ?>
                                </td>

                                <td>
                                    <?= departmentEscape(
                                        $department["department_name"] ?? ""
                                    ) ?>
                                </td>

                                <td>
                                    <?= departmentEscape(
                                        $department["created_at"] ?? "—"
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">
                No departments were returned by the API.
                Add a department above or check the Express API response.
            </p>
        <?php endif; ?>
    </section>

</main>
</body>
</html>
