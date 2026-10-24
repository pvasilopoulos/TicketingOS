"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { prisma } from "@/lib/db";
import { requireTenantUser, isStaff } from "@/lib/auth";
import { createTicketSchema, updateTicketSchema } from "@/lib/validations";
import { sanitizeRichHtml, htmlToText } from "@/lib/sanitize";
import { saveUploads } from "@/lib/storage";
import { isWatcherRole, WATCHER_ROLE_LABELS } from "@/lib/constants";
import { dispatch } from "@/lib/notify";
import { getTenantStatuses, initialStatusKey, resolveStatus } from "@/lib/statuses";
import { getTenantPriorities, resolvePriority } from "@/lib/priorities";
import { getTenantSla, computeDueAt } from "@/lib/sla";

/** Record an activity-log entry for a ticket (best-effort snapshot). */
async function logEvent(
  tenantId: string,
  ticketId: string,
  actorId: string,
  type: string,
  summary: string,
) {
  await prisma.ticketEvent.create({
    data: { tenantId, ticketId, actorId, type, summary },
  });
}

/** Apply enabled TICKET_CREATED automation rules to a freshly created ticket. */
async function applyAutomations(
  tenantId: string,
  ticket: { id: string; number: number; subject: string; createdAt: Date; priority: string; categoryId: string | null; status: string; assigneeId: string | null },
  ctx: {
    statuses: { key: string; isClosed: boolean }[];
    priorities: { key: string }[];
    sla: Record<string, number>;
  },
) {
  const rules = await prisma.automationRule.findMany({
    where: { tenantId, enabled: true, trigger: "TICKET_CREATED" },
    orderBy: [{ order: "asc" }, { createdAt: "asc" }],
  });
  if (rules.length === 0) return;

  const cur = {
    priority: ticket.priority,
    categoryId: ticket.categoryId,
    status: ticket.status,
    assigneeId: ticket.assigneeId,
  };

  for (const r of rules) {
    // Condition check.
    if (r.conditionField === "priority" && cur.priority !== r.conditionValue) continue;
    if (r.conditionField === "category" && cur.categoryId !== r.conditionValue) continue;

    if (r.actionType === "ASSIGN") {
      const agent = await prisma.user.findFirst({
        where: { id: r.actionValue, tenantId, role: { in: ["ADMIN", "AGENT"] } },
        select: { id: true, name: true },
      });
      if (!agent || cur.assigneeId === agent.id) continue;
      await prisma.ticket.update({ where: { id: ticket.id }, data: { assigneeId: agent.id } });
      cur.assigneeId = agent.id;
      await prisma.notification.create({
        data: { tenantId, userId: agent.id, type: "TICKET_ASSIGNED", message: `You were assigned ticket #${ticket.number}: ${ticket.subject}`, ticketId: ticket.id },
      });
      await prisma.ticketEvent.create({
        data: { tenantId, ticketId: ticket.id, actorId: null, type: "ASSIGNED", summary: `auto-assigned the ticket to ${agent.name} (rule "${r.name}")` },
      });
      await dispatch({
        tenantId,
        recipients: [agent.id],
        category: "assigned",
        ticket: { id: ticket.id, number: ticket.number, subject: ticket.subject, priority: cur.priority },
        inAppType: "TICKET_ASSIGNED",
        inAppMessage: `You were assigned ticket #${ticket.number}: ${ticket.subject}`,
        pushBody: "You were auto-assigned this ticket",
        emailLine: `You were assigned ticket #${ticket.number}: ${ticket.subject}.`,
      });
    } else if (r.actionType === "SET_PRIORITY") {
      if (!ctx.priorities.some((p) => p.key === r.actionValue) || cur.priority === r.actionValue) continue;
      await prisma.ticket.update({
        where: { id: ticket.id },
        data: { priority: r.actionValue, dueAt: computeDueAt(ticket.createdAt, r.actionValue, ctx.sla) },
      });
      cur.priority = r.actionValue;
      await prisma.ticketEvent.create({
        data: { tenantId, ticketId: ticket.id, actorId: null, type: "PRIORITY_CHANGED", summary: `set priority to ${r.actionValue} (rule "${r.name}")` },
      });
    } else if (r.actionType === "SET_STATUS") {
      const st = ctx.statuses.find((s) => s.key === r.actionValue);
      if (!st || cur.status === r.actionValue) continue;
      await prisma.ticket.update({
        where: { id: ticket.id },
        data: { status: r.actionValue, closedAt: st.isClosed ? new Date() : null },
      });
      cur.status = r.actionValue;
      await prisma.ticketEvent.create({
        data: { tenantId, ticketId: ticket.id, actorId: null, type: "STATUS_CHANGED", summary: `set status to ${r.actionValue} (rule "${r.name}")` },
      });
    } else if (r.actionType === "ADD_TAG") {
      const tag = await prisma.tag.findFirst({ where: { id: r.actionValue, tenantId }, select: { id: true, name: true } });
      if (!tag) continue;
      await prisma.ticketTag.upsert({
        where: { ticketId_tagId: { ticketId: ticket.id, tagId: tag.id } },
        create: { ticketId: ticket.id, tagId: tag.id },
        update: {},
      });
      await prisma.ticketEvent.create({
        data: { tenantId, ticketId: ticket.id, actorId: null, type: "MEMBER_ADDED", summary: `tagged the ticket #${tag.name} (rule "${r.name}")` },
      });
    }
  }
}

