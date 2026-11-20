const { getDB, nextId, isDuplicateKey } = require("../config/db");
const { activeEnrollmentCounts } = require("./classController");
const bcrypt = require("bcrypt");
const fs = require("fs");
const path = require("path");

// ================================================
// HELPERS
// ================================================

function toPositiveInt(value) {
    const number = Number(value);
    return Number.isInteger(number) && number > 0 ? number : null;
}

async function deleteUploadedFile(filePath) {
    if (!filePath) return;

    const filename = path.basename(filePath);

    const fullPath = path.join(
        __dirname,
        "..",
        "uploads",
        "assignments",
        filename
    );

    try {
        await fs.promises.unlink(fullPath);
    } catch (error) {
        if (error.code !== "ENOENT") {
            console.error("File deletion error:", error);
        }
    }
}

async function findStudent(studentId) {
    return getDB().collection("users").findOne({
        id: studentId,
        role: "student"
    });
}

// ================================================
// CREATE A STUDENT ACCOUNT
// ================================================

exports.createStudent = async (req, res) => {
    try {
        const {
            name,
            roll_number,
            email,
            username,
            password,
            department
        } = req.body;

        if (
            !name ||
            !roll_number ||
            !email ||
            !username ||
            !password
        ) {
            return res.status(400).json({
                success: false,
                message: "All required fields are required."
            });
        }

        const users = getDB().collection("users");

        const existing = await users.findOne({
            $or: [
                { username: String(username).trim() },
                { email: String(email).trim() },
                { roll_number: String(roll_number).trim() }
            ]
        });

        if (existing) {
            return res.status(409).json({
                success: false,
                message: "Username, email or roll number already exists."
            });
        }

        const studentId = await nextId("users");

        await users.insertOne({
            id: studentId,
            name: String(name).trim(),
            username: String(username).trim(),
            email: String(email).trim(),
            password: await bcrypt.hash(password, 12),
            role: "student",
            roll_number: String(roll_number).trim(),
            employee_id: null,
            department: department || null,
            created_at: new Date()
        });

        return res.status(201).json({
            success: true,
            message: "Student account created successfully.",
            studentId
        });

    } catch (error) {
        if (isDuplicateKey(error)) {
            return res.status(409).json({
                success: false,
                message: "Username, email or roll number already exists."
            });
        }

        console.error("Student creation error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to create student."
        });
    }
};

// ================================================
// GET ALL STUDENTS
// ================================================

exports.getAllStudents = async (req, res) => {
    try {
        const students = await getDB().collection("users")
            .find(
                { role: "student" },
                { projection: { _id: 0, id: 1, name: 1, roll_number: 1, email: 1, department: 1 } }
            )
            .sort({ name: 1 })
            .toArray();

        return res.json({
            success: true,
            students
        });

    } catch (error) {
        console.error("Get students error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to load students."
        });
    }
};

// ================================================
// GET AVAILABLE CLASSES
// ================================================

exports.getAvailableClasses = async (req, res) => {
    try {
        const classes = await getDB().collection("classes")
            .find({})
            .sort({ class_name: 1, section_code: 1 })
            .toArray();

        const counts = await activeEnrollmentCounts();

        return res.json({
            success: true,
            classes: classes.map((c) => {
                const enrolled = counts.get(c.id) || 0;

                return {
                    id: c.id,
                    class_name: c.class_name,
                    section_code: c.section_code,
                    subject: c.subject,
                    department: c.department,
                    capacity: c.capacity,
                    enrolled_count: enrolled,
                    available_seats: Math.max(Number(c.capacity) - enrolled, 0)
                };
            })
        });

    } catch (error) {
        console.error("Get classes error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to load sections."
        });
    }
};

// ================================================
// GET ASSIGNMENTS FOR A STUDENT
// ================================================

