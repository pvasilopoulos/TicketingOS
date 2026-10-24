// TicketingOS push service worker.
self.addEventListener("push", (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = {};
  }
  const title = data.title || "TicketingOS";
  const options = {
    body: data.body || "",
    data: { url: data.url || "/" },
    tag: data.tag || undefined,
    renotify: Boolean(data.tag),
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || "/";
  event.waitUntil(
    self.clients
      .matchAll({ type: "window", includeUncontrolled: true })
      .then((wins) => {
        for (const w of wins) {
          if (w.url.includes(url) && "focus" in w) return w.focus();
        }
        if (self.clients.openWindow) return self.clients.openWindow(url);
      }),
  );
});
