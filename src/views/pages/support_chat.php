<?php
/**
 * Website live chat for visitors (/support/{slug}/chat), usually inside the widget (?embed=1).
 * @var array $tenant @var array $cfg @var bool $embed @var ?array $appUser @var array $agents
 */
$accent = '#4f46e5';
$base = '/support/' . $tenant['slug'] . '/chat';
?>
<div class="flex <?= $embed ? 'h-dvh' : 'min-h-dvh items-center justify-center bg-bg p-4' ?>">
  <div class="flex w-full flex-col overflow-hidden bg-surface <?= $embed ? 'h-full' : 'h-[640px] max-w-md rounded-2xl border border-border shadow-xl' ?>" data-chat-root data-base="<?= e($base) ?>" data-key="<?= e('tos-chat:' . $tenant['slug'] . ($appUser ? ':' . $appUser['id'] : '')) ?>" data-app="<?= $appUser ? '1' : '' ?>">
    <header class="flex items-center gap-3 px-4 py-3 text-white" style="background:<?= e($accent) ?>">
      <span class="grid h-9 w-9 place-items-center rounded-full bg-white/20"><?= icon('headset', 18) ?></span>
      <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-semibold"><?= e($cfg['title']) ?></p>
        <p class="truncate text-xs text-white/80" data-chat-sub><?= e($tenant['name']) ?></p>
      </div>
      <button type="button" data-chat-end hidden class="rounded-lg bg-white/15 px-2.5 py-1 text-xs font-medium hover:bg-white/25 cursor-pointer">End chat</button>
    </header>

    <div class="flex-1 space-y-2.5 overflow-y-auto bg-surface-2/40 px-3 py-4" data-chat-log aria-live="polite"></div>
    <div data-chat-typing hidden class="flex items-center gap-2 bg-surface-2/40 px-4 pb-2 text-xs text-muted">
      <span class="flex gap-0.5"><span class="h-1.5 w-1.5 animate-bounce rounded-full bg-muted [animation-delay:-0.3s]"></span><span class="h-1.5 w-1.5 animate-bounce rounded-full bg-muted [animation-delay:-0.15s]"></span><span class="h-1.5 w-1.5 animate-bounce rounded-full bg-muted"></span></span>
      <span data-typing-who>Agent</span> is typing…
    </div>

    <form data-chat-start class="space-y-2.5 border-t border-border p-4">
      <p class="text-sm text-muted"><?= e($cfg['greeting']) ?></p>
      <?php if ($appUser): ?>
        <fieldset>
          <legend class="mb-1.5 text-xs font-medium text-muted">Who would you like to talk to?</legend>
          <div class="flex max-h-36 flex-wrap gap-1.5 overflow-y-auto">
            <?php foreach (array_merge([['id' => '', 'name' => 'Anyone available']], $agents) as $i => $a): $ini = $a['id'] === '' ? '★' : mb_strtoupper(mb_substr($a['name'], 0, 1)); ?>
              <label class="cursor-pointer">
                <input type="radio" name="agentId" value="<?= e($a['id']) ?>" <?= $i === 0 ? 'checked' : '' ?> class="peer sr-only">
                <span class="flex items-center gap-1.5 rounded-full border border-border py-1 pl-1 pr-3 text-xs font-medium transition-colors peer-checked:border-transparent peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40 peer-checked:bg-brand">
                  <span class="grid h-6 w-6 place-items-center rounded-full bg-surface-2 text-[10px] font-semibold text-fg"><?= e($ini) ?></span><?= e($a['id'] === '' ? $a['name'] : first_name($a['name'])) ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
      <?php else: ?>
      <div class="grid grid-cols-2 gap-2">
        <input name="name" required maxlength="80" placeholder="Your name" autocomplete="name" class="<?= INPUT_CLS ?>">
        <input name="email" type="email" required placeholder="Email" autocomplete="email" class="<?= INPUT_CLS ?>">
      </div>
      <?php endif; ?>
      <textarea name="message" required rows="3" placeholder="How can we help?" class="<?= INPUT_CLS ?> h-auto py-2"></textarea>
      <input name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
      <p data-chat-error hidden class="text-xs text-red-600"></p>
      <button type="submit" class="flex h-10 w-full items-center justify-center gap-2 rounded-lg text-sm font-semibold text-white cursor-pointer" style="background:<?= e($accent) ?>"><?= icon('send', 15) ?> Start chat</button>
      <?php if ($appUser): ?>
      <p class="text-center text-xs text-muted">Not urgent? <a href="/tickets/new" target="_top" class="font-medium underline">Open a ticket</a></p>
      <?php else: ?>
      <p class="text-center text-xs text-muted">Prefer email? <a href="/support/<?= e($tenant['slug']) ?><?= $embed ? '?embed=1' : '' ?>" class="font-medium underline">Send a request</a></p>
      <?php endif; ?>
    </form>

    <div data-chat-uploads class="space-y-1 bg-surface-2/40 px-3 empty:hidden"></div>
    <form data-chat-compose hidden class="flex items-end gap-1 border-t border-border p-3">
      <button type="button" data-emoji-btn aria-label="Emoji" class="grid h-10 w-9 shrink-0 place-items-center rounded-lg text-muted hover:bg-surface-2 cursor-pointer"><?= icon('smile', 18) ?></button>
      <button type="button" data-attach-btn aria-label="Attach a file" class="grid h-10 w-9 shrink-0 place-items-center rounded-lg text-muted hover:bg-surface-2 cursor-pointer"><?= icon('paperclip', 18) ?></button>
      <input type="file" data-file multiple hidden>
      <textarea name="body" rows="1" placeholder="Write a message…" class="max-h-32 min-h-10 flex-1 resize-none rounded-xl border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
      <button type="submit" aria-label="Send" class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-white cursor-pointer" style="background:<?= e($accent) ?>"><?= icon('send-horizontal', 17) ?></button>
    </form>
    <div data-chat-ended hidden class="border-t border-border p-4 text-center">
      <p class="text-sm text-muted">This chat has ended.</p>
      <div data-chat-rate hidden class="mt-3">
        <p class="text-sm font-medium">How did we do?</p>
        <div class="mt-2 flex justify-center gap-2">
          <button type="button" data-rate="1" class="rounded-full border border-border px-4 py-1.5 text-lg hover:bg-surface-2 cursor-pointer" aria-label="Good">👍</button>
          <button type="button" data-rate="-1" class="rounded-full border border-border px-4 py-1.5 text-lg hover:bg-surface-2 cursor-pointer" aria-label="Bad">👎</button>
        </div>
      </div>
      <p data-chat-thanks hidden class="mt-2 text-sm text-emerald-600">Thanks for the feedback!</p>
      <button type="button" data-chat-new class="mt-2 text-sm font-semibold underline cursor-pointer" style="color:<?= e($accent) ?>">Start a new chat</button>
    </div>
  </div>
