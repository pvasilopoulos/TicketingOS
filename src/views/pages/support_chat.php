<?php
/**
 * Website live chat for visitors (/support/{slug}/chat), usually inside the widget (?embed=1).
 * @var array $tenant @var array $cfg @var bool $embed
 */
$accent = '#4f46e5';
$base = '/support/' . $tenant['slug'] . '/chat';
?>
<div class="flex <?= $embed ? 'h-dvh' : 'min-h-dvh items-center justify-center bg-bg p-4' ?>">
  <div class="flex w-full flex-col overflow-hidden bg-surface <?= $embed ? 'h-full' : 'h-[640px] max-w-md rounded-2xl border border-border shadow-xl' ?>" data-chat-root data-base="<?= e($base) ?>" data-key="<?= e('tos-chat:' . $tenant['slug']) ?>">
    <header class="flex items-center gap-3 px-4 py-3 text-white" style="background:<?= e($accent) ?>">
      <span class="grid h-9 w-9 place-items-center rounded-full bg-white/20"><?= icon('headset', 18) ?></span>
      <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-semibold"><?= e($cfg['title']) ?></p>
        <p class="truncate text-xs text-white/80" data-chat-sub><?= e($tenant['name']) ?></p>
      </div>
      <button type="button" data-chat-end hidden class="rounded-lg bg-white/15 px-2.5 py-1 text-xs font-medium hover:bg-white/25 cursor-pointer">End chat</button>
    </header>

    <div class="flex-1 space-y-2.5 overflow-y-auto bg-surface-2/40 px-3 py-4" data-chat-log aria-live="polite"></div>

    <form data-chat-start class="space-y-2.5 border-t border-border p-4">
      <p class="text-sm text-muted"><?= e($cfg['greeting']) ?></p>
      <div class="grid grid-cols-2 gap-2">
        <input name="name" required maxlength="80" placeholder="Your name" autocomplete="name" class="<?= INPUT_CLS ?>">
        <input name="email" type="email" required placeholder="Email" autocomplete="email" class="<?= INPUT_CLS ?>">
      </div>
      <textarea name="message" required rows="3" placeholder="How can we help?" class="<?= INPUT_CLS ?> h-auto py-2"></textarea>
      <input name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
      <p data-chat-error hidden class="text-xs text-red-600"></p>
      <button type="submit" class="flex h-10 w-full items-center justify-center gap-2 rounded-lg text-sm font-semibold text-white cursor-pointer" style="background:<?= e($accent) ?>"><?= icon('send', 15) ?> Start chat</button>
      <p class="text-center text-xs text-muted">Prefer email? <a href="/support/<?= e($tenant['slug']) ?><?= $embed ? '?embed=1' : '' ?>" class="font-medium underline">Send a request</a></p>
    </form>

    <form data-chat-compose hidden class="flex items-end gap-2 border-t border-border p-3">
      <textarea name="body" rows="1" placeholder="Write a message…" class="max-h-32 min-h-10 flex-1 resize-none rounded-xl border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
      <button type="submit" aria-label="Send" class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-white cursor-pointer" style="background:<?= e($accent) ?>"><?= icon('send-horizontal', 17) ?></button>
    </form>
    <div data-chat-ended hidden class="border-t border-border p-4 text-center">
      <p class="text-sm text-muted">This chat has ended.</p>
      <button type="button" data-chat-new class="mt-2 text-sm font-semibold underline cursor-pointer" style="color:<?= e($accent) ?>">Start a new chat</button>
    </div>
  </div>