/** Extract mentioned user ids from sanitized editor HTML. */
function extractMentionIds(html: string): string[] {
  const ids = new Set<string>();
  const re = /<span[^>]*data-type="mention"[^>]*>/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(html))) {
    const id = /data-id="([^"]+)"/.exec(m[0]);
    if (id) ids.add(id[1]);
  }
  return [...ids];
}

/**
 * Add @mentioned users to the ticket team (as viewers) and notify them.
 * Skips the actor, the requester, and anyone already on the team.
 */
async function processMentions(
  ticket: { id: string; number: number; subject: string; requesterId: string; priority: string },
  tenantId: string,
  actor: { id: string; name: string },
  html: string,
) {
  const ids = extractMentionIds(html).filter(
    (id) => id !== actor.id && id !== ticket.requesterId,
  );
  if (ids.length === 0) return;

  const users = await prisma.user.findMany({
    where: { id: { in: ids }, tenantId, role: { not: "SUPER_ADMIN" } },
    select: { id: true, name: true },
  });

  const added: string[] = [];
  for (const u of users) {
    const existing = await prisma.ticketWatcher.findUnique({
      where: { ticketId_userId: { ticketId: ticket.id, userId: u.id } },
      select: { userId: true },
    });
    if (!existing) {
      await prisma.ticketWatcher.create({
        data: { ticketId: ticket.id, userId: u.id, role: "VIEWER" },
      });
      await logEvent(tenantId, ticket.id, actor.id, "MEMBER_ADDED", `mentioned ${u.name}`);
    }
    added.push(u.id);
  }

  await dispatch({
    tenantId,
    recipients: added,
    category: "mention",
    ticket: { id: ticket.id, number: ticket.number, subject: ticket.subject, priority: ticket.priority },
    inAppType: "NEW_COMMENT",
    inAppMessage: `${actor.name} mentioned you on ticket #${ticket.number}`,
    pushBody: `${actor.name} mentioned you`,
    emailLine: `${actor.name} mentioned you on ticket #${ticket.number}.`,
  });
}
export type ActionState = { error?: string; ok?: boolean } | undefined;

