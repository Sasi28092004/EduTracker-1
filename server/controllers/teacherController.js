const { getDB, nextId, isDuplicateKey } = require("../config/db");
const bcrypt = require("bcrypt");
const { sendEmail } = require("../utils/emailService");

// ==================================================
// HELPER FUNCTIONS
// ==================================================

function toPositiveInt(value) {
    const number = Number(value);
    return Number.isInteger(number) && number > 0 ? number : null;
}

function getTeacherId(req) {
    return toPositiveInt(req.body?.teacher_id || req.query?.teacher_id);
}

async function isTeacher(teacherId) {
    const teacher = await getDB().collection("users").findOne({
        id: teacherId,
        role: "teacher"
    });

    return Boolean(teacher);
}

async function isAssignedToClass(teacherId, classId) {
    const row = await getDB().collection("teacher_classes").findOne({
        teacher_id: teacherId,
        class_id: classId
    });

    return Boolean(row);
}

// Assignment owned by this teacher, in a class the teacher is still assigned to.
async function findOwnedAssignment(teacherId, assignmentId) {
    const assignment = await getDB().collection("assignments").findOne({
        id: assignmentId,
        teacher_id: teacherId
    });

    if (!assignment) {
        return null;
    }

    return (await isAssignedToClass(teacherId, assignment.class_id))
        ? assignment
        : null;
}

async function findEnrolledStudent(classId, studentId, activeOnly) {
    const db = getDB();

    const query = { class_id: classId, student_id: studentId };

    if (activeOnly) {
        query.status = "active";
    }

    const enrollment = await db.collection("student_classes").findOne(query);

    if (!enrollment) {
        return null;
    }

    return db.collection("users").findOne({ id: studentId, role: "student" });
}

// ==================================================
// 1. CREATE TEACHER
// ==================================================

exports.createTeacher = async (req, res) => {
    try {
        const {
            name,
            employee_id,
            email,
            username,
            password,
            department
        } = req.body;

        if (!name || !employee_id || !email || !username || !password) {
            return res.status(400).json({
                success: false,
                message: "Name, employee ID, email, username and password are required."
            });
        }

        const users = getDB().collection("users");

        const existing = await users.findOne({
            $or: [
                { username: String(username).trim() },
                { email: String(email).trim() },
                { employee_id: String(employee_id).trim() }
            ]
        });

        if (existing) {
            return res.status(409).json({
                success: false,
                message: "Username, email or employee ID already exists."
            });
        }

        const teacherId = await nextId("users");

        await users.insertOne({
            id: teacherId,
            name: String(name).trim(),
            username: String(username).trim(),
            email: String(email).trim(),
            password: await bcrypt.hash(password, 12),
            role: "teacher",
            roll_number: null,
            employee_id: String(employee_id).trim(),
            department: department || null,
            created_at: new Date()
        });

        return res.status(201).json({
            success: true,
            message: "Teacher account created successfully.",
            teacherId
        });

    } catch (error) {
        if (isDuplicateKey(error)) {
            return res.status(409).json({
                success: false,
                message: "Username, email or employee ID already exists."
            });
        }

        console.error("Create teacher error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to create teacher."
        });
    }
};

// ==================================================
// 2. GET ALL TEACHERS
// ==================================================

exports.getAllTeachers = async (req, res) => {
    try {
        const teachers = await getDB().collection("users")
            .find(
                { role: "teacher" },
                { projection: { _id: 0, id: 1, name: 1, employee_id: 1, email: 1, department: 1 } }
            )
            .sort({ name: 1 })
            .toArray();

        return res.json({
            success: true,
            teachers
        });

    } catch (error) {
        console.error("Get all teachers error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to load teachers."
        });
    }
};

// ==================================================
// 3. ASSIGN CLASSES TO A TEACHER
// ==================================================

