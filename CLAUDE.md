# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Current State

This repository currently contains **only `SPEC.md`** — a complete specification for the Dev Task Tracker. No application code, Docker files, or dependencies exist yet. When implementing, treat `SPEC.md` as the source of truth; it defines the directory layout, DB schema, API contract, and conventions in detail. The notes below summarize the architecture that requires reading across multiple parts of the spec.

## What This App Is

A **solo-use** developer task tracker (no auth, no multi-user). Its distinguishing concept: the "waiting for the requester" state is a first-class part of the pipeline. Tasks loop through `awaiting_feedback` ⇄ `feedback_received` repeatedly, and the app tracks how long each task has been blocked and how many feedback rounds it has been through.

## Commands

All commands run via Docker Compose (ports are in the 8900s to avoid collisions):

```bash
docker-compose up -d --build      # First run / rebuild
docker-compose logs -f app        # Tail app logs
docker-compose down               # Stop
docker-compose down -v            # Stop and destroy DB data
```

- App: http://localhost:8980
- MySQL (host access for TablePlus etc.): localhost:8981
- Container/network/volume prefix is `devtracker_` by design — keep it to guarantee zero collision with other Compose projects on the machine.

There is **no frontend build step**: Alpine.js, htmx, and TailwindCSS all load from a CDN — no npm, no bundler. PHP deps are managed by Composer (`src/composer.json`). No test framework is specified in the spec.

## Architecture

**Two separate roots inside one container** (this is the key structural decision):
- `src/public/` → Apache document root (SPA: `index.html`, `css/`, `js/`)
- `src/api/` → Slim 4 app, sits **outside** the document root. Apache vhost routes `/api/*` here; everything else falls through to the SPA shell.

**Backend** (Slim 4 + PHP-DI, PDO, no ORM):
- `api/index.php` — Slim bootstrap + route wiring
- `api/routes/{tasks,feedback,planio}.php` — route groups
- `api/services/PlanioService.php` — Plan.io REST client
- `api/db/Database.php` — PDO singleton
- `.env` loaded via `vlucas/phpdotenv`

**Frontend** (Alpine.js + htmx + TailwindCSS, all CDN, no build):
- Alpine owns all UI state and rendering. The backend stays a **pure JSON API**; Alpine fetches JSON and builds the DOM (`x-data`/`x-for`/`x-show`). htmx is used *only* for lightweight polling/lazy-load (e.g. periodic Plan.io sync), not HTML-fragment swaps.
- Suggested split: `Alpine.store('tasks')` (`js/store.js`) holds tasks + view filters; `board.js` and `taskCard.js` are Alpine components reading from the store; `api.js` is a thin fetch wrapper that unwraps the JSON envelope. Status changes call the API then mutate the store optimistically.
- TailwindCSS via the **Play CDN** (`<script src="https://cdn.tailwindcss.com">`); theme tokens go in an inline `tailwind.config` `<script>` in `index.html`. `app.css` is minimal (only what utilities can't express). Standalone Tailwind CLI is the upgrade path if recompilation is ever needed.

## Core Domain Logic

**Status pipeline:** `new → in_progress → awaiting_feedback → feedback_received → done`, where `awaiting_feedback ⇄ feedback_received` loops on each round.

**The critical invariant:** every transition *into* `awaiting_feedback` must (1) increment `tasks.feedback_rounds`, (2) set `tasks.last_sent_at = now`, and (3) insert a row into `feedback_log`. This is what powers the "days blocked" and round-count UI. Implement it in one place (the send-feedback handler) so the three side effects can't drift apart.

**Plan.io sync** (`GET /api/planio/sync`, read-only, HTTP Basic Auth `{api_key}:X`):
- New issue (no matching `planio_issue_id`) → insert as `status='new'`.
- Existing issue → update **only** `title`, `project`, `due_date`. **Never overwrite local `status`** — the developer owns status locally.
- Sync never deletes tasks; closed Plan.io issues are simply ignored.

## Conventions

- **API envelope (always):** success → `{ "success": true, "data": ... }`; error → `{ "success": false, "error": "message" }`.
- PDO for all DB access — no ORM.
- Settings (Plan.io URL, API key, `feedback_warning_days`) live in the `settings` table and are editable in-app, *not* only in `.env`. The Plan.io API key entered via the UI is the source of truth for auth.
- UI thresholds: Awaiting Feedback cards turn amber when `last_sent_at` is older than `feedback_warning_days` (default 3) and red after 7 days.
