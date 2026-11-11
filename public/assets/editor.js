/*
 * TicketingOS — lightweight rich text editor (contenteditable, no dependencies).
 * Features: headings, bold/italic/underline/strike, inline code, text colour,
 * highlight, bullet/numbered/task lists, quote, code block, divider, alignment,
 * links, image upload, tables (+ row/column), canned responses, @mentions,
 * undo/redo. The HTML is posted in a hidden input and sanitized on the server.
 */
(function () {
  "use strict";

  var APP = window.APP || {};
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  function Editor(root) {
    this.root = root;
    this.input = root.querySelector("[data-rte-input]");
    this.value = root.querySelector("[data-rte-value]");
    this.toolbar = root.querySelector("[data-rte-toolbar]");
    this.fileInput = root.querySelector("[data-rte-file]");
    this.uploading = root.querySelector("[data-rte-uploading]");
    this.tableTools = root.querySelector("[data-table-tools]");
    this.mentions = JSON.parse(root.getAttribute("data-mentions") || "[]");
    this.canned = JSON.parse(root.getAttribute("data-canned-list") || "[]");
    this.savedRange = null;
    this.popup = null;
    this.bind();
    this.sync();
  }

  Editor.prototype.exec = function (cmd, value) {
    this.restore();
    try { document.execCommand("styleWithCSS", false, cmd === "foreColor" || cmd === "hiliteColor"); } catch (e) {}
    document.execCommand(cmd, false, value);
    this.sync();
    this.updateToolbar();
  };

  Editor.prototype.save = function () {
    var sel = window.getSelection();
    if (sel.rangeCount && this.input.contains(sel.anchorNode)) this.savedRange = sel.getRangeAt(0).cloneRange();
  };

  Editor.prototype.restore = function () {
    this.input.focus();
    if (this.savedRange) {
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(this.savedRange);
    }
  };

  Editor.prototype.isEmpty = function () {
    var text = (this.input.textContent || "").replace(/​/g, "").trim();
    return !text && !this.input.querySelector("img, table, hr, [data-type=mention]");
  };

  Editor.prototype.sync = function () {
    // Normalise task-list items.
    $$('ul[data-type="taskList"] > li', this.input).forEach(function (li) {
      li.setAttribute("data-type", "taskItem");
      if (!li.hasAttribute("data-checked")) li.setAttribute("data-checked", "false");
    });
    var empty = this.isEmpty();
    this.input.classList.toggle("is-empty", empty);
    this.value.value = empty ? "" : this.input.innerHTML;
  };

  /** Closest element (inside the editor) matching a selector, from the caret. */
  Editor.prototype.closest = function (selector) {
    var sel = window.getSelection();
    if (!sel.rangeCount) return null;
    var node = sel.anchorNode;
    if (!node || !this.input.contains(node)) return null;
    var el = node.nodeType === 1 ? node : node.parentElement;
    var found = el && el.closest(selector);
    return found && this.input.contains(found) ? found : null;
  };

  Editor.prototype.currentBlock = function () {
    var b = this.closest("h1, h2, h3, p, pre, blockquote, li, div");
    return b ? b.tagName.toLowerCase() : "p";
  };

  Editor.prototype.updateToolbar = function () {
    var self = this;
    var inEditor = this.closest("*") !== null || document.activeElement === this.input;
    var states = {
      bold: "bold", italic: "italic", underline: "underline", strikeThrough: "strikeThrough",
      insertUnorderedList: "insertUnorderedList", insertOrderedList: "insertOrderedList",
      justifyLeft: "justifyLeft", justifyCenter: "justifyCenter", justifyRight: "justifyRight",
    };
    $$("[data-cmd]", this.toolbar).forEach(function (b) {
      var cmd = b.getAttribute("data-cmd");
      var active = false;
      if (inEditor) {
        if (states[cmd]) { try { active = document.queryCommandState(states[cmd]); } catch (e) {} }
        if (cmd === "insertUnorderedList" && self.closest('ul[data-type="taskList"]')) active = false;
        if (cmd === "taskList") active = !!self.closest('ul[data-type="taskList"]');
        if (cmd === "blockquote") active = !!self.closest("blockquote");
        if (cmd === "pre") active = !!self.closest("pre");
        if (cmd === "code") active = !!self.closest("code") && !self.closest("pre");
        if (cmd === "link") active = !!self.closest("a");
      }
      b.dataset.active = active ? "true" : "false";
    });
    var block = this.toolbar.querySelector("[data-block]");
    var cur = this.currentBlock();
    block.value = /^h[1-3]$/.test(cur) ? cur : "p";
    if (this.tableTools) this.tableTools.hidden = !this.closest("table");
  };

  Editor.prototype.toggleInlineCode = function () {
    this.restore();
    var code = this.closest("code");
    if (code && !this.closest("pre")) {
      var parent = code.parentNode;
      while (code.firstChild) parent.insertBefore(code.firstChild, code);
      parent.removeChild(code);
    } else {
      var sel = window.getSelection();
      if (!sel.rangeCount) return;
      var range = sel.getRangeAt(0);
      var text = range.toString() || "code";
      var el = document.createElement("code");
      el.textContent = text;
      range.deleteContents();
      range.insertNode(el);
      range.setStartAfter(el);
      range.collapse(true);
      sel.removeAllRanges();
      sel.addRange(range);
    }
    this.sync();
  };

  Editor.prototype.toggleBlock = function (tag) {
    this.restore();
    var cur = this.closest(tag);
    document.execCommand("formatBlock", false, cur ? "<p>" : "<" + tag + ">");
    this.sync();
    this.updateToolbar();
  };

  Editor.prototype.toggleTaskList = function () {
    this.restore();
    var ul = this.closest('ul[data-type="taskList"]');
    if (ul) {
      ul.removeAttribute("data-type");
      $$("li", ul).forEach(function (li) { li.removeAttribute("data-type"); li.removeAttribute("data-checked"); });
      document.execCommand("insertUnorderedList");
    } else {
      if (!this.closest("ul")) document.execCommand("insertUnorderedList");
      var list = this.closest("ul");
      if (list) {
        list.setAttribute("data-type", "taskList");
        $$("li", list).forEach(function (li) { li.setAttribute("data-type", "taskItem"); li.setAttribute("data-checked", "false"); });
      }
    }
    this.sync();
    this.updateToolbar();
  };

  Editor.prototype.insertHTML = function (html) {
    this.restore();
    document.execCommand("insertHTML", false, html);
    this.sync();
  };

  Editor.prototype.insertTable = function () {
    var head = "", row = "";
    for (var c = 0; c < 3; c++) { head += "<th><p><br></p></th>"; row += "<td><p><br></p></td>"; }
    this.insertHTML("<table><tbody><tr>" + head + "</tr><tr>" + row + "</tr><tr>" + row + "</tr></tbody></table><p><br></p>");
  };

  Editor.prototype.tableOp = function (op) {
    this.restore();
    var table = this.closest("table");
    var cell = this.closest("td, th");
    if (!table) return;
    if (op === "deleteTable") {
      table.remove();
    } else if (op === "addRow") {
      var tr = cell ? cell.parentElement : table.rows[table.rows.length - 1];
      var nr = document.createElement("tr");
      for (var i = 0; i < tr.cells.length; i++) {
        var td = document.createElement("td");
        td.innerHTML = "<p><br></p>";
        nr.appendChild(td);
      }
      tr.parentNode.insertBefore(nr, tr.nextSibling);
    } else if (op === "addColumn") {
      var idx = cell ? cell.cellIndex : table.rows[0].cells.length - 1;
      $$("tr", table).forEach(function (r) {
        var isHead = r.cells[0] && r.cells[0].tagName === "TH";
        var n = document.createElement(isHead ? "th" : "td");
        n.innerHTML = "<p><br></p>";
        var ref = r.cells[idx];
        if (ref && ref.nextSibling) r.insertBefore(n, ref.nextSibling); else r.appendChild(n);
      });
    }
    this.sync();
    this.updateToolbar();
  };

  Editor.prototype.link = function () {
    var a = this.closest("a");
    if (a) { this.exec("unlink"); return; }
    this.save();
    var self = this;
    var ask = window.TOS && window.TOS.ask ? window.TOS.ask : function (t) { return Promise.resolve(window.prompt(t)); };
    ask("Link URL", { placeholder: "https://…", type: "url", ok: "Add link" }).then(function (url) {
      if (!url) return;
      if (!/^(https?:|mailto:|\/)/i.test(url)) url = "https://" + url;
      self.restore();
      var sel = window.getSelection();
      if (sel.isCollapsed) {
        var safe = url.replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;");
        document.execCommand("insertHTML", false, '<a href="' + safe + '">' + safe + "</a>&nbsp;");
      } else {
        document.execCommand("createLink", false, url);
      }
      self.sync();
    });
  };

  Editor.prototype.uploadImage = function (file) {
    var self = this;
    var fd = new FormData();
    fd.append("file", file);
    fd.append("_csrf", APP.csrf || "");
    this.uploading.hidden = false;
    fetch("/api/editor/images", { method: "POST", body: fd, credentials: "same-origin", headers: { "X-CSRF-Token": APP.csrf || "" } })
      .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, j: j }; }); })
      .then(function (r) {
        if (r.ok && r.j.url) {
          var alt = file.name.replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;");
          self.insertHTML('<img src="' + r.j.url + '" alt="' + alt + '">');
        } else {
          window.alert((r.j && r.j.error) || "Upload failed");
        }
      })
      .catch(function () { window.alert("Upload failed"); })
      .then(function () { self.uploading.hidden = true; });
  };

  /* ----- @mentions ----- */

  Editor.prototype.mentionQuery = function () {
    var sel = window.getSelection();
    if (!sel.rangeCount || !sel.isCollapsed) return null;
    var node = sel.anchorNode;
    if (!node || node.nodeType !== 3 || !this.input.contains(node)) return null;
    var before = node.textContent.slice(0, sel.anchorOffset);
    var m = /(?:^|[\s ])@([^\s@]{0,30})$/.exec(before);
    if (!m) return null;
    return { node: node, start: sel.anchorOffset - m[1].length - 1, end: sel.anchorOffset, query: m[1] };
  };

  Editor.prototype.closePopup = function () {
    if (this.popup) { this.popup.remove(); this.popup = null; }
    this.mentionState = null;
  };

  Editor.prototype.renderPopup = function () {
    var self = this;
    var st = this.mentionState;
    if (!st || st.items.length === 0) { this.closePopup(); return; }
    if (!this.popup) {
      this.popup = document.createElement("div");
      this.popup.className = "mention-popup";
      document.body.appendChild(this.popup);
    }
    this.popup.innerHTML = "";
    st.items.forEach(function (it, i) {
      var b = document.createElement("button");
      b.type = "button";
      b.textContent = "@" + it.label;
      b.className = "mention-item" + (i === st.selected ? " is-selected" : "");
      b.addEventListener("mousedown", function (e) { e.preventDefault(); self.pickMention(it); });
      self.popup.appendChild(b);
    });
    var sel = window.getSelection();
    if (sel.rangeCount) {
      var rect = sel.getRangeAt(0).getBoundingClientRect();
      if (!rect || (!rect.left && !rect.top)) rect = this.input.getBoundingClientRect();
      this.popup.style.left = rect.left + "px";
      this.popup.style.top = rect.bottom + 6 + "px";
    }
  };

  Editor.prototype.checkMention = function () {
    if (!this.mentions.length) return;
    var q = this.mentionQuery();
    if (!q) { this.closePopup(); return; }
    var query = q.query.toLowerCase();
    var items = this.mentions.filter(function (u) { return u.label.toLowerCase().indexOf(query) !== -1; }).slice(0, 6);
    var prevSel = this.mentionState ? this.mentionState.selected : 0;
    this.mentionState = { q: q, items: items, selected: Math.min(prevSel, Math.max(0, items.length - 1)) };
    this.renderPopup();
  };

  Editor.prototype.pickMention = function (user) {
    var st = this.mentionState;
    if (!st) return;
    var q = this.mentionQuery() || st.q;
    // Direct DOM insertion keeps the chip inside the current paragraph
    // (execCommand("insertHTML") tends to hoist non-editable inline nodes out).
    var range = document.createRange();
    range.setStart(q.node, q.start);
    range.setEnd(q.node, q.end);
    range.deleteContents();
    var chip = document.createElement("span");
    chip.setAttribute("data-type", "mention");
    chip.className = "mention";
    chip.setAttribute("data-id", user.id);
    chip.setAttribute("data-label", user.label);
    chip.setAttribute("contenteditable", "false");
    chip.textContent = "@" + user.label;
    var space = document.createTextNode(" ");
    range.insertNode(space);
    range.insertNode(chip);
    var sel = window.getSelection();
    var caret = document.createRange();
    caret.setStart(space, 1);
    caret.collapse(true);
    sel.removeAllRanges();
    sel.addRange(caret);
    this.closePopup();
    this.sync();
  };

  /* ----- events ----- */

  Editor.prototype.bind = function () {
    var self = this;
    var input = this.input;

    try { document.execCommand("defaultParagraphSeparator", false, "p"); } catch (e) {}

    input.addEventListener("input", function () { self.sync(); self.checkMention(); });
    input.addEventListener("keyup", function (e) {
      if (["ArrowLeft", "ArrowRight", "Home", "End"].indexOf(e.key) !== -1) self.checkMention();
      self.updateToolbar();
    });
    input.addEventListener("mouseup", function () { self.updateToolbar(); });
    input.addEventListener("blur", function () { setTimeout(function () { self.closePopup(); }, 150); });
    input.addEventListener("focus", function () {
      if (!input.innerHTML.trim()) input.innerHTML = "<p><br></p>";
    });

    input.addEventListener("keydown", function (e) {
      var st = self.mentionState;
      if (st && st.items.length) {
        var n = st.items.length;
        if (e.key === "ArrowDown") { e.preventDefault(); st.selected = (st.selected + 1) % n; self.renderPopup(); return; }
        if (e.key === "ArrowUp") { e.preventDefault(); st.selected = (st.selected - 1 + n) % n; self.renderPopup(); return; }
        if (e.key === "Enter" || e.key === "Tab") { e.preventDefault(); self.pickMention(st.items[st.selected]); return; }
        if (e.key === "Escape") { e.preventDefault(); self.closePopup(); return; }
      }
      var mod = e.ctrlKey || e.metaKey;
      if (mod && !e.shiftKey && e.key.toLowerCase() === "k") { e.preventDefault(); self.link(); return; }
      if (e.key === "Enter" && !e.shiftKey) {
        // New task items start unchecked (browsers copy attributes when splitting).
        if (self.closest('ul[data-type="taskList"]')) {
          setTimeout(function () {
            var li = self.closest("li");
            if (li) li.setAttribute("data-checked", "false");
            self.sync();
          }, 0);
        }
      }
    });

    // Toggle task items by clicking their checkbox area.
    input.addEventListener("mousedown", function (e) {
      var li = e.target.closest && e.target.closest('ul[data-type="taskList"] > li');
      if (!li || !input.contains(li)) return;
      var rect = li.getBoundingClientRect();
      if (e.clientX - rect.left < 22) {
        e.preventDefault();
        li.setAttribute("data-checked", li.getAttribute("data-checked") === "true" ? "false" : "true");
        self.sync();
      }
    });

    // Paste images directly as uploads.
    input.addEventListener("paste", function (e) {
      var items = (e.clipboardData && e.clipboardData.files) || [];
      if (items.length && /^image\//.test(items[0].type)) {
        e.preventDefault();
        self.save();
        self.uploadImage(items[0]);
      }
    });

    document.addEventListener("selectionchange", function () {
      var sel = window.getSelection();
      if (sel.rangeCount && input.contains(sel.anchorNode)) {
        self.save();
        self.updateToolbar();
      }
    });

    // Keep focus in the editor when clicking toolbar buttons.
    this.toolbar.addEventListener("mousedown", function (e) {
      if (e.target.closest("[data-cmd]")) e.preventDefault();
    });

    this.toolbar.addEventListener("click", function (e) {
      var b = e.target.closest("[data-cmd]");
      if (b) {
        var cmd = b.getAttribute("data-cmd");
        switch (cmd) {
          case "code": self.toggleInlineCode(); break;
          case "taskList": self.toggleTaskList(); break;
          case "blockquote": self.toggleBlock("blockquote"); break;
          case "pre": self.toggleBlock("pre"); break;
          case "link": self.link(); break;
          case "image": self.save(); self.fileInput.click(); break;
          case "table": self.insertTable(); break;
          case "addRow": case "addColumn": case "deleteTable": self.tableOp(cmd); break;
          case "insertUnorderedList":
            if (self.closest('ul[data-type="taskList"]')) { self.toggleTaskList(); break; }
            self.exec(cmd); break;
          default: self.exec(cmd);
        }
        return;
      }
      var canned = e.target.closest("[data-canned]");
      if (canned) {
        var body = self.canned[parseInt(canned.getAttribute("data-canned"), 10)];
        if (body) self.insertHTML(body);
      }
    });

    var block = this.toolbar.querySelector("[data-block]");
    block.addEventListener("mousedown", function () { self.save(); });
    block.addEventListener("change", function () {
      self.restore();
      document.execCommand("formatBlock", false, "<" + block.value + ">");
      self.sync();
      self.updateToolbar();
    });

    $$("[data-color]", this.toolbar).forEach(function (picker) {
      var swatch = picker.parentElement.querySelector("[data-swatch]");
      picker.addEventListener("mousedown", function () { self.save(); });
      picker.addEventListener("focus", function () { self.save(); });
      picker.addEventListener("input", function () {
        self.exec(picker.getAttribute("data-color"), picker.value);
        swatch.style.backgroundColor = picker.value;
        swatch.style.outline = "none";
      });
      picker.addEventListener("dblclick", function (e) {
        e.preventDefault();
        self.exec("removeFormat");
        swatch.style.backgroundColor = "transparent";
        swatch.style.outline = "1px solid var(--border)";
      });
    });

    this.fileInput.addEventListener("change", function () {
      var f = self.fileInput.files && self.fileInput.files[0];
      if (f) self.uploadImage(f);
      self.fileInput.value = "";
    });

    var form = this.root.closest("form");
    if (form) form.addEventListener("submit", function () { self.sync(); }, true);
  };

  document.addEventListener("DOMContentLoaded", function () {
    $$("[data-rte]").forEach(function (el) { el._editor = new Editor(el); });
  });
})();
