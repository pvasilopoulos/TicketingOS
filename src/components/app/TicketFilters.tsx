"use client";

import { useRouter, useSearchParams, usePathname } from "next/navigation";
import { Search, X, Clock } from "lucide-react";

export function TicketFilters({
  categories,
  statuses,
  priorities,
  tags,
}: {
  categories: { id: string; name: string }[];
  statuses: { key: string; label: string }[];
  priorities: { key: string; label: string }[];
  tags: { id: string; name: string }[];
}) {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();

  function setParam(key: string, value: string) {
    const next = new URLSearchParams(params.toString());
    if (value) next.set(key, value);
    else next.delete(key);
    router.push(`${pathname}?${next.toString()}`);
  }

  const q = params.get("q") ?? "";
  const status = params.get("status") ?? "";
  const priority = params.get("priority") ?? "";
  const category = params.get("category") ?? "";
  const tag = params.get("tag") ?? "";
  const overdue = params.get("overdue") === "1";
  const hasFilters = q || status || priority || category || tag || overdue;

  const selectCls =
    "h-9 rounded-lg border border-border bg-surface px-2.5 text-sm text-fg focus:outline-none focus:ring-2 focus:ring-brand/40 cursor-pointer";

  return (
    <div className="flex flex-wrap items-center gap-2">
      <form
        className="relative flex-1 min-w-48"
        onSubmit={(e) => {
          e.preventDefault();
          setParam("q", new FormData(e.currentTarget).get("q") as string);
        }}
      >
        <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
        <input
          name="q"
          defaultValue={q}
          key={q}
          placeholder="Search by subject or #number…"
          className="h-9 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-brand/40"
        />
      </form>

      <select className={selectCls} value={status} onChange={(e) => setParam("status", e.target.value)}>
        <option value="">All statuses</option>
        {statuses.map((s) => (
          <option key={s.key} value={s.key}>{s.label}</option>
        ))}
      </select>

      <select className={selectCls} value={priority} onChange={(e) => setParam("priority", e.target.value)}>
        <option value="">All priorities</option>
        {priorities.map((p) => (
          <option key={p.key} value={p.key}>{p.label}</option>
        ))}
      </select>

      <select className={selectCls} value={category} onChange={(e) => setParam("category", e.target.value)}>
        <option value="">All categories</option>
        {categories.map((c) => (
          <option key={c.id} value={c.id}>{c.name}</option>
        ))}
      </select>

      {tags.length > 0 && (
        <select className={selectCls} value={tag} onChange={(e) => setParam("tag", e.target.value)}>
          <option value="">All tags</option>
          {tags.map((t) => (
            <option key={t.id} value={t.id}>#{t.name}</option>
          ))}
        </select>
      )}

      <button
        onClick={() => setParam("overdue", overdue ? "" : "1")}
        className={
          "inline-flex h-9 items-center gap-1.5 rounded-lg border px-2.5 text-sm transition-colors cursor-pointer " +
          (overdue
            ? "border-red-300 dark:border-red-500/40 bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-300"
            : "border-border bg-surface text-muted hover:text-fg")
        }
      >
        <Clock size={14} /> Overdue
      </button>

      {hasFilters && (
        <button
          onClick={() => router.push(pathname)}
          className="inline-flex h-9 items-center gap-1 rounded-lg px-2.5 text-sm text-muted hover:bg-surface-2 hover:text-fg cursor-pointer"
        >
          <X size={14} /> Clear
        </button>
      )}
    </div>
  );
}
