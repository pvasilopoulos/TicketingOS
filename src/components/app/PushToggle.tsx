"use client";

import { useEffect, useState } from "react";
import { BellRing, BellOff, Send, Loader2 } from "lucide-react";
import { Dropdown } from "@/components/ui/Dropdown";

const PUBLIC_KEY = process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY ?? "";

function urlBase64ToUint8Array(base64: string) {
  const padding = "=".repeat((4 - (base64.length % 4)) % 4);
  const b64 = (base64 + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = atob(b64);
  const arr = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
  return arr;
}

type State = "loading" | "unsupported" | "on" | "off" | "busy";

export function PushToggle() {
  const [state, setState] = useState<State>("loading");
  const [tested, setTested] = useState(false);

  useEffect(() => {
    if (
      typeof window === "undefined" ||
      !("serviceWorker" in navigator) ||
      !("PushManager" in window) ||
      !PUBLIC_KEY
    ) {
      setState("unsupported");
      return;
    }
    navigator.serviceWorker
      .register("/sw.js")
      .then((reg) => reg.pushManager.getSubscription())
      .then((sub) => setState(sub ? "on" : "off"))
      .catch(() => setState("off"));
  }, []);

  async function enable() {
    setState("busy");
    try {
      const perm = await Notification.requestPermission();
      if (perm !== "granted") {
        setState("off");
        return;
      }
      const reg = await navigator.serviceWorker.register("/sw.js");
      await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(PUBLIC_KEY),
      });
      await fetch("/api/push/subscribe", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(sub),
      });
      setState("on");
    } catch {
      setState("off");
    }
  }

  async function disable() {
    setState("busy");
    try {
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      if (sub) {
        await fetch("/api/push/unsubscribe", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ endpoint: sub.endpoint }),
        });
        await sub.unsubscribe();
      }
    } catch {}
    setState("off");
  }

  async function test() {
    setTested(false);
    await fetch("/api/push/test", { method: "POST" });
    setTested(true);
  }

  if (state === "unsupported") return null;

  const active = state === "on";

  return (
    <Dropdown
      width={260}
      trigger={({ toggle }) => (
        <button
          onClick={toggle}
          aria-label="Push notifications"
          title="Push notifications"
          className={
            "inline-flex h-9 w-9 items-center justify-center rounded-lg transition-colors cursor-pointer " +
            (active
              ? "text-brand hover:bg-brand/10"
              : "text-muted hover:bg-surface-2 hover:text-fg")
          }
        >
          {state === "loading" || state === "busy" ? (
            <Loader2 size={18} className="animate-spin" />
          ) : active ? (
            <BellRing size={18} />
          ) : (
            <BellOff size={18} />
          )}
        </button>
      )}
    >
      {() => (
        <div className="p-3">
          <p className="text-sm font-semibold">Device push notifications</p>
          <p className="mt-1 text-xs text-muted">
            {active
              ? "This device will receive push notifications."
              : "Get notified on this device even when the app is closed."}
          </p>
          <div className="mt-3 flex flex-col gap-2">
            {active ? (
              <>
                <button
                  onClick={test}
                  className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-2 text-sm hover:bg-surface-2 cursor-pointer"
                >
                  <Send size={14} /> Send test
                </button>
                {tested && <p className="text-xs text-emerald-600 dark:text-emerald-400">Test sent — check your notifications.</p>}
                <button
                  onClick={disable}
                  className="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 cursor-pointer"
                >
                  <BellOff size={14} /> Turn off on this device
                </button>
              </>
            ) : (
              <button
                onClick={enable}
                className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand px-3 py-2 text-sm font-medium text-brand-fg hover:opacity-90 cursor-pointer"
              >
                <BellRing size={14} /> Enable on this device
              </button>
            )}
          </div>
        </div>
      )}
    </Dropdown>
  );
}