/** Parse the team JSON submitted from the create form into clean rows. */
function parseTeam(raw: FormDataEntryValue | null): { userId: string; role: string }[] {
  if (typeof raw !== "string" || !raw.trim()) return [];
  try {
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    const seen = new Set<string>();
    const out: { userId: string; role: string }[] = [];
    for (const item of parsed) {
      const userId = String(item?.userId ?? "");
      const role = isWatcherRole(String(item?.role)) ? String(item.role) : "VIEWER";
      if (userId && !seen.has(userId)) {
        seen.add(userId);
        out.push({ userId, role });
      }
    }
    return out;
  } catch {
    return [];
  }
}

/**
 * Whether a user may manage a ticket: staff, the requester, or a team member
 * with the COLLABORATOR role.
 */
async function canManageTicket(
  ticket: { id: string; requesterId: string },
  user: { id: string; role: string },
): Promise<boolean> {
  if (isStaff(user.role) || ticket.requesterId === user.id) return true;
  const w = await prisma.ticketWatcher.findUnique({
    where: { ticketId_userId: { ticketId: ticket.id, userId: user.id } },
    select: { role: true },
  });
  return w?.role === "COLLABORATOR";
}

/** Create a new ticket within the current tenant. */
export async function createTicket(_prev: ActionState, formData: FormData) {
  const { user, tenantId } = await requireTenantUser();

  const parsed = createTicketSchema.safeParse({
    subject: formData.get("subject"),
    priority: formData.get("priority"),
    categoryId: formData.get("categoryId") || null,
  });
  if (!parsed.success) {
    return { error: parsed.error.issues[0]?.message ?? "Invalid input" };
  }
  const data = parsed.data;

  // Rich-text description: sanitize, then validate the plain-text length.
  const description = sanitizeRichHtml((formData.get("description") as string) ?? "");
  if (htmlToText(description).length < 5) {
    return { error: "Please describe the issue" };
  }

  // Validate category belongs to tenant.
  if (data.categoryId) {
    const cat = await prisma.category.findFirst({
      where: { id: data.categoryId, tenantId },
    });
    if (!cat) return { error: "Invalid category" };
  }

  // Per-tenant incrementing ticket number.
  const last = await prisma.ticket.findFirst({
    where: { tenantId },
    orderBy: { number: "desc" },
    select: { number: true },
  });
  const number = (last?.number ?? 0) + 1;

  // Validate priority against the tenant's custom priorities.
  const priorities = await getTenantPriorities(tenantId);
  if (!priorities.some((p) => p.key === data.priority)) {
    return { error: "Invalid priority" };
  }

  // New tickets start in the tenant's initial status.
  const statuses = await getTenantStatuses(tenantId);
  const sla = await getTenantSla(tenantId);

  const ticket = await prisma.ticket.create({
    data: {
      number,
      subject: data.subject,
      description,
      priority: data.priority,
      categoryId: data.categoryId ?? null,
      tenantId,
      requesterId: user.id,
      status: initialStatusKey(statuses),
      dueAt: computeDueAt(new Date(), data.priority, sla),
    },
  });

  // Save any file attachments on the opening message.
  const files = formData.getAll("files").filter((f): f is File => f instanceof File);
  if (files.length) {
    await saveUploads(files, { tenantId, uploaderId: user.id, ticketId: ticket.id });
  }

  // Team members chosen on the create form: [{ userId, role }].
  const team = parseTeam(formData.get("team"));
  if (team.length) {
    const ids = team.map((t) => t.userId);
    const valid = await prisma.user.findMany({
      where: { id: { in: ids }, tenantId },
      select: { id: true },
    });
    const validIds = new Set(valid.map((v) => v.id));
    const rows = team.filter(
      (t) => validIds.has(t.userId) && t.userId !== user.id,
    );
    if (rows.length) {
      await prisma.ticketWatcher.createMany({
        data: rows.map((t) => ({ ticketId: ticket.id, userId: t.userId, role: t.role })),
      });
      await dispatch({
        tenantId,
        recipients: rows.map((t) => t.userId),
        category: "team",
        ticket: { id: ticket.id, number, subject: data.subject, priority: data.priority },
        inAppType: "NEW_TICKET",
        inAppMessage: `You were added to ticket #${number}: ${data.subject}`,
        pushBody: `${user.name} added you to this ticket`,
        emailLine: `${user.name} added you to ticket #${number}: ${data.subject}.`,
      });
    }
  }

  // Notify all staff in the tenant about the new ticket.
  const staff = await prisma.user.findMany({
    where: { tenantId, role: { in: ["ADMIN", "AGENT"] }, id: { not: user.id } },
    select: { id: true },
  });
  if (staff.length) {
    await dispatch({
      tenantId,
      recipients: staff.map((s) => s.id),
      category: "new_ticket",
      ticket: { id: ticket.id, number, subject: data.subject, priority: data.priority },
      inAppType: "NEW_TICKET",
      inAppMessage: `New ticket #${number}: ${data.subject}`,
      pushBody: `New ticket: ${data.subject}`,
      emailLine: `New ticket #${number}: ${data.subject}.`,
    });
  }

  await logEvent(tenantId, ticket.id, user.id, "CREATED", "opened the ticket");
  await processMentions(
    { id: ticket.id, number: ticket.number, subject: ticket.subject, requesterId: user.id, priority: data.priority },
    tenantId,
    { id: user.id, name: user.name },
    description,
  );
  await applyAutomations(tenantId, ticket, { statuses, priorities, sla });

  revalidatePath("/tickets");
  revalidatePath("/dashboard");
  redirect(`/tickets/${ticket.id}`);
}

