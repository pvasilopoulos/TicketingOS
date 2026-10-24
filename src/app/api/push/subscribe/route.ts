import { NextResponse } from "next/server";
import { getCurrentUser } from "@/lib/auth";
import { prisma } from "@/lib/db";

export async function POST(req: Request) {
  const user = await getCurrentUser();
  if (!user) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });

  const body = await req.json().catch(() => null);
  const endpoint = body?.endpoint as string | undefined;
  const p256dh = body?.keys?.p256dh as string | undefined;
  const auth = body?.keys?.auth as string | undefined;
  if (!endpoint || !p256dh || !auth) {
    return NextResponse.json({ error: "Invalid subscription" }, { status: 400 });
  }

  const userAgent = req.headers.get("user-agent")?.slice(0, 200) ?? null;

  await prisma.pushSubscription.upsert({
    where: { endpoint },
    create: { endpoint, p256dh, auth, userAgent, userId: user.id },
    update: { p256dh, auth, userAgent, userId: user.id },
  });

  return NextResponse.json({ ok: true });
}
