"use client";

import { useRef, useState } from "react";
import { Paperclip, X, FileText } from "lucide-react";
import { formatBytes } from "@/lib/utils";

const MAX = 10 * 1024 * 1024;

export function FileInput({ name = "files" }: { name?: string }) {
  const ref = useRef<HTMLInputElement>(null);
  const [files, setFiles] = useState<File[]>([]);
  const [tooBig, setTooBig] = useState(false);

  function refresh() {
    const list = ref.current?.files ? Array.from(ref.current.files) : [];
    setFiles(list);
    setTooBig(list.some((f) => f.size > MAX));
  }

  function removeAt(i: number) {
    if (!ref.current) return;
    const dt = new DataTransfer();
    Array.from(ref.current.files ?? []).forEach((f, idx) => {
      if (idx !== i) dt.items.add(f);
    });
    ref.current.files = dt.files;
    refresh();
  }

  return (
    <div>
      <button
        type="button"
        onClick={() => ref.current?.click()}
        className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-muted hover:bg-surface-2 hover:text-fg transition-colors cursor-pointer"
      >
        <Paperclip size={15} /> Attach files
      </button>
      <input
        ref={ref}
        type="file"
        name={name}
        multiple
        onChange={refresh}
        className="hidden"
      />

      {files.length > 0 && (
        <ul className="mt-2 space-y-1.5">
          {files.map((f, i) => {
            const big = f.size > MAX;
            return (
              <li
                key={i}
                className={`flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-sm ${
                  big
                    ? "border-red-300 dark:border-red-500/40 bg-red-50 dark:bg-red-500/10"
                    : "border-border bg-surface-2"
                }`}
              >
                <FileText size={15} className="shrink-0 text-muted" />
                <span className="min-w-0 flex-1 truncate">{f.name}</span>
                <span className="shrink-0 text-xs text-muted">{formatBytes(f.size)}</span>
                <button
                  type="button"
                  onClick={() => removeAt(i)}
                  className="shrink-0 text-muted hover:text-red-600 cursor-pointer"
                  aria-label="Remove file"
                >
                  <X size={14} />
                </button>
              </li>
            );
          })}
        </ul>
      )}
      {tooBig && (
        <p className="mt-1 text-xs text-red-600 dark:text-red-400">
          Some files exceed the 10&nbsp;MB limit and will be skipped.
        </p>
      )}
    </div>
  );
}
