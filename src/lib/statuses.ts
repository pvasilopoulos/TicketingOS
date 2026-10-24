import "server-only";
import { prisma } from "./db";

export type TenantStatus = {
  id: string;
  key: string;
  label: string;
  color: string;
  isClosed: boolean;
  order: number;
};

/** Ordered list of a tenant's ticket statuses. */
export async function getTenantStatuses(tenantId: string): Promise<TenantStatus[]> {
  return prisma.ticketStatus.findMany({
    where: { tenantId },
    orderBy: { order: "asc" },
    select: {
      id: true,
      key: true,
      label: true,
      color: true,
      isClosed: true,
      order: true,
    },
  });
}

/** Resolve a status key → its definition, with a safe fallback. */
export function resolveStatus(statuses: TenantStatus[], key: string) {
  return (
    statuses.find((s) => s.key === key) ?? {
      id: key,
      key,
      label: key,
      color: "#94a3b8",
      isClosed: false,
      order: 999,
    }
  );
}

/** The status new tickets start in (lowest order), or OPEN as a fallback. */
export function initialStatusKey(statuses: TenantStatus[]): string {
  return statuses[0]?.key ?? "OPEN";
}
