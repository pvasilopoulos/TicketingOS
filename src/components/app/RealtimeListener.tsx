"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Bell, X } from "lucide-react";

type Toast = { id: number; message: string; url: string };

export function RealtimeListener() {
  const router = useRouter();
  const [toasts, setToasts] = useState<Toast[]>([]);

  useEffect(() => {
    let es: EventSource | null = null;
    try {
      es = new EventSource("/api/stream");
    } catch {
      return;
    }
    es.onmessage = (e) => {
      try {
        const d = JSON.parse(e.data);
        if (d.kind === "notification") {
          const id = Date.now() + Math.random();
          setToasts((t) => [...t.slice(-3), { id, message: d.message, url: d.url }]);
          setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 6000);
          router.refresh();
        }
      } catch {}
    };
    return () => es?.close();
  }, [router]);

  if (toasts.length === 0) return null;

  return (
    <div className="pointer-events-none fixed bottom-4 right-4 z-[100] flex flex-col gap-2">
      {toasts.map((t) => (
        <div
          key={t.id}
          className="pointer-events-auto flex w-80 items-start gap-2.5 rounded-xl border border-border bg-surface p-3 shadow-lg shadow-black/10 animate-in"
        >
          <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand/10 text-brand">
            <Bell size={16} />
          </span>
          <button
            onClick={() => {
              router.push(t.url);
              setToasts((x) => x.filter((y) => y.id !== t.id));
            }}
            className="min-w-0 flex-1 text-left text-sm cursor-pointer"
          >
            {t.message}
          </button>
          <button
            onClick={() => setToasts((x) => x.filter((y) => y.id !== t.id))}
            aria-label="Dismiss"
            className="text-muted hover:text-fg cursor-pointer"
          >
            <X size={15} />
          </button>
        </div>
      ))}
    </div>
  );
}
