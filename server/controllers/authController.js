
// --------------------------------------------------
// IMPORT PACKAGES
// --------------------------------------------------
const { getDB } = require("../config/db");
const bcrypt = require("bcrypt");

// --------------------------------------------------
// LOGIN CONTROLLER
// --------------------------------------------------
exports.login = async (req, res) => {

    try {

        // Get login details from frontend
        const {
            username,
            password,
            role
        } = req.body;

        // --------------------------------------------------
        // VALIDATE INPUT
        // --------------------------------------------------
        if (!username || !password || !role) {

            return res.status(400).json({
                success: false,
                message: "Username, password and role are required."
            });

        }

        // --------------------------------------------------
        // VALIDATE USER ROLE
        // --------------------------------------------------
        const allowedRoles = [
            "admin",
            "student",
            "teacher"
        ];

        if (!allowedRoles.includes(role)) {

            return res.status(400).json({
                success: false,
                message: "Invalid role."
            });

        }

        // --------------------------------------------------
        // FIND USER (MongoDB)
        // --------------------------------------------------
        const user = await getDB().collection("users").findOne({
            username: String(username).trim(),
            role: role
        });

        if (!user) {

            return res.status(401).json({
                success: false,
                message: "Invalid username, password or role."
            });

        }

        // --------------------------------------------------
        // VERIFY PASSWORD
        // --------------------------------------------------
        if (!user.password) {

            return res.status(401).json({
                success: false,
                message: "Invalid username, password or role."
            });

        }

        const passwordCorrect = await bcrypt.compare(
            password,
            user.password
        );

        if (!passwordCorrect) {

            return res.status(401).json({
                success: false,
                message: "Invalid username, password or role."
            });

        }

        // --------------------------------------------------
        // PREPARE USER RESPONSE
        // --------------------------------------------------
        const loggedInUser = {
            id: user.id,
            name: user.name,
            username: user.username,
            email: user.email,
            role: user.role
        };

        // --------------------------------------------------
        // LOGIN SUCCESSFUL
        // --------------------------------------------------
        return res.status(200).json({

            success: true,

            message: "Login successful.",

            user: loggedInUser

        });

    } catch (error) {

        // --------------------------------------------------
        // ERROR HANDLING
        // --------------------------------------------------
        console.error("Login error:", error);

        return res.status(500).json({

            success: false,

            message: "Internal server error."

        });

    }

};