exports.assignSections = async (req, res) => {
    try {
        const teacherId = toPositiveInt(req.body.teacher_id);
        const classIds = req.body.class_ids;

        if (
            !teacherId ||
            !Array.isArray(classIds) ||
            classIds.length === 0
        ) {
            return res.status(400).json({
                success: false,
                message: "Select a teacher and at least one class."
            });
        }

        const ids = [...new Set(classIds.map(Number))];

        if (ids.some((id) => !Number.isInteger(id) || id <= 0)) {
            return res.status(400).json({
                success: false,
                message: "Invalid class IDs."
            });
        }

        if (!(await isTeacher(teacherId))) {
            return res.status(404).json({
                success: false,
                message: "Teacher not found."
            });
        }

        const db = getDB();

        const found = await db.collection("classes").countDocuments({
            id: { $in: ids }
        });

        if (found !== ids.length) {
            return res.status(400).json({
                success: false,
                message: "One or more classes do not exist."
            });
        }

        const teacherClasses = db.collection("teacher_classes");

        let assigned = 0;
        let alreadyAssigned = 0;

        for (const classId of ids) {
            const existing = await teacherClasses.findOne({
                teacher_id: teacherId,
                class_id: classId
            });

            if (existing) {
                alreadyAssigned++;
                continue;
            }

            try {
                await teacherClasses.insertOne({
                    id: await nextId("teacher_classes"),
                    teacher_id: teacherId,
                    class_id: classId,
                    assigned_at: new Date()
                });

                assigned++;
            } catch (error) {
                if (!isDuplicateKey(error)) {
                    throw error;
                }

                alreadyAssigned++;
            }
        }

        return res.json({
            success: true,
            message: `Assigned ${assigned} class(es). ${alreadyAssigned} were already assigned.`,
            assigned,
            alreadyAssigned
        });

    } catch (error) {
        console.error("Assign sections error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to assign classes."
        });
    }
};

// ==================================================
// 4. GET CLASSES ASSIGNED TO A TEACHER
// ==================================================

exports.getMyClasses = async (req, res) => {
    try {
        const teacherId = getTeacherId(req);

        if (!teacherId || !(await isTeacher(teacherId))) {
            return res.status(403).json({
                success: false,
                message: "A valid teacher account is required."
            });
        }

        const db = getDB();

        const links = await db.collection("teacher_classes")
            .find({ teacher_id: teacherId })
            .toArray();

        const classIds = links.map((link) => link.class_id);

        const classes = classIds.length
            ? await db.collection("classes")
                .find({ id: { $in: classIds } })
                .sort({ class_name: 1 })
                .toArray()
            : [];

        const enrollments = classIds.length
            ? await db.collection("student_classes")
                .find({ class_id: { $in: classIds } })
                .toArray()
            : [];

        const studentsByClass = new Map();

        for (const e of enrollments) {
            if (!studentsByClass.has(e.class_id)) {
                studentsByClass.set(e.class_id, new Set());
            }

            studentsByClass.get(e.class_id).add(e.student_id);
        }

        return res.json({
            success: true,
            classes: classes.map((c) => ({
                id: c.id,
                class_name: c.class_name,
                subject: c.subject,
                department: c.department,
                student_count: studentsByClass.get(c.id)?.size || 0
            }))
        });

    } catch (error) {
        console.error("Get teacher classes error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to load assigned classes.",
            ...(process.env.NODE_ENV !== "production"
                ? { error: error.message }
                : {})
        });
    }
};

// ==================================================
// 5. CREATE AN ASSIGNMENT
// ==================================================

