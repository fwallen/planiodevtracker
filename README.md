# Dev Task Tracker

A solo-use developer task tracker built around a **feedback-loop-aware pipeline**. Its
distinguishing idea: the "waiting for the requester" state is a first-class part of the
workflow. Tasks loop through `awaiting_feedback ⇄ feedback_received` as many rounds as it
takes, and the app tracks how long each task has been blocked and how many rounds it has been
through. It optionally imports issues assigned to you from [Plan.io](https://plan.io) and
layers personal status tracking on top.

No auth, no multi-user, no team features — just you and your work queue.

## Tech Stack

| Layer     | Choice                                                     |
|-----------|------------------------------------------------------------|
| Backend   | PHP 8.4 + Slim 4 (PHP-DI, PDO — no ORM)                     |
| Frontend  | Alpine.js + htmx + TailwindCSS, all via CDN — **no build**  |
| Database  | MySQL 8                                                    |
| Runtime   | Apache (`php:8.4-apache`), orchestrated with Docker Compose |
| Migrations| [Phoenix](https://github.com/lulco/phoenix)                |

Everything runs in Docker. You do **not** need PHP, Composer, or Node installed on your host.

---

## Requirements

- Docker + Docker Compose
- Ports **8980** and **8981** free on the host (chosen to avoid collisions with other projects)

---

## Installation

```bash
# 1. Clone
git clone <repo-url> devtracker
cd devtracker

# 2. Create your .env from the template
cp .env.example .env
#    The DB defaults work out of the box. Plan.io is configured
#    in-app via the Settings screen, not here (see below).

# 3. Build and start the containers (installs PHP deps as part of the build)
./dev build          # == docker-compose up -d --build

# 4. Run the database migrations (required on first run)
./dev migrate
```

Then open **http://localhost:8980**.

> **Note:** Composer dependencies are installed during `./dev build` and live in the
> `devtracker_vendor` volume (not on the host), so the `src/api` bind mount doesn't shadow
> them. If you later change `composer.json`, refresh them with `./dev composer install`.

> **Note:** Migrations are **not** run automatically on boot. After the very first
> `./dev build` — and any time new migrations are added — run `./dev migrate`.

### Services

| Service | URL / Host             | Notes                                            |
|---------|------------------------|--------------------------------------------------|
| App     | http://localhost:8980  | The SPA + JSON API                               |
| MySQL   | `localhost:8981`       | For TablePlus / any DB client. DB `devtracker`, user `devtracker`, password `devtracker` |

Docker resources are all prefixed `devtracker_` (`devtracker_app`, `devtracker_mysql`,
`devtracker_net`, `devtracker_mysql_data`) so they won't collide with other Compose projects.

---

## The `./dev` helper

A thin wrapper around `docker-compose` and the container tooling:

```
./dev up               Start containers (no rebuild)
./dev build            Start containers with a rebuild
./dev down             Stop containers
./dev destroy          Stop containers and DELETE the DB volume (wipes all data)
./dev logs [service]   Tail logs (default: app)
./dev shell            Open a bash shell in the app container
./dev composer <args>  Run Composer inside the container (e.g. ./dev composer require foo/bar)
./dev migrate          Run pending migrations
./dev migrate:status   Show applied / pending migrations
./dev migrate:create <Name>   Scaffold a new migration
./dev mysql            Open a MySQL shell
./dev ps               Show container status
```

Equivalent raw Docker Compose commands, if you prefer:

```bash
docker-compose up -d --build      # first run / rebuild
docker-compose logs -f app        # tail app logs
docker-compose down               # stop
docker-compose down -v            # stop and destroy the DB volume
```

---

## Usage

### The pipeline

```
new → in_progress → awaiting_feedback → feedback_received → done
                          ↑                    |
                          └────────────────────┘
                          (loops on each feedback round)
```

There is also an **`on_hold`** state for tasks parked outside the main flow.

Every transition **into** `awaiting_feedback` does three things atomically: increments the
task's feedback-round count, stamps `last_sent_at`, and writes a row to the feedback log.
This is what powers the "days blocked" and round-count indicators.

### Views

- **Board (default)** — Kanban columns, one per status. Cards show title, project, requester,
  days in current status, a feedback-round badge (when > 0), and a Plan.io link icon when
  linked. In the **Awaiting Feedback** column, cards turn **amber** once `last_sent_at` is
  older than your feedback-warning threshold (default 3 days) and **red** after 7 days.
- **My Day** — a focused list of just `in_progress` and `feedback_received` tasks, sorted by
  due date (overdue first): "what should I work on today."

### Common actions

- **Quick-add**: type a title in the bar at the top and press Enter to create a `new` task.
- **Card click**: opens a slide-out detail panel with notes, feedback log, reference links,
  and status buttons.
- **Send for Feedback**: prompts for an optional note, then moves the task to
  `awaiting_feedback` and logs the round.
- **Sync Plan.io**: the header button pulls issues assigned to you and reports how many were
  newly imported.

---

## Plan.io Integration (optional)

Read-only import of issues assigned to you. Configure it from the in-app **Settings** screen
(the gear icon in the header) — the values entered there are the source of truth:

- **Base URL** — e.g. `https://mycompany.plan.io`
- **API key** — used with HTTP Basic Auth (`{api_key}:X`)
- **Feedback warning threshold** — days before an awaiting card turns amber (default 3)

Use **Test Connection** to verify the key. Then **Sync Plan.io** from the header.

Sync behavior:

- New issues (no matching `planio_issue_id`) are inserted with status `new`.
- Existing issues update `title`, `project`, `assignee`, `due_date`, and deploy approval. You own
  status locally, so it's preserved — with two exceptions:
  - An issue **resolved, closed, or done** in Plan.io forces the task to `done` (a `rejected`
    issue does not).
  - A **deploy approval** (staging or production) nudges an in-flight task to `feedback_received`.
- Sync never deletes tasks.

---

## Configuration

Only the database connection lives in `.env` (copied from `.env.example`):

```ini
DB_HOST=mysql
DB_PORT=3306
DB_NAME=devtracker
DB_USER=devtracker
DB_PASS=devtracker
```

`.env` is git-ignored. Plan.io credentials and the feedback-warning threshold are configured
entirely in the app's **Settings** screen (stored in the `settings` table) — there are no
environment variables for them.

---

## Project Layout

```
docker-compose.yml, Dockerfile, apache.conf, dev   # infra + helper script
src/
├── public/                 # Apache document root (the SPA)
│   ├── index.html          # SPA shell; loads Alpine/htmx/Tailwind from CDN
│   ├── css/app.css
│   └── js/                 # store.js, board.js, taskCard.js, settings.js, api.js
└── api/                    # Slim app — sits OUTSIDE the document root
    ├── public/index.php    # Slim bootstrap + route wiring
    ├── routes/             # tasks.php, feedback.php, planio.php, settings.php
    ├── services/PlanioService.php
    ├── db/Database.php      # PDO singleton
    ├── migrations/          # Phoenix migrations
    └── composer.json
```

Apache routes `/api/*` to the Slim app; everything else falls through to the SPA shell.

### API envelope

All responses use a consistent envelope:

```json
{ "success": true,  "data": ... }
{ "success": false, "error": "message" }
```

| Method | Path                            | Description                              |
|--------|---------------------------------|------------------------------------------|
| GET    | `/api/tasks`                    | List tasks (supports `?status=`)         |
| POST   | `/api/tasks`                    | Create a task manually                   |
| GET    | `/api/tasks/{id}`               | Get a task with its feedback log         |
| PATCH  | `/api/tasks/{id}`               | Update a task (status, notes, etc.)      |
| DELETE | `/api/tasks/{id}`               | Delete a task                            |
| POST   | `/api/tasks/{id}/send-feedback` | Move to `awaiting_feedback` + log a round |
| POST   | `/api/tasks/{id}/got-feedback`  | Move to `feedback_received`              |
| GET    | `/api/planio/sync`              | Import issues assigned to you            |
| GET    | `/api/planio/status`            | Test the API key / return current user   |
| GET    | `/api/settings`                 | Get all settings                         |
| POST   | `/api/settings`                 | Save settings                            |

---

## Troubleshooting

- **Blank page or 500s right after first start** — you probably haven't run `./dev migrate` yet.
- **`Bind for 0.0.0.0:8980 failed`** — another process is using port 8980 or 8981; free it or
  change the host-side port mappings in `docker-compose.yml`.
- **Plan.io sync returns nothing** — check the base URL and API key in Settings and use
  **Test Connection**. Only *open* issues assigned to you are imported.
- **Start over from a clean database** — `./dev destroy` (deletes the volume), then
  `./dev build` and `./dev migrate` again.
```