</div>
<script>
(function () {
  var root = document.querySelector("[data-chat-root]");
  var base = root.getAttribute("data-base"), key = root.getAttribute("data-key");
  var log = root.querySelector("[data-chat-log]"), start = root.querySelector("[data-chat-start]"), compose = root.querySelector("[data-chat-compose]");
  var ended = root.querySelector("[data-chat-ended]"), endBtn = root.querySelector("[data-chat-end]"), sub = root.querySelector("[data-chat-sub]");
  var token = null, after = "", timer = null, seen = {}, status = "";
  try { token = localStorage.getItem(key); } catch (e) {}
  function api(op, body) {
    return fetch(base + "/" + op + (op === "poll" ? "?t=" + encodeURIComponent(token) + "&after=" + encodeURIComponent(after) : ""), {
      method: op === "poll" ? "GET" : "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, credentials: "omit",
      body: op === "poll" ? undefined : JSON.stringify(Object.assign({ t: token }, body || {})),
    }).then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); });
  }
  function time(iso) { var d = new Date(iso); return (d.getHours() < 10 ? "0" : "") + d.getHours() + ":" + (d.getMinutes() < 10 ? "0" : "") + d.getMinutes(); }
  function add(m) {
    if (seen[m.id]) return;
    seen[m.id] = 1;
    if (m.at > after) after = m.at;
    var row = document.createElement("div");
    if (m.sender === "system") {
      row.className = "px-6 text-center text-xs text-muted";
      row.textContent = m.body;
    } else {
      var mine = m.sender === "visitor";
      row.className = "flex " + (mine ? "justify-end" : "justify-start");
      var b = document.createElement("div");
      b.className = "max-w-[80%] whitespace-pre-wrap break-words rounded-2xl px-3.5 py-2 text-sm shadow-sm " + (mine ? "rounded-br-md text-white" : "rounded-bl-md border border-border bg-surface");
      if (mine) b.style.background = "<?= e($accent) ?>";
      b.textContent = m.body;
      var meta = document.createElement("div");
      meta.className = "mt-0.5 text-[10px] " + (mine ? "text-white/70 text-right" : "text-muted");
      meta.textContent = (m.name ? m.name + " · " : "") + time(m.at);
      b.appendChild(meta);
      row.appendChild(b);
    }
    log.appendChild(row);
    log.scrollTop = log.scrollHeight;
  }
  function setStatus(s, agent) {
    status = s;
    var open = s === "open";
    start.hidden = !!token;
    compose.hidden = !token || !open;
    ended.hidden = !token || open;
    endBtn.hidden = !token || !open;
    if (agent) sub.textContent = agent + " is chatting with you";
    if (!open) { clearTimeout(timer); timer = null; }
  }
  function poll() {
    if (!token) return;
    api("poll").then(function (j) {
      if (j._status === 404) { forget(); return; }
      (j.messages || []).forEach(add);
      setStatus(j.status, j.agent);
      if (j.status === "open") timer = setTimeout(poll, document.hidden ? 10000 : 3000);
    }, function () { timer = setTimeout(poll, 8000); });
  }
  function forget() { token = null; try { localStorage.removeItem(key); } catch (e) {} log.innerHTML = ""; seen = {}; after = ""; setStatus(""); }
  start.addEventListener("submit", function (e) {
    e.preventDefault();
    var err = start.querySelector("[data-chat-error]"), btn = start.querySelector("button[type=submit]");
    btn.disabled = true; err.hidden = true;
    var f = new FormData(start);
    var page = ""; try { page = document.referrer || ""; } catch (x) {}
    api("start", { name: f.get("name"), email: f.get("email"), message: f.get("message"), website: f.get("website"), page: page }).then(function (j) {
      btn.disabled = false;
      if (j.error) { err.textContent = j.error; err.hidden = false; return; }
      token = j.token;
      try { localStorage.setItem(key, token); } catch (x) {}
      (j.messages || []).forEach(add);
      setStatus("open");
      compose.querySelector("textarea").focus();
      timer = setTimeout(poll, 3000);
    }, function () { btn.disabled = false; err.textContent = "Network error — please try again."; err.hidden = false; });
  });
  var ta = compose.querySelector("textarea");
  ta.addEventListener("input", function () { ta.style.height = "auto"; ta.style.height = Math.min(128, ta.scrollHeight) + "px"; });
  ta.addEventListener("keydown", function (e) { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); compose.requestSubmit(); } });
  compose.addEventListener("submit", function (e) {
    e.preventDefault();
    var body = ta.value.trim();
    if (!body) return;
    ta.value = ""; ta.style.height = "";
    api("send", { body: body }).then(function (j) {
      if (j.error) { add({ id: "e" + Date.now(), sender: "system", body: j.error, at: new Date().toISOString() }); return; }
      clearTimeout(timer); poll();
    });
  });
  endBtn.addEventListener("click", function () { if (confirm("End this chat?")) api("close").then(function () { clearTimeout(timer); poll(); }); });
  root.querySelector("[data-chat-new]").addEventListener("click", forget);
  document.addEventListener("visibilitychange", function () { if (!document.hidden && token && status === "open") { clearTimeout(timer); poll(); } });
  setStatus(token ? "open" : "");
  if (token) poll();
})();
</script>
