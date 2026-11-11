-- TicketingOS — SQLite schema (multi-tenant).
-- Every data row carries a tenantId; a SUPER_ADMIN user has tenantId = NULL.
-- Timestamps are UTC ISO-8601 strings with milliseconds; booleans are 0/1.

CREATE TABLE IF NOT EXISTS "Tenant" (
  "id" TEXT PRIMARY KEY,
  "name" TEXT NOT NULL,
  "slug" TEXT NOT NULL UNIQUE,
  "createdAt" TEXT NOT NULL,
  "updatedAt" TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS "User" (
  "id" TEXT PRIMARY KEY,
  "email" TEXT NOT NULL UNIQUE,
  "name" TEXT NOT NULL,
  "passwordHash" TEXT NOT NULL,
  "role" TEXT NOT NULL DEFAULT 'CUSTOMER',
  "avatarColor" TEXT NOT NULL DEFAULT '#6366f1',
  "createdAt" TEXT NOT NULL,
  "updatedAt" TEXT NOT NULL,
  "tenantId" TEXT REFERENCES "Tenant"("id") ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "User_role_idx" ON "User"("role");
CREATE INDEX IF NOT EXISTS "User_tenantId_idx" ON "User"("tenantId");

CREATE TABLE IF NOT EXISTS "TenantNotificationPolicy" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL UNIQUE REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "allowInApp" INTEGER NOT NULL DEFAULT 1,
  "allowPush" INTEGER NOT NULL DEFAULT 1,
  "allowEmail" INTEGER NOT NULL DEFAULT 1,
  "defaults" TEXT NOT NULL DEFAULT '{}',
  "updatedAt" TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS "AutomationRule" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "name" TEXT NOT NULL,
  "enabled" INTEGER NOT NULL DEFAULT 1,
  "trigger" TEXT NOT NULL DEFAULT 'TICKET_CREATED',
  "conditionField" TEXT,
  "conditionValue" TEXT,
  "actionType" TEXT NOT NULL,
  "actionValue" TEXT NOT NULL,
  "order" INTEGER NOT NULL DEFAULT 0,
  "createdAt" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "AutomationRule_tenant_enabled_idx" ON "AutomationRule"("tenantId", "enabled");

CREATE TABLE IF NOT EXISTS "CannedResponse" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "title" TEXT NOT NULL,
  "body" TEXT NOT NULL,
  "createdAt" TEXT NOT NULL,
  "updatedAt" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "CannedResponse_tenantId_idx" ON "CannedResponse"("tenantId");

CREATE TABLE IF NOT EXISTS "NotificationPreference" (
  "id" TEXT PRIMARY KEY,
  "userId" TEXT NOT NULL UNIQUE REFERENCES "User"("id") ON DELETE CASCADE,
  "prefs" TEXT NOT NULL DEFAULT '{}',
  "quietStart" INTEGER,
  "quietEnd" INTEGER,
  "priorityThreshold" TEXT NOT NULL DEFAULT '',
  "scope" TEXT NOT NULL DEFAULT 'all',
  "updatedAt" TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS "PushSubscription" (
  "id" TEXT PRIMARY KEY,
  "userId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE,
  "endpoint" TEXT NOT NULL UNIQUE,
  "p256dh" TEXT NOT NULL,
  "auth" TEXT NOT NULL,
  "userAgent" TEXT,
  "createdAt" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "PushSubscription_userId_idx" ON "PushSubscription"("userId");

CREATE TABLE IF NOT EXISTS "SavedView" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL,
  "userId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE,
  "name" TEXT NOT NULL,
  "query" TEXT NOT NULL,
  "createdAt" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "SavedView_userId_idx" ON "SavedView"("userId");

CREATE TABLE IF NOT EXISTS "PasswordResetToken" (
  "id" TEXT PRIMARY KEY,
  "userId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE,
  "tokenHash" TEXT NOT NULL UNIQUE,
  "expiresAt" TEXT NOT NULL,
  "usedAt" TEXT,
  "createdAt" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "PasswordResetToken_userId_idx" ON "PasswordResetToken"("userId");

CREATE TABLE IF NOT EXISTS "Category" (
  "id" TEXT PRIMARY KEY,
  "name" TEXT NOT NULL,
  "color" TEXT NOT NULL DEFAULT '#64748b',
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  UNIQUE ("tenantId", "name")
);

CREATE TABLE IF NOT EXISTS "Tag" (
  "id" TEXT PRIMARY KEY,
  "name" TEXT NOT NULL,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  UNIQUE ("tenantId", "name")
);

CREATE TABLE IF NOT EXISTS "Ticket" (
  "id" TEXT PRIMARY KEY,
  "number" INTEGER NOT NULL,
  "subject" TEXT NOT NULL,
  "description" TEXT NOT NULL,
  "status" TEXT NOT NULL DEFAULT 'OPEN',
  "priority" TEXT NOT NULL DEFAULT 'MEDIUM',
  "createdAt" TEXT NOT NULL,
  "updatedAt" TEXT NOT NULL,
  "closedAt" TEXT,
  "dueAt" TEXT,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "requesterId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE,
  "assigneeId" TEXT REFERENCES "User"("id") ON DELETE SET NULL,
  "categoryId" TEXT REFERENCES "Category"("id") ON DELETE SET NULL,
  "searchText" TEXT,
  UNIQUE ("tenantId", "number")
);
CREATE INDEX IF NOT EXISTS "Ticket_tenant_status_idx" ON "Ticket"("tenantId", "status");
CREATE INDEX IF NOT EXISTS "Ticket_tenant_priority_idx" ON "Ticket"("tenantId", "priority");
CREATE INDEX IF NOT EXISTS "Ticket_requesterId_idx" ON "Ticket"("requesterId");
CREATE INDEX IF NOT EXISTS "Ticket_assigneeId_idx" ON "Ticket"("assigneeId");
CREATE INDEX IF NOT EXISTS "Ticket_createdAt_idx" ON "Ticket"("createdAt");
-- Large boards: list/board pages sort by createdAt inside a tenant (+ status).
CREATE INDEX IF NOT EXISTS "Ticket_tenant_created_idx" ON "Ticket"("tenantId", "createdAt");
CREATE INDEX IF NOT EXISTS "Ticket_tenant_status_created_idx" ON "Ticket"("tenantId", "status", "createdAt");
CREATE INDEX IF NOT EXISTS "Ticket_tenant_due_idx" ON "Ticket"("tenantId", "dueAt");
CREATE INDEX IF NOT EXISTS "Ticket_tenant_category_idx" ON "Ticket"("tenantId", "categoryId");

CREATE TABLE IF NOT EXISTS "TicketPriority" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "key" TEXT NOT NULL,
  "label" TEXT NOT NULL,
  "color" TEXT NOT NULL DEFAULT '#64748b',
  "order" INTEGER NOT NULL DEFAULT 0,
  UNIQUE ("tenantId", "key")
);
CREATE INDEX IF NOT EXISTS "TicketPriority_tenant_order_idx" ON "TicketPriority"("tenantId", "order");

CREATE TABLE IF NOT EXISTS "SlaTarget" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "priority" TEXT NOT NULL,
  "resolveHours" INTEGER NOT NULL,
  UNIQUE ("tenantId", "priority")
);

CREATE TABLE IF NOT EXISTS "TicketStatus" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "key" TEXT NOT NULL,
  "label" TEXT NOT NULL,
  "color" TEXT NOT NULL DEFAULT '#64748b',
  "isClosed" INTEGER NOT NULL DEFAULT 0,
  "order" INTEGER NOT NULL DEFAULT 0,
  "createdAt" TEXT NOT NULL,
  UNIQUE ("tenantId", "key")
);
CREATE INDEX IF NOT EXISTS "TicketStatus_tenant_order_idx" ON "TicketStatus"("tenantId", "order");

CREATE TABLE IF NOT EXISTS "EmailSetting" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL UNIQUE REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "enabled" INTEGER NOT NULL DEFAULT 0,
  "provider" TEXT NOT NULL DEFAULT 'SMTP',
  "fromName" TEXT,
  "fromEmail" TEXT,
  "smtpHost" TEXT,
  "smtpPort" INTEGER,
  "smtpSecure" INTEGER NOT NULL DEFAULT 1,
  "smtpUser" TEXT,
  "smtpPassword" TEXT,
  "msTenantId" TEXT,
  "msClientId" TEXT,
  "msClientSecret" TEXT,
  "msSender" TEXT,
  "updatedAt" TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS "TicketEvent" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "ticketId" TEXT NOT NULL REFERENCES "Ticket"("id") ON DELETE CASCADE,
  "actorId" TEXT REFERENCES "User"("id") ON DELETE SET NULL,
  "type" TEXT NOT NULL,
  "summary" TEXT NOT NULL,
  "createdAt" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "TicketEvent_ticket_createdAt_idx" ON "TicketEvent"("ticketId", "createdAt");

CREATE TABLE IF NOT EXISTS "TicketWatcher" (
  "ticketId" TEXT NOT NULL REFERENCES "Ticket"("id") ON DELETE CASCADE,
  "userId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE,
  "role" TEXT NOT NULL DEFAULT 'VIEWER',
  "createdAt" TEXT NOT NULL,
  PRIMARY KEY ("ticketId", "userId")
);
CREATE INDEX IF NOT EXISTS "TicketWatcher_userId_idx" ON "TicketWatcher"("userId");

CREATE TABLE IF NOT EXISTS "TicketTag" (
  "ticketId" TEXT NOT NULL REFERENCES "Ticket"("id") ON DELETE CASCADE,
  "tagId" TEXT NOT NULL REFERENCES "Tag"("id") ON DELETE CASCADE,
  PRIMARY KEY ("ticketId", "tagId")
);
CREATE INDEX IF NOT EXISTS "TicketTag_tagId_idx" ON "TicketTag"("tagId");

CREATE TABLE IF NOT EXISTS "Comment" (
  "id" TEXT PRIMARY KEY,
  "body" TEXT NOT NULL,
  "isInternal" INTEGER NOT NULL DEFAULT 0,
  "createdAt" TEXT NOT NULL,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "ticketId" TEXT NOT NULL REFERENCES "Ticket"("id") ON DELETE CASCADE,
  "authorId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "Comment_ticketId_idx" ON "Comment"("ticketId");

CREATE TABLE IF NOT EXISTS "Attachment" (
  "id" TEXT PRIMARY KEY,
  "filename" TEXT NOT NULL,
  "url" TEXT NOT NULL,
  "size" INTEGER NOT NULL,
  "mime" TEXT NOT NULL,
  "createdAt" TEXT NOT NULL,
  "tenantId" TEXT REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "ticketId" TEXT REFERENCES "Ticket"("id") ON DELETE CASCADE,
  "commentId" TEXT REFERENCES "Comment"("id") ON DELETE CASCADE,
  "uploaderId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "Attachment_ticketId_idx" ON "Attachment"("ticketId");

CREATE TABLE IF NOT EXISTS "Notification" (
  "id" TEXT PRIMARY KEY,
  "type" TEXT NOT NULL,
  "message" TEXT NOT NULL,
  "read" INTEGER NOT NULL DEFAULT 0,
  "createdAt" TEXT NOT NULL,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "userId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE,
  "ticketId" TEXT REFERENCES "Ticket"("id") ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "Notification_user_read_idx" ON "Notification"("userId", "read");
CREATE INDEX IF NOT EXISTS "Notification_user_createdAt_idx" ON "Notification"("userId", "createdAt");

-- Macros: one click applies a reply and/or field changes to a ticket.
CREATE TABLE IF NOT EXISTS "Macro" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "name" TEXT NOT NULL,
  "body" TEXT,
  "isInternal" INTEGER NOT NULL DEFAULT 0,
  "setStatus" TEXT,
  "setPriority" TEXT,
  "setAssignee" TEXT,
  "addTagId" TEXT,
  "createdAt" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "Macro_tenantId_idx" ON "Macro"("tenantId");

-- Related tickets (stored once per pair).
CREATE TABLE IF NOT EXISTS "TicketLink" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "ticketId" TEXT NOT NULL REFERENCES "Ticket"("id") ON DELETE CASCADE,
  "linkedId" TEXT NOT NULL REFERENCES "Ticket"("id") ON DELETE CASCADE,
  "createdAt" TEXT NOT NULL,
  UNIQUE ("ticketId", "linkedId")
);
CREATE INDEX IF NOT EXISTS "TicketLink_linkedId_idx" ON "TicketLink"("linkedId");

-- Who is looking at / replying to a ticket right now (collision detection).
CREATE TABLE IF NOT EXISTS "TicketPresence" (
  "ticketId" TEXT NOT NULL REFERENCES "Ticket"("id") ON DELETE CASCADE,
  "userId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE,
  "state" TEXT NOT NULL DEFAULT 'viewing',
  "updatedAt" TEXT NOT NULL,
  PRIMARY KEY ("ticketId", "userId")
);

-- Knowledge base articles.
CREATE TABLE IF NOT EXISTS "KbArticle" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "title" TEXT NOT NULL,
  "body" TEXT NOT NULL,
  "section" TEXT,
  "published" INTEGER NOT NULL DEFAULT 1,
  "views" INTEGER NOT NULL DEFAULT 0,
  "helpful" INTEGER NOT NULL DEFAULT 0,
  "searchText" TEXT,
  "authorId" TEXT REFERENCES "User"("id") ON DELETE SET NULL,
  "createdAt" TEXT NOT NULL,
  "updatedAt" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "KbArticle_tenant_idx" ON "KbArticle"("tenantId", "published");

-- Generic per-tenant settings (JSON values), e.g. the mobile bottom menu.
CREATE TABLE IF NOT EXISTS "TenantSetting" (
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "key" TEXT NOT NULL,
  "value" TEXT NOT NULL,
  "updatedAt" TEXT NOT NULL,
  PRIMARY KEY ("tenantId", "key")
);

-- Custom staff roles with a set of permissions (JSON array of permission keys).
CREATE TABLE IF NOT EXISTS "Role" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "name" TEXT NOT NULL,
  "description" TEXT,
  "color" TEXT NOT NULL DEFAULT '#6366f1',
  "permissions" TEXT NOT NULL DEFAULT '[]',
  "createdAt" TEXT NOT NULL,
  UNIQUE ("tenantId", "name")
);

-- ---------- Audit log: who changed what in the workspace ----------
CREATE TABLE IF NOT EXISTS "AuditLog" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "actorId" TEXT REFERENCES "User"("id") ON DELETE SET NULL,
  "actorName" TEXT,
  "action" TEXT NOT NULL,
  "targetType" TEXT,
  "targetId" TEXT,
  "summary" TEXT NOT NULL,
  "meta" TEXT,
  "ip" TEXT,
  "createdAt" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "AuditLog_tenant_created_idx" ON "AuditLog"("tenantId", "createdAt");
CREATE INDEX IF NOT EXISTS "AuditLog_tenant_action_idx" ON "AuditLog"("tenantId", "action");

-- ---------- Agent groups (teams) with automatic assignment ----------
CREATE TABLE IF NOT EXISTS "AgentGroup" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "name" TEXT NOT NULL,
  "description" TEXT,
  "color" TEXT NOT NULL DEFAULT '#0ea5e9',
  "assignMode" TEXT NOT NULL DEFAULT 'none',
  "lastAssignedId" TEXT,
  "createdAt" TEXT NOT NULL,
  UNIQUE ("tenantId", "name")
);
CREATE TABLE IF NOT EXISTS "AgentGroupMember" (
  "groupId" TEXT NOT NULL REFERENCES "AgentGroup"("id") ON DELETE CASCADE,
  "userId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE,
  "createdAt" TEXT NOT NULL,
  PRIMARY KEY ("groupId", "userId")
);
CREATE INDEX IF NOT EXISTS "AgentGroupMember_user_idx" ON "AgentGroupMember"("userId");

-- ---------- Customer organizations ----------
CREATE TABLE IF NOT EXISTS "Organization" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "name" TEXT NOT NULL,
  "domain" TEXT,
  "notes" TEXT,
  "createdAt" TEXT NOT NULL,
  UNIQUE ("tenantId", "name")
);
CREATE INDEX IF NOT EXISTS "Organization_tenant_domain_idx" ON "Organization"("tenantId", "domain");

-- ---------- Custom ticket fields ----------
CREATE TABLE IF NOT EXISTS "CustomField" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "key" TEXT NOT NULL,
  "label" TEXT NOT NULL,
  "type" TEXT NOT NULL DEFAULT 'text',
  "options" TEXT,
  "required" INTEGER NOT NULL DEFAULT 0,
  "customerVisible" INTEGER NOT NULL DEFAULT 1,
  "categoryId" TEXT REFERENCES "Category"("id") ON DELETE CASCADE,
  "order" INTEGER NOT NULL DEFAULT 0,
  "createdAt" TEXT NOT NULL,
  UNIQUE ("tenantId", "key")
);
CREATE TABLE IF NOT EXISTS "TicketFieldValue" (
  "ticketId" TEXT NOT NULL REFERENCES "Ticket"("id") ON DELETE CASCADE,
  "fieldId" TEXT NOT NULL REFERENCES "CustomField"("id") ON DELETE CASCADE,
  "value" TEXT NOT NULL,
  PRIMARY KEY ("ticketId", "fieldId")
);
CREATE INDEX IF NOT EXISTS "TicketFieldValue_field_idx" ON "TicketFieldValue"("fieldId");

