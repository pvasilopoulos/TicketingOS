"use client";

import { useState, useTransition } from "react";
import { Plus, X, Hash } from "lucide-react";
import { Dropdown } from "@/components/ui/Dropdown";
import { addTicketTag, removeTicketTag } from "@/lib/actions/tags";

type Tag = { id: string; name: string };

export function TicketTags({
  ticketId,
  tags,
  allTags,
  canManage,
}: {
  ticketId: string;
  tags: Tag[];
  allTags: Tag[];
  canManage: boolean;
}) {
  const [pending, start] = useTransition();
  const [newName, setNewName] = useState("");

  const assigned = new Set(tags.map((t) => t.id));
  const available = allTags.filter((t) => !assigned.has(t.id));

  function add(tagId: string) {
    const fd = new FormData();
    fd.set("tagId", tagId);
    start(() => addTicketTag(ticketId, fd));
  }
  function create(name: string) {
    const n = name.trim();
    if (!n) return;
    const fd = new FormData();
    fd.set("name", n);
    setNewName("");
    start(() => addTicketTag(ticketId, fd));
  }
  function remove(tagId: string) {
    const fd = new FormData();
    fd.set("tagId", tagId);
    start(() => removeTicketTag(ticketId, fd));
  }

  return (
    <div>
      <div className="mb-3 flex items-center justify-between">
        <h3 className="text-sm font-semibold">Tags</h3>
        {canManage && (
          <Dropdown
            width={240}
            trigger={({ toggle }) => (
              <button
                onClick={toggle}
                disabled={pending}
                className="inline-flex items-center gap-1 text-xs font-medium text-brand hover:underline cursor-pointer disabled:opacity-50"
              >
                <Plus size={14} /> Add
              </button>
            )}
          >
            {(close) => (
              <div className="py-1">
                <div className="p-2">
                  <input
                    value={newName}
                    onChange={(e) => setNewName(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter") {
                        create(newName);
                        close();
                      }
                    }}
                    placeholder="New tag + Enter"
                    className="h-8 w-full rounded-md border border-border bg-surface px-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40"
                  />
                </div>
                <div className="max-h-56 overflow-y-auto border-t border-border">
                  {available.length === 0 ? (
                    <p className="px-3 py-3 text-center text-xs text-muted">No more tags</p>
                  ) : (
                    available.map((t) => (
                      <button
                        key={t.id}
                        onClick={() => {
                          add(t.id);
                          close();
                        }}
                        className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-surface-2 transition-colors cursor-pointer"
                      >
                        <Hash size={13} className="text-muted" />
                        {t.name}
                      </button>
                    ))
                  )}
                </div>
              </div>
            )}
          </Dropdown>
        )}
      </div>

      {tags.length === 0 ? (
        <p className="text-sm text-muted">No tags.</p>
      ) : (
        <div className="flex flex-wrap gap-1.5">
          {tags.map((t) => (
            <span
              key={t.id}
              className="inline-flex items-center gap-1 rounded-full bg-surface-2 px-2.5 py-0.5 text-xs text-muted"
            >
              #{t.name}
              {canManage && (
                <button
                  onClick={() => remove(t.id)}
                  disabled={pending}
                  aria-label={`Remove ${t.name}`}
                  className="text-muted hover:text-red-600 cursor-pointer"
                >
                  <X size={12} />
                </button>
              )}
            </span>
          ))}
        </div>
      )}
    </div>
  );
}
