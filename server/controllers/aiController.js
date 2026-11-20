// ==================================================
// EduTracker AI - API controller
// ==================================================
const { getDB } = require("../config/db");
const {
    trainModel,
    getModel,
    summary,
    predictForStudent,
    predictForAssignments
} = require("../ai/riskModel");

function toPositiveInt(value) {
    const number = Number(value);
    return Number.isInteger(number) && number > 0 ? number : null;
}

// GET /api/ai/model
exports.getModelInfo = async (req, res) => {
    try {
        return res.json({ success: true, model: summary(await getModel()) });
    } catch (error) {
        console.error("AI model info error:", error);
        return res.status(500).json({ success: false, message: "Unable to load the AI model." });
    }
};

// POST /api/ai/train
exports.retrain = async (req, res) => {
    try {
        const model = await trainModel();
        return res.json({ success: true, message: "AI model retrained.", model: summary(model) });
    } catch (error) {
        console.error("AI training error:", error);
        return res.status(500).json({ success: false, message: "Unable to train the AI model." });
    }
};

// GET /api/ai/student-risk?student_id=
exports.getStudentRisk = async (req, res) => {
    try {
        const studentId = toPositiveInt(req.query.student_id);

        if (!studentId) {
            return res.status(400).json({ success: false, message: "A valid student ID is required." });
        }

        const student = await getDB().collection("users").findOne({ id: studentId, role: "student" });

        if (!student) {
            return res.status(404).json({ success: false, message: "Student not found." });
        }

        const result = await predictForStudent(studentId);
        return res.json({ success: true, ...result });
    } catch (error) {
        console.error("AI student risk error:", error);
        return res.status(500).json({ success: false, message: "Unable to calculate risk." });
    }
};

// GET /api/ai/teacher-risk?teacher_id=   (only the teacher's own sections)
exports.getTeacherRisk = async (req, res) => {
    try {
        const teacherId = toPositiveInt(req.query.teacher_id);
        const db = getDB();

        const teacher = teacherId
            ? await db.collection("users").findOne({ id: teacherId, role: "teacher" })
            : null;

        if (!teacher) {
            return res.status(403).json({ success: false, message: "A valid teacher account is required." });
        }

        const allocations = await db.collection("teacher_classes").find({ teacher_id: teacherId }).toArray();
        const classIds = new Set(allocations.map((a) => a.class_id));

        const result = await predictForAssignments(
            (a) => a.teacher_id === teacherId && classIds.has(a.class_id)
        );

        const ids = [...new Set(result.predictions.map((p) => p.student_id))];
        const students = ids.length
            ? await db.collection("users").find({ id: { $in: ids } }).toArray()
            : [];
        const byId = new Map(students.map((s) => [s.id, s]));

        const predictions = result.predictions.map((p) => ({
            ...p,
            student_name: byId.get(p.student_id)?.name || "Student",
            roll_number: byId.get(p.student_id)?.roll_number || ""
        }));

        const counts = { high: 0, medium: 0, low: 0 };
        predictions.forEach((p) => { counts[p.level]++; });

        return res.json({ success: true, model: result.model, counts, predictions });
    } catch (error) {
        console.error("AI teacher risk error:", error);
        return res.status(500).json({ success: false, message: "Unable to calculate risk." });
    }
};
