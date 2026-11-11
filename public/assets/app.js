/*
 * TicketingOS — client behaviour (vanilla JS, no build step).
 * Progressive enhancement over plain HTML forms rendered by PHP.
 */
(function () {
  "use strict";

  var APP = window.APP || { csrf: "", vapidKey: "", icons: {} };
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  // Search ignores case and accents: "ΠΡΟΔΡΟΜΟΣ", "Προδρομος" and "Πρόδρομος" all match.
  // Character by character, so positions stay the same for highlighting.
  var fold = window.TOSfold = function (s) {
    s = s == null ? "" : String(s);
    var o = "";
    for (var i = 0; i < s.length; i++) { var c = s.charAt(i).normalize("NFD").charAt(0).toLowerCase(); o += c === "ς" ? "σ" : c; }
    return o;
  };

  /* ---------- helpers ---------- */

  function post(url, data) {
    var body = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData) && data) Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    if (!body.has("_csrf")) body.append("_csrf", APP.csrf);
    return fetch(url, {
      method: "POST",
      body: body,
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-Requested-With": "fetch", "X-CSRF-Token": APP.csrf },
    }).then(function (res) {
      return res.json().catch(function () { return { error: "Request failed (" + res.status + ")" }; });
    });
  }

  /** Reload while keeping the scroll position of the main pane. */
  function refresh() {
    var main = $("[data-main]");
    try {
      sessionStorage.setItem("tos_scroll", JSON.stringify({ path: location.pathname + location.search, y: main ? main.scrollTop : window.scrollY }));
    } catch (e) {}
    location.reload();
  }
  (function restoreScroll() {
    try {
      var s = JSON.parse(sessionStorage.getItem("tos_scroll") || "null");
      sessionStorage.removeItem("tos_scroll");
      if (s && s.path === location.pathname + location.search) {
        var main = $("[data-main]");
        requestAnimationFrame(function () { if (main) main.scrollTop = s.y; else window.scrollTo(0, s.y); });
      }
    } catch (e) {}
  })();

  function handleResult(r) {
    if (r && r.redirect) { location.href = r.redirect; return; }
    if (r && r.error) { toast(r.error, null, true); return; }
    var msg = r && typeof r.success === "string" ? r.success : "";
    // A message carrying a link (invite / reset link without email) must be copyable.
    if (/https?:\/\//.test(msg)) { notice(msg).then(refresh); return; }
    if (msg) { try { sessionStorage.setItem("tos_toast", msg); } catch (e) {} }
    refresh();
  }

  /** Modal with a message; a URL in it gets a Copy button. */
  function notice(message) {
    return new Promise(function (resolve) {
      var wrap = document.createElement("div");
      wrap.className = "fixed inset-0 z-[120] flex items-end justify-center bg-black/40 p-3 sm:items-center";
      wrap.innerHTML = '<div class="w-full max-w-md rounded-2xl border border-border bg-surface p-4 shadow-2xl animate-in pb-[max(1rem,env(safe-area-inset-bottom))]">' +
        '<p class="text-sm" data-msg></p><div data-url-row hidden class="mt-3 flex gap-2"><input readonly class="h-10 min-w-0 flex-1 rounded-lg border border-border bg-surface-2 px-3 text-xs">' +
        '<button type="button" data-copy class="h-10 rounded-lg bg-surface-2 px-3 text-sm font-medium cursor-pointer">Copy</button></div>' +
        '<button type="button" data-ok class="mt-4 h-10 w-full rounded-lg bg-brand text-sm font-medium text-brand-fg cursor-pointer">Done</button></div>';
      var m = message.match(/https?:\/\/\S+/);
      wrap.querySelector("[data-msg]").textContent = m ? message.replace(m[0], "").replace(/[:\s]+$/, "") : message;
      if (m) {
        var input = wrap.querySelector("input");
        input.value = m[0];
        wrap.querySelector("[data-url-row]").hidden = false;
        wrap.querySelector("[data-copy]").addEventListener("click", function (e) {
          input.select();
          (navigator.clipboard ? navigator.clipboard.writeText(m[0]) : Promise.reject()).catch(function () { document.execCommand("copy"); });
          e.target.textContent = "Copied";
        });
      }
      function done() { wrap.remove(); resolve(); }
      wrap.querySelector("[data-ok]").addEventListener("click", done);
      wrap.addEventListener("keydown", function (e) { if (e.key === "Escape") done(); });
      document.body.appendChild(wrap);
      setTimeout(function () { wrap.querySelector("[data-ok]").focus(); }, 0);
    });
  }

  /** Yes/no modal (window.confirm is blocked in some installed apps). */
  function confirmDialog(message, ok) {
    return new Promise(function (resolve) {
      var wrap = document.createElement("div");
      wrap.className = "fixed inset-0 z-[120] flex items-end justify-center bg-black/40 p-3 sm:items-center";
      wrap.innerHTML = '<div class="w-full max-w-sm rounded-2xl border border-border bg-surface p-4 shadow-2xl animate-in pb-[max(1rem,env(safe-area-inset-bottom))]">' +
        '<p class="text-sm" data-msg></p><div class="mt-4 flex gap-2"><button type="button" data-cancel class="h-10 flex-1 rounded-lg bg-surface-2 text-sm font-medium cursor-pointer">Cancel</button>' +
        '<button type="button" data-ok class="h-10 flex-1 rounded-lg bg-red-600 text-sm font-medium text-white cursor-pointer"></button></div></div>';
      wrap.querySelector("[data-msg]").textContent = message;
      wrap.querySelector("[data-ok]").textContent = ok || "Confirm";
      function done(v) { wrap.remove(); resolve(v); }
      wrap.querySelector("[data-ok]").addEventListener("click", function () { done(true); });
      wrap.querySelector("[data-cancel]").addEventListener("click", function () { done(false); });
      wrap.addEventListener("mousedown", function (e) { if (e.target === wrap) done(false); });
      wrap.addEventListener("keydown", function (e) { if (e.key === "Escape") done(false); });
      document.body.appendChild(wrap);
      setTimeout(function () { wrap.querySelector("[data-cancel]").focus(); }, 0);
    });
  }

  // Confirm before submitting forms (or submit buttons) marked data-confirm.
  document.addEventListener("submit", function (e) {
    var form = e.target, btn = e.submitter;
    var q = (btn && btn.getAttribute("data-confirm")) || (form.getAttribute && form.getAttribute("data-confirm"));
    if (!q) return;
    if (form.dataset.confirmed === "1") { delete form.dataset.confirmed; return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    confirmDialog(q, (btn && btn.textContent.trim().replace(/…$/, "")) || "Confirm").then(function (yes) {
      if (!yes) return;
      form.dataset.confirmed = "1";
      form.requestSubmit(btn || undefined);
    });
  }, true);

  // Show/hide a block: <button data-toggle-target="#id">.
  document.addEventListener("click", function (e) {
    var t = e.target.closest("[data-toggle-target]");
    if (!t) return;
    var el = $(t.getAttribute("data-toggle-target"));
    if (!el) return;
    el.hidden = !el.hidden;
    if (!el.hidden) fillOrgPickers(el);
    if (!el.hidden) { var f = el.querySelector("input:not([type=hidden]), select"); if (f) setTimeout(function () { f.focus(); }, 0); }
  });

  // Settings → Users: the organization checklist is copied from one <template> when a form opens
  // (instead of being repeated for every user), with that user's memberships ticked.
  var orgList = null;
  function fillOrgPickers(scope) {
    if (orgList === null) {
      var src = document.getElementById("org-picker-data");
      try { orgList = src ? JSON.parse(src.textContent) : []; } catch (e) { orgList = []; }
    }
    if (!orgList.length) return;
    var escH = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); };
    $$("[data-org-picker]", scope).forEach(function (box) {
      if (box.hasAttribute("data-filled")) return;
      box.setAttribute("data-filled", "");
      var mine = {}; try { mine = JSON.parse(box.getAttribute("data-orgs") || "{}"); } catch (e) {}
      var has = function (id) { return Object.prototype.hasOwnProperty.call(mine, id); };
      // Their organizations first, then the rest.
      var rows = orgList.slice().sort(function (a, b) { return (has(b[0]) ? 1 : 0) - (has(a[0]) ? 1 : 0); }).map(function (o) {
        var id = escH(o[0]);
        return '<div data-org-item class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-surface-2">' +
          '<label class="flex min-w-0 flex-1 cursor-pointer items-center gap-2 text-sm"><input type="checkbox" name="orgs[]" value="' + id + '"' + (has(o[0]) ? " checked" : "") + ' class="h-4 w-4 rounded border-border accent-brand"><span class="truncate">' + escH(o[1]) + "</span></label>" +
          '<label data-org-wrap class="flex shrink-0 cursor-pointer items-center gap-1 text-[11px] text-muted" title="Manager: sees all of this organization’s tickets"><input type="checkbox" name="orgManagers[]" value="' + id + '"' + (mine[o[0]] === true ? " checked" : "") + ' class="h-3.5 w-3.5 rounded border-border accent-brand">👑</label></div>';
      }).join("");
      box.innerHTML = (orgList.length > 8 ? '<input type="search" data-org-filter placeholder="Search ' + orgList.length + ' organizations" class="mb-1.5 h-8 w-full rounded-lg border border-border bg-surface px-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">' : "") +
        '<div class="grid max-h-44 gap-1 overflow-y-auto rounded-lg border border-border p-1 sm:grid-cols-2 xl:grid-cols-3">' + rows + "</div>";
      var f = box.querySelector("[data-org-filter]");
      if (f) f.addEventListener("input", function () {
        var q = fold(f.value.trim());
        $$("[data-org-item]", box).forEach(function (it) { it.hidden = q && fold(it.textContent).indexOf(q) < 0; });
      });
      syncUserForm(box.closest("form"));
    });
  }

  // User forms: custom-role picker only for agents; temp password only without an invite.
  function syncUserForm(form) {
    if (!form) return;
    var role = form.querySelector("[data-role-select]");
    var wrap = form.querySelector("[data-custom-role-wrap]");
    if (role && wrap) wrap.hidden = role.value !== "AGENT";
    $$("[data-org-wrap]", form).forEach(function (w) { w.hidden = !role || role.value !== "CUSTOMER"; });
    var inv = form.querySelector("[data-invite-toggle]"), pw = form.querySelector("[data-temp-password]");
    if (inv && pw) { pw.hidden = inv.checked; pw.querySelector("input").required = !inv.checked; }
  }
  document.addEventListener("change", function (e) {
    if (e.target.matches && e.target.matches("[data-role-select], [data-invite-toggle]")) syncUserForm(e.target.form);
  });
  document.addEventListener("DOMContentLoaded", function () {
    $$("[data-role-select]").forEach(function (s) { syncUserForm(s.form); });
    $$("form:not([hidden]) [data-org-picker]").forEach(function (b) { fillOrgPickers(b.parentNode); });
    try {
      var t = sessionStorage.getItem("tos_toast");
      if (t) { sessionStorage.removeItem("tos_toast"); setTimeout(function () { toast(t); }, 50); }
    } catch (e) {}
  });

  window.TOS = { post: post, refresh: refresh };

  /**
   * In-page text prompt (window.prompt is blocked in installed apps / webviews).
   * Resolves with the trimmed value, or null when cancelled.
   */
  function ask(title, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var wrap = document.createElement("div");
      wrap.className = "fixed inset-0 z-[120] flex items-end justify-center bg-black/40 p-3 sm:items-center";
      wrap.innerHTML =
        '<form class="w-full max-w-sm rounded-2xl border border-border bg-surface p-4 shadow-2xl animate-in pb-[max(1rem,env(safe-area-inset-bottom))]">' +
        '<label class="block text-sm font-semibold"></label>' +
        '<input class="mt-3 h-11 w-full rounded-lg border border-border bg-surface px-3 text-base focus:outline-none focus:ring-2 focus:ring-brand/40 sm:h-10 sm:text-sm" autocomplete="off">' +
        '<div class="mt-4 flex gap-2"><button type="button" data-cancel class="h-10 flex-1 rounded-lg bg-surface-2 text-sm font-medium cursor-pointer">Cancel</button>' +
        '<button type="submit" class="h-10 flex-1 rounded-lg bg-brand text-sm font-medium text-brand-fg cursor-pointer">' + (opts.ok || "Save") + "</button></div></form>";
      var form = wrap.querySelector("form"), input = wrap.querySelector("input");
      wrap.querySelector("label").textContent = title;
      input.placeholder = opts.placeholder || "";
      input.value = opts.value || "";
      if (opts.type) input.type = opts.type;
      function done(v) { wrap.remove(); resolve(v); }
      form.addEventListener("submit", function (e) { e.preventDefault(); var v = input.value.trim(); done(v || null); });
      wrap.querySelector("[data-cancel]").addEventListener("click", function () { done(null); });
      wrap.addEventListener("mousedown", function (e) { if (e.target === wrap) done(null); });
      wrap.addEventListener("keydown", function (e) { if (e.key === "Escape") done(null); });
      document.body.appendChild(wrap);
      setTimeout(function () { input.focus(); input.select(); }, 0);
    });
  }
  window.TOS.ask = ask;

  /* ---------- theme toggle ---------- */

  document.addEventListener("click", function (e) {
    var t = e.target.closest("[data-theme-toggle]");
    if (!t) return;
    var dark = !document.documentElement.classList.contains("dark");
    document.documentElement.classList.toggle("dark", dark);
    try { localStorage.setItem("theme", dark ? "dark" : "light"); } catch (err) {}
  });

  /* ---------- dropdowns ---------- */

  function closeAllDropdowns(except) {
    $$("[data-dropdown-menu]").forEach(function (m) { if (m !== except) m.hidden = true; });
  }
  // A phone sheet menu doesn't move with the page: close it when the page scrolls.
  document.addEventListener("scroll", function (e) {
    if (e.target.closest && e.target.closest("[data-dropdown-menu]")) return;
    $$("[data-dropdown-menu]:not([hidden])").forEach(function (m) { if (m.style.top) m.hidden = true; });
  }, { passive: true, capture: true });
  document.addEventListener("click", function (e) {
    var trigger = e.target.closest("[data-dropdown-trigger]");
    if (trigger) {
      var menu = trigger.closest("[data-dropdown]").querySelector("[data-dropdown-menu]");
      closeAllDropdowns(menu);
      menu.hidden = !menu.hidden;
      menu.style.top = menu.style.bottom = "";
      if (!menu.hidden) {
        // Phone "sheet" menus are position:fixed: place them from where the button is on screen
        // (inside the scrolled page their automatic spot can be far below the screen).
        if (getComputedStyle(menu).position === "fixed") {
          var r = trigger.getBoundingClientRect(), h = menu.offsetHeight, vh = window.innerHeight;
          var below = vh - r.bottom - 76, above = r.top - 12;
          if (h <= below || below >= above) menu.style.top = Math.round(r.bottom + 8) + "px";
          else menu.style.top = Math.max(12, Math.round(r.top - 8 - Math.min(h, above))) + "px";
          menu.style.maxHeight = Math.max(160, Math.round((h <= below || below >= above) ? below : above)) + "px";
        } else menu.style.maxHeight = "";
        var input = menu.querySelector("input:not([type=hidden])");
        if (input) setTimeout(function () { input.focus(); }, 0);
      }
      return;
    }
    if (e.target.closest("[data-dropdown-close]")) {
      var m = e.target.closest("[data-dropdown-menu]");
      if (m) m.hidden = true;
      return;
    }
    if (!e.target.closest("[data-dropdown-menu]")) closeAllDropdowns(null);
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") closeAllDropdowns(null);
  });

  /* ---------- mobile drawer ---------- */

  document.addEventListener("click", function (e) {
    var drawer = $("[data-drawer]");
    if (!drawer) return;
    if (e.target.closest("[data-drawer-open]")) drawer.hidden = false;
    else if (e.target.closest("[data-drawer-close]")) drawer.hidden = true;
  });

  /* ---------- AJAX forms ---------- */

  document.addEventListener("submit", function (e) {
    var form = e.target;
    if (form.hasAttribute("data-ajax")) {
      e.preventDefault();
      var spinner = form.querySelector("[data-spinner]");
      if (spinner) spinner.hidden = false;
      $$("button", form).forEach(function (b) { b.disabled = true; });
      var fd = new FormData(form);
      // Include the clicked button (e.g. a rating) like a normal submit would.
      if (e.submitter && e.submitter.name) fd.set(e.submitter.name, e.submitter.value);
      post(e.submitter && e.submitter.getAttribute("formaction") || form.action, fd).then(handleResult, function () {
        toast("Network error", null, true);
        $$("button", form).forEach(function (b) { b.disabled = false; });
      });
      return;
    }
    // Plain forms: show a pending label and prevent double submits.
    var label = form.getAttribute("data-pending");
    var btn = form.querySelector("button[type=submit]");
    if (btn) {
      if (form.dataset.submitting) { e.preventDefault(); return; }
      form.dataset.submitting = "1";
      if (label) {
        btn.dataset.label = btn.innerHTML;
        btn.textContent = label;
      }
      btn.disabled = true;
      // Re-enable if the user navigates back to this page.
      window.addEventListener("pageshow", function () {
        delete form.dataset.submitting;
        btn.disabled = false;
        if (btn.dataset.label) btn.innerHTML = btn.dataset.label;
      }, { once: true });
    }
  }, false);

  // Auto-save forms (selects that submit on change).
  document.addEventListener("change", function (e) {
    var el = e.target;
    if (el.hasAttribute("data-per-page")) { location.href = el.value; return; }
    var form = el.form;
    if (!form) return;
    // Multi-select pickers save once, when they close.
    var pick = el.closest && el.closest("[data-multi-pick]");
    if (pick) { pick.dataset.dirty = "1"; return; }
    if (form.hasAttribute("data-autosave") || el.hasAttribute("data-submit-on-change")) {
      form.requestSubmit();
    } else if (el.hasAttribute("data-autosubmit")) {
      form.submit();
    }
  });

  /* ---------- "dirty" rows (enable Save only when changed) ---------- */

  function bindDirty(form) {
    var save = form.querySelector("[data-dirty-save]");
    if (!save) return;
    var fields = $$("input, select", form).filter(function (i) { return i.type !== "hidden"; });
    var initial = fields.map(function (i) { return i.type === "checkbox" ? i.checked : i.value; });
    function check() {
      var dirty = fields.some(function (i, idx) { return (i.type === "checkbox" ? i.checked : i.value) !== initial[idx]; });
      var label = form.querySelector("[name=label], [name=name]");
      var valid = !label || label.value.trim().length >= (label.name === "label" ? 2 : 1);
      save.disabled = !dirty || !valid;
      save.dataset.dirty = dirty ? "true" : "false";
    }
    fields.forEach(function (i) { i.addEventListener("input", check); i.addEventListener("change", check); });
  }

  /* ---------- two-step confirm (remove member) ---------- */

  document.addEventListener("click", function (e) {
    var start = e.target.closest("[data-confirm-start]");
    if (!start) return;
    var form = start.closest("[data-confirm-two-step]");
    start.hidden = true;
    form.querySelector("[data-confirm-final]").hidden = false;
  });

  /* ---------- notifications dropdown ---------- */

  document.addEventListener("click", function (e) {
    var n = e.target.closest("[data-notification]");
    if (n) {
      var href = n.getAttribute("data-href");
      post("/action/notif-read", { id: n.getAttribute("data-notification") }).then(function () {
        if (href) location.href = href; else refresh();
      });
      return;
    }
    if (e.target.closest("[data-mark-all-read]")) {
      post("/action/notif-read-all", {}).then(refresh);
    }
  });

  /* ---------- file inputs ---------- */

  var MAX = 10 * 1024 * 1024;
  function formatBytes(b) {
    if (!b) return "0 B";
    var k = 1024, s = ["B", "KB", "MB", "GB"], i = Math.floor(Math.log(b) / Math.log(k));
    return parseFloat((b / Math.pow(k, i)).toFixed(1)) + " " + s[i];
  }
  function bindFileInput(root) {
    var input = root.querySelector("input[type=file]");
    var list = root.querySelector("[data-file-list]");
    var problem = root.querySelector("[data-file-problem]");
    var rules = {};
    try { rules = JSON.parse(root.getAttribute("data-rules") || "{}"); } catch (e) {}
    var maxBytes = rules.maxBytes || MAX, maxFiles = rules.maxFiles || 5, exts = rules.exts || [];
    var form = root.closest("form") || root;
    var extOf = function (n) { var m = /\.([^.]+)$/.exec(n || ""); return m ? m[1].toLowerCase() : ""; };
    var iconFor = function (f) {
      if (/^image\//.test(f.type)) return "file-image";
      if (/^video\/|^audio\//.test(f.type)) return "file-video";
      if (/xls|csv|ods/.test(extOf(f.name))) return "file-spreadsheet";
      if (/zip|rar|7z|gz|tar/.test(extOf(f.name))) return "file-archive";
      return "file-text";
    };
    function issue(f) {
      if (f.size > maxBytes) return "larger than " + formatBytes(maxBytes);
      if (exts.length && exts.indexOf(extOf(f.name)) < 0) return "file type not allowed";
      return "";
    }
    function setFiles(arr) {
      var dt = new DataTransfer();
      arr.forEach(function (ff) { dt.items.add(ff); });
      input.files = dt.files;
      render();
    }
    function add(files) {
      var cur = Array.prototype.slice.call(input.files || []);
      Array.prototype.forEach.call(files, function (nf) {
        if (!cur.some(function (c) { return c.name === nf.name && c.size === nf.size; })) cur.push(nf);
      });
      setFiles(cur);
    }
    root.addFiles = add;
    function render() {
      var files = Array.prototype.slice.call(input.files || []);
      list.innerHTML = "";
      list.hidden = files.length === 0;
      var bad = files.filter(issue).length, msgs = [];
      if (bad) msgs.push(bad === 1 ? "1 file can’t be sent — remove it to continue." : bad + " files can’t be sent — remove them to continue.");
      if (files.length > maxFiles) msgs.push("Up to " + maxFiles + " files per message.");
      problem.textContent = msgs.join(" ");
      problem.hidden = !msgs.length;
      form.dataset.filesInvalid = msgs.length ? "1" : "";
      form.dispatchEvent(new CustomEvent("files:change", { detail: { count: files.length } }));
      files.forEach(function (f, i) {
        var why = issue(f);
        var li = document.createElement("li");
        li.className = "flex items-center gap-2.5 rounded-lg border px-2.5 py-1.5 text-sm " +
          (why ? "border-red-300 dark:border-red-500/40 bg-red-50 dark:bg-red-500/10" : "border-border bg-surface-2");
        var thumb = document.createElement("span");
        thumb.className = "grid h-8 w-8 shrink-0 place-items-center overflow-hidden rounded-md bg-surface text-muted";
        if (/^image\/(png|jpeg|gif|webp)$/.test(f.type) && f.size < 15e6) {
          var img = document.createElement("img");
          img.className = "h-full w-full object-cover"; img.alt = "";
          img.src = URL.createObjectURL(f);
          img.onload = function () { URL.revokeObjectURL(img.src); };
          thumb.appendChild(img);
        } else thumb.innerHTML = APP.icons[iconFor(f)] || APP.icons["file-text"];
        li.appendChild(thumb);
        var info = document.createElement("span");
        info.className = "min-w-0 flex-1";
        info.innerHTML = '<span class="block truncate"></span><span class="block text-xs"></span>';
        info.children[0].textContent = f.name;
        info.children[1].textContent = formatBytes(f.size) + (why ? " · " + why : "");
        info.children[1].className = "block text-xs " + (why ? "text-red-600 dark:text-red-400" : "text-muted");
        li.appendChild(info);
        var rm = document.createElement("button");
        rm.type = "button";
        rm.className = "grid h-7 w-7 shrink-0 place-items-center rounded-md text-muted hover:bg-red-500/10 hover:text-red-600 cursor-pointer";
        rm.setAttribute("aria-label", "Remove " + f.name);
        rm.innerHTML = APP.icons["x-sm"];
        rm.addEventListener("click", function () { setFiles(files.filter(function (_, idx) { return idx !== i; })); });
        li.appendChild(rm);
        list.appendChild(li);
      });
    }
    root.querySelector("[data-file-pick]").addEventListener("click", function () { input.click(); });
    input.addEventListener("change", render);
    // Drag & drop anywhere on the form; the editor hands over non-image files.
    form.addEventListener("rte:files", function (e) { depth = 0; form.classList.remove("ring-2", "ring-brand/40"); add(e.detail.files); });
    var depth = 0;
    var hasFiles = function (e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], "Files") >= 0; };
    form.addEventListener("dragenter", function (e) { if (!hasFiles(e)) return; depth++; form.classList.add("ring-2", "ring-brand/40"); });
    form.addEventListener("dragleave", function () { if (--depth <= 0) { depth = 0; form.classList.remove("ring-2", "ring-brand/40"); } });
    form.addEventListener("dragover", function (e) { if (hasFiles(e)) e.preventDefault(); });
    form.addEventListener("drop", function (e) {
      depth = 0; form.classList.remove("ring-2", "ring-brand/40");
      if (!hasFiles(e) || e.defaultPrevented) return;
      e.preventDefault();
      add(e.dataTransfer.files);
    });
  }

  /* ---------- searchable people picker (team / followers) ---------- */

  function bindPeoplePicker(root) {
    var openBtn = root.querySelector("[data-pp-open]");
    var panel = root.querySelector("[data-pp-panel]");
    var input = root.querySelector("[data-pp-search]");
    var list = root.querySelector("[data-pp-results]");
    var status = root.querySelector("[data-pp-status]");
    var multi = root.hasAttribute("data-multi");
    var ticket = root.getAttribute("data-ticket") || "";
    var kind = "all", items = [], active = -1, seq = 0, timer;
    root.ppExclude = root.ppExclude || function () { return []; };
    var esc = function (s) { var d = document.createElement("div"); d.textContent = s == null ? "" : String(s); return d.innerHTML; };
    var mark = function (text, q) {
      var t = esc(text);
      if (!q) return t;
      var i = fold(text).indexOf(fold(q));
      return i < 0 ? t : esc(text.slice(0, i)) + '<mark class="rounded bg-brand/15 px-0.5 text-fg">' + esc(text.slice(i, i + q.length)) + "</mark>" + esc(text.slice(i + q.length));
    };
    var initials = function (n) { return String(n).split(/\s+/).map(function (p) { return p.charAt(0); }).join("").slice(0, 2).toUpperCase(); };
    var roleCls = { SUPER_ADMIN: "bg-amber-500/15 text-amber-700 dark:text-amber-300", ADMIN: "bg-indigo-500/10 text-indigo-700 dark:text-indigo-300", AGENT: "bg-sky-500/10 text-sky-700 dark:text-sky-300", CUSTOMER: "bg-surface-2 text-muted" };

    function render(q) {
      if (!items.length) {
        list.innerHTML = '<li class="px-3 py-8 text-center text-sm text-muted">' + (q ? "Nobody matches “" + esc(q) + "”" : "Nobody else to add") + "</li>";
        return;
      }
      list.innerHTML = items.map(function (p, i) {
        return '<li role="option" id="pp-' + p.id + '" data-i="' + i + '" aria-selected="' + (i === active) + '" class="flex cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 aria-selected:bg-brand/10 hover:bg-surface-2">'
          + '<span class="grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-semibold text-white" style="background:' + esc(p.color) + '">' + esc(initials(p.name)) + "</span>"
          + '<span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">' + mark(p.name, q) + "</span>"
          + '<span class="block truncate text-xs text-muted">' + [p.email ? mark(p.email, q) : "", p.orgs && p.orgs.length ? "🏢 " + mark(p.orgs.join(", "), q) : ""].filter(Boolean).join(" · ") + "</span></span>"
          + '<span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold ' + (roleCls[p.role] || roleCls.CUSTOMER) + '">' + esc(p.roleLabel) + "</span></li>";
      }).join("");
    }
    function load() {
      var q = input.value.trim(), my = ++seq;
      status.textContent = "Searching…";
      var ex = root.ppExclude().join(",");
      fetch("/api/people?q=" + encodeURIComponent(q) + "&kind=" + kind + (ticket ? "&ticketId=" + encodeURIComponent(ticket) : "") + (ex ? "&exclude=" + encodeURIComponent(ex) : ""), { credentials: "same-origin" })
        .then(function (r) { return r.json(); }).then(function (j) {
          if (my !== seq) return; // a newer search already answered
          items = (j && j.data) || [];
          active = items.length ? 0 : -1;
          render(q);
          var c = (j && j.counts) || {};
          $$("[data-pp-count]", root).forEach(function (el) { el.textContent = c[el.getAttribute("data-pp-count")] != null ? c[el.getAttribute("data-pp-count")] : "·"; });
          var total = c[kind] != null ? c[kind] : items.length;
          status.textContent = total > items.length ? "Showing " + items.length + " of " + total + " — type to narrow" : total + (total === 1 ? " person" : " people");
        }).catch(function () { status.textContent = "Couldn’t search — check your connection"; });
    }
    function open() {
      panel.hidden = false;
      openBtn.setAttribute("aria-expanded", "true");
      // Open upwards when there isn't room below (e.g. above the phone's bottom menu).
      var r = openBtn.getBoundingClientRect(), need = 420;
      var up = window.innerHeight - r.bottom < need && r.top > window.innerHeight - r.bottom;
      panel.classList.toggle("bottom-full", up); panel.classList.toggle("mb-2", up); panel.classList.toggle("mt-2", !up);
      input.value = ""; load(); setTimeout(function () { input.focus(); }, 0);
    }
    function close() { panel.hidden = true; openBtn.setAttribute("aria-expanded", "false"); }
    function move(d) {
      if (!items.length) return;
      active = (active + d + items.length) % items.length;
      $$("[data-i]", list).forEach(function (li) { li.setAttribute("aria-selected", String(+li.getAttribute("data-i") === active)); });
      var cur = list.querySelector('[data-i="' + active + '"]'); if (cur) cur.scrollIntoView({ block: "nearest" });
    }
    function pick(i) {
      var p = items[i];
      if (!p) return;
      root.dispatchEvent(new CustomEvent("people:pick", { detail: p, bubbles: true }));
      if (multi) { items.splice(i, 1); active = Math.min(active, items.length - 1); render(input.value.trim()); status.textContent = "Added " + p.name; input.focus(); }
      else close();
    }
    openBtn.addEventListener("click", function () { panel.hidden ? open() : close(); });
    input.addEventListener("input", function () { clearTimeout(timer); timer = setTimeout(load, 150); });
    input.addEventListener("keydown", function (e) {
      if (e.key === "ArrowDown") { e.preventDefault(); move(1); }
      else if (e.key === "ArrowUp") { e.preventDefault(); move(-1); }
      else if (e.key === "Enter") { e.preventDefault(); pick(active); }
      else if (e.key === "Escape") { e.preventDefault(); close(); openBtn.focus(); }
    });
    list.addEventListener("mousedown", function (e) { var li = e.target.closest("[data-i]"); if (li) { e.preventDefault(); pick(+li.getAttribute("data-i")); } });
    $$("[data-pp-kind]", root).forEach(function (b) {
      b.addEventListener("click", function () {
        kind = b.getAttribute("data-pp-kind");
        $$("[data-pp-kind]", root).forEach(function (o) { o.setAttribute("aria-selected", String(o === b)); });
        load(); input.focus();
      });
    });
    document.addEventListener("mousedown", function (e) { if (!panel.hidden && !root.contains(e.target)) close(); });
  }

  /* ---------- team picker (new ticket) ---------- */

  function bindTeamPicker(root) {
    var people = JSON.parse(root.getAttribute("data-people") || "[]");
    var pp = root.querySelector("[data-people-picker]");
    if (pp) pp.ppExclude = function () { return members.map(function (m) { return m.userId; }); };
    var list = root.querySelector("[data-team-list]");
    var tpl = root.querySelector("template[data-team-row]");
    var value = root.querySelector("[data-team-value]");
    var members = [];
    function sync() {
      value.value = JSON.stringify(members.map(function (m) { return { userId: m.userId, role: m.role }; }));
      list.hidden = members.length === 0;
      var chosen = {};
      members.forEach(function (m) { chosen[m.userId] = true; });
      $$("[data-team-add]", root).forEach(function (b) { b.hidden = !!chosen[b.getAttribute("data-team-add")]; });
    }
    function avatarHtml(name, color) {
      var ini = name.split(/\s+/).map(function (p) { return p.charAt(0); }).join("").slice(0, 2).toUpperCase();
      var span = document.createElement("span");
      span.className = "inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white select-none";
      span.style.cssText = "background-color:" + (color || "#6366f1") + ";width:28px;height:28px;font-size:10.64px";
      span.textContent = ini;
      return span;
    }
    function add(id, person) {
      var p = person || people.find(function (x) { return x.id === id; });
      if (!p || members.some(function (m) { return m.userId === id; })) return;
      var m = { userId: id, name: p.name, role: p.staff === false ? "VIEWER" : "COLLABORATOR" };
      members.push(m);
      var li = tpl.content.firstElementChild.cloneNode(true);
      li.querySelector('[data-slot="avatar"]').replaceWith(avatarHtml(p.name, p.color));
      li.querySelector('[data-slot="name"]').textContent = p.name;
      var sel = li.querySelector('[data-slot="role"]');
      sel.value = m.role;
      sel.addEventListener("change", function () { m.role = sel.value; sync(); });
      li.querySelector('[data-slot="remove"]').addEventListener("click", function () {
        members = members.filter(function (x) { return x !== m; });
        li.remove();
        sync();
      });
      list.appendChild(li);
      sync();
    }
    root.addEventListener("click", function (e) {
      var b = e.target.closest("[data-team-add]");
      if (b) add(b.getAttribute("data-team-add"));
    });
    root.addEventListener("people:pick", function (e) { add(e.detail.id, e.detail); });
    sync();
  }

  /* ---------- reply form: internal-note styling ---------- */

  function bindReply(form) {
    var toggle = form.querySelector("[data-internal-toggle]");
    var input = form.querySelector("[data-rte-input]");
    var basePh = input ? input.getAttribute("data-placeholder") : "";
    function apply() {
      var on = !!(toggle && toggle.checked);
      form.dataset.internal = on ? "true" : "false";
      form.querySelector('[data-when="public"]').hidden = on;
      form.querySelector('[data-when="internal"]').hidden = !on;
      var btn = form.querySelector("[data-reply-submit]");
      btn.className = btn.className.replace(/\b(bg-brand text-brand-fg hover:opacity-90 shadow-sm shadow-brand\/20|bg-surface-2 text-fg hover:bg-border\/60)\b/g, "");
      btn.className += on ? " bg-surface-2 text-fg hover:bg-border/60" : " bg-brand text-brand-fg hover:opacity-90 shadow-sm shadow-brand/20";
      if (input) input.setAttribute("data-placeholder", on ? "Write an internal note (only staff can see this)…" : basePh);
    }
    if (toggle) toggle.addEventListener("change", apply);
    apply();
  }

  /* ---------- ticket filters ---------- */

  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-toggle-overdue]");
    if (!b) return;
    var form = b.closest("form");
    var input = form.querySelector("[data-overdue-input]");
    if (input) input.remove();
    else {
      input = document.createElement("input");
      input.type = "hidden"; input.name = "overdue"; input.value = "1";
      form.appendChild(input);
    }
    form.submit();
  });
  // Drop empty params from filter GET forms for clean URLs.
  document.addEventListener("submit", function (e) {
    var f = e.target;
    if (!f.hasAttribute("data-filters")) return;
    $$("input, select", f).forEach(function (i) { if (!i.value) i.disabled = true; });
  });

  /* ---------- saved views ---------- */

  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-save-view]");
    if (!b) return;
    var wrap = b.closest("[data-saved-views]");
    ask("Name this view", { placeholder: "e.g. Urgent billing" }).then(function (name) {
      if (!name) return;
      b.disabled = true;
      post("/action/view-create", { name: name, query: wrap.getAttribute("data-query") || "" }).then(handleResult);
    });
  });

  /* ---------- bulk list ---------- */

  function bindBulk(root) {
    var bar = root.querySelector("[data-bulk-bar]");
    var all = root.querySelector("[data-bulk-all]");
    var items = $$("[data-bulk-item]", root);
    function sync() {
      var ids = items.filter(function (i) { return i.checked; }).map(function (i) { return i.value; });
      bar.hidden = ids.length === 0;
      root.querySelector("[data-bulk-count]").textContent = ids.length;
      root.querySelector("[data-bulk-ids]").value = JSON.stringify(ids);
      all.checked = ids.length > 0 && ids.length === items.length;
    }
    items.forEach(function (i) { i.addEventListener("change", sync); });
    all.addEventListener("change", function () {
      items.forEach(function (i) { i.checked = all.checked; });
      sync();
    });
    root.querySelector("[data-bulk-clear]").addEventListener("click", function () {
      items.forEach(function (i) { i.checked = false; });
      $$("select", bar).forEach(function (s) { s.selectedIndex = 0; });
      sync();
    });
    sync();
  }

  /* ---------- kanban board ---------- */

  function bindBoard(board) {
    if (board.getAttribute("data-can-manage") !== "1") return;
    var dragId = null;
    function col(el) { return el.closest("[data-board-col]"); }
    function updateCounts() {
      $$("[data-board-col]", board).forEach(function (c) {
        var n = $$("[data-board-card]", c).length;
        c.querySelector("[data-board-count]").textContent = n;
        c.querySelector("[data-board-empty]").hidden = n > 0;
      });
    }
    board.addEventListener("dragstart", function (e) {
      var card = e.target.closest("[data-board-card]");
      if (!card) return;
      dragId = card.getAttribute("data-board-card");
      card.classList.add("opacity-50");
      e.dataTransfer.effectAllowed = "move";
      try { e.dataTransfer.setData("text/plain", dragId); } catch (err) {}
    });
    board.addEventListener("dragend", function (e) {
      var card = e.target.closest("[data-board-card]");
      if (card) card.classList.remove("opacity-50");
      $$("[data-board-col]", board).forEach(function (c) { c.dataset.over = "false"; });
      dragId = null;
    });
    board.addEventListener("dragover", function (e) {
      var c = col(e.target);
      if (!c || !dragId) return;
      e.preventDefault();
      $$("[data-board-col]", board).forEach(function (x) { x.dataset.over = x === c ? "true" : "false"; });
    });
    board.addEventListener("drop", function (e) {
      var c = col(e.target);
      if (!c || !dragId) return;
      e.preventDefault();
      var card = board.querySelector('[data-board-card="' + dragId + '"]');
      var from = col(card);
      c.dataset.over = "false";
      if (from === c) return;
      // Optimistic move, then persist.
      c.querySelector("[data-board-cards]").insertBefore(card, c.querySelector("[data-board-empty]"));
      updateCounts();
      var saving = document.createElement("span");
      saving.className = "pointer-events-none fixed bottom-[calc(5rem+env(safe-area-inset-bottom))] right-4 z-[100] lg:bottom-4 inline-flex items-center gap-1.5 rounded-lg bg-surface px-3 py-1.5 text-xs text-muted shadow";
      saving.innerHTML = APP.icons.loader + " Saving…";
      document.body.appendChild(saving);
      post("/action/ticket-update", { ticketId: dragId, status: c.getAttribute("data-board-col") }).then(handleResult);
    });
  }

  /* ---------- settings: canned edit, rule builder, email provider, quiet hours ---------- */

  document.addEventListener("click", function (e) {
    var row = e.target.closest("[data-canned-row]");
    if (!row) return;
    if (e.target.closest("[data-canned-edit]")) {
      row.querySelector("[data-canned-view]").hidden = true;
      row.querySelector("[data-canned-form]").hidden = false;
    } else if (e.target.closest("[data-canned-cancel]")) {
      row.querySelector("[data-canned-view]").hidden = false;
      row.querySelector("[data-canned-form]").hidden = true;
    }
  });

  function fillSelect(sel, opts, first) {
    sel.innerHTML = "";
    var o0 = document.createElement("option");
    o0.value = ""; o0.textContent = first;
    sel.appendChild(o0);
    opts.forEach(function (o) {
      var op = document.createElement("option");
      op.value = o.value; op.textContent = o.label;
      sel.appendChild(op);
    });
  }
  function bindRuleForm(form) {
    var opts = JSON.parse(form.getAttribute("data-options") || "{}");
    var cond = form.querySelector("[data-rule-cond]");
    var condVal = form.querySelector("[data-rule-cond-value]");
    var action = form.querySelector("[data-rule-action]");
    var actionVal = form.querySelector("[data-rule-action-value]");
    cond.addEventListener("change", function () {
      fillSelect(condVal, opts[cond.value] || [], "—");
      condVal.disabled = !cond.value;
    });
    action.addEventListener("change", function () { fillSelect(actionVal, opts[action.value] || [], "Choose…"); });
  }

  function bindEmailForm(form) {
    var sel = form.querySelector("[data-email-provider]");
    function apply() {
      $$("[data-provider-panel]", form).forEach(function (p) { p.hidden = p.getAttribute("data-provider-panel") !== sel.value; });
    }
    sel.addEventListener("change", apply);
  }

  function bindQuiet(root) {
    var t = root.querySelector("[data-quiet-toggle]");
    t.addEventListener("change", function () { root.querySelector("[data-quiet-fields]").hidden = !t.checked; });
  }

  /* ---------- toasts + realtime (SSE) ---------- */

  function toast(message, url, isError, ms) {
    var host = $("[data-toasts]");
    if (!host) {
      host = document.createElement("div");
      host.className = "pointer-events-none fixed bottom-[calc(5rem+env(safe-area-inset-bottom))] right-4 z-[100] flex flex-col gap-2 lg:bottom-4";
      host.setAttribute("data-toasts", "");
      document.body.appendChild(host);
    }
    while (host.children.length >= 4) host.removeChild(host.firstChild);
    var el = document.createElement("div");
    el.className = "pointer-events-auto flex w-80 items-start gap-2.5 rounded-xl border border-border bg-surface p-3 shadow-lg shadow-black/10 animate-in";
    el.innerHTML = '<span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg ' + (isError ? "bg-red-500/10 text-red-600" : "bg-brand/10 text-brand") + '">' + APP.icons.bell + "</span>" +
      '<button type="button" class="min-w-0 flex-1 text-left text-sm cursor-pointer"></button>' +
      '<button type="button" aria-label="Dismiss" class="text-muted hover:text-fg cursor-pointer">' + APP.icons.x + "</button>";
    el.children[1].textContent = message;
    el.children[1].addEventListener("click", function () { if (url) location.href = url; el.remove(); });
    el.children[2].addEventListener("click", function () { el.remove(); });
    host.appendChild(el);
    setTimeout(function () { el.remove(); }, ms || 6000);
  }
  window.TOS.toast = toast;

  // After an update, staff see once which version they're on now, with a link to what's new.
  document.addEventListener("DOMContentLoaded", function () {
    if (!APP.whatsNew || !APP.version || location.pathname === "/about") { try { if (APP.version) localStorage.setItem("tos-seen-version", APP.version); } catch (e) {} return; }
    var seen = null;
    try { seen = localStorage.getItem("tos-seen-version"); } catch (e) { return; }
    if (seen === APP.version) return;
    try { localStorage.setItem("tos-seen-version", APP.version); } catch (e) {}
    setTimeout(function () { toast("Updated to v" + APP.version + " — see what’s new", "/about", false, 15000); }, 1200);
  });

  function startRealtime() {
    if (!$("[data-realtime]") || !window.EventSource) return;
    var es;
    try { es = new EventSource("/api/stream"); } catch (e) { return; }
    var pending = false;
    es.onmessage = function (e) {
      try {
        var d = JSON.parse(e.data);
        if (d.kind !== "notification") return;
        toast(d.message, d.url, false);
        bumpBell();
        pending = true;
      } catch (err) {}
    };
    // Refresh the page shortly after new activity, unless the user is typing.
    setInterval(function () {
      if (!pending) return;
      var active = document.activeElement;
      var typing = active && (active.isContentEditable || /INPUT|TEXTAREA|SELECT/.test(active.tagName));
      var dirtyEditor = $$("[data-rte-value]").some(function (i) { return i.value; });
      if (!typing && !dirtyEditor && !$("[data-dropdown-menu]:not([hidden])")) { pending = false; refresh(); }
    }, 7000);
  }
  function bumpBell() {
    var btn = $('[aria-label="Notifications"]');
    if (!btn) return;
    var badge = btn.querySelector("span.bg-red-500");
    if (!badge) {
      badge = document.createElement("span");
      badge.className = "absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white";
      badge.textContent = "0";
      btn.appendChild(badge);
    }
    var n = (parseInt(btn.getAttribute("data-unread"), 10) || 0) + 1;
    btn.setAttribute("data-unread", n);
    badge.textContent = n > 9 ? "9+" : String(n);
    updateBadge();
  }

  /* ---------- Web Push ---------- */

  function urlBase64ToUint8Array(base64) {
    var padding = "=".repeat((4 - (base64.length % 4)) % 4);
    var b64 = (base64 + padding).replace(/-/g, "+").replace(/_/g, "/");
    var raw = atob(b64);
    var arr = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }
  function jsonPost(url, body) {
    return fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": APP.csrf },
      body: JSON.stringify(body || {}),
    });
  }
  function bindPush(root) {
    if (!("serviceWorker" in navigator) || !("PushManager" in window) || !APP.vapidKey) return;
    root.hidden = false;
    function setState(state) {
      $$("[data-push-icon]", root).forEach(function (i) {
        i.hidden = i.getAttribute("data-push-icon") !== (state === "loading" ? "busy" : state);
      });
      var on = state === "on";
      var btn = root.querySelector("[data-push-button]");
      btn.classList.toggle("text-brand", on);
      btn.classList.toggle("text-muted", !on);
      root.querySelector("[data-push-text]").textContent = on
        ? "This device will receive push notifications."
        : "Get notified on this device even when the app is closed.";
      root.querySelector("[data-push-enable]").hidden = on;
      root.querySelector("[data-push-disable]").hidden = !on;
    }
    setState("busy");
    navigator.serviceWorker.register("/sw.js")
      .then(function (reg) { return reg.pushManager.getSubscription(); })
      .then(function (sub) { setState(sub ? "on" : "off"); })
      .catch(function () { setState("off"); });

    root.querySelector("[data-push-enable]").addEventListener("click", function () {
      setState("busy");
      Notification.requestPermission().then(function (perm) {
        if (perm !== "granted") { setState("off"); return; }
        return navigator.serviceWorker.register("/sw.js")
          .then(function () { return navigator.serviceWorker.ready; })
          .then(function (reg) {
            return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(APP.vapidKey) });
          })
          .then(function (sub) { return jsonPost("/api/push/subscribe", sub.toJSON()); })
          .then(function () { setState("on"); });
      }).catch(function () { setState("off"); });
    });
    root.querySelector("[data-push-disable]").addEventListener("click", function () {
      setState("busy");
      navigator.serviceWorker.ready
        .then(function (reg) { return reg.pushManager.getSubscription(); })
        .then(function (sub) {
          if (!sub) return;
          return jsonPost("/api/push/unsubscribe", { endpoint: sub.endpoint }).then(function () { return sub.unsubscribe(); });
        })
        .catch(function () {})
        .then(function () { setState("off"); });
    });
  }

  /* ---------- live search (suggestions as you type) ---------- */

  var SEARCH_TIPS = [
    ["status:open", "by status"], ["priority:high", "by priority"], ["assignee:me", "assigned to me"],
    ["is:overdue", "past SLA"], ["tag:bug", "by tag"], ["requester:", "by requester"],
    ["created:7d", "last 7 days"], ['"exact phrase"', "phrase"], ["#42", "ticket number"],
  ];
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function highlight(text, q) {
    text = String(text);
    var words = (q.match(/"[^"]+"|\S+/g) || [])
      .filter(function (w) { return !/^\w+:/.test(w) && !/^#?\d+$/.test(w); })
      .map(function (w) { return fold(w.replace(/"/g, "")); })
      .filter(function (w) { return w.length > 1; });
    // Mark the matching ranges on the folded text, then wrap the original characters.
    var f = fold(text), hit = new Array(text.length);
    words.forEach(function (w) { for (var i = f.indexOf(w); i !== -1; i = f.indexOf(w, i + w.length)) for (var k = i; k < i + w.length; k++) hit[k] = true; });
    var html = "", open = false;
    for (var i = 0; i < text.length; i++) {
      if (hit[i] && !open) { html += '<mark class="rounded bg-amber-200/70 px-0.5 text-inherit dark:bg-amber-400/30">'; open = true; }
      if (!hit[i] && open) { html += "</mark>"; open = false; }
      html += esc(text.charAt(i));
    }
    return html + (open ? "</mark>" : "");
  }
  function bindLiveSearch(input) {
    var form = input.form;
    var anchor = input.closest("[data-live-wrap]") || input.parentElement;
    var panel = document.createElement("div");
    panel.hidden = true;
    panel.setAttribute("role", "listbox");
    panel.className = "absolute inset-x-0 top-full z-50 mt-1.5 max-h-[70vh] overflow-y-auto rounded-xl border border-border bg-surface text-left shadow-xl shadow-black/10 animate-in";
    anchor.appendChild(panel);
    var timer = null, ctrl = null, active = -1, items = [];

    function close() { panel.hidden = true; active = -1; }
    function setActive(i) {
      items = $$("[data-sr]", panel);
      if (!items.length) return;
      active = (i + items.length) % items.length;
      items.forEach(function (el, idx) { el.dataset.active = idx === active ? "true" : "false"; });
      items[active].scrollIntoView({ block: "nearest" });
    }
    function tips() {
      panel.innerHTML = '<div class="p-3"><p class="mb-2 text-xs font-medium text-muted">Search subject, description, replies, people and tags — or narrow down with:</p><div class="flex flex-wrap gap-1.5">' +
        SEARCH_TIPS.map(function (t) {
          return '<button type="button" data-tip="' + esc(t[0]) + '" class="inline-flex items-center gap-1 rounded-md border border-border bg-surface-2 px-2 py-1 text-xs hover:border-brand/50 cursor-pointer"><code class="font-mono text-brand">' + esc(t[0]) + '</code><span class="text-muted">' + esc(t[1]) + "</span></button>";
        }).join("") + "</div></div>";
      panel.hidden = false;
    }
    function render(data, q) {
      var html = "";
      if (!data.items.length) {
        html = '<p class="px-4 py-6 text-center text-sm text-muted">No tickets match <strong class="text-fg">' + esc(q) + "</strong></p>";
      } else {
        html = data.items.map(function (it) {
          return '<a data-sr href="/tickets/' + esc(it.id) + '" class="flex gap-3 border-b border-border px-3.5 py-2.5 hover:bg-surface-2 data-[active=true]:bg-surface-2">' +
            '<span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" style="background:' + esc(it.status.color) + '" title="' + esc(it.status.label) + '"></span>' +
            '<span class="min-w-0 flex-1"><span class="flex items-baseline gap-2"><span class="shrink-0 font-mono text-xs text-muted">#' + it.number + '</span><span class="truncate text-sm font-medium">' + highlight(it.subject, q) + "</span></span>" +
            (it.snippet ? '<span class="mt-0.5 line-clamp-2 block text-xs text-muted">' + highlight(it.snippet, q) + "</span>" : "") + "</span>" +
            '<span class="shrink-0 self-center rounded-full px-2 py-0.5 text-[11px] font-medium" style="background:' + esc(it.priority.color) + '22;color:' + esc(it.priority.color) + '">' + esc(it.priority.label) + "</span></a>";
        }).join("");
      }
      var more = data.total > data.items.length ? (data.total >= 1000 ? "1000+" : data.total) : data.items.length;
      html += '<button type="submit" data-sr-all class="flex w-full items-center justify-between px-3.5 py-2.5 text-sm font-medium text-brand hover:bg-surface-2 cursor-pointer"><span>See all results' +
        (data.items.length ? " (" + more + ")" : "") + '</span><span class="text-xs font-normal text-muted">Enter ↵</span></button>';
      panel.innerHTML = html;
      panel.hidden = false;
      active = -1;
    }
    function run() {
      var q = input.value.trim();
      if (!q) { tips(); return; }
      if (ctrl) ctrl.abort();
      ctrl = window.AbortController ? new AbortController() : null;
      fetch("/api/search?q=" + encodeURIComponent(q), { credentials: "same-origin", headers: { Accept: "application/json" }, signal: ctrl ? ctrl.signal : undefined })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (input.value.trim() === q && d.items) render(d, q); })
        .catch(function () {});
    }
    input.setAttribute("autocomplete", "off");
    input.addEventListener("input", function () { clearTimeout(timer); timer = setTimeout(run, 200); });
    input.addEventListener("focus", function () { if (!input.value.trim()) tips(); else run(); });
    input.addEventListener("keydown", function (e) {
      if (panel.hidden) return;
      if (e.key === "ArrowDown") { e.preventDefault(); setActive(active + 1); }
      else if (e.key === "ArrowUp") { e.preventDefault(); setActive(active - 1); }
      else if (e.key === "Escape") { close(); }
      else if (e.key === "Enter" && active >= 0 && items[active]) { e.preventDefault(); location.href = items[active].href; }
    });
    panel.addEventListener("mousedown", function (e) { if (e.target.closest("[data-tip]")) e.preventDefault(); });
    panel.addEventListener("click", function (e) {
      var tip = e.target.closest("[data-tip]");
      if (tip) {
        var v = tip.getAttribute("data-tip");
        input.value = (input.value.trim() ? input.value.trim() + " " : "") + (v.endsWith(":") ? v : v + " ");
        input.focus();
        run();
      }
    });
    document.addEventListener("click", function (e) { if (!anchor.contains(e.target)) close(); });
    if (form) form.addEventListener("submit", close);
  }

  /* ---------- phone search overlay ---------- */

  document.addEventListener("click", function (e) {
    var panel = $("[data-search-panel]");
    if (!panel) return;
    if (e.target.closest("[data-search-open]")) {
      panel.hidden = false;
      var i = panel.querySelector("input[name=q]");
      setTimeout(function () { i.focus(); }, 0);
    } else if (e.target.closest("[data-search-close]")) {
      panel.hidden = true;
    }
  });

  /* ---------- mobile filters toggle ---------- */

  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-filters-toggle]");
    if (!b) return;
    var p = b.closest("form").querySelector("[data-filters-panel]");
    var open = p.dataset.open !== "true";
    p.dataset.open = open ? "true" : "false";
    b.setAttribute("aria-expanded", open ? "true" : "false");
  });

  /* ---------- share / copy link ---------- */

  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-share]");
    if (!b) return;
    var url = location.origin + location.pathname;
    var title = b.getAttribute("data-share-title") || document.title;
    if (navigator.share && window.matchMedia("(pointer: coarse)").matches) {
      navigator.share({ title: title, url: url }).catch(function () {});
      return;
    }
    (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () {
      var l = b.querySelector("[data-share-label]");
      if (l) { var t = l.textContent; l.textContent = "Link copied"; setTimeout(function () { l.textContent = t; }, 1800); }
    }, function () { ask("Copy this link", { value: url, ok: "Done" }); });
  });

  /* ---------- settings tabs ---------- */

  function bindSettings(root) {
    var sections = $$("[data-settings-section]", root);
    var tabs = $$("[data-settings-tab]", root);
    function show(id) {
      if (!sections.some(function (s) { return s.id === id; })) id = sections[0].id;
      sections.forEach(function (s) { s.hidden = s.id !== id; });
      tabs.forEach(function (t) {
        var on = t.getAttribute("data-settings-tab") === id;
        t.dataset.active = on ? "true" : "false";
        if (on) t.scrollIntoView({ block: "nearest", inline: "nearest" });
      });
      // Saving a form comes back to the same tab.
      $$('input[name="_back"]', root).forEach(function (i) { i.value = location.pathname + location.search + "#" + id; });
    }
    tabs.forEach(function (t) {
      t.addEventListener("click", function (e) {
        e.preventDefault();
        var id = t.getAttribute("data-settings-tab");
        history.replaceState(null, "", "#" + id);
        show(id);
        var main = $("[data-main]");
        if (main && main.scrollTop > 200) main.scrollTo({ top: 0, behavior: "smooth" });
      });
    });
    show(location.hash.slice(1));
  }

  /* ---------- PWA: service worker, install prompt, offline, badge ---------- */

  var deferredInstall = null;
  var isStandalone = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);

  function registerSW() {
    if (!("serviceWorker" in navigator)) return;
    navigator.serviceWorker.register("/sw.js").catch(function () {});
    navigator.serviceWorker.addEventListener("message", function (e) {
      if (e.data && e.data.type === "navigate" && e.data.url) location.href = e.data.url;
    });
  }
  window.addEventListener("beforeinstallprompt", function (e) {
    e.preventDefault();
    deferredInstall = e;
    $$("[data-install-app]").forEach(function (b) { b.hidden = false; });
  });
  window.addEventListener("appinstalled", function () {
    deferredInstall = null;
    $$("[data-install-app]").forEach(function (b) { b.hidden = true; });
    toast("TicketingOS was installed on this device", null, false);
  });
  document.addEventListener("click", function (e) {
    if (!e.target.closest("[data-install-app]")) return;
    if (deferredInstall) {
      deferredInstall.prompt();
      deferredInstall.userChoice.finally(function () { deferredInstall = null; });
    } else if (isIOS) {
      toast("On iPhone/iPad: tap Share, then “Add to Home Screen”.", null, false);
    }
  });
  function offlineBanner() {
    var b = $("[data-offline-banner]");
    if (b) b.hidden = navigator.onLine;
  }
  window.addEventListener("online", offlineBanner);
  window.addEventListener("offline", offlineBanner);
  // Sign-out wipes cached pages so the next person on this device sees nothing.
  document.addEventListener("submit", function (e) {
    if (!e.target.hasAttribute("data-logout")) return;
    try {
      if (navigator.serviceWorker && navigator.serviceWorker.controller) navigator.serviceWorker.controller.postMessage({ type: "clear" });
      if (window.caches) caches.keys().then(function (ks) { ks.forEach(function (k) { if (k.indexOf("tos-pages") === 0) caches.delete(k); }); });
      if (navigator.clearAppBadge) navigator.clearAppBadge();
    } catch (err) {}
  }, true);
  document.addEventListener("submit", function (e) {
    if (!navigator.onLine && e.target.method === "post") {
      e.preventDefault();
      e.stopImmediatePropagation();
      toast("You're offline — reconnect to save changes.", null, true);
    }
  }, true);

  /** App-icon badge with the unread notification count (installed PWAs). */
  function updateBadge() {
    var btn = $('[aria-label="Notifications"][data-unread]');
    if (!btn || !("setAppBadge" in navigator)) return;
    var n = parseInt(btn.getAttribute("data-unread"), 10) || 0;
    (n > 0 ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(function () {});
  }

  /* ---------- pull to refresh (installed app on phones) ---------- */

  function bindPullToRefresh() {
    var main = $("[data-main]");
    var ind = $("[data-ptr]");
    if (!main || !ind || !("ontouchstart" in window)) return;
    var startY = null, dist = 0;
    main.addEventListener("touchstart", function (e) { startY = main.scrollTop <= 0 ? e.touches[0].clientY : null; dist = 0; }, { passive: true });
    main.addEventListener("touchmove", function (e) {
      if (startY === null) return;
      dist = Math.max(0, e.touches[0].clientY - startY);
      ind.style.opacity = Math.min(1, dist / 80);
      ind.style.transform = "translateY(" + Math.min(dist / 2, 48) + "px) rotate(" + dist * 3 + "deg)";
    }, { passive: true });
    main.addEventListener("touchend", function () {
      if (startY !== null && dist > 90 && navigator.onLine) { ind.firstElementChild.classList.add("animate-spin"); location.reload(); return; }
      ind.style.opacity = 0; ind.style.transform = ""; startY = null;
    });
  }

  /* ---------- Custom fields follow the chosen category ---------- */

  function syncFieldCategories(form) {
    var cat = form.querySelector('select[name=categoryId]');
    var v = cat ? cat.value : '';
    $$('[data-field-category]', form).forEach(function (el) {
      var c = el.getAttribute('data-field-category');
      var on = !c || c === v;
      el.hidden = !on;
      $$('input,select,textarea', el).forEach(function (i) { i.disabled = !on; });
    });
  }
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('select[name=categoryId]') && e.target.form && e.target.form.querySelector('[data-fields]')) syncFieldCategories(e.target.form);
    if (e.target.matches && e.target.matches('input[data-field-type]')) {
      var opts = e.target.form.querySelector('[data-field-options]');
      if (opts) opts.hidden = e.target.value !== 'select';
    }
  });
  document.addEventListener('DOMContentLoaded', function () {
    $$('[data-fields]').forEach(function (b) { if (b.closest('form')) syncFieldCategories(b.closest('form')); });
  });

  // Copy-to-clipboard buttons.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    var v = b.getAttribute('data-copy');
    (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).catch(function () {
      var t = document.createElement('textarea'); t.value = v; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove();
    });
    toast('Copied to clipboard');
  });

  // Group member picker filter.
  document.addEventListener('input', function (e) {
    if (!e.target.matches || !e.target.matches('[data-member-filter]')) return;
    var q = fold(e.target.value.trim());
    $$('[data-member-name]', e.target.form).forEach(function (l) { l.hidden = q && fold(l.getAttribute('data-member-name')).indexOf(q) < 0; });
  });
  document.addEventListener('change', function (e) {
    if (!e.target.matches || !e.target.matches('[data-member-list] input')) return;
    var c = e.target.form.querySelector('[data-member-count]');
    if (c) c.textContent = '(' + $$('[data-member-list] input:checked', e.target.form).length + ')';
  });

  /* ---------- Settings → Roles & permissions ---------- */

  function bindPermForm(form) {
    var boxes = $$("input[data-perm]", form);
    var save = form.querySelector("[data-perm-save]");
    var note = form.querySelector("[data-dirty-note]");
    var totalEl = form.querySelector("[data-perm-total]");
    function snapshot() {
      return $$("input:not([type=hidden]):not([data-group-all]):not([data-custom-color])", form)
        .map(function (i) { return i.type === "checkbox" || i.type === "radio" ? (i.checked ? "1" : "0") : i.value; }).join("|");
    }
    var initial = null;
    function box(key) { return form.querySelector('input[data-perm="' + key + '"]'); }
    function update() {
      var on = 0;
      boxes.forEach(function (b) { if (b.checked) on++; });
      if (totalEl) totalEl.textContent = on + "/" + boxes.length;
      $$("[data-perm-group]", form).forEach(function (g) {
        var gb = $$("input[data-perm]", g), n = gb.filter(function (b) { return b.checked; }).length;
        var c = g.querySelector("[data-group-count]"); if (c) c.textContent = n;
        var all = g.querySelector("[data-group-all]");
        if (all) { all.checked = n === gb.length; all.dataset.partial = n > 0 && n < gb.length ? "true" : "false"; }
      });
      if (initial !== null) {
        var dirty = snapshot() !== initial || form.hasAttribute("data-perm-new");
        if (note) note.hidden = !(dirty && !form.hasAttribute("data-perm-new"));
        if (save) save.disabled = !dirty;
      }
    }
    // Dependencies: turning on X turns on what it needs; turning off a requirement turns off its dependants.
    form.addEventListener("change", function (e) {
      var t = e.target;
      if (t.hasAttribute("data-perm")) {
        var req = t.getAttribute("data-requires");
        if (t.checked && req && box(req)) box(req).checked = true;
        if (!t.checked) boxes.forEach(function (b) { if (b.getAttribute("data-requires") === t.getAttribute("data-perm")) b.checked = false; });
      } else if (t.hasAttribute("data-group-all")) {
        var g = t.closest("[data-perm-group]");
        $$("input[data-perm]", g).forEach(function (b) { b.checked = t.checked; });
        boxes.forEach(function (b) { var r = b.getAttribute("data-requires"); if (b.checked && r && box(r)) box(r).checked = true; });
        boxes.forEach(function (b) { var r = b.getAttribute("data-requires"); if (r && box(r) && !box(r).checked) b.checked = false; });
      } else if (t.hasAttribute("data-template")) {
        var keys = JSON.parse(t.getAttribute("data-template") || "[]");
        boxes.forEach(function (b) { b.checked = keys.indexOf(b.getAttribute("data-perm")) >= 0; });
        var name = form.querySelector("[data-role-name]");
        if (name && (!name.value || name.dataset.auto === "1") && t.value !== "blank") {
          name.value = t.closest("label").querySelector(".font-medium").textContent;
          name.dataset.auto = "1";
          name.dispatchEvent(new Event("input", { bubbles: true }));
        }
      } else if (t.hasAttribute("data-role-color")) {
        var sw = form.querySelector("[data-role-swatch]"); if (sw) sw.style.background = t.value;
      }
      update();
    });
    form.addEventListener("input", function (e) {
      var t = e.target;
      // Checkboxes and radios are handled on "change" (which fires after "input").
      if (t.type === "checkbox" || t.type === "radio") return;
      if (t.hasAttribute("data-role-name")) {
        if (e.isTrusted) delete t.dataset.auto;
        var title = form.querySelector("[data-role-title]"); if (title) title.textContent = t.value.trim() || "New role";
      }
      if (t.hasAttribute("data-custom-color")) {
        var radio = t.parentNode.querySelector("[data-custom-color-radio]");
        radio.value = t.value; radio.checked = true;
        var dot = radio.nextElementSibling; dot.style.background = t.value; dot.style.color = "#fff";
        var sw = form.querySelector("[data-role-swatch]"); if (sw) sw.style.background = t.value;
      }
      update();
    });
    form.addEventListener("submit", function () {
      initial = snapshot();
      window.removeEventListener("beforeunload", warn);
      // Come back to this role after saving.
      var back = form.querySelector('input[name="_back"]'); if (back) back.value = location.pathname + location.search + "#roles";
    });
    function warn(e) { if (save && !save.disabled && !form.hidden && !form.hasAttribute("data-perm-new")) { e.preventDefault(); e.returnValue = ""; } }
    window.addEventListener("beforeunload", warn);
    update();
    initial = snapshot();
    update();
  }

  function bindRoles(root) {
    function show(key) {
      var panel = root.querySelector('[data-role-panel="' + key + '"]');
      if (!panel) return false;
      $$("[data-role-panel]", root).forEach(function (p) { p.hidden = p !== panel; });
      $$("[data-role-pick]", root).forEach(function (a) { a.dataset.active = a.getAttribute("data-role-pick") === key ? "true" : "false"; });
      var url = new URL(location.href);
      url.searchParams.set("role", key);
      url.searchParams.delete("from");
      history.replaceState(null, "", url.pathname + url.search + "#roles");
      var first = panel.querySelector("[data-role-name]");
      if (key === "new" && first) setTimeout(function () { first.focus(); }, 0);
      return true;
    }
    document.addEventListener("click", function (e) {
      var a = e.target.closest("[data-role-pick]");
      if (!a || e.metaKey || e.ctrlKey) return;
      if (show(a.getAttribute("data-role-pick"))) {
        e.preventDefault();
        if (window.innerWidth < 1024) root.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    });
    $$("form[data-perm-form]", root).forEach(bindPermForm);
  }

  // Jump between settings tabs from a link inside a section.
  document.addEventListener("click", function (e) {
    var j = e.target.closest("[data-settings-jump]");
    if (!j) return;
    var tab = $('[data-settings-tab="' + j.getAttribute("data-settings-jump") + '"]');
    if (tab) { e.preventDefault(); tab.click(); }
  });

  // Typing or following a #tab link on the settings page switches the tab.
  window.addEventListener("hashchange", function () {
    var tab = $('[data-settings-tab="' + location.hash.slice(1) + '"]');
    if (tab && tab.dataset.active !== "true") tab.click();
  });

  // Sign-in screens: show/hide password, strength meter, Caps Lock hint.
  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-reveal]");
    if (!b) return;
    var input = document.getElementById(b.getAttribute("data-reveal"));
    if (!input) return;
    var show = input.type === "password";
    input.type = show ? "text" : "password";
    b.querySelector("[data-reveal-show]").hidden = show;
    b.querySelector("[data-reveal-hide]").hidden = !show;
    b.setAttribute("aria-label", show ? "Hide password" : "Show password");
    input.focus();
  });
  document.addEventListener("input", function (e) {
    var input = e.target;
    if (!input.matches || !input.matches("input[data-strength]")) return;
    var box = document.querySelector('[data-strength-for="' + input.id + '"]');
    if (!box) return;
    var v = input.value, score = 0;
    if (v.length >= 8) score++;
    if (v.length >= 12) score++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
    if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) score++;
    if (v.length && v.length < 6) score = 0;
    var levels = [["bg-red-500", "Too short"], ["bg-red-500", "Weak"], ["bg-amber-500", "Fair"], ["bg-lime-500", "Good"], ["bg-emerald-500", "Strong"]];
    var lvl = v.length ? Math.max(score, v.length >= 6 ? 1 : 0) : -1;
    $$("[data-bar]", box).forEach(function (bar, i) {
      bar.className = "h-1 rounded-full transition-colors duration-300 " + (lvl >= 0 && i < Math.max(lvl, 1) ? levels[lvl][0] : "bg-border");
    });
    box.querySelector("[data-strength-label]").textContent = lvl < 0 ? "Use 8+ characters with a mix of letters, numbers and symbols." : levels[lvl][1] + (lvl < 4 ? " — add length, capitals, numbers or symbols." : " password.");
  });
  ["keyup", "keydown"].forEach(function (ev) {
    document.addEventListener(ev, function (e) {
      if (!e.target.matches || !e.target.matches('input[type="password"], input[data-reveal-target]') || !e.getModifierState) return;
      var hint = document.querySelector('[data-caps-for="' + e.target.id + '"]');
      if (hint) hint.hidden = !e.getModifierState("CapsLock");
    });
  });

  // Settings → Branding: live preview of name and logo.
  document.addEventListener("input", function (e) {
    if (!e.target.matches || !e.target.matches("[data-brand-name]")) return;
    var v = e.target.value.trim() || "TicketingOS";
    $$("[data-brand-preview-name]").forEach(function (el) { el.textContent = v; });
    $$("[data-brand-preview-tab]").forEach(function (el) { el.textContent = v + " — Helpdesk"; });
  });
  document.addEventListener("change", function (e) {
    if (!e.target.matches || !e.target.matches("[data-brand-file]")) return;
    var f = e.target.files && e.target.files[0];
    if (!f) return;
    var url = URL.createObjectURL(f);
    $$("[data-brand-preview-mark]").forEach(function (el) { el.innerHTML = '<img alt="" class="h-8 w-8 rounded-xl object-contain" src="' + url + '">'; });
    $$("[data-brand-preview-fav]").forEach(function (el) {
      var img = document.createElement("img"); img.src = url; img.alt = ""; img.className = "h-4 w-4 rounded-sm object-contain"; img.setAttribute("data-brand-preview-fav", "");
      el.replaceWith(img);
    });
    var rm = document.querySelector("[data-brand-remove]"); if (rm) rm.checked = false;
    var lbl = e.target.closest("label"); if (lbl) { var t = lbl.querySelector(".text-sm"); if (t) t.textContent = f.name; }
  });

  // Multi-select picker (ticket assignees): close on outside click, save on close.
  document.addEventListener("click", function (e) {
    $$("details[data-multi-pick][open]").forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
  });
  document.addEventListener("toggle", function (e) {
    var d = e.target;
    if (!d.matches || !d.matches("details[data-multi-pick]") || d.open || d.dataset.dirty !== "1") return;
    d.dataset.dirty = "";
    var form = d.closest("form");
    var spin = form.querySelector("[data-spinner]");
    if (spin) spin.hidden = false;
    fetch(form.action, { method: "POST", body: new FormData(form), headers: { "Accept": "application/json", "X-Requested-With": "fetch" }, credentials: "same-origin" })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (j) {
        if (j && j.error) { if (spin) spin.hidden = true; notice(j.error, true); }
        else location.reload();
      })
      .catch(function () { location.reload(); });
  }, true);

  /* ---------- New-ticket form: customer, duplicates, SLA, templates ---------- */
  function bindNewTicket(form) {
    var esc = function (s) { var d = document.createElement("div"); d.textContent = s == null ? "" : String(s); return d.innerHTML; };
    var debounce = function (fn, ms) { var t; return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); }; };
    var reqId = form.querySelector("[data-requester-id]");

    // Who is this for?
    function showMode() {
      var m = (form.querySelector("[data-requester-mode]:checked") || {}).value || "self";
      $$("[data-mode-panel]", form).forEach(function (p) { p.hidden = p.getAttribute("data-mode-panel") !== m; });
      checkSimilar();
    }
    $$("[data-requester-mode]", form).forEach(function (r) { r.addEventListener("change", showMode); });

    var search = form.querySelector("[data-customer-search]");
    var results = form.querySelector("[data-customer-results]");
    var chosen = form.querySelector("[data-customer-chosen]");
    function pick(c) {
      reqId.value = c.id;
      chosen.querySelector("[data-customer-label]").textContent = c.name + " · " + c.email + (c.org ? " · " + c.org : "");
      chosen.hidden = false; search.hidden = true; results.hidden = true;
      checkSimilar();
    }
    if (search) {
      search.addEventListener("input", debounce(function () {
        var q = search.value.trim();
        if (q.length < 2) { results.hidden = true; return; }
        fetch("/api/customers?q=" + encodeURIComponent(q), { credentials: "same-origin" }).then(function (r) { return r.json(); }).then(function (j) {
          var list = (j && j.data) || [];
          results.innerHTML = list.length ? list.map(function (c, i) {
            return '<button type="button" data-i="' + i + '" class="flex w-full flex-col items-start rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-2 cursor-pointer"><span class="font-medium">' + esc(c.name) + '</span><span class="text-xs text-muted">' + esc(c.email) + (c.org ? " · " + esc(c.org) : "") + "</span></button>";
          }).join("") : '<p class="px-3 py-2 text-sm text-muted">No customer found — use “New customer”.</p>';
          $$("[data-i]", results).forEach(function (b) { b.addEventListener("click", function () { pick(list[+b.getAttribute("data-i")]); }); });
          results.hidden = false;
        });
      }, 250));
      form.querySelector("[data-customer-clear]").addEventListener("click", function () {
        reqId.value = ""; chosen.hidden = true; search.hidden = false; search.value = ""; search.focus(); checkSimilar();
      });
      document.addEventListener("click", function (e) { if (!results.contains(e.target) && e.target !== search) results.hidden = true; });
    }

    // Possible duplicates while the subject is typed.
    var subj = form.querySelector("[data-similar-source]");
    var simBox = form.querySelector("[data-similar]");
    var simList = form.querySelector("[data-similar-list]");
    var lastQ = "";
    function checkSimilar() {
      if (!subj || !simBox) return;
      var q = subj.value.trim();
      var mode = (form.querySelector("[data-requester-mode]:checked") || {}).value || "self";
      var rid = mode === "customer" ? (reqId && reqId.value) || "" : "";
      var key = q + "|" + rid;
      if (q.length < 6) { simBox.hidden = true; lastQ = ""; return; }
      if (key === lastQ) return;
      lastQ = key;
      fetch("/api/tickets/similar?q=" + encodeURIComponent(q) + (rid ? "&requesterId=" + encodeURIComponent(rid) : ""), { credentials: "same-origin" })
        .then(function (r) { return r.json(); }).then(function (j) {
          var list = (j && j.data) || [];
          simList.innerHTML = list.map(function (t) {
            return '<li><a href="' + t.url + '" target="_blank" rel="noopener" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-white/60 dark:hover:bg-white/5"><span class="font-mono text-xs text-muted">#' + t.number + '</span><span class="min-w-0 flex-1 truncate">' + esc(t.subject) + '</span><span class="shrink-0 text-xs text-muted">' + esc(t.status) + " · " + esc(t.ago) + "</span></a></li>";
          }).join("");
          simBox.hidden = !list.length;
        }).catch(function () {});
    }
    if (subj) subj.addEventListener("input", debounce(checkSimilar, 450));

    // Resolution target preview from the priority (or the custom deadline).
    var sla = {};
    try { sla = JSON.parse(form.getAttribute("data-sla") || "{}"); } catch (e) {}
    var prio = form.querySelector("[data-priority]");
    var prev = form.querySelector("[data-sla-preview]");
    var due = form.querySelector("[data-due-input]");
    var fmt = function (d) { return d.toLocaleString(undefined, { weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }); };
    function showSla() {
      if (!prev || !prio) return;
      var span = prev.querySelector("span");
      if (due && due.value) { span.textContent = "Custom deadline: " + fmt(new Date(due.value)); prev.hidden = false; return; }
      var h = sla[prio.value] || 0;
      if (!h) { prev.hidden = true; return; }
      span.textContent = "Resolution target: " + fmt(new Date(Date.now() + h * 3600e3)) + " (" + (h % 24 ? h + "h" : h / 24 + (h === 24 ? " day" : " days")) + ")";
      prev.hidden = false;
    }
    if (prio) prio.addEventListener("change", showSla);
    if (due) due.addEventListener("change", showSla);
    showSla();

    // Templates fill the form.
    var tpls = [];
    try { tpls = JSON.parse(form.getAttribute("data-templates") || "[]"); } catch (e) {}
    $$("[data-use-template]", form).forEach(function (b) {
      b.addEventListener("click", function () {
        var t = tpls.filter(function (x) { return x.id === b.getAttribute("data-use-template"); })[0];
        if (!t) return;
        $$("[data-use-template]", form).forEach(function (o) { o.dataset.on = o === b ? "true" : "false"; });
        form.querySelector("[data-template-id]").value = t.id;
        if (t.subject && subj) { subj.value = t.subject; checkSimilar(); }
        var ed = form.querySelector('[data-rte] [data-rte-value][name="description"]');
        if (t.body && ed) {
          var root = ed.closest("[data-rte]");
          root.querySelector("[data-rte-input]").innerHTML = t.body;
          ed.value = t.body;
        }
        if (t.priority && prio) { prio.value = t.priority; showSla(); }
        var cat = form.querySelector("#categoryId");
        if (t.categoryId && cat) { cat.value = t.categoryId; cat.dispatchEvent(new Event("change", { bubbles: true })); }
        var tags = form.querySelector("[data-tags-input]");
        if (tags && t.tags && t.tags.length) tags.value = t.tags.join(", ");
        var subs = form.querySelector("[data-subtasks-input]");
        if (subs && t.subtasks && t.subtasks.length) subs.value = t.subtasks.join("\n");
        $$("[data-assignee-box]", form).forEach(function (c) { if (!c.disabled && t.assigneeIds) c.checked = t.assigneeIds.indexOf(c.value) >= 0; });
        if ((t.assigneeIds && t.assigneeIds.length) || (t.subtasks && t.subtasks.length) || (t.tags && t.tags.length)) {
          var det = subs && subs.closest("details"); if (det) det.open = true;
        }
      });
    });
    showMode();
  }
  document.addEventListener("DOMContentLoaded", function () { $$("form[data-new-ticket]").forEach(bindNewTicket); });

  // Board on phones: status tabs jump to a column and follow the swipe.
  function bindBoardTabs(nav) {
    var board = nav.parentElement.querySelector("[data-board]");
    if (!board) return;
    var tabs = $$("[data-board-tab]", nav);
    function setActive(key) {
      tabs.forEach(function (t) {
        var on = t.getAttribute("data-board-tab") === key;
        t.setAttribute("aria-current", String(on));
        if (on) nav.scrollTo({ left: t.offsetLeft - 16, behavior: "smooth" });
      });
    }
    tabs.forEach(function (t) {
      t.addEventListener("click", function () {
        var col = board.querySelector('[data-board-col="' + t.getAttribute("data-board-tab") + '"]');
        // Phones show the columns one under another: scroll the page to the section.
        if (col) col.scrollIntoView({ behavior: "smooth", block: "start" });
        lockUntil = Date.now() + 900;
        setActive(t.getAttribute("data-board-tab"));
      });
    });
    // Highlight the status whose section is under the sticky tabs while scrolling.
    var timer, lockUntil = 0;
    document.addEventListener("scroll", function (e) {
      if (nav.offsetParent === null || Date.now() < lockUntil) return;
      clearTimeout(timer);
      timer = setTimeout(function () {
        var line = nav.getBoundingClientRect().bottom + 12, cur = null;
        var sc = e.target === document ? document.scrollingElement : e.target;
        // At the very bottom the last sections can't reach the top: take the last one in view.
        if (sc && sc.scrollTop + sc.clientHeight >= sc.scrollHeight - 4) line = window.innerHeight - 80;
        $$("[data-board-col]", board).forEach(function (c) { if (c.getBoundingClientRect().top <= line) cur = c; });
        if (!cur) cur = board.querySelector("[data-board-col]");
        if (cur) setActive(cur.getAttribute("data-board-col"));
      }, 60);
    }, { passive: true, capture: true });
  }
  document.addEventListener("DOMContentLoaded", function () { $$("[data-board-tabs]").forEach(bindBoardTabs); });

  // Simple tabs (ticket Conversation / History).
  function showTab(root, key) {
    var btn = root.querySelector('[data-tab="' + key + '"]');
    if (!btn) return false;
    $$("[data-tab]", root).forEach(function (b) { b.setAttribute("aria-selected", b === btn ? "true" : "false"); });
    $$("[data-tab-panel]", root).forEach(function (p) { p.hidden = p.getAttribute("data-tab-panel") !== key; });
    return true;
  }
  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-tabs] [data-tab]");
    if (!b) return;
    var key = b.getAttribute("data-tab");
    showTab(b.closest("[data-tabs]"), key);
    try { history.replaceState(null, "", key === "conversation" ? location.pathname + location.search : "#" + key); } catch (_) {}
  });
  function tabFromHash() {
    $$("[data-tabs]").forEach(function (root) {
      // #history opens History; any other anchor (#reply, a comment) needs the conversation.
      var key = location.hash === "#history" ? "history" : "conversation";
      if (location.hash === "#history" || location.hash.length > 1) showTab(root, key);
    });
  }
  window.addEventListener("hashchange", tabFromHash);
  document.addEventListener("DOMContentLoaded", tabFromHash);

  // Range inputs with a live <output>.
  document.addEventListener("input", function (e) {
    if (!e.target.matches || !e.target.matches("input[data-range]")) return;
    var out = e.target.form && e.target.form.querySelector('output[for="' + e.target.id + '"]');
    if (out) out.textContent = e.target.value;
  });

  document.addEventListener("DOMContentLoaded", function () { $$("[data-roles]").forEach(bindRoles); });

  /* ---------- forms that ask for a value first (Link ticket / Merge into…) ---------- */

  document.addEventListener("submit", function (e) {
    var form = e.target;
    var question = form.getAttribute && form.getAttribute("data-ask");
    if (!question || form.dataset.asked === "1") { if (form.dataset) delete form.dataset.asked; return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    ask(question, { placeholder: form.getAttribute("data-ask-placeholder") || "#", type: form.getAttribute("data-ask-type") || "", ok: form.getAttribute("data-ask-ok") || "OK" }).then(function (v) {
      if (!v) return;
      form.querySelector('[name="' + form.getAttribute("data-ask-field") + '"]').value = v;
      form.dataset.asked = "1";
      form.requestSubmit();
    });
  }, true);

  /* ---------- collision detection on the ticket page ---------- */

  function bindPresence(box) {
    var ticketId = box.getAttribute("data-ticket");
    var since = box.getAttribute("data-since");
    var typingUntil = 0;
    var timer = null;
    var dismissedReply = false;
    function state() { return Date.now() < typingUntil ? "typing" : "viewing"; }
    function send(s) {
      return fetch("/api/presence", {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": APP.csrf },
        body: JSON.stringify({ ticketId: ticketId, state: s, since: since }),
        keepalive: s === "left",
      }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
    }
    function render(d) {
      if (!d) return;
      var parts = [];
      var typing = (d.others || []).filter(function (o) { return o.state === "typing"; });
      (d.others || []).forEach(function (o) {
        parts.push('<span class="inline-flex items-center gap-1.5"><span class="relative inline-flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-semibold text-white" style="background:' + esc(o.color) + '" title="' + esc(o.name + " · " + o.role) + '">' + esc(o.initials) +
          '<span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-surface ' + (o.state === "typing" ? "bg-amber-500" : "bg-emerald-500") + '"></span></span>' +
          '<span><strong class="font-medium">' + esc(o.name.split(" ")[0]) + "</strong> " + (o.state === "typing" ? "is replying…" : "is viewing") + "</span></span>");
      });
      if (d.newReply && !dismissedReply) {
        parts.push('<span class="inline-flex items-center gap-2"><span><strong class="font-medium">' + esc(d.newReply.name) + "</strong> added " + (d.newReply.internal ? "an internal note" : "a reply") +
          ' since you opened this ticket.</span><button type="button" data-presence-reload class="rounded-md bg-brand px-2 py-1 text-xs font-medium text-brand-fg cursor-pointer">Show</button></span>');
      }
      box.hidden = parts.length === 0;
      box.className = "mb-2 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border px-3 py-2 text-sm " +
        (typing.length || d.newReply ? "border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200" : "border-border bg-surface text-muted");
      box.innerHTML = parts.join("");
    }
    function tick() {
      if (document.visibilityState === "hidden") return;
      send(state()).then(render);
    }
    var input = document.querySelector("form[data-reply] [data-rte-input]");
    if (input) input.addEventListener("input", function () {
      var wasTyping = state() === "typing";
      typingUntil = Date.now() + 6000;
      if (!wasTyping) tick(); // tell others immediately
    });
    box.addEventListener("click", function (e) {
      if (!e.target.closest("[data-presence-reload]")) return;
      var dirty = $$("[data-rte-value]").some(function (i) { return i.value; });
      if (dirty && !window.confirm("Reload to see the new reply? Your unsent text will be kept only if you copy it first.")) { dismissedReply = true; box.hidden = true; return; }
      refresh();
    });
    tick();
    timer = setInterval(tick, 8000);
    document.addEventListener("visibilitychange", function () { if (document.visibilityState === "visible") tick(); });
    window.addEventListener("pagehide", function () { clearInterval(timer); send("left"); });
  }

  /* ---------- knowledge-base suggestions on the new-ticket form ---------- */

  function bindKbSuggest(input) {
    var box = $("[data-kb-suggest]");
    var list = box && box.querySelector("[data-kb-list]");
    if (!list) return;
    var t = null, last = "";
    input.addEventListener("input", function () {
      clearTimeout(t);
      t = setTimeout(function () {
        var q = input.value.trim();
        if (q === last) return;
        last = q;
        if (q.length < 3) { box.hidden = true; return; }
        fetch("/api/kb/suggest?q=" + encodeURIComponent(q), { credentials: "same-origin", headers: { Accept: "application/json" } })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (input.value.trim() !== q) return;
            var items = (d && d.items) || [];
            box.hidden = items.length === 0;
            list.innerHTML = items.map(function (a) {
              return '<li><a href="/kb/' + esc(a.id) + '" target="_blank" rel="noopener" class="block rounded-lg px-2 py-1.5 hover:bg-surface">' +
                '<span class="block text-sm font-medium text-fg">' + highlight(a.title, q) + "</span>" +
                '<span class="line-clamp-1 block text-xs text-muted">' + esc(a.excerpt) + "</span></a></li>";
            }).join("");
          }).catch(function () {});
      }, 300);
    });
  }

  /* ---------- Settings: live preview of the mobile bottom menu ---------- */

  function bindNavEditor(root) {
    var icons = JSON.parse(root.getAttribute("data-icons") || "{}");
    var labels = JSON.parse(root.getAttribute("data-labels") || "{}");
    var plus = root.getAttribute("data-plus"), more = root.getAttribute("data-more");
    function cell(icon, label, first) {
      return '<span class="flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 text-[10px] font-medium ' + (first ? "text-brand" : "text-muted") + '">' + icon + '<span class="max-w-full truncate">' + esc(label) + "</span></span>";
    }
    $$("[data-nav-menu]", root).forEach(function (fs) {
      var preview = fs.querySelector("[data-nav-preview]");
      var selects = $$("[data-nav-slot]", fs);
      var center = fs.querySelector("[data-nav-center]");
      function render() {
        var seen = {};
        var keys = selects.map(function (s) { return s.value; }).filter(function (k) { if (!k || seen[k]) return false; seen[k] = 1; return true; });
        // Mark options already used in another slot.
        selects.forEach(function (s) {
          $$("option", s).forEach(function (o) { o.disabled = !!o.value && o.value !== s.value && keys.indexOf(o.value) !== -1; });
        });
        var cells = keys.map(function (k, i) { return cell(icons[k] || "", labels[k] || k, i === 0); });
        cells.push(cell(more, "More", false));
        if (center.checked) {
          cells.splice(Math.floor(cells.length / 2), 0,
            '<span class="flex min-w-0 flex-1 flex-col items-center justify-center"><span class="-mt-5 grid h-11 w-11 place-items-center rounded-2xl bg-brand text-brand-fg shadow-lg shadow-brand/30">' + plus + '</span><span class="mt-0.5 text-[10px] font-medium text-muted">New</span></span>');
        }
        preview.innerHTML = keys.length ? cells.join("") : '<span class="m-auto text-xs text-red-600">Pick at least one item</span>';
      }
      selects.forEach(function (s) { s.addEventListener("change", render); });
      center.addEventListener("change", render);
      render();
    });
    var reset = root.querySelector("[data-nav-reset]");
    if (reset) reset.addEventListener("click", function (e) {
      if (!window.confirm("Reset both menus to the default layout?")) e.preventDefault();
    });
  }

  /* ---------- boot ---------- */

  document.addEventListener("DOMContentLoaded", function () {
    $$("[data-file-input]").forEach(bindFileInput);
    $$("[data-people-picker]").forEach(bindPeoplePicker);
    $$("[data-team-picker]").forEach(bindTeamPicker);
    $$("[data-watch-picker]").forEach(function (box) {
      box.addEventListener("people:pick", function (e) {
        var fd = new FormData();
        fd.append("_csrf", APP.csrf || "");
        fd.append("ticketId", box.getAttribute("data-watch-picker"));
        fd.append("userId", e.detail.id);
        fd.append("role", "VIEWER");
        fetch("/action/watcher-add", { method: "POST", body: fd, credentials: "same-origin", headers: { "Accept": "application/json", "X-Requested-With": "fetch" } })
          .then(function (r) { return r.json().catch(function () { return {}; }); })
          .then(function (j) { if (j && j.error) notice(j.error); else location.reload(); });
      });
    });
    $$("form[data-reply]").forEach(bindReply);
    $$("[data-bulk]").forEach(bindBulk);
    $$("[data-board]").forEach(bindBoard);
    $$("form[data-dirty]").forEach(bindDirty);
    $$("form[data-rule-form]").forEach(bindRuleForm);
    $$("form[data-email-form]").forEach(bindEmailForm);
    $$("[data-quiet]").forEach(bindQuiet);
    $$("[data-push-root]").forEach(bindPush);
    $$("input[data-live-search]").forEach(bindLiveSearch);
    $$("[data-settings]").forEach(bindSettings);
    $$("[data-presence]").forEach(bindPresence);
    $$("[data-nav-editor]").forEach(bindNavEditor);
    $$("input[data-kb-source]").forEach(bindKbSuggest);
    registerSW();
    offlineBanner();
    updateBadge();
    bindPullToRefresh();
    if (isStandalone) document.documentElement.classList.add("standalone");
    if (isIOS && !isStandalone) $$("[data-install-app]").forEach(function (b) { b.hidden = false; });
    if (isIOS && !isStandalone) $$("[data-push-ios]").forEach(function (p) { p.classList.remove("hidden"); });
    startRealtime();
  });
})();
