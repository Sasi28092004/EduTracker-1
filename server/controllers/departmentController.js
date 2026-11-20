const { getDB, nextId, clean, isDuplicateKey } = require("../config/db");

// List departments.
exports.getDepartments = async (req, res) => {
    try {
        const departments = await getDB().collection("departments")
            .find({}, { projection: { _id: 0, id: 1, department_name: 1, created_at: 1 } })
            .sort({ department_name: 1 })
            .toArray();

        return res.json({
            success: true,
            departments
        });
    } catch (error) {
        console.error("Get departments error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to load departments."
        });
    }
};

// Create a department.
exports.createDepartment = async (req, res) => {
    try {
        const departmentName = String(
            req.body.department_name || ""
        ).trim();

        if (!departmentName) {
            return res.status(400).json({
                success: false,
                message: "Department name is required."
            });
        }

        if (departmentName.length > 100) {
            return res.status(400).json({
                success: false,
                message: "Department name must be 100 characters or fewer."
            });
        }

        const departments = getDB().collection("departments");

        const existing = await departments.findOne({
            department_name: departmentName
        });

        if (existing) {
            return res.status(409).json({
                success: false,
                message: "This department already exists."
            });
        }

        const department = {
            id: await nextId("departments"),
            department_name: departmentName,
            created_at: new Date()
        };

        await departments.insertOne(department);

        return res.status(201).json({
            success: true,
            message: "Department created successfully.",
            department: clean(department)
        });
    } catch (error) {
        if (isDuplicateKey(error)) {
            return res.status(409).json({
                success: false,
                message: "This department already exists."
            });
        }

        console.error("Create department error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to create department."
        });
    }
};
