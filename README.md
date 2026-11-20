# EduTracker (MongoDB Atlas version)

Academic management system for admins, teachers and students: departments, sections,
teacher assignment, student enrolment, assignments with PDF upload, grading and
e-mail deadline reminders.

## How the pieces connect

```
Browser
  │
  ▼
PHP frontend  (frontend/pages/*.php, served by Apache / XAMPP / Docker)
  │   every page includes  php/config/database.php
  │   → cURL to Express with header  x-api-key: API_KEY
  ▼
Express API   (server/server.js → routes/ → controllers/)
  │   every controller uses  require("../config/db")  → getDB(), nextId()
  ▼
MongoDB Atlas (database "edutracker")
```

PHP never connects to the database. Only the Node API talks to MongoDB.

## Collections

Every document has a numeric `id` (the PHP pages use these numbers). A `counters`
collection hands out the next id, and on startup each counter is moved past the highest
`id` already stored, so existing data is kept and never collides.

| Collection | Holds | Unique rule |
|---|---|---|
| `users` | admins, teachers, students | `id`, `username`, `email`, `roll_number`, `employee_id` |
| `departments` | department names | `department_name` |
| `classes` | sections (department, section, subject, capacity) | department + section + subject |
| `student_classes` | which student is in which section | student + class |
| `teacher_classes` | which teacher teaches which section | teacher + class |
| `assignments` | assignments with `due_date` | `id` |
| `assignment_submissions` | PDF / answer, status, marks, feedback | assignment + student |
| `assignment_reminder_log` | reminder e-mails already sent | assignment + student |
| `ai_models` | trained AI risk model | `name` |
| `ai_early_reminder_log` | AI early reminders already sent | assignment + student |
| `counters` | next id per collection | – |

Indexes and the first admin account are created automatically when the API starts.

## AI feature: deadline risk prediction

EduTracker includes a machine-learning model that predicts how likely each student is
to **miss** an open assignment deadline. It is in `server/ai/` and needs no extra npm packages.

**How it works**
- **Model:** logistic regression, trained in pure JavaScript (gradient descent, L2 regularisation,
  standardised features, class weighting).
- **Features** (taken only from data available before the assignment, so there is no look-ahead):
  past miss rate, average submission lead time, average marks, overlapping deadlines within
  ±3 days, and days given for the task.
- **Label:** missed = no submission on or before the due date.
- **Evaluation:** 25% hold-out split giving accuracy, precision, recall and ROC-AUC, then refitted on all data.
- **Cold start:** until 30 labelled student–assignment records with both outcomes exist, the model
  uses expert prior weights (`mode: "prior"`). After that it switches to `mode: "trained"` automatically.
- **Retraining:** on API start-up, every hour, and on demand ("Retrain model" button).
- **Explanations:** each prediction lists the factors that raised the risk
  (e.g. "Usually submits about 3 h before deadlines").

**Where it appears**
- **Teacher dashboard → AI Insights:** at-risk students for the teacher's open assignments, with
  probability, risk level and reasons, plus model accuracy and ROC-AUC.
- **Student dashboard:** an "AI assistant" tip and an AI risk badge on every open assignment.
- **Risk-aware early reminder:** about 72 hours before a deadline, students predicted to be at
  **high** risk receive an extra e-mail. Everyone still gets the normal 24-hour reminder.

**API** (all need `x-api-key`)

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/ai/model` | Model mode, weights, hold-out metrics |
| POST | `/api/ai/train` | Retrain now |
| GET | `/api/ai/student-risk?student_id=` | Risk for each open assignment of a student |
| GET | `/api/ai/teacher-risk?teacher_id=` | Risk for students in the teacher's sections |

New collections: `ai_models` (stored model) and `ai_early_reminder_log` (unique assignment + student).
Indexes are created automatically on start-up.

Risk levels: high ≥ 60%, medium ≥ 35%, low < 35%. Predictions are a prompt for teachers to check
in with students. They are not a judgement of the student.

## Run locally in VS Code

**You need:** Node.js 20+, XAMPP (only **Apache**: MySQL is not used) and a MongoDB Atlas cluster.

1. Copy the folder to `C:\xampp\htdocs\EduTracker` and open it in VS Code.
2. Start **Apache** in XAMPP.
3. In MongoDB Atlas → **Network Access**, add your IP address (or `0.0.0.0/0`).
4. Check `server/.env`:
   ```
   MONGODB_URI=mongodb+srv://<user>:<password>@<cluster>.mongodb.net/
   MONGODB_DB_NAME=edutracker
   API_KEY=any-long-random-string
   API_BASE_URL=http://127.0.0.1:3000/api
   FILE_VIEW_BASE_URL=http://localhost:3000
   ADMIN_USERNAME=admin
   ADMIN_PASSWORD=Admin@123
   ```
5. In the VS Code terminal:
   ```
   cd server
   npm install
   npm start
   ```
   You should see `MongoDB Atlas connected. Database: edutracker` and `Database ready.`
6. Open **http://localhost/EduTracker/** and sign in as **Admin** (`admin` / `Admin@123`,
   unless an admin already exists in your Atlas data).

## Deploy on Render (one service, one URL)

On Render everything runs in ONE Docker web service:
Apache/PHP serves the pages on Render's port, Node/Express runs inside the same
container on 127.0.0.1:3000, and Apache forwards `/api/` and `/uploads/` to it.
Files: `Dockerfile`, `docker/start.sh`, `docker/apache-edutracker.conf`.

1. Atlas → Database & Network Access → IP Access List → add `0.0.0.0/0`.
2. Push this folder to GitHub (`server/.env` is never uploaded).
3. Render → New → Web Service → your repo → Language **Docker**, Root Directory empty,
   Health Check Path `/api/health`, Free plan.
4. Environment variables: `MONGODB_URI`, `MONGODB_DB_NAME=edutracker`, `ADMIN_USERNAME`,
   `ADMIN_PASSWORD`, `NODE_ENV=production`, optional `EMAIL_USER`/`EMAIL_PASS`.
   `API_KEY` is optional (generated at start if missing). Do NOT set `PORT`,
   `API_BASE_URL` or `FILE_VIEW_BASE_URL`: the container sets them itself.
5. Open the service URL. That one link is the whole app.

(Or New → Blueprint: `render.yaml` defines this single service.)

### Render free-tier notes
- Free services sleep after ~15 minutes idle; the first request can take up to a minute.
- Uploaded PDFs live on Render's temporary disk and are lost on restart/redeploy.
  Everything else is safe in Atlas.
- Deadline reminders run only while the API is awake.

## Security
- Passwords are hashed with bcrypt.
- PHP validates forms server-side.
- Role-based access with PHP sessions.
- Every API route except login needs the shared `x-api-key`.
- Unique indexes in MongoDB stop duplicate usernames, e-mails, enrolments and submissions.