exports.createAssignment = async (req, res) => {
    try {
        const teacherId = getTeacherId(req);

        const {
            class_id,
            title,
            description,
            due_date
        } = req.body;

        const classId = toPositiveInt(class_id);

        if (!teacherId || !(await isTeacher(teacherId))) {
            return res.status(403).json({
                success: false,
                message: "A valid teacher account is required."
            });
        }

        if (
            !classId ||
            typeof title !== "string" ||
            !title.trim() ||
            !due_date
        ) {
            return res.status(400).json({
                success: false,
                message: "Class, assignment title and deadline are required."
            });
        }

        const dueDate = new Date(due_date);

        if (Number.isNaN(dueDate.getTime())) {
            return res.status(400).json({
                success: false,
                message: "Please provide a valid deadline."
            });
        }

        if (!(await isAssignedToClass(teacherId, classId))) {
            return res.status(403).json({
                success: false,
                message: "You are not assigned to this class."
            });
        }

        const db = getDB();
        const cleanTitle = title.trim();
        const cleanDescription = description ? String(description).trim() : null;

        const assignmentId = await nextId("assignments");

        await db.collection("assignments").insertOne({
            id: assignmentId,
            class_id: classId,
            teacher_id: teacherId,
            title: cleanTitle,
            description: cleanDescription,
            due_date: dueDate,
            created_at: new Date()
        });

        // Students in this class
        const enrollments = await db.collection("student_classes")
            .find({ class_id: classId })
            .toArray();

        const studentIds = [...new Set(enrollments.map((e) => e.student_id))];

        const students = studentIds.length
            ? await db.collection("users")
                .find({ id: { $in: studentIds }, role: "student" })
                .toArray()
            : [];

        // A "pending" submission row for every student
        if (students.length > 0) {
            const firstId = await nextId("assignment_submissions", students.length);

            try {
                await db.collection("assignment_submissions").insertMany(
                    students.map((student, index) => ({
                        id: firstId + index,
                        assignment_id: assignmentId,
                        student_id: student.id,
                        answer_text: null,
                        file_path: null,
                        status: "pending",
                        submitted_at: null,
                        verified_at: null,
                        marks_obtained: null,
                        max_marks: null,
                        teacher_feedback: null,
                        evaluated_at: null
                    })),
                    { ordered: false }
                );
            } catch (error) {
                if (!isDuplicateKey(error)) {
                    throw error;
                }
            }
        }

        const teacher = await db.collection("users").findOne({ id: teacherId });
        const section = await db.collection("classes").findOne({ id: classId });

        const teacherName = teacher?.name || "Your teacher";
        const className = section?.class_name || "your class";

        const formattedDeadline = dueDate.toLocaleString("en-IN", {
            dateStyle: "medium",
            timeStyle: "short"
        });

        const recipients = students.filter(
            (student) => student.email && String(student.email).trim() !== ""
        );

        let emailsSent = 0;
        let emailsFailed = 0;

        for (const student of recipients) {
            const subject = `New Assessment Assigned: ${cleanTitle}`;

            const message = `
Hello ${student.name || "Student"},

Your teacher, ${teacherName}, has assigned a new assessment to ${className}.

Assessment: ${cleanTitle}
Deadline: ${formattedDeadline}

Description:
${cleanDescription || "Please check your dashboard for the assessment details."}

Please log in to your EduTracker student dashboard to view the full assessment details and submit your work before the deadline.

Regards,
EduTracker Notifications
            `.trim();

            try {
                const sent = await sendEmail(student.email, subject, message);

                if (sent) {
                    emailsSent++;
                    console.log(`[ASSESSMENT EMAIL] Sent to ${student.email}`);
                } else {
                    emailsFailed++;
                    console.error(`[ASSESSMENT EMAIL] Failed for ${student.email}`);
                }

            } catch (emailError) {
                emailsFailed++;
                console.error(
                    `[ASSESSMENT EMAIL] Error for ${student.email}:`,
                    emailError.message
                );
            }
        }

        return res.status(201).json({
            success: true,
            message: "Assignment created successfully.",
            assignmentId,
            studentCount: recipients.length,
            emailsSent,
            emailsFailed
        });

    } catch (error) {
        console.error("Create assignment error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to create assignment."
        });
    }
};

// ==================================================
// 6. GET ASSIGNMENTS FOR A CLASS
// ==================================================

