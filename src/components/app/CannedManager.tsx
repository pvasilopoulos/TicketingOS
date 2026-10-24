"use client";

import { useActionState, useEffect, useState, useTransition } from "react";
import { useFormStatus } from "react-dom";
import { MessageSquarePlus, Trash2, Pencil, X } from "lucide-react";
import {
  createCanned,
  updateCanned,
  deleteCanned,
  type CannedState,
} from "@/lib/actions/canned";
import { Input, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { RichTextEditor } from "@/components/ui/RichTextEditor";

type Canned = { id: string; title: string; body: string };

function SaveBtn({ label }: { label: string }) {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" disabled={pending}>
      {pending ? "Saving…" : label}
    </Button>
  );
}

export function CannedManager({ items }: { items: Canned[] }) {
  const [state, action] = useActionState<CannedState, FormData>(createCanned, undefined);
  const [formKey, setFormKey] = useState(0);

  useEffect(() => {
    if (state?.success) setFormKey((k) => k + 1);
  }, [state]);

  return (
    <Card>
      <CardHeader
        title={
          <span className="inline-flex items-center gap-2">
            <MessageSquarePlus size={16} /> Canned responses
          </span>
        }
      />
      <p className="px-6 pt-4 text-sm text-muted">
        Reusable reply templates. Agents can insert them in the ticket reply box.
      </p>

      <div className="divide-y divide-border px-2 py-3">
        {items.length === 0 && (
          <p className="px-3 py-6 text-center text-sm text-muted">No canned responses yet.</p>
        )}
        {items.map((c) => (
          <CannedRow key={c.id} item={c} />
        ))}
      </div>

      <form action={action} key={formKey} className="space-y-3 border-t border-border p-5">
        {state?.error && (
          <p className="text-sm text-red-600 dark:text-red-400">{state.error}</p>
        )}
        <div>
          <Label htmlFor="c-title">New template title</Label>
          <Input id="c-title" name="title" placeholder="e.g. Asking for more details" required />
        </div>
        <div>
          <Label>Body</Label>
          <RichTextEditor name="body" minHeight={100} placeholder="Template text…" />
        </div>
        <SaveBtn label="Add template" />
      </form>
    </Card>
  );
}

function CannedRow({ item }: { item: Canned }) {
  const [editing, setEditing] = useState(false);
  const [state, action] = useActionState<CannedState, FormData>(updateCanned, undefined);
  const [, start] = useTransition();

  useEffect(() => {
    if (state?.success) setEditing(false);
  }, [state]);

  function remove() {
    const fd = new FormData();
    fd.set("id", item.id);
    start(() => deleteCanned(fd));
  }

  if (editing) {
    return (
      <form action={action} className="space-y-3 px-3 py-3">
        {state?.error && <p className="text-sm text-red-600 dark:text-red-400">{state.error}</p>}
        <input type="hidden" name="id" value={item.id} />
        <Input name="title" defaultValue={item.title} required />
        <RichTextEditor name="body" minHeight={100} defaultHTML={item.body} />
        <div className="flex gap-2">
          <SaveBtn label="Save" />
          <Button type="button" variant="secondary" onClick={() => setEditing(false)}>
            <X size={15} /> Cancel
          </Button>
        </div>
      </form>
    );
  }

  return (
    <div className="flex items-start gap-3 px-3 py-3">
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium">{item.title}</p>
        <div
          className="rich mt-1 line-clamp-2 text-xs text-muted"
          dangerouslySetInnerHTML={{ __html: item.body }}
        />
      </div>
      <button
        onClick={() => setEditing(true)}
        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-surface-2 hover:text-fg transition-colors cursor-pointer"
        aria-label="Edit"
      >
        <Pencil size={15} />
      </button>
      <button
        onClick={remove}
        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 transition-colors cursor-pointer"
        aria-label="Delete"
      >
        <Trash2 size={15} />
      </button>
    </div>
  );
}
