# TicketingOS — PHP edition

A port of the TicketingOS multi-tenant helpdesk (originally Next.js 16 + Prisma)
to **plain PHP 8.1+, HTML and vanilla JavaScript**. No framework, no Composer
packages, no Node.js at runtime. It runs on any ordinary PHP host (Apache, nginx +
PHP-FPM, or PHP's built-in server).

Every customer organization gets a **fully isolated board**: its own users, agents,
categories, statuses, priorities and tickets. A platform operator (super admin) can
oversee every tenant and step into any board to manage it.

## Features (same as the original)

- 🔐 **Auth & roles**: HS256 JWT session in an httpOnly cookie, bcrypt passwords. Roles: `SUPER_ADMIN`, `ADMIN`, `AGENT`, `CUSTOMER`
- 🏢 **Multi-tenancy**: strict `tenantId` scoping on every query; self-serve org signup
- 🎫 **Tickets**: per-tenant numbering, assignment, categories, SLA due dates
- 💬 **Conversations**: threaded replies and staff-only internal notes
- 📝 **Rich text editor** (dependency-free contenteditable): headings, bold/italic/underline/strike, code, colours, highlight, lists, task lists, quotes, code blocks, alignment, links, image upload, tables, canned responses, @mentions. HTML is sanitized server-side against an allowlist
- 📎 **Attachments**: private storage, tenant-isolated download endpoint
- 🗂️ **Kanban board** with drag and drop, plus a list view with **bulk actions**
- 🎨 Custom **statuses & priorities**, ⏱️ **SLA** targets, 🏷️ **tags**, 💡 **canned responses**, 🔖 **saved views**
- 🤖 **Automations**: rules that run when a ticket is created (assign, set priority/status, add tag)
- 👁️ **Watchers / team** (Viewer / Collaborator) and a full **activity timeline**
- 🔔 **Notifications**: in-app (live via Server-Sent Events + toasts), **Web Push** (VAPID, implemented with ext-openssl) and **email** (built-in SMTP client or Microsoft 365 Graph). Per-user preferences (channels, quiet hours, priority threshold, scope) are combined with a per-tenant policy
- 🛰️ **Platform console** with tenant impersonation, 🔑 password reset, 📊 dashboard with SVG charts, 🌗 dark mode, responsive layout

## Added in the PHP edition

- 📱 **Installable PWA**: web app manifest with icons and home-screen shortcuts (New ticket, My tickets, Overdue), install button, offline copy of recently opened pages, offline banner, app-icon badge with the unread count, pull-to-refresh, notification icons/badge and click-to-open in the service worker
- 📲 **Mobile-first UI**: bottom tab bar with a central “+”, compact header with expandable search, full-width menus, collapsible filters, swipeable board columns, ticket properties above the conversation, single-row editor toolbar, floating bulk-action bar, safe-area support for notched phones, 16px inputs (no iOS zoom)
- 🔎 **Smart search** across subject, description, public replies, requester (name/email), assignee, category and tags, with **live suggestions** as you type (keyboard navigable, matches highlighted) and operators:
  `status:open` `priority:high` `assignee:me|none|dana` `requester:jamie` `tag:bug` `category:billing` `is:open|closed|overdue|unassigned|mine` `created:today|7d|2026-01-31` `#42` `"exact phrase"`. Greek accents are ignored (`επιλυση` finds `επίλυση`)
- ⚡ **Large data**: list pagination (50/page), board columns capped at 50 cards with real totals, paginated/searchable Team and Platform pages, extra composite indexes, a denormalised search document with a MariaDB FULLTEXT index
- ✨ **Productivity**: “My tickets / Unassigned / Overdue” quick filters, assignee filter, **CSV export** of the filtered list (Excel-friendly UTF-8), ticket quick actions (Assign to me, Mark resolved, Share link), settings split into tabs

- 🧭 **Sorting**: newest, oldest, recently updated, highest priority, due soonest, ticket number
- 🪄 **Macros** (Settings → Tags & replies): one click posts a reply or internal note (with `{{requester.first_name}}`, `{{agent.name}}`, `{{ticket.number}}` … placeholders) and changes status / priority / assignee / tags
- 🔗 **Merge & link**: “Merge into…” moves the original request, replies, attachments, people and tags into another ticket, closes the duplicate and notifies its requester; “Link ticket” connects related tickets (shown on both)
- 👀 **Collision detection**: agents see who else is viewing or replying to a ticket right now, and get a “new reply since you opened this” warning
- 📚 **Knowledge base** (`/kb`): sections, drafts, views and “Was this helpful?” counts; customers get matching articles suggested while they type a new ticket's subject (English and Greek stop-words ignored)
- 📱 **Configurable bottom menu** (Settings → General → Mobile bottom menu): pick up to 4 shortcuts (Home, Tickets, Mine, Unassigned, Overdue, Board, Help, Reports, Alerts, Team, Settings) separately for staff and for customers, show/hide the round “+ New ticket” button, with a live preview; admin-only items are hidden from agents automatically
- 👥 **Users** (Settings → People & access → Users; `/team` redirects there). Overview tiles plus filters by role, custom role and status (including staff who never signed in):
  - **Users**: search, filter by role / custom role / deactivated, paging, and inline edit of name, email, role and permissions role.
  - **Account actions**: deactivate or reactivate (blocks sign-in and signs the user out), send a reset link or set a password, and remove. The app shows the link to copy when email isn't set up.
  - **Invite**: by email (link valid 72h) or with a temporary password.
- 🛡️ **Permissions**:
  - 19 permissions in four groups:
    - Tickets: see all, reply to customers, internal notes, edit, assign, tag, manage followers, macros
    - Bulk & data: bulk edit, merge & link, export
    - Knowledge & insights: articles, reports
    - Administration: manage users, plus one permission per settings section (general, workflow, replies library, automations, notifications & email)
  - **Roles & permissions** tab: a list of roles and an editor with toggle switches. It includes per-group “all” switches, “Sensitive” markers, dependencies (bulk edit needs edit) and an unsaved-changes bar. New roles can start from a template (Read-only, Junior agent, Team lead, Content editor) or duplicate an existing role. A compare table shows every role side by side.
  - Existing roles keep their old abilities: `bin/migrate.php` adds the new reply / tag / follower permissions to them once.
  - Admins can edit the built-in **Agent** role and create **custom roles**, with a colour, a description and an overview matrix.
  - Agents without “see all tickets” only see tickets they requested, are assigned or watch.
  - Locked fields and buttons are hidden or disabled in the UI and enforced on the server.
  - A user with “manage users” can't touch admins or grant admin.
  - The workspace always keeps one active admin.
- 🔐 **Security** tab: admin backup warning, deactivated and never-signed-in counts, and a minimum password length slider with a letters+numbers rule. The rules apply to invites, resets and admin-set passwords.
- 📜 **Audit log** (Settings → Audit log, admins):
  - Records every change to users, roles, groups, organizations, settings, integrations and scheduled reports, plus staff sign-ins (also failed and blocked ones), bulk edits, merges and CSV exports.
  - Filters by activity, person and period, with a details view and CSV export. Secrets and one-time links are never stored.
- 👥 **Groups** (Settings → Groups):
  - Teams such as Billing or Technical, with members and a colour.
  - New tickets in a group are assigned **manually**, **round robin** or to the **least busy** member.
  - Category → group routing.
  - Group members always see their group's tickets, even without "see all tickets".
  - Filters `group=mine|none|<id>`, a "My groups" bottom-menu item, and the search operator `group:billing`.
- 🏢 **Organizations** (Settings → Organizations):
  - Customer companies with email domains. New customers join automatically, from invites, the support form or the API.
  - **Organization managers** see all of their company's tickets.
  - Org notes appear for staff on the ticket. There is a filter `org=<id>`, a search operator `org:acme` and a "Top organizations" report.
- 🧩 **Custom ticket fields** (Settings → Ticket fields & CSAT):
  - Types: short/long text, number, date, dropdown and checkbox. Each field can be required, staff-only, and shown for all categories or for one.
  - They appear on the new-ticket and support forms (following the chosen category) and in the ticket sidebar, where changes are logged in the timeline.
  - They are also in the CSV export, the API and search (`order_number:ORD-55`).
- 😊 **Satisfaction surveys (CSAT)**:
  - When staff close a ticket, the customer gets a one-click 1–5 rating, by email (`/csat/{token}`) and on the ticket page.
  - Low ratings notify the agent.
  - Reports show a Satisfaction KPI, a per-agent column and recent comments. The search operator `rating:<3` finds rated tickets.
- 📅 **Scheduled report emails** (Reports → Scheduled emails):
  - Daily, weekly or monthly summaries (KPIs, SLA, CSAT, top agents) sent to staff who can view reports.
  - Includes Preview and "Send now".
  - Sending runs from `php bin/cron.php`, or `GET /cron?key=CRON_KEY` on hosts without cron.
- 🔌 **REST API** (`/api/v1`):
  - Uses Bearer API keys (read or read & write), shown once and stored hashed. A key acts with its creator's permissions and is limited to 120 requests a minute.
  - Endpoints: tickets (list/filter, create with a new requester, get, patch, comments), users, organizations, groups, fields and meta. The reference is in Settings → Integrations.
- 🪝 **Webhooks**:
  - Events: `ticket.created`, `ticket.updated`, `ticket.closed`, `comment.created` and `csat.received`.
  - Formats: signed JSON (`X-TicketingOS-Signature: sha256=HMAC`), Slack or Microsoft Teams.
  - Deliveries are sent after the response. Failures are retried with backoff (by the cron), and each webhook shows a delivery log and a test button.
- 🌐 **Public support form & website widget**:
  - `/support/{workspace}` lets anyone open a ticket without an account, with category, custom fields and help-center suggestions. Senders become customers and get a link to follow their ticket.
  - Embed it on any site with `<script src=".../widget.js" data-workspace="acme" async></script>`.
  - Spam protection: a hidden field, a signed fill-time check and per-IP rate limits.
- 📈 **Reports** (`/reports`, staff): created vs resolved, median first response and resolution time, SLA met %, backlog/overdue, breakdowns by priority and category, per-agent table — for 7 / 30 / 90 days or 12 months, with change vs the previous period

### Performance (measured locally)

MariaDB 13, 33 tenants / 35,012 tickets / ~70,000 replies, one tenant with 20,000 tickets
(`php bin/seed-bulk.php`). Response times for that 20k-ticket tenant, PHP built-in server:

| Page | Time |
| --- | --- |
| Ticket list (any page) | ~0.03–0.05 s |
| Board | ~0.05 s |
| Dashboard | ~0.08 s |
| Search with operators (`status:open priority:urgent is:overdue`) | ~0.07 s |
| Free-text search / live suggestions | ~0.13–0.18 s |
| CSV export (~5,000 rows) | ~0.28 s |
| Sorted list (priority / due) | ~0.10 s |
| Reports, 30 days / 12 months | ~0.10 s / ~0.34 s |

## Requirements

- PHP **8.1+** with `pdo_sqlite` (or `pdo_mysql`), `openssl`, `mbstring`, `curl`
- Optional: `gd` (an extra check on uploaded images)

> **Windows / WinGet PHP:** `pdo_sqlite` ships but is disabled by default. Enable it
> in `php.ini` (`extension=pdo_sqlite`) or pass `-d extension=pdo_sqlite` as shown below.

## Getting started

```bash
cp .env.example .env            # then set AUTH_SECRET (see below)
php bin/migrate.php             # create the SQLite schema (storage/database.sqlite)
php bin/seed.php                # load demo tenants + data
php -S localhost:8000 -t public public/index.php
```

Open http://localhost:8000. On Windows without `pdo_sqlite` in php.ini, add
`-d extension=pdo_sqlite` after `php` in each command.

### Environment (`.env`)

| Key | Purpose |
| --- | --- |
| `APP_URL` | Base URL used in email and push links |
| `APP_TIMEZONE` | Display timezone and quiet hours |
| `DB_DRIVER` | `sqlite` (default) or `mysql` (+ `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`) |
| `AUTH_SECRET` | Signs sessions and derives the AES-256-GCM key for stored SMTP/M365 secrets. Generate with `php -r "echo bin2hex(random_bytes(48)), PHP_EOL;"` |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` / `VAPID_SUBJECT` | Web Push keys. Generate with `php bin/vapid-keys.php` |

Encrypted secrets use the same format as the Node version (`enc:v1:…`), so with
the same `AUTH_SECRET` they stay readable.

### Using MariaDB (recommended for many tenants)

```sql
CREATE DATABASE ticketingos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ticketingos'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL ON ticketingos.* TO 'ticketingos'@'localhost';
```

Set `DB_DRIVER="mysql"` and the `DB_*` keys in `.env`, then `php bin/migrate.php` and
`php bin/seed.php`. `migrate.php` is safe to re-run: it adds new columns/indexes to an
existing database and builds the search index for tickets that don't have one.

**Local development on this machine** uses a private MariaDB instance whose data
lives in `storage/mariadb` (port 3307), started from `.claude/launch.json` (`mariadb`)
or by hand:

```bash
"C:/Program Files/MariaDB 13.0/bin/mariadbd.exe" --defaults-file=storage/mariadb/my.ini --console
```

### Load-test data

```bash
php bin/seed-bulk.php --tenants=30 --tickets=500 --big=20000
php bin/seed-extras.php   # demo knowledge base + macros for tenants without them
```

Adds 30 tenants × 500 tickets plus "Initech" with 20,000 tickets
(`admin@initech.dev` / `password123`). Existing data is kept.

### PWA and push on phones

Installing the app and Web Push need **HTTPS** on a real domain (browsers allow
plain HTTP only on `localhost`). On iPhone/iPad, push works only after the app is
added to the Home Screen (iOS 16.4+). Regenerate icons with `php bin/make-icons.php`.
After changing `public/sw.js`, bump its `VERSION` so clients drop old caches.

## Demo accounts

All passwords: `password123`

| Role              | Email                    |
| ----------------- | ------------------------ |
| Platform operator | info@softify.gr          |
| Acme: Admin       | admin@acme.dev           |
| Acme: Agent       | sofia@acme.dev           |
| Acme: Customer    | jamie@acme-customer.com  |
| Globex: Admin     | admin@globex.dev         |
| Globex: Customer  | leo@globex-customer.com  |

## Background jobs

Run `php bin/cron.php` every 5 minutes (scheduled reports, webhook retries, cleanup). Without a CLI cron, set `CRON_KEY` in `.env` and call `GET /cron?key=…` from any external scheduler.

- **Linux:** `*/5 * * * * php /path/to/bin/cron.php`
- **Windows** (in an *administrator* PowerShell):
  `schtasks /Create /TN "TicketingOS cron" /SC MINUTE /MO 5 /TR "\"C:path	ophp-win.exe\" \"C:path	oincron.php\" --log"`
  (`php-win.exe` runs without a console window; `--log` appends each run to `storage/cron.log`).
- **Development:** `php bin/cron-loop.php` runs it every 5 minutes for as long as it stays open.

## Email in development

`php bin/mail-catcher.php` starts a local SMTP server on `127.0.0.1:1025` that saves every message to `storage/mail/` instead of delivering it. `php bin/dev-mail-setup.php` points the demo workspaces at it, and admins can read the caught messages at **/dev/mail** (only with `APP_DEBUG=1`). For real delivery, enter your SMTP or Microsoft 365 details in Settings → Email.

## Deploying

**Apache**: point the document root at `public/`. `public/.htaccess` routes every
request to `index.php`.

**nginx**:

```nginx
root /var/www/ticketingos/public;
location / { try_files $uri /index.php$is_args$args; }
location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
location /api/stream { fastcgi_buffering off; include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
```

Make `storage/` writable by the web server; it holds the SQLite database and uploads
and must **not** be web-accessible (it lives outside `public/`).

**MySQL / MariaDB**: set `DB_DRIVER=mysql` and the `DB_*` keys, create the database,
then run `php bin/migrate.php`. The SQLite DDL is translated automatically.

## How it maps to the original

| Next.js original | PHP edition |
| --- | --- |
| `src/app/**/page.tsx` (server components) | `src/views/pages/*.php` |
| `src/components/**` | `src/views/partials/components.php`, `src/views/layouts/*` and `public/assets/*.js` |
| Server actions (`src/lib/actions/*`) | `src/actions/*.php`, POSTed to `/action/{name}` (CSRF-protected, PRG or JSON) |
| Route handlers (`src/app/api/*`) | `src/api.php` |
| Prisma schema | `database/schema.sqlite.sql` (+ automatic MySQL translation in `bin/migrate.php`) |
| Tiptap editor | `public/assets/editor.js` |
| Recharts | server-rendered SVG in `components.php` |
| Lucide React | inline SVGs in `src/lib/icons_data.php` (generated from Lucide, ISC licence) |
| Tailwind (build-time) | precompiled `public/assets/app.css` |
| In-process SSE pub/sub | `/api/stream` reads new `Notification` rows since `Last-Event-ID`; the browser's EventSource reconnects every ~4 s. This works on any PHP SAPI, including the single-threaded dev server |

## Project structure

```
public/            web root: index.php (front controller), .htaccess, sw.js, assets/
src/bootstrap.php  loads .env + modules
src/lib/           db, auth/session, crypto, sanitize, storage, tenant config, email/smtp, push, notify, view helpers
src/actions/       mutations (auth, tickets, admin settings, per-user)
src/api.php        attachments, editor images, push subscribe/test, SSE stream
src/views/         layouts, pages, partials
database/          schema
bin/               migrate.php, seed.php, vapid-keys.php
tools/             optional: rebuild CSS / icons with Node (not needed to run the app)
storage/           SQLite database + uploads (git-ignored)
```

## Changing styles

The templates use Tailwind utility classes, and the compiled CSS is committed. After you change
classes, rebuild it (Node 18+ needed only for this step):

```bash
cd tools && npm install && cd ..
node tools/build-css.mjs
```
