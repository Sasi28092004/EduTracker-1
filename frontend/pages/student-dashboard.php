<?php

session_start();

require_once __DIR__ . "/../../php/config/database.php";

/* -----------------------------------------

   1. Verify student login

\----------------------------------------- */

if (

    !isset($_SESSION["user_id"]) ||

    !isset($_SESSION["role"]) ||

    $_SESSION["role"] !== "student"

) {

    header("Location: login.php");

    exit;

}



$studentId = (int) $_SESSION["user_id"];

$studentName = trim($_SESSION["name"] ?? "Student");



if ($studentName === "") {

    $studentName = "Student";

}



/* -----------------------------------------

   2. Configuration

\----------------------------------------- */

$apiBase = getApiBaseUrl() . "/students";

$assignments = [];

$error = "";
$uploadError = "";
$uploadSuccess = "";



/* Escape output safely */

function h($value): string

{

    return htmlspecialchars(

        (string) $value,

        ENT_QUOTES,

        "UTF-8"

    );

}



/* -----------------------------------------

   3. Request assignments from Express API

\----------------------------------------- */

function studentApiRequest(string $url): array

{

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



    curl_setopt_array($curl, [

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_CONNECTTIMEOUT => 20,

        CURLOPT_TIMEOUT => 60,

        CURLOPT_HTTPHEADER => [

            "Accept: application/json",

            "x-api-key: " . $apiKey

        ]

    ]);



    $response = curl_exec($curl);

    $httpCode = (int) curl_getinfo(

        $curl,

        CURLINFO_HTTP_CODE

    );



    if ($response === false) {

        error_log(

            "Student dashboard API error: " .

            curl_error($curl)

        );



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

            "message" => "The API returned an invalid response."

        ];

    }



    if ($httpCode < 200 || $httpCode >= 300) {

        return [

            "success" => false,

            "message" => $result["message"]

                ?? "Unable to load assignments."

        ];

    }



    return $result;

}

if (

    $_SERVER["REQUEST_METHOD"] === "POST" &&

    ($_POST["action"] ?? "") === "upload_assignment"

) {

    $assignmentId = (int)($_POST["assignment_id"] ?? 0);



    if (

        $assignmentId <= 0 ||

        !isset($_FILES["assignment_file"]) ||

        $_FILES["assignment_file"]["error"] !== UPLOAD_ERR_OK

    ) {

        $uploadError = "Please select a valid PDF file.";

    } else {



        $file = $_FILES["assignment_file"];
        $extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));

        if ($extension !== "pdf") {
            $uploadError = "Only PDF files are allowed.";
        } elseif ($file["size"] > 10 * 1024 * 1024) {
            $uploadError = "Maximum file size is 10 MB.";
        } else {



            $apiKey = getEduTrackerApiKey();



            $curl = curl_init(

                $apiBase . "/assignments/" .

                $assignmentId . "/submit"

            );



            $postFields = [

                "student_id" => $studentId,

                "assignment_file" => new CURLFile(

                    $file["tmp_name"],

                    "application/pdf",

                    basename($file["name"])

                )

            ];



            curl_setopt_array($curl, [

                CURLOPT_RETURNTRANSFER => true,

                CURLOPT_POST => true,

                CURLOPT_POSTFIELDS => $postFields,

                CURLOPT_HTTPHEADER => [

                    "x-api-key: " . $apiKey

                ],

                CURLOPT_TIMEOUT => 60

            ]);



            $response = curl_exec($curl);

            if ($response === false) {

                error_log("Assignment upload error: " . curl_error($curl));

            }

            $httpCode = curl_getinfo(

                $curl,

                CURLINFO_HTTP_CODE

            );



            curl_close($curl);



            $result = is_string($response)

                ? json_decode($response, true)

                : null;



            if (

                $httpCode >= 200 &&

                $httpCode < 300 &&

                !empty($result["success"])

            ) {

                header(

                    "Location: student-dashboard.php?uploaded=1#assignments"

                );

                exit;

            }



            $error = $result["message"] ??

                "Unable to upload assignment.";

        }

    }

}



if (isset($_GET["uploaded"])) {

    $uploadSuccess = "Assignment uploaded successfully!";

}



