"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/db";
import { requireTenantAdmin } from "@/lib/auth";
import { NOTIFY_CATEGORIES } from "@/lib/notify-shared";

export type PolicyState = { success?: string } | undefined;

export async function saveTenantPolicy(
  _prev: PolicyState,
  formData: FormData,
): Promise<PolicyState> {
  const { tenantId } = await requireTenantAdmin();

  const defaults: Record<string, { inApp: boolean; push: boolean; email: boolean }> = {};
  for (const c of NOTIFY_CATEGORIES) {
    defaults[c] = {
      inApp: formData.get(`def_${c}_inApp`) === "on",
      push: formData.get(`def_${c}_push`) === "on",
      email: formData.get(`def_${c}_email`) === "on",
    };
  }

  const data = {
    allowInApp: formData.get("allowInApp") === "on",
    allowPush: formData.get("allowPush") === "on",
    allowEmail: formData.get("allowEmail") === "on",
    defaults: JSON.stringify(defaults),
  };

  await prisma.tenantNotificationPolicy.upsert({
    where: { tenantId },
    create: { tenantId, ...data },
    update: data,
  });

  revalidatePath("/", "layout");
  return { success: "Notification policy saved" };
}
