/*
 * TicketingOS — small helpers shared by the chat screens (agent console, website
 * chat and the in-app messenger): emoji picker, file messages and uploads.
 */
(function () {
  "use strict";
  var EMOJI = {
    "Smileys": "😀 😃 😄 😁 😆 😅 😂 🤣 😊 🙂 😉 😍 😘 😎 🤩 🥳 😇 🤗 🤔 🤨 😐 😶 🙄 😏 😬 😌 😴 🤒 😷 😢 😭 😤 😡 🤯 😱 🥺 😳 🫡 🤝",
    "Gestures": "👍 👎 👌 ✌️ 🤞 👏 🙌 🙏 💪 👋 ✋ 👉 👀 🫶",
    "Hearts & symbols": "❤️ 🧡 💛 💚 💙 💜 🖤 💯 ✅ ❌ ⚠️ ❓ ❗ ⭐ ✨ 🔥 🎉 🎁 💡 📌 📎 🔒 🔑 ⏰ 📅 📞 💬 📧",
    "Work": "💻 🖥️ ⌨️ 🖨️ 📱 🧾 📄 📊 📈 🗂️ 📁 🛠️ ⚙️ 🐞 🚀 ☕"
  };
  function esc(s) { var d = document.createElement("div"); d.textContent = s == null ? "" : String(s); return d.innerHTML; }
  function size(n) { return n < 1024 ? n + " B" : n < 1048576 ? Math.round(n / 1024) + " KB" : (n / 1048576).toFixed(1) + " MB"; }

  /* Insert text at the caret of a textarea. */
  function insert(ta, text) {
    var s = ta.selectionStart == null ? ta.value.length : ta.selectionStart, e = ta.selectionEnd == null ? s : ta.selectionEnd;
    ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
    var p = s + text.length;
    ta.focus();
    try { ta.setSelectionRange(p, p); } catch (x) {}
    ta.dispatchEvent(new Event("input", { bubbles: true }));
  }

  /* Emoji picker attached to a button; opens above it. */
  function emojiPicker(btn, ta) {
    var pop = null;
    function close() { if (pop) { pop.remove(); pop = null; document.removeEventListener("mousedown", outside, true); } }
    function outside(e) { if (pop && !pop.contains(e.target) && e.target !== btn && !btn.contains(e.target)) close(); }
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      if (pop) { close(); return; }
      pop = document.createElement("div");
      pop.className = "fixed z-[120] w-72 overflow-hidden rounded-xl border border-border bg-surface shadow-2xl";
      pop.setAttribute("role", "dialog");
      pop.setAttribute("aria-label", "Emoji");
      var html = '<div class="max-h-64 overflow-y-auto p-2">';
      Object.keys(EMOJI).forEach(function (g) {
        html += '<p class="px-1 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide text-muted">' + esc(g) + '</p><div class="grid grid-cols-8 gap-0.5">';
        EMOJI[g].split(" ").forEach(function (em) { html += '<button type="button" data-emoji="' + esc(em) + '" class="grid h-8 w-8 place-items-center rounded-lg text-xl hover:bg-surface-2 cursor-pointer">' + em + "</button>"; });
        html += "</div>";
      });
      pop.innerHTML = html + "</div>";
      document.body.appendChild(pop);
      var r = btn.getBoundingClientRect(), w = 288;
      var left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8));
      pop.style.left = left + "px";
      pop.style.bottom = (window.innerHeight - r.top + 6) + "px";
      pop.addEventListener("click", function (ev) { var b = ev.target.closest("[data-emoji]"); if (b) { insert(ta, b.getAttribute("data-emoji")); } });
      document.addEventListener("mousedown", outside, true);
    });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") close(); });
    if (ta.form) ta.form.addEventListener("submit", close);
    window.addEventListener("resize", close);
  }

  /* Bubble content for a file message. url: where the file opens. */
  function fileHtml(file, url, mine) {
    if (file.image) {
      return '<a href="' + esc(url) + '" target="_blank" rel="noopener" class="block"><img src="' + esc(url) + '" alt="' + esc(file.name) + '" loading="lazy" class="max-h-56 max-w-full rounded-lg object-cover"></a>' +
        '<span class="mt-1 block truncate text-[11px] ' + (mine ? "opacity-80" : "text-muted") + '">' + esc(file.name) + "</span>";
    }
    var ext = (/\.([^.]+)$/.exec(file.name) || [, "file"])[1].slice(0, 4).toUpperCase();
    return '<a href="' + esc(url) + '" target="_blank" rel="noopener" class="flex items-center gap-2.5 no-underline">' +
      '<span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg text-[10px] font-bold ' + (mine ? "bg-white/20" : "bg-brand/10 text-brand") + '">' + esc(ext) + "</span>" +
      '<span class="min-w-0"><span class="block truncate font-medium">' + esc(file.name) + '</span><span class="block text-[11px] ' + (mine ? "opacity-80" : "text-muted") + '">' + size(file.size) + " · Download</span></span></a>";
  }

  /* Upload one file with progress. opts: url, fields, csrf, credentials, onProgress */
  function upload(file, opts) {
    return new Promise(function (resolve) {
      var fd = new FormData();
      Object.keys(opts.fields || {}).forEach(function (k) { fd.append(k, opts.fields[k]); });
      fd.append("file", file);
      var x = new XMLHttpRequest();
      x.open("POST", opts.url);
      x.withCredentials = opts.credentials !== "omit";
      x.setRequestHeader("Accept", "application/json");
      if (opts.csrf) x.setRequestHeader("X-CSRF-Token", opts.csrf);
      if (x.upload && opts.onProgress) x.upload.onprogress = function (e) { if (e.lengthComputable) opts.onProgress(e.loaded / e.total); };
      x.onload = function () { var j = {}; try { j = JSON.parse(x.responseText); } catch (e) { j = { error: x.status === 413 ? "The file is too large" : "Upload failed" }; } resolve(j); };
      x.onerror = function () { resolve({ error: "Network error — the file wasn’t sent" }); };
      x.send(fd);
    });
  }

  /* Wire an attach button + hidden file input + drag & drop on a drop zone. send(file) does the upload. */
  function attach(btn, input, zone, send) {
    btn.addEventListener("click", function (e) { e.preventDefault(); input.click(); });
    input.addEventListener("change", function () { Array.prototype.forEach.call(input.files, send); input.value = ""; });
    if (!zone) return;
    var depth = 0;
    zone.addEventListener("dragenter", function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, "Files") !== -1) { depth++; zone.classList.add("ring-2", "ring-brand/50"); } });
    zone.addEventListener("dragleave", function () { if (--depth <= 0) { depth = 0; zone.classList.remove("ring-2", "ring-brand/50"); } });
    zone.addEventListener("dragover", function (e) { e.preventDefault(); });
    zone.addEventListener("drop", function (e) {
      e.preventDefault(); depth = 0; zone.classList.remove("ring-2", "ring-brand/50");
      Array.prototype.forEach.call((e.dataTransfer && e.dataTransfer.files) || [], send);
    });
    // Paste an image (screenshot) straight into the message box.
    zone.addEventListener("paste", function (e) {
      var items = (e.clipboardData && e.clipboardData.files) || [];
      if (items.length) { e.preventDefault(); Array.prototype.forEach.call(items, send); }
    });
  }

  window.ChatKit = { emojiPicker: emojiPicker, fileHtml: fileHtml, upload: upload, attach: attach, insert: insert, size: size, esc: esc };
})();
