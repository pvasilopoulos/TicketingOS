"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/db";
import { requireTenantAdmin, requireTenantUser, isStaff } from "@/lib/auth";

export type TagState = { error?: string; success?: string } | undefined;

function clean(name: string) {
  return name.trim().replace(/\s+/g, " ").slice(0, 40);
}

/* ---------- Settings management (admin) ---------- */

export async function createTag(
  _prev: TagState,
  formData: FormData,
): Promise<TagState> {
  const { tenantId } = await requireTenantAdmin();
  const name = clean((formData.get("name") as string) ?? "");
  if (name.length < 1) return { error: "Tag name is required" };

  const exists = await prisma.tag.findFirst({ where: { tenantId, name } });
  if (exists) return { error: "That tag already exists" };

  await prisma.tag.create({ data: { tenantId, name } });
  revalidatePath("/settings");
  return { success: `Added "${name}"` };
}

export async function renameTag(formData: FormData) {
  const { tenantId } = await requireTenantAdmin();
  const id = formData.get("id") as string;
  const name = clean((formData.get("name") as string) ?? "");
  if (!id || name.length < 1) return;

  const dup = await prisma.tag.findFirst({ where: { tenantId, name, id: { not: id } } });
  if (dup) return;

  await prisma.tag.updateMany({ where: { id, tenantId }, data: { name } });
  revalidatePath("/settings");
}

export async function deleteTag(formData: FormData) {
  const { tenantId } = await requireTenantAdmin();
  const id = formData.get("id") as string;
  if (!id) return;
  // TicketTag rows cascade-delete with the tag.
  await prisma.tag.deleteMany({ where: { id, tenantId } });
  revalidatePath("/settings");
}

/* ---------- Tagging a ticket (staff / managers) ---------- */

async function canManage(ticketId: string) {
  const { user, tenantId } = await requireTenantUser();
  const ticket = await prisma.ticket.findFirst({ where: { id: ticketId, tenantId } });
  if (!ticket) return null;
  if (isStaff(user.role) || ticket.requesterId === user.id) return { user, tenantId, ticket };
  const w = await prisma.ticketWatcher.findUnique({
    where: { ticketId_userId: { ticketId, userId: user.id } },
    select: { role: true },
  });
  if (w?.role === "COLLABORATOR") return { user, tenantId, ticket };
  return null;
}

/** Add an existing tag (by id) or a new tag (by name) to a ticket. */
export async function addTicketTag(ticketId: string, formData: FormData) {
  const ctx = await canManage(ticketId);
  if (!ctx) return;
  const { tenantId } = ctx;

  let tagId = (formData.get("tagId") as string) || "";
  const newName = clean((formData.get("name") as string) ?? "");

  if (!tagId && newName) {
    const existing = await prisma.tag.findFirst({ where: { tenantId, name: newName } });
    tagId = existing
      ? existing.id
      : (await prisma.tag.create({ data: { tenantId, name: newName } })).id;
  }
  if (!tagId) return;

  // Validate the tag belongs to this tenant.
  const tag = await prisma.tag.findFirst({ where: { id: tagId, tenantId } });
  if (!tag) return;

  await prisma.ticketTag.upsert({
    where: { ticketId_tagId: { ticketId, tagId } },
    create: { ticketId, tagId },
    update: {},
  });
  revalidatePath(`/tickets/${ticketId}`);
}

export async function removeTicketTag(ticketId: string, formData: FormData) {
  const ctx = await canManage(ticketId);
  if (!ctx) return;
  const tagId = formData.get("tagId") as string;
  if (!tagId) return;
  await prisma.ticketTag.deleteMany({ where: { ticketId, tagId } });
  revalidatePath(`/tickets/${ticketId}`);
}
