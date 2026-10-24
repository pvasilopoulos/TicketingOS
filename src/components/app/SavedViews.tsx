"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useTransition } from "react";
import { Bookmark, BookmarkPlus, X } from "lucide-react";
import { createSavedView, deleteSavedView } from "@/lib/actions/views";

type View = { id: string; name: string; query: string };

export function SavedViews({ views }: { views: View[] }) {
  const router = useRouter();
  const params = useSearchParams();
  const [pending, start] = useTransition();

  // Current filters (excluding the list/board toggle).
  const current = new URLSearchParams(params.toString());
  current.delete("view");
  const currentQuery = current.toString();
  const hasFilters = currentQuery.length > 0;

  function save() {
    const name = window.prompt("Name this view");
    if (!name) return;
    const fd = new FormData();
    fd.set("name", name.trim());
    fd.set("query", currentQuery);
    start(() => createSavedView(fd));
  }
  function remove(id: string) {
    const fd = new FormData();
    fd.set("id", id);
    start(() => deleteSavedView(fd));
  }

  if (views.length === 0 && !hasFilters) return null;

  return (
    <div className="mb-3 flex flex-wrap items-center gap-2">
      <Bookmark size={15} className="text-muted" />
      {views.map((v) => (
        <span
          key={v.id}
          className="group inline-flex items-center gap-1 rounded-full border border-border bg-surface px-2.5 py-1 text-xs hover:border-brand/50"
        >
          <button
            onClick={() => router.push(`/tickets${v.query ? `?${v.query}` : ""}`)}
            className="font-medium cursor-pointer"
          >
            {v.name}
          </button>
          <button
            onClick={() => remove(v.id)}
            disabled={pending}
            aria-label={`Delete ${v.name}`}
            className="text-muted hover:text-red-600 cursor-pointer"
          >
            <X size={12} />
          </button>
        </span>
      ))}
      {hasFilters && (
        <button
          onClick={save}
          disabled={pending}
          className="inline-flex items-center gap-1 rounded-full border border-dashed border-border px-2.5 py-1 text-xs text-muted hover:text-fg hover:border-brand/50 cursor-pointer disabled:opacity-50"
        >
          <BookmarkPlus size={13} /> Save view
        </button>
      )}
    </div>
  );
}
