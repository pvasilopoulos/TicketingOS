"use server";

import { redirect } from "next/navigation";
import { prisma } from "@/lib/db";
import { hashPassword, verifyPassword } from "@/lib/auth";
import { createSession, destroySession } from "@/lib/session";
import { loginSchema, registerSchema } from "@/lib/validations";
import { AVATAR_COLORS, DEFAULT_STATUSES, DEFAULT_PRIORITIES } from "@/lib/constants";

export type FormState = { error?: string } | undefined;

function slugify(input: string) {
  return input
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 40);
}

async function startSession(user: {
  id: string;
  role: string;
  name: string;
  email: string;
  tenantId: string | null;
}) {
  let tenantName: string | null = null;
  let tenantSlug: string | null = null;
  if (user.tenantId) {
    const t = await prisma.tenant.findUnique({ where: { id: user.tenantId } });
    tenantName = t?.name ?? null;
    tenantSlug = t?.slug ?? null;
  }
  await createSession({
    userId: user.id,
    role: user.role,
    name: user.name,
    email: user.email,
    tenantId: user.tenantId,
    tenantName,
    tenantSlug,
  });
}

export async function loginAction(
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  const parsed = loginSchema.safeParse({
    email: formData.get("email"),
    password: formData.get("password"),
  });
  if (!parsed.success) {
    return { error: parsed.error.issues[0]?.message ?? "Invalid input" };
  }

  const user = await prisma.user.findUnique({
    where: { email: parsed.data.email.toLowerCase() },
  });
  if (!user || !(await verifyPassword(parsed.data.password, user.passwordHash))) {
    return { error: "Invalid email or password" };
  }

  await startSession(user);
  redirect(user.role === "SUPER_ADMIN" ? "/platform" : "/dashboard");
}

export async function registerAction(
  _prev: FormState,
  formData: FormData,
): Promise<FormState> {
  const parsed = registerSchema.safeParse({
    organizationName: formData.get("organizationName"),
    name: formData.get("name"),
    email: formData.get("email"),
    password: formData.get("password"),
  });
  if (!parsed.success) {
    return { error: parsed.error.issues[0]?.message ?? "Invalid input" };
  }
  const { organizationName, name, email, password } = parsed.data;

  const existing = await prisma.user.findUnique({
    where: { email: email.toLowerCase() },
  });
  if (existing) {
    return { error: "An account with this email already exists" };
  }

  // Generate a unique tenant slug.
  const baseSlug = slugify(organizationName) || "team";
  let slug = baseSlug;
  let n = 1;
  while (await prisma.tenant.findUnique({ where: { slug } })) {
    slug = `${baseSlug}-${n++}`;
  }

  // Create the tenant, its first ADMIN, and default categories in one transaction.
  const user = await prisma.$transaction(async (tx) => {
    const tenant = await tx.tenant.create({
      data: { name: organizationName, slug },
    });
    await tx.category.createMany({
      data: [
        { name: "Billing", color: "#f59e0b", tenantId: tenant.id },
        { name: "Technical", color: "#3b82f6", tenantId: tenant.id },
        { name: "Account", color: "#8b5cf6", tenantId: tenant.id },
        { name: "General", color: "#64748b", tenantId: tenant.id },
      ],
    });
    await tx.ticketStatus.createMany({
      data: DEFAULT_STATUSES.map((s, i) => ({
        tenantId: tenant.id,
        key: s.key,
        label: s.label,
        color: s.color,
        isClosed: s.isClosed,
        order: i,
      })),
    });
    await tx.ticketPriority.createMany({
      data: DEFAULT_PRIORITIES.map((p) => ({
        tenantId: tenant.id,
        key: p.key,
        label: p.label,
        color: p.color,
        order: p.order,
      })),
    });
    await tx.slaTarget.createMany({
      data: [
        { priority: "URGENT", resolveHours: 4 },
        { priority: "HIGH", resolveHours: 24 },
        { priority: "MEDIUM", resolveHours: 72 },
        { priority: "LOW", resolveHours: 168 },
      ].map((s) => ({ ...s, tenantId: tenant.id })),
    });
    return tx.user.create({
      data: {
        name,
        email: email.toLowerCase(),
        passwordHash: await hashPassword(password),
        role: "ADMIN",
        avatarColor: AVATAR_COLORS[0],
        tenantId: tenant.id,
      },
    });
  });

  await startSession(user);
  redirect("/dashboard");
}

export async function logoutAction() {
  await destroySession();
  redirect("/login");
}
