import { requireTenantAdmin } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { isEncrypted } from "@/lib/crypto";
import { getTenantStatuses } from "@/lib/statuses";
import { getTenantPriorities } from "@/lib/priorities";
import { getTenantSla } from "@/lib/sla";
import { PageHeader } from "@/components/app/PageHeader";
import { SettingsManager } from "@/components/app/SettingsManager";
import { StatusManager } from "@/components/app/StatusManager";
import { PriorityManager } from "@/components/app/PriorityManager";
import { SlaManager } from "@/components/app/SlaManager";
import { TagsManager } from "@/components/app/TagsManager";
import { CannedManager } from "@/components/app/CannedManager";
import { AutomationManager } from "@/components/app/AutomationManager";
import { TenantPolicyManager } from "@/components/app/TenantPolicyManager";
import { getTenantPolicy } from "@/lib/notify";
import { EmailSettingsManager } from "@/components/app/EmailSettingsManager";

export default async function SettingsPage() {
  const { tenantId } = await requireTenantAdmin();
  const [tenant, categories, email, statuses, statusCounts, sla] = await Promise.all([
    prisma.tenant.findUnique({ where: { id: tenantId } }),
    prisma.category.findMany({ where: { tenantId }, orderBy: { name: "asc" } }),
    prisma.emailSetting.findUnique({ where: { tenantId } }),
    getTenantStatuses(tenantId),
    prisma.ticket.groupBy({ by: ["status"], where: { tenantId }, _count: true }),
    getTenantSla(tenantId),
  ]);

  const [tags, priorities, priorityCounts] = await Promise.all([
    prisma.tag.findMany({
      where: { tenantId },
      orderBy: { name: "asc" },
      include: { _count: { select: { tickets: true } } },
    }),
    getTenantPriorities(tenantId),
    prisma.ticket.groupBy({ by: ["priority"], where: { tenantId }, _count: true }),
  ]);
  const prioUse = new Map(priorityCounts.map((c) => [c.priority, c._count as number]));

  const [canned, rules, agents, policy] = await Promise.all([
    prisma.cannedResponse.findMany({ where: { tenantId }, orderBy: { title: "asc" }, select: { id: true, title: true, body: true } }),
    prisma.automationRule.findMany({ where: { tenantId }, orderBy: [{ order: "asc" }, { createdAt: "asc" }] }),
    prisma.user.findMany({ where: { tenantId, role: { in: ["ADMIN", "AGENT"] } }, orderBy: { name: "asc" }, select: { id: true, name: true } }),
    getTenantPolicy(tenantId),
  ]);

  const useMap = new Map(statusCounts.map((c) => [c.status, c._count as number]));

  return (
    <div className="space-y-6">
      <PageHeader title="Settings" description="Configure your workspace." />

      <SettingsManager
        workspaceName={tenant?.name ?? ""}
        slug={tenant?.slug ?? ""}
        categories={categories.map((c) => ({ id: c.id, name: c.name, color: c.color }))}
      />

      <StatusManager
        count={statuses.length}
        statuses={statuses.map((s) => ({
          id: s.id,
          key: s.key,
          label: s.label,
          color: s.color,
          isClosed: s.isClosed,
          inUse: useMap.get(s.key) ?? 0,
        }))}
      />

      <PriorityManager
        count={priorities.length}
        priorities={priorities.map((p) => ({
          id: p.id,
          key: p.key,
          label: p.label,
          color: p.color,
          inUse: prioUse.get(p.key) ?? 0,
        }))}
      />

      <SlaManager
        targets={sla}
        priorities={priorities.map((p) => ({ key: p.key, label: p.label, color: p.color }))}
      />

      <TagsManager
        tags={tags.map((t) => ({ id: t.id, name: t.name, count: t._count.tickets }))}
      />

      <CannedManager items={canned} />

      <AutomationManager
        rules={rules.map((r) => ({
          id: r.id,
          name: r.name,
          enabled: r.enabled,
          conditionField: r.conditionField,
          conditionValue: r.conditionValue,
          actionType: r.actionType,
          actionValue: r.actionValue,
        }))}
        priorities={priorities.map((p) => ({ value: p.key, label: p.label }))}
        categories={categories.map((c) => ({ value: c.id, label: c.name }))}
        statuses={statuses.map((s) => ({ value: s.key, label: s.label }))}
        agents={agents.map((a) => ({ value: a.id, label: a.name }))}
        tags={tags.map((t) => ({ value: t.id, label: t.name }))}
      />

      <TenantPolicyManager policy={policy} />

      {/* Secrets are never sent to the client — only whether they are set. */}
      <EmailSettingsManager
        settings={{
          enabled: email?.enabled ?? false,
          provider: (email?.provider as "SMTP" | "MICROSOFT365") ?? "SMTP",
          fromName: email?.fromName ?? "",
          fromEmail: email?.fromEmail ?? "",
          smtpHost: email?.smtpHost ?? "",
          smtpPort: email?.smtpPort ? String(email.smtpPort) : "",
          smtpSecure: email?.smtpSecure ?? true,
          smtpUser: email?.smtpUser ?? "",
          smtpPasswordSet: isEncrypted(email?.smtpPassword),
          msTenantId: email?.msTenantId ?? "",
          msClientId: email?.msClientId ?? "",
          msClientSecretSet: isEncrypted(email?.msClientSecret),
          msSender: email?.msSender ?? "",
        }}
      />
    </div>
  );
}
