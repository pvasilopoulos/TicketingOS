"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/db";
import { requireTenantUser } from "@/lib/auth";

export async function createSavedView(formData: FormData) {
  const { user, tenantId } = await requireTenantUser();
  const name = ((formData.get("name") as string) ?? "").trim().slice(0, 40);
  let query = ((formData.get("query") as string) ?? "").trim();
  if (query.startsWith("?")) query = query.slice(1);
  if (name.length < 1) return;

  await prisma.savedView.create({
    data: { tenantId, userId: user.id, name, query },
  });
  revalidatePath("/tickets");
}

export async function deleteSavedView(formData: FormData) {
  const { user } = await requireTenantUser();
  const id = formData.get("id") as string;
  if (!id) return;
  await prisma.savedView.deleteMany({ where: { id, userId: user.id } });
  revalidatePath("/tickets");
}
