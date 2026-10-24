"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  LayoutDashboard,
  Ticket,
  Users,
  Settings,
  PlusCircle,
  LifeBuoy,
} from "lucide-react";
import { cn } from "@/lib/utils";

type NavItem = { href: string; label: string; icon: React.ElementType };

export function Sidebar({
  role,
  tenantName,
  onNavigate,
}: {
  role: string;
  tenantName: string | null;
  onNavigate?: () => void;
}) {
  const pathname = usePathname();
  const isAdmin = role === "ADMIN" || role === "SUPER_ADMIN";
  const isStaff = isAdmin || role === "AGENT";

  const nav: NavItem[] = [
    { href: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
    { href: "/tickets", label: "Tickets", icon: Ticket },
  ];
  if (isAdmin) {
    nav.push({ href: "/team", label: "Team", icon: Users });
    nav.push({ href: "/settings", label: "Settings", icon: Settings });
  }

  return (
    <aside className="flex h-full w-64 flex-col border-r border-border bg-surface">
      <div className="flex items-center gap-2.5 px-5 h-16 border-b border-border">
        <span className="grid h-9 w-9 place-items-center rounded-lg bg-brand text-brand-fg">
          <LifeBuoy size={20} />
        </span>
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold leading-tight">
            {tenantName ?? "TicketingOS"}
          </p>
          <p className="text-xs text-muted">Helpdesk</p>
        </div>
      </div>

      <div className="px-3 pt-4">
        <Link
          href="/tickets/new"
          onClick={onNavigate}
          className="flex items-center justify-center gap-2 rounded-lg bg-brand px-3 py-2.5 text-sm font-medium text-brand-fg hover:opacity-90 transition"
        >
          <PlusCircle size={18} />
          New ticket
        </Link>
      </div>

      <nav className="flex-1 space-y-1 px-3 py-4">
        {nav.map((item) => {
          const active =
            pathname === item.href || pathname.startsWith(item.href + "/");
          return (
            <Link
              key={item.href}
              href={item.href}
              onClick={onNavigate}
              className={cn(
                "flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors",
                active
                  ? "bg-brand/10 text-brand"
                  : "text-muted hover:bg-surface-2 hover:text-fg",
              )}
            >
              <item.icon size={18} />
              {item.label}
            </Link>
          );
        })}
      </nav>

      <div className="border-t border-border p-3">
        <p className="px-3 text-xs text-muted">
          {isStaff ? "Agent workspace" : "Customer portal"}
        </p>
      </div>
    </aside>
  );
}