exports.getClassAssignments = async (req, res) => {
    try {
        const teacherId = getTeacherId(req);
        const classId = toPositiveInt(req.params.classId);

        if (!teacherId || !(await isTeacher(teacherId))) {
            return res.status(403).json({
                success: false,
                message: "A valid teacher account is required."
            });
        }

        if (!classId || !(await isAssignedToClass(teacherId, classId))) {
            return res.status(403).json({
                success: false,
                message: "You are not assigned to this class."
            });
        }

        const db = getDB();

        const assignments = await db.collection("assignments")
            .find({ class_id: classId, teacher_id: teacherId })
            .sort({ due_date: 1 })
            .toArray();

        const submissions = assignments.length
            ? await db.collection("assignment_submissions")
                .find({ assignment_id: { $in: assignments.map((a) => a.id) } })
                .toArray()
            : [];

        const totals = new Map();

        for (const s of submissions) {
            const t = totals.get(s.assignment_id) || { total: 0, done: 0 };
            t.total++;

            if (s.status === "verified") {
                t.done++;
            }

            totals.set(s.assignment_id, t);
        }

        return res.json({
            success: true,
            assignments: assignments.map((a) => ({
                id: a.id,
                class_id: a.class_id,
                teacher_id: a.teacher_id,
                title: a.title,
                description: a.description ?? null,
                due_date: a.due_date,
                created_at: a.created_at ?? null,
                total_students: totals.get(a.id)?.total || 0,
                completed_students: totals.get(a.id)?.done || 0
            }))
        });

    } catch (error) {
        console.error("Get class assignments error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to load assignments."
        });
    }
};

// ==================================================
// 7. GET STUDENT PROGRESS FOR AN ASSIGNMENT
// ==================================================

exports.getAssignmentProgress = async (req, res) => {
    try {
        const teacherId = getTeacherId(req);
        const assignmentId = toPositiveInt(req.params.assignmentId);

        if (!teacherId || !(await isTeacher(teacherId))) {
            return res.status(403).json({
                success: false,
                message: "A valid teacher account is required."
            });
        }

        if (!assignmentId) {
            return res.status(400).json({
                success: false,
                message: "Invalid assignment ID."
            });
        }

        const db = getDB();

        const assignment = await db.collection("assignments").findOne({
            id: assignmentId,
            teacher_id: teacherId
        });

        if (!assignment) {
            return res.status(404).json({
                success: false,
                message: "Assignment not found."
            });
        }

        const enrollments = await db.collection("student_classes")
            .find({ class_id: assignment.class_id, status: "active" })
            .toArray();

        const studentIds = enrollments.map((e) => e.student_id);

        const students = studentIds.length
            ? await db.collection("users")
                .find({ id: { $in: studentIds }, role: "student" })
                .sort({ name: 1 })
                .toArray()
            : [];

        const submissions = await db.collection("assignment_submissions")
            .find({ assignment_id: assignmentId })
            .toArray();

        const submissionByStudent = new Map(
            submissions.map((s) => [s.student_id, s])
        );

        return res.json({
            success: true,
            students: students.map((u) => {
                const s = submissionByStudent.get(u.id) || {};

                return {
                    student_id: u.id,
                    name: u.name,
                    roll_number: u.roll_number ?? null,
                    username: u.username,
                    submission_id: s.id ?? null,
                    status: s.status || "pending",
                    answer_text: s.answer_text ?? null,
                    file_path: s.file_path ?? null,
                    submitted_at: s.submitted_at ?? null,
                    verified_at: s.verified_at ?? null,
                    marks_obtained: s.marks_obtained ?? null,
                    max_marks: s.max_marks ?? null,
                    teacher_feedback: s.teacher_feedback ?? null,
                    evaluated_at: s.evaluated_at ?? null
                };
            })
        });

    } catch (error) {
        console.error("Get assignment progress error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to load student progress."
        });
    }
};

// ==================================================
// 8. UPDATE STUDENT COMPLETION STATUS
// ==================================================

