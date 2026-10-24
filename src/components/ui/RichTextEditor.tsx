"use client";

import { useEditor, EditorContent, type Editor } from "@tiptap/react";
import StarterKit from "@tiptap/starter-kit";
import Placeholder from "@tiptap/extension-placeholder";
import TextAlign from "@tiptap/extension-text-align";
import Highlight from "@tiptap/extension-highlight";
import { TextStyle, Color } from "@tiptap/extension-text-style";
import { TableKit } from "@tiptap/extension-table";
import { TaskList } from "@tiptap/extension-task-list";
import { TaskItem } from "@tiptap/extension-task-item";
import { Image as ImageExt } from "@tiptap/extension-image";
import Mention from "@tiptap/extension-mention";
import { useEffect, useRef, useState } from "react";
import {
  Bold,
  Italic,
  Underline as UnderlineIcon,
  Strikethrough,
  Baseline,
  Highlighter,
  Code,
  Code2,
  List,
  ListOrdered,
  ListTodo,
  Quote,
  Minus,
  Link as LinkIcon,
  ImagePlus,
  Table as TableIcon,
  Rows3,
  Columns3,
  Trash2,
  AlignLeft,
  AlignCenter,
  AlignRight,
  Undo2,
  Redo2,
  MessageSquarePlus,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { Dropdown } from "@/components/ui/Dropdown";

function TB({
  active,
  disabled,
  onClick,
  label,
  children,
}: {
  active?: boolean;
  disabled?: boolean;
  onClick: () => void;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      title={label}
      aria-label={label}
      disabled={disabled}
      onClick={onClick}
      className={cn(
        "inline-flex h-8 w-8 items-center justify-center rounded-md transition-colors cursor-pointer disabled:opacity-40 disabled:cursor-default",
        active ? "bg-brand/15 text-brand" : "text-muted hover:bg-surface-2 hover:text-fg",
      )}
    >
      {children}
    </button>
  );
}

// A toolbar button that also hosts a hidden native color input.
function ColorButton({
  label,
  icon,
  value,
  onPick,
  onClear,
}: {
  label: string;
  icon: React.ReactNode;
  value: string;
  onPick: (hex: string) => void;
  onClear: () => void;
}) {
  return (
    <span className="relative inline-flex">
      <span
        className="inline-flex h-8 items-center justify-center rounded-md px-1 text-muted hover:bg-surface-2 hover:text-fg transition-colors"
        title={label}
      >
        {icon}
        <span
          className="ml-0.5 h-1 w-4 rounded-full"
          style={{ backgroundColor: value || "transparent", outline: value ? "none" : "1px solid var(--border)" }}
        />
      </span>
      <input
        type="color"
        aria-label={label}
        value={value || "#000000"}
        onChange={(e) => onPick(e.target.value)}
        onDoubleClick={onClear}
        className="absolute inset-0 cursor-pointer opacity-0"
      />
    </span>
  );
}

function Divider() {
  return <span className="mx-1 h-5 w-px bg-border" />;
}

function Toolbar({
  editor,
  onImage,
  canned,
}: {
  editor: Editor;
  onImage: () => void;
  canned?: { title: string; body: string }[];
}) {
  const [, force] = useState(0);
  useEffect(() => {
    const handler = () => force((n) => n + 1);
    editor.on("transaction", handler);
    return () => {
      editor.off("transaction", handler);
    };
  }, [editor]);

  const blockValue = editor.isActive("heading", { level: 1 })
    ? "h1"
    : editor.isActive("heading", { level: 2 })
      ? "h2"
      : editor.isActive("heading", { level: 3 })
        ? "h3"
        : "p";

  function setBlock(v: string) {
    const chain = editor.chain().focus();
    if (v === "p") chain.setParagraph().run();
    else chain.toggleHeading({ level: Number(v[1]) as 1 | 2 | 3 }).run();
  }

  const inTable = editor.isActive("table");
  const currentColor = (editor.getAttributes("textStyle").color as string) || "";
  const currentHighlight = (editor.getAttributes("highlight").color as string) || "";

  return (
    <div className="flex flex-wrap items-center gap-0.5 border-b border-border px-2 py-1.5">
      <select
        value={blockValue}
        onChange={(e) => setBlock(e.target.value)}
        title="Text style"
        className="mr-1 h-8 rounded-md border border-border bg-surface px-1.5 text-xs text-fg focus:outline-none focus:ring-2 focus:ring-brand/40 cursor-pointer"
      >
        <option value="p">Paragraph</option>
        <option value="h1">Heading 1</option>
        <option value="h2">Heading 2</option>
        <option value="h3">Heading 3</option>
      </select>

      <Divider />

      <TB label="Bold" active={editor.isActive("bold")} onClick={() => editor.chain().focus().toggleBold().run()}>
        <Bold size={16} />
      </TB>
      <TB label="Italic" active={editor.isActive("italic")} onClick={() => editor.chain().focus().toggleItalic().run()}>
        <Italic size={16} />
      </TB>
      <TB label="Underline" active={editor.isActive("underline")} onClick={() => editor.chain().focus().toggleUnderline().run()}>
        <UnderlineIcon size={16} />
      </TB>
      <TB label="Strikethrough" active={editor.isActive("strike")} onClick={() => editor.chain().focus().toggleStrike().run()}>
        <Strikethrough size={16} />
      </TB>
      <TB label="Inline code" active={editor.isActive("code")} onClick={() => editor.chain().focus().toggleCode().run()}>
        <Code size={16} />
      </TB>

      <Divider />

      {/* Colors */}
      <ColorButton
        label="Text color (double-click to reset)"
        icon={<Baseline size={16} />}
        value={currentColor}
        onPick={(hex) => editor.chain().focus().setColor(hex).run()}
        onClear={() => editor.chain().focus().unsetColor().run()}
      />
      <ColorButton
        label="Highlight color (double-click to remove)"
        icon={<Highlighter size={16} />}
        value={currentHighlight}
        onPick={(hex) => editor.chain().focus().setHighlight({ color: hex }).run()}
        onClear={() => editor.chain().focus().unsetHighlight().run()}
      />

      <Divider />

      <TB label="Bullet list" active={editor.isActive("bulletList")} onClick={() => editor.chain().focus().toggleBulletList().run()}>
        <List size={16} />
      </TB>
      <TB label="Numbered list" active={editor.isActive("orderedList")} onClick={() => editor.chain().focus().toggleOrderedList().run()}>
        <ListOrdered size={16} />
      </TB>
      <TB label="Task list" active={editor.isActive("taskList")} onClick={() => editor.chain().focus().toggleTaskList().run()}>
        <ListTodo size={16} />
      </TB>
      <TB label="Quote" active={editor.isActive("blockquote")} onClick={() => editor.chain().focus().toggleBlockquote().run()}>
        <Quote size={16} />
      </TB>
      <TB label="Code block" active={editor.isActive("codeBlock")} onClick={() => editor.chain().focus().toggleCodeBlock().run()}>
        <Code2 size={16} />
      </TB>
      <TB label="Divider" onClick={() => editor.chain().focus().setHorizontalRule().run()}>
        <Minus size={16} />
      </TB>

      <Divider />

      <TB label="Align left" active={editor.isActive({ textAlign: "left" })} onClick={() => editor.chain().focus().setTextAlign("left").run()}>
        <AlignLeft size={16} />
      </TB>
      <TB label="Align center" active={editor.isActive({ textAlign: "center" })} onClick={() => editor.chain().focus().setTextAlign("center").run()}>
        <AlignCenter size={16} />
      </TB>
      <TB label="Align right" active={editor.isActive({ textAlign: "right" })} onClick={() => editor.chain().focus().setTextAlign("right").run()}>
        <AlignRight size={16} />
      </TB>

      <Divider />

      <TB
        label="Link"
        active={editor.isActive("link")}
        onClick={() => {
          if (editor.isActive("link")) {
            editor.chain().focus().unsetLink().run();
            return;
          }
          const url = window.prompt("Link URL");
          if (url) editor.chain().focus().setLink({ href: url }).run();
        }}
      >
        <LinkIcon size={16} />
      </TB>
      <TB label="Insert image" onClick={onImage}>
        <ImagePlus size={16} />
      </TB>
      <TB
        label="Insert table"
        onClick={() =>
          editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()
        }
      >
        <TableIcon size={16} />
      </TB>

      {canned && canned.length > 0 && (
        <Dropdown
          width={260}
          trigger={({ toggle }) => (
            <button
              type="button"
              title="Insert canned response"
              aria-label="Insert canned response"
              onClick={toggle}
              className="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted hover:bg-surface-2 hover:text-fg transition-colors cursor-pointer"
            >
              <MessageSquarePlus size={16} />
            </button>
          )}
        >
          {(close) => (
            <div className="max-h-72 overflow-y-auto py-1">
              {canned.map((c, i) => (
                <button
                  key={i}
                  type="button"
                  onClick={() => {
                    editor.chain().focus().insertContent(c.body).run();
                    close();
                  }}
                  className="block w-full truncate px-3 py-2 text-left text-sm hover:bg-surface-2 transition-colors cursor-pointer"
                >
                  {c.title}
                </button>
              ))}
            </div>
          )}
        </Dropdown>
      )}

      {/* Table ops — only when the selection is inside a table */}
      {inTable && (
        <>
          <Divider />
          <TB label="Add column" onClick={() => editor.chain().focus().addColumnAfter().run()}>
            <Columns3 size={16} />
          </TB>
          <TB label="Add row" onClick={() => editor.chain().focus().addRowAfter().run()}>
            <Rows3 size={16} />
          </TB>
          <TB label="Delete table" onClick={() => editor.chain().focus().deleteTable().run()}>
            <Trash2 size={16} />
          </TB>
        </>
      )}

      <Divider />

      <TB label="Undo" disabled={!editor.can().undo()} onClick={() => editor.chain().focus().undo().run()}>
        <Undo2 size={16} />
      </TB>
      <TB label="Redo" disabled={!editor.can().redo()} onClick={() => editor.chain().focus().redo().run()}>
        <Redo2 size={16} />
      </TB>
    </div>
  );
}

type MentionUser = { id: string; label: string };

// Lightweight (dependency-free) @mention suggestion popup.
function mentionSuggestion(users: MentionUser[]) {
  return {
    items: ({ query }: { query: string }) =>
      users
        .filter((u) => u.label.toLowerCase().includes(query.toLowerCase()))
        .slice(0, 6),
    render: () => {
      let el: HTMLDivElement | null = null;
      let items: MentionUser[] = [];
      let command: ((item: { id: string; label: string }) => void) | null = null;
      let selected = 0;

      const paint = () => {
        if (!el) return;
        el.innerHTML = "";
        if (items.length === 0) {
          el.style.display = "none";
          return;
        }
        el.style.display = "block";
        items.forEach((it, i) => {
          const b = document.createElement("button");
          b.type = "button";
          b.textContent = "@" + it.label;
          b.className = "mention-item" + (i === selected ? " is-selected" : "");
          b.onmousedown = (e) => {
            e.preventDefault();
            command?.({ id: it.id, label: it.label });
          };
          el!.appendChild(b);
        });
      };
      const place = (rect: DOMRect | null | undefined) => {
        if (!el || !rect) return;
        el.style.left = `${rect.left}px`;
        el.style.top = `${rect.bottom + 6}px`;
      };

      return {
        onStart: (props: {
          items: MentionUser[];
          command: (i: { id: string; label: string }) => void;
          clientRect?: (() => DOMRect | null) | null;
        }) => {
          items = props.items;
          command = props.command;
          selected = 0;
          el = document.createElement("div");
          el.className = "mention-popup";
          document.body.appendChild(el);
          paint();
          place(props.clientRect?.());
        },
        onUpdate: (props: {
          items: MentionUser[];
          command: (i: { id: string; label: string }) => void;
          clientRect?: (() => DOMRect | null) | null;
        }) => {
          items = props.items;
          command = props.command;
          selected = 0;
          paint();
          place(props.clientRect?.());
        },
        onKeyDown: (props: { event: KeyboardEvent }) => {
          const n = items.length;
          if (props.event.key === "ArrowDown") {
            selected = n ? (selected + 1) % n : 0;
            paint();
            return true;
          }
          if (props.event.key === "ArrowUp") {
            selected = n ? (selected - 1 + n) % n : 0;
            paint();
            return true;
          }
          if (props.event.key === "Enter") {
            const it = items[selected];
            if (it) command?.({ id: it.id, label: it.label });
            return true;
          }
          if (props.event.key === "Escape") return true;
          return false;
        },
        onExit: () => {
          el?.remove();
          el = null;
        },
      };
    },
  };
}

export function RichTextEditor({
  name,
  placeholder = "Write here…",
  minHeight = 140,
  defaultHTML = "",
  mentionUsers,
  canned,
}: {
  name: string;
  placeholder?: string;
  minHeight?: number;
  defaultHTML?: string;
  mentionUsers?: MentionUser[];
  canned?: { title: string; body: string }[];
}) {
  const [html, setHtml] = useState(defaultHTML);
  const [uploading, setUploading] = useState(false);
  const imageInputRef = useRef<HTMLInputElement>(null);

  const editor = useEditor({
    immediatelyRender: false,
    extensions: [
      StarterKit.configure({
        heading: { levels: [1, 2, 3] },
        link: { openOnClick: false, autolink: true },
      }),
      Placeholder.configure({ placeholder }),
      TextAlign.configure({ types: ["heading", "paragraph"] }),
      TextStyle,
      Color,
      Highlight.configure({ multicolor: true }),
      TableKit.configure({ table: { resizable: true } }),
      TaskList,
      TaskItem.configure({ nested: true }),
      ImageExt.configure({ inline: false, allowBase64: false }),
      ...(mentionUsers && mentionUsers.length
        ? [
            Mention.configure({
              HTMLAttributes: { class: "mention" },
              suggestion: mentionSuggestion(mentionUsers),
            }),
          ]
        : []),
    ],
    content: defaultHTML,
    editorProps: {
      attributes: {
        class: "rich tiptap-input focus:outline-none",
        style: `min-height:${minHeight}px`,
      },
    },
    onUpdate: ({ editor }) => {
      setHtml(editor.isEmpty ? "" : editor.getHTML());
    },
  });

  async function handleImageFile(file: File) {
    if (!editor) return;
    setUploading(true);
    try {
      const fd = new FormData();
      fd.set("file", file);
      const res = await fetch("/api/editor/images", { method: "POST", body: fd });
      if (res.ok) {
        const { url } = await res.json();
        editor.chain().focus().setImage({ src: url, alt: file.name }).run();
      } else {
        const { error } = await res.json().catch(() => ({ error: "Upload failed" }));
        window.alert(error ?? "Upload failed");
      }
    } finally {
      setUploading(false);
    }
  }

  return (
    <div className="rounded-lg border border-border bg-surface focus-within:ring-2 focus-within:ring-brand/40 focus-within:border-brand transition">
      {editor && (
        <Toolbar
          editor={editor}
          onImage={() => imageInputRef.current?.click()}
          canned={canned}
        />
      )}
      <div className="relative px-3 py-2">
        <EditorContent editor={editor} />
        {uploading && (
          <span className="absolute right-3 top-2 rounded bg-surface-2 px-2 py-0.5 text-xs text-muted">
            Uploading image…
          </span>
        )}
      </div>

      <input
        ref={imageInputRef}
        type="file"
        accept="image/png,image/jpeg,image/gif,image/webp"
        className="hidden"
        onChange={(e) => {
          const f = e.target.files?.[0];
          if (f) handleImageFile(f);
          e.target.value = "";
        }}
      />
      <input type="hidden" name={name} value={html} />
    </div>
  );
}
