import { NextResponse } from "next/server";
import { readFile } from "fs/promises";
import { getCurrentUser, isStaff } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { attachmentDiskPath } from "@/lib/storage";

export async function GET(
  _req: Request,
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params;
  const user = await getCurrentUser();
  if (!user) return new NextResponse("Unauthorized", { status: 401 });

  const attachment = await prisma.attachment.findUnique({
    where: { id },
    include: { ticket: true, comment: true },
  });
  if (!attachment) {
    return new NextResponse("Not found", { status: 404 });
  }

  const superAdmin = user.role === "SUPER_ADMIN";
  // Tenant that owns the file: the ticket's tenant, or the attachment's own
  // tenant for inline editor images that aren't tied to a ticket yet.
  const ownerTenantId = attachment.ticket?.tenantId ?? attachment.tenantId;
  if (!ownerTenantId) {
    return new NextResponse("Not found", { status: 404 });
  }

  // Tenant isolation.
  if (!superAdmin && ownerTenantId !== user.tenantId) {
    return new NextResponse("Forbidden", { status: 403 });
  }
  // Customers may only access their own tickets (ticket-bound attachments).
  if (
    attachment.ticket &&
    !superAdmin &&
    !isStaff(user.role) &&
    attachment.ticket.requesterId !== user.id
  ) {
    return new NextResponse("Forbidden", { status: 403 });
  }
  // Attachments on internal notes are staff-only.
  if (attachment.comment?.isInternal && !isStaff(user.role) && !superAdmin) {
    return new NextResponse("Forbidden", { status: 403 });
  }

  let data: Buffer;
  try {
    data = await readFile(attachmentDiskPath(ownerTenantId, attachment.id));
  } catch {
    return new NextResponse("File missing", { status: 404 });
  }

  const inline = attachment.mime.startsWith("image/") || attachment.mime === "application/pdf";
  return new NextResponse(new Uint8Array(data), {
    headers: {
      "Content-Type": attachment.mime || "application/octet-stream",
      "Content-Length": String(attachment.size),
      "Content-Disposition": `${inline ? "inline" : "attachment"}; filename="${encodeURIComponent(
        attachment.filename,
      )}"`,
      "Cache-Control": "private, max-age=3600",
    },
  });
}
