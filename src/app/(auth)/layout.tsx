import { Ticket } from "lucide-react";
import { redirect } from "next/navigation";
import { getCurrentUser } from "@/lib/auth";
import { ThemeToggle } from "@/components/ui/ThemeToggle";

export default async function AuthLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  // Verify the user actually exists (a stale cookie must still show the login form).
  const user = await getCurrentUser();
  if (user) redirect(user.role === "SUPER_ADMIN" ? "/platform" : "/dashboard");

  return (
    <div className="min-h-screen grid lg:grid-cols-2">
      {/* Left: form */}
      <div className="flex flex-col px-6 py-8">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2 font-semibold">
            <span className="grid h-8 w-8 place-items-center rounded-lg bg-brand text-brand-fg">
              <Ticket size={18} />
            </span>
            TicketingOS
          </div>
          <ThemeToggle />
        </div>
        <div className="flex flex-1 items-center justify-center">
          <div className="w-full max-w-sm animate-in">{children}</div>
        </div>
      </div>

      {/* Right: brand panel */}
      <div className="relative hidden lg:block overflow-hidden bg-brand">
        <div className="absolute inset-0 bg-gradient-to-br from-indigo-500 via-indigo-600 to-violet-700" />
        <div
          className="absolute inset-0 opacity-20"
          style={{
            backgroundImage:
              "radial-gradient(circle at 20% 30%, white 1px, transparent 1px)",
            backgroundSize: "28px 28px",
          }}
        />
        <div className="relative z-10 flex h-full flex-col justify-end p-12 text-white">
          <h2 className="text-3xl font-bold leading-tight">
            Support that scales with every customer.
          </h2>
          <p className="mt-3 max-w-md text-white/80">
            A modern, multi-tenant helpdesk. Every organization gets its own
            isolated board, agents, and workflow — out of the box.
          </p>
        </div>
      </div>
    </div>
  );
}
