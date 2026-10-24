"use client";

import { useActionState, useState, useTransition } from "react";
import { useFormStatus } from "react-dom";
import { ChevronUp, ChevronDown, Trash2, Plus, Check } from "lucide-react";
import {
  createStatus,
  updateStatus,
  deleteStatus,
  moveStatus,
  type StatusState,
} from "@/lib/actions/statuses";
import { Input, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";

type StatusItem = {
  id: string;
  key: string;
  label: string;
  color: string;
  isClosed: boolean;
  inUse: number;
};

function AddButton() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" variant="secondary" disabled={pending}>
      <Plus size={16} /> {pending ? "Adding…" : "Add status"}
    </Button>
  );
}

export function StatusManager({
  statuses,
  count,
}: {
  statuses: StatusItem[];
  count: number;
}) {
  const [addState, addAction] = useActionState<StatusState, FormData>(
    createStatus,
    undefined,
  );

  return (
    <Card>
      <CardHeader title="Ticket statuses" />
      <p className="px-6 pt-4 text-sm text-muted">
        Customize the stages a ticket moves through. Statuses marked{" "}
        <strong>Closed</strong> count as resolved — they set the resolved date and
        a customer reply reopens the ticket. Order controls the board columns.
      </p>

      <div className="divide-y divide-border px-2 py-3">
        {statuses.map((s, i) => (
          <StatusRow
            key={s.id}
            status={s}
            isFirst={i === 0}
            isLast={i === statuses.length - 1}
            canDelete={count > 1 && s.inUse === 0}
          />
        ))}
      </div>

      <form action={addAction} className="flex flex-wrap items-end gap-3 border-t border-border p-5">
        <div>
          <Label htmlFor="new-color">Color</Label>
          <input
            id="new-color"
            name="color"
            type="color"
            defaultValue="#6366f1"
            className="h-10 w-14 cursor-pointer rounded-lg border border-border bg-surface p-1"
          />
        </div>
        <div className="flex-1 min-w-40">
          <Label htmlFor="new-label">New status</Label>
          <Input id="new-label" name="label" placeholder="e.g. In Progress" required />
        </div>
        <label className="flex items-center gap-2 pb-2.5 text-sm text-muted cursor-pointer select-none">
          <input type="checkbox" name="isClosed" className="h-4 w-4 rounded border-border accent-brand" />
          Closed
        </label>
        <AddButton />
      </form>
      {addState?.error && (
        <p className="px-5 pb-5 text-sm text-red-600 dark:text-red-400">{addState.error}</p>
      )}
    </Card>
  );
}

function StatusRow({
  status,
  isFirst,
  isLast,
  canDelete,
}: {
  status: StatusItem;
  isFirst: boolean;
  isLast: boolean;
  canDelete: boolean;
}) {
  const [label, setLabel] = useState(status.label);
  const [color, setColor] = useState(status.color);
  const [isClosed, setIsClosed] = useState(status.isClosed);
  const [pending, start] = useTransition();

  const dirty =
    label !== status.label || color !== status.color || isClosed !== status.isClosed;

  function save() {
    const fd = new FormData();
    fd.set("id", status.id);
    fd.set("label", label);
    fd.set("color", color);
    if (isClosed) fd.set("isClosed", "on");
    start(() => updateStatus(fd));
  }
  function move(dir: "up" | "down") {
    const fd = new FormData();
    fd.set("id", status.id);
    fd.set("dir", dir);
    start(() => moveStatus(fd));
  }
  function remove() {
    const fd = new FormData();
    fd.set("id", status.id);
    start(() => deleteStatus(fd));
  }

  return (
    <div className="flex flex-wrap items-center gap-2 px-3 py-2">
      <div className="flex flex-col">
        <button
          type="button"
          onClick={() => move("up")}
          disabled={isFirst || pending}
          className="text-muted hover:text-fg disabled:opacity-30 cursor-pointer"
          aria-label="Move up"
        >
          <ChevronUp size={14} />
        </button>
        <button
          type="button"
          onClick={() => move("down")}
          disabled={isLast || pending}
          className="text-muted hover:text-fg disabled:opacity-30 cursor-pointer"
          aria-label="Move down"
        >
          <ChevronDown size={14} />
        </button>
      </div>

      <input
        type="color"
        value={color}
        onChange={(e) => setColor(e.target.value)}
        className="h-9 w-10 cursor-pointer rounded-lg border border-border bg-surface p-1"
      />
      <input
        value={label}
        onChange={(e) => setLabel(e.target.value)}
        className="h-9 flex-1 min-w-32 rounded-lg border border-border bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40"
      />

      <label className="flex items-center gap-2 text-sm text-muted cursor-pointer select-none">
        <input
          type="checkbox"
          checked={isClosed}
          onChange={(e) => setIsClosed(e.target.checked)}
          className="h-4 w-4 rounded border-border accent-brand"
        />
        Closed
      </label>

      <Button
        size="sm"
        variant={dirty ? "primary" : "secondary"}
        disabled={!dirty || pending || label.trim().length < 2}
        onClick={save}
      >
        <Check size={14} /> Save
      </Button>

      <button
        type="button"
        onClick={remove}
        disabled={!canDelete || pending}
        title={
          canDelete
            ? "Delete status"
            : status.inUse > 0
              ? `In use by ${status.inUse} ticket(s)`
              : "Keep at least one status"
        }
        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 transition-colors cursor-pointer disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-muted"
        aria-label="Delete status"
      >
        <Trash2 size={15} />
      </button>
    </div>
  );
}
