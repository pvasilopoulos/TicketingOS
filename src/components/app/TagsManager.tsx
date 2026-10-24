"use client";

import { useActionState, useState, useTransition } from "react";
import { useFormStatus } from "react-dom";
import { Plus, Trash2, Check, Hash } from "lucide-react";
import { createTag, renameTag, deleteTag, type TagState } from "@/lib/actions/tags";
import { Input } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";

type TagItem = { id: string; name: string; count: number };

function AddButton() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" variant="secondary" disabled={pending}>
      <Plus size={16} /> {pending ? "Adding…" : "Add tag"}
    </Button>
  );
}

export function TagsManager({ tags }: { tags: TagItem[] }) {
  const [addState, addAction] = useActionState<TagState, FormData>(createTag, undefined);

  return (
    <Card>
      <CardHeader
        title={
          <span className="inline-flex items-center gap-2">
            <Hash size={16} /> Tags
          </span>
        }
      />
      <div className="divide-y divide-border px-2 py-2">
        {tags.length === 0 && (
          <p className="px-3 py-6 text-center text-sm text-muted">No tags yet.</p>
        )}
        {tags.map((t) => (
          <TagRow key={t.id} tag={t} />
        ))}
      </div>

      <form action={addAction} className="flex flex-wrap items-end gap-3 border-t border-border p-5">
        <div className="flex-1 min-w-40">
          <Input name="name" placeholder="e.g. regression" required />
        </div>
        <AddButton />
      </form>
      {addState?.error && (
        <p className="px-5 pb-5 text-sm text-red-600 dark:text-red-400">{addState.error}</p>
      )}
      {addState?.success && (
        <p className="px-5 pb-5 text-sm text-emerald-600 dark:text-emerald-400">{addState.success}</p>
      )}
    </Card>
  );
}

function TagRow({ tag }: { tag: TagItem }) {
  const [name, setName] = useState(tag.name);
  const [pending, start] = useTransition();
  const dirty = name.trim() !== tag.name && name.trim().length > 0;

  function save() {
    const fd = new FormData();
    fd.set("id", tag.id);
    fd.set("name", name);
    start(() => renameTag(fd));
  }
  function remove() {
    const fd = new FormData();
    fd.set("id", tag.id);
    start(() => deleteTag(fd));
  }

  return (
    <div className="flex items-center gap-2 px-3 py-2">
      <Hash size={14} className="text-muted" />
      <input
        value={name}
        onChange={(e) => setName(e.target.value)}
        className="h-9 flex-1 rounded-lg border border-border bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40"
      />
      <span className="text-xs text-muted">{tag.count} used</span>
      <Button size="sm" variant={dirty ? "primary" : "secondary"} disabled={!dirty || pending} onClick={save}>
        <Check size={14} /> Save
      </Button>
      <button
        type="button"
        onClick={remove}
        disabled={pending}
        aria-label="Delete tag"
        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 transition-colors cursor-pointer"
      >
        <Trash2 size={15} />
      </button>
    </div>
  );
}
