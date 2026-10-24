import { cn } from "@/lib/utils";

export function Card({
  className,
  children,
}: {
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div
      className={cn(
        "rounded-xl border border-border bg-surface",
        className,
      )}
    >
      {children}
    </div>
  );
}

export function CardHeader({
  title,
  action,
  className,
}: {
  title: React.ReactNode;
  action?: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "flex items-center justify-between border-b border-border px-5 py-3.5",
        className,
      )}
    >
      <h3 className="text-sm font-semibold">{title}</h3>
      {action}
    </div>
  );
}
