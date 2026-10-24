# TicketingOS

A complete, modern **multi-tenant helpdesk / support ticketing system** built with
Next.js 16 (App Router), TypeScript, Tailwind CSS v4 and Prisma.

Every customer organization gets a **fully isolated board** — its own users, agents,
categories and tickets. A platform operator (super admin) can see every tenant.

## Features

- 🔐 **Auth & roles** — JWT session (httpOnly cookie), roles: `SUPER_ADMIN`, `ADMIN`, `AGENT`, `CUSTOMER`
- 🏢 **Multi-tenancy** — strict `tenantId` scoping on every query; self-serve org signup
- 🎫 **Tickets** — statuses, priorities, categories, tags, per-tenant numbering
- 💬 **Conversations** — threaded replies + staff-only internal notes
- 🧑‍💼 **Assignment** — assign tickets to agents, auto-notifications
- 🔔 **Notifications** — in-app, per user, mark read / mark all read
- 📊 **Dashboard** — stats, 14-day trend chart, status breakdown
- 🔎 **Search & filters** — by subject/number, status, priority, category
- 👥 **Team management** — invite members, change roles, remove (admin)
- ⚙️ **Settings** — workspace name, manage ticket categories
- 🛰️ **Platform console** — super admin overview across all tenants
- 🌗 **Dark mode**, responsive, polished UI

## Tech stack

| Layer     | Choice                               |
| --------- | ------------------------------------ |
| Framework | Next.js 16 (App Router, Server Actions) |
| Language  | TypeScript                           |
| Styling   | Tailwind CSS v4                      |
| Database  | Prisma ORM + SQLite (swap to Postgres) |
| Auth      | bcryptjs + jose (JWT)                |
| Charts    | Recharts                             |

## Getting started

```bash
npm install
npm run db:push     # create the SQLite schema
npm run db:seed     # load demo tenants + data
npm run dev         # http://localhost:3000
```

Useful scripts: `npm run db:studio` (Prisma Studio), `npm run db:reset` (wipe + reseed).

## Demo accounts

All passwords: `password123`

| Role              | Email                      |
| ----------------- | -------------------------- |
| Platform operator | super@ticketingos.dev      |
| Acme — Admin      | admin@acme.dev             |
| Acme — Agent      | sofia@acme.dev             |
| Acme — Customer   | jamie@acme-customer.com    |
| Globex — Admin    | admin@globex.dev           |
| Globex — Customer | leo@globex-customer.com    |

Sign in as an Acme user vs a Globex user to see the tenant isolation in action.

## Deploying to Postgres

1. In `prisma/schema.prisma` set `datasource.provider = "postgresql"`.
2. Point `DATABASE_URL` at your Postgres instance and set a strong `AUTH_SECRET`.
3. `npx prisma migrate deploy` (or `db push`), then `npm run db:seed` if desired.

## Project structure

```
prisma/schema.prisma      # multi-tenant data model
prisma/seed.ts            # demo data
src/lib/                  # db client, auth/session, validations, constants
src/lib/actions/          # server actions (auth, tickets, team, settings, notifications)
src/components/ui/        # design-system primitives
src/components/app/       # app-specific components
src/app/(auth)/           # login / register
src/app/(app)/            # tenant app (dashboard, tickets, team, settings)
src/app/platform/         # super-admin console
```
