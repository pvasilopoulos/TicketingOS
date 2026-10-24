import "server-only";
import { prisma } from "./db";

// Default resolution targets (hours) per priority.
export const DEFAULT_SLA: Record<string, number> = {
  URGENT: 4,
  HIGH: 24,
  MEDIUM: 72,
  LOW: 168,
};

/** A tenant's SLA targets as a priority → hours map (falls back to defaults). */
export async function getTenantSla(tenantId: string): Promise<Record<string, number>> {
  const rows = await prisma.slaTarget.findMany({ where: { tenantId } });
  const map: Record<string, number> = { ...DEFAULT_SLA };
  for (const r of rows) map[r.priority] = r.resolveHours;
  return map;
}

/** Deadline for a ticket given its creation time + priority. */
export function computeDueAt(
  createdAt: Date,
  priority: string,
  sla: Record<string, number>,
): Date | null {
  const hours = sla[priority];
  if (!hours || hours <= 0) return null;
  return new Date(createdAt.getTime() + hours * 3600_000);
}
