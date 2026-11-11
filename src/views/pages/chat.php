<?php
[$user, $tenantId] = require_tenant_user();
if (!is_staff($user['role']) || !can($user, 'chat.answer')) redirect('/dashboard');
page_title('Live chat');
$cfg = chat_settings($tenantId);
?>
<div>
  <?= page_header('Live chat', 'Visitors chatting from your website. Unanswered chats become tickets after ' . $cfg['ticketAfter'] . ' minutes.',
      can($user, 'settings.chat') ? '<a href="/settings#chat" class="' . btn_cls('outline', 'sm') . '">' . icon('settings', 14) . ' Chat settings</a>' : '') ?>
  <?php if (!$cfg['enabled']): ?>
    <p class="mb-4 flex items-center gap-2 rounded-xl border border-amber-500/40 bg-amber-500/5 p-4 text-sm text-amber-800 dark:text-amber-300"><?= icon('triangle-alert', 15) ?> Live chat is off. Turn it on in Settings → Integrations → Live chat.</p>
  <?php endif; ?>
  <div class="grid gap-4 lg:grid-cols-[20rem_1fr]" data-agent-chat>
    <div class="rounded-xl border border-border bg-surface" data-chat-pane="list">
      <div class="flex items-center justify-between border-b border-border px-4 py-3"><h3 class="text-sm font-semibold">Conversations</h3><span data-chat-waiting class="rounded-full bg-surface-2 px-2 py-0.5 text-xs text-muted">0 waiting</span></div>
      <ul data-chat-list class="max-h-[70vh] divide-y divide-border overflow-y-auto"><li class="px-4 py-8 text-center text-sm text-muted">Loading…</li></ul>
    </div>
    <div class="flex min-h-[28rem] flex-col rounded-xl border border-border bg-surface max-lg:hidden" data-chat-pane="conv">
      <div data-chat-empty class="m-auto p-8 text-center text-sm text-muted"><?= icon('messages-square', 28, 'mx-auto mb-2 text-muted') ?>Pick a conversation.</div>
      <div data-chat-head hidden class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
        <button type="button" data-chat-back class="grid h-8 w-8 place-items-center rounded-lg text-muted hover:bg-surface-2 lg:hidden cursor-pointer" aria-label="Back"><?= icon('arrow-left', 16) ?></button>
        <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold" data-chat-name></p><p class="truncate text-xs text-muted" data-chat-meta></p></div>
        <a data-chat-ticket hidden class="<?= btn_cls('outline', 'sm') ?>"></a>
        <button type="button" data-chat-op="ticket" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('ticket', 14) ?> To ticket</button>
        <button type="button" data-chat-op="close" class="<?= btn_cls('ghost', 'sm') ?> text-red-600"><?= icon('x', 14) ?> End</button>
      </div>
      <div data-chat-log hidden class="max-h-[60vh] min-h-64 flex-1 space-y-2.5 overflow-y-auto bg-surface-2/40 px-4 py-4"></div>
      <form data-chat-reply hidden class="flex items-end gap-2 border-t border-border p-3">
        <textarea rows="1" placeholder="Reply… (Enter to send, Shift+Enter for a new line)" class="max-h-40 min-h-10 flex-1 resize-none rounded-xl border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
        <button type="submit" class="<?= btn_cls('primary') ?>"><?= icon('send', 15) ?> Send</button>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  var root = document.querySelector("[data-agent-chat]");
  var list = root.querySelector("[data-chat-list]"), log = root.querySelector("[data-chat-log]"), reply = root.querySelector("[data-chat-reply]");
  var listPane = root.querySelector('[data-chat-pane="list"]'), convPane = root.querySelector('[data-chat-pane="conv"]');
  var cur = null, after = "", seen = {}, convTimer = null, known = {}, firstLoad = true;
  var csrf = (window.APP || {}).csrf || "";
  var esc = function (s) { var d = document.createElement("div"); d.textContent = s == null ? "" : String(s); return d.innerHTML; };
  var time = function (iso) { var d = new Date(iso); return (d.getHours() < 10 ? "0" : "") + d.getHours() + ":" + (d.getMinutes() < 10 ? "0" : "") + d.getMinutes(); };
  function get(url) { return fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } }).then(function (r) { return r.json(); }); }
  function post(url, body) {
    return fetch(url, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": csrf, "X-Requested-With": "fetch" }, body: JSON.stringify(body) }).then(function (r) { return r.json(); });
  }
  function ding() {
    try { var a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain(); o.frequency.value = 880; g.gain.value = 0.05; o.connect(g); g.connect(a.destination); o.start(); o.stop(a.currentTime + 0.15); } catch (e) {}
  }
  function loadList() {
    get("/api/chat/list").then(function (j) {
      var items = j.items || [];
      root.querySelector("[data-chat-waiting]").textContent = (j.waiting || 0) + " waiting";
      document.title = (j.waiting ? "(" + j.waiting + ") " : "") + "Live chat";
      var fresh = items.some(function (it) { return it.waiting && it.unread && known[it.id] !== (it.last && it.last.at); });
      if (fresh && !firstLoad) ding();
      firstLoad = false;
      items.forEach(function (it) { known[it.id] = it.last && it.last.at; });
      if (!items.length) { list.innerHTML = '<li class="px-4 py-10 text-center text-sm text-muted">No chats right now.</li>'; return; }
      list.innerHTML = items.map(function (it) {
        return '<li><button type="button" data-open="' + esc(it.id) + '" class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-surface-2 cursor-pointer' + (cur === it.id ? " bg-brand/5" : "") + '">' +
          '<span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ' + (it.status !== "open" ? "bg-slate-300" : it.waiting ? "bg-amber-500 animate-pulse" : "bg-emerald-500") + '"></span>' +
          '<span class="min-w-0 flex-1"><span class="flex items-center gap-2"><span class="truncate text-sm ' + (it.unread && it.status === "open" ? "font-semibold" : "font-medium") + '">' + esc(it.name) + '</span>' +
          '<span class="ml-auto shrink-0 text-[11px] text-muted">' + (it.last ? time(it.last.at) : "") + '</span></span>' +
          '<span class="block truncate text-xs text-muted">' + (it.last ? (it.last.from === "agent" ? "You: " : "") + esc(it.last.text) : "") + '</span>' +
          '<span class="block truncate text-[11px] text-muted">' + (it.status === "open" ? (it.agent ? esc(it.agent) : "Waiting for an agent") : "Ended") + (it.ticketId ? " · ticket" : "") + "</span></span></button></li>";
      }).join("");
    });
  }
  function add(m) {
    if (seen[m.id]) return;
    seen[m.id] = 1;
    if (m.at > after) after = m.at;
    var row = document.createElement("div");
    if (m.sender === "system") { row.className = "text-center text-xs text-muted"; row.textContent = m.body; }
    else {
      var mine = m.sender === "agent";
      row.className = "flex " + (mine ? "justify-end" : "justify-start");
      row.innerHTML = '<div class="max-w-[80%] whitespace-pre-wrap break-words rounded-2xl px-3.5 py-2 text-sm shadow-sm ' + (mine ? "rounded-br-md bg-brand text-brand-fg" : "rounded-bl-md border border-border bg-surface") + '"></div>';
      row.firstChild.textContent = m.body;
      var meta = document.createElement("div");
      meta.className = "mt-0.5 text-[10px] " + (mine ? "text-right opacity-70" : "text-muted");
      meta.textContent = (m.name ? m.name + " · " : "") + time(m.at);
      row.firstChild.appendChild(meta);
    }
    log.appendChild(row);
    log.scrollTop = log.scrollHeight;
  }
  function loadConv() {
    if (!cur) return;
    var id = cur;
    get("/api/chat/messages?id=" + encodeURIComponent(id) + "&after=" + encodeURIComponent(after)).then(function (j) {
      if (id !== cur || j.error) return;
      (j.messages || []).forEach(add);
      var s = j.session;
      root.querySelector("[data-chat-name]").textContent = s.name;
      root.querySelector("[data-chat-meta]").textContent = s.email + (s.knownTickets ? " · " + s.knownTickets + " earlier ticket(s)" : " · new customer") + (s.page ? " · " + s.page : "");
      var tl = root.querySelector("[data-chat-ticket]");
      tl.hidden = !s.ticket;
      if (s.ticket) { tl.href = "/tickets/" + s.ticket.id; tl.textContent = "Ticket #" + s.ticket.number; }
      var open = j.status === "open";
      reply.hidden = !open;
      root.querySelector('[data-chat-op="close"]').hidden = !open;
      root.querySelector('[data-chat-op="ticket"]').hidden = !!s.ticket && !open;
      clearTimeout(convTimer);
      if (open) convTimer = setTimeout(loadConv, 2500);
    });
  }
  function openChat(id) {
    cur = id; after = ""; seen = {}; log.innerHTML = "";
    root.querySelector("[data-chat-empty]").hidden = true;
    root.querySelector("[data-chat-head]").hidden = false;
    log.hidden = false;
    convPane.classList.remove("max-lg:hidden");
    listPane.classList.add("max-lg:hidden");
    loadConv();
    loadList();
    reply.querySelector("textarea").focus();
  }
  list.addEventListener("click", function (e) { var b = e.target.closest("[data-open]"); if (b) openChat(b.getAttribute("data-open")); });
  root.querySelector("[data-chat-back]").addEventListener("click", function () { convPane.classList.add("max-lg:hidden"); listPane.classList.remove("max-lg:hidden"); });
  var ta = reply.querySelector("textarea");
  ta.addEventListener("input", function () { ta.style.height = "auto"; ta.style.height = Math.min(160, ta.scrollHeight) + "px"; });
  ta.addEventListener("keydown", function (e) { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); reply.requestSubmit(); } });
  reply.addEventListener("submit", function (e) {
    e.preventDefault();
    var body = ta.value.trim();
    if (!body || !cur) return;
    ta.value = ""; ta.style.height = "";
    post("/api/chat/reply", { id: cur, body: body }).then(function (j) {
      if (j.error && window.TOS) window.TOS.toast(j.error, null, true);
      loadConv(); loadList();
    });
  });
  root.addEventListener("click", function (e) {
    var b = e.target.closest("[data-chat-op]");
    if (!b || !cur) return;
    var op = b.getAttribute("data-chat-op");
    if (op === "close" && !confirm("End this chat? It’s saved as a ticket.")) return;
    b.disabled = true;
    post("/api/chat/action", { id: cur, op: op }).then(function (j) {
      b.disabled = false;
      if (j.error) { if (window.TOS) window.TOS.toast(j.error, null, true); return; }
      if (j.ticket && window.TOS) window.TOS.toast("Ticket #" + j.ticket.number + " created");
      loadConv(); loadList();
    });
  });
  loadList();
  setInterval(loadList, 5000);
  var want = new URLSearchParams(location.search).get("c");
  if (want) openChat(want);
})();
</script>
