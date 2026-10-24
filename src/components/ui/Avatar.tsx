import { cn, initials } from "@/lib/utils";

export function Avatar({
  name,
  color = "#6366f1",
  size = 36,
  className,
}: {
  name: string;
  color?: string;
  size?: number;
  className?: string;
}) {
  return (
    <span
      className={cn(
        "inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white select-none",
        className,
      )}
      style={{
        backgroundColor: color,
        width: size,
        height: size,
        fontSize: size * 0.38,
      }}
      title={name}
    >
      {initials(name)}
    </span>
  );
}
