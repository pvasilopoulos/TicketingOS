"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { prisma } from "@/lib/db";
import { requireTenantAdmin } from "@/lib/auth";
import { encryptSecret } from "@/lib/crypto";
import { sendTenantEmail } from "@/lib/email";

export type EmailState = { error?: string; success?: string } | undefined;

const baseSchema = z.object({
  enabled: z.boolean(),
  provider: z.enum(["SMTP", "MICROSOFT365"]),
  fromName: z.string().max(80).optional().nullable(),
  fromEmail: z.string().email("A valid From email is required"),
});

function str(fd: FormData, key: string): string {
  return ((fd.get(key) as string) ?? "").trim();
}

export async function saveEmailSettings(
  _prev: EmailState,
  formData: FormData,
): Promise<EmailState> {
  const { tenantId } = await requireTenantAdmin();

  const base = baseSchema.safeParse({
    enabled: formData.get("enabled") === "on",
    provider: str(formData, "provider") || "SMTP",
    fromName: str(formData, "fromName") || null,
    fromEmail: str(formData, "fromEmail"),
  });
  if (!base.success) {
    return { error: base.error.issues[0]?.message ?? "Invalid input" };
  }
  const { enabled, provider, fromName, fromEmail } = base.data;

  const existing = await prisma.emailSetting.findUnique({ where: { tenantId } });

  // Secret fields: keep the stored (encrypted) value when the field is blank.
  const smtpPasswordInput = str(formData, "smtpPassword");
  const msClientSecretInput = str(formData, "msClientSecret");

  const data = {
    enabled,
    provider,
    fromName,
    fromEmail,
    // SMTP
    smtpHost: str(formData, "smtpHost") || null,
    smtpPort: Number(str(formData, "smtpPort")) || null,
    smtpSecure: formData.get("smtpSecure") === "on",
    smtpUser: str(formData, "smtpUser") || null,
    smtpPassword: smtpPasswordInput
      ? encryptSecret(smtpPasswordInput)
      : (existing?.smtpPassword ?? null),
    // Microsoft 365
    msTenantId: str(formData, "msTenantId") || null,
    msClientId: str(formData, "msClientId") || null,
    msClientSecret: msClientSecretInput
      ? encryptSecret(msClientSecretInput)
      : (existing?.msClientSecret ?? null),
    msSender: str(formData, "msSender") || null,
  };

  // Light provider-specific completeness check when enabling.
  if (enabled && provider === "SMTP" && (!data.smtpHost || !data.smtpPort)) {
    return { error: "SMTP host and port are required to enable email" };
  }
  if (
    enabled &&
    provider === "MICROSOFT365" &&
    (!data.msTenantId || !data.msClientId || !data.msSender ||
      (!msClientSecretInput && !existing?.msClientSecret))
  ) {
    return { error: "Microsoft 365 tenant, client ID, secret and sender are required" };
  }

  await prisma.emailSetting.upsert({
    where: { tenantId },
    create: { tenantId, ...data },
    update: data,
  });

  revalidatePath("/settings");
  return { success: "Email settings saved" };
}

export async function sendTestEmail(
  _prev: EmailState,
  _formData: FormData,
): Promise<EmailState> {
  const { user, tenantId } = await requireTenantAdmin();

  const settings = await prisma.emailSetting.findUnique({ where: { tenantId } });
  if (!settings) return { error: "Save your settings first" };
  if (!settings.enabled) return { error: "Enable email notifications first" };

  const result = await sendTenantEmail(tenantId, {
    to: user.email,
    subject: "TicketingOS — test email",
    html: `<p>Hi ${user.name},</p><p>This is a test email from <strong>TicketingOS</strong>. If you received it, your ${
      settings.provider === "MICROSOFT365" ? "Microsoft 365" : "SMTP"
    } email notifications are working. ✅</p>`,
  });

  if (result.ok) return { success: `Test email sent to ${user.email}` };
  return { error: result.error ?? "Could not send test email" };
}
