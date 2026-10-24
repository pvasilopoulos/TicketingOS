import { FileText, Download, ImageIcon } from "lucide-react";
import { formatBytes } from "@/lib/utils";

export type AttachmentView = {
  id: string;
  filename: string;
  size: number;
  mime: string;
  url: string;
};

export function AttachmentList({ items }: { items: AttachmentView[] }) {
  if (!items.length) return null;
  return (
    <div className="mt-3 flex flex-wrap gap-2">
      {items.map((a) => {
        const isImage = a.mime.startsWith("image/");
        return (
          <a
            key={a.id}
            href={a.url}
            target="_blank"
            rel="noopener noreferrer"
            className="group inline-flex items-center gap-2 rounded-lg border border-border bg-surface-2 px-2.5 py-1.5 text-sm hover:border-brand/50 hover:bg-surface transition-colors"
          >
            {isImage ? (
              <ImageIcon size={15} className="text-muted" />
            ) : (
              <FileText size={15} className="text-muted" />
            )}
            <span className="max-w-50 truncate">{a.filename}</span>
            <span className="text-xs text-muted">{formatBytes(a.size)}</span>
            <Download size={14} className="text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
          </a>
        );
      })}
    </div>
  );
}
