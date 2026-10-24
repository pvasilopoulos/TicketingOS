// Client-safe notification constants & types (no server-only imports).

export const NOTIFY_CATEGORIES = [
  "new_ticket",
  "reply",
  "mention",
  "assigned",
  "status",
  "team",
] as const;
export type Category = (typeof NOTIFY_CATEGORIES)[number];

export const CATEGORY_LABELS: Record<Category, string> = {
  new_ticket: "New ticket created",
  reply: "New reply",
  mention: "You're @mentioned",
  assigned: "Assigned to you",
  status: "Status changed",
  team: "Added to a ticket",
};

export type Channels = { inApp: boolean; push: boolean; email: boolean };
export type UserPrefs = {
  channels: Record<Category, Channels>;
  quietStart: number | null;
  quietEnd: number | null;
  priorityThreshold: string;
  scope: "all" | "assigned" | "watching";
};

export function defaultPrefs(): UserPrefs {
  const channels = {} as Record<Category, Channels>;
  for (const c of NOTIFY_CATEGORIES) channels[c] = { inApp: true, push: true, email: true };
  return { channels, quietStart: null, quietEnd: null, priorityThreshold: "", scope: "all" };
}
