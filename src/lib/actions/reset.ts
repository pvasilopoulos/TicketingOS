"use server";

import { randomBytes, createHash } from "crypto";
import { redirect } from "next/navigation";
import { z } from "zod";
import { prisma } from "@/lib/db";
import { hashPassword } from "@/lib/auth";
import { sendTenantEmailSafe, appUrl } from "@/lib/email";

export type ResetState = { error?: string; success?: string } | undefined;

const TOKEN_TTL_MS = 60 * 60 * 1000; // 1 hour

function sha256(v: string) {
  return createHash("sha256").update(v).digest("hex");
}

/** Request a password-reset link. Always returns a generic success (no enumeration). */
export async function requestPasswordReset(
  _prev: ResetState,
  formData: FormData,
): Promise<ResetState> {
  const email = ((formData.get("email") as string) ?? "").trim().toLowerCase();
  const generic = {
    success: "If an account exists for that email, we've sent a reset link.",
  };
  if (!email || !email.includes("@")) return generic;

  const user = await prisma.user.findUnique({ where: { email } });
  if (!user) return generic;

  // Invalidate previous tokens, issue a fresh one.
  await prisma.passwordResetToken.deleteMany({ where: { userId: user.id } });
  const raw = randomBytes(32).toString("hex");
  await prisma.passwordResetToken.create({
    data: {
      userId: user.id,
      tokenHash: sha256(raw),
      expiresAt: new Date(Date.now() + TOKEN_TTL_MS),
    },
  });

  const link = `${appUrl()}/reset?token=${raw}`;
  // Dev convenience: the link is also logged to the server console.
  console.log(`[password-reset] ${user.email} -> ${link}`);

  if (user.tenantId) {
    await sendTenantEmailSafe(user.tenantId, {
      to: user.email,
      subject: "Reset your TicketingOS password",
      html: `<p>Hi ${user.name},</p><p>We received a request to reset your password. This link expires in 1 hour:</p><p><a href="${link}">Reset my password</a></p><p>If you didn't request this, you can ignore this email.</p>`,
    });
  }

  return generic;
}

const resetSchema = z
  .object({
    token: z.string().min(10),
    password: z.string().min(6, "Password must be at least 6 characters"),
    confirm: z.string(),
  })
  .refine((d) => d.password === d.confirm, {
    message: "Passwords do not match",
    path: ["confirm"],
  });

export async function resetPassword(
  _prev: ResetState,
  formData: FormData,
): Promise<ResetState> {
  const parsed = resetSchema.safeParse({
    token: formData.get("token"),
    password: formData.get("password"),
    confirm: formData.get("confirm"),
  });
  if (!parsed.success) {
    return { error: parsed.error.issues[0]?.message ?? "Invalid input" };
  }

  const row = await prisma.passwordResetToken.findUnique({
    where: { tokenHash: sha256(parsed.data.token) },
  });
  if (!row || row.usedAt || row.expiresAt < new Date()) {
    return { error: "This reset link is invalid or has expired. Please request a new one." };
  }

  await prisma.$transaction([
    prisma.user.update({
      where: { id: row.userId },
      data: { passwordHash: await hashPassword(parsed.data.password) },
    }),
    prisma.passwordResetToken.update({
      where: { id: row.id },
      data: { usedAt: new Date() },
    }),
  ]);

  redirect("/login?reset=1");
}
