import "server-only";
import { randomUUID } from "crypto";
import { mkdir, writeFile } from "fs/promises";
import path from "path";
import { prisma } from "./db";

export const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
export const MAX_FILES = 5;
export const MAX_IMAGE_SIZE = 5 * 1024 * 1024; // 5 MB

const IMAGE_MIME = new Set([
  "image/png",
  "image/jpeg",
  "image/gif",
  "image/webp",
]);

const ALLOWED_MIME = new Set([
  "image/png",
  "image/jpeg",
  "image/gif",
  "image/webp",
  "image/svg+xml",
  "application/pdf",
  "text/plain",
  "text/csv",
  "application/zip",
  "application/msword",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "application/vnd.ms-excel",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
]);

/** Absolute path to the private upload storage root (not web-served). */
export function storageRoot() {
  return path.join(process.cwd(), "storage");
}

export function attachmentDiskPath(tenantId: string, id: string) {
  return path.join(storageRoot(), tenantId, id);
}

/**
 * Persist uploaded files to disk and create Attachment rows.
 * Invalid files (wrong type / too large / empty) are skipped.
 */
export async function saveUploads(
  files: File[],
  ctx: {
    tenantId: string;
    uploaderId: string;
    ticketId: string;
    commentId?: string | null;
  },
) {
  const valid = files
    .filter((f) => f && f.size > 0)
    .slice(0, MAX_FILES)
    .filter((f) => f.size <= MAX_FILE_SIZE && ALLOWED_MIME.has(f.type));

  const dir = path.join(storageRoot(), ctx.tenantId);
  if (valid.length) await mkdir(dir, { recursive: true });

  const created = [];
  for (const file of valid) {
    const id = randomUUID();
    const buffer = Buffer.from(await file.arrayBuffer());
    await writeFile(path.join(dir, id), buffer);

    const attachment = await prisma.attachment.create({
      data: {
        id,
        filename: file.name.slice(0, 200),
        url: `/api/attachments/${id}`,
        size: file.size,
        mime: file.type,
        tenantId: ctx.tenantId,
        uploaderId: ctx.uploaderId,
        ticketId: ctx.ticketId,
        commentId: ctx.commentId ?? null,
      },
    });
    created.push(attachment);
  }
  return created;
}

/**
 * Persist a single inline editor image (raster only). Returns its served URL,
 * or null if the file is not an allowed image or is too large.
 */
export async function saveInlineImage(
  file: File,
  ctx: { tenantId: string; uploaderId: string },
): Promise<string | null> {
  if (!file || file.size === 0) return null;
  if (!IMAGE_MIME.has(file.type) || file.size > MAX_IMAGE_SIZE) return null;

  const id = randomUUID();
  const dir = path.join(storageRoot(), ctx.tenantId);
  await mkdir(dir, { recursive: true });
  const buffer = Buffer.from(await file.arrayBuffer());
  await writeFile(path.join(dir, id), buffer);

  await prisma.attachment.create({
    data: {
      id,
      filename: file.name.slice(0, 200) || "image",
      url: `/api/attachments/${id}`,
      size: file.size,
      mime: file.type,
      tenantId: ctx.tenantId,
      uploaderId: ctx.uploaderId,
      ticketId: null,
    },
  });

  return `/api/attachments/${id}`;
}
