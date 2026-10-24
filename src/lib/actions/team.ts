"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/db";
import { requireTenantUser, hashPassword, isTenantAdmin } from "@/lib/auth";
import { createMemberSchema, updateUserRoleSchema } from "@/lib/validations";
import { AVATAR_COLORS } from "@/lib/constants";

export type TeamState = { error?: string; success?: string } | undefined;

export async function createMember(
  _prev: TeamState,
  formData: FormData,
): Promise<TeamState> {
  const { user, tenantId } = await requireTenantUser();
  if (!isTenantAdmin(user.role)) return { error: "Only admins can add members" };

  const parsed = createMemberSchema.safeParse({
    name: formData.get("name"),
    email: formData.get("email"),
    password: formData.get("password"),
    role: formData.get("role"),
  });
  if (!parsed.success) {
    return { error: parsed.error.issues[0]?.message ?? "Invalid input" };
  }
  const { name, email, password, role } = parsed.data;

  const existing = await prisma.user.findUnique({
    where: { email: email.toLowerCase() },
  });
  if (existing) return { error: "Email already in use" };

  const count = await prisma.user.count({ where: { tenantId } });
  await prisma.user.create({
    data: {
      name,
      email: email.toLowerCase(),
      passwordHash: await hashPassword(password),
      role,
      tenantId,
      avatarColor: AVATAR_COLORS[count % AVATAR_COLORS.length],
    },
  });

  revalidatePath("/team");
  return { success: `${name} added to the team` };
}

export async function updateMemberRole(formData: FormData) {
  const { user, tenantId } = await requireTenantUser();
  if (!isTenantAdmin(user.role)) return;

  const parsed = updateUserRoleSchema.safeParse({
    userId: formData.get("userId"),
    role: formData.get("role"),
  });
  if (!parsed.success) return;
  const { userId, role } = parsed.data;

  // Ensure the target belongs to the same tenant.
  const target = await prisma.user.findFirst({ where: { id: userId, tenantId } });
  if (!target) return;

  // Don't allow demoting the last remaining admin.
  if (target.role === "ADMIN" && role !== "ADMIN") {
    const admins = await prisma.user.count({ where: { tenantId, role: "ADMIN" } });
    if (admins <= 1) return;
  }

  await prisma.user.update({ where: { id: userId }, data: { role } });
  revalidatePath("/team");
}

export async function removeMember(formData: FormData) {
  const { user, tenantId } = await requireTenantUser();
  if (!isTenantAdmin(user.role)) return;

  const userId = formData.get("userId") as string;
  if (!userId || userId === user.id) return; // can't remove yourself

  const target = await prisma.user.findFirst({ where: { id: userId, tenantId } });
  if (!target) return;

  if (target.role === "ADMIN") {
    const admins = await prisma.user.count({ where: { tenantId, role: "ADMIN" } });
    if (admins <= 1) return;
  }

  await prisma.user.delete({ where: { id: userId } });
  revalidatePath("/team");
}
