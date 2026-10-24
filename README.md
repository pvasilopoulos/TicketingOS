# TicketingOS

A complete, modern **multi-tenant helpdesk / support ticketing system** built with
Next.js 16 (App Router), TypeScript, Tailwind CSS v4 and Prisma.

Every customer organization gets a **fully isolated board** — its own users, agents,
categories, statuses, priorities and tickets. A platform operator (super admin) can
oversee every tenant and even step into any board to manage it directly.

## Features

### Core
- 🔐 **Auth & roles** — JWT session (httpOnly cookie), bcrypt hashing. Roles: `SUPER_ADMIN`, `ADMIN`, `AGENT`, `CUSTOMER`
- 🏢 **Multi-tenancy** — strict `tenantId` scoping on every query; self-serve org signup
- 🎫 **Tickets** — per-tenant numbering, assignment, categories, due dates
- 💬 **Conversations** — threaded replies + staff-only internal notes
- 📝 **Rich text editor** — Tiptap: formatting, colors, highlight, tables, task lists, images, @mentions; server-side HTML sanitization
- 📎 **Attachments** — upload on tickets and inline editor images
- 🗂️ **Kanban board** — drag-and-drop view alongside the list view

### Workflow
- 🎨 **Custom statuses & priorities** — create/edit/reorder per tenant, with colors and "closed" semantics
- ⏱️ **SLA & due dates** — per-priority resolve targets, breach tracking
- 🏷️ **Tags**, 💡 **canned responses**, 🔖 **saved views** + bulk actions
- 🤖 **Automations** — trigger/condition/action rules (e.g. auto-assign, auto-tag)
- 👁️ **Watchers / CC** — collaborators who can follow or help manage a ticket
- 🧵 **Activity timeline** — full audit log of every change on a ticket

### Notifications
- 🔔 **In-app** — real-time via Server-Sent Events, with toast + bell dropdown
- 📱 **Web Push** — VAPID push to devices (service worker)
- ✉️ **Email** — SMTP (nodemailer) or Microsoft 365 (Graph), secrets encrypted at rest (AES-256-GCM)
- ⚙️ **Rules depth** — per-user preferences (channel matrix, quiet hours, priority threshold, scope) intersected with per-tenant policy

### Admin & platform
- 👥 **Team management** — invite members, change roles, remove
- ⚙️ **Settings** — workspace, statuses, priorities, SLA, tags, canned responses, automations, email, notification policy
- 🛰️ **Platform console** — super admin overview across all tenants + **impersonation** (enter any tenant board, manage, exit)
- 🔑 **Password reset** — tokenized email flow (one-time, expiring)
- 📊 **Dashboard** — stats, trend chart, status breakdown
- 🌗 **Dark mode**, responsive, polished UI

## Tech stack

| Layer     | Choice                                   |
| --------- | ---------------------------------------- |
| Framework | Next.js 16 (App Router, Server Actions)  |
| Language  | TypeScript                               |
| Styling   | Tailwind CSS v4                          |
| Database  | Prisma ORM + SQLite (swap to Postgres)   |
| Auth      | bcryptjs + jose (JWT)                     |
| Editor    | Tiptap v3 + sanitize-html                |
| Realtime  | Server-Sent Events                       |
| Push      | web-push (VAPID) + Service Worker        |
| Email     | nodemailer (SMTP) / Microsoft Graph       |
| Charts    | Recharts                                 |

## Getting started

```bash
npm install
cp .env.example .env        # then fill in the values (see below)
npm run db:push             # create the SQLite schema
npm run db:seed             # load demo tenants + data
npm run dev                 # http://localhost:3000
```

Useful scripts: `npm run db:studio` (Prisma Studio), `npm run db:reset` (wipe + reseed), `npm run build`, `npm run lint`.

### Environment

Copy `.env.example` to `.env`. Key values:

- `AUTH_SECRET` — long random string; signs sessions and encrypts stored email secrets.
  Generate: `node -e "console.log(require('crypto').randomBytes(48).toString('hex'))"`
- VAPID keys for Web Push — generate with `npx web-push generate-vapid-keys`.

## Demo accounts

All passwords: `password123`

| Role              | Email                    |
| ----------------- | ------------------------ |
| Platform operator | info@softify.gr          |
| Acme — Admin      | admin@acme.dev           |
| Acme — Agent      | sofia@acme.dev           |
| Acme — Customer   | jamie@acme-customer.com  |
| Globex — Admin    | admin@globex.dev         |
| Globex — Customer | leo@globex-customer.com  |

Sign in as an Acme user vs a Globex user to see the tenant isolation in action, or as
the platform operator to oversee and enter any tenant board.

## Deploying to Postgres

1. In `prisma/schema.prisma` set `datasource.provider = "postgresql"`.
2. Point `DATABASE_URL` at your Postgres instance and set a strong `AUTH_SECRET`.
3. `npx prisma migrate deploy` (or `db push`), then `npm run db:seed` if desired.

## Project structure

```
prisma/schema.prisma      # multi-tenant data model
prisma/seed.ts            # demo data
src/lib/                  # db client, auth/session, crypto, email, notify, push, realtime, sla
src/lib/actions/          # server actions (auth, tickets, team, settings, automations, …)
src/components/ui/        # design-system primitives + rich text editor
src/components/app/       # app-specific components (board, filters, managers, …)
src/app/(auth)/           # login / register / forgot / reset
src/app/(app)/            # tenant app (dashboard, tickets, team, settings, notifications)
src/app/platform/         # super-admin console
src/app/api/              # route handlers (attachments, editor images, push, stream/SSE)
public/sw.js              # service worker for Web Push
```