-- ---------- Customer satisfaction surveys ----------
CREATE TABLE IF NOT EXISTS "CsatSurvey" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "ticketId" TEXT NOT NULL REFERENCES "Ticket"("id") ON DELETE CASCADE,
  "agentId" TEXT REFERENCES "User"("id") ON DELETE SET NULL,
  "requesterId" TEXT REFERENCES "User"("id") ON DELETE SET NULL,
  "tokenHash" TEXT NOT NULL,
  "rating" INTEGER,
  "comment" TEXT,
  "createdAt" TEXT NOT NULL,
  "answeredAt" TEXT
);
CREATE INDEX IF NOT EXISTS "CsatSurvey_ticket_idx" ON "CsatSurvey"("ticketId");
CREATE INDEX IF NOT EXISTS "CsatSurvey_tenant_answered_idx" ON "CsatSurvey"("tenantId", "answeredAt");
CREATE INDEX IF NOT EXISTS "CsatSurvey_token_idx" ON "CsatSurvey"("tokenHash");

-- ---------- Scheduled report emails ----------
CREATE TABLE IF NOT EXISTS "ScheduledReport" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "name" TEXT NOT NULL,
  "frequency" TEXT NOT NULL DEFAULT 'weekly',
  "weekday" INTEGER NOT NULL DEFAULT 1,
  "hour" INTEGER NOT NULL DEFAULT 8,
  "days" INTEGER NOT NULL DEFAULT 7,
  "recipients" TEXT NOT NULL,
  "active" INTEGER NOT NULL DEFAULT 1,
  "nextRunAt" TEXT,
  "lastSentAt" TEXT,
  "createdById" TEXT REFERENCES "User"("id") ON DELETE SET NULL,
  "createdAt" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "ScheduledReport_due_idx" ON "ScheduledReport"("active", "nextRunAt");

