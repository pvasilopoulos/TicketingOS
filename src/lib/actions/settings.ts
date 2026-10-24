"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/db";
import { requireTenantUser, isTenantAdmin } from "@/lib/auth";

export type SettingsState = { error?: string; success?: string } | undefined;

export async function updateWorkspaceName(
  _prev: SettingsState,
  formData: FormData,
): Promise<SettingsState> {
  const { user, tenantId } = await requireTenantUser();
  if (!isTenantAdmin(user.role)) return { error: "Not allowed" };

  const name = (formData.get("name") as string)?.trim();
  if (!name || name.length < 2) return { error: "Name is too short" };

  await prisma.tenant.update({ where: { id: tenantId }, data: { name } });
  revalidatePath("/", "layout");
  return { success: "Workspace name updated" };
}

export async function createCategory(
  _prev: SettingsState,
  formData: FormData,
): Promise<SettingsState> {
  const { user, tenantId } = await requireTenantUser();
  if (!isTenantAdmin(user.role)) return { error: "Not allowed" };

  const name = (formData.get("name") as string)?.trim();
  const color = (formData.get("color") as string) || "#64748b";
  if (!name) return { error: "Category name is required" };

  const exists = await prisma.category.findFirst({ where: { tenantId, name } });
  if (exists) return { error: "A category with that name already exists" };

  await prisma.category.create({ data: { name, color, tenantId } });
  revalidatePath("/settings");
  return { success: `Added "${name}"` };
}

export async function deleteCategory(formData: FormData) {
  const { user, tenantId } = await requireTenantUser();
  if (!isTenantAdmin(user.role)) return;

  const id = formData.get("id") as string;
  await prisma.category.deleteMany({ where: { id, tenantId } });
  revalidatePath("/settings");
}
