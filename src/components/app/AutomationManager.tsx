"use client";

import { useActionState, useState, useTransition } from "react";
import { useFormStatus } from "react-dom";
import { Zap, Trash2, ArrowRight } from "lucide-react";
import {
  createRule,
  toggleRule,
  deleteRule,
  type AutomationState,
} from "@/lib/actions/automations";
import { Input, Select, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";

type Opt = { value: string; label: string };
type Rule = {
  id: string;
  name: string;
  enabled: boolean;
  conditionField: string | null;
  conditionValue: string | null;
  actionType: string;
  actionValue: string;
};

const ACTION_LABELS: Record<string, string> = {
  ASSIGN: "Assign to",
  SET_PRIORITY: "Set priority",
  SET_STATUS: "Set status",
  ADD_TAG: "Add tag",
};

function AddBtn() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" variant="secondary" disabled={pending}>
      {pending ? "Adding…" : "Add rule"}
    </Button>
  );
}

export function AutomationManager({
  rules,
  priorities,
  categories,
  statuses,
  agents,
  tags,
}: {
  rules: Rule[];
  priorities: Opt[];
  categories: Opt[];
  statuses: Opt[];
  agents: Opt[];
  tags: Opt[];
}) {
  const [state, action] = useActionState<AutomationState, FormData>(createRule, undefined);
  const [condField, setCondField] = useState("");
  const [actionType, setActionType] = useState("ASSIGN");
  const [, start] = useTransition();

  const condOptions = condField === "priority" ? priorities : condField === "category" ? categories : [];
  const actionOptions =
    actionType === "ASSIGN" ? agents :
    actionType === "SET_PRIORITY" ? priorities :
    actionType === "SET_STATUS" ? statuses : tags;

  function labelFor(opts: Opt[], v: string | null) {
    return opts.find((o) => o.value === v)?.label ?? v ?? "";
  }
  function summarize(r: Rule) {
    const cond =
      r.conditionField === "priority" ? ` & priority is ${labelFor(priorities, r.conditionValue)}` :
      r.conditionField === "category" ? ` & category is ${labelFor(categories, r.conditionValue)}` : "";
    const opts =
      r.actionType === "ASSIGN" ? agents :
      r.actionType === "SET_PRIORITY" ? priorities :
      r.actionType === "SET_STATUS" ? statuses : tags;
    return { when: `When ticket created${cond}`, then: `${ACTION_LABELS[r.actionType]} ${labelFor(opts, r.actionValue)}` };
  }

  function toggle(r: Rule) {
    const fd = new FormData();
    fd.set("id", r.id);
    fd.set("enabled", String(!r.enabled));
    start(() => toggleRule(fd));
  }
  function remove(id: string) {
    const fd = new FormData();
    fd.set("id", id);
    start(() => deleteRule(fd));
  }

  const selectCls = "h-10";

  return (
    <Card>
      <CardHeader
        title={
          <span className="inline-flex items-center gap-2">
            <Zap size={16} /> Automations
          </span>
        }
      />
      <p className="px-6 pt-4 text-sm text-muted">
        Run actions automatically when a ticket is created.
      </p>

      <div className="divide-y divide-border px-2 py-3">
        {rules.length === 0 && (
          <p className="px-3 py-6 text-center text-sm text-muted">No rules yet.</p>
        )}
        {rules.map((r) => {
          const s = summarize(r);
          return (
            <div key={r.id} className="flex flex-wrap items-center gap-3 px-3 py-3">
              <label className="inline-flex cursor-pointer items-center">
                <input
                  type="checkbox"
                  checked={r.enabled}
                  onChange={() => toggle(r)}
                  className="h-4 w-4 rounded border-border accent-brand"
                />
              </label>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">{r.name}</p>
                <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted">
                  {s.when} <ArrowRight size={11} /> <span className="text-brand">{s.then}</span>
                </p>
              </div>
              <button
                onClick={() => remove(r.id)}
                aria-label="Delete rule"
                className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 transition-colors cursor-pointer"
              >
                <Trash2 size={15} />
              </button>
            </div>
          );
        })}
      </div>

      <form action={action} className="space-y-4 border-t border-border p-5">
        {state?.error && <p className="text-sm text-red-600 dark:text-red-400">{state.error}</p>}
        {state?.success && <p className="text-sm text-emerald-600 dark:text-emerald-400">{state.success}</p>}
        <div>
          <Label htmlFor="r-name">Rule name</Label>
          <Input id="r-name" name="name" placeholder="e.g. Route urgent to Dana" required />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Label>Condition (optional)</Label>
            <Select name="conditionField" value={condField} onChange={(e) => setCondField(e.target.value)} className={selectCls}>
              <option value="">Any ticket</option>
              <option value="priority">Priority is…</option>
              <option value="category">Category is…</option>
            </Select>
          </div>
          <div>
            <Label>Condition value</Label>
            <Select name="conditionValue" disabled={!condField} className={selectCls}>
              <option value="">—</option>
              {condOptions.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Action</Label>
            <Select name="actionType" value={actionType} onChange={(e) => setActionType(e.target.value)} className={selectCls}>
              <option value="ASSIGN">Assign to</option>
              <option value="SET_PRIORITY">Set priority</option>
              <option value="SET_STATUS">Set status</option>
              <option value="ADD_TAG">Add tag</option>
            </Select>
          </div>
          <div>
            <Label>Action target</Label>
            <Select name="actionValue" required className={selectCls}>
              <option value="">Choose…</option>
              {actionOptions.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </Select>
          </div>
        </div>
        <AddBtn />
      </form>
    </Card>
  );
}
