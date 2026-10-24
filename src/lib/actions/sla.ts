"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/db";
import { requireTenantAdmin } from "@/lib/auth";
import { getTenantPriorities } from "@/lib/priorities";

export type SlaState = { success?: string; error?: string } | undefined;

export async function saveSlaTargets(
  _prev: SlaState,
  formData: FormData,
): Promise<SlaState> {
  const { tenantId } = await requireTenantAdmin();

  const priorities = await getTenantPriorities(tenantId);
  for (const { key: p } of priorities) {
    const raw = Number(formData.get(`sla_${p}`));
    const hours = Number.isFinite(raw) && raw > 0 ? Math.round(raw) : 0;
    if (hours <= 0) continue;
    await prisma.slaTarget.upsert({
      where: { tenantId_priority: { tenantId, priority: p } },
      create: { tenantId, priority: p, resolveHours: hours },
      update: { resolveHours: hours },
    });
  }

  // Recompute deadlines for open tickets so changes take effect immediately.
  const targets = await prisma.slaTarget.findMany({ where: { tenantId } });
  const map = new Map(targets.map((t) => [t.priority, t.resolveHours]));
  const open = await prisma.ticket.findMany({
    where: { tenantId, closedAt: null },
    select: { id: true, createdAt: true, priority: true },
  });
  for (const t of open) {
    const hours = map.get(t.priority);
    if (!hours) continue;
    await prisma.ticket.update({
      where: { id: t.id },
      data: { dueAt: new Date(t.createdAt.getTime() + hours * 3600_000) },
    });
  }

  revalidatePath("/", "layout");
  return { success: "SLA targets saved" };
}
