"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { MessageSquare, Loader2 } from "lucide-react";
import { updateTicket } from "@/lib/actions/tickets";
import { Avatar } from "@/components/ui/Avatar";
import { PriorityBadge, DueBadge } from "@/components/ui/Badge";
import { cn, timeAgo } from "@/lib/utils";
import type { TicketRow } from "./TicketListItem";

type StatusCol = { key: string; label: string; color: string; isClosed: boolean };

export function TicketBoard({
  tickets,
  statuses,
  canManage,
}: {
  tickets: TicketRow[];
  statuses: StatusCol[];
  canManage: boolean;
}) {
  const router = useRouter();
  const [cards, setCards] = useState<TicketRow[]>(tickets);
  const [dragId, setDragId] = useState<string | null>(null);
  const [overCol, setOverCol] = useState<string | null>(null);
  const [pending, start] = useTransition();

  function move(id: string, status: StatusCol) {
    const card = cards.find((c) => c.id === id);
    if (!card || card.status.key === status.key) return;
    // Optimistic update.
    setCards((cs) =>
      cs.map((c) =>
        c.id === id
          ? {
              ...c,
              status: {
                key: status.key,
                label: status.label,
                color: status.color,
                isClosed: status.isClosed,
              },
            }
          : c,
      ),
    );
    const fd = new FormData();
    fd.set("status", status.key);
    start(async () => {
      await updateTicket(id, fd);
      router.refresh();
    });
  }

  return (
    <div className="flex gap-4 overflow-x-auto pb-2">
      {statuses.map((status) => {
        const colCards = cards.filter((c) => c.status.key === status.key);
        return (
          <div
            key={status.key}
            onDragOver={(e) => {
              if (!canManage || !dragId) return;
              e.preventDefault();
              setOverCol(status.key);
            }}
            onDragLeave={() => setOverCol((s) => (s === status.key ? null : s))}
            onDrop={() => {
              if (dragId) move(dragId, status);
              setDragId(null);
              setOverCol(null);
            }}
            className={cn(
              "flex w-72 shrink-0 flex-col rounded-xl border bg-surface-2/50 transition-colors",
              overCol === status.key ? "border-brand bg-brand/5" : "border-border",
            )}
          >
            <div className="flex items-center justify-between px-3 py-2.5">
              <span className="inline-flex items-center gap-2 text-sm font-semibold">
                <span
                  className="h-2 w-2 rounded-full"
                  style={{ backgroundColor: status.color }}
                />
                {status.label}
              </span>
              <span className="rounded-full bg-surface px-2 py-0.5 text-xs text-muted">
                {colCards.length}
              </span>
            </div>

            <div className="flex flex-1 flex-col gap-2 px-2 pb-2">
              {colCards.map((c) => (
                <button
                  key={c.id}
                  draggable={canManage}
                  onDragStart={() => setDragId(c.id)}
                  onDragEnd={() => {
                    setDragId(null);
                    setOverCol(null);
                  }}
                  onClick={() => router.push(`/tickets/${c.id}`)}
                  className={cn(
                    "group rounded-lg border border-border bg-surface p-3 text-left transition-shadow hover:shadow-sm",
                    canManage && "cursor-grab active:cursor-grabbing",
                    dragId === c.id && "opacity-50",
                  )}
                >
                  <div className="flex items-start justify-between gap-2">
                    <span className="line-clamp-2 text-sm font-medium">{c.subject}</span>
                    <PriorityBadge label={c.priority.label} color={c.priority.color} />
                  </div>
                  {(() => {
                    const d = c.dueAt;
                    return d ? (
                      <div className="mt-1.5">
                        <DueBadge dueAt={d} isClosed={c.status.isClosed} />
                      </div>
                    ) : null;
                  })()}
                  <div className="mt-2 flex items-center gap-2 text-xs text-muted">
                    <span className="font-mono">#{c.number}</span>
                    {c.category && (
                      <span className="inline-flex items-center gap-1">
                        <span
                          className="h-2 w-2 rounded-full"
                          style={{ backgroundColor: c.category.color }}
                        />
                        {c.category.name}
                      </span>
                    )}
                    {c.commentCount > 0 && (
                      <span className="inline-flex items-center gap-0.5">
                        <MessageSquare size={11} /> {c.commentCount}
                      </span>
                    )}
                    <span className="ml-auto">{timeAgo(c.createdAt)}</span>
                  </div>
                  <div className="mt-2 flex items-center gap-2">
                    <Avatar name={c.requester.name} color={c.requester.color} size={22} />
                    <span className="truncate text-xs text-muted">{c.requester.name}</span>
                    {c.assignee && (
                      <Avatar
                        name={c.assignee.name}
                        color={c.assignee.color}
                        size={22}
                        className="ml-auto"
                      />
                    )}
                  </div>
                </button>
              ))}

              {colCards.length === 0 && (
                <div className="rounded-lg border border-dashed border-border py-6 text-center text-xs text-muted">
                  {canManage ? "Drop here" : "—"}
                </div>
              )}
            </div>
          </div>
        );
      })}

      {pending && (
        <span className="pointer-events-none fixed bottom-4 right-4 inline-flex items-center gap-1.5 rounded-lg bg-surface px-3 py-1.5 text-xs text-muted shadow">
          <Loader2 size={13} className="animate-spin" /> Saving…
        </span>
      )}
    </div>
  );
}