/* Load assignments for the logged-in student */

$result = studentApiRequest(

    $apiBase . "/assignments?student_id=" . $studentId

);



if (!empty($result["success"])) {

    $assignments = $result["assignments"] ?? [];



    if (!is_array($assignments)) {

        $assignments = [];

    }

} else {

    $error = $result["message"]

        ?? "Unable to load assignments.";

}


/* AI: predicted risk of missing each open deadline */

$aiRiskByAssignment = [];
$aiModelMode = "";
$aiTop = null;

$aiResult = studentApiRequest(
    getApiBaseUrl() . "/ai/student-risk?student_id=" . $studentId
);

if (!empty($aiResult["success"])) {
    $aiModelMode = $aiResult["model"]["mode"] ?? "";

    foreach (($aiResult["predictions"] ?? []) as $prediction) {
        $aiRiskByAssignment[(int) ($prediction["assignment_id"] ?? 0)] = $prediction;
    }

    $aiTop = $aiResult["predictions"][0] ?? null;
}




/* -----------------------------------------

   4. Calculate statistics

\----------------------------------------- */

$totalAssignments = count($assignments);

$totalCompleted = 0;

$totalOverdue = 0;

$totalPending = 0;

$totalCompletedOnTime = 0;

$totalCompletedWithDeadline = 0;



foreach ($assignments as $assignment) {

    $status = strtolower((string) ($assignment["status"] ?? "pending"));

    $deadline = !empty($assignment["due_date"])

        ? strtotime((string) $assignment["due_date"])

        : false;

    $verifiedAt = !empty($assignment["verified_at"])

        ? strtotime((string) $assignment["verified_at"])

        : false;



    if ($status === "verified") {

        $totalCompleted++;

        if ($deadline !== false) {

            $totalCompletedWithDeadline++;

            // Count as on-time only when verification happened by the deadline.

            if ($verifiedAt !== false && $verifiedAt <= $deadline) {

                $totalCompletedOnTime++;

            }

        }

    } elseif ($deadline !== false && $deadline < time()) {

        $totalOverdue++;

    } else {

        $totalPending++;

    }

}



$completionRate = $totalAssignments > 0

    ? (int) round(($totalCompleted / $totalAssignments) * 100)

    : 0;

$onTimeRate = $totalCompletedWithDeadline > 0

    ? (int) round(($totalCompletedOnTime / $totalCompletedWithDeadline) * 100)

    : 0;

$pendingRate = $totalAssignments > 0

    ? (int) round(($totalPending / $totalAssignments) * 100)

    : 0;

$overdueRate = $totalAssignments > 0

    ? (int) round(($totalOverdue / $totalAssignments) * 100)

    : 0;



if ($totalAssignments === 0) {

    $performanceInsight = "No assignment data is available yet. Your performance metrics will appear when teachers assign work to your enrolled classes.";

} elseif ($totalOverdue > 0) {

    $performanceInsight = "You have {$totalOverdue} overdue assignment" . ($totalOverdue === 1 ? "" : "s") . ". Prioritize these first, then work on upcoming deadlines.";

} elseif ($completionRate >= 80) {

    $performanceInsight = "Great progress: you have completed {$completionRate}% of your assigned work. Keep maintaining this completion rate.";

} else {

    $performanceInsight = "You have completed {$totalCompleted} of {$totalAssignments} assignments ({$completionRate}%). Focus on pending work to improve your completion rate.";

}



/* Student avatar initial */

$studentInitial = strtoupper(

    substr($studentName, 0, 1)

);

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>EduTracker | Student Overview</title>

<style>

