const { getDB, nextId, isDuplicateKey } = require("../config/db");

// Count active students per class id -> Map(classId => count)
async function activeEnrollmentCounts() {
    const enrollments = await getDB().collection("student_classes")
        .find({ status: "active" }, { projection: { _id: 0, class_id: 1 } })
        .toArray();

    const counts = new Map();

    for (const row of enrollments) {
        counts.set(row.class_id, (counts.get(row.class_id) || 0) + 1);
    }

    return counts;
}

exports.activeEnrollmentCounts = activeEnrollmentCounts;

// Create a section.
exports.createClass = async (req, res) => {
    try {
        const {
            department,
            section_code,
            class_name,
            subject,
            capacity
        } = req.body;

        if (
            !department ||
            !section_code ||
            !class_name ||
            !subject
        ) {
            return res.status(400).json({
                success: false,
                message: "Department, section, class name and subject are required."
            });
        }

        const studentCapacity = Number(capacity || 60);

        if (
            !Number.isInteger(studentCapacity) ||
            studentCapacity < 1 ||
            studentCapacity > 60
        ) {
            return res.status(400).json({
                success: false,
                message: "Section capacity must be between 1 and 60."
            });
        }

        const classes = getDB().collection("classes");

        const newClass = {
            department: String(department).trim(),
            section_code: String(section_code).trim(),
            class_name: String(class_name).trim(),
            subject: String(subject).trim()
        };

        // Check for a duplicate section.
        const existing = await classes.findOne({
            department: newClass.department,
            section_code: newClass.section_code,
            subject: newClass.subject
        });

        if (existing) {
            return res.status(409).json({
                success: false,
                message: "This section already exists for that department and subject."
            });
        }

        const adminId = Number(req.body.admin_id);
        const classId = await nextId("classes");

        await classes.insertOne({
            id: classId,
            ...newClass,
            capacity: studentCapacity,
            created_by: Number.isInteger(adminId) && adminId > 0 ? adminId : null,
            created_at: new Date()
        });

        return res.status(201).json({
            success: true,
            message: "Section created successfully.",
            classId
        });
    } catch (error) {
        if (isDuplicateKey(error)) {
            return res.status(409).json({
                success: false,
                message: "This section already exists for that department and subject."
            });
        }

        console.error("Create section error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to create section."
        });
    }
};

// List sections.
exports.getClasses = async (req, res) => {
    try {
        const classes = await getDB().collection("classes")
            .find({})
            .sort({ department: 1, section_code: 1 })
            .toArray();

        const counts = await activeEnrollmentCounts();

        return res.json({
            success: true,
            classes: classes.map((c) => ({
                id: c.id,
                class_name: c.class_name,
                section_code: c.section_code,
                department: c.department,
                subject: c.subject,
                capacity: c.capacity,
                enrolled_students: counts.get(c.id) || 0
            }))
        });
    } catch (error) {
        console.error("Get sections error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to load sections."
        });
    }
};
