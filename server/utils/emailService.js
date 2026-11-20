const nodemailer = require("nodemailer");
const path = require("path");

// Load environment variables from server/.env
require("dotenv").config({
    path: path.join(__dirname, "../.env")
});

if (!process.env.EMAIL_USER || !process.env.EMAIL_PASS) {
    console.error(
        "Email configuration missing. Check EMAIL_USER and EMAIL_PASS in server/.env"
    );
}

const transporter = nodemailer.createTransport({
    service: "gmail",
    auth: {
        user: process.env.EMAIL_USER,
        pass: process.env.EMAIL_PASS
    }
});

// Verify Gmail SMTP connection
async function verifyEmailConnection() {
    try {
        await transporter.verify();
        console.log("Gmail SMTP connection successful.");
        return true;
    } catch (error) {
        console.error(
            "Gmail SMTP connection failed:",
            error.message
        );
        return false;
    }
}

// Send an email
async function sendEmail(to, subject, text) {
    if (!process.env.EMAIL_USER || !process.env.EMAIL_PASS) {
        console.error("Cannot send email: email credentials are missing.");
        return false;
    }

    if (!to || !subject || !text) {
        console.error("Cannot send email: recipient, subject, and text are required.");
        return false;
    }

    try {
        const info = await transporter.sendMail({
            from: `"EduTracker Notifications" <${process.env.EMAIL_USER}>`,
            to,
            subject,
            text
        });

        console.log("Email sent successfully:", info.messageId);
        return true;

    } catch (error) {
        console.error("Email sending failed:", error.message);
        return false;
    }
}

module.exports = {
    sendEmail,
    verifyEmailConnection,
    transporter
};