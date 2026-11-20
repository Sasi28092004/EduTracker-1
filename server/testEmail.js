require("dotenv").config({
    path: require("path").join(__dirname, ".env")
});

const { sendEmail, transporter } = require("./utils/emailService");

async function testEmail() {
    try {
        await transporter.verify();

        console.log("Gmail connection successful!");

        const result = await sendEmail(
            process.env.EMAIL_USER,
            "EduTracker Email Test",
            "Congratulations! Your EduTracker email notification system is working."
        );

        if (result) {
            console.log("Test email sent successfully!");
        } else {
            console.log("Failed to send test email.");
        }

    } catch (error) {
        console.error("Email test failed:", error.message);
    }
}

testEmail();