</div>
<script>
(function () {
  var root = document.querySelector("[data-chat-root]");
  var base = root.getAttribute("data-base"), key = root.getAttribute("data-key"), inApp = root.getAttribute("data-app") === "1";
  var log = root.querySelector("[data-chat-log]"), start = root.querySelector("[data-chat-start]"), compose = root.querySelector("[data-chat-compose]");
  var ended = root.querySelector("[data-chat-ended]"), endBtn = root.querySelector("[data-chat-end]"), sub = root.querySelector("[data-chat-sub]");
  var token = null, after = "", timer = null, seen = {}, status = "", subDefault = sub.textContent;
  try { token = localStorage.getItem(key); } catch (e) {}
  function api(op, body) {
    return fetch(base + "/" + op + (op === "poll" ? "?t=" + encodeURIComponent(token) + "&after=" + encodeURIComponent(after) : ""), {
      method: op === "poll" ? "GET" : "POST", credentials: inApp ? "same-origin" : "omit",
      headers: Object.assign({ "Content-Type": "application/json", Accept: "application/json" }, inApp && window.APP ? { "X-CSRF-Token": window.APP.csrf } : {}),
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
      if (m.file) b.innerHTML = window.ChatKit.fileHtml(m.file, base + "/file?t=" + encodeURIComponent(token) + "&id=" + encodeURIComponent(m.file.id), mine); else b.textContent = m.body;
      var meta = document.createElement("div");
      meta.className = "mt-0.5 text-[10px] " + (mine ? "text-white/70 text-right" : "text-muted");
      meta.textContent = (m.name ? m.name + " · " : "") + time(m.at);
      b.appendChild(meta);
      row.appendChild(b);
    }
    log.appendChild(row);
    log.scrollTop = log.scrollHeight;
    if (live && m.sender === "agent") {
      // Tell the page around the chat (the in-app button shows a dot) and play a soft sound.
      try { if (window.parent !== window) window.parent.postMessage({ tosChat: "message" }, location.origin); } catch (x) {}
      try { var a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain(); o.frequency.value = 660; g.gain.value = 0.04; o.connect(g); g.connect(a.destination); o.start(); o.stop(a.currentTime + 0.12); } catch (x) {}
    }
  }
  var live = false, typingSent = 0, rated = false;
  var typingRow = root.querySelector("[data-chat-typing]"), rateBox = root.querySelector("[data-chat-rate]"), thanks = root.querySelector("[data-chat-thanks]");
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
      live = true;
      setStatus(j.status, j.agent);
      root.querySelector("[data-typing-who]").textContent = j.agent || "Agent";
      typingRow.hidden = !(j.status === "open" && j.typing);
      rateBox.hidden = j.status === "open" || j.rating !== null || rated;
      if (j.status === "open") timer = setTimeout(poll, document.hidden ? 10000 : 3000);
    }, function () { timer = setTimeout(poll, 8000); });
  }
  function forget() { sub.textContent = subDefault; token = null; try { localStorage.removeItem(key); } catch (e) {} log.innerHTML = ""; seen = {}; after = ""; rated = false; thanks.hidden = true; rateBox.hidden = true; typingRow.hidden = true; setStatus(""); }
  start.addEventListener("submit", function (e) {
    e.preventDefault();
    var err = start.querySelector("[data-chat-error]"), btn = start.querySelector("button[type=submit]");
    btn.disabled = true; err.hidden = true;
    var f = new FormData(start);
    var page = ""; try { page = document.referrer || ""; } catch (x) {}
    api("start", inApp ? { app: 1, agentId: f.get("agentId") || "", message: f.get("message"), website: f.get("website"), page: page }
      : { name: f.get("name"), email: f.get("email"), message: f.get("message"), website: f.get("website"), page: page }).then(function (j) {
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
  ta.addEventListener("input", function () {
    ta.style.height = "auto"; ta.style.height = Math.min(128, ta.scrollHeight) + "px";
    if (token && ta.value.trim() && Date.now() - typingSent > 3000) { typingSent = Date.now(); api("typing"); }
  });
  window.ChatKit.emojiPicker(root.querySelector("[data-emoji-btn]"), ta);
  window.ChatKit.attach(root.querySelector("[data-attach-btn]"), root.querySelector("[data-file]"), root, function (file) {
    if (!token || status !== "open") return;
    var box = root.querySelector("[data-chat-uploads]"), r = document.createElement("div");
    r.className = "flex items-center gap-2 rounded-lg bg-surface px-3 py-2 text-xs ring-1 ring-border";
    r.innerHTML = '<span class="min-w-0 flex-1 truncate"></span><span class="h-1.5 w-20 overflow-hidden rounded-full bg-surface-2"><span class="block h-full w-0 transition-all" style="background:<?= e($accent) ?>"></span></span>';
    r.firstChild.textContent = file.name;
    box.appendChild(r);
    window.ChatKit.upload(file, { url: base + "/upload", fields: { t: token }, credentials: inApp ? "same-origin" : "omit", csrf: inApp && window.APP ? window.APP.csrf : "",
      onProgress: function (p) { r.lastChild.firstChild.style.width = Math.round(p * 100) + "%"; } }).then(function (j) {
      r.remove();
      if (j.error) { add({ id: "e" + Date.now(), sender: "system", body: j.error, at: new Date().toISOString() }); return; }
      clearTimeout(timer); poll();
    });
  });
  rateBox.addEventListener("click", function (e) {
    var b = e.target.closest("[data-rate]"); if (!b) return;
    rated = true; rateBox.hidden = true; thanks.hidden = false;
    api("rate", { rating: +b.getAttribute("data-rate") });
  });
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
