<?php
[$user, $tenantId] = require_tenant_user();
if (!is_staff($user['role']) || !can($user, 'chat.answer')) redirect('/dashboard');
page_title('Live chat');
$cfg = chat_settings($tenantId);
$canned = array_map(fn ($c) => ['title' => $c['title'], 'text' => trim(html_to_text((string) $c['body']))],
    q_all('SELECT "title","body" FROM "CannedResponse" WHERE "tenantId" = ? ORDER BY "title" ASC', [$tenantId]));
$agents = chat_agents($tenantId);
$boot = ['me' => $user['id'], 'canned' => $canned, 'agents' => $agents];
$chip = 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-border bg-surface px-2.5 text-xs font-medium text-muted hover:bg-surface-2 hover:text-fg cursor-pointer';
?>
<div>
  <?= page_header('Live chat', 'Answer visitors and customers in real time. Unanswered chats become tickets after ' . $cfg['ticketAfter'] . ' minutes.',
      '<div class="flex flex-wrap items-center gap-2">'
      . '<button type="button" data-chat-sound class="' . $chip . '" title="Sound on new messages">' . icon('volume-2', 14) . ' <span>Sound on</span></button>'
      . '<button type="button" data-chat-desktop class="' . $chip . '" title="Desktop notifications when this tab is in the background">' . icon('bell', 14) . ' <span>Desktop alerts</span></button>'
      . (can($user, 'settings.chat') ? '<a href="/settings#chat" class="' . btn_cls('outline', 'sm') . '">' . icon('settings', 14) . ' Settings</a>' : '')
      . '</div>') ?>
  <?php if (!$cfg['enabled']): ?>
    <p class="mb-4 flex items-center gap-2 rounded-xl border border-amber-500/40 bg-amber-500/5 p-4 text-sm text-amber-800 dark:text-amber-300"><?= icon('triangle-alert', 15) ?> Live chat is off. Turn it on in Settings → Integrations → Live chat.</p>
  <?php endif; ?>

  <div data-agent-chat>
    <!-- Today at a glance -->
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
      <?php foreach ([['waiting', 'Waiting', 'clock', 'text-amber-600'], ['active', 'In progress', 'messages-square', 'text-emerald-600'], ['mine', 'Yours', 'user', 'text-brand'], ['ended', 'Ended today', 'circle-check', 'text-muted']] as [$k, $label, $ic, $tone]): ?>
        <div class="flex items-center gap-3 rounded-xl border border-border bg-surface px-4 py-3">
          <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-surface-2 <?= $tone ?>"><?= icon($ic, 17) ?></span>
          <div class="min-w-0"><p class="text-xl font-semibold leading-tight tabular-nums" data-stat="<?= $k ?>">–</p><p class="truncate text-xs text-muted"><?= $label ?></p></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="grid h-[calc(100dvh-19rem)] min-h-[34rem] gap-4 lg:grid-cols-[20rem_minmax(0,1fr)] xl:grid-cols-[20rem_minmax(0,1fr)_17rem]">
      <!-- Conversations -->
      <section class="flex min-h-0 flex-col overflow-hidden rounded-xl border border-border bg-surface" data-chat-pane="list">
        <div class="space-y-2.5 border-b border-border p-3">
          <label class="relative block">
            <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-muted"><?= icon('search', 15) ?></span>
            <input type="search" data-chat-search placeholder="Search name, email or message" class="h-9 w-full rounded-lg border border-border bg-surface-2/60 pl-8 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
          </label>
          <div class="flex gap-1 rounded-lg bg-surface-2 p-1 text-xs font-medium" role="tablist">
            <?php foreach (['open' => 'Open', 'waiting' => 'Waiting', 'mine' => 'Yours', 'ended' => 'Ended'] as $k => $label): ?>
              <button type="button" role="tab" data-filter="<?= $k ?>" class="flex flex-1 items-center justify-center gap-1 rounded-md px-2 py-1.5 text-muted cursor-pointer"><?= $label ?><span data-count="<?= $k ?>" class="tabular-nums"></span></button>
            <?php endforeach; ?>
          </div>
        </div>
        <ul data-chat-list class="min-h-0 flex-1 overflow-y-auto"><li class="px-4 py-10 text-center text-sm text-muted">Loading…</li></ul>
      </section>

      <!-- Conversation -->
      <section class="flex min-h-0 flex-col overflow-hidden rounded-xl border border-border bg-surface max-lg:hidden" data-chat-pane="conv">
        <div data-chat-empty class="m-auto max-w-xs p-8 text-center">
          <span class="mx-auto mb-3 grid h-14 w-14 place-items-center rounded-2xl bg-brand/10 text-brand"><?= icon('messages-square', 26) ?></span>
          <p class="font-medium">No conversation open</p>
          <p class="mt-1 text-sm text-muted">Pick a chat on the left. Waiting chats pulse in amber; new messages play a sound.</p>
        </div>
        <header data-chat-head hidden class="flex items-center gap-3 border-b border-border px-4 py-3">
          <button type="button" data-chat-back class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-muted hover:bg-surface-2 lg:hidden cursor-pointer" aria-label="Back to conversations"><?= icon('arrow-left', 16) ?></button>
          <span data-chat-avatar class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-sm font-semibold text-white"></span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold" data-chat-name></p>
            <p class="flex items-center gap-1.5 truncate text-xs text-muted" data-chat-state></p>
          </div>
          <div class="flex shrink-0 items-center gap-1.5">
            <a data-chat-ticket hidden class="<?= btn_cls('outline', 'sm') ?>"></a>
            <div class="relative" data-transfer-wrap>
              <button type="button" data-transfer-btn class="<?= btn_cls('outline', 'sm') ?>" title="Pass this chat to someone else"><?= icon('arrow-right-left', 14) ?><span class="max-2xl:hidden"> Transfer</span></button>
              <div data-transfer-menu hidden class="absolute right-0 top-full z-20 mt-1 w-56 overflow-hidden rounded-xl border border-border bg-surface py-1 shadow-xl"></div>
            </div>
            <button type="button" data-chat-op="ticket" class="<?= btn_cls('outline', 'sm') ?>" title="Save the chat as a ticket"><?= icon('ticket', 14) ?><span class="max-2xl:hidden"> To ticket</span></button>
            <button type="button" data-chat-op="close" class="<?= btn_cls('ghost', 'sm') ?> text-red-600" title="End the chat"><?= icon('circle-x', 14) ?><span class="max-2xl:hidden"> End</span></button>
            <button type="button" data-info-toggle class="grid h-8 w-8 place-items-center rounded-lg text-muted hover:bg-surface-2 xl:hidden cursor-pointer" aria-label="Customer details"><?= icon('info', 16) ?></button>
          </div>
        </header>
        <div data-chat-log hidden class="min-h-0 flex-1 overflow-y-auto bg-surface-2/40 px-4 py-4" aria-live="polite"></div>
        <div data-chat-typing hidden class="flex items-center gap-2 bg-surface-2/40 px-5 pb-2 text-xs text-muted">
          <span class="flex gap-0.5"><span class="h-1.5 w-1.5 animate-bounce rounded-full bg-muted [animation-delay:-0.3s]"></span><span class="h-1.5 w-1.5 animate-bounce rounded-full bg-muted [animation-delay:-0.15s]"></span><span class="h-1.5 w-1.5 animate-bounce rounded-full bg-muted"></span></span>
          <span data-typing-name></span> is typing…
        </div>
        <div data-uploads class="space-y-1 bg-surface-2/40 px-4 empty:hidden"></div>
        <form data-chat-reply hidden class="border-t border-border p-3">
          <div class="relative">
            <div data-canned-menu hidden class="absolute bottom-full left-0 z-20 mb-2 max-h-64 w-full max-w-md overflow-y-auto rounded-xl border border-border bg-surface py-1 shadow-xl"></div>
            <div class="flex items-end gap-2 rounded-xl border border-border bg-surface p-1.5 focus-within:ring-2 focus-within:ring-brand/40">
              <button type="button" data-canned-btn class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-muted hover:bg-surface-2 hover:text-fg cursor-pointer" title="Quick replies (type /)" aria-label="Quick replies"><?= icon('zap', 17) ?></button>
              <button type="button" data-emoji-btn class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-muted hover:bg-surface-2 hover:text-fg cursor-pointer" title="Emoji" aria-label="Emoji"><?= icon('smile', 17) ?></button>
              <button type="button" data-attach-btn class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-muted hover:bg-surface-2 hover:text-fg cursor-pointer" title="Attach a file (or drop / paste it)" aria-label="Attach a file"><?= icon('paperclip', 17) ?></button>
              <input type="file" data-file multiple hidden>
              <textarea rows="1" placeholder="Write a reply…" class="max-h-40 min-h-9 flex-1 resize-none border-0 bg-transparent px-1 py-2 text-sm focus:outline-none"></textarea>
              <button type="submit" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand text-brand-fg hover:opacity-90 cursor-pointer" aria-label="Send"><?= icon('send-horizontal', 17) ?></button>
            </div>
          </div>
          <p class="mt-1.5 px-1 text-[11px] text-muted max-sm:hidden"><kbd class="rounded border border-border px-1">Enter</kbd> send · <kbd class="rounded border border-border px-1">Shift</kbd>+<kbd class="rounded border border-border px-1">Enter</kbd> new line · <kbd class="rounded border border-border px-1">/</kbd> quick replies · <kbd class="rounded border border-border px-1">Esc</kbd> close</p>
        </form>
        <div data-chat-ended hidden class="flex items-center justify-center gap-2 border-t border-border p-4 text-sm text-muted"><?= icon('circle-check', 15) ?> <span data-ended-text>This chat has ended.</span></div>
      </section>

      <!-- Customer details -->
      <aside data-chat-info class="hidden min-h-0 flex-col overflow-y-auto rounded-xl border border-border bg-surface xl:flex max-xl:fixed max-xl:inset-y-0 max-xl:right-0 max-xl:z-50 max-xl:w-[min(20rem,100vw)] max-xl:rounded-none max-xl:shadow-2xl">
        <div class="flex items-center justify-between border-b border-border px-4 py-3">
          <h3 class="text-sm font-semibold">Customer</h3>
          <button type="button" data-info-close class="grid h-8 w-8 place-items-center rounded-lg text-muted hover:bg-surface-2 xl:hidden cursor-pointer" aria-label="Close"><?= icon('x', 16) ?></button>
        </div>
        <div data-info-body class="p-4 text-sm text-muted">Pick a conversation to see who you’re talking to.</div>
      </aside>
    </div>
  </div>