exports.getMyAssignments = async (req, res) => {
    try {
        const studentId = toPositiveInt(req.query.student_id);

        if (!studentId) {
            return res.status(400).json({
                success: false,
                message: "A valid student ID is required."
            });
        }

        if (!(await findStudent(studentId))) {
            return res.status(404).json({
                success: false,
                message: "Student not found."
            });
        }

        const db = getDB();

        const enrollments = await db.collection("student_classes")
            .find({ student_id: studentId, status: "active" })
            .toArray();

        const classIds = enrollments.map((e) => e.class_id);

        if (classIds.length === 0) {
            return res.json({ success: true, assignments: [] });
        }

        const classes = await db.collection("classes")
            .find({ id: { $in: classIds } })
            .toArray();

        const classById = new Map(classes.map((c) => [c.id, c]));

        const assignments = await db.collection("assignments")
            .find({ class_id: { $in: classIds } })
            .sort({ due_date: 1 })
            .toArray();

        const submissions = await db.collection("assignment_submissions")
            .find({
                student_id: studentId,
                assignment_id: { $in: assignments.map((a) => a.id) }
            })
            .toArray();

        const submissionByAssignment = new Map(
            submissions.map((s) => [s.assignment_id, s])
        );

        return res.json({
            success: true,
            assignments: assignments.map((a) => {
                const c = classById.get(a.class_id) || {};
                const s = submissionByAssignment.get(a.id) || {};

                return {
                    assignment_id: a.id,
                    title: a.title,
                    description: a.description ?? null,
                    due_date: a.due_date,
                    class_name: c.class_name ?? null,
                    subject: c.subject ?? null,
                    status: s.status || "pending",
                    answer_text: s.answer_text ?? null,
                    file_path: s.file_path ?? null,
                    submitted_at: s.submitted_at ?? null,
                    verified_at: s.verified_at ?? null,
                    marks_obtained: s.marks_obtained ?? null,
                    max_marks: s.max_marks ?? null,
                    teacher_feedback: s.teacher_feedback ?? null
                };
            })
        });

    } catch (error) {
        console.error("Get student assignments error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to load student assignments."
        });
    }
};

// ================================================
// ENROLL A STUDENT IN A SECTION
// ================================================

exports.enrollStudent = async (req, res) => {
    try {
        const studentId = toPositiveInt(req.body.student_id);
        const classId = toPositiveInt(req.body.class_id);

        if (!studentId || !classId) {
            return res.status(400).json({
                success: false,
                message: "Valid student_id and class_id are required."
            });
        }

        const db = getDB();

        if (!(await findStudent(studentId))) {
            return res.status(404).json({
                success: false,
                message: "Student not found."
            });
        }

        const section = await db.collection("classes").findOne({ id: classId });

        if (!section) {
            return res.status(404).json({
                success: false,
                message: "Section not found."
            });
        }

        const enrollmentsCollection = db.collection("student_classes");

        const existing = await enrollmentsCollection.findOne({
            student_id: studentId,
            class_id: classId
        });

        if (existing) {
            return res.status(409).json({
                success: false,
                message: "This student is already enrolled in this section."
            });
        }

        const capacity = Number(section.capacity);
        const enrolled = await enrollmentsCollection.countDocuments({
            class_id: classId,
            status: "active"
        });

        if (enrolled >= capacity) {
            return res.status(409).json({
                success: false,
                message: `This section is full. Capacity: ${capacity}.`
            });
        }

        await enrollmentsCollection.insertOne({
            id: await nextId("student_classes"),
            student_id: studentId,
            class_id: classId,
            status: "active",
            enrolled_at: new Date()
        });

        return res.status(201).json({
            success: true,
            message: "Student enrolled successfully.",
            studentId,
            classId,
            enrolledCount: enrolled + 1,
            capacity,
            availableSeats: capacity - enrolled - 1
        });

    } catch (error) {
        if (isDuplicateKey(error)) {
            return res.status(409).json({
                success: false,
                message: "This student is already enrolled in this section."
            });
        }

        console.error("Student enrollment error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to enroll student."
        });
    }
};

// ================================================
// SUBMIT ASSIGNMENT FILE OR WRITTEN ANSWER
// ================================================

