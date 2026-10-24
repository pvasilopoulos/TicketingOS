"use client";

import { useActionState } from "react";
import { useFormStatus } from "react-dom";
import { BellRing, CheckCircle2 } from "lucide-react";
import { saveTenantPolicy, type PolicyState } from "@/lib/actions/policy";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { NOTIFY_CATEGORIES, CATEGORY_LABELS, type Channels, type Category } from "@/lib/notify-shared";

function SaveButton() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" disabled={pending}>
      {pending ? "Saving…" : "Save policy"}
    </Button>
  );
}

export function TenantPolicyManager({
  policy,
}: {
  policy: {
    allowInApp: boolean;
    allowPush: boolean;
    allowEmail: boolean;
    defaults: Record<Category, Channels>;
  };
}) {
  const [state, action] = useActionState<PolicyState, FormData>(saveTenantPolicy, undefined);

  return (
    <Card>
      <CardHeader
        title={
          <span className="inline-flex items-center gap-2">
            <BellRing size={16} /> Notification policy
          </span>
        }
      />
      <form action={action} className="space-y-5 p-6">
        <div>
          <p className="text-sm font-semibold">Allowed channels (org-wide)</p>
          <p className="mb-2 text-xs text-muted">Disabling a channel turns it off for everyone, overriding personal settings.</p>
          <div className="flex flex-wrap gap-4">
            {([
              ["allowInApp", "In-app", policy.allowInApp],
              ["allowPush", "Push", policy.allowPush],
              ["allowEmail", "Email", policy.allowEmail],
            ] as const).map(([name, label, checked]) => (
              <label key={name} className="flex items-center gap-2 text-sm cursor-pointer select-none">
                <input type="checkbox" name={name} defaultChecked={checked} className="h-4 w-4 rounded border-border accent-brand" />
                {label}
              </label>
            ))}
          </div>
        </div>

        <div>
          <p className="text-sm font-semibold">Defaults for new users</p>
          <p className="mb-2 text-xs text-muted">Applied until a user customizes their own preferences.</p>
          <div className="overflow-x-auto rounded-lg border border-border">
            <table className="w-full min-w-xl text-sm">
              <thead>
                <tr className="text-xs text-muted">
                  <th className="px-3 py-2 text-left font-medium">Event</th>
                  <th className="px-3 py-2 font-medium">In-app</th>
                  <th className="px-3 py-2 font-medium">Push</th>
                  <th className="px-3 py-2 font-medium">Email</th>
                </tr>
              </thead>
              <tbody>
                {NOTIFY_CATEGORIES.map((c) => (
                  <tr key={c} className="border-t border-border">
                    <td className="px-3 py-2.5">{CATEGORY_LABELS[c]}</td>
                    {(["inApp", "push", "email"] as const).map((ch) => (
                      <td key={ch} className="px-3 py-2.5 text-center">
                        <input
                          type="checkbox"
                          name={`def_${c}_${ch}`}
                          defaultChecked={policy.defaults[c][ch]}
                          className="h-4 w-4 rounded border-border accent-brand"
                        />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        <div className="flex items-center gap-3">
          <SaveButton />
          {state?.success && (
            <span className="inline-flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400">
              <CheckCircle2 size={15} /> {state.success}
            </span>
          )}
        </div>
      </form>
    </Card>
  );
}
