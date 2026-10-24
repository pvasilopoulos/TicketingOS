import Link from "next/link";
import { Building2, Users, Ticket, ChevronRight } from "lucide-react";
import { requireSuperAdmin } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { Card } from "@/components/ui/Card";
import { timeAgo } from "@/lib/utils";

export default async function PlatformPage() {
  await requireSuperAdmin();

  const [tenants, totalUsers, totalTickets, openTickets] = await Promise.all([
    prisma.tenant.findMany({
      orderBy: { createdAt: "desc" },
      include: {
        _count: {
          select: { users: true, tickets: true },
        },
      },
    }),
    prisma.user.count({ where: { tenantId: { not: null } } }),
    prisma.ticket.count(),
    prisma.ticket.count({ where: { status: { in: ["OPEN", "PENDING"] } } }),
  ]);

  const stats = [
    { label: "Tenants", value: tenants.length, icon: Building2 },
    { label: "Users", value: totalUsers, icon: Users },
    { label: "Tickets", value: totalTickets, icon: Ticket },
    { label: "Open / Pending", value: openTickets, icon: Ticket },
  ];

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">Platform overview</h1>
      <p className="mt-1 text-sm text-muted">
        Every tenant on TicketingOS, isolated and self-serve.
      </p>

      <div className="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        {stats.map((s) => (
          <Card key={s.label} className="p-5">
            <div className="inline-flex rounded-lg bg-sky-500/10 p-2">
              <s.icon size={20} className="text-sky-600 dark:text-sky-400" />
            </div>
            <p className="mt-3 text-3xl font-bold tracking-tight">{s.value}</p>
            <p className="text-sm text-muted">{s.label}</p>
          </Card>
        ))}
      </div>

      <h2 className="mt-8 mb-3 text-sm font-semibold">Tenants</h2>
      <Card>
        <div className="divide-y divide-border">
          {tenants.map((t) => (
            <Link
              key={t.id}
              href={`/platform/tenants/${t.id}`}
              className="flex items-center gap-4 px-5 py-4 hover:bg-surface-2 transition-colors"
            >
              <span className="grid h-11 w-11 place-items-center rounded-lg bg-brand/10 font-semibold text-brand">
                {t.name.slice(0, 2).toUpperCase()}
              </span>
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium">{t.name}</p>
                <p className="truncate text-xs text-muted">
                  /{t.slug} · created {timeAgo(t.createdAt)}
                </p>
              </div>
              <div className="hidden gap-6 text-sm sm:flex">
                <div className="text-right">
                  <p className="font-semibold">{t._count.users}</p>
                  <p className="text-xs text-muted">users</p>
                </div>
                <div className="text-right">
                  <p className="font-semibold">{t._count.tickets}</p>
                  <p className="text-xs text-muted">tickets</p>
                </div>
              </div>
              <ChevronRight size={18} className="text-muted" />
            </Link>
          ))}
          {tenants.length === 0 && (
            <p className="px-5 py-12 text-center text-sm text-muted">No tenants yet.</p>
          )}
        </div>
      </Card>
    </div>
  );
}
