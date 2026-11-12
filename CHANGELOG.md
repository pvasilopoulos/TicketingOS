# Changelog

All notable changes to TicketingOS (PHP edition). Versions follow [Semantic Versioning](https://semver.org):
**major** for changes that need manual steps when updating, **minor** for new features, **patch** for fixes.
The app shows this list under **About & what’s new** (menu footer → version number).

## [1.11.0] - 2026-11-12

### Added
- **Delete tickets** permanently — from the ticket page (“Delete ticket”) or several at once from the ticket list — with their replies, files, time entries and history. New permission “Delete tickets” (Bulk & data): only super admins have it by default; a super admin can give it to administrators (Roles → Administrator) and administrators to roles. Every deletion is in the audit log with the ticket’s number and subject, and numbers of deleted tickets are never reused.

### Changed
- Roles → Administrator: “Full access” is now “Reset to defaults” (everything except deleting tickets).

## [1.10.0] - 2026-11-12

### Added
- **AI assistant for customers** (Claude): answers anything customers ask in Messages and, if you want, first in the website chat — with your company’s instructions, matching help-center articles and the customer’s recent tickets. “Talk to a person” hands the conversation to Live chat with its history. Settings → Integrations → AI assistant for customers.
- **SMS notifications** through Twilio or any SMS gateway with an HTTP API: ticket received, new reply and resolved for customers; high / urgent tickets, assignments and SLA misses for staff. Mobile number per person.
- **Viber and WhatsApp** as ticket channels: messages open a ticket (or join the customer’s open one), customers are matched by phone number, and agents’ replies go back on the same app.
- **Invoices** from billable time: drafts per organization and month (one line per ticket at its hourly rate), editable lines, VAT, numbering per series, print / PDF, email, paid / void; billing details (ΑΦΜ, ΔΟΥ, address) per organization; organization managers see their invoices in the portal. Until myDATA is connected, documents can be titled “Billing statement” or “Pro forma”.

### Fixed
- The “Support form & website widget” heading no longer gets squeezed by its buttons.

## [1.9.0] - 2026-11-12

### Added
- App versioning: the version is shown in the menu, with an **About & what’s new** page (version, release date, database schema, server details for admins, and this changelog).
- After an update, staff see a one-time “Updated to vX.Y.Z — what’s new” notice.
- `VERSION` file and this changelog; releases are tagged `vX.Y.Z` on GitHub.

## [1.8.0] - 2026-11-12

### Improved
- Large workspaces (tested with 20,000 tickets, 2,000 users, 300 organizations and 6,000 chats): faster customer ticket lists and dashboard, reports, live chat and Messages polling, and settings.
- Settings → Users: the organization checklist is built when a form opens, with search (page size 10.8 MB → 1.4 MB). Settings → Organizations is paged with search.
- New database indexes (added automatically after the update).

### Added
- `DB_PROFILE="1"` in `.env` logs slow pages and repeated queries to `storage/logs/queries.log`.

## [1.7.0] - 2026-11-12

### Added
- **Messages** for customers (chat button in the app): talk to the organization’s support team, the administrators or colleagues of the same organization. Colleague conversations are private and never become tickets.
- Emoji and files (button, drag & drop, paste a screenshot) in every chat; images show inline. Chat files move to the ticket when a chat becomes one.

### Fixed
- Ticket page was wider than the screen on phones.
- Search ignores case and accents everywhere (Πρόδρομος = Προδρομος = ΠΡΟΔΡΟΜΟΣ).

## [1.6.0] - 2026-11-11

### Added
- Chat button for signed-in customers with a choice of agent.
- Redesigned Live chat console: stats, tabs, search, typing indicator, quick replies, transfer to a colleague, customer details, sound and desktop alerts, 👍/👎 rating.
- Widget preview page (Settings → Integrations).

## [1.5.0] - 2026-11-11

### Added
- Live chat from the website widget; unanswered chats become tickets.
- Time tracking with timer, support contracts with hour banks, hourly rate per organization.
- Reports per organization, busy-hours heatmap, time & billing, Excel and PDF.
- Shared saved views, AI triage and help-center articles from tickets, Teams and Viber webhooks.
- Backups, organization exports and GDPR tools.
- User manual (`docs/manual.md`).

## [1.4.0] - 2026-11-11

### Added
- Ticket page: drafts, signatures, reply variables, quote / edit / delete with history, pinned messages, files panel with ZIP download, print / PDF, customer history and the AI assistant.
- Scheduled reports per organization to any email address.
- New headset logo, favicon and app icons; colour-coded board that stacks on phones.

## [1.3.0] - 2026-11-11

### Added
- Notifications overhaul: delivery queue, rules per role and branded emails.
- Satisfaction survey status on tickets.

## [1.2.0] - 2026-11-11

### Added
- Several assignees per ticket, ticket teams with a searchable people picker.
- Advanced new-ticket form and its settings; people in several organizations.
- Signed-in devices list.

## [1.1.0] - 2026-11-11

### Added
- Modern sign-in pages, branding, “sign in as”, detailed permissions (42 in 8 groups), admin-only switches, sign-up switch.

## [1.0.0] - 2026-11-11

### Added
- First release of the PHP edition: multi-tenant helpdesk with tickets, board, knowledge base, roles, super admin, ticket history and MySQL schema for import.
