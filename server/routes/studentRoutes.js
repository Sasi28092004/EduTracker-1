
const express = require("express");
const router = express.Router();

const studentController = require("../controllers/studentController");

const multer = require("multer");
const path = require("path");
const fs = require("fs");
const crypto = require("crypto");

// ================================================
// ASSIGNMENT UPLOAD DIRECTORY
// ================================================

const uploadDir = path.join(
    __dirname,
    "..",
    "uploads",
    "assignments"
);

fs.mkdirSync(uploadDir, { recursive: true });

// ================================================
// FILE STORAGE CONFIGURATION
// ================================================

const storage = multer.diskStorage({
    destination: function (req, file, cb) {
        cb(null, uploadDir);
    },

    filename: function (req, file, cb) {
        const filename =
            `assignment-${Date.now()}-${crypto.randomUUID()}.pdf`;

        cb(null, filename);
    }
});

// ================================================
// PDF FILE VALIDATION
// Maximum file size: 10 MB
// ================================================

const upload = multer({
    storage: storage,

    limits: {
        fileSize: 10 * 1024 * 1024,
        files: 1
    },

    fileFilter: function (req, file, cb) {
        const extension = path.extname(
            file.originalname
        ).toLowerCase();

        // Some browsers / PHP cURL send PDFs as application/octet-stream.
        // The PDF signature is verified again in the controller.
        if (
            extension === ".pdf" &&
            ["application/pdf", "application/octet-stream", "application/x-pdf"]
                .includes(file.mimetype)
        ) {
            cb(null, true);
        } else {
            cb(
                new Error("Only PDF files are allowed."),
                false
            );
        }
    }
});

// ================================================
// STUDENT ROUTES
// ================================================

// Create a student
router.post(
    "/",
    studentController.createStudent
);

// List registered students
router.get(
    "/",
    studentController.getAllStudents
);

// List sections and available seats
router.get(
    "/classes",
    studentController.getAvailableClasses
);

// Enroll a student
router.post(
    "/enroll",
    studentController.enrollStudent
);

// Get assignments for a student
router.get(
    "/assignments",
    studentController.getMyAssignments
);

// ================================================
// ASSIGNMENT SUBMISSION ROUTES
// ================================================

// Upload assignment PDF
router.post(
    "/assignments/:assignmentId/submit",
    upload.single("assignment_file"),
    studentController.submitAssignment
);

// Get student's submission and evaluation
router.get(
    "/assignments/:assignmentId/submission",
    studentController.getMySubmission
);

module.exports = router;
