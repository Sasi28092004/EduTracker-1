// ==================================================
// EduTracker AI - Deadline Risk Prediction Model
// --------------------------------------------------
// Predicts the probability that a student will MISS an assignment
// deadline (no submission on or before the due date), using
// logistic regression trained on the institution's own history.
//
//   P(miss) = sigmoid( b + w1*z1 + ... + w5*z5 )
//
// Features (computed only from data available BEFORE the assignment,
// so the model never "sees the future" while training):
//   1. missRate     - smoothed share of the student's past deadlines missed
//   2. leadDays     - average days before the deadline the student submits
//   3. marksPct     - average marks obtained / maximum marks (0..1)
//   4. workload     - other deadlines of the student within +/- 3 days
//   5. windowDays   - days between publishing and the deadline
//
// Training: batch gradient descent with L2 regularisation on
// standardised features. Evaluated on a 25% hold-out split
// (accuracy, precision, recall, ROC-AUC), then refitted on all data.
//
// Cold start: until there are enough labelled examples (MIN_SAMPLES)
// containing both outcomes, the model uses expert-initialised prior
// weights and reports mode = "prior". After that it reports "trained".
//
// Pure JavaScript - no extra npm packages are required.
// ==================================================

const { getDB } = require("../config/db");

const FEATURES = ["missRate", "leadDays", "marksPct", "workload", "windowDays"];

const FEATURE_LABELS = {
    missRate: "past deadline misses",
    leadDays: "submission lead time",
    marksPct: "average marks",
    workload: "overlapping deadlines",
    windowDays: "time given for the task"
};

const DAY = 24 * 60 * 60 * 1000;
const MIN_SAMPLES = 30;

// Expert prior (raw feature scale) used before enough history exists.
const PRIOR = {
    bias: -1.4,
    weights: [3.0, -0.35, -1.2, 0.25, -0.08],
    reference: [0.25, 2, 0.7, 1, 7]
};

let currentModel = null;

// --------------------------------------------------
// Maths helpers
// --------------------------------------------------
const sigmoid = (x) => 1 / (1 + Math.exp(-Math.max(-35, Math.min(35, x))));
const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
const toTime = (v) => (v ? new Date(v).getTime() : NaN);

