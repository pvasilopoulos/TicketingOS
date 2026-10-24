"use client";

import { useActionState, useState, useTransition } from "react";
import { useFormStatus } from "react-dom";
import { CheckCircle2, Smartphone, Trash2 } from "lucide-react";
import {
  saveNotificationPrefs,
  removeDevice,
  type PrefsState,
} from "@/lib/actions/prefs";
import { NOTIFY_CATEGORIES, CATEGORY_LABELS, type UserPrefs } from "@/lib/notify-shared";
import { Select, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { timeAgo } from "@/lib/utils";

function mm(v: number | null) {
  if (v == null) return "";
  const h = String(Math.floor(v / 60)).padStart(2, "0");
  const m = String(v % 60).padStart(2, "0");
  return `${h}:${m}`;
}

function SaveButton() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" disabled={pending}>
      {pending ? "Saving…" : "Save preferences"}
    </Button>
  );
}

type Device = { id: string; userAgent: string | null; createdAt: string };

export function NotificationPrefsForm({
  prefs,
  priorities,
  devices,
}: {
  prefs: UserPrefs;
  priorities: { key: string; label: string }[];
  devices: Device[];
}) {
  const [state, action] = useActionState<PrefsState, FormData>(saveNotificationPrefs, undefined);
  const [quietOn, setQuietOn] = useState(prefs.quietStart != null && prefs.quietEnd != null);
  const [, start] = useTransition();

  const cell = "flex items-center justify-center";

  return (
    <div className="space-y-6">
      <form action={action}>
        <Card>
          <CardHeader title="Notify me about…" />
          <div className="overflow-x-auto p-2">
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
                      <td key={ch} className="px-3 py-2.5">
                        <div className={cell}>
                          <input
                            type="checkbox"
                            name={`ch_${c}_${ch}`}
                            defaultChecked={prefs.channels[c][ch]}
                            className="h-4 w-4 rounded border-border accent-brand"
                          />
                        </div>
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="grid gap-5 border-t border-border p-5 sm:grid-cols-2">
            <div>
              <Label>Only notify for priority ≥</Label>
              <Select name="priorityThreshold" defaultValue={prefs.priorityThreshold}>
                <option value="">Any priority</option>
                {priorities.map((p) => (
                  <option key={p.key} value={p.key}>{p.label} and above</option>
                ))}
              </Select>
              <p className="mt-1 text-xs text-muted">Applies to push & email (in-app always kept).</p>
            </div>
            <div>
              <Label>New-ticket scope</Label>
              <Select name="scope" defaultValue={prefs.scope}>
                <option value="all">All new tickets</option>
                <option value="assigned">Only when assigned to me</option>
                <option value="watching">Only tickets I watch</option>
              </Select>
              <p className="mt-1 text-xs text-muted">Limits the broadcast of newly created tickets.</p>
            </div>

            <div className="sm:col-span-2">
              <label className="flex items-center gap-2 text-sm cursor-pointer select-none">
                <input
                  type="checkbox"
                  name="quietEnabled"
                  checked={quietOn}
                  onChange={(e) => setQuietOn(e.target.checked)}
                  className="h-4 w-4 rounded border-border accent-brand"
                />
                Quiet hours (no push)
              </label>
              {quietOn && (
                <div className="mt-2 flex items-center gap-2 text-sm">
                  <input type="time" name="quietStart" defaultValue={mm(prefs.quietStart) || "22:00"} className="h-9 rounded-lg border border-border bg-surface px-2" />
                  <span className="text-muted">to</span>
                  <input type="time" name="quietEnd" defaultValue={mm(prefs.quietEnd) || "08:00"} className="h-9 rounded-lg border border-border bg-surface px-2" />
                </div>
              )}
            </div>
          </div>

          <div className="flex items-center gap-3 border-t border-border p-5">
            <SaveButton />
            {state?.success && (
              <span className="inline-flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400">
                <CheckCircle2 size={15} /> {state.success}
              </span>
            )}
          </div>
        </Card>
      </form>

      <Card>
        <CardHeader title="Your devices" />
        <div className="divide-y divide-border">
          {devices.length === 0 ? (
            <p className="px-5 py-6 text-center text-sm text-muted">
              No devices registered. Use the 🔔 in the top bar to enable push on this device.
            </p>
          ) : (
            devices.map((d) => (
              <div key={d.id} className="flex items-center gap-3 px-5 py-3">
                <Smartphone size={18} className="text-muted" />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm">{d.userAgent || "Unknown device"}</p>
                  <p className="text-xs text-muted">added {timeAgo(d.createdAt)}</p>
                </div>
                <form
                  action={(fd) => {
                    fd.set("id", d.id);
                    start(() => removeDevice(fd));
                  }}
                >
                  <button
                    type="submit"
                    aria-label="Remove device"
                    className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 transition-colors cursor-pointer"
                  >
                    <Trash2 size={15} />
                  </button>
                </form>
              </div>
            ))
          )}
        </div>
      </Card>
    </div>
  );
}
