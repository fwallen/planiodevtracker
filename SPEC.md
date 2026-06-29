# Dev Task Tracker — Project Specification

## Overview

A personal developer task tracker built as a web application, designed to manage work requests
through a feedback-loop-aware pipeline. The core problem it solves: tasks frequently get sent
back to requesters and stall while waiting for a response. This app makes that "waiting" state
a first-class part of the workflow, with visibility into how long tasks have been blocked and
how many feedback rounds they've gone through.

This is a solo-use tool — no multi-user auth, no team features. It integrates with Plan.io to
import issues assigned to the developer, and layers personal status tracking on top.

---

## Tech Stack

| Layer      | Choice                          |
|------------|---------------------------------|
| Backend    | PHP 8.2+ with Slim Framework 4  |
| Frontend   | Alpine.js + htmx + TailwindCSS (all via CDN, no build) |
| Database   | MySQL 8                         |
| Container  | Docker + Docker Compose         |
| HTTP       | Apache (via php:8.2-apache image) |

---

## Docker Environment

All ports are in the 8900s to avoid collisions with other running environments.

```yaml
Services:
  app   → http://localhost:8980   (PHP/Slim/Apache)
  mysql → localhost:8981          (MySQL, exposed on host for TablePlus or any local DB client)
```

**Container naming prefix:** `devtracker_` (e.g. `devtracker_app`, `devtracker_mysql`)
**Network name:** `devtracker_net`
**Volume name:** `devtracker_mysql_data`

This ensures zero collision with any other Docker Compose project on the machine.

### docker-compose.yml outline

```yaml
version: '3.9'
services:
  app:
    container_name: devtracker_app
    build: .
    ports:
      - "8980:80"
    volumes:
      - ./src:/var/www/html
    depends_on:
      - mysql
    networks:
      - devtracker_net

  mysql:
    container_name: devtracker_mysql
    image: mysql:8
    ports:
      - "8981:3306"
    environment:
      MYSQL_DATABASE: devtracker
      MYSQL_USER: devtracker
      MYSQL_PASSWORD: devtracker
      MYSQL_ROOT_PASSWORD: root
    volumes:
      - devtracker_mysql_data:/var/lib/mysql
    networks:
      - devtracker_net


networks:
  devtracker_net:
    name: devtracker_net

volumes:
  devtracker_mysql_data:
    name: devtracker_mysql_data
```

---

## Directory Structure

```
/
├── docker-compose.yml
├── Dockerfile
├── .env                        # Plan.io API key, DB creds
├── src/
│   ├── public/                 # Apache document root
│   │   ├── index.html          # SPA shell; loads Alpine + htmx from CDN
│   │   ├── css/
│   │   │   └── app.css         # Minimal; Tailwind (Play CDN) does most styling
│   │   └── js/
│   │       ├── store.js        # Alpine.store('tasks') — global state + fetch logic
│   │       ├── board.js        # Alpine component: Kanban board
│   │       ├── taskCard.js     # Alpine component: task card / detail panel
│   │       └── api.js          # Thin fetch wrapper (JSON envelope handling)
│   ├── api/                    # Slim app root
│   │   ├── index.php           # Slim bootstrap + routes
│   │   ├── routes/
│   │   │   ├── tasks.php
│   │   │   ├── feedback.php
│   │   │   └── planio.php
│   │   ├── services/
│   │   │   └── PlanioService.php
│   │   └── db/
│   │       └── Database.php    # PDO singleton
│   └── composer.json
```

---

## Database Schema