/** Update ticket fields (staff only). */
export async function updateTicket(ticketId: string, formData: FormData) {
  const { user, tenantId } = await requireTenantUser();

  const ticket = await prisma.ticket.findFirst({
    where: { id: ticketId, tenantId },
  });
  if (!ticket) return;
  // Staff, the requester, or a COLLABORATOR team member may manage.
  if (!(await canManageTicket(ticket, user))) return;

  const raw = {
    status: formData.get("status") ?? undefined,
    priority: formData.get("priority") ?? undefined,
    assigneeId:
      formData.get("assigneeId") === "" ? null : formData.get("assigneeId") ?? undefined,
    categoryId:
      formData.get("categoryId") === "" ? null : formData.get("categoryId") ?? undefined,
  };
  const parsed = updateTicketSchema.safeParse(raw);
  if (!parsed.success) return;
  const data = parsed.data;

  const events: string[] = [];

  // Validate relations belong to tenant.
  let assigneeName: string | null = null;
  if (data.assigneeId) {
    const a = await prisma.user.findFirst({
      where: { id: data.assigneeId, tenantId, role: { in: ["ADMIN", "AGENT"] } },
    });
    if (!a) return;
    assigneeName = a.name;
  }
  let categoryName: string | null = null;
  if (data.categoryId) {
    const c = await prisma.category.findFirst({
      where: { id: data.categoryId, tenantId },
    });
    if (!c) return;
    categoryName = c.name;
  }

  // Validate the status against the tenant's custom statuses.
  let targetStatusIsClosed = false;
  let statusFromLabel = ticket.status;
  let statusToLabel = data.status ?? "";
  if (data.status) {
    const statuses = await getTenantStatuses(tenantId);
    const st = statuses.find((s) => s.key === data.status);
    if (!st) return;
    targetStatusIsClosed = st.isClosed;
    statusFromLabel = resolveStatus(statuses, ticket.status).label;
    statusToLabel = st.label;
  }

  // Validate priority against the tenant's custom priorities.
  let priorityFromLabel = ticket.priority;
  let priorityToLabel = data.priority ?? "";
  if (data.priority) {
    const priorities = await getTenantPriorities(tenantId);
    if (!priorities.some((p) => p.key === data.priority)) return;
    priorityFromLabel = resolvePriority(priorities, ticket.priority).label;
    priorityToLabel = resolvePriority(priorities, data.priority).label;
  }

  const statusChanged = data.status && data.status !== ticket.status;
  const priorityChanged = data.priority && data.priority !== ticket.priority;
  const assigneeChanged =
    data.assigneeId !== undefined && data.assigneeId !== ticket.assigneeId;
  const categoryChanged =
    data.categoryId !== undefined && data.categoryId !== ticket.categoryId;

  // Recompute the SLA deadline when priority changes.
  let newDueAt = ticket.dueAt;
  if (priorityChanged) {
    const sla = await getTenantSla(tenantId);
    newDueAt = computeDueAt(ticket.createdAt, data.priority!, sla);
  }

  await prisma.ticket.update({
    where: { id: ticketId },
    data: {
      ...data,
      dueAt: newDueAt,
      closedAt: data.status
        ? targetStatusIsClosed
          ? new Date()
          : null
        : ticket.closedAt,
    },
  });

  // Build activity-log entries for what changed.
  if (statusChanged) events.push(`changed status from ${statusFromLabel} to ${statusToLabel}`);
  if (priorityChanged)
    events.push(`changed priority from ${priorityFromLabel} to ${priorityToLabel}`);
  if (assigneeChanged)
    events.push(assigneeName ? `assigned the ticket to ${assigneeName}` : "unassigned the ticket");
  if (categoryChanged)
    events.push(categoryName ? `set the category to ${categoryName}` : "removed the category");
  for (const summary of events) {
    const type = summary.startsWith("changed status")
      ? "STATUS_CHANGED"
      : summary.startsWith("changed priority")
        ? "PRIORITY_CHANGED"
        : summary.includes("assigned")
          ? "ASSIGNED"
          : "CATEGORY_CHANGED";
    await logEvent(tenantId, ticketId, user.id, type, summary);
  }

  // Notify requester and watchers of status changes.
  const effTicket = { id: ticket.id, number: ticket.number, subject: ticket.subject, priority: data.priority ?? ticket.priority };

  if (statusChanged) {
    const watcherIds = (
      await prisma.ticketWatcher.findMany({ where: { ticketId }, select: { userId: true } })
    ).map((w) => w.userId);
    const recipients = new Set<string>([ticket.requesterId, ...watcherIds]);
    recipients.delete(user.id);
    await dispatch({
      tenantId,
      recipients: [...recipients],
      category: "status",
      ticket: effTicket,
      inAppType: "STATUS_CHANGED",
      inAppMessage: `Ticket #${ticket.number} is now ${statusToLabel}`,
      pushBody: `Status is now ${statusToLabel}`,
      emailLine: `Ticket #${ticket.number} is now ${statusToLabel}.`,
    });
  }
  // Notify newly assigned agent.
  if (assigneeChanged && data.assigneeId && data.assigneeId !== user.id) {
    await dispatch({
      tenantId,
      recipients: [data.assigneeId],
      category: "assigned",
      ticket: effTicket,
      inAppType: "TICKET_ASSIGNED",
      inAppMessage: `You were assigned ticket #${ticket.number}: ${ticket.subject}`,
      pushBody: "You were assigned this ticket",
      emailLine: `You were assigned ticket #${ticket.number}: ${ticket.subject}.`,
    });
  }

  revalidatePath(`/tickets/${ticketId}`);
  revalidatePath("/tickets");
  revalidatePath("/dashboard");
}

