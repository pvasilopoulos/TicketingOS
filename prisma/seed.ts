import { PrismaClient } from "@prisma/client";
import bcrypt from "bcryptjs";

const prisma = new PrismaClient();

const AVATAR_COLORS = [
  "#6366f1", "#ec4899", "#f59e0b", "#10b981",
  "#3b82f6", "#8b5cf6", "#ef4444", "#14b8a6",
];

async function seedTenant(
  pw: string,
  cfg: {
    name: string;
    slug: string;
    adminEmail: string;
    agents: { name: string; email: string }[];
    customers: { name: string; email: string }[];
  },
) {
  const tenant = await prisma.tenant.create({
    data: { name: cfg.name, slug: cfg.slug },
  });

  await prisma.ticketStatus.createMany({
    data: [
      { key: "OPEN", label: "Open", color: "#3b82f6", isClosed: false, order: 0 },
      { key: "PENDING", label: "Pending", color: "#f59e0b", isClosed: false, order: 1 },
      { key: "RESOLVED", label: "Resolved", color: "#10b981", isClosed: true, order: 2 },
      { key: "CLOSED", label: "Closed", color: "#94a3b8", isClosed: true, order: 3 },
    ].map((s) => ({ ...s, tenantId: tenant.id })),
  });

  await prisma.ticketPriority.createMany({
    data: [
      { key: "LOW", label: "Low", color: "#94a3b8", order: 0 },
      { key: "MEDIUM", label: "Medium", color: "#0ea5e9", order: 1 },
      { key: "HIGH", label: "High", color: "#f97316", order: 2 },
      { key: "URGENT", label: "Urgent", color: "#ef4444", order: 3 },
    ].map((p) => ({ ...p, tenantId: tenant.id })),
  });

  await prisma.cannedResponse.createMany({
    data: [
      {
        title: "Ask for more details",
        body: "<p>Thanks for reaching out! To help you faster, could you share a few more details — steps to reproduce, screenshots, and when it started?</p>",
      },
      {
        title: "Resolved — closing",
        body: "<p>Glad we could help! I'm marking this as resolved. Feel free to reply if anything else comes up. 🙂</p>",
      },
    ].map((c) => ({ ...c, tenantId: tenant.id })),
  });

  const SLA: Record<string, number> = { URGENT: 4, HIGH: 24, MEDIUM: 72, LOW: 168 };
  await prisma.slaTarget.createMany({
    data: Object.entries(SLA).map(([priority, resolveHours]) => ({
      tenantId: tenant.id,
      priority,
      resolveHours,
    })),
  });

  const admin = await prisma.user.create({
    data: {
      name: `${cfg.name} Admin`,
      email: cfg.adminEmail,
      passwordHash: pw,
      role: "ADMIN",
      avatarColor: AVATAR_COLORS[0],
      tenantId: tenant.id,
    },
  });

  const agents = await Promise.all(
    cfg.agents.map((a, i) =>
      prisma.user.create({
        data: {
          ...a,
          passwordHash: pw,
          role: "AGENT",
          avatarColor: AVATAR_COLORS[(i + 1) % AVATAR_COLORS.length],
          tenantId: tenant.id,
        },
      }),
    ),
  );

  const customers = await Promise.all(
    cfg.customers.map((c, i) =>
      prisma.user.create({
        data: {
          ...c,
          passwordHash: pw,
          role: "CUSTOMER",
          avatarColor: AVATAR_COLORS[(i + 4) % AVATAR_COLORS.length],
          tenantId: tenant.id,
        },
      }),
    ),
  );

  const categories = await Promise.all(
    [
      { name: "Billing", color: "#f59e0b" },
      { name: "Technical", color: "#3b82f6" },
      { name: "Account", color: "#8b5cf6" },
      { name: "Feature Request", color: "#10b981" },
      { name: "General", color: "#64748b" },
    ].map((c) => prisma.category.create({ data: { ...c, tenantId: tenant.id } })),
  );

  const tags = await Promise.all(
    ["bug", "urgent", "mobile", "payment", "ui", "integration"].map((name) =>
      prisma.tag.create({ data: { name, tenantId: tenant.id } }),
    ),
  );

  const seedTickets = [
    {
      subject: "Unable to log in after password reset",
      description:
        "I reset my password via the email link but the new password is not accepted on the login page. I keep getting 'invalid credentials'.",
      status: "OPEN", priority: "HIGH",
      requester: customers[0], assignee: agents[0], category: categories[2], tags: [tags[0]],
    },
    {
      subject: "Invoice shows wrong VAT amount",
      description:
        "The invoice for October lists 24% VAT but our account is set to the reduced 13% rate. Please correct and reissue.",
      status: "PENDING", priority: "MEDIUM",
      requester: customers[1], assignee: agents[agents.length - 1], category: categories[0], tags: [tags[3]],
    },
    {
      subject: "App crashes when uploading large files",
      description:
        "On the mobile app, uploading a file larger than ~20MB causes an immediate crash. Happens on both iOS and Android.",
      status: "OPEN", priority: "URGENT",
      requester: customers[customers.length - 1], assignee: agents[0], category: categories[1], tags: [tags[0], tags[2]],
    },
    {
      subject: "Request: dark mode for the dashboard",
      description:
        "Would love a dark theme option for the dashboard — staring at the white screen all day is rough.",
      status: "OPEN", priority: "LOW",
      requester: customers[0], assignee: null, category: categories[3], tags: [tags[4]],
    },
    {
      subject: "Webhook integration not firing",
      description:
        "Our Slack webhook integration stopped delivering events yesterday around 14:00. No config changes on our side.",
      status: "RESOLVED", priority: "HIGH",
      requester: customers[1], assignee: agents[agents.length - 1], category: categories[1], tags: [tags[5]],
    },
    {
      subject: "How do I export my data?",
      description:
        "I'd like to export all my tickets and account data as CSV. Is there an option for that somewhere?",
      status: "CLOSED", priority: "LOW",
      requester: customers[customers.length - 1], assignee: agents[0], category: categories[4], tags: [],
    },
  ];

  for (const [i, t] of seedTickets.entries()) {
    const createdAt = new Date(Date.now() - (seedTickets.length - i) * 86400000);
    const created = await prisma.ticket.create({
      data: {
        number: i + 1,
        subject: t.subject,
        description: t.description,
        status: t.status,
        priority: t.priority,
        tenantId: tenant.id,
        requesterId: t.requester.id,
        assigneeId: t.assignee?.id ?? null,
        categoryId: t.category?.id ?? null,
        closedAt: t.status === "CLOSED" ? new Date() : null,
        dueAt: new Date(createdAt.getTime() + SLA[t.priority] * 3600_000),
        createdAt,
        tags: { create: t.tags.map((tag) => ({ tagId: tag.id })) },
      },
    });

    if (i < 3) {
      await prisma.comment.create({
        data: {
          tenantId: tenant.id,
          ticketId: created.id,
          authorId: (t.assignee ?? admin).id,
          body: "Thanks for reaching out — I'm looking into this now and will update you shortly.",
          isInternal: false,
        },
      });
      await prisma.comment.create({
        data: {
          tenantId: tenant.id,
          ticketId: created.id,
          authorId: (t.assignee ?? admin).id,
          body: "Internal: reproduced on staging, escalating to the platform team.",
          isInternal: true,
        },
      });
    }

    if (t.assignee) {
      await prisma.notification.create({
        data: {
          tenantId: tenant.id,
          userId: t.assignee.id,
          type: "TICKET_ASSIGNED",
          message: `You were assigned ticket #${created.number}: ${t.subject}`,
          ticketId: created.id,
          read: i % 2 === 0,
        },
      });
    }
  }

  return tenant;
}

