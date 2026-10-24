"use client";

import Link from "next/link";
import { LogOut, ChevronDown, Bell } from "lucide-react";
import { Avatar } from "@/components/ui/Avatar";
import { Dropdown } from "@/components/ui/Dropdown";
import { logoutAction } from "@/lib/actions/auth";
import { ROLE_LABELS, type Role } from "@/lib/constants";

export function UserMenu({
  name,
  email,
  role,
  color,
}: {
  name: string;
  email: string;
  role: string;
  color: string;
}) {
  return (
    <Dropdown
      width={240}
      trigger={({ toggle }) => (
        <button
          onClick={toggle}
          className="flex items-center gap-2 rounded-lg px-1.5 py-1 hover:bg-surface-2 transition-colors cursor-pointer"
        >
          <Avatar name={name} color={color} size={32} />
          <ChevronDown size={16} className="text-muted" />
        </button>
      )}
    >
      {() => (
        <div>
          <div className="flex items-center gap-3 p-3 border-b border-border">
            <Avatar name={name} color={color} size={40} />
            <div className="min-w-0">
              <p className="truncate text-sm font-medium">{name}</p>
              <p className="truncate text-xs text-muted">{email}</p>
            </div>
          </div>
          <div className="px-3 py-2 text-xs text-muted border-b border-border">
            Role:{" "}
            <span className="font-medium text-fg">
              {ROLE_LABELS[role as Role] ?? role}
            </span>
          </div>
          <div className="p-1.5">
            <Link
              href="/notifications"
              className="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-fg hover:bg-surface-2 transition-colors cursor-pointer"
            >
              <Bell size={16} />
              Notification settings
            </Link>
          </div>
          <form action={logoutAction} className="border-t border-border p-1.5">
            <button
              type="submit"
              className="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors cursor-pointer"
            >
              <LogOut size={16} />
              Sign out
            </button>
          </form>
        </div>
      )}
    </Dropdown>
  );
}
