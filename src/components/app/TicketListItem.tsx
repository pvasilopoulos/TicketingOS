import Link from "next/link";
import { MessageSquare } from "lucide-react";
import { Avatar } from "@/components/ui/Avatar";
import { StatusBadge, PriorityBadge, DueBadge } from "@/components/ui/Badge";
import { timeAgo } from "@/lib/utils";

export type TicketRow = {
  id: string;
  number: number;
  subject: string;
  status: { key: string; label: string; color: string; isClosed: boolean };
  priority: { key: string; label: string; color: string };
  createdAt: string;
  dueAt: string | null;
  commentCount: number;
  requester: { name: string; color: string };
  assignee: { name: string; color: string } | null;
  category: { name: string; color: string } | null;
};

export function TicketListItem({ ticket }: { ticket: TicketRow }) {
  return (
    <Link
      href={`/tickets/${ticket.id}`}
      className="flex items-center gap-4 px-5 py-3.5 hover:bg-surface-2 transition-colors"
    >
      <Avatar name={ticket.requester.name} color={ticket.requester.color} size={38} />

      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="truncate font-medium text-fg">{ticket.subject}</span>
        </div>
        <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
          <span className="font-mono">#{ticket.number}</span>
          <span>·</span>
          <span>{ticket.requester.name}</span>
          {ticket.category && (
            <>
              <span>·</span>
              <span className="inline-flex items-center gap-1">
                <span
                  className="h-2 w-2 rounded-full"
                  style={{ backgroundColor: ticket.category.color }}
                />
                {ticket.category.name}
              </span>
            </>
          )}
          <span>·</span>
          <span>{timeAgo(ticket.createdAt)}</span>
          {ticket.commentCount > 0 && (
            <span className="inline-flex items-center gap-1">
              <MessageSquare size={12} />
              {ticket.commentCount}
            </span>
          )}
        </div>
      </div>

      <div className="hidden items-center gap-2 sm:flex">
        <DueBadge dueAt={ticket.dueAt} isClosed={ticket.status.isClosed} />
        <PriorityBadge label={ticket.priority.label} color={ticket.priority.color} />
        <StatusBadge label={ticket.status.label} color={ticket.status.color} />
      </div>

      {ticket.assignee ? (
        <Avatar
          name={ticket.assignee.name}
          color={ticket.assignee.color}
          size={30}
          className="hidden md:inline-flex"
        />
      ) : (
        <span className="hidden h-[30px] w-[30px] rounded-full border border-dashed border-border md:inline-block" />
      )}
    </Link>
  );
}
