import { requireTenantUser } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { getUserPrefs } from "@/lib/notify";
import { getTenantPriorities } from "@/lib/priorities";
import { PageHeader } from "@/components/app/PageHeader";
import { NotificationPrefsForm } from "@/components/app/NotificationPrefsForm";

export default async function NotificationsPage() {
  const { user, tenantId } = await requireTenantUser();
  const [prefs, priorities, devices] = await Promise.all([
    getUserPrefs(user.id, tenantId),
    getTenantPriorities(tenantId),
    prisma.pushSubscription.findMany({
      where: { userId: user.id },
      orderBy: { createdAt: "desc" },
      select: { id: true, userAgent: true, createdAt: true },
    }),
  ]);

  return (
    <div>
      <PageHeader
        title="Notifications"
        description="Choose how and when you're notified."
      />
      <NotificationPrefsForm
        prefs={prefs}
        priorities={priorities.map((p) => ({ key: p.key, label: p.label }))}
        devices={devices.map((d) => ({
          id: d.id,
          userAgent: d.userAgent,
          createdAt: d.createdAt.toISOString(),
        }))}
      />
    </div>
  );
}
