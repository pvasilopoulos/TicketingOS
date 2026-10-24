import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { requireTenantUser } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { getTenantPriorities, defaultPriorityKey } from "@/lib/priorities";
import { Card } from "@/components/ui/Card";
import { NewTicketForm } from "@/components/app/NewTicketForm";

export default async function NewTicketPage() {
  const { user, tenantId } = await requireTenantUser();
  const [categories, people, priorities] = await Promise.all([
    prisma.category.findMany({ where: { tenantId }, orderBy: { name: "asc" } }),
    prisma.user.findMany({
      where: { tenantId, role: { not: "SUPER_ADMIN" }, id: { not: user.id } },
      orderBy: { name: "asc" },
      select: { id: true, name: true, role: true },
    }),
    getTenantPriorities(tenantId),
  ]);

  return (
    <div className="mx-auto max-w-2xl">
      <Link
        href="/tickets"
        className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"
      >
        <ArrowLeft size={16} /> Back to tickets
      </Link>
      <h1 className="text-2xl font-bold tracking-tight">New ticket</h1>
      <p className="mt-1 text-sm text-muted">
        Tell us what you need help with. An agent will pick it up shortly.
      </p>

      <Card className="mt-6 p-6">
        <NewTicketForm
          categories={categories.map((c) => ({ id: c.id, name: c.name }))}
          people={people}
          priorities={priorities.map((p) => ({ key: p.key, label: p.label }))}
          defaultPriority={defaultPriorityKey(priorities)}
        />
      </Card>
    </div>
  );
}