-- ---------- REST API keys ----------
CREATE TABLE IF NOT EXISTS "ApiKey" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "userId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE,
  "name" TEXT NOT NULL,
  "prefix" TEXT NOT NULL,
  "tokenHash" TEXT NOT NULL,
  "scope" TEXT NOT NULL DEFAULT 'read',
  "lastUsedAt" TEXT,
  "createdAt" TEXT NOT NULL,
  "revokedAt" TEXT
);
CREATE INDEX IF NOT EXISTS "ApiKey_token_idx" ON "ApiKey"("tokenHash");

-- ---------- Outgoing webhooks (generic JSON, Slack, Microsoft Teams) ----------
CREATE TABLE IF NOT EXISTS "Webhook" (
  "id" TEXT PRIMARY KEY,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "name" TEXT NOT NULL,
  "url" TEXT NOT NULL,
  "secret" TEXT NOT NULL,
  "format" TEXT NOT NULL DEFAULT 'json',
  "events" TEXT NOT NULL,
  "active" INTEGER NOT NULL DEFAULT 1,
  "failCount" INTEGER NOT NULL DEFAULT 0,
  "lastStatus" INTEGER,
  "lastError" TEXT,
  "lastAt" TEXT,
  "createdAt" TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS "WebhookDelivery" (
  "id" TEXT PRIMARY KEY,
  "webhookId" TEXT NOT NULL REFERENCES "Webhook"("id") ON DELETE CASCADE,
  "tenantId" TEXT NOT NULL REFERENCES "Tenant"("id") ON DELETE CASCADE,
  "event" TEXT NOT NULL,
  "payload" TEXT NOT NULL,
  "status" TEXT NOT NULL DEFAULT 'pending',
  "attempts" INTEGER NOT NULL DEFAULT 0,
  "responseCode" INTEGER,
  "error" TEXT,
  "nextAttemptAt" TEXT NOT NULL,
  "createdAt" TEXT NOT NULL,
  "deliveredAt" TEXT
);
CREATE INDEX IF NOT EXISTS "WebhookDelivery_due_idx" ON "WebhookDelivery"("status", "nextAttemptAt");
CREATE INDEX IF NOT EXISTS "WebhookDelivery_hook_idx" ON "WebhookDelivery"("webhookId", "createdAt");

-- ---------- Simple rate limiting for public endpoints (support form, API) ----------
CREATE TABLE IF NOT EXISTS "RateHit" (
  "id" TEXT PRIMARY KEY,
  "bucket" TEXT NOT NULL,
  "createdAt" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "RateHit_bucket_idx" ON "RateHit"("bucket", "createdAt");

-- Co-assignees: everyone working on a ticket besides the lead (Ticket.assigneeId).
CREATE TABLE IF NOT EXISTS "TicketAssignee" (
  "ticketId" TEXT NOT NULL REFERENCES "Ticket"("id") ON DELETE CASCADE,
  "userId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE,
  "createdAt" TEXT NOT NULL,
  PRIMARY KEY ("ticketId", "userId")
);
CREATE INDEX IF NOT EXISTS "TicketAssignee_userId_idx" ON "TicketAssignee"("userId");
