import Link from "next/link";
import { Prisma } from "@prisma/client";
import { Ticket, Clock, CheckCircle2, AlertTriangle } from "lucide-react";
import { requireTenantUser, isStaff } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { PageHeader } from "@/components/app/PageHeader";
import { Card, CardHeader } from "@/components/ui/Card";
import { TrendChart, StatusPie } from "@/components/app/DashboardCharts";
import { TicketListItem } from "@/components/app/TicketListItem";
import { getTenantStatuses, resolveStatus } from "@/lib/statuses";
import { getTenantPriorities, resolvePriority, topPriorityKeys } from "@/lib/priorities";
import { format } from "date-fns";

export default async function DashboardPage() {
  const { user, tenantId } = await requireTenantUser();
  const staff = isStaff(user.role);

  // Customers see tickets they requested or watch; staff see the whole board.
  const scope: Prisma.TicketWhereInput = staff
    ? { tenantId }
    : {
        tenantId,
        OR: [
          { requesterId: user.id },
          { watchers: { some: { userId: user.id } } },
        ],
      };

  const [statuses, priorities] = await Promise.all([
    getTenantStatuses(tenantId),
    getTenantPriorities(tenantId),
  ]);
  const closedKeys = statuses.filter((s) => s.isClosed).map((s) => s.key);
  const topKeys = topPriorityKeys(priorities);

  const [total, resolved, urgent, byStatusRaw, recent, last14] =
    await Promise.all([
      prisma.ticket.count({ where: scope }),
      prisma.ticket.count({ where: { ...scope, status: { in: closedKeys } } }),
      prisma.ticket.count({ where: { ...scope, status: { notIn: closedKeys }, priority: { in: topKeys } } }),
      prisma.ticket.groupBy({ by: ["status"], where: scope, _count: true }),
      prisma.ticket.findMany({
        where: scope,
        orderBy: { createdAt: "desc" },
        take: 6,
        include: {
          requester: { select: { name: true, avatarColor: true } },
          assignee: { select: { name: true, avatarColor: true } },
          category: { select: { name: true, color: true } },
          _count: { select: { comments: true } },
        },
      }),
      prisma.ticket.findMany({
        where: { ...scope, createdAt: { gte: new Date(Date.now() - 13 * 86400000) } },
        select: { createdAt: true },
      }),
    ]);

  // Build 14-day trend.
  const days: { date: string; count: number }[] = [];
  for (let i = 13; i >= 0; i--) {
    const d = new Date();
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() - i);
    days.push({ date: format(d, "MMM d"), count: 0 });
  }
  const dayIndex = new Map(days.map((d, i) => [d.date, i]));
  for (const t of last14) {
    const key = format(new Date(t.createdAt), "MMM d");
    const idx = dayIndex.get(key);
    if (idx !== undefined) days[idx].count++;
  }

  const statusCounts = new Map(byStatusRaw.map((s) => [s.status, s._count as number]));
  const pieData = statuses
    .map((s) => ({
      name: s.label,
      value: statusCounts.get(s.key) ?? 0,
      color: s.color,
    }))
    .filter((d) => d.value > 0);

  const unresolved = total - resolved;
  const stats = [
    { label: "Total tickets", value: total, icon: Ticket, color: "text-brand", bg: "bg-brand/10" },
    { label: "Unresolved", value: unresolved, icon: Clock, color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-500/10" },
    { label: "Resolved", value: resolved, icon: CheckCircle2, color: "text-emerald-600 dark:text-emerald-400", bg: "bg-emerald-500/10" },
    { label: "Urgent open", value: urgent, icon: AlertTriangle, color: "text-red-600 dark:text-red-400", bg: "bg-red-500/10" },
  ];

  return (
    <div>
      <PageHeader
        title={`Hello, ${user.name.split(" ")[0]} 👋`}
        description={
          staff
            ? "Here's what's happening across your helpdesk."
            : "Track the status of your support requests."
        }
      />

      {urgent > 0 && staff && (
        <div className="mb-6 flex items-center gap-2 rounded-xl border border-red-200 dark:border-red-500/30 bg-red-50 dark:bg-red-500/10 px-4 py-3 text-sm text-red-700 dark:text-red-300">
          <AlertTriangle size={18} />
          {urgent} urgent {urgent === 1 ? "ticket" : "tickets"} need attention.
          <Link href={`/tickets?priority=${topKeys[0] ?? ""}`} className="ml-auto font-medium underline">
            View
          </Link>
        </div>
      )}

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        {stats.map((s) => (
          <Card key={s.label} className="p-5">
            <div className={`inline-flex rounded-lg p-2 ${s.bg}`}>
              <s.icon size={20} className={s.color} />
            </div>
            <p className="mt-3 text-3xl font-bold tracking-tight">{s.value}</p>
            <p className="text-sm text-muted">{s.label}</p>
          </Card>
        ))}
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Tickets created (last 14 days)" />
          <TrendChart data={days} />
        </Card>
        <Card>
          <CardHeader title="By status" />
          {pieData.length ? (
            <StatusPie data={pieData} />
          ) : (
            <p className="px-5 py-16 text-center text-sm text-muted">No data yet</p>
          )}
        </Card>
      </div>

      <Card className="mt-6">
        <CardHeader
          title="Recent tickets"
          action={
            <Link href="/tickets" className="text-sm font-medium text-brand hover:underline">
              View all
            </Link>
          }
        />
        {recent.length ? (
          <div className="divide-y divide-border">
            {recent.map((t) => {
              const s = resolveStatus(statuses, t.status);
              const p = resolvePriority(priorities, t.priority);
              return (
              <TicketListItem
                key={t.id}
                ticket={{
                  id: t.id,
                  number: t.number,
                  subject: t.subject,
                  status: { key: s.key, label: s.label, color: s.color, isClosed: s.isClosed },
                  priority: { key: p.key, label: p.label, color: p.color },
                  createdAt: t.createdAt.toISOString(),
                  dueAt: t.dueAt ? t.dueAt.toISOString() : null,
                  commentCount: t._count.comments,
                  requester: { name: t.requester.name, color: t.requester.avatarColor },
                  assignee: t.assignee
                    ? { name: t.assignee.name, color: t.assignee.avatarColor }
                    : null,
                  category: t.category,
                }}
              />
              );
            })}
          </div>
        ) : (
          <p className="px-5 py-16 text-center text-sm text-muted">
            No tickets yet.{" "}
            <Link href="/tickets/new" className="font-medium text-brand hover:underline">
              Create the first one
            </Link>
          </p>
        )}
      </Card>
    </div>
  );
}
