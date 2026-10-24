"use client";

import { useTransition } from "react";
import { UserPlus, X } from "lucide-react";
import { Avatar } from "@/components/ui/Avatar";
import { Dropdown } from "@/components/ui/Dropdown";
import { addWatcher, removeWatcher, setWatcherRole } from "@/lib/actions/tickets";
import {
  ROLE_LABELS,
  WATCHER_ROLES,
  WATCHER_ROLE_LABELS,
  WATCHER_ROLE_HINTS,
  type Role,
  type WatcherRole,
} from "@/lib/constants";

type Member = {
  id: string;
  name: string;
  color: string;
  tenantRole: string;
  watchRole: string;
};
type Candidate = { id: string; name: string; role: string };

export function TicketWatchers({
  ticketId,
  watchers,
  candidates,
  canManage,
}: {
  ticketId: string;
  watchers: Member[];
  candidates: Candidate[];
  canManage: boolean;
}) {
  const [pending, start] = useTransition();

  function act(fn: (id: string, fd: FormData) => Promise<void>, fd: FormData) {
    start(() => fn(ticketId, fd));
  }

  function add(userId: string) {
    const fd = new FormData();
    fd.set("userId", userId);
    fd.set("role", "VIEWER");
    act(addWatcher, fd);
  }
  function remove(userId: string) {
    const fd = new FormData();
    fd.set("userId", userId);
    act(removeWatcher, fd);
  }
  function changeRole(userId: string, role: WatcherRole) {
    const fd = new FormData();
    fd.set("userId", userId);
    fd.set("role", role);
    act(setWatcherRole, fd);
  }

  return (
    <div>
      <div className="mb-3 flex items-center justify-between">
        <h3 className="text-sm font-semibold">Team</h3>
        {canManage && candidates.length > 0 && (
          <Dropdown
            width={240}
            trigger={({ toggle }) => (
              <button
                onClick={toggle}
                disabled={pending}
                className="inline-flex items-center gap-1 text-xs font-medium text-brand hover:underline cursor-pointer disabled:opacity-50"
              >
                <UserPlus size={14} /> Add
              </button>
            )}
          >
            {(close) => (
              <div className="max-h-72 overflow-y-auto py-1">
                {candidates.map((c) => (
                  <button
                    key={c.id}
                    onClick={() => {
                      add(c.id);
                      close();
                    }}
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-surface-2 transition-colors cursor-pointer"
                  >
                    <Avatar name={c.name} size={26} />
                    <span className="min-w-0 flex-1 truncate">{c.name}</span>
                    <span className="text-xs text-muted">
                      {ROLE_LABELS[c.role as Role] ?? c.role}
                    </span>
                  </button>
                ))}
              </div>
            )}
          </Dropdown>
        )}
      </div>

      {watchers.length === 0 ? (
        <p className="text-sm text-muted">No team members yet.</p>
      ) : (
        <ul className="space-y-2.5">
          {watchers.map((w) => (
            <li key={w.id} className="flex items-center gap-2.5">
              <Avatar name={w.name} color={w.color} size={30} />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium leading-tight">{w.name}</p>
                <p className="text-xs text-muted">
                  {ROLE_LABELS[w.tenantRole as Role] ?? w.tenantRole}
                </p>
              </div>
              {canManage ? (
                <select
                  value={w.watchRole}
                  disabled={pending}
                  onChange={(e) => changeRole(w.id, e.target.value as WatcherRole)}
                  title={WATCHER_ROLE_HINTS[w.watchRole as WatcherRole]}
                  className="h-8 rounded-md border border-border bg-surface px-1.5 text-xs text-fg focus:outline-none focus:ring-2 focus:ring-brand/40 cursor-pointer"
                >
                  {WATCHER_ROLES.map((r) => (
                    <option key={r} value={r}>
                      {WATCHER_ROLE_LABELS[r]}
                    </option>
                  ))}
                </select>
              ) : (
                <span className="rounded bg-surface-2 px-2 py-0.5 text-xs text-muted">
                  {WATCHER_ROLE_LABELS[w.watchRole as WatcherRole] ?? w.watchRole}
                </span>
              )}
              {canManage && (
                <button
                  onClick={() => remove(w.id)}
                  disabled={pending}
                  aria-label={`Remove ${w.name}`}
                  className="text-muted hover:text-red-600 cursor-pointer disabled:opacity-50"
                >
                  <X size={15} />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
