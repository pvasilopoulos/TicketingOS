/*
 * TicketingOS — ticket page: reply drafts, upload progress, quoting / editing messages,
 * file viewer, files filter, help-center links and the AI assistant.
 */
(function () {
  "use strict";

  var APP = window.APP || { csrf: "", icons: {} };
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var toast = function (msg, isError) { if (window.TOS && window.TOS.toast) window.TOS.toast(msg, null, !!isError); };
  var esc = function (s) { var d = document.createElement("div"); d.textContent = s == null ? "" : String(s); return d.innerHTML; };
  var store = {
    get: function (k) { try { return JSON.parse(localStorage.getItem(k) || "null"); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
    del: function (k) { try { localStorage.removeItem(k); } catch (e) {} },
  };

  function replyForm() { return $("form[data-reply]"); }
  function replyEditor() { var f = replyForm(); var r = f && $("[data-rte]", f); return r && r._editor; }

  /** Put the caret at the end of the editor so inserted HTML lands there. */
  function caretToEnd(ed) {
    ed.input.focus();
    var range = document.createRange();
    range.selectNodeContents(ed.input);
    range.collapse(false);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    ed.savedRange = range.cloneRange();
  }

  /* ---------- reply drafts (saved in this browser, per ticket) ---------- */

  function bindDraft(form) {
    var key = form.getAttribute("data-draft-key");
    var ed = replyEditor();
    var status = $("[data-draft-status]", form);
    var toggle = $("[data-internal-toggle]", form);
    if (!key || !ed) return;
    var MAX_AGE = 14 * 864e5;
    function show(html) { if (!status) return; status.innerHTML = html; status.hidden = !html; }
    function discardLink() {
      var b = document.createElement("button");
      b.type = "button"; b.className = "underline hover:text-fg cursor-pointer"; b.textContent = "Discard";
      b.addEventListener("click", function () { store.del(key); ed.setHTML(""); show(""); });
      return b;
    }

    // A reply sent from this page: drop its draft unless the server sent it back with an error.
    try {
      if (sessionStorage.getItem("tos-draft-sent") === key) {
        sessionStorage.removeItem("tos-draft-sent");
        if (!form.querySelector(":scope > p.text-red-600")) store.del(key);
      }
    } catch (e) {}

    var d = store.get(key);
    if (d && d.html && Date.now() - (d.at || 0) < MAX_AGE && ed.isEmpty()) {
      ed.setHTML(d.html);
      if (toggle && d.internal && !toggle.checked) { toggle.checked = true; toggle.dispatchEvent(new Event("change")); }
      show(APP.icons.check + " Draft restored · ");
      status.appendChild(discardLink());
    } else if (d && Date.now() - (d.at || 0) >= MAX_AGE) store.del(key);

    var timer;
    function save() {
      ed.sync();
      if (ed.isEmpty()) { store.del(key); show(""); return; }
      store.set(key, { html: ed.input.innerHTML, internal: !!(toggle && toggle.checked), at: Date.now() });
      show(APP.icons.check + " Draft saved · ");
      status.appendChild(discardLink());
    }
    ed.input.addEventListener("input", function () { clearTimeout(timer); timer = setTimeout(save, 700); });
    if (toggle) toggle.addEventListener("change", function () { if (!ed.isEmpty()) save(); });
    window.addEventListener("pagehide", function () { clearTimeout(timer); if (!form.dataset.sending) save(); });
    form.addEventListener("submit", function () { try { sessionStorage.setItem("tos-draft-sent", key); } catch (e) {} });
  }

  /* ---------- sending with an upload progress bar ---------- */

  function bindUpload(form) {
    var input = $("input[type=file]", form);
    var box = $("[data-upload-progress]", form);
    var staffFiles = $("[data-staff-files]", form);
    form.addEventListener("files:change", function (e) { if (staffFiles) staffFiles.hidden = !e.detail.count; });
    if (!input || !box) return;
    form.addEventListener("submit", function (e) {
      if (form.dataset.filesInvalid) {
        e.preventDefault();
        e.stopImmediatePropagation();
        toast("Remove the files that can’t be sent first.", true);
        return;
      }
      if (!input.files || !input.files.length || !window.FormData) return;
      e.preventDefault();
      var bar = $("[data-upload-bar]", box), label = $("[data-upload-label]", box);
      var total = Array.prototype.reduce.call(input.files, function (s, f) { return s + f.size; }, 0);
      box.hidden = false;
      form.dataset.sending = "1";
      var xhr = new XMLHttpRequest();
      xhr.open("POST", form.action);
      xhr.setRequestHeader("Accept", "application/json");
      xhr.setRequestHeader("X-Requested-With", "fetch");
      xhr.upload.addEventListener("progress", function (ev) {
        var pct = ev.lengthComputable ? Math.round(ev.loaded / ev.total * 100) : 0;
        bar.style.width = pct + "%";
        label.textContent = pct < 100 ? "Uploading " + input.files.length + " file" + (input.files.length === 1 ? "" : "s") + " (" + fmt(total) + ") — " + pct + "%" : "Processing…";
      });
      function fail(msg) {
        delete form.dataset.sending;
        delete form.dataset.submitting;
        box.hidden = true; bar.style.width = "0";
        $$("button[type=submit]", form).forEach(function (b) { b.disabled = false; if (b.dataset.label) b.innerHTML = b.dataset.label; });
        try { sessionStorage.removeItem("tos-draft-sent"); } catch (err) {}
        toast(msg || "Sending failed — your reply is still here.", true);
      }
      xhr.addEventListener("load", function () {
        var r = null;
        try { r = JSON.parse(xhr.responseText); } catch (err) {}
        if (r && r.redirect) { location.href = r.redirect; if (r.redirect.indexOf("#") > 0) location.reload(); return; }
        if (xhr.status === 413) return fail("The files are too large for the server.");
        fail(r && r.error);
      });
      xhr.addEventListener("error", function () { fail("Network error — your reply is still here."); });
      xhr.send(new FormData(form));
    });
  }
  function fmt(b) { return b < 1024 ? b + " B" : b < 1048576 ? (b / 1024).toFixed(0) + " KB" : (b / 1048576).toFixed(1) + " MB"; }

  /* ---------- message actions: quote, copy link, edit ---------- */

  var quoteSel = null;
  document.addEventListener("mousedown", function (e) {
    // Remember a selection inside a message before the menu takes the click.
    if (!e.target.closest("[data-message] [data-dropdown-trigger]")) return;
    var sel = window.getSelection();
    var msg = e.target.closest("[data-message]");
    var body = msg && $("[data-message-body]", msg);
    quoteSel = sel && sel.rangeCount && !sel.isCollapsed && body && body.contains(sel.anchorNode) ? sel.toString() : null;
  }, true);

  document.addEventListener("click", function (e) {
    var q = e.target.closest("[data-quote]");
    if (q) {
      var msg = q.closest("[data-message]"), ed = replyEditor();
      if (!msg || !ed) return;
      var body = $("[data-message-body]", msg);
      var inner = quoteSel ? quoteSel.split(/\n{2,}/).map(function (p) { return "<p>" + esc(p).replace(/\n/g, "<br>") + "</p>"; }).join("") : (body ? body.innerHTML : "");
      quoteSel = null;
      caretToEnd(ed);
      ed.insertHTML("<blockquote><p><strong>" + esc(msg.getAttribute("data-author")) + " wrote:</strong></p>" + inner + "</blockquote><p><br></p>");
      var rf = replyForm();
      if (rf) rf.scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }
    var cl = e.target.closest("[data-copy-link]");
    if (cl) {
      var url = location.origin + location.pathname + "#" + cl.getAttribute("data-copy-link");
      (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () { toast("Link copied"); }, function () { window.prompt("Copy this link", url); });
      return;
    }
    var et = e.target.closest("[data-edit-toggle]");
    if (et) {
      var m = et.closest("[data-message]");
      var f = m && $("[data-edit-form]", m);
      if (!f) return;
      f.hidden = false;
      $$("[data-message-body], [data-message-body] + div", m).forEach(function (el) { el.hidden = true; });
      var ied = $("[data-rte]", f)._editor;
      if (ied) caretToEnd(ied);
      return;
    }
    var ec = e.target.closest("[data-edit-cancel]");
    if (ec) {
      var m2 = ec.closest("[data-message]");
      ec.closest("[data-edit-form]").hidden = true;
      $$("[data-message-body], [data-message-body] + div", m2).forEach(function (el) { el.hidden = false; });
    }
  });

  /** Briefly highlight the message a link points to (#c-…). */
  function flashTarget() {
    if (!/^#c-/.test(location.hash)) return;
    var el = document.getElementById(location.hash.slice(1));
    if (!el) return;
    var card = el.children[1] || el;
    card.classList.add("ring-2", "ring-brand/60");
    setTimeout(function () { card.classList.remove("ring-2", "ring-brand/60"); }, 2200);
  }
  window.addEventListener("hashchange", flashTarget);

  /* ---------- file viewer (images and PDFs) ---------- */

  function gallery() {
    var seen = {}, out = [];
    $$("a[data-preview]").forEach(function (a) {
      var href = a.getAttribute("href");
      if (seen[href]) return;
      seen[href] = 1;
      out.push({ href: href, kind: a.getAttribute("data-preview"), name: a.getAttribute("data-name") || "" });
    });
    return out;
  }

  function openViewer(startHref) {
    var items = gallery();
    var i = Math.max(0, items.findIndex(function (x) { return x.href === startHref; }));
    var wrap = document.createElement("div");
    wrap.className = "fixed inset-0 z-[130] flex flex-col bg-black/90 text-white";
    wrap.setAttribute("role", "dialog");
    wrap.setAttribute("aria-modal", "true");
    var btn = "grid h-10 w-10 place-items-center rounded-full hover:bg-white/15 cursor-pointer";
    wrap.innerHTML =
      '<div class="flex items-center gap-2 px-3 py-2 sm:px-4">' +
      '<p class="min-w-0 flex-1 truncate text-sm font-medium" data-v-name></p><span class="text-xs text-white/60 tabular-nums" data-v-count></span>' +
      '<a data-v-open target="_blank" rel="noopener" class="' + btn + '" aria-label="Open in a new tab">' + APP.icons["external-link"] + "</a>" +
      '<a data-v-download class="' + btn + '" aria-label="Download">' + APP.icons.download + "</a>" +
      '<button type="button" data-v-close class="' + btn + '" aria-label="Close">' + APP.icons["x-lg"] + "</button></div>" +
      '<div class="relative flex min-h-0 flex-1 items-center justify-center px-2 pb-3 sm:px-14" data-v-stage></div>' +
      '<button type="button" data-v-prev class="absolute left-2 top-1/2 -translate-y-1/2 bg-black/40 ' + btn + '" aria-label="Previous">' + APP.icons["chevron-left"] + "</button>" +
      '<button type="button" data-v-next class="absolute right-2 top-1/2 -translate-y-1/2 bg-black/40 ' + btn + '" aria-label="Next">' + APP.icons["chevron-right"] + "</button>";
    var stage = $("[data-v-stage]", wrap);
    function show() {
      var it = items[i];
      $("[data-v-name]", wrap).textContent = it.name;
      $("[data-v-count]", wrap).textContent = items.length > 1 ? (i + 1) + " / " + items.length : "";
      $("[data-v-open]", wrap).href = it.href;
      var dl = $("[data-v-download]", wrap); dl.href = it.href; dl.setAttribute("download", it.name);
      $("[data-v-prev]", wrap).hidden = $("[data-v-next]", wrap).hidden = items.length < 2;
      stage.innerHTML = "";
      if (it.kind === "image") {
        var img = document.createElement("img");
        img.src = it.href; img.alt = it.name;
        img.className = "max-h-full max-w-full rounded object-contain shadow-2xl";
        stage.appendChild(img);
      } else {
        var fr = document.createElement("iframe");
        fr.src = it.href; fr.title = it.name;
        fr.className = "h-full w-full max-w-5xl rounded-lg bg-white";
        stage.appendChild(fr);
      }
    }
    function go(d) { i = (i + d + items.length) % items.length; show(); }
    function close() { document.removeEventListener("keydown", onKey); wrap.remove(); document.body.style.overflow = ""; }
    function onKey(e) {
      if (e.key === "Escape") close();
      else if (e.key === "ArrowLeft" && items.length > 1) go(-1);
      else if (e.key === "ArrowRight" && items.length > 1) go(1);
    }
    wrap.addEventListener("click", function (e) {
      if (e.target.closest("[data-v-close]") || e.target === stage) close();
      else if (e.target.closest("[data-v-prev]")) go(-1);
      else if (e.target.closest("[data-v-next]")) go(1);
    });
    var sx = null;
    wrap.addEventListener("touchstart", function (e) { sx = e.touches[0].clientX; }, { passive: true });
    wrap.addEventListener("touchend", function (e) {
      if (sx === null || items.length < 2) return;
      var dx = e.changedTouches[0].clientX - sx; sx = null;
      if (Math.abs(dx) > 60) go(dx < 0 ? 1 : -1);
    });
    document.addEventListener("keydown", onKey);
    document.body.appendChild(wrap);
    document.body.style.overflow = "hidden";
    show();
    $("[data-v-close]", wrap).focus();
  }

  document.addEventListener("click", function (e) {
    var a = e.target.closest("a[data-preview]");
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    openViewer(a.getAttribute("href"));
  });

  /* ---------- Files card filter ---------- */

  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-files-filter]");
    if (!b) return;
    var card = b.closest("[data-files]"), k = b.getAttribute("data-files-filter");
    $$("[data-files-filter]", card).forEach(function (x) { x.setAttribute("aria-pressed", String(x === b)); });
    $$("[data-file-kind]", card).forEach(function (li) { li.hidden = k !== "all" && li.getAttribute("data-file-kind") !== k; });
  });

  /* ---------- help-center article links in the editor ---------- */

  function bindKbPicker(picker) {
    var input = $("[data-kb-search]", picker), out = $("[data-kb-results]", picker);
    var ed = picker.closest("[data-rte]")._editor;
    var timer, seq = 0;
    input.addEventListener("input", function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 3) { out.innerHTML = '<p class="px-2 py-3 text-xs text-muted">Type at least 3 letters.</p>'; return; }
      timer = setTimeout(function () {
        var my = ++seq;
        out.innerHTML = '<p class="px-2 py-3 text-xs text-muted">Searching…</p>';
        fetch("/api/kb/suggest?q=" + encodeURIComponent(q), { credentials: "same-origin", headers: { Accept: "application/json" } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (my !== seq) return;
            var items = (j && j.items) || [];
            if (!items.length) { out.innerHTML = '<p class="px-2 py-3 text-xs text-muted">No published articles match.</p>'; return; }
            out.innerHTML = "";
            items.forEach(function (it) {
              var b = document.createElement("button");
              b.type = "button";
              b.setAttribute("data-dropdown-close", "");
              b.className = "block w-full rounded-lg px-2 py-2 text-left hover:bg-surface-2 cursor-pointer";
              b.innerHTML = '<span class="block truncate text-sm font-medium"></span><span class="block truncate text-xs text-muted"></span>';
              b.children[0].textContent = it.title;
              b.children[1].textContent = (it.section ? it.section + " · " : "") + (it.excerpt || "");
              b.addEventListener("click", function () {
                ed.insertHTML('<a href="' + esc(location.origin + "/kb/" + it.id) + '">' + esc(it.title) + "</a>&nbsp;");
                input.value = "";
              });
              out.appendChild(b);
            });
          })
          .catch(function () { out.innerHTML = '<p class="px-2 py-3 text-xs text-red-600">Search failed.</p>'; });
      }, 250);
    });
    input.addEventListener("keydown", function (e) { if (e.key === "Enter") e.preventDefault(); });
  }

  /* ---------- AI assistant ---------- */

  function bindAi(form) {
    var panel = $("[data-ai-panel]");
    if (!panel) return;
    var ticketId = form.getAttribute("data-ticket-id");
    var body = $("[data-ai-body]", panel), title = $("[data-ai-title]", panel), actions = $("[data-ai-actions]", panel);
    var busy = false, lastSummary = "";
    function showPanel(t, html, withActions) {
      title.textContent = t;
      body.innerHTML = html;
      actions.hidden = !withActions;
      panel.hidden = false;
    }
    function call(payload) {
      return fetch("/api/ai", {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": APP.csrf, "X-Requested-With": "fetch" },
        body: JSON.stringify(payload),
      }).then(function (r) { return r.json().catch(function () { return { error: "AI request failed (" + r.status + ")" }; }); });
    }
    function spinner(text) { return '<p class="flex items-center gap-2 text-muted">' + APP.icons.loader + " " + esc(text) + "</p>"; }

    function run(task, extra) {
      var ed = replyEditor();
      if (busy || !ed) return;
      ed.sync();
      var payload = { ticketId: ticketId, task: task, html: ed.isEmpty() ? "" : ed.input.innerHTML };
      Object.keys(extra || {}).forEach(function (k) { payload[k] = extra[k]; });
      if ((task === "tone" || task === "translate") && !payload.html) { toast("Write something in the reply box first.", true); return; }
      busy = true;
      var before = ed.input.innerHTML;
      showPanel(task === "summary" ? "AI summary" : task === "reply" ? "Suggested reply" : task === "translate" ? "Translating" : "Improving your text",
        spinner(task === "summary" ? "Reading the ticket…" : task === "reply" ? "Writing a reply…" : "Working on it…"), false);
      call(payload).then(function (j) {
        busy = false;
        if (!j || j.error) { showPanel("AI assistant", '<p class="text-red-600 dark:text-red-400">' + esc((j && j.error) || "Something went wrong") + "</p>", false); return; }
        if (task === "summary") {
          lastSummary = j.html;
          showPanel("AI summary", j.html, true);
          return;
        }
        ed.setHTML(j.html);
        ed.input.dispatchEvent(new Event("input"));
        var undo = '<p class="text-muted">' + (task === "reply" ? "A suggested reply is in the reply box — check it before sending." : "Your text in the reply box was replaced.") +
          ' <button type="button" data-ai-undo class="font-medium text-brand underline cursor-pointer">Undo</button></p>';
        showPanel(task === "reply" ? "Suggested reply" : task === "translate" ? "Translated" : "Text improved", undo, false);
        var u = $("[data-ai-undo]", panel);
        if (u) u.addEventListener("click", function () { ed.setHTML(before); ed.input.dispatchEvent(new Event("input")); panel.hidden = true; });
      }, function () {
        busy = false;
        showPanel("AI assistant", '<p class="text-red-600">Network error.</p>', false);
      });
    }

    document.addEventListener("click", function (e) {
      var b = e.target.closest("[data-ai-task]");
      if (!b) return;
      var task = b.getAttribute("data-ai-task");
      if (task === "translate" && !b.getAttribute("data-ai-lang")) {
        var ask = window.TOS && window.TOS.ask ? window.TOS.ask("Translate to which language?", { ok: "Translate" }) : Promise.resolve(window.prompt("Translate to which language?"));
        ask.then(function (lang) { if (lang && String(lang).trim()) run("translate", { lang: String(lang).trim() }); });
        return;
      }
      run(task, { tone: b.getAttribute("data-ai-tone") || "", lang: b.getAttribute("data-ai-lang") || "" });
    });
    $("[data-ai-close]", panel).addEventListener("click", function () { panel.hidden = true; });
    $("[data-ai-copy]", panel).addEventListener("click", function () {
      var tmp = document.createElement("div"); tmp.innerHTML = lastSummary;
      var text = tmp.innerText || tmp.textContent || "";
      (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { toast("Summary copied"); }, function () {});
    });
    var note = $("[data-ai-note]", panel);
    if (note) note.addEventListener("click", function () {
      var ed = replyEditor(), toggle = $("[data-internal-toggle]", form);
      if (!ed) return;
      if (toggle && !toggle.checked) { toggle.checked = true; toggle.dispatchEvent(new Event("change")); }
      ed.setHTML("<p><strong>Summary</strong></p>" + lastSummary);
      ed.input.dispatchEvent(new Event("input"));
      panel.hidden = true;
      form.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  }

  /* ---------- time tracking: a timer per ticket that survives reloads ---------- */

  function bindTimer(card) {
    var btn = $("[data-timer]", card);
    if (!btn) return;
    var key = "tos-timer:" + card.getAttribute("data-ticket");
    var label = $("[data-timer-label]", btn), ic = $("[data-timer-icon]", btn), out = $("[data-timer-minutes]", card);
    var tick = null;
    var pad = function (n) { return (n < 10 ? "0" : "") + n; };
    function render() {
      var start = store.get(key);
      btn.dataset.running = start ? "true" : "false";
      if (!start) { label.textContent = "Start timer"; ic.innerHTML = APP.icons.play || "▶"; return; }
      var s = Math.max(0, Math.floor((Date.now() - start) / 1000));
      label.textContent = Math.floor(s / 3600) + ":" + pad(Math.floor(s / 60) % 60) + ":" + pad(s % 60);
      ic.innerHTML = APP.icons.stop || "■";
    }
    btn.addEventListener("click", function () {
      var start = store.get(key);
      if (start) {
        store.del(key);
        clearInterval(tick); tick = null;
        var min = Math.max(1, Math.ceil((Date.now() - start) / 60000));
        out.value = Math.floor(min / 60) + ":" + pad(min % 60);
        var note = card.querySelector("input[name=note]");
        if (note) note.focus();
      } else {
        store.set(key, Date.now());
        tick = setInterval(render, 1000);
      }
      render();
    });
    if (store.get(key)) tick = setInterval(render, 1000);
    render();
  }

  document.addEventListener("DOMContentLoaded", function () {
    $$("[data-time-card]").forEach(bindTimer);
    var form = replyForm();
    if (form) { bindDraft(form); bindUpload(form); bindAi(form); }
    $$("[data-kb-picker]").forEach(bindKbPicker);
    flashTarget();
  });
})();
