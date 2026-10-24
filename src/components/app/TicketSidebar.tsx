"use client";

import { useState, useTransition } from "react";
import { updateTicket } from "@/lib/actions/tickets";
import { Select, Label } from "@/components/ui/Field";
import { Loader2 } from "lucide-react";

type Person = { id: string; name: string };

export function TicketSidebar({
  ticketId,
  status,
  priority,
  assigneeId,
  categoryId,
  agents,
  categories,
  statuses,
  priorities,
}: {
  ticketId: string;
  status: string;
  priority: string;
  assigneeId: string | null;
  categoryId: string | null;
  agents: Person[];
  categories: { id: string; name: string }[];
  statuses: { key: string; label: string }[];
  priorities: { key: string; label: string }[];
}) {
  const [values, setValues] = useState({
    status,
    priority,
    assigneeId: assigneeId ?? "",
    categoryId: categoryId ?? "",
  });
  const [pending, start] = useTransition();

  function change(key: keyof typeof values, value: string) {
    const next = { ...values, [key]: value };
    setValues(next);
    const fd = new FormData();
    fd.set("status", next.status);
    fd.set("priority", next.priority);
    fd.set("assigneeId", next.assigneeId);
    fd.set("categoryId", next.categoryId);
    start(() => updateTicket(ticketId, fd));
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold">Properties</h3>
        {pending && <Loader2 size={15} className="animate-spin text-muted" />}
      </div>

      <div>
        <Label htmlFor="s-status">Status</Label>
        <Select id="s-status" value={values.status} onChange={(e) => change("status", e.target.value)}>
          {statuses.map((s) => (
            <option key={s.key} value={s.key}>{s.label}</option>
          ))}
        </Select>
      </div>

      <div>
        <Label htmlFor="s-priority">Priority</Label>
        <Select id="s-priority" value={values.priority} onChange={(e) => change("priority", e.target.value)}>
          {priorities.map((p) => (
            <option key={p.key} value={p.key}>{p.label}</option>
          ))}
        </Select>
      </div>

      <div>
        <Label htmlFor="s-assignee">Assignee</Label>
        <Select id="s-assignee" value={values.assigneeId} onChange={(e) => change("assigneeId", e.target.value)}>
          <option value="">Unassigned</option>
          {agents.map((a) => (
            <option key={a.id} value={a.id}>{a.name}</option>
          ))}
        </Select>
      </div>

      <div>
        <Label htmlFor="s-category">Category</Label>
        <Select id="s-category" value={values.categoryId} onChange={(e) => change("categoryId", e.target.value)}>
          <option value="">— None —</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>{c.name}</option>
          ))}
        </Select>
      </div>
    </div>
  );
}