exports.updateStudentStatus = async (req, res) => {
    try {
        const teacherId = getTeacherId(req);
        const assignmentId = toPositiveInt(req.params.assignmentId);
        const studentId = toPositiveInt(req.params.studentId);
        const { status } = req.body;

        if (!teacherId || !(await isTeacher(teacherId))) {
            return res.status(403).json({
                success: false,
                message: "A valid teacher account is required."
            });
        }

        if (
            !assignmentId ||
            !studentId ||
            !["pending", "verified"].includes(status)
        ) {
            return res.status(400).json({
                success: false,
                message: "Invalid assignment, student or status."
            });
        }

        const assignment = await findOwnedAssignment(teacherId, assignmentId);
        const student = assignment
            ? await findEnrolledStudent(assignment.class_id, studentId, false)
            : null;

        if (!assignment || !student) {
            return res.status(404).json({
                success: false,
                message: "Assignment or enrolled student not found."
            });
        }

        const submissions = getDB().collection("assignment_submissions");

        const existing = await submissions.findOne({
            assignment_id: assignmentId,
            student_id: studentId
        });

        const verifiedAt = status === "verified" ? new Date() : null;

        if (existing) {
            await submissions.updateOne(
                { _id: existing._id },
                { $set: { status, verified_at: verifiedAt } }
            );
        } else {
            await submissions.insertOne({
                id: await nextId("assignment_submissions"),
                assignment_id: assignmentId,
                student_id: studentId,
                answer_text: null,
                file_path: null,
                status,
                submitted_at: null,
                verified_at: verifiedAt,
                marks_obtained: null,
                max_marks: null,
                teacher_feedback: null,
                evaluated_at: null
            });
        }

        return res.json({
            success: true,
            message: status === "verified"
                ? "Student marked as completed."
                : "Student marked as pending."
        });

    } catch (error) {
        console.error("Update student status error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to update student status."
        });
    }
};

// ==================================================
// 9. GRADE AN ASSIGNMENT (TEXT OR PDF SUBMISSION)
// ==================================================

exports.gradeAssignment = async (req, res) => {
    try {
        const teacherId = getTeacherId(req);
        const assignmentId = toPositiveInt(req.params.assignmentId);
        const studentId = toPositiveInt(req.params.studentId);

        const marks = Number(req.body.marks_obtained);
        const maxMarks = Number(req.body.max_marks ?? 100);
        const feedback = String(req.body.teacher_feedback || "").trim();

        if (!teacherId || !(await isTeacher(teacherId))) {
            return res.status(403).json({
                success: false,
                message: "A valid teacher account is required."
            });
        }

        if (
            !assignmentId ||
            !studentId ||
            req.body.marks_obtained === undefined ||
            !Number.isFinite(marks) ||
            !Number.isFinite(maxMarks) ||
            maxMarks <= 0 ||
            marks < 0 ||
            marks > maxMarks
        ) {
            return res.status(400).json({
                success: false,
                message: "Provide valid marks within the maximum marks."
            });
        }

        // Teacher owns the assignment, student is actively enrolled,
        // and the student actually submitted something.
        const assignment = await findOwnedAssignment(teacherId, assignmentId);
        const student = assignment
            ? await findEnrolledStudent(assignment.class_id, studentId, true)
            : null;

        const submission = student
            ? await getDB().collection("assignment_submissions").findOne({
                assignment_id: assignmentId,
                student_id: studentId
            })
            : null;

        if (
            !submission ||
            (submission.answer_text == null && submission.file_path == null)
        ) {
            return res.status(404).json({
                success: false,
                message: "Submitted assignment not found."
            });
        }

        const now = new Date();

        await getDB().collection("assignment_submissions").updateOne(
            { _id: submission._id },
            {
                $set: {
                    marks_obtained: marks,
                    max_marks: maxMarks,
                    teacher_feedback: feedback || null,
                    status: "verified",
                    verified_at: now,
                    evaluated_at: now
                }
            }
        );

        return res.json({
            success: true,
            message: "Assignment evaluated successfully.",
            marks_obtained: marks,
            max_marks: maxMarks
        });

    } catch (error) {
        console.error("Grade assignment error:", error);

        return res.status(500).json({
            success: false,
            message: "Unable to evaluate assignment."
        });
    }
};
