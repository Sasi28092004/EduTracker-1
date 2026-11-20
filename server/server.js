
// --------------------------------------------------
// IMPORT PACKAGES
// --------------------------------------------------
const express = require("express");
const cors = require("cors");
const path = require("path");
const fs = require("fs");

// --------------------------------------------------
// ENVIRONMENT CONFIGURATION
// --------------------------------------------------
require("dotenv").config({
    path: path.join(__dirname, ".env")
});

// --------------------------------------------------
// MONGODB ATLAS CONNECTION (shared by every controller)
// --------------------------------------------------
const { connectDB } = require("./config/db");

// --------------------------------------------------
// IMPORT ROUTES
// --------------------------------------------------
const authRoutes = require("./routes/authRoutes");
const studentRoutes = require("./routes/studentRoutes");
const teacherRoutes = require("./routes/teacherRoutes");
const classRoutes = require("./routes/classRoutes");
const departmentRoutes = require("./routes/departmentRoutes");
const aiRoutes = require("./routes/aiRoutes");

// --------------------------------------------------
// IMPORT DEADLINE REMINDER SCHEDULER
// --------------------------------------------------
const {
    startDeadlineReminderScheduler
} = require("./utils/deadlineReminder");

// --------------------------------------------------
// IMPORT AI SCHEDULER (risk model training + early reminders)
// --------------------------------------------------
const { startAiScheduler } = require("./ai/riskReminder");

// --------------------------------------------------
// INITIALIZE EXPRESS
// --------------------------------------------------
const app = express();
const PORT = process.env.PORT || 3000;

// --------------------------------------------------
// MIDDLEWARE
// --------------------------------------------------
app.use(cors());

app.use(express.json({
    limit: "1mb"
}));

app.use(express.urlencoded({
    extended: true,
    limit: "1mb"
}));

// --------------------------------------------------
// ASSIGNMENT UPLOAD FOLDER
// --------------------------------------------------
const assignmentUploadDir = path.join(
    __dirname,
    "uploads",
    "assignments"
);

fs.mkdirSync(assignmentUploadDir, {
    recursive: true
});

// Make uploaded files accessible
app.use(
    "/uploads/assignments",
    express.static(assignmentUploadDir)
);

// --------------------------------------------------
// ROOT
// The PHP frontend (frontend/ + php/) is served by Apache
// (XAMPP locally, the Docker service on Render). This Node
// service only exposes the JSON API and uploaded PDFs.
// --------------------------------------------------
app.get("/", (req, res) => {
    // On Render, set FRONTEND_URL to the website service's URL
    // (e.g. https://edutracker-web.onrender.com) so opening the API's
    // address takes people to the real pages.
    const frontendUrl = String(process.env.FRONTEND_URL || "").trim();

    if (/^https?:\/\//i.test(frontendUrl)) {
        return res.redirect(302, frontendUrl);
    }

    res.json({
        success: true,
        message: "EduTracker API is running.",
        health: "/api/health",
        note: "This is the API. Open the website service's URL to see the pages."
    });
});

// --------------------------------------------------
// API KEY AUTHENTICATION
// --------------------------------------------------
function verifyApiKey(req, res, next) {

    const configuredKey = process.env.API_KEY;
    const providedKey = req.get("x-api-key");

    if (!configuredKey) {

        console.error(
            "API_KEY is missing from server/.env"
        );

        return res.status(500).json({
            success: false,
            message: "API authentication is not configured."
        });
    }

    if (
        !providedKey ||
        providedKey !== configuredKey
    ) {

        return res.status(401).json({
            success: false,
            message: "Unauthorized API request."
        });
    }

    next();
}

// --------------------------------------------------
// API HEALTH CHECK
// --------------------------------------------------
app.get("/api/health", (req, res) => {

    res.status(200).json({
        success: true,
        message: "EduTracker Express API is running.",
        database: "MongoDB Atlas"
    });

});

// --------------------------------------------------
// AUTHENTICATION ROUTES
// --------------------------------------------------
app.use(
    "/api/auth",
    authRoutes
);

// --------------------------------------------------
// STUDENT ROUTES
// --------------------------------------------------
app.use(
    "/api/students",
    verifyApiKey,
    studentRoutes
);

// --------------------------------------------------
// TEACHER ROUTES
// --------------------------------------------------
app.use(
    "/api/teachers",
    verifyApiKey,
    teacherRoutes
);

// --------------------------------------------------
// CLASS / SECTION ROUTES
// --------------------------------------------------
app.use(
    "/api/classes",
    verifyApiKey,
    classRoutes
);

// --------------------------------------------------
// DEPARTMENT ROUTES
// --------------------------------------------------
app.use(
    "/api/departments",
    verifyApiKey,
    departmentRoutes
);

// --------------------------------------------------
// AI ROUTES (deadline risk prediction)
// --------------------------------------------------
app.use(
    "/api/ai",
    verifyApiKey,
    aiRoutes
);

// --------------------------------------------------
// UNKNOWN ROUTE HANDLER
// --------------------------------------------------
app.use((req, res) => {

    res.status(404).json({
        success: false,
        message: "Route not found.",
        path: req.originalUrl
    });

});

// --------------------------------------------------
// CENTRAL ERROR HANDLER
// --------------------------------------------------
app.use((err, req, res, next) => {

    if (res.headersSent) {
        return next(err);
    }

    // Upload validation errors (multer / PDF filter) -> 400
    if (
        err &&
        (err.name === "MulterError" ||
            err.message === "Only PDF files are allowed.")
    ) {
        return res.status(400).json({
            success: false,
            message: err.code === "LIMIT_FILE_SIZE"
                ? "Maximum file size is 10 MB."
                : err.message
        });
    }

    console.error(
        "EduTracker API error:",
        err
    );

    res.status(500).json({
        success: false,
        message: "An internal server error occurred."
    });

});

// --------------------------------------------------
// START SERVER AFTER DATABASE CONNECTION
// --------------------------------------------------
async function startServer() {

    try {

        // Connect to MongoDB Atlas, create indexes and seed the admin account
        await connectDB();

        console.log(
            "Database ready."
        );

        // Start Express server
        const server = app.listen(
            PORT,
            "0.0.0.0",
            () => {

                console.log(
                    `EduTracker server running on port ${PORT}`
                );

                console.log(
                    `API Health: http://localhost:${PORT}/api/health`
                );

                // Start deadline reminder scheduler
                try {

                    startDeadlineReminderScheduler();

                    console.log(
                        "Deadline reminder scheduler started."
                    );

                } catch (error) {

                    console.error(
                        "Scheduler error:",
                        error.message
                    );

                }

                // Start AI risk model and early reminders
                try {

                    startAiScheduler();

                } catch (error) {

                    console.error(
                        "AI scheduler error:",
                        error.message
                    );

                }

            }
        );

        server.on("error", (error) => {

            console.error(
                "Server startup error:",
                error.message
            );

            process.exit(1);

        });

    } catch (error) {

        console.error(
            "Failed to start EduTracker:",
            error.message
        );

        process.exit(1);

    }

}

// --------------------------------------------------
// RUN APPLICATION
// --------------------------------------------------
startServer();
