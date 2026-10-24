import Link from "next/link";
import { Prisma } from "@prisma/client";
import { PlusCircle, Inbox, List, LayoutGrid } from "lucide-react";
import { requireTenantUser, isStaff } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { PageHeader } from "@/components/app/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { TicketFilters } from "@/components/app/TicketFilters";
import { TicketListItem } from "@/components/app/TicketListItem";
import { TicketBoard } from "@/components/app/TicketBoard";
import { BulkList } from "@/components/app/BulkList";
import { SavedViews } from "@/components/app/SavedViews";
import { getTenantStatuses, resolveStatus } from "@/lib/statuses";
import { getTenantPriorities, resolvePriority } from "@/lib/priorities";
import { cn } from "@/lib/utils";

export default async function TicketsPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const { user, tenantId } = await requireTenantUser();
  const staff = isStaff(user.role);
  const sp = await searchParams;
  const view = sp.view === "board" ? "board" : "list";

  const [statuses, priorities] = await Promise.all([
    getTenantStatuses(tenantId),
    getTenantPriorities(tenantId),
  ]);
  const closedKeys = statuses.filter((s) => s.isClosed).map((s) => s.key);

  const where: Prisma.TicketWhereInput = { tenantId };
  const and: Prisma.TicketWhereInput[] = [];
  // Customers see tickets they requested or watch; staff see the whole board.
  if (!staff) {
    and.push({
      OR: [
        { requesterId: user.id },
        { watchers: { some: { userId: user.id } } },
      ],
    });
  }
  if (sp.status) where.status = sp.status;
  if (sp.priority) where.priority = sp.priority;
  if (sp.category) where.categoryId = sp.category;
  if (sp.tag) where.tags = { some: { tagId: sp.tag } };
  if (sp.overdue === "1") {
    and.push({ dueAt: { lt: new Date() }, status: { notIn: closedKeys } });
  }
  if (sp.q) {
    const q = sp.q.trim();
    const asNumber = Number(q.replace("#", ""));
    and.push({
      OR: [
        { subject: { contains: q } },
        ...(Number.isFinite(asNumber) && asNumber > 0 ? [{ number: asNumber }] : []),
      ],
    });
  }
  if (and.length) where.AND = and;

  const [tickets, categories] = await Promise.all([
    prisma.ticket.findMany({
      where,
      orderBy: { createdAt: "desc" },
      include: {
        requester: { select: { name: true, avatarColor: true } },
        assignee: { select: { name: true, avatarColor: true } },
        category: { select: { name: true, color: true } },
        _count: { select: { comments: true } },
      },
    }),
    prisma.category.findMany({ where: { tenantId }, orderBy: { name: "asc" } }),
  ]);

  const [tags, savedViews, agents] = await Promise.all([
    prisma.tag.findMany({ where: { tenantId }, orderBy: { name: "asc" }, select: { id: true, name: true } }),
    prisma.savedView.findMany({ where: { userId: user.id }, orderBy: { createdAt: "asc" }, select: { id: true, name: true, query: true } }),
    staff
      ? prisma.user.findMany({ where: { tenantId, role: { in: ["ADMIN", "AGENT"] } }, orderBy: { name: "asc" }, select: { id: true, name: true } })
      : Promise.resolve([] as { id: string; name: string }[]),
  ]);

  const rows = tickets.map((t) => {
    const s = resolveStatus(statuses, t.status);
    const p = resolvePriority(priorities, t.priority);
    return {
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
    };
  });

  // View toggle links preserve the current filters.
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(sp)) {
    if (v && k !== "view") params.set(k, v);
  }
  const qs = params.toString();
  const listHref = `/tickets${qs ? `?${qs}` : ""}`;
  const boardHref = `/tickets?${qs ? qs + "&" : ""}view=board`;

  const toggle = (
    <div className="inline-flex rounded-lg border border-border bg-surface p-0.5">
      <Link
        href={listHref}
        className={cn(
          "inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors",
          view === "list" ? "bg-surface-2 text-fg" : "text-muted hover:text-fg",
        )}
      >
        <List size={15} /> List
      </Link>
      <Link
        href={boardHref}
        className={cn(
          "inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors",
          view === "board" ? "bg-surface-2 text-fg" : "text-muted hover:text-fg",
        )}
      >
        <LayoutGrid size={15} /> Board
      </Link>
    </div>
  );

  return (
    <div>
      <PageHeader
        title="Tickets"
        description={staff ? "All support requests for your workspace." : "Your support requests."}
        action={
          <Link href="/tickets/new">
            <Button>
              <PlusCircle size={18} /> New ticket
            </Button>
          </Link>
        }
      />

      <SavedViews views={savedViews} />

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <TicketFilters
          categories={categories.map((c) => ({ id: c.id, name: c.name }))}
          statuses={statuses.map((s) => ({ key: s.key, label: s.label }))}
          priorities={priorities.map((p) => ({ key: p.key, label: p.label }))}
          tags={tags}
        />
        {toggle}
      </div>

      {rows.length === 0 ? (
        <Card>
          <div className="flex flex-col items-center justify-center px-5 py-20 text-center">
            <Inbox size={40} className="text-muted" />
            <p className="mt-3 font-medium">No tickets found</p>
            <p className="mt-1 text-sm text-muted">
              Try adjusting your filters, or create a new ticket.
            </p>
          </div>
        </Card>
      ) : view === "board" ? (
        <TicketBoard
          tickets={rows}
          statuses={statuses.map((s) => ({
            key: s.key,
            label: s.label,
            color: s.color,
            isClosed: s.isClosed,
          }))}
          canManage={staff}
        />
      ) : staff ? (
        <>
          <BulkList
            rows={rows}
            statuses={statuses.map((s) => ({ key: s.key, label: s.label }))}
            priorities={priorities.map((p) => ({ key: p.key, label: p.label }))}
            agents={agents}
          />
          <p className="mt-3 text-center text-xs text-muted">
            {rows.length} {rows.length === 1 ? "ticket" : "tickets"}
          </p>
        </>
      ) : (
        <>
          <Card>
            <div className="divide-y divide-border">
              {rows.map((t) => (
                <TicketListItem key={t.id} ticket={t} />
              ))}
            </div>
          </Card>
          <p className="mt-3 text-center text-xs text-muted">
            {rows.length} {rows.length === 1 ? "ticket" : "tickets"}
          </p>
        </>
      )}
    </div>
  );
}