async function main() {
  console.log("🌱 Seeding database...");

  await prisma.notification.deleteMany();
  await prisma.attachment.deleteMany();
  await prisma.comment.deleteMany();
  await prisma.ticketTag.deleteMany();
  await prisma.ticket.deleteMany();
  await prisma.tag.deleteMany();
  await prisma.category.deleteMany();
  await prisma.user.deleteMany();
  await prisma.tenant.deleteMany();

  const pw = await bcrypt.hash("password123", 10);

  // Platform operator — sees every tenant, belongs to none.
  await prisma.user.create({
    data: {
      name: "Platform Operator",
      email: "info@softify.gr",
      passwordHash: pw,
      role: "SUPER_ADMIN",
      avatarColor: "#0ea5e9",
      tenantId: null,
    },
  });

  await seedTenant(pw, {
    name: "Acme Inc",
    slug: "acme",
    adminEmail: "admin@acme.dev",
    agents: [
      { name: "Sofia Reyes", email: "sofia@acme.dev" },
      { name: "Marcus Lee", email: "marcus@acme.dev" },
    ],
    customers: [
      { name: "Jamie Carter", email: "jamie@acme-customer.com" },
      { name: "Priya Nair", email: "priya@acme-customer.com" },
      { name: "Tom Becker", email: "tom@acme-customer.com" },
    ],
  });

  await seedTenant(pw, {
    name: "Globex",
    slug: "globex",
    adminEmail: "admin@globex.dev",
    agents: [{ name: "Dana White", email: "dana@globex.dev" }],
    customers: [
      { name: "Leo Fischer", email: "leo@globex-customer.com" },
      { name: "Mia Rossi", email: "mia@globex-customer.com" },
    ],
  });

  console.log("✅ Seed complete.\n");
  console.log("All passwords: password123\n");
  console.log("Platform operator (sees every tenant):");
  console.log("  super@ticketingos.dev\n");
  console.log("Tenant 'Acme Inc':");
  console.log("  Admin:    admin@acme.dev");
  console.log("  Agents:   sofia@acme.dev / marcus@acme.dev");
  console.log("  Customers jamie@acme-customer.com / priya@acme-customer.com / tom@acme-customer.com\n");
  console.log("Tenant 'Globex':");
  console.log("  Admin:    admin@globex.dev");
  console.log("  Agent:    dana@globex.dev");
  console.log("  Customers leo@globex-customer.com / mia@globex-customer.com");
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
