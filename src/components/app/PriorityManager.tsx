"use client";

import { useActionState, useState, useTransition } from "react";
import { useFormStatus } from "react-dom";
import { ChevronUp, ChevronDown, Trash2, Plus, Check, Flag } from "lucide-react";
import {
  createPriority,
  updatePriority,
  deletePriority,
  movePriority,
  type PriorityState,
} from "@/lib/actions/priorities";
import { Input, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";

type Item = { id: string; key: string; label: string; color: string; inUse: number };

function AddButton() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" variant="secondary" disabled={pending}>
      <Plus size={16} /> {pending ? "Adding…" : "Add priority"}
    </Button>
  );
}

export function PriorityManager({
  priorities,
  count,
}: {
  priorities: Item[];
  count: number;
}) {
  const [addState, addAction] = useActionState<PriorityState, FormData>(
    createPriority,
    undefined,
  );

  return (
    <Card>
      <CardHeader
        title={
          <span className="inline-flex items-center gap-2">
            <Flag size={16} /> Priorities
          </span>
        }
      />
      <p className="px-6 pt-4 text-sm text-muted">
        Severity levels for tickets, ordered low → high. The highest one drives
        the &quot;urgent&quot; stats. Set resolution targets per priority in SLA.
      </p>

      <div className="divide-y divide-border px-2 py-3">
        {priorities.map((p, i) => (
          <PriorityRow
            key={p.id}
            item={p}
            isFirst={i === 0}
            isLast={i === priorities.length - 1}
            canDelete={count > 1 && p.inUse === 0}
          />
        ))}
      </div>

      <form action={addAction} className="flex flex-wrap items-end gap-3 border-t border-border p-5">
        <div>
          <Label htmlFor="np-color">Color</Label>
          <input
            id="np-color"
            name="color"
            type="color"
            defaultValue="#6366f1"
            className="h-10 w-14 cursor-pointer rounded-lg border border-border bg-surface p-1"
          />
        </div>
        <div className="flex-1 min-w-40">
          <Label htmlFor="np-label">New priority</Label>
          <Input id="np-label" name="label" placeholder="e.g. Critical" required />
        </div>
        <AddButton />
      </form>
      {addState?.error && (
        <p className="px-5 pb-5 text-sm text-red-600 dark:text-red-400">{addState.error}</p>
      )}
    </Card>
  );
}

function PriorityRow({
  item,
  isFirst,
  isLast,
  canDelete,
}: {
  item: Item;
  isFirst: boolean;
  isLast: boolean;
  canDelete: boolean;
}) {
  const [label, setLabel] = useState(item.label);
  const [color, setColor] = useState(item.color);
  const [pending, start] = useTransition();
  const dirty = label !== item.label || color !== item.color;

  function save() {
    const fd = new FormData();
    fd.set("id", item.id);
    fd.set("label", label);
    fd.set("color", color);
    start(() => updatePriority(fd));
  }
  function move(dir: "up" | "down") {
    const fd = new FormData();
    fd.set("id", item.id);
    fd.set("dir", dir);
    start(() => movePriority(fd));
  }
  function remove() {
    const fd = new FormData();
    fd.set("id", item.id);
    start(() => deletePriority(fd));
  }

  return (
    <div className="flex flex-wrap items-center gap-2 px-3 py-2">
      <div className="flex flex-col">
        <button type="button" onClick={() => move("up")} disabled={isFirst || pending} className="text-muted hover:text-fg disabled:opacity-30 cursor-pointer" aria-label="Move up">
          <ChevronUp size={14} />
        </button>
        <button type="button" onClick={() => move("down")} disabled={isLast || pending} className="text-muted hover:text-fg disabled:opacity-30 cursor-pointer" aria-label="Move down">
          <ChevronDown size={14} />
        </button>
      </div>
      <input type="color" value={color} onChange={(e) => setColor(e.target.value)} className="h-9 w-10 cursor-pointer rounded-lg border border-border bg-surface p-1" />
      <input value={label} onChange={(e) => setLabel(e.target.value)} className="h-9 flex-1 min-w-32 rounded-lg border border-border bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40" />
      <Button size="sm" variant={dirty ? "primary" : "secondary"} disabled={!dirty || pending || label.trim().length < 2} onClick={save}>
        <Check size={14} /> Save
      </Button>
      <button
        type="button"
        onClick={remove}
        disabled={!canDelete || pending}
        title={canDelete ? "Delete priority" : item.inUse > 0 ? `In use by ${item.inUse} ticket(s)` : "Keep at least one priority"}
        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 transition-colors cursor-pointer disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-muted"
        aria-label="Delete priority"
      >
        <Trash2 size={15} />
      </button>
    </div>
  );
}
