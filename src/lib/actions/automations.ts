"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/db";
import { requireTenantAdmin } from "@/lib/auth";

export type AutomationState = { error?: string; success?: string } | undefined;

const ACTIONS = ["ASSIGN", "SET_PRIORITY", "SET_STATUS", "ADD_TAG"];

export async function createRule(
  _prev: AutomationState,
  formData: FormData,
): Promise<AutomationState> {
  const { tenantId } = await requireTenantAdmin();
  const name = ((formData.get("name") as string) ?? "").trim().slice(0, 60);
  const conditionField = (formData.get("conditionField") as string) || "";
  const conditionValue = (formData.get("conditionValue") as string) || "";
  const actionType = (formData.get("actionType") as string) || "";
  const actionValue = (formData.get("actionValue") as string) || "";

  if (name.length < 2) return { error: "Rule name is too short" };
  if (!ACTIONS.includes(actionType)) return { error: "Choose an action" };
  if (!actionValue) return { error: "Choose an action target" };
  if (conditionField && !conditionValue) return { error: "Choose a condition value" };

  const count = await prisma.automationRule.count({ where: { tenantId } });
  await prisma.automationRule.create({
    data: {
      tenantId,
      name,
      trigger: "TICKET_CREATED",
      conditionField: conditionField || null,
      conditionValue: conditionField ? conditionValue : null,
      actionType,
      actionValue,
      order: count,
    },
  });
  revalidatePath("/settings");
  return { success: `Added rule "${name}"` };
}

export async function toggleRule(formData: FormData) {
  const { tenantId } = await requireTenantAdmin();
  const id = formData.get("id") as string;
  const enabled = formData.get("enabled") === "true";
  if (!id) return;
  await prisma.automationRule.updateMany({ where: { id, tenantId }, data: { enabled } });
  revalidatePath("/settings");
}

export async function deleteRule(formData: FormData) {
  const { tenantId } = await requireTenantAdmin();
  const id = formData.get("id") as string;
  if (!id) return;
  await prisma.automationRule.deleteMany({ where: { id, tenantId } });
  revalidatePath("/settings");
}
