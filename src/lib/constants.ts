// Central config for roles, statuses, priorities & categories.
// Keeps UI labels, colors and ordering consistent across the app.

export const ROLES = ["SUPER_ADMIN", "ADMIN", "AGENT", "CUSTOMER"] as const;
export type Role = (typeof ROLES)[number];

// Roles a tenant admin can assign to users within their own tenant.
export const TENANT_ROLES = ["ADMIN", "AGENT", "CUSTOMER"] as const;

// Per-ticket team membership roles.
export const WATCHER_ROLES = ["VIEWER", "COLLABORATOR"] as const;
export type WatcherRole = (typeof WATCHER_ROLES)[number];
export const WATCHER_ROLE_LABELS: Record<WatcherRole, string> = {
  VIEWER: "Viewer",
  COLLABORATOR: "Collaborator",
};
export const WATCHER_ROLE_HINTS: Record<WatcherRole, string> = {
  VIEWER: "Can view & is notified",
  COLLABORATOR: "Can view, manage & reply",
};
export function isWatcherRole(v: string): v is WatcherRole {
  return (WATCHER_ROLES as readonly string[]).includes(v);
}

// Statuses are now customizable per tenant (see TicketStatus). These are the
// defaults seeded for every new tenant and the base palette for custom ones.
export const DEFAULT_STATUSES = [
  { key: "OPEN", label: "Open", color: "#3b82f6", isClosed: false },
  { key: "PENDING", label: "Pending", color: "#f59e0b", isClosed: false },
  { key: "RESOLVED", label: "Resolved", color: "#10b981", isClosed: true },
  { key: "CLOSED", label: "Closed", color: "#94a3b8", isClosed: true },
] as const;

export type StatusDef = {
  key: string;
  label: string;
  color: string;
  isClosed: boolean;
};

/** Slugify a status label into a stable uppercase key. */
export function statusKeyFromLabel(label: string): string {
  return (
    label
      .toUpperCase()
      .trim()
      .replace(/[^A-Z0-9]+/g, "_")
      .replace(/^_+|_+$/g, "")
      .slice(0, 40) || "STATUS"
  );
}

export const STATUS_PALETTE = [
  "#3b82f6", "#f59e0b", "#10b981", "#8b5cf6",
  "#ef4444", "#14b8a6", "#ec4899", "#94a3b8",
];

// Priorities are customizable per tenant (see TicketPriority). These are the
// defaults seeded for every new tenant (order: low → high severity).
export const DEFAULT_PRIORITIES = [
  { key: "LOW", label: "Low", color: "#94a3b8", order: 0 },
  { key: "MEDIUM", label: "Medium", color: "#0ea5e9", order: 1 },
  { key: "HIGH", label: "High", color: "#f97316", order: 2 },
  { key: "URGENT", label: "Urgent", color: "#ef4444", order: 3 },
] as const;

export function priorityKeyFromLabel(label: string): string {
  return (
    label
      .toUpperCase()
      .trim()
      .replace(/[^A-Z0-9]+/g, "_")
      .replace(/^_+|_+$/g, "")
      .slice(0, 40) || "PRIORITY"
  );
}

export const ROLE_LABELS: Record<Role, string> = {
  SUPER_ADMIN: "Platform Admin",
  ADMIN: "Administrator",
  AGENT: "Agent",
  CUSTOMER: "Customer",
};

export const AVATAR_COLORS = [
  "#6366f1",
  "#ec4899",
  "#f59e0b",
  "#10b981",
  "#3b82f6",
  "#8b5cf6",
  "#ef4444",
  "#14b8a6",
];

export function isRole(v: string): v is Role {
  return (ROLES as readonly string[]).includes(v);
}
