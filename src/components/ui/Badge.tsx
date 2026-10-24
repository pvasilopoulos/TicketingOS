import { Clock } from "lucide-react";
import { cn, dueInfo } from "@/lib/utils";

export function Badge({
  children,
  className,
  style,
}: {
  children: React.ReactNode;
  className?: string;
  style?: React.CSSProperties;
}) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium",
        className,
      )}
      style={style}
    >
      {children}
    </span>
  );
}

// Statuses are per-tenant, so the label/color come from the resolved status.
export function StatusBadge({ label, color }: { label: string; color: string }) {
  return (
    <Badge
      style={{ backgroundColor: `${color}22`, color }}
      className="dark:brightness-125"
    >
      <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: color }} />
      {label}
    </Badge>
  );
}

export function PriorityBadge({ label, color }: { label: string; color: string }) {
  return (
    <Badge
      style={{ backgroundColor: `${color}22`, color }}
      className="dark:brightness-125"
    >
      {label}
    </Badge>
  );
}

const DUE_TONES = {
  overdue: "bg-red-100 dark:bg-red-500/15 text-red-700 dark:text-red-300",
  soon: "bg-amber-100 dark:bg-amber-500/15 text-amber-700 dark:text-amber-300",
  ok: "bg-slate-100 dark:bg-slate-500/15 text-slate-600 dark:text-slate-400",
};

export function DueBadge({
  dueAt,
  isClosed,
}: {
  dueAt: string | Date | null | undefined;
  isClosed: boolean;
}) {
  const info = dueInfo(dueAt, isClosed);
  if (!info) return null;
  return (
    <Badge className={DUE_TONES[info.tone]}>
      <Clock size={11} />
      {info.text}
    </Badge>
  );
}
