"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/db";
import { requireTenantAdmin } from "@/lib/auth";
import { sanitizeRichHtml, htmlToText } from "@/lib/sanitize";

export type CannedState = { error?: string; success?: string } | undefined;

export async function createCanned(
  _prev: CannedState,
  formData: FormData,
): Promise<CannedState> {
  const { tenantId } = await requireTenantAdmin();
  const title = ((formData.get("title") as string) ?? "").trim().slice(0, 80);
  const body = sanitizeRichHtml((formData.get("body") as string) ?? "");
  if (title.length < 2) return { error: "Title is too short" };
  if (htmlToText(body).length < 2) return { error: "Body is required" };

  await prisma.cannedResponse.create({ data: { tenantId, title, body } });
  revalidatePath("/settings");
  return { success: `Saved "${title}"` };
}

export async function updateCanned(
  _prev: CannedState,
  formData: FormData,
): Promise<CannedState> {
  const { tenantId } = await requireTenantAdmin();
  const id = formData.get("id") as string;
  const title = ((formData.get("title") as string) ?? "").trim().slice(0, 80);
  const body = sanitizeRichHtml((formData.get("body") as string) ?? "");
  if (!id || title.length < 2) return { error: "Title is too short" };
  if (htmlToText(body).length < 2) return { error: "Body is required" };

  await prisma.cannedResponse.updateMany({
    where: { id, tenantId },
    data: { title, body },
  });
  revalidatePath("/settings");
  return { success: "Updated" };
}

export async function deleteCanned(formData: FormData) {
  const { tenantId } = await requireTenantAdmin();
  const id = formData.get("id") as string;
  if (!id) return;
  await prisma.cannedResponse.deleteMany({ where: { id, tenantId } });
  revalidatePath("/settings");
}
