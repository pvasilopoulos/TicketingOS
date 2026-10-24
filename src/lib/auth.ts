import "server-only";
import bcrypt from "bcryptjs";
import { redirect } from "next/navigation";
import { cookies } from "next/headers";
import { prisma } from "./db";
import { getSession } from "./session";
import type { Role } from "./constants";

// Cookie that lets a SUPER_ADMIN "enter" a tenant and operate inside its board.
const ACT_COOKIE = "tos_act_tenant";

export async function hashPassword(password: string) {
  return bcrypt.hash(password, 10);
}

export async function verifyPassword(password: string, hash: string) {
  return bcrypt.compare(password, hash);
}

/** Returns the full User row for the current session, or null. */
export async function getCurrentUser() {
  const session = await getSession();
  if (!session) return null;
  const user = await prisma.user.findUnique({
    where: { id: session.userId },
  });
  return user;
}

/** Requires an authenticated user; redirects to /login otherwise. */
export async function requireUser() {
  const user = await getCurrentUser();
  if (!user) redirect("/login");
  return user;
}

/** Requires one of the given roles; redirects to /dashboard otherwise. */
export async function requireRole(...roles: Role[]) {
  const user = await requireUser();
  if (!roles.includes(user.role as Role)) redirect("/dashboard");
  return user;
}

// A super admin acting inside a tenant is treated as staff/admin there.
export function isStaff(role: string) {
  return role === "ADMIN" || role === "AGENT" || role === "SUPER_ADMIN";
}
export function isTenantAdmin(role: string) {
  return role === "ADMIN" || role === "SUPER_ADMIN";
}
export function isSuperAdmin(role: string) {
  return role === "SUPER_ADMIN";
}

/** The tenant the super admin is currently impersonating, if any. */
export async function getActiveTenantId(): Promise<string | null> {
  const store = await cookies();
  return store.get(ACT_COOKIE)?.value ?? null;
}

export async function setActiveTenantId(tenantId: string) {
  const store = await cookies();
  store.set(ACT_COOKIE, tenantId, {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
  });
}

export async function clearActiveTenantId() {
  const store = await cookies();
  store.delete(ACT_COOKIE);
}

/** The tenant a user operates in: their own, or (super admin) the impersonated one. */
export async function getEffectiveTenantId(user: {
  role: string;
  tenantId: string | null;
}): Promise<string | null> {
  if (user.role === "SUPER_ADMIN") return getActiveTenantId();
  return user.tenantId ?? null;
}

/**
 * Requires an authenticated user operating inside a tenant.
 * - A tenant user → their own tenant.
 * - A SUPER_ADMIN → the tenant they've entered (else sent to /platform).
 * Returns a guaranteed non-null tenantId plus whether this is impersonation.
 */
export async function requireTenantUser() {
  const user = await requireUser();

  if (user.role === "SUPER_ADMIN") {
    const tid = await getActiveTenantId();
    if (!tid) redirect("/platform");
    const exists = await prisma.tenant.findUnique({
      where: { id: tid },
      select: { id: true },
    });
    if (!exists) {
      await clearActiveTenantId();
      redirect("/platform");
    }
    return { user, tenantId: tid, impersonating: true };
  }

  if (!user.tenantId) redirect("/platform");
  return { user, tenantId: user.tenantId, impersonating: false };
}

/** Requires tenant-admin powers (tenant ADMIN, or a SUPER_ADMIN in a tenant). */
export async function requireTenantAdmin() {
  const ctx = await requireTenantUser();
  if (!isTenantAdmin(ctx.user.role)) redirect("/dashboard");
  return ctx;
}

/** Requires the platform operator (SUPER_ADMIN). */
export async function requireSuperAdmin() {
  const user = await requireUser();
  if (user.role !== "SUPER_ADMIN") redirect("/dashboard");
  return user;
}
