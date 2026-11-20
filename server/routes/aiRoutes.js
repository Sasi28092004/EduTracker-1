const express = require("express");
const router = express.Router();

const aiController = require("../controllers/aiController");

// ========================================
// AI ROUTES (deadline risk prediction)
// ========================================

// Model status, weights and hold-out metrics
router.get("/model", aiController.getModelInfo);

// Retrain the model on the latest data
router.post("/train", aiController.retrain);

// Risk for each open assignment of a student
router.get("/student-risk", aiController.getStudentRisk);

// Risk for students in a teacher's sections
router.get("/teacher-risk", aiController.getTeacherRisk);

module.exports = router;