</div>
<script>
(function () {
  var BOOT = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var root = document.querySelector("[data-agent-chat]");
  var $ = function (s) { return root.querySelector(s); };
  var list = $("[data-chat-list]"), log = $("[data-chat-log]"), reply = $("[data-chat-reply]"), ta = reply.querySelector("textarea");
  var listPane = $('[data-chat-pane="list"]'), convPane = $('[data-chat-pane="conv"]'), info = $("[data-chat-info]");
  var cannedMenu = $("[data-canned-menu]"), transferMenu = $("[data-transfer-menu]");
  var cur = null, curStatus = "", after = "", seen = {}, convTimer = null, known = {}, firstLoad = true, items = [], lastDay = "", lastSender = "";
  var filter = "open", query = "", typingSent = 0;
  var csrf = (window.APP || {}).csrf || "";
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };
  var soundOn = store.get("tos-chat-sound") !== "0", desktopOn = store.get("tos-chat-desktop") === "1";

  var fold = function (s) { s = s == null ? "" : String(s); var o = ""; for (var i = 0; i < s.length; i++) { var c = s.charAt(i).normalize("NFD").charAt(0).toLowerCase(); o += c === "ς" ? "σ" : c; } return o; };
  var esc = function (s) { var d = document.createElement("div"); d.textContent = s == null ? "" : String(s); return d.innerHTML; };
  var pad = function (n) { return (n < 10 ? "0" : "") + n; };
  var time = function (iso) { var d = new Date(iso); return pad(d.getHours()) + ":" + pad(d.getMinutes()); };
  var dayKey = function (iso) { var d = new Date(iso); return d.getFullYear() + "-" + d.getMonth() + "-" + d.getDate(); };
  function dayLabel(iso) {
    var d = new Date(iso), t = new Date(), y = new Date(); y.setDate(t.getDate() - 1);
    if (dayKey(iso) === dayKey(t.toISOString())) return "Today";
    if (dayKey(iso) === dayKey(y.toISOString())) return "Yesterday";
    return d.toLocaleDateString(undefined, { day: "numeric", month: "short", year: d.getFullYear() === t.getFullYear() ? undefined : "numeric" });
  }
  function ago(iso) {
    var s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return "just now";
    if (s < 3600) return Math.floor(s / 60) + "m";
    if (s < 86400) return Math.floor(s / 3600) + "h " + Math.floor((s % 3600) / 60) + "m";
    return Math.floor(s / 86400) + "d";
  }
  function initials(name) { var p = String(name || "?").trim().split(/\s+/); return ((p[0] || "?")[0] + (p.length > 1 ? p[p.length - 1][0] : "")).toUpperCase(); }
  function hue(s) { var h = 0; s = String(s || ""); for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) % 360; return "hsl(" + h + " 55% 48%)"; }
  function avatar(name, size) { return '<span class="grid ' + (size || "h-9 w-9") + ' shrink-0 place-items-center rounded-full text-xs font-semibold text-white" style="background:' + hue(name) + '">' + esc(initials(name)) + "</span>"; }

  function get(url) { return fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } }).then(function (r) { return r.json(); }); }
  function post(url, body) {
    return fetch(url, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": csrf, "X-Requested-With": "fetch" }, body: JSON.stringify(body) }).then(function (r) { return r.json(); });
  }
  function toast(msg, err) { if (window.TOS && window.TOS.toast) window.TOS.toast(msg, null, !!err); }
  function ding() {
    if (!soundOn) return;
    try { var a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain(); o.frequency.value = 880; g.gain.value = 0.05; o.connect(g); g.connect(a.destination); o.start(); o.frequency.setValueAtTime(1175, a.currentTime + 0.08); o.stop(a.currentTime + 0.18); } catch (e) {}
  }
  function desktop(it) {
    if (!desktopOn || !document.hidden || !("Notification" in window) || Notification.permission !== "granted") return;
    try { var n = new Notification(it.name + " · Live chat", { body: it.last ? it.last.text : "New chat", tag: "chat-" + it.id }); n.onclick = function () { window.focus(); openChat(it.id); n.close(); }; } catch (e) {}
  }

  /* ---------- Toolbar toggles ---------- */
  var soundBtn = document.querySelector("[data-chat-sound]"), deskBtn = document.querySelector("[data-chat-desktop]");
  function paintToggles() {
    soundBtn.querySelector("span").textContent = soundOn ? "Sound on" : "Sound off";
    soundBtn.classList.toggle("text-brand", soundOn);
    deskBtn.classList.toggle("text-brand", desktopOn);
    deskBtn.querySelector("span").textContent = desktopOn ? "Desktop alerts on" : "Desktop alerts";
  }
  soundBtn.addEventListener("click", function () { soundOn = !soundOn; store.set("tos-chat-sound", soundOn ? "1" : "0"); paintToggles(); if (soundOn) ding(); });
  deskBtn.addEventListener("click", function () {
    if (!("Notification" in window)) { toast("This browser doesn’t support desktop notifications", true); return; }
    if (desktopOn) { desktopOn = false; store.set("tos-chat-desktop", "0"); paintToggles(); return; }
    Notification.requestPermission().then(function (p) {
      desktopOn = p === "granted"; store.set("tos-chat-desktop", desktopOn ? "1" : "0"); paintToggles();
      if (!desktopOn) toast("Notifications are blocked for this site in your browser settings", true);
    });
  });
  paintToggles();

  /* ---------- Conversation list ---------- */
  function matches(it, f) {
    if (f === "open") return it.status === "open";
    if (f === "waiting") return it.status === "open" && it.waiting;
    if (f === "mine") return it.status === "open" && (it.mine || (it.forMe && !it.agent));
    return it.status !== "open";
  }
  function renderList() {
    var counts = { open: 0, waiting: 0, mine: 0, ended: 0 };
    items.forEach(function (it) { ["open", "waiting", "mine", "ended"].forEach(function (f) { if (matches(it, f)) counts[f]++; }); });
    Object.keys(counts).forEach(function (k) {
      var el = $('[data-count="' + k + '"]'); if (el) el.textContent = counts[k] ? " " + counts[k] : "";
      var st = $('[data-stat="' + (k === "open" ? "active" : k) + '"]');
      if (st) st.textContent = k === "open" ? counts.open - counts.waiting : counts[k];
    });
    root.querySelectorAll("[data-filter]").forEach(function (b) {
      var on = b.getAttribute("data-filter") === filter;
      b.classList.toggle("bg-surface", on); b.classList.toggle("shadow-sm", on); b.classList.toggle("text-fg", on); b.classList.toggle("text-muted", !on);
      b.setAttribute("aria-selected", on ? "true" : "false");
    });
    var q = fold(query);
    var rows = items.filter(function (it) {
      return matches(it, filter) && (!q || fold(it.name + " " + it.email + " " + (it.last ? it.last.text : "")).indexOf(q) !== -1);
    });
    if (!rows.length) {
      var empty = q ? "Nothing matches “" + esc(query) + "”." : { open: "No open chats. You’re all caught up.", waiting: "Nobody is waiting.", mine: "No chats assigned to you.", ended: "No chats ended in the last 24 hours." }[filter];
      list.innerHTML = '<li class="px-6 py-12 text-center text-sm text-muted">' + empty + "</li>";
      return;
    }
    list.innerHTML = rows.map(function (it) {
      var badge = "";
      if (it.status === "open" && it.forMe && !it.agent) badge = '<span class="rounded-full bg-brand/10 px-1.5 py-0.5 text-[10px] font-semibold text-brand">For you</span>';
      if (it.rating === 1) badge += '<span class="text-emerald-600" title="Rated good">👍</span>';
      if (it.rating === -1) badge += '<span class="text-red-600" title="Rated bad">👎</span>';
      var line = it.status !== "open" ? "Ended" + (it.agent ? " · " + esc(it.agent) : "")
        : it.agent ? (it.mine ? "With you" : "With " + esc(it.agent))
        : it.requested ? "Asked for " + (it.forMe ? "you" : esc(it.requested))
        : "Waiting for an agent";
      if (it.status === "open" && it.waiting && it.waitingSince) line += ' · <span class="font-medium text-amber-600">waiting ' + ago(it.waitingSince) + "</span>";
      var preview = it.typing ? '<span class="italic text-brand">typing…</span>' : it.last ? (it.last.from === "agent" ? "You: " : "") + esc(it.last.text) : "";
      var dot = it.status !== "open" ? "bg-slate-300 dark:bg-slate-600" : it.waiting ? "bg-amber-500 animate-pulse" : "bg-emerald-500";
      return '<li><button type="button" data-open="' + esc(it.id) + '" class="relative flex w-full items-start gap-3 border-b border-border px-3 py-3 text-left transition-colors hover:bg-surface-2 cursor-pointer' + (cur === it.id ? " bg-brand/5 before:absolute before:inset-y-0 before:left-0 before:w-0.5 before:bg-brand" : "") + '">' +
        '<span class="relative">' + avatar(it.name) + '<span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-surface ' + dot + '"></span></span>' +
        '<span class="min-w-0 flex-1"><span class="flex items-center gap-1.5"><span class="truncate text-sm ' + (it.unread && it.status === "open" ? "font-semibold" : "font-medium") + '">' + esc(it.name) + "</span>" + badge +
        '<span class="ml-auto shrink-0 text-[11px] text-muted">' + (it.last ? time(it.last.at) : "") + "</span></span>" +
        '<span class="flex items-center gap-1.5"><span class="block min-w-0 flex-1 truncate text-xs text-muted">' + preview + "</span>" +
        (it.unread && it.status === "open" ? '<span class="h-2 w-2 shrink-0 rounded-full bg-brand"></span>' : "") + "</span>" +
        '<span class="block truncate text-[11px] text-muted">' + line + (it.ticketId ? " · ticket" : "") + "</span></span></button></li>";
    }).join("");
  }
  function loadList() {
    return get("/api/chat/list").then(function (j) {
      items = j.items || [];
      document.title = (j.waiting ? "(" + j.waiting + ") " : "") + "Live chat";
      var fresh = items.filter(function (it) { return it.waiting && it.unread && known[it.id] !== (it.last && it.last.at); });
      if (fresh.length && !firstLoad) { ding(); fresh.forEach(desktop); }
      firstLoad = false;
      items.forEach(function (it) { known[it.id] = it.last && it.last.at; });
      renderList();
    }, function () {
      if (firstLoad) list.innerHTML = '<li class="px-6 py-12 text-center text-sm text-muted">Couldn’t load the chats. Retrying…</li>';
    });
  }
  root.querySelectorAll("[data-filter]").forEach(function (b) { b.addEventListener("click", function () { filter = b.getAttribute("data-filter"); store.set("tos-chat-filter", filter); renderList(); }); });
  var saved = store.get("tos-chat-filter"); if (saved && /^(open|waiting|mine|ended)$/.test(saved)) filter = saved;
  $("[data-chat-search]").addEventListener("input", function (e) { query = e.target.value.trim(); renderList(); });

  /* ---------- Messages ---------- */
  function add(m) {
    if (seen[m.id]) return;
    seen[m.id] = 1;
    if (m.at > after) after = m.at;
    var dk = dayKey(m.at);
    if (dk !== lastDay) {
      lastDay = dk; lastSender = "";
      var sep = document.createElement("div");
      sep.className = "my-3 flex items-center gap-3 text-[11px] font-medium uppercase tracking-wide text-muted";
      sep.innerHTML = '<span class="h-px flex-1 bg-border"></span>' + esc(dayLabel(m.at)) + '<span class="h-px flex-1 bg-border"></span>';
      log.appendChild(sep);
    }
    var row = document.createElement("div");
    if (m.sender === "system") {
      row.className = "my-2 flex justify-center";
      row.innerHTML = '<span class="rounded-full bg-surface px-3 py-1 text-center text-xs text-muted shadow-sm ring-1 ring-border"></span>';
      row.firstChild.textContent = m.body;
      lastSender = "";
    } else {
      var mine = m.sender === "agent", group = (m.sender + (m.name || "")) === lastSender;
      lastSender = m.sender + (m.name || "");
      row.className = "flex items-end gap-2 " + (mine ? "justify-end" : "justify-start") + (group ? " mt-1" : " mt-3");
      var bubble = '<div class="max-w-[75%] whitespace-pre-wrap break-words rounded-2xl px-3.5 py-2 text-sm shadow-sm ' + (mine ? "rounded-br-md bg-brand text-brand-fg" : "rounded-bl-md border border-border bg-surface") + '"></div>';
      var av = mine ? "" : (group ? '<span class="w-7 shrink-0"></span>' : avatar(curName, "h-7 w-7 text-[10px]"));
      row.innerHTML = av + bubble;
      var b = row.lastChild;
      if (m.file) b.innerHTML = window.ChatKit.fileHtml(m.file, "/api/attachments/" + encodeURIComponent(m.file.id), mine); else b.textContent = m.body;
      var meta = document.createElement("div");
      meta.className = "mt-0.5 text-[10px] " + (mine ? "text-right opacity-70" : "text-muted");
      meta.textContent = (mine && m.name ? m.name + " · " : "") + time(m.at);
      b.appendChild(meta);
    }
    var nearBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 120;
    log.appendChild(row);
    if (nearBottom || m.sender === "agent") log.scrollTop = log.scrollHeight;
  }
  var curName = "";
  function row(label, value) { return value ? '<div class="flex gap-3 py-1.5"><dt class="w-24 shrink-0 text-xs text-muted">' + label + '</dt><dd class="min-w-0 flex-1 break-words text-xs">' + value + "</dd></div>" : ""; }
  function renderInfo(s, status) {
    var dur = s.createdAt ? ago(s.createdAt) : "";
    if (s.closedAt) { var m = Math.round((new Date(s.closedAt) - new Date(s.createdAt)) / 60000); dur = m < 1 ? "under a minute" : m + " min"; }
    var html = '<div class="flex flex-col items-center p-5 text-center">' + avatar(s.name, "h-14 w-14 text-base") +
      '<p class="mt-2 font-semibold text-fg">' + esc(s.name) + '</p><a href="mailto:' + esc(s.email) + '" class="text-xs text-brand hover:underline">' + esc(s.email) + "</a>" +
      (s.customerId ? '<span class="mt-2 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-400">Customer</span>' : '<span class="mt-2 rounded-full bg-surface-2 px-2 py-0.5 text-[11px] font-medium text-muted">New visitor</span>') + "</div>";
    html += '<dl class="divide-y divide-border border-t border-border px-4 py-2">' +
      row("Organization", s.org ? esc(s.org.name) : "") +
      row("Customer since", s.customerSince ? esc(new Date(s.customerSince).toLocaleDateString()) : "") +
      row("Last sign-in", s.lastLogin ? esc(ago(s.lastLogin)) + " ago" : "") +
      row("Chat started", esc(time(s.createdAt)) + " · " + esc(dayLabel(s.createdAt))) +
      row(s.closedAt ? "Lasted" : "Going on", esc(dur)) +
      row("Agent", s.agent ? esc(s.agent) : (status === "open" ? "Nobody yet" : "")) +
      row("Asked for", s.requested ? esc(s.requested) : "") +
      row("Rating", s.rating === 1 ? "👍 Good" : s.rating === -1 ? "👎 Bad" : "") +
      row("Page", s.page ? '<a href="' + esc(s.page) + '" target="_blank" rel="noopener" class="text-brand hover:underline">' + esc(s.page.replace(/^https?:\/\//, "")) + "</a>" : "") +
      "</dl>";
    html += '<div class="border-t border-border px-4 py-3"><p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Recent tickets' + (s.knownTickets ? " · " + s.knownTickets : "") + "</p>";
    if (!s.recent || !s.recent.length) html += '<p class="text-xs text-muted">No tickets yet.</p>';
    else html += '<ul class="space-y-1">' + s.recent.map(function (t) {
      return '<li><a href="/tickets/' + esc(t.id) + '" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs hover:bg-surface-2"><span class="h-2 w-2 shrink-0 rounded-full" style="background:' + esc(t.color) + '"></span><span class="min-w-0 flex-1 truncate ' + (t.closed ? "text-muted" : "text-fg") + '">#' + t.number + " " + esc(t.subject) + '</span><span class="shrink-0 text-[10px] text-muted">' + esc(t.status) + "</span></a></li>";
    }).join("") + "</ul>";
    html += "</div>";
    info.querySelector("[data-info-body]").innerHTML = html;
  }
  function loadConv() {
    if (!cur) return;
    var id = cur;
    return get("/api/chat/messages?id=" + encodeURIComponent(id) + "&after=" + encodeURIComponent(after)).then(function (j) {
      if (id !== cur || j.error) return;
      var s = j.session;
      curName = s.name;
      (j.messages || []).forEach(add);
      curStatus = j.status;
      $("[data-chat-name]").textContent = s.name;
      var av = $("[data-chat-avatar]"); av.textContent = initials(s.name); av.style.background = hue(s.name);
      var open = j.status === "open";
      $("[data-chat-state]").innerHTML = open
        ? '<span class="h-2 w-2 rounded-full bg-emerald-500"></span>' + (s.typing ? '<span class="text-brand">typing…</span>' : esc(s.email) + (s.agent ? " · with " + esc(s.agent) : s.requested ? " · asked for " + esc(s.requested) : ""))
        : '<span class="h-2 w-2 rounded-full bg-slate-400"></span>Ended · ' + esc(s.email);
      $("[data-typing-name]").textContent = s.name.split(" ")[0];
      $("[data-chat-typing]").hidden = !(open && s.typing);
      var tl = $("[data-chat-ticket]");
      tl.hidden = !s.ticket;
      if (s.ticket) { tl.href = "/tickets/" + s.ticket.id; tl.innerHTML = "#" + s.ticket.number; tl.title = "Open ticket #" + s.ticket.number; }
      reply.hidden = !open;
      $("[data-chat-ended]").hidden = open;
      $("[data-ended-text]").textContent = "This chat has ended" + (s.rating === 1 ? " · the customer rated it 👍" : s.rating === -1 ? " · the customer rated it 👎" : "") + ".";
      $('[data-chat-op="close"]').hidden = !open;
      $("[data-transfer-wrap]").hidden = !open;
      $('[data-chat-op="ticket"]').hidden = !!s.ticket && !open;
      renderInfo(s, j.status);
      clearTimeout(convTimer);
      if (open) convTimer = setTimeout(loadConv, document.hidden ? 8000 : 2500);
    });
  }
  function openChat(id) {
    cur = id; after = ""; seen = {}; log.innerHTML = ""; lastDay = ""; lastSender = "";
    $("[data-chat-empty]").hidden = true;
    $("[data-chat-head]").hidden = false;
    log.hidden = false;
    convPane.classList.remove("max-lg:hidden");
    listPane.classList.add("max-lg:hidden");
    history.replaceState(null, "", "/chat?c=" + encodeURIComponent(id));
    loadConv();
    renderList();
    if (window.matchMedia("(min-width: 1024px)").matches) ta.focus();
  }
  function closeChatView() {
    cur = null; clearTimeout(convTimer);
    convPane.classList.add("max-lg:hidden"); listPane.classList.remove("max-lg:hidden");
    history.replaceState(null, "", "/chat");
    renderList();
  }
  list.addEventListener("click", function (e) { var b = e.target.closest("[data-open]"); if (b) openChat(b.getAttribute("data-open")); });
  $("[data-chat-back]").addEventListener("click", closeChatView);

  /* ---------- Customer panel (small screens) ---------- */
  // On small screens the panel slides over the page; it moves to <body> because the page
  // wrapper is animated (a transform would pin the "fixed" panel inside it).
  var infoHome = info.parentNode;
  function showInfo(on) {
    if (on && info.parentNode !== document.body) document.body.appendChild(info);
    if (!on && info.parentNode !== infoHome) infoHome.appendChild(info);
    info.classList.toggle("max-xl:hidden", !on); info.classList.toggle("max-xl:flex", on);
  }
  window.matchMedia("(min-width: 1280px)").addEventListener("change", function (e) { if (e.matches) showInfo(false); });
  info.classList.add("max-xl:hidden");
  $("[data-info-toggle]").addEventListener("click", function () { showInfo(true); });
  info.querySelector("[data-info-close]").addEventListener("click", function () { showInfo(false); });

  /* ---------- Composer ---------- */
  ta.addEventListener("input", function () {
    ta.style.height = "auto"; ta.style.height = Math.min(160, ta.scrollHeight) + "px";
    if (ta.value.charAt(0) === "/") openCanned(ta.value.slice(1)); else cannedMenu.hidden = true;
    if (cur && ta.value.trim() && Date.now() - typingSent > 3000) { typingSent = Date.now(); post("/api/chat/action", { id: cur, op: "typing" }); }
  });
  ta.addEventListener("keydown", function (e) {
    if (!cannedMenu.hidden) {
      var opts = cannedMenu.querySelectorAll("[data-canned]"), act = cannedMenu.querySelector("[data-active]"), i = Array.prototype.indexOf.call(opts, act);
      if (e.key === "ArrowDown" || e.key === "ArrowUp") { e.preventDefault(); if (!opts.length) return; i = (i + (e.key === "ArrowDown" ? 1 : -1) + opts.length) % opts.length; markCanned(opts[i]); return; }
      if (e.key === "Enter" && act) { e.preventDefault(); useCanned(+act.getAttribute("data-canned")); return; }
      if (e.key === "Escape") { cannedMenu.hidden = true; return; }
    }
    if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); reply.requestSubmit(); }
  });
  reply.addEventListener("submit", function (e) {
    e.preventDefault();
    var body = ta.value.trim();
    if (!body || !cur) return;
    ta.value = ""; ta.style.height = ""; cannedMenu.hidden = true;
    post("/api/chat/reply", { id: cur, body: body }).then(function (j) {
      if (j.error) { toast(j.error, true); ta.value = body; return; }
      loadConv(); loadList();
    }, function () { toast("Network error — your message wasn’t sent", true); ta.value = body; });
  });

  /* Emoji and files */
  window.ChatKit.emojiPicker($("[data-emoji-btn]"), ta);
  window.ChatKit.attach($("[data-attach-btn]"), $("[data-file]"), convPane, function (file) {
    if (!cur || curStatus !== "open") { toast("Open a live conversation first", true); return; }
    var box = $("[data-uploads]"), r = document.createElement("div");
    r.className = "flex items-center gap-2 rounded-lg bg-surface px-3 py-2 text-xs ring-1 ring-border";
    r.innerHTML = '<span class="min-w-0 flex-1 truncate">' + esc(file.name) + '</span><span class="h-1.5 w-24 overflow-hidden rounded-full bg-surface-2"><span class="block h-full w-0 bg-brand transition-all"></span></span>';
    box.appendChild(r);
    window.ChatKit.upload(file, { url: "/api/chat/upload", fields: { id: cur }, csrf: csrf, onProgress: function (p) { r.querySelector(".bg-brand").style.width = Math.round(p * 100) + "%"; } }).then(function (j) {
      r.remove();
      if (j.error) { toast(j.error, true); return; }
      loadConv(); loadList();
    });
  });

  /* Quick replies (canned responses) */
  function markCanned(el) { cannedMenu.querySelectorAll("[data-active]").forEach(function (x) { x.removeAttribute("data-active"); x.classList.remove("bg-surface-2"); }); if (el) { el.setAttribute("data-active", ""); el.classList.add("bg-surface-2"); el.scrollIntoView({ block: "nearest" }); } }
  function openCanned(q) {
    q = fold(q || "");
    if (!BOOT.canned.length) {
      cannedMenu.innerHTML = '<p class="px-3 py-3 text-xs text-muted">No quick replies yet. Admins add them in Settings → Canned replies.</p>';
      cannedMenu.hidden = false; return;
    }
    var html = BOOT.canned.map(function (c, i) {
      if (q && fold(c.title + " " + c.text).indexOf(q) === -1) return "";
      return '<button type="button" data-canned="' + i + '" class="block w-full px-3 py-2 text-left hover:bg-surface-2 cursor-pointer"><span class="block text-sm font-medium">' + esc(c.title) + '</span><span class="block truncate text-xs text-muted">' + esc(c.text) + "</span></button>";
    }).join("");
    cannedMenu.innerHTML = html || '<p class="px-3 py-3 text-xs text-muted">No quick reply matches.</p>';
    cannedMenu.hidden = false;
    markCanned(cannedMenu.querySelector("[data-canned]"));
  }
  function useCanned(i) {
    var c = BOOT.canned[i]; if (!c) return;
    var first = (curName || "").split(" ")[0];
    ta.value = c.text.replace(/\{customer\.name\}/g, curName).replace(/\{customer\.first_?name\}/g, first);
    cannedMenu.hidden = true; ta.focus(); ta.dispatchEvent(new Event("input"));
  }
  $("[data-canned-btn]").addEventListener("click", function () { if (cannedMenu.hidden) openCanned(""); else cannedMenu.hidden = true; });
  cannedMenu.addEventListener("click", function (e) { var b = e.target.closest("[data-canned]"); if (b) useCanned(+b.getAttribute("data-canned")); });

  /* ---------- Transfer ---------- */
  $("[data-transfer-btn]").addEventListener("click", function (e) {
    e.stopPropagation();
    var others = BOOT.agents;
    transferMenu.innerHTML = '<p class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted">Pass the chat to</p>' + (others.length ? others.map(function (a) {
      return '<button type="button" data-transfer="' + esc(a.id) + '" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-surface-2 cursor-pointer">' + avatar(a.name, "h-6 w-6 text-[9px]") + '<span class="truncate">' + esc(a.name) + (a.id === BOOT.me ? " (you)" : "") + "</span></button>";
    }).join("") : '<p class="px-3 py-2 text-xs text-muted">Nobody else answers chats.</p>');
    transferMenu.hidden = !transferMenu.hidden;
  });
  transferMenu.addEventListener("click", function (e) {
    var b = e.target.closest("[data-transfer]"); if (!b || !cur) return;
    transferMenu.hidden = true;
    post("/api/chat/action", { id: cur, op: "transfer", agentId: b.getAttribute("data-transfer") }).then(function (j) {
      if (j.error) { toast(j.error, true); return; }
      toast("Chat passed on"); loadConv(); loadList();
    });
  });
  document.addEventListener("click", function (e) { if (!e.target.closest("[data-transfer-wrap]")) transferMenu.hidden = true; if (!e.target.closest("[data-chat-reply]")) cannedMenu.hidden = true; });

  /* ---------- Actions ---------- */
  root.addEventListener("click", function (e) {
    var b = e.target.closest("[data-chat-op]");
    if (!b || !cur) return;
    var op = b.getAttribute("data-chat-op");
    if (op === "close" && !confirm("End this chat? It’s saved as a ticket.")) return;
    b.disabled = true;
    post("/api/chat/action", { id: cur, op: op }).then(function (j) {
      b.disabled = false;
      if (j.error) { toast(j.error, true); return; }
      if (j.ticket) toast("Ticket #" + j.ticket.number + " created");
      loadConv(); loadList();
    });
  });
  document.addEventListener("keydown", function (e) {
    if (e.key !== "Escape" || !cannedMenu.hidden) return;
    if (!info.classList.contains("max-xl:hidden") && !window.matchMedia("(min-width: 1280px)").matches) { showInfo(false); return; }
    if (cur && document.activeElement !== $("[data-chat-search]")) closeChatView();
  });
  document.addEventListener("visibilitychange", function () { if (!document.hidden) { loadList(); if (cur && curStatus === "open") { clearTimeout(convTimer); loadConv(); } } });

  loadList().then(function () {
    var want = new URLSearchParams(location.search).get("c");
    if (want) openChat(want);
  });
  setInterval(loadList, 5000);
  setInterval(function () { if (items.some(function (it) { return it.waiting; })) renderList(); }, 30000);
})();
</script>
