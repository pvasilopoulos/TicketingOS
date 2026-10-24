import "server-only";
import sanitizeHtml from "sanitize-html";

const COLOR = [/^#(0x)?[0-9a-fA-F]{3,8}$/, /^rgba?\(/];
const SIZE = [/^\d+(\.\d+)?(px|%)$/];

// Allowlist for user-authored rich text coming from the Tiptap editor.
const OPTIONS: sanitizeHtml.IOptions = {
  allowedTags: [
    // text
    "p", "br", "strong", "b", "em", "i", "u", "s", "strike", "mark", "span",
    "h1", "h2", "h3", "a", "hr",
    // blocks
    "ul", "ol", "li", "blockquote", "code", "pre", "div",
    // media
    "img",
    // tables
    "table", "thead", "tbody", "tr", "th", "td", "colgroup", "col",
  ],
  allowedAttributes: {
    a: ["href", "target", "rel"],
    p: ["style"],
    h1: ["style"],
    h2: ["style"],
    h3: ["style"],
    span: ["style", "data-type", "data-id", "data-label", "class"],
    mark: ["style", "data-color"],
    img: ["src", "alt"],
    table: ["style"],
    col: ["style", "span"],
    th: ["colspan", "rowspan", "colwidth", "style"],
    td: ["colspan", "rowspan", "colwidth", "style"],
    ul: ["data-type"],
    li: ["data-checked", "data-type", "style"],
  },
  allowedStyles: {
    "*": {
      "text-align": [/^(left|right|center|justify)$/],
      color: COLOR,
      "background-color": COLOR,
      width: SIZE,
      "min-width": SIZE,
    },
  },
  // Links may use http(s)/mailto; <img> may additionally reference our own
  // same-origin attachment endpoint (a relative path), never data:/javascript:.
  allowedSchemes: ["http", "https", "mailto"],
  allowedSchemesByTag: { img: ["http", "https"] },
  transformTags: {
    a: sanitizeHtml.simpleTransform("a", {
      target: "_blank",
      rel: "noopener noreferrer nofollow",
    }),
  },
};

/** Clean untrusted HTML down to a safe, allowlisted subset. */
export function sanitizeRichHtml(html: string): string {
  return sanitizeHtml(html ?? "", OPTIONS).trim();
}

/** Plain-text projection (for length validation / previews). */
export function htmlToText(html: string): string {
  return sanitizeHtml(html ?? "", { allowedTags: [], allowedAttributes: {} })
    .replace(/\s+/g, " ")
    .trim();
}
