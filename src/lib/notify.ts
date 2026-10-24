import "server-only";
import { prisma } from "./db";
import { sendPushToUsers } from "./push";
import { emailTenantUsers, appUrl } from "./email";
import { getTenantPriorities } from "./priorities";
import { publishToUser } from "./realtime";
import {
  NOTIFY_CATEGORIES,
  defaultPrefs,
  type Channels,
  type Category,
  type UserPrefs,
} from "./notify-shared";

export type { Category, UserPrefs } from "./notify-shared";
export { NOTIFY_CATEGORIES, CATEGORY_LABELS } from "./notify-shared";

export type TenantPolicy = {
  allowInApp: boolean;
  allowPush: boolean;
  allowEmail: boolean;
  defaults: Record<Category, Channels>;
};

function parseChannelMatrix(json: string): Record<Category, Channels> {
  const base = defaultPrefs().channels;
  try {
    const stored = JSON.parse(json) as Record<string, Partial<Channels>>;
    for (const c of NOTIFY_CATEGORIES) {
      if (stored[c]) {
        base[c] = {
          inApp: stored[c].inApp !== false,
          push: stored[c].push !== false,
          email: stored[c].email !== false,
        };
      }
    }
  } catch {}
  return base;
}

export async function getTenantPolicy(tenantId: string): Promise<TenantPolicy> {
  const row = await prisma.tenantNotificationPolicy.findUnique({ where: { tenantId } });
  return {
    allowInApp: row ? row.allowInApp : true,
    allowPush: row ? row.allowPush : true,
    allowEmail: row ? row.allowEmail : true,
    defaults: parseChannelMatrix(row?.defaults ?? "{}"),
  };
}

export async function getUserPrefs(userId: string, tenantId: string): Promise<UserPrefs> {
  const [row, policy] = await Promise.all([
    prisma.notificationPreference.findUnique({ where: { userId } }),
    getTenantPolicy(tenantId),
  ]);
  const def = defaultPrefs();
  // Users without their own prefs inherit the tenant defaults.
  def.channels = policy.defaults;
  if (!row) return def;
  def.channels = parseChannelMatrix(row.prefs);
  def.quietStart = row.quietStart;
  def.quietEnd = row.quietEnd;
  def.priorityThreshold = row.priorityThreshold;
  def.scope = (row.scope as UserPrefs["scope"]) ?? "all";
  return def;
}

function inQuietHours(p: UserPrefs): boolean {
  if (p.quietStart == null || p.quietEnd == null || p.quietStart === p.quietEnd) return false;
  const now = new Date();
  const mins = now.getHours() * 60 + now.getMinutes();
  return p.quietStart < p.quietEnd
    ? mins >= p.quietStart && mins < p.quietEnd
    : mins >= p.quietStart || mins < p.quietEnd; // overnight window
}

/**
 * Fan a ticket event out to each recipient's enabled channels, honoring their
 * preferences (channel matrix, priority threshold, quiet hours, scope).
 */
export async function dispatch(opts: {
  tenantId: string;
  recipients: string[];
  category: Category;
  ticket: { id: string; number: number; subject: string; priority: string };
  inAppType: string;
  inAppMessage: string;
  pushBody: string;
  emailLine: string;
}) {
  const recipients = [...new Set(opts.recipients)].filter(Boolean);
  if (recipients.length === 0) return;

  const [priorities, policy] = await Promise.all([
    getTenantPriorities(opts.tenantId),
    getTenantPolicy(opts.tenantId),
  ]);
  const orderOf = (key: string) => priorities.find((p) => p.key === key)?.order ?? 0;
  const ticketOrder = orderOf(opts.ticket.priority);

  const pushIds: string[] = [];
  const emailIds: string[] = [];
  const inApp: { tenantId: string; userId: string; type: string; message: string; ticketId: string }[] = [];

  for (const userId of recipients) {
    const prefs = await getUserPrefs(userId, opts.tenantId);
    // Scope only gates the broadcast "new ticket" to all staff.
    if (opts.category === "new_ticket" && prefs.scope !== "all") continue;

    const pref = prefs.channels[opts.category];
    // Intersect the user's choice with the tenant's allowed channels.
    const ch = {
      inApp: pref.inApp && policy.allowInApp,
      push: pref.push && policy.allowPush,
      email: pref.email && policy.allowEmail,
    };
    const meetsThreshold =
      !prefs.priorityThreshold || ticketOrder >= orderOf(prefs.priorityThreshold);

    if (ch.inApp) {
      inApp.push({
        tenantId: opts.tenantId,
        userId,
        type: opts.inAppType,
        message: opts.inAppMessage,
        ticketId: opts.ticket.id,
      });
    }
    if (ch.push && meetsThreshold && !inQuietHours(prefs)) pushIds.push(userId);
    if (ch.email && meetsThreshold) emailIds.push(userId);
  }

  if (inApp.length) {
    await prisma.notification.createMany({ data: inApp });
    // Live-push to any open app tabs of these users.
    for (const n of inApp) {
      publishToUser(n.userId, {
        kind: "notification",
        message: n.message,
        ticketId: opts.ticket.id,
        url: `/tickets/${opts.ticket.id}`,
        at: Date.now(),
      });
    }
  }
  if (pushIds.length) {
    await sendPushToUsers(pushIds, {
      title: `#${opts.ticket.number} · ${opts.ticket.subject}`.slice(0, 80),
      body: opts.pushBody,
      url: `/tickets/${opts.ticket.id}`,
      tag: `ticket-${opts.ticket.id}`,
    });
  }
  if (emailIds.length) {
    await emailTenantUsers(opts.tenantId, emailIds, {
      subject: `[#${opts.ticket.number}] ${opts.ticket.subject}`,
      html: `<p>${opts.emailLine}</p><p><a href="${appUrl()}/tickets/${opts.ticket.id}">View ticket #${opts.ticket.number}</a></p>`,
    });
  }
}
