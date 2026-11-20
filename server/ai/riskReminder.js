// ==================================================
// EduTracker AI - Risk-aware early reminders
// --------------------------------------------------
// About 72 hours before a deadline, students whom the AI model
// predicts are at HIGH risk of missing it receive an extra,
// earlier reminder. Everyone still receives the normal 24-hour
// reminder (utils/deadlineReminder.js).
//
// ai_early_reminder_log (unique assignment + student) makes sure
// each student gets at most one early reminder per assignment.
// The model is retrained every hour so it keeps learning from
// new submissions.
// ==================================================

const cron = require("node-cron");
const { getDB } = require("../config/db");
const { sendEmail } = require("../utils/emailService");
const { trainModel, predictForAssignments } = require("./riskModel");

let running = false;

async function sendEarlyRiskReminders() {
    if (running) return { sent: 0, skipped: true };
    running = true;

    let sent = 0;

    try {
        const now = Date.now();
        const from = now + 4315 * 60 * 1000; // 71h 55m
        const to = now + 4325 * 60 * 1000;   // 72h 05m

        const { predictions } = await predictForAssignments((a) => {
            const due = new Date(a.due_date).getTime();
            return due >= from && due <= to;
        });

        const db = getDB();

        for (const p of predictions) {
            if (p.level !== "high") continue;

            const already = await db.collection("ai_early_reminder_log").findOne({
                assignment_id: p.assignment_id,
                student_id: p.student_id
            });
            if (already) continue;

            const student = await db.collection("users").findOne({ id: p.student_id, role: "student" });
            if (!student || !student.email || !String(student.email).trim()) continue;

            const deadline = new Date(p.due_date).toLocaleString("en-IN", {
                dateStyle: "medium",
                timeStyle: "short"
            });

            const message = `
Hello ${student.name || "Student"},

EduTracker's assistant noticed that "${p.title}" is due in about 3 days (${deadline}) and has not been submitted yet.

Why you are getting this early reminder:
${p.reasons.length ? p.reasons.map((r) => "- " + r).join("\n") : "- Based on your recent submission pattern"}

Starting today gives you time to ask questions and upload before the deadline.

Regards,
EduTracker Notifications
            `.trim();

            const ok = await sendEmail(
                student.email,
                `EduTracker | Early reminder: ${p.title}`,
                message
            );

            if (!ok) continue;

            await db.collection("ai_early_reminder_log").updateOne(
                { assignment_id: p.assignment_id, student_id: p.student_id },
                { $setOnInsert: { sent_at: new Date(), probability: p.probability } },
                { upsert: true }
            );
            sent++;
        }

        if (sent) console.log(`[AI] Early risk reminders sent: ${sent}`);
    } catch (error) {
        console.error("[AI] Early reminder check failed:", error.message);
    } finally {
        running = false;
    }

    return { sent };
}

function startAiScheduler() {
    trainModel()
        .then(() => sendEarlyRiskReminders())
        .catch((error) => console.error("[AI] Initial training failed:", error.message));

    // Same five-minute rhythm as the 24-hour reminder.
    cron.schedule("*/5 * * * *", () => {
        sendEarlyRiskReminders();
    });

    // Retrain every hour on the latest data.
    cron.schedule("7 * * * *", () => {
        trainModel().catch((error) => console.error("[AI] Retraining failed:", error.message));
    });

    console.log("[AI] Scheduler started (early reminders every 5 min, retraining hourly).");
}

module.exports = {
    sendEarlyRiskReminders,
    startAiScheduler
};
