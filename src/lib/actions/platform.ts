"use server";

import { redirect } from "next/navigation";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/db";
import {
  requireSuperAdmin,
  setActiveTenantId,
  clearActiveTenantId,
} from "@/lib/auth";

/** Platform operator enters a tenant's board to view & manage it. */
export async function enterTenant(formData: FormData) {
  await requireSuperAdmin();
  const tenantId = formData.get("tenantId") as string;
  if (!tenantId) return;

  const tenant = await prisma.tenant.findUnique({
    where: { id: tenantId },
    select: { id: true },
  });
  if (!tenant) return;

  await setActiveTenantId(tenantId);
  revalidatePath("/", "layout");
  redirect("/dashboard");
}

/** Leave the impersonated tenant and return to the platform console. */
export async function exitTenant() {
  await requireSuperAdmin();
  await clearActiveTenantId();
  revalidatePath("/", "layout");
  redirect("/platform");
}
