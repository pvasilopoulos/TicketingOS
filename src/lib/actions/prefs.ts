"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/db";
import { requireTenantUser } from "@/lib/auth";
import { NOTIFY_CATEGORIES } from "@/lib/notify-shared";

export type PrefsState = { success?: string } | undefined;

function hhmmToMinutes(v: string): number | null {
  const m = /^(\d{1,2}):(\d{2})$/.exec(v);
  if (!m) return null;
  const h = Math.min(23, Number(m[1]));
  const min = Math.min(59, Number(m[2]));
  return h * 60 + min;
}

export async function saveNotificationPrefs(
  _prev: PrefsState,
  formData: FormData,
): Promise<PrefsState> {
  const { user } = await requireTenantUser();

  const prefs: Record<string, { inApp: boolean; push: boolean; email: boolean }> = {};
  for (const c of NOTIFY_CATEGORIES) {
    prefs[c] = {
      inApp: formData.get(`ch_${c}_inApp`) === "on",
      push: formData.get(`ch_${c}_push`) === "on",
      email: formData.get(`ch_${c}_email`) === "on",
    };
  }

  const quietEnabled = formData.get("quietEnabled") === "on";
  const quietStart = quietEnabled ? hhmmToMinutes((formData.get("quietStart") as string) ?? "") : null;
  const quietEnd = quietEnabled ? hhmmToMinutes((formData.get("quietEnd") as string) ?? "") : null;

  const priorityThreshold = ((formData.get("priorityThreshold") as string) ?? "").trim();
  const scopeRaw = (formData.get("scope") as string) ?? "all";
  const scope = ["all", "assigned", "watching"].includes(scopeRaw) ? scopeRaw : "all";

  const data = {
    prefs: JSON.stringify(prefs),
    quietStart,
    quietEnd,
    priorityThreshold,
    scope,
  };

  await prisma.notificationPreference.upsert({
    where: { userId: user.id },
    create: { userId: user.id, ...data },
    update: data,
  });

  revalidatePath("/notifications");
  return { success: "Notification preferences saved" };
}

export async function removeDevice(formData: FormData) {
  const { user } = await requireTenantUser();
  const id = formData.get("id") as string;
  if (!id) return;
  await prisma.pushSubscription.deleteMany({ where: { id, userId: user.id } });
  revalidatePath("/notifications");
}
