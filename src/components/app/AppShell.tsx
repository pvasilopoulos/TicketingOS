"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Menu, Search, X, ShieldCheck, LogOut } from "lucide-react";
import { Sidebar } from "./Sidebar";
import { UserMenu } from "./UserMenu";
import {
  NotificationsButton,
  type NotificationItem,
} from "./NotificationsButton";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { PushToggle } from "./PushToggle";
import { RealtimeListener } from "./RealtimeListener";
import { exitTenant } from "@/lib/actions/platform";

export function AppShell({
  user,
  notifications,
  impersonating = false,
  children,
}: {
  user: { name: string; email: string; role: string; color: string; tenantName: string | null };
  notifications: NotificationItem[];
  impersonating?: boolean;
  children: React.ReactNode;
}) {
  const [drawer, setDrawer] = useState(false);
  const router = useRouter();

  return (
    <div className="flex h-screen overflow-hidden">
      <RealtimeListener />
      {/* Desktop sidebar */}
      <div className="hidden lg:block">
        <Sidebar role={user.role} tenantName={user.tenantName} />
      </div>

      {/* Mobile drawer */}
      {drawer && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div
            className="absolute inset-0 bg-black/40"
            onClick={() => setDrawer(false)}
          />
          <div className="absolute left-0 top-0 h-full animate-in">
            <Sidebar
              role={user.role}
              tenantName={user.tenantName}
              onNavigate={() => setDrawer(false)}
            />
          </div>
          <button
            onClick={() => setDrawer(false)}
            className="absolute right-4 top-4 text-white"
            aria-label="Close menu"
          >
            <X size={24} />
          </button>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        {impersonating && (
          <div className="flex shrink-0 items-center gap-2 bg-sky-600 px-4 py-2 text-sm text-white lg:px-6">
            <ShieldCheck size={16} />
            <span className="min-w-0 truncate">
              Platform operator — viewing{" "}
              <strong>{user.tenantName}</strong> as admin
            </span>
            <form action={exitTenant} className="ml-auto">
              <button
                type="submit"
                className="inline-flex items-center gap-1.5 rounded-md bg-white/15 px-2.5 py-1 text-xs font-medium hover:bg-white/25 transition-colors cursor-pointer"
              >
                <LogOut size={13} /> Exit tenant
              </button>
            </form>
          </div>
        )}
        <header className="flex h-16 shrink-0 items-center gap-3 border-b border-border bg-surface px-4 lg:px-6">
          <button
            onClick={() => setDrawer(true)}
            className="lg:hidden inline-flex h-9 w-9 items-center justify-center rounded-lg text-muted hover:bg-surface-2"
            aria-label="Open menu"
          >
            <Menu size={20} />
          </button>

          <form
            className="relative flex-1 max-w-md"
            onSubmit={(e) => {
              e.preventDefault();
              const q = new FormData(e.currentTarget).get("q") as string;
              router.push(q ? `/tickets?q=${encodeURIComponent(q)}` : "/tickets");
            }}
          >
            <Search
              size={16}
              className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted"
            />
            <input
              name="q"
              placeholder="Search tickets…"
              className="h-9 w-full rounded-lg border border-border bg-surface-2 pl-9 pr-3 text-sm placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-brand/40"
            />
          </form>

          <div className="ml-auto flex items-center gap-1">
            <ThemeToggle />
            <PushToggle />
            <NotificationsButton notifications={notifications} />
            <div className="ml-1">
              <UserMenu
                name={user.name}
                email={user.email}
                role={user.role}
                color={user.color}
              />
            </div>
          </div>
        </header>

        <main className="flex-1 overflow-y-auto">
          <div className="mx-auto max-w-6xl px-4 py-6 lg:px-8 lg:py-8 animate-in">
            {children}
          </div>
        </main>
      </div>
    </div>
  );
}
