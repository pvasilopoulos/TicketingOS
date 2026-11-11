-- TicketingOS: MySQL 8 / MariaDB 10.5+ schema (41 tables).
-- Import into an EMPTY database (phpMyAdmin → Import), or run: php bin/migrate.php
-- After importing, open /register to create the first workspace and admin.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `Tenant` (
  `id` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `slug` varchar(191) NOT NULL,
  `createdAt` varchar(30) NOT NULL,
  `updatedAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `User` (
  `id` varchar(40) NOT NULL,
  `email` varchar(191) NOT NULL,
  `name` varchar(191) NOT NULL,
  `passwordHash` mediumtext NOT NULL,
  `role` varchar(64) NOT NULL DEFAULT 'CUSTOMER',
  `avatarColor` varchar(64) NOT NULL DEFAULT '#6366f1',
  `createdAt` varchar(30) NOT NULL,
  `updatedAt` varchar(30) NOT NULL,
  `tenantId` varchar(40) DEFAULT NULL,
  `customRoleId` varchar(40) DEFAULT NULL,
  `active` int(11) NOT NULL DEFAULT 1,
  `lastLoginAt` varchar(30) DEFAULT NULL,
  `organizationId` varchar(40) DEFAULT NULL,
  `orgManager` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `User_role_idx` (`role`),
  KEY `User_tenantId_idx` (`tenantId`),
  KEY `User_org_idx` (`organizationId`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TenantNotificationPolicy` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `allowInApp` int(11) NOT NULL DEFAULT 1,
  `allowPush` int(11) NOT NULL DEFAULT 1,
  `allowEmail` int(11) NOT NULL DEFAULT 1,
  `defaults` varchar(4000) NOT NULL DEFAULT '{}',
  `updatedAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenantId` (`tenantId`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `AutomationRule` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `enabled` int(11) NOT NULL DEFAULT 1,
  `trigger` varchar(64) NOT NULL DEFAULT 'TICKET_CREATED',
  `conditionField` varchar(64) DEFAULT NULL,
  `conditionValue` varchar(191) DEFAULT NULL,
  `actionType` varchar(64) NOT NULL,
  `actionValue` varchar(191) NOT NULL,
  `order` int(11) NOT NULL DEFAULT 0,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `AutomationRule_tenant_enabled_idx` (`tenantId`,`enabled`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `CannedResponse` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `title` varchar(191) NOT NULL,
  `body` mediumtext NOT NULL,
  `createdAt` varchar(30) NOT NULL,
  `updatedAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `CannedResponse_tenantId_idx` (`tenantId`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `NotificationPreference` (
  `id` varchar(40) NOT NULL,
  `userId` varchar(40) NOT NULL,
  `prefs` varchar(4000) NOT NULL DEFAULT '{}',
  `quietStart` int(11) DEFAULT NULL,
  `quietEnd` int(11) DEFAULT NULL,
  `priorityThreshold` varchar(64) NOT NULL DEFAULT '',
  `scope` varchar(64) NOT NULL DEFAULT 'all',
  `updatedAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userId` (`userId`),
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PushSubscription` (
  `id` varchar(40) NOT NULL,
  `userId` varchar(40) NOT NULL,
  `endpoint` varchar(500) NOT NULL,
  `p256dh` varchar(191) NOT NULL,
  `auth` varchar(191) NOT NULL,
  `userAgent` mediumtext DEFAULT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `endpoint` (`endpoint`),
  KEY `PushSubscription_userId_idx` (`userId`),
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `SavedView` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `userId` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `query` mediumtext NOT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `SavedView_userId_idx` (`userId`),
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PasswordResetToken` (
  `id` varchar(40) NOT NULL,
  `userId` varchar(40) NOT NULL,
  `tokenHash` varchar(64) NOT NULL,
  `expiresAt` varchar(30) NOT NULL,
  `usedAt` varchar(30) DEFAULT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tokenHash` (`tokenHash`),
  KEY `PasswordResetToken_userId_idx` (`userId`),
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Category` (
  `id` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `color` varchar(64) NOT NULL DEFAULT '#64748b',
  `tenantId` varchar(40) NOT NULL,
  `groupId` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenantId` (`tenantId`,`name`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Tag` (
  `id` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenantId` (`tenantId`,`name`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Ticket` (
  `id` varchar(40) NOT NULL,
  `number` int(11) NOT NULL,
  `subject` varchar(191) NOT NULL,
  `description` mediumtext NOT NULL,
  `status` varchar(64) NOT NULL DEFAULT 'OPEN',
  `priority` varchar(64) NOT NULL DEFAULT 'MEDIUM',
  `createdAt` varchar(30) NOT NULL,
  `updatedAt` varchar(30) NOT NULL,
  `closedAt` varchar(30) DEFAULT NULL,
  `dueAt` varchar(30) DEFAULT NULL,
  `tenantId` varchar(40) NOT NULL,
  `requesterId` varchar(40) NOT NULL,
  `assigneeId` varchar(40) DEFAULT NULL,
  `categoryId` varchar(40) DEFAULT NULL,
  `searchText` mediumtext DEFAULT NULL,
  `mergedIntoId` varchar(40) DEFAULT NULL,
  `firstResponseAt` varchar(30) DEFAULT NULL,
  `groupId` varchar(40) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'web',
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenantId` (`tenantId`,`number`),
  KEY `categoryId` (`categoryId`),
  KEY `Ticket_tenant_status_idx` (`tenantId`,`status`),
  KEY `Ticket_tenant_priority_idx` (`tenantId`,`priority`),
  KEY `Ticket_requesterId_idx` (`requesterId`),
  KEY `Ticket_assigneeId_idx` (`assigneeId`),
  KEY `Ticket_createdAt_idx` (`createdAt`),
  KEY `Ticket_tenant_created_idx` (`tenantId`,`createdAt`),
  KEY `Ticket_tenant_status_created_idx` (`tenantId`,`status`,`createdAt`),
  KEY `Ticket_tenant_due_idx` (`tenantId`,`dueAt`),
  KEY `Ticket_tenant_category_idx` (`tenantId`,`categoryId`),
  KEY `Ticket_tenant_group_idx` (`tenantId`,`groupId`),
  KEY `Ticket_tenant_updated_idx` (`tenantId`,`updatedAt`),
  KEY `Ticket_tenant_closed_idx` (`tenantId`,`closedAt`),
  FULLTEXT KEY `Ticket_search_ft` (`searchText`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`requesterId`) REFERENCES `User` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigneeId`) REFERENCES `User` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`categoryId`) REFERENCES `Category` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TicketPriority` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `key` varchar(64) NOT NULL,
  `label` varchar(191) NOT NULL,
  `color` varchar(64) NOT NULL DEFAULT '#64748b',
  `order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenantId` (`tenantId`,`key`),
  KEY `TicketPriority_tenant_order_idx` (`tenantId`,`order`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `SlaTarget` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `priority` varchar(64) NOT NULL,
  `resolveHours` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenantId` (`tenantId`,`priority`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TicketStatus` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `key` varchar(64) NOT NULL,
  `label` varchar(191) NOT NULL,
  `color` varchar(64) NOT NULL DEFAULT '#64748b',
  `isClosed` int(11) NOT NULL DEFAULT 0,
  `order` int(11) NOT NULL DEFAULT 0,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenantId` (`tenantId`,`key`),
  KEY `TicketStatus_tenant_order_idx` (`tenantId`,`order`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `EmailSetting` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `enabled` int(11) NOT NULL DEFAULT 0,
  `provider` varchar(64) NOT NULL DEFAULT 'SMTP',
  `fromName` varchar(191) DEFAULT NULL,
  `fromEmail` varchar(191) DEFAULT NULL,
  `smtpHost` varchar(191) DEFAULT NULL,
  `smtpPort` int(11) DEFAULT NULL,
  `smtpSecure` int(11) NOT NULL DEFAULT 1,
  `smtpUser` varchar(191) DEFAULT NULL,
  `smtpPassword` mediumtext DEFAULT NULL,
  `msTenantId` varchar(191) DEFAULT NULL,
  `msClientId` varchar(191) DEFAULT NULL,
  `msClientSecret` mediumtext DEFAULT NULL,
  `msSender` varchar(191) DEFAULT NULL,
  `updatedAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenantId` (`tenantId`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TicketEvent` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `ticketId` varchar(40) NOT NULL,
  `actorId` varchar(40) DEFAULT NULL,
  `type` varchar(64) NOT NULL,
  `summary` mediumtext NOT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `tenantId` (`tenantId`),
  KEY `actorId` (`actorId`),
  KEY `TicketEvent_ticket_createdAt_idx` (`ticketId`,`createdAt`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ticketId`) REFERENCES `Ticket` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`actorId`) REFERENCES `User` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TicketWatcher` (
  `ticketId` varchar(40) NOT NULL,
  `userId` varchar(40) NOT NULL,
  `role` varchar(64) NOT NULL DEFAULT 'VIEWER',
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`ticketId`,`userId`),
  KEY `TicketWatcher_userId_idx` (`userId`),
  FOREIGN KEY (`ticketId`) REFERENCES `Ticket` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TicketTag` (
  `ticketId` varchar(40) NOT NULL,
  `tagId` varchar(40) NOT NULL,
  PRIMARY KEY (`ticketId`,`tagId`),
  KEY `TicketTag_tagId_idx` (`tagId`),
  FOREIGN KEY (`ticketId`) REFERENCES `Ticket` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tagId`) REFERENCES `Tag` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Comment` (
  `id` varchar(40) NOT NULL,
  `body` mediumtext NOT NULL,
  `isInternal` int(11) NOT NULL DEFAULT 0,
  `createdAt` varchar(30) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `ticketId` varchar(40) NOT NULL,
  `authorId` varchar(40) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `tenantId` (`tenantId`),
  KEY `authorId` (`authorId`),
  KEY `Comment_ticketId_idx` (`ticketId`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ticketId`) REFERENCES `Ticket` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`authorId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Attachment` (
  `id` varchar(40) NOT NULL,
  `filename` varchar(191) NOT NULL,
  `url` mediumtext NOT NULL,
  `size` int(11) NOT NULL,
  `mime` varchar(191) NOT NULL,
  `createdAt` varchar(30) NOT NULL,
  `tenantId` varchar(40) DEFAULT NULL,
  `ticketId` varchar(40) DEFAULT NULL,
  `commentId` varchar(40) DEFAULT NULL,
  `uploaderId` varchar(40) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `tenantId` (`tenantId`),
  KEY `commentId` (`commentId`),
  KEY `uploaderId` (`uploaderId`),
  KEY `Attachment_ticketId_idx` (`ticketId`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ticketId`) REFERENCES `Ticket` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`commentId`) REFERENCES `Comment` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaderId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Notification` (
  `id` varchar(40) NOT NULL,
  `type` varchar(64) NOT NULL,
  `message` mediumtext NOT NULL,
  `read` int(11) NOT NULL DEFAULT 0,
  `createdAt` varchar(30) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `userId` varchar(40) NOT NULL,
  `ticketId` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenantId` (`tenantId`),
  KEY `ticketId` (`ticketId`),
  KEY `Notification_user_read_idx` (`userId`,`read`),
  KEY `Notification_user_createdAt_idx` (`userId`,`createdAt`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ticketId`) REFERENCES `Ticket` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Macro` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `body` mediumtext DEFAULT NULL,
  `isInternal` int(11) NOT NULL DEFAULT 0,
  `setStatus` varchar(191) DEFAULT NULL,
  `setPriority` varchar(191) DEFAULT NULL,
  `setAssignee` varchar(191) DEFAULT NULL,
  `addTagId` varchar(40) DEFAULT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `Macro_tenantId_idx` (`tenantId`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TicketLink` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `ticketId` varchar(40) NOT NULL,
  `linkedId` varchar(40) NOT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticketId` (`ticketId`,`linkedId`),
  KEY `tenantId` (`tenantId`),
  KEY `TicketLink_linkedId_idx` (`linkedId`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ticketId`) REFERENCES `Ticket` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`linkedId`) REFERENCES `Ticket` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TicketPresence` (
  `ticketId` varchar(40) NOT NULL,
  `userId` varchar(40) NOT NULL,
  `state` varchar(191) NOT NULL DEFAULT 'viewing',
  `updatedAt` varchar(30) NOT NULL,
  PRIMARY KEY (`ticketId`,`userId`),
  KEY `userId` (`userId`),
  FOREIGN KEY (`ticketId`) REFERENCES `Ticket` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `KbArticle` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `title` varchar(191) NOT NULL,
  `body` mediumtext NOT NULL,
  `section` varchar(191) DEFAULT NULL,
  `published` int(11) NOT NULL DEFAULT 1,
  `views` int(11) NOT NULL DEFAULT 0,
  `helpful` int(11) NOT NULL DEFAULT 0,
  `searchText` mediumtext DEFAULT NULL,
  `authorId` varchar(40) DEFAULT NULL,
  `createdAt` varchar(30) NOT NULL,
  `updatedAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `authorId` (`authorId`),
  KEY `KbArticle_tenant_idx` (`tenantId`,`published`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`authorId`) REFERENCES `User` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TenantSetting` (
  `tenantId` varchar(40) NOT NULL,
  `key` varchar(64) NOT NULL,
  `value` varchar(4000) NOT NULL,
  `updatedAt` varchar(30) NOT NULL,
  PRIMARY KEY (`tenantId`,`key`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Role` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `description` mediumtext DEFAULT NULL,
  `color` varchar(64) NOT NULL DEFAULT '#6366f1',
  `permissions` varchar(4000) NOT NULL DEFAULT '[]',
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenantId` (`tenantId`,`name`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `AuditLog` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `actorId` varchar(40) DEFAULT NULL,
  `actorName` varchar(191) DEFAULT NULL,
  `action` varchar(191) NOT NULL,
  `targetType` varchar(191) DEFAULT NULL,
  `targetId` varchar(40) DEFAULT NULL,
  `summary` mediumtext NOT NULL,
  `meta` varchar(4000) DEFAULT NULL,
  `ip` varchar(191) DEFAULT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `actorId` (`actorId`),
  KEY `AuditLog_tenant_created_idx` (`tenantId`,`createdAt`),
  KEY `AuditLog_tenant_action_idx` (`tenantId`,`action`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`actorId`) REFERENCES `User` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `AgentGroup` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `description` mediumtext DEFAULT NULL,
  `color` varchar(64) NOT NULL DEFAULT '#0ea5e9',
  `assignMode` varchar(191) NOT NULL DEFAULT 'none',
  `lastAssignedId` varchar(40) DEFAULT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenantId` (`tenantId`,`name`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `AgentGroupMember` (
  `groupId` varchar(40) NOT NULL,
  `userId` varchar(40) NOT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`groupId`,`userId`),
  KEY `AgentGroupMember_user_idx` (`userId`),
  FOREIGN KEY (`groupId`) REFERENCES `AgentGroup` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Organization` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `domain` varchar(191) DEFAULT NULL,
  `notes` mediumtext DEFAULT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenantId` (`tenantId`,`name`),
  KEY `Organization_tenant_domain_idx` (`tenantId`,`domain`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `CustomField` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `key` varchar(64) NOT NULL,
  `label` varchar(191) NOT NULL,
  `type` varchar(64) NOT NULL DEFAULT 'text',
  `options` varchar(4000) DEFAULT NULL,
  `required` int(11) NOT NULL DEFAULT 0,
  `customerVisible` int(11) NOT NULL DEFAULT 1,
  `categoryId` varchar(40) DEFAULT NULL,
  `order` int(11) NOT NULL DEFAULT 0,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenantId` (`tenantId`,`key`),
  KEY `categoryId` (`categoryId`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`categoryId`) REFERENCES `Category` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TicketFieldValue` (
  `ticketId` varchar(40) NOT NULL,
  `fieldId` varchar(40) NOT NULL,
  `value` varchar(4000) NOT NULL,
  PRIMARY KEY (`ticketId`,`fieldId`),
  KEY `TicketFieldValue_field_idx` (`fieldId`),
  FOREIGN KEY (`ticketId`) REFERENCES `Ticket` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`fieldId`) REFERENCES `CustomField` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `CsatSurvey` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `ticketId` varchar(40) NOT NULL,
  `agentId` varchar(40) DEFAULT NULL,
  `requesterId` varchar(40) DEFAULT NULL,
  `tokenHash` varchar(64) NOT NULL,
  `rating` int(11) DEFAULT NULL,
  `comment` mediumtext DEFAULT NULL,
  `createdAt` varchar(30) NOT NULL,
  `answeredAt` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agentId` (`agentId`),
  KEY `requesterId` (`requesterId`),
  KEY `CsatSurvey_ticket_idx` (`ticketId`),
  KEY `CsatSurvey_tenant_answered_idx` (`tenantId`,`answeredAt`),
  KEY `CsatSurvey_token_idx` (`tokenHash`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ticketId`) REFERENCES `Ticket` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`agentId`) REFERENCES `User` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`requesterId`) REFERENCES `User` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ScheduledReport` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `frequency` varchar(191) NOT NULL DEFAULT 'weekly',
  `weekday` int(11) NOT NULL DEFAULT 1,
  `hour` int(11) NOT NULL DEFAULT 8,
  `days` int(11) NOT NULL DEFAULT 7,
  `recipients` varchar(4000) NOT NULL,
  `active` int(11) NOT NULL DEFAULT 1,
  `nextRunAt` varchar(30) DEFAULT NULL,
  `lastSentAt` varchar(30) DEFAULT NULL,
  `createdById` varchar(40) DEFAULT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `tenantId` (`tenantId`),
  KEY `createdById` (`createdById`),
  KEY `ScheduledReport_due_idx` (`active`,`nextRunAt`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`createdById`) REFERENCES `User` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ApiKey` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `userId` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `prefix` varchar(191) NOT NULL,
  `tokenHash` varchar(64) NOT NULL,
  `scope` varchar(64) NOT NULL DEFAULT 'read',
  `lastUsedAt` varchar(30) DEFAULT NULL,
  `createdAt` varchar(30) NOT NULL,
  `revokedAt` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenantId` (`tenantId`),
  KEY `userId` (`userId`),
  KEY `ApiKey_token_idx` (`tokenHash`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Webhook` (
  `id` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `url` mediumtext NOT NULL,
  `secret` varchar(191) NOT NULL,
  `format` varchar(191) NOT NULL DEFAULT 'json',
  `events` varchar(4000) NOT NULL,
  `active` int(11) NOT NULL DEFAULT 1,
  `failCount` int(11) NOT NULL DEFAULT 0,
  `lastStatus` int(11) DEFAULT NULL,
  `lastError` varchar(4000) DEFAULT NULL,
  `lastAt` varchar(30) DEFAULT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `tenantId` (`tenantId`),
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `WebhookDelivery` (
  `id` varchar(40) NOT NULL,
  `webhookId` varchar(40) NOT NULL,
  `tenantId` varchar(40) NOT NULL,
  `event` varchar(191) NOT NULL,
  `payload` mediumtext NOT NULL,
  `status` varchar(64) NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `responseCode` int(11) DEFAULT NULL,
  `error` varchar(4000) DEFAULT NULL,
  `nextAttemptAt` varchar(30) NOT NULL,
  `createdAt` varchar(30) NOT NULL,
  `deliveredAt` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenantId` (`tenantId`),
  KEY `WebhookDelivery_due_idx` (`status`,`nextAttemptAt`),
  KEY `WebhookDelivery_hook_idx` (`webhookId`,`createdAt`),
  FOREIGN KEY (`webhookId`) REFERENCES `Webhook` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tenantId`) REFERENCES `Tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RateHit` (
  `id` varchar(40) NOT NULL,
  `bucket` varchar(191) NOT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `RateHit_bucket_idx` (`bucket`,`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TicketAssignee` (
  `ticketId` varchar(40) NOT NULL,
  `userId` varchar(40) NOT NULL,
  `createdAt` varchar(30) NOT NULL,
  PRIMARY KEY (`ticketId`,`userId`),
  KEY `TicketAssignee_userId_idx` (`userId`),
  FOREIGN KEY (`ticketId`) REFERENCES `Ticket` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
