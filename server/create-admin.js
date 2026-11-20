// Manually create the default admin account.
// (server.js already does this automatically on first start.)
// Usage:  npm run create-admin

const { connectDB, closeDB } = require("./config/db");

async function createAdmin() {
    try {
        // connectDB() creates the admin if none exists yet.
        await connectDB();
        console.log("Admin check finished. Username:", process.env.ADMIN_USERNAME || "admin");
    } catch (error) {
        console.error("Admin creation failed:", error.message);
        process.exitCode = 1;
    } finally {
        await closeDB();
    }
}

createAdmin();
