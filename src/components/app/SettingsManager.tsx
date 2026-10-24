"use client";

import { useActionState, useTransition } from "react";
import { useFormStatus } from "react-dom";
import { Trash2, Plus } from "lucide-react";
import {
  updateWorkspaceName,
  createCategory,
  deleteCategory,
  type SettingsState,
} from "@/lib/actions/settings";
import { Input, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";

function Saving({ label }: { label: string }) {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" disabled={pending}>
      {pending ? "Saving…" : label}
    </Button>
  );
}

function Banner({ state }: { state: SettingsState }) {
  if (!state) return null;
  if (state.error)
    return (
      <div className="rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-3 py-2 text-sm text-red-700 dark:text-red-300">
        {state.error}
      </div>
    );
  if (state.success)
    return (
      <div className="rounded-lg bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
        {state.success}
      </div>
    );
  return null;
}

export function SettingsManager({
  workspaceName,
  slug,
  categories,
}: {
  workspaceName: string;
  slug: string;
  categories: { id: string; name: string; color: string }[];
}) {
  const [nameState, nameAction] = useActionState<SettingsState, FormData>(
    updateWorkspaceName,
    undefined,
  );
  const [catState, catAction] = useActionState<SettingsState, FormData>(
    createCategory,
    undefined,
  );
  const [, start] = useTransition();

  return (
    <div className="space-y-6">
      <Card className="p-6">
        <h3 className="mb-4 text-sm font-semibold">Workspace</h3>
        <form action={nameAction} className="space-y-4">
          <Banner state={nameState} />
          <div>
            <Label htmlFor="name">Workspace name</Label>
            <Input id="name" name="name" defaultValue={workspaceName} required />
          </div>
          <div>
            <Label htmlFor="slug">Workspace URL slug</Label>
            <Input id="slug" value={slug} disabled className="font-mono" />
            <p className="mt-1 text-xs text-muted">
              Used to identify your tenant. Contact support to change it.
            </p>
          </div>
          <Saving label="Save changes" />
        </form>
      </Card>

      <Card>
        <CardHeader title="Ticket categories" />
        <div className="divide-y divide-border">
          {categories.length === 0 && (
            <p className="px-5 py-6 text-center text-sm text-muted">No categories yet.</p>
          )}
          {categories.map((c) => (
            <div key={c.id} className="flex items-center gap-3 px-5 py-3">
              <span className="h-3.5 w-3.5 rounded-full" style={{ backgroundColor: c.color }} />
              <span className="flex-1 text-sm font-medium">{c.name}</span>
              <form
                action={(fd) => {
                  fd.set("id", c.id);
                  start(() => deleteCategory(fd));
                }}
              >
                <button
                  type="submit"
                  className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 transition-colors cursor-pointer"
                  aria-label={`Delete ${c.name}`}
                >
                  <Trash2 size={15} />
                </button>
              </form>
            </div>
          ))}
        </div>
        <form action={catAction} className="flex flex-wrap items-end gap-3 border-t border-border p-5">
          <div className="flex-1 min-w-40">
            <Label htmlFor="cat-name">New category</Label>
            <Input id="cat-name" name="name" placeholder="e.g. Hardware" required />
          </div>
          <div>
            <Label htmlFor="cat-color">Color</Label>
            <input
              id="cat-color"
              name="color"
              type="color"
              defaultValue="#6366f1"
              className="h-10 w-14 cursor-pointer rounded-lg border border-border bg-surface p-1"
            />
          </div>
          <Button type="submit" variant="secondary">
            <Plus size={16} /> Add
          </Button>
        </form>
        <div className="px-5 pb-5">
          <Banner state={catState} />
        </div>
      </Card>
    </div>
  );
}