:root{--ink:#14213d;--muted:#7b879d;--blue:#5968ee;--blue-dark:#4453d7;--blue-soft:#eef0ff;--line:#e7ebf3;--canvas:#f7f8fc;--green:#14805e;--green-bg:#e5f7ef;--amber:#a85a12;--amber-bg:#fff1e3;--red:#b4233b;--red-bg:#ffeaee;--navy:#17213a;--shadow:0 16px 45px rgba(26,39,76,.055)}

*{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:24px}body{margin:0;background:#fff;color:var(--ink);font-family:Inter,"Segoe UI",Arial,sans-serif;-webkit-font-smoothing:antialiased}a{color:inherit;text-decoration:none}button{font:inherit}

.topbar{height:84px;display:flex;align-items:center;justify-content:space-between;padding:0 clamp(20px,3vw,42px);border-bottom:1px solid #edf0f6;background:#fff;position:sticky;top:0;z-index:20}

.brand{display:flex;align-items:center;gap:14px;font-size:20px;font-weight:800;letter-spacing:-.5px}.brand-mark{width:43px;height:43px;border-radius:13px;display:grid;place-items:center;background:var(--blue);color:#fff;font-size:22px;box-shadow:0 8px 18px #5968ee30}.brand-divider{margin-left:8px;color:#a0a8b8;font-weight:400;font-size:16px;letter-spacing:0}

.top-actions{display:flex;align-items:center;gap:18px}.profile{display:flex;align-items:center;gap:10px}.avatar{width:40px;height:40px;display:grid;place-items:center;border-radius:50%;background:var(--blue-soft);color:var(--blue-dark);font-weight:800}.profile-meta strong{display:block;font-size:13px}.profile-meta span{display:block;color:var(--muted);font-size:11px;margin-top:2px}.logout{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);border-radius:10px;padding:10px 13px;font-size:12px;font-weight:700;color:#5d6880;transition:.2s}.logout:hover{background:#f8f9ff;border-color:#d8ddff;color:var(--blue-dark)}

.layout{display:grid;grid-template-columns:230px minmax(0,1fr);min-height:calc(100vh - 84px);background:var(--canvas)}

.sidebar{padding:34px 20px;background:#fafbfe;border-right:1px solid #edf0f6}.side-label{padding:0 13px;margin-bottom:15px;color:#9aa4b8;font-size:10px;letter-spacing:1.5px;font-weight:800}.side-nav{display:grid;gap:7px}.side-link{display:flex;align-items:center;gap:13px;padding:14px 14px;border-radius:12px;color:#738098;font-size:13px;font-weight:700;transition:background .2s,color .2s,transform .2s}.side-link:hover{background:#f0f2ff;color:var(--blue-dark);transform:translateX(2px)}.side-link.active{background:#e9ecff;color:var(--blue-dark)}.side-icon{width:18px;text-align:center;font-size:17px}.sidebar-note{margin-top:36px;padding:15px 14px;border:1px solid #e8ebf5;border-radius:13px;background:#fff}.sidebar-note strong{display:block;font-size:12px;margin-bottom:5px}.sidebar-note p{margin:0;color:var(--muted);font-size:11px;line-height:1.65}

main{min-width:0;padding:35px clamp(20px,3.2vw,46px) 28px}.welcome{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;margin-bottom:27px}.kicker{display:block;margin-bottom:8px;color:#8994aa;font-size:10px;font-weight:800;letter-spacing:1.8px}.welcome h1{margin:0;font-size:clamp(27px,3vw,35px);letter-spacing:-1.2px;line-height:1.2}.welcome p{margin:9px 0 0;color:var(--muted);font-size:13px;line-height:1.7}.date-chip{flex:0 0 auto;padding:10px 13px;border:1px solid var(--line);border-radius:10px;background:#fff;color:#68748b;font-size:11px;font-weight:700}

.error{margin-bottom:18px;padding:13px 15px;border:1px solid #f4c8cf;border-radius:11px;background:#fff0f2;color:#a51e34;font-size:13px;line-height:1.6}
.success-message{margin-bottom:18px;padding:13px 15px;border:1px solid #b9e6d2;border-radius:11px;background:#eaf8f1;color:#176b4d;font-size:13px;line-height:1.6}.upload-area{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:14px;padding:13px;border:1px dashed #d8deed;border-radius:12px;background:#fbfcff}.upload-area form{display:flex;flex-wrap:wrap;align-items:center;gap:9px}.upload-area input[type=file]{max-width:100%;font-size:11px;color:#667085}.upload-button{border:0;border-radius:9px;padding:10px 14px;background:var(--blue);color:#fff;font-size:11px;font-weight:800;cursor:pointer}.upload-button:hover{background:var(--blue-dark)}.submission-note{font-size:11px;color:#667085;line-height:1.6}.submission-file{display:inline-flex;align-items:center;gap:6px;margin-top:10px;color:var(--blue-dark);font-size:11px;font-weight:800;text-decoration:underline;overflow-wrap:anywhere}

.metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:19px}.metric-card{min-width:0;padding:20px 19px;background:#fff;border:1px solid var(--line);border-radius:15px;box-shadow:0 3px 13px rgba(26,39,76,.025);transition:transform .22s,box-shadow .22s,border-color .22s}.metric-card:hover{transform:translateY(-3px);border-color:#d9deff;box-shadow:var(--shadow)}.metric-top{display:flex;align-items:center;justify-content:space-between;gap:8px}.metric-label{color:#7b879d;font-size:12px;font-weight:700}.metric-icon{width:33px;height:33px;border-radius:10px;display:grid;place-items:center;background:var(--blue-soft);color:var(--blue);font-size:16px}.metric-card strong{display:block;margin-top:11px;font-size:31px;line-height:1.15;letter-spacing:-1px}.metric-foot{display:block;margin-top:7px;color:#8a94a8;font-size:11px}.metric-card.green .metric-icon{background:var(--green-bg);color:var(--green)}.metric-card.amber .metric-icon{background:var(--amber-bg);color:var(--amber)}.metric-card.red .metric-icon{background:var(--red-bg);color:var(--red)}

.panel{background:#fff;border:1px solid var(--line);border-radius:17px;box-shadow:0 3px 14px rgba(26,39,76,.025)}.performance{padding:24px 25px;margin-bottom:19px}.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:21px}.panel-heading h2{margin:0;font-size:17px;letter-spacing:-.4px}.panel-caption{color:#8994aa;font-size:11px}.score-line{display:flex;align-items:flex-end;gap:12px;margin-bottom:24px}.score{font-size:clamp(42px,5vw,58px);font-weight:800;line-height:1;letter-spacing:-2.5px}.score-copy{padding-bottom:4px;color:var(--muted);font-size:12px;line-height:1.55}.score-copy strong{display:block;color:#3f4a62;font-size:11px}

.progress-list{display:grid;gap:17px}.progress-row{display:grid;grid-template-columns:155px minmax(80px,1fr) 45px;align-items:center;gap:15px}.progress-name{font-size:12px;color:#49556c}.track{height:11px;overflow:hidden;border-radius:99px;background:#edf0f6}.fill{height:100%;border-radius:inherit;background:var(--blue);transform-origin:left;animation:grow .9s cubic-bezier(.2,.7,.25,1) both}.fill.green{background:#25a678}.fill.amber{background:#ed9b3d}.fill.red{background:#d94d62}.progress-value{text-align:right;font-size:12px;font-weight:800;color:#28334c}

.ai-risk{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:10px;padding:9px 11px;border-radius:10px;background:#f6f7ff;font-size:11px;color:#56627c}.ai-risk strong{color:#4e5fdc}.ai-risk .lvl{padding:3px 8px;border-radius:99px;font-weight:800;font-size:9px;letter-spacing:.3px}.ai-risk .lvl.high{background:var(--red-bg);color:var(--red)}.ai-risk .lvl.medium{background:var(--amber-bg);color:var(--amber)}.ai-risk .lvl.low{background:var(--green-bg);color:var(--green)}.ai-risk .why{flex-basis:100%;color:#7a859b}
.insight{display:flex;align-items:flex-start;gap:12px;margin-top:24px;padding:16px 17px;border-radius:12px;background:#f1f3ff;color:#63718f;font-size:12px;line-height:1.7}.spark{flex:0 0 auto;color:var(--blue);font-size:19px}.insight strong{display:block;color:#4e5fdc;margin-bottom:2px;font-size:12px}.data-note{margin:10px 0 0;color:#9aa3b4;font-size:10px;line-height:1.6}

.assignments{padding:24px 25px}.assignment-list{display:grid;gap:0}.assignment-item{display:flex;align-items:flex-start;gap:13px;padding:17px 0;border-top:1px solid #eff1f6;transition:background .2s}.assignment-item:first-child{border-top:0;padding-top:0}.assignment-symbol{flex:0 0 39px;width:39px;height:39px;display:grid;place-items:center;border-radius:12px;background:var(--blue-soft);color:var(--blue);font-size:17px}.assignment-body{flex:1;min-width:0}.assignment-title-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.assignment-title{font-size:13px;font-weight:800;line-height:1.5;overflow-wrap:anywhere}.assignment-desc{margin-top:4px;color:#8791a5;font-size:11px;line-height:1.6;overflow-wrap:anywhere}.assignment-meta{display:flex;flex-wrap:wrap;gap:13px;margin-top:9px;color:#8993a7;font-size:10px}.badge{flex:0 0 auto;display:inline-flex;align-items:center;gap:5px;padding:6px 9px;border-radius:999px;font-size:9px;letter-spacing:.35px;font-weight:800;white-space:nowrap}.badge.urgent{background:var(--red-bg);color:var(--red)}.badge.upcoming{background:var(--amber-bg);color:var(--amber)}.badge.ontrack{background:var(--green-bg);color:var(--green)}.badge.overdue{background:#9f2337;color:#fff}.badge.completed{background:var(--green-bg);color:var(--green)}.badge.nodate{background:#edf0f5;color:#657086}

.empty{text-align:center;padding:38px 16px}.empty-icon{display:grid;place-items:center;width:49px;height:49px;margin:0 auto 13px;border-radius:14px;background:var(--blue-soft);color:var(--blue);font-size:22px}.empty h3{margin:0 0 7px;font-size:15px}.empty p{margin:0;color:var(--muted);font-size:12px;line-height:1.7}

.footer{display:flex;justify-content:space-between;gap:15px;padding:20px 2px 0;color:#98a1b2;font-size:10px}.footer strong{color:var(--blue);font-weight:800}

@keyframes grow{from{transform:scaleX(.02)}to{transform:scaleX(1)}}@keyframes enter{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

.metric-card,.performance,.assignments{animation:enter .55s both}.metric-card:nth-child(2){animation-delay:.06s}.metric-card:nth-child(3){animation-delay:.12s}.performance{animation-delay:.15s}.assignments{animation-delay:.2s}

@media(max-width:1000px){.layout{grid-template-columns:190px minmax(0,1fr)}.sidebar{padding:28px 13px}.progress-row{grid-template-columns:130px minmax(70px,1fr) 42px}.metrics{gap:10px}.metric-card{padding:17px 15px}}

@media(max-width:760px){.topbar{height:72px;padding:0 17px}.brand{font-size:17px;gap:9px}.brand-mark{width:37px;height:37px}.brand-divider{display:none}.profile-meta{display:none}.top-actions{gap:10px}.logout{padding:9px 10px}.layout{display:block}.sidebar{padding:10px 15px;border-right:0;border-bottom:1px solid var(--line);overflow-x:auto}.side-label,.sidebar-note{display:none}.side-nav{display:flex;min-width:max-content;gap:6px}.side-link{padding:10px 12px;font-size:11px}.main{padding:24px 16px}main{padding:24px 16px}.welcome{align-items:flex-start;flex-direction:column;margin-bottom:20px}.date-chip{display:none}.metrics{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.metric-card{padding:13px 11px;border-radius:12px}.metric-label{font-size:10px}.metric-icon{width:27px;height:27px;font-size:13px}.metric-card strong{font-size:25px}.metric-foot{font-size:9px}.performance,.assignments{padding:18px 16px}.progress-row{grid-template-columns:115px minmax(50px,1fr) 36px;gap:9px}.progress-name,.progress-value{font-size:10px}.track{height:9px}.assignment-title-row{flex-direction:column;gap:7px}.assignment-meta{gap:8px}.footer{flex-direction:column}}

@media(max-width:420px){.metrics{grid-template-columns:1fr}.metric-card{display:grid;grid-template-columns:1fr auto;align-items:center}.metric-top{grid-column:1/-1}.metric-card strong{margin-top:8px}.metric-foot{margin-top:0;text-align:right}.progress-row{grid-template-columns:95px minmax(40px,1fr) 32px;gap:7px}.score{font-size:44px}.panel-heading h2{font-size:15px}.panel-caption{font-size:9px}}

@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;scroll-behavior:auto!important;transition:none!important}}

</style>

</head>

<body>

<header class="topbar">

  <a class="brand" href="student-dashboard.php"><span class="brand-mark">E</span><span>EduTracker</span></a>

  <div class="top-actions">

    <div class="profile"><div class="avatar"><?= h($studentInitial) ?></div><div class="profile-meta"><strong><?= h($studentName) ?></strong><span>Student account</span></div></div>

    <a class="logout" href="../../logout.php">Logout <span aria-hidden="true">↗</span></a>

  </div>

</header>

<div class="layout">

  <aside class="sidebar">

    <div class="side-label">WORKSPACE</div>

    <nav class="side-nav" aria-label="Student dashboard navigation">

      <a class="side-link active" href="#overview"><span class="side-icon">⌂</span>Overview</a>

      <a class="side-link" href="#assignments"><span class="side-icon">▤</span>Assignments</a>

      <a class="side-link" href="#deadlines"><span class="side-icon">◷</span>Deadlines</a>

      <a class="side-link" href="#progress"><span class="side-icon">✓</span>Progress</a>

    </nav>

    <div class="sidebar-note"><strong>Stay on track</strong><p>Check urgent deadlines first, then work through upcoming assignments.</p></div>

  </aside>



  <main id="overview">

    <section class="welcome">

      <div><span class="kicker">YOUR LEARNING SPACE</span><h1>Assignment overview</h1><p>Welcome back, <?= h($studentName) ?>. Here’s the latest view of your assigned work.</p></div>

      <div class="date-chip"><?= h(date("D, d M Y")) ?></div>

    </section>



    <?php if ($error !== ""): ?><div class="error"><strong>We couldn’t load your assignments.</strong> <?= h($error) ?> Please check that the API server is running and refresh this page.</div><?php endif; ?>
      <?php if ($uploadSuccess !== ""): ?><div class="success-message" role="status"><?= h($uploadSuccess) ?></div><?php endif; ?>
      <?php if ($uploadError !== ""): ?><div class="error" role="alert"><strong>Upload failed.</strong> <?= h($uploadError) ?></div><?php endif; ?>



    <section class="metrics" aria-label="Assignment summary">

      <article class="metric-card"><div class="metric-top"><span class="metric-label">Total assignments</span><span class="metric-icon">▤</span></div><strong><?= (int)$totalAssignments ?></strong><span class="metric-foot">Assigned to your classes</span></article>

      <article class="metric-card green"><div class="metric-top"><span class="metric-label">Verified</span><span class="metric-icon">✓</span></div><strong><?= (int)$totalCompleted ?></strong><span class="metric-foot">Reviewed by your teacher</span></article>

      <article class="metric-card amber"><div class="metric-top"><span class="metric-label">Need attention</span><span class="metric-icon">◷</span></div><strong><?= (int)($totalPending + $totalOverdue) ?></strong><span class="metric-foot"><?= (int)$totalPending ?> pending · <?= (int)$totalOverdue ?> overdue</span></article>

    </section>



    <section class="panel performance" id="progress" aria-labelledby="performance-title">

      <div class="panel-heading"><h2 id="performance-title">Class performance</h2><span class="panel-caption">Based on assignment records</span></div>

      <div class="score-line"><div class="score"><?= $totalAssignments > 0 ? (int)$completionRate . '%' : '—' ?></div><div class="score-copy"><strong>Assignment completion</strong><?= (int)$totalCompleted ?> of <?= (int)$totalAssignments ?> assignments verified</div></div>

      <?php

        $performanceMetrics = [

          ['name' => 'Verified', 'value' => $completionRate, 'class' => ''],

          ['name' => 'Completed on time', 'value' => $onTimeRate, 'class' => 'green'],

          ['name' => 'Pending', 'value' => $pendingRate, 'class' => 'amber'],

          ['name' => 'Overdue', 'value' => $overdueRate, 'class' => 'red'],

        ];

      ?>

      <div class="progress-list">

      <?php foreach ($performanceMetrics as $metric): $metricValue = max(0, min(100, (int)$metric['value'])); ?>

        <div class="progress-row"><span class="progress-name"><?= h($metric['name']) ?></span><div class="track" role="progressbar" aria-label="<?= h($metric['name']) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $metricValue ?>"><div class="fill <?= h($metric['class']) ?>" style="width:<?= $metricValue ?>%"></div></div><strong class="progress-value"><?= $metricValue ?>%</strong></div>

      <?php endforeach; ?>

      </div>

      <div class="insight"><span class="spark">✦</span><div><strong>Student insight</strong><?= h($performanceInsight) ?></div></div>

      <?php if ($aiTop !== null): ?>
      <div class="insight"><span class="spark">✦</span><div><strong>AI assistant</strong><?php
        $aiPct = (int) round(((float) ($aiTop["probability"] ?? 0)) * 100);
        if (($aiTop["level"] ?? "low") === "low") {
            echo h("All your open assignments look on track (highest predicted risk {$aiPct}%).");
        } else {
            echo h("\"" . ($aiTop["title"] ?? "An assignment") . "\" has the highest predicted risk of being missed ({$aiPct}%). " . ($aiTop["suggestion"] ?? ""));
        }
      ?></div></div>
      <?php endif; ?>

      <p class="data-note">Metrics are calculated from your current assignment records. On-time completion is only counted when a valid verification timestamp and deadline are available. Attendance, exam marks and project scores are not included because they are not currently stored in the connected data.</p>

    </section>



    <section class="panel assignments" id="assignments">

      <div class="panel-heading"><h2>Assignment deadlines</h2><span class="panel-caption"><?= (int)$totalAssignments ?> total</span></div>

      <?php if ($error !== ""): ?>

        <div class="empty"><div class="empty-icon">!</div><h3>Assignments unavailable</h3><p>Please check the API server and refresh this page.</p></div>

      <?php elseif (empty($assignments)): ?>

        <div class="empty"><div class="empty-icon">▤</div><h3>No assignments yet</h3><p>When your teachers create assignments for your enrolled classes, they will appear here.</p></div>

      <?php else: ?>

      <div class="assignment-list" id="deadlines">

        <?php foreach ($assignments as $assignment): ?>

          <?php

            $status = strtolower((string)($assignment['status'] ?? 'pending'));

            $deadline = !empty($assignment['due_date']) ? strtotime((string)$assignment['due_date']) : false;

            $isCompleted = $status === 'verified';

            $isOverdue = !$isCompleted && $deadline !== false && $deadline < time();

            if ($isCompleted) { $priorityClass = 'completed'; $priorityLabel = '✓ COMPLETED'; }

            elseif ($isOverdue) { $priorityClass = 'overdue'; $priorityLabel = '! OVERDUE'; }

            elseif ($deadline === false) { $priorityClass = 'nodate'; $priorityLabel = 'NO DEADLINE'; }

            else {

              $remainingSeconds = $deadline - time();

              if ($remainingSeconds <= 24 * 60 * 60) { $priorityClass = 'urgent'; $priorityLabel = 'URGENT'; }

              elseif ($remainingSeconds <= 3 * 24 * 60 * 60) { $priorityClass = 'upcoming'; $priorityLabel = 'UPCOMING'; }

              else { $priorityClass = 'ontrack'; $priorityLabel = 'ON TRACK'; }

            }

          ?>

          <article class="assignment-item">

            <div class="assignment-symbol"><?= $isCompleted ? '✓' : '▤' ?></div>

            <div class="assignment-body">

              <div class="assignment-title-row"><div class="assignment-title"><?= h($assignment['title'] ?? 'Assignment') ?></div><span class="badge <?= h($priorityClass) ?>"><?= h($priorityLabel) ?></span></div>

              <?php if (!empty($assignment['description'])): ?><p class="assignment-desc"><?= nl2br(h($assignment['description'])) ?></p><?php endif; ?>

              <div class="assignment-meta">

                <span><?= h($assignment['class_name'] ?? 'Class not specified') ?></span>

                <span><?= h($assignment['subject'] ?? 'Subject not specified') ?></span>

                <span>Due: <?= $deadline !== false ? h(date('d M Y, h:i A', $deadline)) : 'Not specified' ?></span>

              </div>

              <?php $aiRisk = $aiRiskByAssignment[(int) ($assignment['id'] ?? $assignment['assignment_id'] ?? 0)] ?? null; ?>
              <?php if ($aiRisk !== null && !$isCompleted): $aiLvl = $aiRisk['level'] ?? 'low'; ?>
              <div class="ai-risk">
                <strong>✦ AI prediction</strong>
                <span class="lvl <?= h($aiLvl) ?>"><?= h(strtoupper($aiLvl)) ?> RISK · <?= (int) round(((float) $aiRisk['probability']) * 100) ?>%</span>
                <span><?= h($aiRisk['suggestion'] ?? '') ?></span>
                <?php if (!empty($aiRisk['reasons'])): ?><span class="why">Based on: <?= h(implode(' · ', $aiRisk['reasons'])) ?></span><?php endif; ?>
              </div>
              <?php endif; ?>

              <?php
                $submissionStatus = strtolower((string)($assignment['status'] ?? 'pending'));
                $submissionFile = trim((string)($assignment['file_path'] ?? ''));
                $submittedAt = !empty($assignment['submitted_at'])
                    ? strtotime((string)$assignment['submitted_at'])
                    : false;
                $isVerified = $submissionStatus === 'verified';
                $fileUrl = $submissionFile !== ''
                    ? getFileViewUrl($submissionFile)
                    : '';
              ?>

              <div class="upload-area">
                <div style="flex:1;min-width:190px">
                  <?php if ($isVerified): ?>
                    <div class="submission-note"><strong>✓ Verified by teacher</strong><?php if ($submittedAt !== false): ?> · Submitted <?= h(date('d M Y, h:i A', $submittedAt)) ?><?php endif; ?></div>
                    <?php if (isset($assignment['marks_obtained']) && $assignment['marks_obtained'] !== null): ?>
                      <div class="submission-note"><strong>Marks:</strong> <?= h(rtrim(rtrim(number_format((float)$assignment['marks_obtained'], 2, '.', ''), '0'), '.')) ?> / <?= h(rtrim(rtrim(number_format((float)($assignment['max_marks'] ?? 100), 2, '.', ''), '0'), '.')) ?><?php if (!empty($assignment['teacher_feedback'])): ?> · <strong>Feedback:</strong> <?= h($assignment['teacher_feedback']) ?><?php endif; ?></div>
                    <?php endif; ?>
                  <?php elseif ($submissionFile !== ''): ?>
                    <div class="submission-note"><strong>PDF submitted — awaiting teacher review</strong><?php if ($submittedAt !== false): ?> · <?= h(date('d M Y, h:i A', $submittedAt)) ?><?php endif; ?></div>
                  <?php else: ?>
                    <div class="submission-note"><strong>No file submitted yet.</strong> Upload your assignment as a PDF (maximum 10 MB).</div>
                  <?php endif; ?>
                  <?php if ($submissionFile !== ''): ?>
                    <a class="submission-file" href="<?= h($fileUrl) ?>" target="_blank" rel="noopener noreferrer">View uploaded PDF ↗</a>
                  <?php endif; ?>
                </div>

                <?php if (!$isVerified): ?>
                  <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_assignment">
                    <input type="hidden" name="assignment_id" value="<?= (int)($assignment['assignment_id'] ?? $assignment['id'] ?? 0) ?>">
                    <input type="file" name="assignment_file" accept=".pdf,application/pdf" required>
                    <button class="upload-button" type="submit"><?= $submissionFile !== '' ? 'Replace PDF' : 'Upload PDF' ?></button>
                  </form>
                <?php endif; ?>
              </div>

            </div>

          </article>

        <?php endforeach; ?>

      </div>

      <?php endif; ?>

    </section>



    <footer class="footer"><strong>EduTracker</strong><span>Stay organised. Keep progressing.</span></footer>

  </main>

</div>

<script>

  // Keep the workspace navigation's active item in sync with the selected section.

  const sideLinks = document.querySelectorAll('.side-link');

  sideLinks.forEach(link => link.addEventListener('click', () => {

    sideLinks.forEach(item => item.classList.remove('active'));

    link.classList.add('active');

  }));

</script>

</body>

</html>
