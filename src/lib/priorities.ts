import "server-only";
import { prisma } from "./db";

export type TenantPriority = {
  id: string;
  key: string;
  label: string;
  color: string;
  order: number;
};

/** Ordered list (low → high severity) of a tenant's priorities. */
export async function getTenantPriorities(tenantId: string): Promise<TenantPriority[]> {
  return prisma.ticketPriority.findMany({
    where: { tenantId },
    orderBy: { order: "asc" },
    select: { id: true, key: true, label: true, color: true, order: true },
  });
}

export function resolvePriority(priorities: TenantPriority[], key: string) {
  return (
    priorities.find((p) => p.key === key) ?? {
      id: key,
      key,
      label: key,
      color: "#94a3b8",
      order: 0,
    }
  );
}

/** Default priority for new tickets: a mid-severity one, or the first. */
export function defaultPriorityKey(priorities: TenantPriority[]): string {
  if (priorities.length === 0) return "MEDIUM";
  const mid = priorities[Math.floor((priorities.length - 1) / 2)];
  return mid.key;
}

/** Keys of the single highest-severity priority (for "urgent" stats). */
export function topPriorityKeys(priorities: TenantPriority[]): string[] {
  if (priorities.length === 0) return [];
  const max = Math.max(...priorities.map((p) => p.order));
  return priorities.filter((p) => p.order === max).map((p) => p.key);
}
