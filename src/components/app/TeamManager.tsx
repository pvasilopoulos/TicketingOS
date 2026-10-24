"use client";

import { useActionState, useState, useTransition } from "react";
import { useFormStatus } from "react-dom";
import { UserPlus, Trash2 } from "lucide-react";
import {
  createMember,
  updateMemberRole,
  removeMember,
  type TeamState,
} from "@/lib/actions/team";
import { Input, Select, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { Avatar } from "@/components/ui/Avatar";
import { TENANT_ROLES, ROLE_LABELS, type Role } from "@/lib/constants";

type Member = {
  id: string;
  name: string;
  email: string;
  role: string;
  color: string;
  ticketCount: number;
};

function AddSubmit() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" disabled={pending}>
      <UserPlus size={16} /> {pending ? "Adding…" : "Add member"}
    </Button>
  );
}

export function TeamManager({
  members,
  currentUserId,
}: {
  members: Member[];
  currentUserId: string;
}) {
  const [state, action] = useActionState<TeamState, FormData>(createMember, undefined);

  return (
    <div className="space-y-6">
      <Card className="p-6">
        <h3 className="mb-4 text-sm font-semibold">Invite a team member</h3>
        <form action={action} className="space-y-4">
          {state?.error && (
            <div className="rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-3 py-2 text-sm text-red-700 dark:text-red-300">
              {state.error}
            </div>
          )}
          {state?.success && (
            <div className="rounded-lg bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
              {state.success}
            </div>
          )}
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="name">Name</Label>
              <Input id="name" name="name" placeholder="Jane Doe" required />
            </div>
            <div>
              <Label htmlFor="email">Email</Label>
              <Input id="email" name="email" type="email" placeholder="jane@company.com" required />
            </div>
            <div>
              <Label htmlFor="password">Temporary password</Label>
              <Input id="password" name="password" type="text" placeholder="At least 6 characters" required />
            </div>
            <div>
              <Label htmlFor="role">Role</Label>
              <Select id="role" name="role" defaultValue="AGENT">
                {TENANT_ROLES.map((r) => (
                  <option key={r} value={r}>{ROLE_LABELS[r as Role]}</option>
                ))}
              </Select>
            </div>
          </div>
          <AddSubmit />
        </form>
      </Card>

      <Card>
        <CardHeader title={`Members (${members.length})`} />
        <div className="divide-y divide-border">
          {members.map((m) => (
            <MemberRow key={m.id} member={m} isSelf={m.id === currentUserId} />
          ))}
        </div>
      </Card>
    </div>
  );
}

function MemberRow({ member, isSelf }: { member: Member; isSelf: boolean }) {
  const [role, setRole] = useState(member.role);
  const [pending, start] = useTransition();
  const [confirming, setConfirming] = useState(false);

  return (
    <div className="flex flex-wrap items-center gap-3 px-5 py-3.5">
      <Avatar name={member.name} color={member.color} size={40} />
      <div className="min-w-0 flex-1">
        <p className="truncate font-medium">
          {member.name}
          {isSelf && <span className="ml-1.5 text-xs text-muted">(you)</span>}
        </p>
        <p className="truncate text-xs text-muted">{member.email}</p>
      </div>

      <span className="hidden text-xs text-muted sm:block">
        {member.ticketCount} assigned
      </span>

      <Select
        value={role}
        disabled={pending}
        onChange={(e) => {
          const next = e.target.value;
          setRole(next);
          const fd = new FormData();
          fd.set("userId", member.id);
          fd.set("role", next);
          start(() => updateMemberRole(fd));
        }}
        className="h-9 w-32"
      >
        {TENANT_ROLES.map((r) => (
          <option key={r} value={r}>{ROLE_LABELS[r as Role]}</option>
        ))}
      </Select>

      {!isSelf &&
        (confirming ? (
          <form
            action={(fd) => {
              fd.set("userId", member.id);
              start(() => removeMember(fd));
            }}
          >
            <Button type="submit" variant="danger" size="sm" disabled={pending}>
              Confirm
            </Button>
          </form>
        ) : (
          <button
            onClick={() => setConfirming(true)}
            className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-muted hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 transition-colors cursor-pointer"
            aria-label="Remove member"
          >
            <Trash2 size={16} />
          </button>
        ))}
    </div>
  );
}
