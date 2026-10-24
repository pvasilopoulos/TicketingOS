import "server-only";
import nodemailer from "nodemailer";
import { prisma } from "./db";
import { decryptSecret } from "./crypto";

export type SendResult = { ok: boolean; error?: string; skipped?: boolean };

type Message = { to: string; subject: string; html: string; text?: string };

/** Load a tenant's email settings (secrets stay encrypted). */
export function getEmailSettings(tenantId: string) {
  return prisma.emailSetting.findUnique({ where: { tenantId } });
}

function fromHeader(name: string | null | undefined, email: string) {
  return name ? `${name} <${email}>` : email;
}

function htmlToText(html: string) {
  return html.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
}

/**
 * Send an email using the tenant's configured provider.
 * No-ops (skipped) when email is disabled or not configured.
 */
export async function sendTenantEmail(
  tenantId: string,
  msg: Message,
): Promise<SendResult> {
  const s = await getEmailSettings(tenantId);
  if (!s || !s.enabled) return { ok: false, skipped: true };

  const message = { ...msg, text: msg.text ?? htmlToText(msg.html) };

  try {
    if (s.provider === "MICROSOFT365") {
      return await sendViaGraph(s, message);
    }
    return await sendViaSmtp(s, message);
  } catch (e) {
    return { ok: false, error: e instanceof Error ? e.message : "Send failed" };
  }
}

async function sendViaSmtp(
  s: NonNullable<Awaited<ReturnType<typeof getEmailSettings>>>,
  msg: Message & { text: string },
): Promise<SendResult> {
  if (!s.smtpHost || !s.smtpPort || !s.fromEmail) {
    return { ok: false, error: "SMTP is not fully configured" };
  }
  const transport = nodemailer.createTransport({
    host: s.smtpHost,
    port: s.smtpPort,
    secure: s.smtpSecure,
    auth: s.smtpUser
      ? { user: s.smtpUser, pass: decryptSecret(s.smtpPassword) }
      : undefined,
  });
  await transport.sendMail({
    from: fromHeader(s.fromName, s.fromEmail),
    to: msg.to,
    subject: msg.subject,
    html: msg.html,
    text: msg.text,
  });
  return { ok: true };
}

async function sendViaGraph(
  s: NonNullable<Awaited<ReturnType<typeof getEmailSettings>>>,
  msg: Message & { text: string },
): Promise<SendResult> {
  if (!s.msTenantId || !s.msClientId || !s.msClientSecret || !s.msSender) {
    return { ok: false, error: "Microsoft 365 is not fully configured" };
  }

  // 1) Client-credentials token.
  const tokenRes = await fetch(
    `https://login.microsoftonline.com/${encodeURIComponent(s.msTenantId)}/oauth2/v2.0/token`,
    {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        client_id: s.msClientId,
        client_secret: decryptSecret(s.msClientSecret),
        scope: "https://graph.microsoft.com/.default",
        grant_type: "client_credentials",
      }),
    },
  );
  if (!tokenRes.ok) {
    const body = await tokenRes.text();
    return { ok: false, error: `Auth failed (${tokenRes.status}): ${body.slice(0, 200)}` };
  }
  const { access_token } = (await tokenRes.json()) as { access_token: string };

  // 2) Send via Graph on behalf of the configured sender mailbox.
  const sendRes = await fetch(
    `https://graph.microsoft.com/v1.0/users/${encodeURIComponent(s.msSender)}/sendMail`,
    {
      method: "POST",
      headers: {
        Authorization: `Bearer ${access_token}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        message: {
          subject: msg.subject,
          body: { contentType: "HTML", content: msg.html },
          toRecipients: [{ emailAddress: { address: msg.to } }],
          from: { emailAddress: { address: s.fromEmail ?? s.msSender } },
        },
        saveToSentItems: false,
      }),
    },
  );
  if (!sendRes.ok) {
    const body = await sendRes.text();
    return { ok: false, error: `Graph send failed (${sendRes.status}): ${body.slice(0, 200)}` };
  }
  return { ok: true };
}

/** Fire-and-forget email that never throws into the caller. */
export async function sendTenantEmailSafe(tenantId: string, msg: Message) {
  try {
    await sendTenantEmail(tenantId, msg);
  } catch {
    // best-effort: notification UI already persisted
  }
}

/** The app URL used to build links inside notification emails. */
export function appUrl() {
  return (process.env.APP_URL ?? "http://localhost:3000").replace(/\/$/, "");
}

/**
 * Email several tenant users (best-effort). Does nothing when email is
 * disabled, so callers can invoke it unconditionally without extra cost
 * beyond a single settings lookup.
 */
export async function emailTenantUsers(
  tenantId: string,
  userIds: string[],
  msg: { subject: string; html: string },
) {
  if (userIds.length === 0) return;
  const s = await getEmailSettings(tenantId);
  if (!s || !s.enabled) return;

  const users = await prisma.user.findMany({
    where: { id: { in: userIds } },
    select: { email: true },
  });
  await Promise.all(
    users.map((u) => sendTenantEmailSafe(tenantId, { to: u.email, ...msg })),
  );
}