exports.submitAssignment = async (req, res) => {

    const uploadedFile = req.file;

    const newFilePath = uploadedFile
        ? `/uploads/assignments/${uploadedFile.filename}`
        : null;

    let saved = false;

    try {
        const studentId = toPositiveInt(req.body.student_id);
        const assignmentId = toPositiveInt(req.params.assignmentId);

        const answerText = String(
            req.body.answer_text || ""
        ).trim();

        if (!studentId || !assignmentId) {
            return res.status(400).json({
                success: false,
                message: "Invalid student or assignment ID."
            });
        }

        if (!uploadedFile && !answerText) {
            return res.status(400).json({
                success: false,
                message: "Please upload your assignment PDF."
            });
        }

        // Verify the uploaded PDF signature
        if (uploadedFile) {
            const fileHandle = await fs.promises.open(
                uploadedFile.path,
                "r"
            );

            try {
                const signature = Buffer.alloc(5);

                const { bytesRead } = await fileHandle.read(
                    signature,
                    0,
                    5,
                    0
                );

                if (
                    bytesRead !== 5 ||
                    signature.toString("ascii") !== "%PDF-"
                ) {
                    return res.status(400).json({
                        success: false,
                        message: "Invalid PDF file."
                    });
                }

            } finally {
                await fileHandle.close();
            }
        }

        const db = getDB();

        // Verify assignment and active enrollment
        const assignment = await db.collection("assignments").findOne({
            id: assignmentId
        });

        const enrollment = assignment
            ? await db.collection("student_classes").findOne({
                class_id: assignment.class_id,
                student_id: studentId,
                status: "active"
            })
            : null;

        if (!assignment || !enrollment) {
            return res.status(403).json({
                success: false,
                message: "Assignment not found or you are not enrolled in its class."
            });
        }

        const submissions = db.collection("assignment_submissions");

        const existing = await submissions.findOne({
            assignment_id: assignmentId,
            student_id: studentId
        });

        if (existing && existing.status === "verified") {
            return res.status(409).json({
                success: false,
                message: "This assignment has already been evaluated."
            });
        }

        const now = new Date();

        if (existing) {

            await submissions.updateOne(
                { _id: existing._id },
                {
                    $set: {
                        answer_text: answerText || null,
                        file_path: newFilePath || existing.file_path || null,
                        status: "submitted",
                        submitted_at: now,
                        verified_at: null,
                        marks_obtained: null,
                        teacher_feedback: null,
                        evaluated_at: null
                    }
                }
            );

            saved = true;

            // Delete the previous file after a successful update
            if (
                newFilePath &&
                existing.file_path &&
                existing.file_path !== newFilePath
            ) {
                await deleteUploadedFile(existing.file_path);
            }

        } else {

            await submissions.insertOne({
                id: await nextId("assignment_submissions"),
                assignment_id: assignmentId,
                student_id: studentId,
                answer_text: answerText || null,
                file_path: newFilePath,
                status: "submitted",
                submitted_at: now,
                verified_at: null,
                marks_obtained: null,
                max_marks: null,
                teacher_feedback: null,
                evaluated_at: null
            });

            saved = true;
        }

        return res.json({
            success: true,
            message: "Assignment uploaded successfully.",
            assignmentId,
            studentId,
            file_path: newFilePath,
            submitted_at: now
        });

    } catch (error) {

        console.error("Assignment upload error:", error);

        return res.status(500).json({
            success: false,
            message: "Assignment upload failed."
        });

    } finally {

        // Remove a newly uploaded file if it was not saved
        if (uploadedFile && !saved) {
            await deleteUploadedFile(newFilePath);
        }
    }
};

// ================================================
// GET STUDENT SUBMISSION AND EVALUATION
// ================================================

exports.getMySubmission = async (req, res) => {
    try {
        const studentId = toPositiveInt(req.query.student_id);
        const assignmentId = toPositiveInt(req.params.assignmentId);

        if (!studentId || !assignmentId) {
            return res.status(400).json({
                success: false,
                message: "Valid student and assignment IDs are required."
            });
        }

        const db = getDB();

        const submission = await db.collection("assignment_submissions").findOne({
            assignment_id: assignmentId,
            student_id: studentId
        });

        const assignment = submission
            ? await db.collection("assignments").findOne({ id: assignmentId })
            : null;

        const enrollment = assignment
            ? await db.collection("student_classes").findOne({
                class_id: assignment.class_id,
                student_id: studentId,
                status: "active"
            })
            : null;

        if (!submission || !assignment || !enrollment) {
            return res.json({
                success: true,
                submission: null,
                message: "No submission found yet."
            });
        }

        return res.json({
            success: true,
            submission: {
                assignment_id: submission.assignment_id,
                student_id: submission.student_id,
                answer_text: submission.answer_text ?? null,
                file_path: submission.file_path ?? null,
                status: submission.status,
                submitted_at: submission.submitted_at ?? null,
                verified_at: submission.verified_at ?? null,
                marks_obtained: submission.marks_obtained ?? null,
                max_marks: submission.max_marks ?? null,
                teacher_feedback: submission.teacher_feedback ?? null,
                evaluated_at: submission.evaluated_at ?? null,
                title: assignment.title,
                due_date: assignment.due_date
            }
        });

    } catch (error) {
        console.error("Get student submission error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to load submission."
        });
    }
};
