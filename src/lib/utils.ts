import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";
import { formatDistanceToNow, format } from "date-fns";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

export function initials(name: string) {
  return name
    .split(" ")
    .map((p) => p[0])
    .filter(Boolean)
    .slice(0, 2)
    .join("")
    .toUpperCase();
}

export function timeAgo(date: Date | string) {
  return formatDistanceToNow(new Date(date), { addSuffix: true });
}

export function formatDate(date: Date | string) {
  return format(new Date(date), "MMM d, yyyy 'at' HH:mm");
}

/** SLA due-date display: label + tone, or null when not applicable. */
export function dueInfo(
  dueAt: string | Date | null | undefined,
  isClosed: boolean,
): { text: string; tone: "overdue" | "soon" | "ok" } | null {
  if (!dueAt || isClosed) return null;
  const due = new Date(dueAt);
  const diffMs = due.getTime() - Date.now();
  const rel = formatDistanceToNow(due);
  if (diffMs < 0) return { text: `Overdue by ${rel}`, tone: "overdue" };
  if (diffMs < 4 * 3600_000) return { text: `Due in ${rel}`, tone: "soon" };
  return { text: `Due in ${rel}`, tone: "ok" };
}

export function formatBytes(bytes: number) {
  if (bytes === 0) return "0 B";
  const k = 1024;
  const sizes = ["B", "KB", "MB", "GB"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
}