### `tasks`
```sql
CREATE TABLE tasks (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  planio_issue_id INT DEFAULT NULL,          -- null if manually created
  title           VARCHAR(500) NOT NULL,
  project         VARCHAR(255) DEFAULT NULL,
  requester       VARCHAR(255) DEFAULT NULL,
  status          ENUM(
                    'new',
                    'in_progress',
                    'awaiting_feedback',
                    'feedback_received',
                    'done'
                  ) NOT NULL DEFAULT 'new',
  priority        TINYINT DEFAULT 2,         -- 1=low, 2=normal, 3=high
  due_date        DATE DEFAULT NULL,
  notes           TEXT DEFAULT NULL,
  feedback_rounds TINYINT DEFAULT 0,         -- increments each loop
  last_sent_at    DATETIME DEFAULT NULL,     -- when last sent for feedback
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### `feedback_log`
```sql
CREATE TABLE feedback_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  task_id     INT NOT NULL,
  sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note        TEXT DEFAULT NULL,             -- optional context when sending
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);
```

### `settings`
```sql
CREATE TABLE settings (
  key_name    VARCHAR(100) PRIMARY KEY,
  value       TEXT NOT NULL
);
-- Stores: planio_api_key, planio_base_url, planio_user_id, feedback_warning_days
```

---

## Task Status Pipeline

```
new → in_progress → awaiting_feedback → feedback_received → done
                          ↑                    |
                          └────────────────────┘
                          (loops on each feedback round)
```

Each transition to `awaiting_feedback` increments `feedback_rounds` and sets `last_sent_at`.
A log entry is created in `feedback_log` on every such transition.

---

## API Endpoints (Slim Routes)

### Tasks
| Method | Path               | Description                          |
|--------|--------------------|--------------------------------------|
| GET    | /api/tasks         | List all tasks (supports ?status=)   |
| POST   | /api/tasks         | Create a new task manually           |
| GET    | /api/tasks/{id}    | Get single task with feedback log    |
| PATCH  | /api/tasks/{id}    | Update task (status, notes, etc.)    |
| DELETE | /api/tasks/{id}    | Delete task                          |

### Feedback
| Method | Path                          | Description                        |
|--------|-------------------------------|------------------------------------|
| POST   | /api/tasks/{id}/send-feedback | Mark as awaiting feedback + log    |
| POST   | /api/tasks/{id}/got-feedback  | Mark feedback received             |

### Plan.io
| Method | Path               | Description                              |
|--------|--------------------|------------------------------------------|
| GET    | /api/planio/sync   | Pull issues assigned to me, upsert tasks |
| GET    | /api/planio/status | Test API key + return current user info  |

### Settings
| Method | Path            | Description               |
|--------|-----------------|---------------------------|
| GET    | /api/settings   | Get all settings          |
| POST   | /api/settings   | Save/update settings      |

---

## Plan.io Integration

### Authentication
- API key stored in the `settings` table (entered via the app's settings screen)
- All requests use HTTP Basic Auth: `{api_key}:X` as per Plan.io's API spec

### Sync Behavior
- Calls Plan.io REST API: `GET /issues.json?assigned_to_id=me&status_id=open`
- For each returned issue:
  - If `planio_issue_id` does not exist in `tasks` → insert as `status = 'new'`
  - If it already exists → update `title`, `project`, `due_date` only (do not overwrite local status)
- Sync does **not** delete tasks that are no longer in Plan.io (they may have been closed)
- Issues closed in Plan.io are ignored on sync; the developer manages `done` status locally

### Plan.io API Reference
- Base URL: `https://{your-subdomain}.plan.io`
- Issues endpoint: `/issues.json`
- Relevant fields to map:
  - `issue.id` → `planio_issue_id`
  - `issue.subject` → `title`
  - `issue.project.name` → `project`
  - `issue.assigned_to.name` → (confirm this is the current user)
  - `issue.due_date` → `due_date`
  - `issue.author.name` → `requester`

---

## Frontend — UI Design

### Rendering Approach
Alpine.js owns all UI state and rendering. The backend stays a pure JSON API (see
envelope convention below) — Alpine fetches JSON and builds the DOM client-side via
`x-data` / `x-for` / `x-show`. htmx is used only for lightweight server polling and
lazy-loading (e.g. periodic Plan.io sync refresh, deferred detail-panel loads), not for
HTML-fragment swaps. Both libraries are loaded from a CDN — no npm, no bundler, no build step.