/** Apply the same change to many tickets at once (staff only). */
export async function bulkUpdateTickets(
  ids: string[],
  changes: { status?: string; priority?: string; assigneeId?: string | null },
) {
  const { user } = await requireTenantUser();
  if (!isStaff(user.role)) return;

  for (const id of ids.slice(0, 100)) {
    const fd = new FormData();
    if (changes.status) fd.set("status", changes.status);
    if (changes.priority) fd.set("priority", changes.priority);
    if (changes.assigneeId !== undefined) fd.set("assigneeId", changes.assigneeId ?? "");
    // Reuses the single-ticket path: validation, events, SLA, notifications.
    await updateTicket(id, fd);
  }
  revalidatePath("/tickets");
  revalidatePath("/dashboard");
}

/** Add a comment / reply to a ticket. */
export async function addComment(
  ticketId: string,
  _prev: ActionState,
  formData: FormData,
): Promise<ActionState> {
  const { user, tenantId } = await requireTenantUser();

  const ticket = await prisma.ticket.findFirst({
    where: { id: ticketId, tenantId },
  });
  if (!ticket) return { error: "Ticket not found" };

  // Customers may reply only on tickets they requested or are a member of.
  if (!isStaff(user.role) && ticket.requesterId !== user.id) {
    const member = await prisma.ticketWatcher.findUnique({
      where: { ticketId_userId: { ticketId, userId: user.id } },
      select: { userId: true },
    });
    if (!member) return { error: "Not allowed" };
  }

  const wantInternal = formData.get("isInternal") === "on";
  const body = sanitizeRichHtml((formData.get("body") as string) ?? "");
  const files = formData.getAll("files").filter((f): f is File => f instanceof File);
  const hasFiles = files.some((f) => f.size > 0);

  // A reply needs text or at least one attachment.
  if (htmlToText(body).length < 1 && !hasFiles) {
    return { error: "Comment cannot be empty" };
  }

  // Only staff can post internal notes.
  const isInternal = isStaff(user.role) && wantInternal;

  const comment = await prisma.comment.create({
    data: {
      tenantId,
      ticketId,
      authorId: user.id,
      body,
      isInternal,
    },
  });

  if (hasFiles) {
    await saveUploads(files, {
      tenantId,
      uploaderId: user.id,
      ticketId,
      commentId: comment.id,
    });
  }

  // @mentions in a (non-internal) reply add people to the team and notify them.
  if (!isInternal) {
    await processMentions(ticket, tenantId, { id: user.id, name: user.name }, body);
  }

  // Reopen a resolved/closed ticket when the requester replies.
  if (!isStaff(user.role)) {
    const statuses = await getTenantStatuses(tenantId);
    const current = statuses.find((s) => s.key === ticket.status);
    if (current?.isClosed) {
      await prisma.ticket.update({
        where: { id: ticketId },
        data: { status: initialStatusKey(statuses), closedAt: null },
      });
      await logEvent(tenantId, ticketId, user.id, "REOPENED", "reopened the ticket by replying");
    }
  }

  // Notify the requester, assignee and watchers.
  // Internal notes go only to staff recipients.
  const watchers = await prisma.ticketWatcher.findMany({
    where: { ticketId },
    include: { user: { select: { id: true, role: true } } },
  });

  const recipients = new Set<string>();
  if (!isInternal && ticket.requesterId !== user.id) {
    recipients.add(ticket.requesterId);
  }
  if (ticket.assigneeId && ticket.assigneeId !== user.id) {
    recipients.add(ticket.assigneeId);
  }
  for (const w of watchers) {
    if (w.user.id === user.id) continue;
    if (isInternal && !isStaff(w.user.role)) continue; // internal → staff only
    recipients.add(w.user.id);
  }

  await dispatch({
    tenantId,
    recipients: [...recipients],
    category: "reply",
    ticket: { id: ticket.id, number: ticket.number, subject: ticket.subject, priority: ticket.priority },
    inAppType: "NEW_COMMENT",
    inAppMessage: `${isInternal ? "Internal note" : "New reply"} on ticket #${ticket.number}`,
    pushBody: `${user.name} ${isInternal ? "added an internal note" : "replied"}`,
    emailLine: `${user.name} ${isInternal ? "added an internal note" : "replied"} on ticket #${ticket.number}.`,
  });

  revalidatePath(`/tickets/${ticketId}`);
  return { ok: true };
}

