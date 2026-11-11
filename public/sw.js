// TicketingOS service worker: offline app shell, page cache and Web Push.
const VERSION = "v12";
const SHELL = `tos-shell-${VERSION}`;
const PAGES = `tos-pages-${VERSION}`;
const SHELL_FILES = [
  "/offline.html",
  "/assets/app.css",
  "/assets/app.js",
  "/assets/editor.js",
  "/icons/icon-192.png",
  "/icons/icon-512.png",
  "/icons/badge-96.png",
  "/favicon.ico",
];
const MAX_PAGES = 60;

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(SHELL).then((c) => c.addAll(SHELL_FILES)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith("tos-") && k !== SHELL && k !== PAGES).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

// Pages post {type:"clear"} on sign-out so the next user never sees cached pages.
self.addEventListener("message", (event) => {
  if (event.data && event.data.type === "clear") event.waitUntil(caches.delete(PAGES));
});

async function trimPages() {
  const cache = await caches.open(PAGES);
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - MAX_PAGES; i++) await cache.delete(keys[i]);
}

self.addEventListener("fetch", (event) => {
  const req = event.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  // Never cache live data, actions, downloads or auth pages.
  if (url.pathname.startsWith("/api/") || url.pathname.startsWith("/action/") || url.pathname === "/tickets/export") return;

  // HTML pages: network first (always fresh), fall back to the last copy, then offline page.
  if (req.mode === "navigate") {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const authPage = ["/login", "/register", "/forgot", "/reset"].includes(url.pathname);
          if (res.ok && !res.redirected && !authPage) {
            const copy = res.clone();
            caches.open(PAGES).then((c) => c.put(req, copy)).then(trimPages);
          }
          return res;
        })
        .catch(async () => (await caches.match(req)) || (await caches.match("/offline.html"))),
    );
    return;
  }

  // Static assets: stale-while-revalidate.
  if (url.pathname.startsWith("/assets/") || url.pathname.startsWith("/icons/") || url.pathname === "/favicon.ico") {
    event.respondWith(
      caches.open(SHELL).then(async (cache) => {
        // Versioned URLs (?v=<mtime>) are exact matches, so a deploy is picked up
        // immediately; the unversioned entry is only an offline fallback.
        const cached = (await cache.match(req)) || (!navigator.onLine ? await cache.match(req, { ignoreSearch: true }) : undefined);
        const network = fetch(req).then((res) => {
          if (res.ok) cache.put(req, res.clone());
          return res;
        }).catch(() => cached || cache.match(req, { ignoreSearch: true }));
        return cached || network;
      }),
    );
  }
});

// ---------- Web Push ----------

self.addEventListener("push", (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { body: event.data ? event.data.text() : "" };
  }
  const title = data.title || "TicketingOS";
  const options = {
    body: data.body || "",
    icon: "/icons/icon-192.png",
    badge: "/icons/badge-96.png",
    data: { url: data.url || "/dashboard" },
    tag: data.tag || undefined,
    renotify: Boolean(data.tag),
    timestamp: Date.now(),
  };
  const tasks = [self.registration.showNotification(title, options)];
  // Bump the app-icon badge on installed PWAs.
  if (self.navigator && "setAppBadge" in self.navigator) {
    tasks.push(self.registration.getNotifications().then((n) => self.navigator.setAppBadge(n.length + 1)).catch(() => {}));
  }
  event.waitUntil(Promise.all(tasks));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = new URL((event.notification.data && event.notification.data.url) || "/dashboard", self.location.origin).href;
  event.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((wins) => {
      for (const w of wins) {
        if (w.url === url && "focus" in w) return w.focus();
      }
      const any = wins.find((w) => "focus" in w);
      if (any) {
        any.postMessage({ type: "navigate", url });
        return any.focus();
      }
      return self.clients.openWindow(url);
    }),
  );
});
