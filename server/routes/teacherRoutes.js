
const express = require("express");
const router = express.Router();

const teacherController = require("../controllers/teacherController");

// Create a teacher account.
router.post("/", teacherController.createTeacher);

// Get all teachers for the admin assignment page.
router.get("/", teacherController.getAllTeachers);

// Assign one teacher to multiple sections.
router.post(
    "/assign-sections",
    teacherController.assignSections
);

// Get classes assigned to a teacher.
router.get("/classes", teacherController.getMyClasses);

// Create an assignment for an assigned class.
router.post("/assignments", teacherController.createAssignment);

// Get assignments and completion counts for a class.
router.get(
    "/classes/:classId/assignments",
    teacherController.getClassAssignments
);

// Get student progress for an assignment.
router.get(
    "/assignments/:assignmentId/progress",
    teacherController.getAssignmentProgress
);

// Verify or return a student's assignment.
router.patch(
    "/assignments/:assignmentId/students/:studentId/status",
    teacherController.updateStudentStatus
);
// Grade a student's digital assignment
router.patch(
    "/assignments/:assignmentId/students/:studentId/grade",
    teacherController.gradeAssignment
);

module.exports = router;
