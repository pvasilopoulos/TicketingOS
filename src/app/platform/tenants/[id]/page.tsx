import Link from "next/link";
import { notFound } from "next/navigation";
import { ArrowLeft, LogIn } from "lucide-react";
import { requireSuperAdmin } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { enterTenant } from "@/lib/actions/platform";
import { Card, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Avatar } from "@/components/ui/Avatar";
import { StatusBadge, PriorityBadge } from "@/components/ui/Badge";
import { ROLE_LABELS, type Role } from "@/lib/constants";
import { timeAgo } from "@/lib/utils";

export default async function TenantDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  await requireSuperAdmin();
  const { id } = await params;

  const tenant = await prisma.tenant.findUnique({
    where: { id },
    include: {
      users: { orderBy: [{ role: "asc" }, { name: "asc" }] },
      statuses: { orderBy: { order: "asc" } },
      priorities: { orderBy: { order: "asc" } },
      tickets: {
        orderBy: { createdAt: "desc" },
        take: 15,
        include: { requester: { select: { name: true, avatarColor: true } } },
      },
      _count: { select: { tickets: true, users: true } },
    },
  });

  if (!tenant) notFound();

  const statusOf = (key: string) =>
    tenant.statuses.find((s) => s.key === key) ?? {
      label: key,
      color: "#94a3b8",
    };
  const priorityOf = (key: string) =>
    tenant.priorities.find((p) => p.key === key) ?? {
      label: key,
      color: "#94a3b8",
    };

  return (
    <div>
      <Link
        href="/platform"
        className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"
      >
        <ArrowLeft size={16} /> Back to platform
      </Link>

      <div className="flex flex-wrap items-center gap-4">
        <span className="grid h-14 w-14 place-items-center rounded-xl bg-brand/10 text-lg font-bold text-brand">
          {tenant.name.slice(0, 2).toUpperCase()}
        </span>
        <div>
          <h1 className="text-2xl font-bold tracking-tight">{tenant.name}</h1>
          <p className="text-sm text-muted">
            /{tenant.slug} · {tenant._count.users} users · {tenant._count.tickets} tickets
          </p>
        </div>
        <form action={enterTenant} className="ml-auto">
          <input type="hidden" name="tenantId" value={tenant.id} />
          <Button type="submit">
            <LogIn size={16} /> Enter board
          </Button>
        </form>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        <Card>
          <CardHeader title="Recent tickets" />
          <div className="divide-y divide-border">
            {tenant.tickets.map((t) => (
              <div key={t.id} className="flex items-center gap-3 px-5 py-3">
                <Avatar name={t.requester.name} color={t.requester.avatarColor} size={32} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{t.subject}</p>
                  <p className="text-xs text-muted">
                    #{t.number} · {timeAgo(t.createdAt)}
                  </p>
                </div>
                <PriorityBadge label={priorityOf(t.priority).label} color={priorityOf(t.priority).color} />
                <StatusBadge label={statusOf(t.status).label} color={statusOf(t.status).color} />
              </div>
            ))}
            {tenant.tickets.length === 0 && (
              <p className="px-5 py-10 text-center text-sm text-muted">No tickets.</p>
            )}
          </div>
        </Card>

        <Card>
          <CardHeader title="Users" />
          <div className="divide-y divide-border">
            {tenant.users.map((u) => (
              <div key={u.id} className="flex items-center gap-3 px-5 py-3">
                <Avatar name={u.name} color={u.avatarColor} size={34} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{u.name}</p>
                  <p className="truncate text-xs text-muted">{u.email}</p>
                </div>
                <span className="rounded bg-surface-2 px-2 py-0.5 text-xs text-muted">
                  {ROLE_LABELS[u.role as Role] ?? u.role}
                </span>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <p className="mt-4 text-xs text-muted">
        Read-only view. Tenant data is fully isolated from other tenants.
      </p>
    </div>
  );
}
