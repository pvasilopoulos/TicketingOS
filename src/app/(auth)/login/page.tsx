"use client";

import Link from "next/link";
import { useActionState } from "react";
import { useFormStatus } from "react-dom";
import { useSearchParams } from "next/navigation";
import { loginAction } from "@/lib/actions/auth";
import { Input, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";

function SubmitButton() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" size="lg" className="w-full" disabled={pending}>
      {pending ? "Signing in…" : "Sign in"}
    </Button>
  );
}

export default function LoginPage() {
  const [state, action] = useActionState(loginAction, undefined);
  const resetDone = useSearchParams().get("reset") === "1";

  return (
    <div>
      <h1 className="text-2xl font-bold">Welcome back</h1>
      <p className="mt-1 text-sm text-muted">Sign in to your workspace.</p>

      <form action={action} className="mt-8 space-y-4">
        {resetDone && (
          <div className="rounded-lg bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
            Password updated. Sign in with your new password.
          </div>
        )}
        {state?.error && (
          <div className="rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-3 py-2 text-sm text-red-700 dark:text-red-300">
            {state.error}
          </div>
        )}
        <div>
          <Label htmlFor="email">Email</Label>
          <Input id="email" name="email" type="email" placeholder="you@company.com" required autoComplete="email" />
        </div>
        <div>
          <div className="flex items-center justify-between">
            <Label htmlFor="password">Password</Label>
            <Link href="/forgot" className="mb-1.5 text-xs font-medium text-brand hover:underline">
              Forgot?
            </Link>
          </div>
          <Input id="password" name="password" type="password" placeholder="••••••••" required autoComplete="current-password" />
        </div>
        <SubmitButton />
      </form>

      <p className="mt-6 text-sm text-muted">
        No workspace yet?{" "}
        <Link href="/register" className="font-medium text-brand hover:underline">
          Create one
        </Link>
      </p>

      <div className="mt-8 rounded-lg border border-border bg-surface-2 p-3 text-xs text-muted">
        <p className="font-medium text-fg">Demo accounts (password: password123)</p>
        <p className="mt-1">Platform: info@softify.gr</p>
        <p>Acme admin: admin@acme.dev · agent: sofia@acme.dev</p>
        <p>Acme customer: jamie@acme-customer.com</p>
        <p>Globex admin: admin@globex.dev</p>
      </div>
    </div>
  );
}
