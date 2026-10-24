"use client";

import { useRouter } from "next/navigation";
import { useTransition } from "react";
import { Bell, CheckCheck } from "lucide-react";
import { Dropdown } from "@/components/ui/Dropdown";
import { cn, timeAgo } from "@/lib/utils";
import {
  markNotificationRead,
  markAllNotificationsRead,
} from "@/lib/actions/notifications";

export type NotificationItem = {
  id: string;
  message: string;
  read: boolean;
  createdAt: string;
  ticketId: string | null;
};

export function NotificationsButton({
  notifications,
}: {
  notifications: NotificationItem[];
}) {
  const router = useRouter();
  const [, start] = useTransition();
  const unread = notifications.filter((n) => !n.read).length;

  return (
    <Dropdown
      width={340}
      trigger={({ toggle }) => (
        <button
          onClick={toggle}
          aria-label="Notifications"
          className="relative inline-flex h-9 w-9 items-center justify-center rounded-lg text-muted hover:bg-surface-2 hover:text-fg transition-colors cursor-pointer"
        >
          <Bell size={18} />
          {unread > 0 && (
            <span className="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
              {unread > 9 ? "9+" : unread}
            </span>
          )}
        </button>
      )}
    >
      {(close) => (
        <div>
          <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
            <p className="text-sm font-semibold">Notifications</p>
            {unread > 0 && (
              <button
                onClick={() => start(() => markAllNotificationsRead())}
                className="flex items-center gap-1 text-xs text-brand hover:underline cursor-pointer"
              >
                <CheckCheck size={14} /> Mark all read
              </button>
            )}
          </div>
          <div className="max-h-96 overflow-y-auto">
            {notifications.length === 0 ? (
              <p className="px-4 py-8 text-center text-sm text-muted">
                You&apos;re all caught up 🎉
              </p>
            ) : (
              notifications.map((n) => (
                <button
                  key={n.id}
                  onClick={() => {
                    start(async () => {
                      await markNotificationRead(n.id);
                      if (n.ticketId) router.push(`/tickets/${n.ticketId}`);
                    });
                    close();
                  }}
                  className={cn(
                    "flex w-full gap-3 border-b border-border px-4 py-3 text-left hover:bg-surface-2 transition-colors cursor-pointer",
                    !n.read && "bg-brand/5",
                  )}
                >
                  <span
                    className={cn(
                      "mt-1.5 h-2 w-2 shrink-0 rounded-full",
                      n.read ? "bg-transparent" : "bg-brand",
                    )}
                  />
                  <span className="min-w-0">
                    <span className="block text-sm text-fg">{n.message}</span>
                    <span className="mt-0.5 block text-xs text-muted">
                      {timeAgo(n.createdAt)}
                    </span>
                  </span>
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </Dropdown>
  );
}