A suggested split:
- `Alpine.store('tasks')` holds the task list and view filters; components read from it.
- Board, card, and detail-panel are Alpine components bound to that store.
- A status change calls the JSON API, then mutates the store optimistically.

### Layout
Single-page application. No page reloads. Two primary views toggled by a top nav:

**1. Board View (default)**
Kanban-style columns, one per status. Cards show:
- Task title
- Project name (subdued)
- Requester name
- Days in current status (auto-calculated)
- Feedback round count badge (only shown if > 0)
- Plan.io link icon (if linked to an issue)

**Awaiting Feedback** column highlights cards amber if `last_sent_at` is older than
`feedback_warning_days` (configurable, default 3 days) and red if older than 7 days.

**2. My Day View**
Filtered list: only tasks with status `in_progress` or `feedback_received`, sorted by
due date ascending (overdue at top). This is the "what do I work on today" view.

### Key Interactions
- **Quick-add bar** at top: type a task title, hit Enter → creates task in `new`
- **Card click** → opens a slide-out detail panel (title, notes, feedback log, status buttons)
- **Status buttons** in detail panel: move task forward/backward in pipeline with one click
- **Send for Feedback** button → prompts for an optional note, then transitions status
- **Sync Plan.io** button in header → triggers `/api/planio/sync`, shows count of new imports

### Settings Screen
Accessible from header icon. Fields:
- Plan.io base URL (e.g. `https://mycompany.plan.io`)
- Plan.io API key (password input)
- Feedback warning threshold (days, default 3)
- "Test Connection" button → hits `/api/planio/status`

---

## Environment Variables (.env)

```ini
DB_HOST=mysql
DB_PORT=3306
DB_NAME=devtracker
DB_USER=devtracker
DB_PASS=devtracker

PLANIO_BASE_URL=https://yoursubdomain.plan.io
PLANIO_API_KEY=your_api_key_here

FEEDBACK_WARNING_DAYS=3
```

---

## Dockerfile

```dockerfile
FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpdo-mysql \
    && docker-php-ext-install pdo pdo_mysql

RUN a2enmod rewrite

COPY ./src/public /var/www/html
COPY ./src/api /var/www/api
COPY ./apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www
```

Apache vhost routes `/api/*` to the Slim app and everything else to the SPA `index.html`.

---

## Build & Run Instructions

```bash
# First run
docker-compose up -d --build

# View logs
docker-compose logs -f app

# Stop
docker-compose down

# Destroy all data
docker-compose down -v
```

App available at: http://localhost:8980
MySQL available at: localhost:8981 (connect via TablePlus or any MySQL client)

---

## Out of Scope (for v1)

- User authentication (single-user tool, assumed local/private)
- Push notifications for feedback responses
- Two-way sync back to Plan.io (read-only from Plan.io)
- Mobile-specific layout (responsive is fine, not mobile-first)
- Export / reporting

---

## Notes for Claude Code

- Use Slim 4 with PHP-DI for dependency injection (standard Slim 4 skeleton)
- Use PDO for all DB access — no ORM
- Frontend uses Alpine.js + htmx, both from a CDN — no npm, no bundler, no build step
  - Alpine owns UI state/rendering against the JSON API; htmx only for polling/lazy-load
- Styling uses TailwindCSS via the **Play CDN** (`<script src="https://cdn.tailwindcss.com">`),
  keeping the no-build constraint. Configure theme tokens inline via `tailwind.config` in a
  `<script>` block in `index.html`. (If utility recompilation ever becomes a concern, the
  standalone Tailwind CLI is the upgrade path — it needs a build step but no npm project.)
- Keep `app.css` only for the few things Tailwind can't express cleanly (e.g. custom keyframes)
- `.env` values loaded via `vlucas/phpdotenv`
- All API responses return JSON with consistent envelope: `{ "success": true, "data": ... }`
- Error responses: `{ "success": false, "error": "message" }`
- The `src/public` directory is the Apache document root; the `src/api` directory sits outside it
