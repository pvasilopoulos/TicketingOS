import { NextResponse } from "next/server";
import { getCurrentUser } from "@/lib/auth";
import { sendPushToUsers } from "@/lib/push";

export async function POST() {
  const user = await getCurrentUser();
  if (!user) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });

  await sendPushToUsers([user.id], {
    title: "TicketingOS",
    body: "🔔 Push notifications are working on this device.",
    url: "/dashboard",
  });
  return NextResponse.json({ ok: true });
}
