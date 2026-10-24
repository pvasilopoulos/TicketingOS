"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { Loader2, X } from "lucide-react";
import { bulkUpdateTickets } from "@/lib/actions/tickets";
import { TicketListItem, type TicketRow } from "./TicketListItem";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";

const NC = "__nochange__";
const UNASSIGN = "__unassign__";

export function BulkList({
  rows,
  statuses,
  priorities,
  agents,
}: {
  rows: TicketRow[];
  statuses: { key: string; label: string }[];
  priorities: { key: string; label: string }[];
  agents: { id: string; name: string }[];
}) {
  const router = useRouter();
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [status, setStatus] = useState(NC);
  const [priority, setPriority] = useState(NC);
  const [assignee, setAssignee] = useState(NC);
  const [pending, start] = useTransition();

  const allSelected = rows.length > 0 && selected.size === rows.length;

  function toggle(id: string) {
    setSelected((s) => {
      const next = new Set(s);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }
  function toggleAll() {
    setSelected(allSelected ? new Set() : new Set(rows.map((r) => r.id)));
  }
  function clear() {
    setSelected(new Set());
    setStatus(NC);
    setPriority(NC);
    setAssignee(NC);
  }

  function apply() {
    const changes: { status?: string; priority?: string; assigneeId?: string | null } = {};
    if (status !== NC) changes.status = status;
    if (priority !== NC) changes.priority = priority;
    if (assignee !== NC) changes.assigneeId = assignee === UNASSIGN ? null : assignee;
    if (Object.keys(changes).length === 0) return;
    const ids = [...selected];
    start(async () => {
      await bulkUpdateTickets(ids, changes);
      clear();
      router.refresh();
    });
  }

  const selectCls =
    "h-9 rounded-lg border border-border bg-surface px-2 text-sm text-fg focus:outline-none focus:ring-2 focus:ring-brand/40 cursor-pointer";

  return (
    <div>
      {selected.size > 0 && (
        <div className="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-brand/40 bg-brand/5 px-3 py-2">
          <span className="text-sm font-medium">{selected.size} selected</span>
          <span className="mx-1 h-5 w-px bg-border" />
          <select className={selectCls} value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value={NC}>Status…</option>
            {statuses.map((s) => (
              <option key={s.key} value={s.key}>{s.label}</option>
            ))}
          </select>
          <select className={selectCls} value={priority} onChange={(e) => setPriority(e.target.value)}>
            <option value={NC}>Priority…</option>
            {priorities.map((p) => (
              <option key={p.key} value={p.key}>{p.label}</option>
            ))}
          </select>
          <select className={selectCls} value={assignee} onChange={(e) => setAssignee(e.target.value)}>
            <option value={NC}>Assignee…</option>
            <option value={UNASSIGN}>Unassigned</option>
            {agents.map((a) => (
              <option key={a.id} value={a.id}>{a.name}</option>
            ))}
          </select>
          <Button size="sm" onClick={apply} disabled={pending}>
            {pending ? <Loader2 size={14} className="animate-spin" /> : null} Apply
          </Button>
          <button
            onClick={clear}
            className="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-sm text-muted hover:bg-surface-2 hover:text-fg cursor-pointer"
          >
            <X size={14} /> Clear
          </button>
        </div>
      )}

      <Card>
        <label className="flex cursor-pointer items-center gap-3 border-b border-border px-5 py-2.5 text-sm text-muted">
          <input
            type="checkbox"
            checked={allSelected}
            onChange={toggleAll}
            className="h-4 w-4 rounded border-border accent-brand"
          />
          Select all
        </label>
        <div className="divide-y divide-border">
          {rows.map((r) => (
            <div key={r.id} className="flex items-center">
              <label className="flex cursor-pointer items-center pl-5">
                <input
                  type="checkbox"
                  checked={selected.has(r.id)}
                  onChange={() => toggle(r.id)}
                  className="h-4 w-4 rounded border-border accent-brand"
                />
              </label>
              <div className="min-w-0 flex-1">
                <TicketListItem ticket={r} />
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
