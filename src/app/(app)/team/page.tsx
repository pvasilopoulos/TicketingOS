import { requireTenantAdmin } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { PageHeader } from "@/components/app/PageHeader";
import { TeamManager } from "@/components/app/TeamManager";

export default async function TeamPage() {
  const { user, tenantId } = await requireTenantAdmin();

  const members = await prisma.user.findMany({
    where: { tenantId },
    orderBy: [{ role: "asc" }, { name: "asc" }],
    include: { _count: { select: { assignedTickets: true } } },
  });

  return (
    <div>
      <PageHeader
        title="Team"
        description="Manage who can access your workspace and what they can do."
      />
      <TeamManager
        currentUserId={user.id}
        members={members.map((m) => ({
          id: m.id,
          name: m.name,
          email: m.email,
          role: m.role,
          color: m.avatarColor,
          ticketCount: m._count.assignedTickets,
        }))}
      />
    </div>
  );
}
