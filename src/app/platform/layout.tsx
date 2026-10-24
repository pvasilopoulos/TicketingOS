import Link from "next/link";
import { ShieldCheck } from "lucide-react";
import { requireSuperAdmin } from "@/lib/auth";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { UserMenu } from "@/components/app/UserMenu";

export default async function PlatformLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const user = await requireSuperAdmin();

  return (
    <div className="min-h-screen">
      <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-border bg-surface px-4 lg:px-8">
        <Link href="/platform" className="flex items-center gap-2.5 font-semibold">
          <span className="grid h-9 w-9 place-items-center rounded-lg bg-sky-500 text-white">
            <ShieldCheck size={20} />
          </span>
          <span>
            TicketingOS <span className="text-muted">· Platform</span>
          </span>
        </Link>
        <div className="ml-auto flex items-center gap-1">
          <ThemeToggle />
          <UserMenu name={user.name} email={user.email} role={user.role} color={user.avatarColor} />
        </div>
      </header>
      <main className="mx-auto max-w-6xl px-4 py-8 lg:px-8 animate-in">{children}</main>
    </div>
  );
}
