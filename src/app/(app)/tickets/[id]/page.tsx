import Link from "next/link";
import { notFound } from "next/navigation";
import { ArrowLeft, Lock } from "lucide-react";
import { requireTenantUser, isStaff } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { Card, CardHeader } from "@/components/ui/Card";
import { Avatar } from "@/components/ui/Avatar";
import { StatusBadge, PriorityBadge, DueBadge } from "@/components/ui/Badge";
import { ReplyForm } from "@/components/app/ReplyForm";
import { TicketSidebar } from "@/components/app/TicketSidebar";
import { AttachmentList, type AttachmentView } from "@/components/app/AttachmentList";
import { TicketWatchers } from "@/components/app/TicketWatchers";
import { TicketTags } from "@/components/app/TicketTags";
import { getTenantStatuses, resolveStatus } from "@/lib/statuses";
import { getTenantPriorities, resolvePriority } from "@/lib/priorities";
import { cn, formatDate, timeAgo } from "@/lib/utils";
import { ROLE_LABELS, type Role } from "@/lib/constants";

export default async function TicketDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const { user, tenantId } = await requireTenantUser();
  const staff = isStaff(user.role);

  const ticket = await prisma.ticket.findFirst({
    where: { id, tenantId },
    include: {
      requester: true,
      assignee: true,
      category: true,
      tags: { include: { tag: true } },
      attachments: { orderBy: { createdAt: "asc" } },
      watchers: { include: { user: true }, orderBy: { createdAt: "asc" } },
      events: {
        orderBy: { createdAt: "asc" },
        include: { actor: { select: { name: true, avatarColor: true } } },
      },
      comments: {
        orderBy: { createdAt: "asc" },
        include: { author: true, attachments: { orderBy: { createdAt: "asc" } } },
      },
    },
  });

  if (!ticket) notFound();

  const myWatch = ticket.watchers.find((w) => w.userId === user.id);
  const isWatcher = Boolean(myWatch);
  // Customers may view a ticket only if they requested it or are on its team.
  if (!staff && ticket.requesterId !== user.id && !isWatcher) notFound();

  const visibleComments = ticket.comments.filter((c) => staff || !c.isInternal);
  // Staff, the requester, or a COLLABORATOR team member may manage the ticket.
  const canManage =
    staff || ticket.requesterId === user.id || myWatch?.role === "COLLABORATOR";

  const [statuses, priorities] = await Promise.all([
    getTenantStatuses(tenantId),
    getTenantPriorities(tenantId),
  ]);
  const currentStatus = resolveStatus(statuses, ticket.status);
  const currentPriority = resolvePriority(priorities, ticket.priority);

  const allTags = await prisma.tag.findMany({
    where: { tenantId },
    orderBy: { name: "asc" },
    select: { id: true, name: true },
  });

  // Canned responses are an agent tool.
  const canned = staff
    ? await prisma.cannedResponse.findMany({
        where: { tenantId },
        orderBy: { title: "asc" },
        select: { title: true, body: true },
      })
    : [];

  // Users that can be @mentioned in replies.
  const mentionUsers = (
    await prisma.user.findMany({
      where: { tenantId, role: { not: "SUPER_ADMIN" } },
      orderBy: { name: "asc" },
      select: { id: true, name: true },
    })
  ).map((u) => ({ id: u.id, label: u.name }));

  // Merge replies and activity events into one chronological timeline.
  type TLItem =
    | { kind: "comment"; at: Date; comment: (typeof visibleComments)[number] }
    | { kind: "event"; at: Date; event: (typeof ticket.events)[number] };
  const timeline: TLItem[] = [
    ...visibleComments.map((c) => ({ kind: "comment" as const, at: c.createdAt, comment: c })),
    ...ticket.events
      .filter((e) => e.type !== "CREATED")
      .map((e) => ({ kind: "event" as const, at: e.createdAt, event: e })),
  ].sort((a, b) => a.at.getTime() - b.at.getTime());

  const [agents, categories] = canManage
    ? await Promise.all([
        prisma.user.findMany({
          where: { tenantId, role: { in: ["ADMIN", "AGENT"] } },
          orderBy: { name: "asc" },
          select: { id: true, name: true },
        }),
        prisma.category.findMany({
          where: { tenantId },
          orderBy: { name: "asc" },
          select: { id: true, name: true },
        }),
      ])
    : [[], []];

  // Candidate users to add to the team: everyone in the tenant except the
  // requester and anyone already on the team.
  const excluded = new Set<string>([
    ticket.requesterId,
    ...ticket.watchers.map((w) => w.userId),
  ]);
  const watcherCandidates = canManage
    ? (
        await prisma.user.findMany({
          where: { tenantId, role: { not: "SUPER_ADMIN" } },
          orderBy: { name: "asc" },
          select: { id: true, name: true, role: true },
        })
      ).filter((u) => !excluded.has(u.id))
    : [];

  return (
    <div>
      <Link
        href="/tickets"
        className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"
      >
        <ArrowLeft size={16} /> Back to tickets
      </Link>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-2 text-sm text-muted">
            <span className="font-mono">#{ticket.number}</span>
            <span>·</span>
            <span>opened {timeAgo(ticket.createdAt)}</span>
          </div>
          <h1 className="mt-1 text-2xl font-bold tracking-tight">{ticket.subject}</h1>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <DueBadge dueAt={ticket.dueAt} isClosed={currentStatus.isClosed} />
          <PriorityBadge label={currentPriority.label} color={currentPriority.color} />
          <StatusBadge label={currentStatus.label} color={currentStatus.color} />
        </div>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_300px]">
        {/* Conversation */}
        <div className="space-y-4">
          {/* Original request */}
          <Message
            name={ticket.requester.name}
            color={ticket.requester.avatarColor}
            role={ticket.requester.role}
            date={ticket.createdAt}
            body={ticket.description}
            attachments={ticket.attachments.filter((a) => a.commentId === null)}
            isRequester
          />

          {timeline.map((item) =>
            item.kind === "comment" ? (
              <Message
                key={`c-${item.comment.id}`}
                name={item.comment.author.name}
                color={item.comment.author.avatarColor}
                role={item.comment.author.role}
                date={item.comment.createdAt}
                body={item.comment.body}
                attachments={item.comment.attachments}
                internal={item.comment.isInternal}
                isRequester={item.comment.authorId === ticket.requesterId}
              />
            ) : (
              <EventLine
                key={`e-${item.event.id}`}
                name={item.event.actor?.name ?? "Automation"}
                color={item.event.actor?.avatarColor ?? "#94a3b8"}
                summary={item.event.summary}
                date={item.event.createdAt}
              />
            ),
          )}

          <div className="pt-2">
            <ReplyForm ticketId={ticket.id} canInternal={staff} mentionUsers={mentionUsers} canned={canned} />
          </div>
        </div>

        {/* Sidebar */}
        <div className="space-y-6">
          <Card className="p-5">
            {canManage ? (
              <TicketSidebar
                ticketId={ticket.id}
                status={ticket.status}
                priority={ticket.priority}
                assigneeId={ticket.assigneeId}
                categoryId={ticket.categoryId}
                agents={agents as { id: string; name: string }[]}
                categories={categories as { id: string; name: string }[]}
                statuses={statuses.map((s) => ({ key: s.key, label: s.label }))}
                priorities={priorities.map((p) => ({ key: p.key, label: p.label }))}
              />
            ) : (
              <div className="space-y-3 text-sm">
                <h3 className="text-sm font-semibold">Details</h3>
                <Row label="Status"><StatusBadge label={currentStatus.label} color={currentStatus.color} /></Row>
                <Row label="Priority"><PriorityBadge label={currentPriority.label} color={currentPriority.color} /></Row>
                <Row label="Category">
                  {ticket.category ? ticket.category.name : "—"}
                </Row>
                <Row label="Assigned to">
                  {ticket.assignee ? ticket.assignee.name : "Unassigned"}
                </Row>
              </div>
            )}
          </Card>

          <Card className="p-5">
            <TicketWatchers
              ticketId={ticket.id}
              canManage={canManage}
              watchers={ticket.watchers.map((w) => ({
                id: w.user.id,
                name: w.user.name,
                color: w.user.avatarColor,
                tenantRole: w.user.role,
                watchRole: w.role,
              }))}
              candidates={watcherCandidates}
            />
          </Card>

          <Card>
            <CardHeader title="Requester" />
            <div className="flex items-center gap-3 p-5">
              <Avatar name={ticket.requester.name} color={ticket.requester.avatarColor} size={42} />
              <div className="min-w-0">
                <p className="truncate font-medium">{ticket.requester.name}</p>
                <p className="truncate text-xs text-muted">{ticket.requester.email}</p>
              </div>
            </div>
            <div className="space-y-2 border-t border-border px-5 py-4 text-sm">
              <Row label="Created">{formatDate(ticket.createdAt)}</Row>
              <Row label="Updated">{timeAgo(ticket.updatedAt)}</Row>
              {ticket.closedAt && <Row label="Closed">{timeAgo(ticket.closedAt)}</Row>}
            </div>
          </Card>

          <Card className="p-5">
            <TicketTags
              ticketId={ticket.id}
              canManage={canManage}
              tags={ticket.tags.map((t) => ({ id: t.tag.id, name: t.tag.name }))}
              allTags={allTags}
            />
          </Card>
        </div>
      </div>
    </div>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <span className="text-muted">{label}</span>
      <span className="text-right font-medium">{children}</span>
    </div>
  );
}

