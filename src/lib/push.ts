import "server-only";
import webpush from "web-push";
import { prisma } from "./db";

const PUBLIC = process.env.VAPID_PUBLIC_KEY ?? "";
const PRIVATE = process.env.VAPID_PRIVATE_KEY ?? "";
const SUBJECT = process.env.VAPID_SUBJECT ?? "mailto:admin@example.com";

const configured = Boolean(PUBLIC && PRIVATE);
if (configured) {
  webpush.setVapidDetails(SUBJECT, PUBLIC, PRIVATE);
}

export type PushPayload = {
  title: string;
  body: string;
  url?: string;
  tag?: string;
};

/**
 * Send a web-push notification to every registered device of the given users.
 * Best-effort: dead subscriptions are pruned, errors never propagate.
 */
export async function sendPushToUsers(userIds: string[], payload: PushPayload) {
  if (!configured || userIds.length === 0) return;

  const subs = await prisma.pushSubscription.findMany({
    where: { userId: { in: userIds } },
  });
  if (subs.length === 0) return;

  const data = JSON.stringify(payload);
  await Promise.all(
    subs.map(async (s) => {
      try {
        await webpush.sendNotification(
          { endpoint: s.endpoint, keys: { p256dh: s.p256dh, auth: s.auth } },
          data,
        );
      } catch (e: unknown) {
        const code = (e as { statusCode?: number })?.statusCode;
        if (code === 404 || code === 410) {
          await prisma.pushSubscription.delete({ where: { id: s.id } }).catch(() => {});
        }
      }
    }),
  );
}
