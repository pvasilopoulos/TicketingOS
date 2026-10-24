"use client";

import { useActionState } from "react";
import { useFormStatus } from "react-dom";
import { Clock, CheckCircle2 } from "lucide-react";
import { saveSlaTargets, type SlaState } from "@/lib/actions/sla";
import { Input } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";

function SaveButton() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" disabled={pending}>
      {pending ? "Saving…" : "Save SLA targets"}
    </Button>
  );
}

export function SlaManager({
  targets,
  priorities,
}: {
  targets: Record<string, number>;
  priorities: { key: string; label: string; color: string }[];
}) {
  const [state, action] = useActionState<SlaState, FormData>(saveSlaTargets, undefined);

  return (
    <Card>
      <CardHeader
        title={
          <span className="inline-flex items-center gap-2">
            <Clock size={16} /> SLA targets
          </span>
        }
      />
      <form action={action} className="space-y-4 p-6">
        {state?.success && (
          <div className="flex items-center gap-2 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
            <CheckCircle2 size={15} /> {state.success}
          </div>
        )}
        <p className="text-sm text-muted">
          Resolution deadline per priority (in hours). Open tickets past their
          deadline are flagged <span className="font-medium text-red-600 dark:text-red-400">overdue</span>.
        </p>

        <div className="grid gap-4 sm:grid-cols-2">
          {priorities.map((p) => (
            <div key={p.key} className="flex items-center gap-3">
              <span
                className="inline-flex min-w-20 rounded-full px-2.5 py-0.5 text-xs font-medium"
                style={{ backgroundColor: `${p.color}22`, color: p.color }}
              >
                {p.label}
              </span>
              <Input
                name={`sla_${p.key}`}
                type="number"
                min={1}
                defaultValue={targets[p.key] ?? ""}
                className="w-28"
              />
              <span className="text-sm text-muted">hours</span>
            </div>
          ))}
        </div>

        <SaveButton />
      </form>
    </Card>
  );
}
