"use client";

import { useActionState } from "react";
import { useFormStatus } from "react-dom";
import { createTicket } from "@/lib/actions/tickets";
import { Input, Select, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import { RichTextEditor } from "@/components/ui/RichTextEditor";
import { FileInput } from "@/components/ui/FileInput";
import { TeamPicker } from "@/components/app/TeamPicker";

function Submit() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" size="lg" disabled={pending}>
      {pending ? "Creating…" : "Create ticket"}
    </Button>
  );
}

export function NewTicketForm({
  categories,
  people,
  priorities,
  defaultPriority,
}: {
  categories: { id: string; name: string }[];
  people: { id: string; name: string; role: string }[];
  priorities: { key: string; label: string }[];
  defaultPriority: string;
}) {
  const [state, action] = useActionState(createTicket, undefined);

  return (
    <form action={action} className="space-y-5">
      {state?.error && (
        <div className="rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-3 py-2 text-sm text-red-700 dark:text-red-300">
          {state.error}
        </div>
      )}

      <div>
        <Label htmlFor="subject">Subject</Label>
        <Input id="subject" name="subject" placeholder="Brief summary of the issue" required maxLength={140} />
      </div>

      <div>
        <Label>Description</Label>
        <RichTextEditor
          name="description"
          minHeight={160}
          placeholder="Describe what happened, steps to reproduce, and what you expected…"
          mentionUsers={people.map((p) => ({ id: p.id, label: p.name }))}
        />
      </div>

      <div>
        <Label>Attachments</Label>
        <FileInput name="files" />
      </div>

      {people.length > 0 && (
        <div className="rounded-lg border border-border bg-surface p-4">
          <TeamPicker people={people} />
        </div>
      )}

      <div className="grid gap-5 sm:grid-cols-2">
        <div>
          <Label htmlFor="priority">Priority</Label>
          <Select id="priority" name="priority" defaultValue={defaultPriority}>
            {priorities.map((p) => (
              <option key={p.key} value={p.key}>{p.label}</option>
            ))}
          </Select>
        </div>
        <div>
          <Label htmlFor="categoryId">Category</Label>
          <Select id="categoryId" name="categoryId" defaultValue="">
            <option value="">— None —</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </Select>
        </div>
      </div>

      <div className="flex gap-3 pt-2">
        <Submit />
      </div>
    </form>
  );
}
