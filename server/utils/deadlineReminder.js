
const cron = require("node-cron");
const { getDB } = require("../config/db");
const { sendEmail } = require("./emailService");

let isChecking = false;

async function checkAssignmentDeadlines() {
    if (isChecking) return;

    isChecking = true;

    try {
        console.log("[REMINDER] Checking assignment deadlines...");

        // Assignments due roughly 24 hours from now (23h55m - 24h05m)
        const now = new Date();
        const startTime = new Date(now.getTime() + 1435 * 60 * 1000);
        const endTime = new Date(now.getTime() + 1445 * 60 * 1000);

        const db = getDB();

        const dueAssignments = await db.collection("assignments")
            .find({ due_date: { $gte: startTime, $lte: endTime } })
            .toArray();

        // One row per (assignment, active student without verification or reminder)
        const rows = [];

        for (const a of dueAssignments) {
            const enrollments = await db.collection("student_classes")
                .find({ class_id: a.class_id, status: "active" })
                .toArray();

            for (const enrollment of enrollments) {
                const student = await db.collection("users").findOne({
                    id: enrollment.student_id,
                    role: "student"
                });

                if (!student || !student.email || !String(student.email).trim()) {
                    continue;
                }

                const verified = await db.collection("assignment_submissions").findOne({
                    assignment_id: a.id,
                    student_id: student.id,
                    status: "verified"
                });

                const alreadySent = await db.collection("assignment_reminder_log").findOne({
                    assignment_id: a.id,
                    student_id: student.id
                });

                if (verified || alreadySent) {
                    continue;
                }

                rows.push({
                    assignment_id: a.id,
                    title: a.title,
                    description: a.description,
                    due_date: a.due_date,
                    student_id: student.id,
                    student_name: student.name,
                    email: student.email
                });
            }
        }

        let eligibleCount = 0;

        for (const row of rows) {

            const assignment = {
                id: row.assignment_id,
                title: row.title,
                description: row.description
            };

            const student = {
                id: row.student_id,
                name: row.student_name,
                email: row.email
            };

            const deadline = new Date(row.due_date);

            if (isNaN(deadline.getTime())) {
                continue;
            }

            {
                eligibleCount++;

                const formattedDeadline = deadline.toLocaleString("en-IN", {
                    dateStyle: "medium",
                    timeStyle: "short"
                });

                const subject =
                    `EduTracker | Assignment Deadline Reminder: ${assignment.title}`;

                const message = `
Hello ${student.name || "Student"},

This is an automated assignment deadline reminder from EduTracker.

ASSIGNMENT DETAILS
------------------
Assignment: ${assignment.title}
Deadline: ${formattedDeadline}

Description:
${assignment.description || "No description provided."}

Your assignment is due in approximately 24 hours.

Please complete and submit your work before the deadline. If you have already submitted it, you may disregard this reminder once your submission status is updated.

Regards,
EduTracker Notification System
Automated Academic Deadline Alerts
                `.trim();

                try {
                    const sent = await sendEmail(
                        student.email,
                        subject,
                        message
                    );

                    if (!sent) {
                        console.error(
                            `[REMINDER] Email failed for student ID ${student.id}`
                        );
                        continue;
                    }

                    await db.collection("assignment_reminder_log").updateOne(
                        { assignment_id: assignment.id, student_id: student.id },
                        { $setOnInsert: { sent_at: new Date() } },
                        { upsert: true }
                    );

                    console.log(
                        `[REMINDER] Sent successfully for assignment ${assignment.id}, student ${student.id}`
                    );

                } catch (error) {
                    console.error(
                        `[REMINDER] Failed for assignment ${assignment.id}, student ${student.id}:`,
                        error.message
                    );
                }
            }
        }

        console.log(
            `[REMINDER] Eligible student reminders: ${eligibleCount}`
        );

        console.log("[REMINDER] Deadline check completed.");

    } catch (error) {
        console.error(
            "[REMINDER] Database check failed:",
            error.message
        );
    } finally {
        isChecking = false;
    }
}

function startDeadlineReminderScheduler() {
    console.log("[REMINDER] Scheduler initialized.");

    // Run once when the server starts.
    checkAssignmentDeadlines();

    // Check every five minutes.
    cron.schedule("*/5 * * * *", () => {
        checkAssignmentDeadlines();
    });

    console.log(
        "[REMINDER] Scheduler started; checking every five minutes."
    );
}

module.exports = {
    startDeadlineReminderScheduler,
    checkAssignmentDeadlines
};