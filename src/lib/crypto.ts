import "server-only";
import { createCipheriv, createDecipheriv, createHash, randomBytes } from "crypto";

// Symmetric encryption for secrets at rest (SMTP password, OAuth client secret).
// Key is derived from AUTH_SECRET; rotate that and old values become unreadable.
const KEY = createHash("sha256")
  .update(process.env.AUTH_SECRET ?? "insecure-dev-secret")
  .digest();

const PREFIX = "enc:v1:";

/** Encrypt a plaintext secret → "enc:v1:<base64(iv|tag|ciphertext)>". */
export function encryptSecret(plain: string): string {
  const iv = randomBytes(12);
  const cipher = createCipheriv("aes-256-gcm", KEY, iv);
  const ct = Buffer.concat([cipher.update(plain, "utf8"), cipher.final()]);
  const tag = cipher.getAuthTag();
  return PREFIX + Buffer.concat([iv, tag, ct]).toString("base64");
}

/** Decrypt a value produced by encryptSecret. Returns "" on failure. */
export function decryptSecret(value: string | null | undefined): string {
  if (!value || !value.startsWith(PREFIX)) return "";
  try {
    const raw = Buffer.from(value.slice(PREFIX.length), "base64");
    const iv = raw.subarray(0, 12);
    const tag = raw.subarray(12, 28);
    const ct = raw.subarray(28);
    const decipher = createDecipheriv("aes-256-gcm", KEY, iv);
    decipher.setAuthTag(tag);
    return Buffer.concat([decipher.update(ct), decipher.final()]).toString("utf8");
  } catch {
    return "";
  }
}

export function isEncrypted(value: string | null | undefined): boolean {
  return Boolean(value && value.startsWith(PREFIX));
}
