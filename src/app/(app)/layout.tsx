import { requireTenantUser } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { AppShell } from "@/components/app/AppShell";

export default async function AppLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const { user, tenantId, impersonating } = await requireTenantUser();

  const [notifications, tenant] = await Promise.all([
    prisma.notification.findMany({
      where: { userId: user.id, tenantId },
      orderBy: { createdAt: "desc" },
      take: 15,
    }),
    prisma.tenant.findUnique({ where: { id: tenantId } }),
  ]);

  return (
    <AppShell
      user={{
        name: user.name,
        email: user.email,
        role: user.role,
        color: user.avatarColor,
        tenantName: tenant?.name ?? null,
      }}
      impersonating={impersonating}
      notifications={notifications.map((n) => ({
        id: n.id,
        message: n.message,
        read: n.read,
        createdAt: n.createdAt.toISOString(),
        ticketId: n.ticketId,
      }))}
    >
      {children}
    </AppShell>
  );
}
