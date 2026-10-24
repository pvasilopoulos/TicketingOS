"use client";

import { useActionState, useEffect, useState } from "react";
import { useFormStatus } from "react-dom";
import { Send, Lock } from "lucide-react";
import { addComment, type ActionState } from "@/lib/actions/tickets";
import { RichTextEditor } from "@/components/ui/RichTextEditor";
import { FileInput } from "@/components/ui/FileInput";
import { Button } from "@/components/ui/Button";
import { cn } from "@/lib/utils";

function Submit({ internal }: { internal: boolean }) {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" disabled={pending} variant={internal ? "secondary" : "primary"}>
      {internal ? <Lock size={16} /> : <Send size={16} />}
      {pending ? "Sending…" : internal ? "Add internal note" : "Send reply"}
    </Button>
  );
}

export function ReplyForm({
  ticketId,
  canInternal,
  mentionUsers,
  canned,
}: {
  ticketId: string;
  canInternal: boolean;
  mentionUsers?: { id: string; label: string }[];
  canned?: { title: string; body: string }[];
}) {
  const action = addComment.bind(null, ticketId);
  const [state, formAction] = useActionState<ActionState, FormData>(action, undefined);
  const [internal, setInternal] = useState(false);
  // Remount the editor + file input after a successful submit to clear them.
  const [formKey, setFormKey] = useState(0);

  useEffect(() => {
    if (state?.ok) {
      setFormKey((k) => k + 1);
      setInternal(false);
    }
  }, [state]);

  return (
    <form
      action={formAction}
      className={cn(
        "rounded-xl border p-4 transition-colors",
        internal
          ? "border-amber-300 dark:border-amber-500/40 bg-amber-50/60 dark:bg-amber-500/5"
          : "border-border bg-surface",
      )}
    >
      {state?.error && (
        <p className="mb-2 text-sm text-red-600 dark:text-red-400">{state.error}</p>
      )}

      <div key={formKey} className="space-y-3">
        <RichTextEditor
          name="body"
          minHeight={90}
          placeholder={internal ? "Write an internal note (only staff can see this)…" : "Write a reply… use @ to mention"}
          mentionUsers={mentionUsers}
          canned={canned}
        />
        <FileInput name="files" />
      </div>

      <div className="mt-3 flex items-center justify-between">
        {canInternal ? (
          <label className="flex items-center gap-2 text-sm text-muted cursor-pointer select-none">
            <input
              type="checkbox"
              name="isInternal"
              checked={internal}
              onChange={(e) => setInternal(e.target.checked)}
              className="h-4 w-4 rounded border-border accent-amber-500"
            />
            Internal note
          </label>
        ) : (
          <span />
        )}
        <Submit internal={internal} />
      </div>
    </form>
  );
}
