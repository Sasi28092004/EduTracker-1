const express = require("express");
const router = express.Router();

const classController = require("../controllers/classController");

// ========================================
// CLASS / SECTION ROUTES
// ========================================

// List all classes / sections
router.get("/", classController.getClasses);

// Create a new class / section
router.post("/", classController.createClass);

module.exports = router;