/** Add a tenant user to the ticket team (staff, requester, or collaborator). */
export async function addWatcher(ticketId: string, formData: FormData) {
  const { user, tenantId } = await requireTenantUser();

  const ticket = await prisma.ticket.findFirst({
    where: { id: ticketId, tenantId },
  });
  if (!ticket) return;
  if (!(await canManageTicket(ticket, user))) return;

  const userId = formData.get("userId") as string;
  if (!userId) return;
  const roleRaw = String(formData.get("role") ?? "VIEWER");
  const role = isWatcherRole(roleRaw) ? roleRaw : "VIEWER";

  // The target must belong to the same tenant and not be the requester.
  if (userId === ticket.requesterId) return;
  const target = await prisma.user.findFirst({ where: { id: userId, tenantId } });
  if (!target) return;

  await prisma.ticketWatcher.upsert({
    where: { ticketId_userId: { ticketId, userId } },
    create: { ticketId, userId, role },
    update: { role },
  });

  await logEvent(
    tenantId,
    ticketId,
    user.id,
    "MEMBER_ADDED",
    `added ${target.name} to the team as ${WATCHER_ROLE_LABELS[role as keyof typeof WATCHER_ROLE_LABELS]}`,
  );

  if (userId !== user.id) {
    await dispatch({
      tenantId,
      recipients: [userId],
      category: "team",
      ticket: { id: ticket.id, number: ticket.number, subject: ticket.subject, priority: ticket.priority },
      inAppType: "NEW_TICKET",
      inAppMessage: `You were added to ticket #${ticket.number}`,
      pushBody: `${user.name} added you to this ticket`,
      emailLine: `${user.name} added you to ticket #${ticket.number}.`,
    });
  }

  revalidatePath(`/tickets/${ticketId}`);
}

