import { getCurrentUser } from "@/lib/auth";
import { addClient } from "@/lib/realtime";

export const dynamic = "force-dynamic";

export async function GET() {
  const user = await getCurrentUser();
  if (!user) return new Response("Unauthorized", { status: 401 });

  const encoder = new TextEncoder();
  let remove = () => {};
  let heartbeat: ReturnType<typeof setInterval> | undefined;

  const stream = new ReadableStream({
    start(controller) {
      const send = (data: string) => {
        try {
          controller.enqueue(encoder.encode(data));
        } catch {}
      };
      send(": connected\n\n");
      remove = addClient({ userId: user.id, send });
      heartbeat = setInterval(() => send(": ping\n\n"), 25000);
    },
    cancel() {
      remove();
      if (heartbeat) clearInterval(heartbeat);
    },
  });

  return new Response(stream, {
    headers: {
      "Content-Type": "text/event-stream",
      "Cache-Control": "no-cache, no-transform",
      Connection: "keep-alive",
      "X-Accel-Buffering": "no",
    },
  });
}
