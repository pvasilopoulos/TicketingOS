"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/db";
import { requireTenantAdmin } from "@/lib/auth";
import { statusKeyFromLabel } from "@/lib/constants";

export type StatusState = { error?: string; success?: string } | undefined;

function reval() {
  // Statuses affect the whole app surface.
  revalidatePath("/", "layout");
}

export async function createStatus(
  _prev: StatusState,
  formData: FormData,
): Promise<StatusState> {
  const { tenantId } = await requireTenantAdmin();

  const label = ((formData.get("label") as string) ?? "").trim();
  const color = ((formData.get("color") as string) || "#64748b").trim();
  const isClosed = formData.get("isClosed") === "on";
  if (label.length < 2) return { error: "Status name is too short" };

  // Unique key per tenant.
  const base = statusKeyFromLabel(label);
  let key = base;
  let n = 1;
  while (await prisma.ticketStatus.findFirst({ where: { tenantId, key } })) {
    key = `${base}_${n++}`;
  }

  const count = await prisma.ticketStatus.count({ where: { tenantId } });
  await prisma.ticketStatus.create({
    data: { tenantId, key, label, color, isClosed, order: count },
  });

  reval();
  return { success: `Added "${label}"` };
}

export async function updateStatus(formData: FormData) {
  const { tenantId } = await requireTenantAdmin();
  const id = formData.get("id") as string;
  const label = ((formData.get("label") as string) ?? "").trim();
  const color = ((formData.get("color") as string) || "#64748b").trim();
  const isClosed = formData.get("isClosed") === "on";
  if (!id || label.length < 2) return;

  await prisma.ticketStatus.updateMany({
    where: { id, tenantId },
    data: { label, color, isClosed },
  });
  reval();
}

export async function deleteStatus(formData: FormData) {
  const { tenantId } = await requireTenantAdmin();
  const id = formData.get("id") as string;
  if (!id) return;

  const status = await prisma.ticketStatus.findFirst({ where: { id, tenantId } });
  if (!status) return;

  // Keep at least one status, and don't orphan tickets.
  const total = await prisma.ticketStatus.count({ where: { tenantId } });
  if (total <= 1) return;
  const inUse = await prisma.ticket.count({
    where: { tenantId, status: status.key },
  });
  if (inUse > 0) return;

  await prisma.ticketStatus.delete({ where: { id } });
  reval();
}

export async function moveStatus(formData: FormData) {
  const { tenantId } = await requireTenantAdmin();
  const id = formData.get("id") as string;
  const dir = formData.get("dir") as string; // "up" | "down"
  if (!id || (dir !== "up" && dir !== "down")) return;

  const list = await prisma.ticketStatus.findMany({
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
    prisma.ticketStatus.update({ where: { id: a.id }, data: { order: b.order } }),
    prisma.ticketStatus.update({ where: { id: b.id }, data: { order: a.order } }),
  ]);
  reval();
}