/** Change a team member's role (staff, requester, or collaborator). */
export async function setWatcherRole(ticketId: string, formData: FormData) {
  const { user, tenantId } = await requireTenantUser();

  const ticket = await prisma.ticket.findFirst({
    where: { id: ticketId, tenantId },
  });
  if (!ticket) return;
  if (!(await canManageTicket(ticket, user))) return;

  const userId = formData.get("userId") as string;
  const roleRaw = String(formData.get("role") ?? "");
  if (!userId || !isWatcherRole(roleRaw)) return;

  await prisma.ticketWatcher.updateMany({
    where: { ticketId, userId },
    data: { role: roleRaw },
  });
  const target = await prisma.user.findUnique({ where: { id: userId }, select: { name: true } });
  if (target) {
    await logEvent(
      tenantId,
      ticketId,
      user.id,
      "MEMBER_ROLE_CHANGED",
      `changed ${target.name}'s role to ${WATCHER_ROLE_LABELS[roleRaw as keyof typeof WATCHER_ROLE_LABELS]}`,
    );
  }
  revalidatePath(`/tickets/${ticketId}`);
}

/** Remove a team member (staff, requester, or collaborator). */
export async function removeWatcher(ticketId: string, formData: FormData) {
  const { user, tenantId } = await requireTenantUser();

  const ticket = await prisma.ticket.findFirst({
    where: { id: ticketId, tenantId },
  });
  if (!ticket) return;
  if (!(await canManageTicket(ticket, user))) return;

  const userId = formData.get("userId") as string;
  if (!userId) return;

  const target = await prisma.user.findUnique({ where: { id: userId }, select: { name: true } });
  const removed = await prisma.ticketWatcher.deleteMany({ where: { ticketId, userId } });
  if (removed.count > 0 && target) {
    await logEvent(tenantId, ticketId, user.id, "MEMBER_REMOVED", `removed ${target.name} from the team`);
  }
  revalidatePath(`/tickets/${ticketId}`);
}
