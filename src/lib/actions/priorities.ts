"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/db";
import { requireTenantAdmin } from "@/lib/auth";
import { priorityKeyFromLabel } from "@/lib/constants";

export type PriorityState = { error?: string; success?: string } | undefined;

function reval() {
  revalidatePath("/", "layout");
}

export async function createPriority(
  _prev: PriorityState,
  formData: FormData,
): Promise<PriorityState> {
  const { tenantId } = await requireTenantAdmin();
  const label = ((formData.get("label") as string) ?? "").trim();
  const color = ((formData.get("color") as string) || "#64748b").trim();
  if (label.length < 2) return { error: "Priority name is too short" };

  const base = priorityKeyFromLabel(label);
  let key = base;
  let n = 1;
  while (await prisma.ticketPriority.findFirst({ where: { tenantId, key } })) {
    key = `${base}_${n++}`;
  }

  const count = await prisma.ticketPriority.count({ where: { tenantId } });
  await prisma.ticketPriority.create({
    data: { tenantId, key, label, color, order: count },
  });
  reval();
  return { success: `Added "${label}"` };
}

export async function updatePriority(formData: FormData) {
  const { tenantId } = await requireTenantAdmin();
  const id = formData.get("id") as string;
  const label = ((formData.get("label") as string) ?? "").trim();
  const color = ((formData.get("color") as string) || "#64748b").trim();
  if (!id || label.length < 2) return;
  await prisma.ticketPriority.updateMany({
    where: { id, tenantId },
    data: { label, color },
  });
  reval();
}

export async function deletePriority(formData: FormData) {
  const { tenantId } = await requireTenantAdmin();
  const id = formData.get("id") as string;
  if (!id) return;

  const priority = await prisma.ticketPriority.findFirst({ where: { id, tenantId } });
  if (!priority) return;

  const total = await prisma.ticketPriority.count({ where: { tenantId } });
  if (total <= 1) return;
  const inUse = await prisma.ticket.count({ where: { tenantId, priority: priority.key } });
  if (inUse > 0) return;

  await prisma.ticketPriority.delete({ where: { id } });
  await prisma.slaTarget.deleteMany({ where: { tenantId, priority: priority.key } });
  reval();
}

export async function movePriority(formData: FormData) {
  const { tenantId } = await requireTenantAdmin();
  const id = formData.get("id") as string;
  const dir = formData.get("dir") as string;
  if (!id || (dir !== "up" && dir !== "down")) return;

  const list = await prisma.ticketPriority.findMany({
    where: { tenantId },
    orderBy: { order: "asc" },
  });
  const idx = list.findIndex((s) => s.id === id);
  if (idx === -1) return;
  const swapWith = dir === "up" ? idx - 1 : idx + 1;
  if (swapWith < 0 || swapWith >= list.length) return;

  const a = list[idx];
  const b = list[swapWith];
  await prisma.$transaction([
    prisma.ticketPriority.update({ where: { id: a.id }, data: { order: b.order } }),
    prisma.ticketPriority.update({ where: { id: b.id }, data: { order: a.order } }),
  ]);
  reval();
}
