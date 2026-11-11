<?php
/**
 * In-app messenger for customers (opened by the chat button at the bottom right, in an iframe).
 * Talk to the support team, administrators or colleagues of the same organization.
 */
[$me, $tenantId] = require_tenant_user();
if ($me['role'] !== 'CUSTOMER' || !chat_settings($tenantId)['enabled']) abort(404);
$cfg = chat_settings($tenantId);
$tenantName = (string) q_val('SELECT "name" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
page_title('Messages');
$iconBtn = 'grid h-9 w-9 shrink-0 place-items-center rounded-lg cursor-pointer';
?>
<div class="flex h-dvh flex-col overflow-hidden bg-surface text-fg" data-m>
  <!-- Home: conversations -->
  <section data-screen="home" class="flex min-h-0 flex-1 flex-col">
    <header class="flex items-center gap-3 bg-brand px-4 py-3 text-brand-fg">
      <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-white/20"><?= icon('messages-square', 18) ?></span>
      <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold"><?= e($cfg['title']) ?></p><p class="truncate text-xs opacity-80"><?= e($tenantName) ?></p></div>
      <button type="button" data-go-new class="flex h-9 items-center gap-1.5 rounded-lg bg-white/15 px-3 text-xs font-semibold hover:bg-white/25 cursor-pointer"><?= icon('plus', 15) ?> New</button>
    </header>
    <ul data-list class="min-h-0 flex-1 overflow-y-auto"></ul>
    <div data-home-empty hidden class="m-auto max-w-xs p-6 text-center">
      <span class="mx-auto mb-3 grid h-14 w-14 place-items-center rounded-2xl bg-brand/10 text-brand"><?= icon('messages-square', 26) ?></span>
      <p class="font-semibold">Hi <?= e(first_name($me['name'])) ?> 👋</p>
      <p class="mt-1 text-sm text-muted"><?= e($cfg['greeting']) ?></p>
      <button type="button" data-go-new class="mt-4 inline-flex h-10 items-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-brand-fg cursor-pointer"><?= icon('send', 15) ?> Start a conversation</button>
    </div>
  </section>

  <!-- New conversation: pick a person -->
  <section data-screen="new" hidden class="flex min-h-0 flex-1 flex-col">
    <header class="flex items-center gap-2 border-b border-border px-2 py-2">
      <button type="button" data-back class="<?= $iconBtn ?> text-muted hover:bg-surface-2" aria-label="Back"><?= icon('arrow-left', 17) ?></button>
      <p class="flex-1 text-sm font-semibold">New conversation</p>
    </header>
    <div class="border-b border-border p-3">
      <label class="relative block">
        <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-muted"><?= icon('search', 15) ?></span>
        <input type="search" data-people-search placeholder="Search people" class="h-9 w-full rounded-lg border border-border bg-surface-2/60 pl-8 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
      </label>
    </div>
    <div data-people class="min-h-0 flex-1 overflow-y-auto pb-3"></div>
  </section>

  <!-- A conversation -->
  <section data-screen="thread" hidden class="relative flex min-h-0 flex-1 flex-col" data-drop>
    <header class="flex items-center gap-2 border-b border-border px-2 py-2">
      <button type="button" data-back class="<?= $iconBtn ?> text-muted hover:bg-surface-2" aria-label="Back"><?= icon('arrow-left', 17) ?></button>
      <span data-t-avatar class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-semibold text-white"></span>
      <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold" data-t-name></p><p class="truncate text-xs text-muted" data-t-sub></p></div>
      <button type="button" data-end hidden class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-500/10 cursor-pointer">End</button>
    </header>
    <div data-log class="min-h-0 flex-1 overflow-y-auto bg-surface-2/40 px-3 py-3" aria-live="polite"></div>
    <div data-typing hidden class="flex items-center gap-2 bg-surface-2/40 px-4 pb-2 text-xs text-muted">
      <span class="flex gap-0.5"><span class="h-1.5 w-1.5 animate-bounce rounded-full bg-muted [animation-delay:-0.3s]"></span><span class="h-1.5 w-1.5 animate-bounce rounded-full bg-muted [animation-delay:-0.15s]"></span><span class="h-1.5 w-1.5 animate-bounce rounded-full bg-muted"></span></span>
      <span data-typing-name></span> is typing…
    </div>
    <div data-uploads class="space-y-1 bg-surface-2/40 px-3 empty:hidden"></div>
    <form data-compose class="border-t border-border p-2">
      <div class="flex items-end gap-1 rounded-xl border border-border bg-surface p-1 focus-within:ring-2 focus-within:ring-brand/40">
        <button type="button" data-emoji-btn class="<?= $iconBtn ?> text-muted hover:bg-surface-2 hover:text-fg" aria-label="Emoji"><?= icon('smile', 18) ?></button>
        <button type="button" data-attach-btn class="<?= $iconBtn ?> text-muted hover:bg-surface-2 hover:text-fg" aria-label="Attach a file"><?= icon('paperclip', 18) ?></button>
        <input type="file" data-file multiple hidden>
        <textarea rows="1" placeholder="Write a message…" class="max-h-32 min-h-9 flex-1 resize-none border-0 bg-transparent px-1 py-2 text-sm focus:outline-none"></textarea>
        <button type="submit" class="<?= $iconBtn ?> bg-brand text-brand-fg hover:opacity-90" aria-label="Send"><?= icon('send-horizontal', 17) ?></button>
      </div>
    </form>
    <div data-ended hidden class="border-t border-border p-4 text-center">
      <p class="text-sm text-muted" data-ended-text>This conversation has ended.</p>
      <div data-rate hidden class="mt-3">
        <p class="text-sm font-medium">How did we do?</p>
        <div class="mt-2 flex justify-center gap-2">
          <button type="button" data-rate-v="1" class="rounded-full border border-border px-4 py-1.5 text-lg hover:bg-surface-2 cursor-pointer" aria-label="Good">👍</button>
          <button type="button" data-rate-v="-1" class="rounded-full border border-border px-4 py-1.5 text-lg hover:bg-surface-2 cursor-pointer" aria-label="Bad">👎</button>
        </div>
      </div>
      <p data-thanks hidden class="mt-2 text-sm text-emerald-600">Thanks for the feedback!</p>
      <button type="button" data-go-new class="mt-3 text-sm font-semibold text-brand underline cursor-pointer">Start a new conversation</button>
    </div>
  </section>
</div>
<script>
(function () {
  var K = window.ChatKit, csrf = (window.APP || {}).csrf || "";
  var root = document.querySelector("[data-m]"), $ = function (s) { return root.querySelector(s); }, $$ = function (s) { return root.querySelectorAll(s); };
  var list = $("[data-list]"), people = $("[data-people]"), log = $("[data-log]"), compose = $("[data-compose]"), ta = compose.querySelector("textarea");
  var state = { screen: "home", items: [], groups: [], cur: null, target: null, after: "", seen: {}, timer: null, typingSent: 0, lastDay: "", lastKey: "", other: null, live: false, rated: false };
  var esc = K.esc, HEADSET = <?= json_encode(icon('headset', 18)) ?>;
  var fold = function (s) { s = s == null ? "" : String(s); var o = ""; for (var i = 0; i < s.length; i++) { var c = s.charAt(i).normalize("NFD").charAt(0).toLowerCase(); o += c === "ς" ? "σ" : c; } return o; };
  var pad = function (n) { return (n < 10 ? "0" : "") + n; };
  var time = function (iso) { var d = new Date(iso); return pad(d.getHours()) + ":" + pad(d.getMinutes()); };
  var dayKey = function (iso) { var d = new Date(iso); return d.getFullYear() + "-" + d.getMonth() + "-" + d.getDate(); };
  function dayLabel(iso) { var t = new Date(), y = new Date(); y.setDate(t.getDate() - 1); if (dayKey(iso) === dayKey(t.toISOString())) return "Today"; if (dayKey(iso) === dayKey(y.toISOString())) return "Yesterday"; return new Date(iso).toLocaleDateString(undefined, { day: "numeric", month: "short" }); }
  function shortTime(iso) { return dayKey(iso) === dayKey(new Date().toISOString()) ? time(iso) : new Date(iso).toLocaleDateString(undefined, { day: "numeric", month: "short" }); }
  function initials(n) { var p = String(n || "?").trim().split(/\s+/); return ((p[0] || "?")[0] + (p.length > 1 ? p[p.length - 1][0] : "")).toUpperCase(); }
  function color(p) { if (p && p.color) return p.color; var s = p ? p.name : "", h = 0; for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) % 360; return "hsl(" + h + " 55% 48%)"; }
  function avatar(p, cls) { if (!p || !p.id) return '<span class="grid ' + (cls || "h-10 w-10") + ' shrink-0 place-items-center rounded-full bg-brand/10 text-brand">' + HEADSET + '</span>'; return '<span class="grid ' + (cls || "h-10 w-10") + ' shrink-0 place-items-center rounded-full text-xs font-semibold text-white" style="background:' + esc(color(p)) + '">' + esc(initials(p.name)) + "</span>"; }
  var roleCls = { "Super admin": "bg-amber-500/15 text-amber-700 dark:text-amber-300", "Admin": "bg-indigo-500/10 text-indigo-700 dark:text-indigo-300", "Support": "bg-sky-500/10 text-sky-700 dark:text-sky-300", "Agent": "bg-sky-500/10 text-sky-700 dark:text-sky-300", "Colleague": "bg-emerald-500/10 text-emerald-700 dark:text-emerald-300" };
  function role(r) { return r ? '<span class="rounded-full px-1.5 py-0.5 text-[10px] font-medium ' + (roleCls[r] || "bg-surface-2 text-muted") + '">' + esc(r) + "</span>" : ""; }

  function get(u) { return fetch(u, { credentials: "same-origin", headers: { Accept: "application/json" } }).then(function (r) { return r.json(); }); }
  function post(u, b) { return fetch(u, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify(b) }).then(function (r) { return r.json(); }); }
  function tell(msg) { try { if (window.parent !== window) window.parent.postMessage(msg, location.origin); } catch (e) {} }

  function show(screen) {
    state.screen = screen;
    $$("[data-screen]").forEach(function (s) { s.hidden = s.getAttribute("data-screen") !== screen; });
    if (screen !== "thread") { clearTimeout(state.timer); state.cur = null; state.target = null; }
    if (screen === "new") { renderPeople(); setTimeout(function () { $("[data-people-search]").focus(); }, 50); }
    if (screen === "home") loadList();
  }

  /* ---------- Home ---------- */
  function renderList() {
    var items = state.items;
    $("[data-home-empty]").hidden = items.length > 0;
    list.hidden = !items.length;
    list.innerHTML = items.map(function (it) {
      var preview = it.typing ? '<span class="italic text-brand">typing…</span>' : it.last ? (it.last.mine ? "You: " : "") + esc(it.last.text) : "";
      return '<li><button type="button" data-open="' + esc(it.id) + '" class="flex w-full items-center gap-3 border-b border-border px-4 py-3 text-left hover:bg-surface-2 cursor-pointer">' + avatar(it.other) +
        '<span class="min-w-0 flex-1"><span class="flex items-center gap-1.5"><span class="truncate text-sm ' + (it.unread ? "font-semibold" : "font-medium") + '">' + esc(it.other.name) + "</span>" + role(it.other.role) +
        '<span class="ml-auto shrink-0 text-[11px] text-muted">' + (it.last ? shortTime(it.last.at) : "") + "</span></span>" +
        '<span class="flex items-center gap-2"><span class="min-w-0 flex-1 truncate text-xs ' + (it.unread ? "text-fg" : "text-muted") + '">' + preview + "</span>" +
        (it.unread ? '<span class="h-2.5 w-2.5 shrink-0 rounded-full bg-brand"></span>' : it.status !== "open" ? '<span class="shrink-0 text-[10px] text-muted">Ended</span>' : "") + "</span></span></button></li>";
    }).join("");
  }
  function loadList() {
    return get("/api/messenger/list").then(function (j) {
      if (j.error) return;
      state.items = j.items || []; state.groups = j.groups || [];
      tell({ tosChat: "unread", count: j.unread || 0 });
      if (state.screen === "home") renderList();
    });
  }
  list.addEventListener("click", function (e) { var b = e.target.closest("[data-open]"); if (b) openThread(b.getAttribute("data-open")); });
  $$("[data-go-new]").forEach(function (b) { b.addEventListener("click", function () { show("new"); }); });
  $$("[data-back]").forEach(function (b) { b.addEventListener("click", function () { show("home"); }); });

  /* ---------- New ---------- */
  function renderPeople() {
    var q = fold($("[data-people-search]").value.trim());
    var row = function (p, sub) {
      return '<button type="button" data-to="' + esc(p.id || "") + '" class="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-surface-2 cursor-pointer">' + avatar(p, "h-9 w-9") +
        '<span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">' + esc(p.name) + '</span><span class="block truncate text-xs text-muted">' + esc(sub || "") + "</span></span>" + role(p.role) + "</button>";
    };
    var html = "";
    if (!q || fold("anyone available support team").indexOf(q) !== -1) html += '<div class="px-2 pt-3"><div class="overflow-hidden rounded-xl border border-brand/30 bg-brand/5">' + row({ id: "", name: "Anyone available" }, "The first free person on the support team answers") + "</div></div>";
    state.groups.forEach(function (g) {
      var ps = g.people.filter(function (p) { return !q || fold(p.name).indexOf(q) !== -1; });
      if (!ps.length) return;
      html += '<p class="px-4 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wide text-muted">' + esc(g.label) + "</p>" + ps.map(function (p) { return row(p, g.key === "colleagues" ? "Private conversation" : g.key === "admins" ? "Administrator" : "Your support team"); }).join("");
    });
    people.innerHTML = html || '<p class="px-4 py-10 text-center text-sm text-muted">Nobody matches your search.</p>';
  }
  $("[data-people-search]").addEventListener("input", renderPeople);
  people.addEventListener("click", function (e) {
    var b = e.target.closest("[data-to]"); if (!b) return;
    var id = b.getAttribute("data-to"), p = { id: "", name: "Anyone available", role: "" };
    state.groups.forEach(function (g) { g.people.forEach(function (x) { if (x.id === id) p = x; }); });
    startDraft(p);
  });

  /* ---------- Thread ---------- */
  function header(other, sub) {
    var av = $("[data-t-avatar]");
    av.outerHTML = avatar(other, "h-9 w-9").replace("<span ", '<span data-t-avatar ');
    $("[data-t-name]").textContent = other.name;
    $("[data-t-sub]").innerHTML = sub;
  }
  function resetLog() { log.innerHTML = ""; state.after = ""; state.seen = {}; state.lastDay = ""; state.lastKey = ""; state.live = false; state.rated = false; $("[data-thanks]").hidden = true; }
  function startDraft(p) {
    show("thread");
    resetLog();
    state.target = p; state.other = p;
    header(p, p.id ? role(p.role) : "We usually answer within a few minutes");
    log.innerHTML = '<div class="m-auto flex h-full max-w-[16rem] flex-col items-center justify-center text-center">' + avatar(p, "h-14 w-14 text-base") +
      '<p class="mt-2 text-sm font-semibold">' + esc(p.name) + '</p><p class="mt-1 text-xs text-muted">' + (p.role === "Colleague" ? "Only the two of you see this conversation." : "Write your message below — we’ll reply right here.") + "</p></div>";
    compose.hidden = false; $("[data-ended]").hidden = true; $("[data-end]").hidden = true; $("[data-typing]").hidden = true;
    ta.focus();
  }
  function openThread(id) {
    show("thread");
    resetLog();
    state.cur = id;
    var it = state.items.filter(function (x) { return x.id === id; })[0];
    if (it) header(it.other, role(it.other.role));
    poll();
  }
  function add(m) {
    if (state.seen[m.id]) return;
    state.seen[m.id] = 1;
    if (m.at > state.after) state.after = m.at;
    if (dayKey(m.at) !== state.lastDay) {
      state.lastDay = dayKey(m.at); state.lastKey = "";
      var sep = document.createElement("div");
      sep.className = "my-3 flex items-center gap-3 text-[10px] font-medium uppercase tracking-wide text-muted";
      sep.innerHTML = '<span class="h-px flex-1 bg-border"></span>' + esc(dayLabel(m.at)) + '<span class="h-px flex-1 bg-border"></span>';
      log.appendChild(sep);
    }
    var row = document.createElement("div");
    if (m.sender === "system") {
      row.className = "my-2 flex justify-center";
      row.innerHTML = '<span class="rounded-full bg-surface px-3 py-1 text-center text-[11px] text-muted ring-1 ring-border"></span>';
      row.firstChild.textContent = m.body;
      state.lastKey = "";
    } else {
      var mine = m.mine, key = (mine ? "me" : "them") + (m.name || "");
      var group = key === state.lastKey; state.lastKey = key;
      row.className = "flex " + (mine ? "justify-end" : "justify-start") + (group ? " mt-1" : " mt-3");
      var b = document.createElement("div");
      b.className = "max-w-[80%] whitespace-pre-wrap break-words rounded-2xl px-3.5 py-2 text-sm shadow-sm " + (mine ? "rounded-br-md bg-brand text-brand-fg" : "rounded-bl-md border border-border bg-surface");
      if (m.file) b.innerHTML = K.fileHtml(m.file, "/api/attachments/" + encodeURIComponent(m.file.id), mine); else b.textContent = m.body;
      var meta = document.createElement("div");
      meta.className = "mt-0.5 text-[10px] " + (mine ? "text-right opacity-70" : "text-muted");
      meta.textContent = (!mine && m.name && state.other && state.other.role !== "Colleague" ? m.name + " · " : "") + time(m.at);
      b.appendChild(meta);
      row.appendChild(b);
      if (state.live && !mine) { try { var a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain(); o.frequency.value = 660; g.gain.value = 0.04; o.connect(g); g.connect(a.destination); o.start(); o.stop(a.currentTime + 0.12); } catch (x) {} }
    }
    var near = log.scrollHeight - log.scrollTop - log.clientHeight < 140;
    log.appendChild(row);
    if (near || (m.mine)) log.scrollTop = log.scrollHeight;
  }
  function poll() {
    clearTimeout(state.timer);
    if (!state.cur) return;
    var id = state.cur;
    get("/api/messenger/messages?id=" + encodeURIComponent(id) + "&after=" + encodeURIComponent(state.after)).then(function (j) {
      if (id !== state.cur) return;
      if (j.error) { show("home"); return; }
      state.other = j.other;
      (j.messages || []).forEach(add);
      state.live = true;
      var open = j.status === "open";
      header(j.other, j.typing ? '<span class="text-brand">typing…</span>' : open ? role(j.other.role) : "Ended");
      $("[data-typing-name]").textContent = j.other.name.split(" ")[0];
      $("[data-typing]").hidden = !(open && j.typing);
      compose.hidden = !open; $("[data-ended]").hidden = open; $("[data-end]").hidden = !open;
      $("[data-rate]").hidden = !j.canRate || state.rated;
      $("[data-ended-text]").textContent = j.kind === "peer" ? "This conversation has ended." : "This chat has ended — it’s saved as a ticket.";
      if (open) state.timer = setTimeout(poll, document.hidden ? 10000 : 3000);
    }, function () { state.timer = setTimeout(poll, 8000); });
  }

  /* ---------- Composer ---------- */
  ta.addEventListener("input", function () {
    ta.style.height = "auto"; ta.style.height = Math.min(128, ta.scrollHeight) + "px";
    if (state.cur && ta.value.trim() && Date.now() - state.typingSent > 3000) { state.typingSent = Date.now(); post("/api/messenger/action", { id: state.cur, op: "typing" }); }
  });
  ta.addEventListener("keydown", function (e) { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); compose.requestSubmit(); } });
  function ensureStarted(firstText) {
    if (state.cur) return Promise.resolve(state.cur);
    return post("/api/messenger/start", { to: state.target ? state.target.id || "" : "", message: firstText }).then(function (j) {
      if (j.error) throw new Error(j.error);
      state.cur = j.id; log.innerHTML = ""; state.seen = {}; state.after = ""; state.lastDay = "";
      return j.id;
    });
  }
  function note(text) { add({ id: "n" + Date.now() + Math.random(), sender: "system", body: text, at: new Date().toISOString() }); }
  compose.addEventListener("submit", function (e) {
    e.preventDefault();
    var body = ta.value.trim(); if (!body) return;
    ta.value = ""; ta.style.height = "";
    if (!state.cur) {
      ensureStarted(body).then(function () { poll(); loadList(); }, function (err) { ta.value = body; note(err.message); });
      return;
    }
    post("/api/messenger/send", { id: state.cur, body: body }).then(function (j) {
      if (j.error) { ta.value = body; note(j.error); return; }
      poll();
    }, function () { ta.value = body; note("Network error — your message wasn’t sent"); });
  });
  K.emojiPicker($("[data-emoji-btn]"), ta);
  K.attach($("[data-attach-btn]"), $("[data-file]"), $("[data-drop]"), function (file) {
    var box = $("[data-uploads]"), row = document.createElement("div");
    row.className = "flex items-center gap-2 rounded-lg bg-surface px-3 py-2 text-xs ring-1 ring-border";
    row.innerHTML = '<span class="min-w-0 flex-1 truncate">' + esc(file.name) + '</span><span class="h-1.5 w-20 overflow-hidden rounded-full bg-surface-2"><span class="block h-full w-0 bg-brand transition-all"></span></span>';
    box.appendChild(row);
    var go = function (id) {
      return K.upload(file, { url: "/api/messenger/upload", fields: { id: id }, csrf: csrf, onProgress: function (p) { row.querySelector(".bg-brand").style.width = Math.round(p * 100) + "%"; } });
    };
    (state.cur ? Promise.resolve(state.cur) : ensureStarted("📎 " + file.name)).then(go).then(function (j) {
      row.remove();
      if (j.error) note(j.error); else { poll(); loadList(); }
    }, function (err) { row.remove(); note(err.message); });
  });
  $("[data-end]").addEventListener("click", function () {
    if (!state.cur || !confirm("End this conversation?")) return;
    post("/api/messenger/action", { id: state.cur, op: "close" }).then(function () { poll(); loadList(); });
  });
  $("[data-rate]").addEventListener("click", function (e) {
    var b = e.target.closest("[data-rate-v]"); if (!b || !state.cur) return;
    state.rated = true; $("[data-rate]").hidden = true; $("[data-thanks]").hidden = false;
    post("/api/messenger/action", { id: state.cur, op: "rate", rating: +b.getAttribute("data-rate-v") });
  });
  document.addEventListener("visibilitychange", function () { if (!document.hidden) { loadList(); if (state.cur) poll(); } });

  // The page around us asks to open a conversation (e.g. from a notification).
  window.addEventListener("message", function (e) { if (e.origin === location.origin && e.data && e.data.tosOpen) { loadList().then(function () { openThread(e.data.tosOpen); }); } });

  loadList();
  setInterval(function () { loadList(); }, 6000);
})();
</script>