function EventLine({
  name,
  color,
  summary,
  date,
}: {
  name: string;
  color: string;
  summary: string;
  date: Date;
}) {
  return (
    <div className="flex items-center gap-2.5 px-1 py-0.5 text-xs text-muted">
      <Avatar name={name} color={color} size={22} />
      <p className="min-w-0">
        <span className="font-medium text-fg">{name}</span> {summary}
        <span className="text-muted"> · {timeAgo(date)}</span>
      </p>
    </div>
  );
}

function Message({
  name,
  color,
  role,
  date,
  body,
  attachments = [],
  internal = false,
  isRequester = false,
}: {
  name: string;
  color: string;
  role: string;
  date: Date;
  body: string;
  attachments?: AttachmentView[];
  internal?: boolean;
  isRequester?: boolean;
}) {
  return (
    <div className="flex gap-3">
      <Avatar name={name} color={color} size={38} />
      <div
        className={cn(
          "min-w-0 flex-1 rounded-xl border p-4",
          internal
            ? "border-amber-300 dark:border-amber-500/40 bg-amber-50/70 dark:bg-amber-500/5"
            : "border-border bg-surface",
        )}
      >
        <div className="mb-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
          <span className="font-semibold">{name}</span>
          {!isRequester && (
            <span className="rounded bg-brand/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-brand">
              {ROLE_LABELS[role as Role] ?? role}
            </span>
          )}
          {internal && (
            <span className="inline-flex items-center gap-1 rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-amber-700 dark:text-amber-300">
              <Lock size={10} /> Internal
            </span>
          )}
          <span className="text-xs text-muted">· {timeAgo(date)}</span>
        </div>
        <div className="rich" dangerouslySetInnerHTML={{ __html: body }} />
        {attachments.length > 0 && (
          <AttachmentList
            items={attachments.map((a) => ({
              id: a.id,
              filename: a.filename,
              size: a.size,
              mime: a.mime,
              url: a.url,
            }))}
          />
        )}
      </div>
    </div>
  );
}
