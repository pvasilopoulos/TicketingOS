"use client";

import { useState } from "react";
import { UserPlus, X } from "lucide-react";
import { Avatar } from "@/components/ui/Avatar";
import { Dropdown } from "@/components/ui/Dropdown";
import {
  WATCHER_ROLES,
  WATCHER_ROLE_LABELS,
  WATCHER_ROLE_HINTS,
  ROLE_LABELS,
  type WatcherRole,
  type Role,
} from "@/lib/constants";

type Person = { id: string; name: string; role: string };
type Member = { userId: string; name: string; role: WatcherRole };

export function TeamPicker({ people }: { people: Person[] }) {
  const [members, setMembers] = useState<Member[]>([]);

  const selectedIds = new Set(members.map((m) => m.userId));
  const available = people.filter((p) => !selectedIds.has(p.id));

  function add(p: Person) {
    setMembers((m) => [...m, { userId: p.id, name: p.name, role: "COLLABORATOR" }]);
  }
  function remove(id: string) {
    setMembers((m) => m.filter((x) => x.userId !== id));
  }
  function setRole(id: string, role: WatcherRole) {
    setMembers((m) => m.map((x) => (x.userId === id ? { ...x, role } : x)));
  }

  return (
    <div>
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium text-fg">Team</span>
        {available.length > 0 && (
          <Dropdown
            width={250}
            align="left"
            trigger={({ toggle }) => (
              <button
                type="button"
                onClick={toggle}
                className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-medium text-muted hover:bg-surface-2 hover:text-fg transition-colors cursor-pointer"
              >
                <UserPlus size={14} /> Add people
              </button>
            )}
          >
            {(close) => (
              <div className="max-h-72 overflow-y-auto py-1">
                {available.map((p) => (
                  <button
                    key={p.id}
                    type="button"
                    onClick={() => {
                      add(p);
                      close();
                    }}
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-surface-2 transition-colors cursor-pointer"
                  >
                    <Avatar name={p.name} size={26} />
                    <span className="min-w-0 flex-1 truncate">{p.name}</span>
                    <span className="text-xs text-muted">
                      {ROLE_LABELS[p.role as Role] ?? p.role}
                    </span>
                  </button>
                ))}
              </div>
            )}
          </Dropdown>
        )}
      </div>

      <p className="mt-1 text-xs text-muted">
        Add teammates who should see this ticket. Collaborators can also manage and reply.
      </p>

      {members.length > 0 && (
        <ul className="mt-3 space-y-2">
          {members.map((m) => (
            <li
              key={m.userId}
              className="flex items-center gap-2.5 rounded-lg border border-border bg-surface-2 px-2.5 py-1.5"
            >
              <Avatar name={m.name} size={28} />
              <span className="min-w-0 flex-1 truncate text-sm font-medium">{m.name}</span>
              <select
                value={m.role}
                onChange={(e) => setRole(m.userId, e.target.value as WatcherRole)}
                title={WATCHER_ROLE_HINTS[m.role]}
                className="h-8 rounded-md border border-border bg-surface px-1.5 text-xs text-fg focus:outline-none focus:ring-2 focus:ring-brand/40 cursor-pointer"
              >
                {WATCHER_ROLES.map((r) => (
                  <option key={r} value={r}>
                    {WATCHER_ROLE_LABELS[r]}
                  </option>
                ))}
              </select>
              <button
                type="button"
                onClick={() => remove(m.userId)}
                aria-label={`Remove ${m.name}`}
                className="text-muted hover:text-red-600 cursor-pointer"
              >
                <X size={15} />
              </button>
            </li>
          ))}
        </ul>
      )}

      <input
        type="hidden"
        name="team"
        value={JSON.stringify(members.map((m) => ({ userId: m.userId, role: m.role })))}
      />
    </div>
  );
}
