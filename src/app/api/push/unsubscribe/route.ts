import { NextResponse } from "next/server";
import { getCurrentUser } from "@/lib/auth";
import { prisma } from "@/lib/db";

export async function POST(req: Request) {
  const user = await getCurrentUser();
  if (!user) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });

  const body = await req.json().catch(() => null);
  const endpoint = body?.endpoint as string | undefined;
  if (!endpoint) return NextResponse.json({ error: "No endpoint" }, { status: 400 });

  await prisma.pushSubscription.deleteMany({ where: { endpoint, userId: user.id } });
  return NextResponse.json({ ok: true });
}
