import * as React from "react";
import { cn } from "@/lib/utils";

const base =
  "w-full rounded-lg border border-border bg-surface px-3 text-sm text-fg placeholder:text-muted " +
  "focus:outline-none focus:ring-2 focus:ring-brand/40 focus:border-brand transition disabled:opacity-50";

export const Input = React.forwardRef<
  HTMLInputElement,
  React.InputHTMLAttributes<HTMLInputElement>
>(({ className, ...props }, ref) => (
  <input ref={ref} className={cn(base, "h-10", className)} {...props} />
));
Input.displayName = "Input";

export const Textarea = React.forwardRef<
  HTMLTextAreaElement,
  React.TextareaHTMLAttributes<HTMLTextAreaElement>
>(({ className, ...props }, ref) => (
  <textarea ref={ref} className={cn(base, "py-2 min-h-24", className)} {...props} />
));
Textarea.displayName = "Textarea";

export const Select = React.forwardRef<
  HTMLSelectElement,
  React.SelectHTMLAttributes<HTMLSelectElement>
>(({ className, children, ...props }, ref) => (
  <select ref={ref} className={cn(base, "h-10 cursor-pointer", className)} {...props}>
    {children}
  </select>
));
Select.displayName = "Select";

export function Label({
  children,
  className,
  ...props
}: React.LabelHTMLAttributes<HTMLLabelElement>) {
  return (
    <label className={cn("block text-sm font-medium text-fg mb-1.5", className)} {...props}>
      {children}
    </label>
  );
}
