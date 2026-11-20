// ==================================================
// EduTracker - MongoDB Atlas connection
// --------------------------------------------------
// Collections (each document has a numeric "id", like the PHP pages expect):
//   users, departments, classes, student_classes, teacher_classes,
//   assignments, assignment_submissions, assignment_reminder_log
// "counters" stores the next id for each collection.
//
// Usage in controllers:
//   const { getDB, nextId } = require("../config/db");
// ==================================================

const { MongoClient } = require("mongodb");
const path = require("path");
const bcrypt = require("bcrypt");

require("dotenv").config({
    path: path.join(__dirname, "../.env"),
    quiet: true
});

const uri = process.env.MONGODB_URI;
const dbName = process.env.MONGODB_DB_NAME || "edutracker";

let client;
let database;

const COLLECTIONS_WITH_IDS = [
    "users",
    "departments",
    "classes",
    "student_classes",
    "teacher_classes",
    "assignments",
    "assignment_submissions",
    "assignment_reminder_log"
];

// --------------------------------------------------
// Numeric auto-increment ids
// nextId("users")      -> 7
// nextId("users", 3)   -> first id of a block of 3 (7, 8, 9)
// --------------------------------------------------
async function nextId(name, count = 1) {
    const result = await getDB().collection("counters").findOneAndUpdate(
        { _id: name },
        { $inc: { seq: count } },
        { upsert: true, returnDocument: "after" }
    );

    // Driver v6+ returns the document; older drivers wrap it in { value }.
    const doc = result && result.value !== undefined && result.seq === undefined
        ? result.value
        : result;

    return doc.seq - count + 1;
}

// Make each counter start after the highest id already stored,
// so existing Atlas data keeps its ids and new ids never collide.
async function syncCounters() {
    const db = getDB();

    for (const name of COLLECTIONS_WITH_IDS) {
        const [last] = await db.collection(name)
            .find({ id: { $type: "number" } })
            .sort({ id: -1 })
            .limit(1)
            .toArray();

        const maxId = last ? Number(last.id) : 0;

        await db.collection("counters").updateOne(
            { _id: name },
            { $max: { seq: maxId } },
            { upsert: true }
        );
    }
}

// --------------------------------------------------
// Indexes = the unique rules the MySQL tables used to enforce
// --------------------------------------------------
async function ensureIndexes() {
    const db = getDB();
    const stringOnly = (field) => ({ [field]: { $type: "string" } });

    const indexes = [
        ["users", { id: 1 }, { unique: true }],
        ["users", { username: 1 }, { unique: true }],
        ["users", { email: 1 }, { unique: true }],
        ["users", { roll_number: 1 }, { unique: true, partialFilterExpression: stringOnly("roll_number") }],
        ["users", { employee_id: 1 }, { unique: true, partialFilterExpression: stringOnly("employee_id") }],
        ["departments", { id: 1 }, { unique: true }],
        ["departments", { department_name: 1 }, { unique: true }],
        ["classes", { id: 1 }, { unique: true }],
        ["classes", { department: 1, section_code: 1, subject: 1 }, { unique: true }],
        ["student_classes", { student_id: 1, class_id: 1 }, { unique: true }],
        ["student_classes", { class_id: 1, status: 1 }, {}],
        ["teacher_classes", { teacher_id: 1, class_id: 1 }, { unique: true }],
        ["assignments", { id: 1 }, { unique: true }],
        ["assignments", { class_id: 1, due_date: 1 }, {}],
        ["assignment_submissions", { assignment_id: 1, student_id: 1 }, { unique: true }],
        ["assignment_reminder_log", { assignment_id: 1, student_id: 1 }, { unique: true }],
        ["ai_early_reminder_log", { assignment_id: 1, student_id: 1 }, { unique: true }],
        ["ai_models", { name: 1 }, { unique: true }]
    ];

    for (const [collection, keys, options] of indexes) {
        try {
            await db.collection(collection).createIndex(keys, options);
        } catch (error) {
            // Existing data with duplicates should not stop the server.
            console.warn(
                `Index on ${collection} ${JSON.stringify(keys)} not created: ${error.message}`
            );
        }
    }
}

// --------------------------------------------------
// First admin account (ADMIN_USERNAME / ADMIN_PASSWORD,
// defaults admin / Admin@123)
// --------------------------------------------------
async function ensureAdmin() {
    const users = getDB().collection("users");

    const existing = await users.findOne({ role: "admin" });

    if (existing) {
        return false;
    }

    const username = process.env.ADMIN_USERNAME || "admin";
    const password = process.env.ADMIN_PASSWORD || "Admin@123";

    await users.insertOne({
        id: await nextId("users"),
        name: process.env.ADMIN_NAME || "EduTracker Admin",
        username,
        email: process.env.ADMIN_EMAIL || "admin@edutracker.com",
        password: await bcrypt.hash(password, 12),
        role: "admin",
        roll_number: null,
        employee_id: null,
        department: null,
        created_at: new Date()
    });

    console.log(`Default admin account created (username: ${username}).`);
    return true;
}

async function connectDB() {
    if (database) {
        return database;
    }

    if (!uri) {
        throw new Error("MONGODB_URI is missing (server/.env locally, Environment Variables on Render).");
    }

    client = new MongoClient(uri, {
        serverSelectionTimeoutMS: 15000
    });

    await client.connect();
    database = client.db(dbName);

    console.log("MongoDB Atlas connected. Database:", dbName);

    await syncCounters();
    await ensureIndexes();
    await ensureAdmin();

    return database;
}

function getDB() {
    if (!database) {
        throw new Error("Database not connected. Call connectDB() first.");
    }

    return database;
}

async function closeDB() {
    if (client) {
        await client.close();
    }

    client = undefined;
    database = undefined;
}

// Remove MongoDB's internal _id before sending a document to PHP.
function clean(doc) {
    if (!doc) {
        return doc;
    }

    const { _id, ...rest } = doc;
    return rest;
}

function isDuplicateKey(error) {
    return Boolean(error) && error.code === 11000;
}

module.exports = {
    connectDB,
    getDB,
    closeDB,
    nextId,
    ensureAdmin,
    clean,
    isDuplicateKey
};