function mulberry32(seed) {
    return function () {
        seed |= 0; seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function fitScaler(rows) {
    const n = rows.length;
    const means = FEATURES.map((_, j) => rows.reduce((s, r) => s + r[j], 0) / n);
    const stds = FEATURES.map((_, j) => {
        const v = rows.reduce((s, r) => s + (r[j] - means[j]) ** 2, 0) / n;
        return Math.sqrt(v) || 1;
    });
    return { means, stds };
}

const standardise = (x, sc) => x.map((v, j) => (v - sc.means[j]) / sc.stds[j]);

function trainLogistic(X, y, { iterations = 3000, rate = 0.1, l2 = 0.01 } = {}) {
    const d = X[0].length;
    const w = new Array(d).fill(0);
    let b = 0;
    const n = X.length;

    // Class weights keep the minority outcome from being ignored.
    const pos = y.reduce((s, v) => s + v, 0);
    const wPos = pos > 0 ? n / (2 * pos) : 1;
    const wNeg = n - pos > 0 ? n / (2 * (n - pos)) : 1;

    for (let it = 0; it < iterations; it++) {
        const gw = new Array(d).fill(0);
        let gb = 0;
        for (let i = 0; i < n; i++) {
            let z = b;
            for (let j = 0; j < d; j++) z += w[j] * X[i][j];
            const err = (sigmoid(z) - y[i]) * (y[i] ? wPos : wNeg);
            for (let j = 0; j < d; j++) gw[j] += err * X[i][j];
            gb += err;
        }
        for (let j = 0; j < d; j++) w[j] -= rate * (gw[j] / n + l2 * w[j]);
        b -= rate * (gb / n);
    }
    return { weights: w, bias: b };
}

function rocAuc(scores, labels) {
    const pairs = scores.map((s, i) => [s, labels[i]]).sort((a, b) => a[0] - b[0]);
    let rankSum = 0;
    let nPos = 0;
    for (let i = 0; i < pairs.length; ) {
        let j = i;
        while (j < pairs.length && pairs[j][0] === pairs[i][0]) j++;
        const avgRank = (i + 1 + j) / 2;
        for (let k = i; k < j; k++) if (pairs[k][1]) { rankSum += avgRank; nPos++; }
        i = j;
    }
    const nNeg = pairs.length - nPos;
    if (!nPos || !nNeg) return null;
    return (rankSum - (nPos * (nPos + 1)) / 2) / (nPos * nNeg);
}

function evaluate(model, X, y) {
    const p = X.map((x) => predictRaw(model, x));
    let tp = 0, fp = 0, tn = 0, fn = 0;
    p.forEach((v, i) => {
        const pred = v >= 0.5 ? 1 : 0;
        if (pred && y[i]) tp++; else if (pred) fp++; else if (y[i]) fn++; else tn++;
    });
    return {
        accuracy: (tp + tn) / y.length,
        precision: tp + fp ? tp / (tp + fp) : null,
        recall: tp + fn ? tp / (tp + fn) : null,
        auc: rocAuc(p, y),
        testSize: y.length
    };
}

// --------------------------------------------------
// Data snapshot (one read of each collection)
// --------------------------------------------------
async function loadSnapshot() {
    const db = getDB();
    const [assignments, enrollments, submissions] = await Promise.all([
        db.collection("assignments").find({}).toArray(),
        db.collection("student_classes").find({ status: "active" }).toArray(),
        db.collection("assignment_submissions").find({}).toArray()
    ]);

    const classesOfStudent = new Map();
    for (const e of enrollments) {
        if (!classesOfStudent.has(e.student_id)) classesOfStudent.set(e.student_id, new Set());
        classesOfStudent.get(e.student_id).add(e.class_id);
    }

    const studentsOfClass = new Map();
    for (const e of enrollments) {
        if (!studentsOfClass.has(e.class_id)) studentsOfClass.set(e.class_id, []);
        studentsOfClass.get(e.class_id).push(e.student_id);
    }

    const submissionOf = new Map();
    for (const s of submissions) submissionOf.set(`${s.assignment_id}:${s.student_id}`, s);

    const byClass = new Map();
    for (const a of assignments) {
        if (!byClass.has(a.class_id)) byClass.set(a.class_id, []);
        byClass.get(a.class_id).push(a);
    }

    return { assignments, classesOfStudent, studentsOfClass, submissionOf, byClass };
}

// Was the assignment completed on time by the student?
function outcome(a, sub) {
    const due = toTime(a.due_date);
    if (!sub) return { onTime: false, lead: null };
    const submitted = toTime(sub.submitted_at);
    if (!Number.isNaN(submitted)) {
        return { onTime: submitted <= due, lead: submitted <= due ? (due - submitted) / DAY : null };
    }
    const verified = toTime(sub.verified_at);
    if (sub.status === "verified" && !Number.isNaN(verified) && verified <= due) {
        return { onTime: true, lead: null };
    }
    return { onTime: false, lead: null };
}

// Feature vector for (student, assignment) using history up to `asOf`.
function featuresFor(snap, studentId, a, asOf) {
    const classes = snap.classesOfStudent.get(studentId) || new Set();
    const due = toTime(a.due_date);

    let n = 0, misses = 0, leadSum = 0, leadN = 0, marksSum = 0, marksN = 0, workload = 0;

    for (const classId of classes) {
        for (const other of snap.byClass.get(classId) || []) {
            if (other.id === a.id) continue;
            const oDue = toTime(other.due_date);
            if (Number.isNaN(oDue)) continue;

            if (Math.abs(oDue - due) <= 3 * DAY) workload++;

            if (oDue < asOf) {
                const sub = snap.submissionOf.get(`${other.id}:${studentId}`);
                const o = outcome(other, sub);
                n++;
                if (!o.onTime) misses++;
                if (o.lead !== null) { leadSum += clamp(o.lead, 0, 7); leadN++; }
                if (sub && sub.marks_obtained != null && sub.max_marks > 0 &&
                    toTime(sub.evaluated_at) < asOf) {
                    marksSum += clamp(sub.marks_obtained / sub.max_marks, 0, 1);
                    marksN++;
                }
            }
        }
    }

    const created = toTime(a.created_at);
    const windowDays = Number.isNaN(created) ? 7 : clamp((due - created) / DAY, 0, 30);

    const x = [
        (misses + 1) / (n + 4),
        leadN ? leadSum / leadN : 2,
        marksN ? marksSum / marksN : 0.7,
        workload,
        windowDays
    ];

    return { x, stats: { pastDeadlines: n, misses, avgLeadDays: leadN ? leadSum / leadN : null, avgMarksPct: marksN ? marksSum / marksN : null, workload, windowDays } };
}

// --------------------------------------------------
// Training
// --------------------------------------------------
function buildDataset(snap, now) {
    const X = [];
    const y = [];
    for (const a of snap.assignments) {
        const due = toTime(a.due_date);
        if (Number.isNaN(due) || due >= now) continue;
        const asOf = Number.isNaN(toTime(a.created_at)) ? due - 7 * DAY : toTime(a.created_at);
        for (const studentId of snap.studentsOfClass.get(a.class_id) || []) {
            const { x } = featuresFor(snap, studentId, a, asOf);
            X.push(x);
            y.push(outcome(a, snap.submissionOf.get(`${a.id}:${studentId}`)).onTime ? 0 : 1);
        }
    }
    return { X, y };
}

function predictRaw(model, x) {
    if (model.mode === "prior") {
        let z = PRIOR.bias;
        x.forEach((v, j) => { z += PRIOR.weights[j] * v; });
        return sigmoid(z);
    }
    const zv = standardise(x, model.scaler);
    let z = model.bias;
    zv.forEach((v, j) => { z += model.weights[j] * v; });
    return sigmoid(z);
}

async function trainModel() {
    const now = Date.now();
    const snap = await loadSnapshot();
    const { X, y } = buildDataset(snap, now);
    const positives = y.reduce((s, v) => s + v, 0);

    let model;

    if (X.length < MIN_SAMPLES || positives === 0 || positives === X.length) {
        model = {
            mode: "prior",
            weights: PRIOR.weights,
            bias: PRIOR.bias,
            scaler: null,
            samples: X.length,
            positives,
            metrics: null,
            note: `Using expert prior weights: ${X.length} labelled examples available, ${MIN_SAMPLES} with both outcomes are needed to train.`
        };
    } else {
        // Hold-out evaluation (seeded shuffle -> reproducible)
        const rnd = mulberry32(42);
        const idx = X.map((_, i) => i).sort(() => rnd() - 0.5);
        const cut = Math.floor(idx.length * 0.75);
        const trainIdx = idx.slice(0, cut);
        const testIdx = idx.slice(cut);

        const scTrain = fitScaler(trainIdx.map((i) => X[i]));
        const mTrain = trainLogistic(trainIdx.map((i) => standardise(X[i], scTrain)), trainIdx.map((i) => y[i]));
        const metrics = evaluate({ mode: "trained", scaler: scTrain, ...mTrain }, testIdx.map((i) => X[i]), testIdx.map((i) => y[i]));

        // Final model on all data
        const scaler = fitScaler(X);
        const fit = trainLogistic(X.map((x) => standardise(x, scaler)), y);

        model = {
            mode: "trained",
            weights: fit.weights,
            bias: fit.bias,
            scaler,
            samples: X.length,
            positives,
            metrics,
            note: "Logistic regression trained on this institution's submission history."
        };
    }

    model.features = FEATURES;
    model.trained_at = new Date();
    currentModel = model;

    try {
        await getDB().collection("ai_models").updateOne(
            { name: "deadline_risk" },
            { $set: { name: "deadline_risk", ...model } },
            { upsert: true }
        );
    } catch (error) {
        console.warn("[AI] Could not store model:", error.message);
    }

    console.log(`[AI] Risk model ready (${model.mode}, ${model.samples} samples` +
        (model.metrics ? `, hold-out accuracy ${(model.metrics.accuracy * 100).toFixed(1)}%` : "") + ").");

    return model;
}

async function getModel() {
    return currentModel || trainModel();
}

// --------------------------------------------------
// Prediction with explanations
// --------------------------------------------------
function levelOf(p) {
    if (p >= 0.6) return "high";
    if (p >= 0.35) return "medium";
    return "low";
}

// Direction in which each feature makes a miss more likely.
const RISKY_DIRECTION = [1, -1, -1, 1, -1];

function explain(model, x, stats) {
    // Contribution of each feature relative to a typical student.
    const deviation = model.mode === "prior"
        ? x.map((v, j) => v - PRIOR.reference[j])
        : standardise(x, model.scaler);
    const weights = model.mode === "prior" ? PRIOR.weights : model.weights;
    const contrib = deviation.map((v, j) => weights[j] * v);

    const text = {
        missRate: stats.pastDeadlines
            ? `Missed ${stats.misses} of the last ${stats.pastDeadlines} deadlines`
            : "No submission history yet",
        leadDays: stats.avgLeadDays !== null
            ? `Usually submits about ${Math.round(stats.avgLeadDays * 24)} h before deadlines`
            : "No on-time submissions recorded yet",
        marksPct: stats.avgMarksPct !== null
            ? `Average marks ${Math.round(stats.avgMarksPct * 100)}%`
            : "No graded work yet",
        workload: `${stats.workload} other deadline${stats.workload === 1 ? "" : "s"} within 3 days`,
        windowDays: `${stats.windowDays.toFixed(1)} days given for this task`
    };

    return FEATURES
        .map((f, j) => ({
            feature: f,
            label: FEATURE_LABELS[f],
            impact: Number(contrib[j].toFixed(3)),
            // A reason is shown only if the student is on the risky side of typical
            // for that feature AND the model says it raises the risk.
            explains: contrib[j] > 0.1 && deviation[j] * RISKY_DIRECTION[j] > 0,
            reason: text[f]
        }))
        .sort((a, b) => b.impact - a.impact);
}

function suggestionFor(level, top) {
    if (level === "low") return "On track. Keep your usual pace.";
    const first = top[0] ? top[0].feature : "";
    if (first === "workload") return "Several deadlines overlap. Start this one today and plan your week.";
    if (first === "leadDays") return "You tend to submit late. Aim to upload a day before the deadline.";
    if (first === "marksPct") return "Ask your teacher about anything unclear before you start.";
    if (first === "windowDays") return "This task has a short window. Start as soon as possible.";
    return "Start early and upload a draft well before the deadline.";
}

// Risk for every open assignment of one student.
async function predictForStudent(studentId) {
    const model = await getModel();
    const snap = await loadSnapshot();
    const now = Date.now();
    const out = [];

    for (const classId of snap.classesOfStudent.get(studentId) || []) {
        for (const a of snap.byClass.get(classId) || []) {
            const due = toTime(a.due_date);
            if (Number.isNaN(due) || due <= now) continue;
            const sub = snap.submissionOf.get(`${a.id}:${studentId}`);
            if (sub && (sub.status === "verified" || sub.status === "submitted")) continue;

            const { x, stats } = featuresFor(snap, studentId, a, now);
            const probability = predictRaw(model, x);
            const level = levelOf(probability);
            const factors = explain(model, x, stats);
            const top = factors.filter((f) => f.explains).slice(0, 2);

            out.push({
                assignment_id: a.id,
                class_id: a.class_id,
                title: a.title,
                due_date: a.due_date,
                probability: Number(probability.toFixed(3)),
                level,
                reasons: top.map((f) => f.reason),
                suggestion: suggestionFor(level, top),
                factors
            });
        }
    }

    return { model: summary(model), predictions: out.sort((p, q) => q.probability - p.probability) };
}

// Risk for every student who has not yet submitted an open assignment.
async function predictForAssignments(assignmentFilter) {
    const model = await getModel();
    const snap = await loadSnapshot();
    const now = Date.now();
    const out = [];

    for (const a of snap.assignments.filter(assignmentFilter)) {
        const due = toTime(a.due_date);
        if (Number.isNaN(due) || due <= now) continue;

        for (const studentId of snap.studentsOfClass.get(a.class_id) || []) {
            const sub = snap.submissionOf.get(`${a.id}:${studentId}`);
            if (sub && (sub.status === "verified" || sub.status === "submitted")) continue;

            const { x, stats } = featuresFor(snap, studentId, a, now);
            const probability = predictRaw(model, x);
            const level = levelOf(probability);
            const top = explain(model, x, stats).filter((f) => f.explains).slice(0, 2);

            out.push({
                assignment_id: a.id,
                class_id: a.class_id,
                title: a.title,
                due_date: a.due_date,
                student_id: studentId,
                probability: Number(probability.toFixed(3)),
                level,
                reasons: top.map((f) => f.reason)
            });
        }
    }

    return { model: summary(model), predictions: out.sort((p, q) => q.probability - p.probability) };
}

function summary(model) {
    return {
        mode: model.mode,
        samples: model.samples,
        positives: model.positives,
        metrics: model.metrics,
        trained_at: model.trained_at,
        note: model.note,
        weights: FEATURES.map((f, j) => ({ feature: f, weight: Number(model.weights[j].toFixed(3)) }))
    };
}

module.exports = {
    trainModel,
    getModel,
    predictForStudent,
    predictForAssignments,
    summary,
    levelOf,
    FEATURES
};
