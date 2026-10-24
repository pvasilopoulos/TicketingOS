"use client";

import Link from "next/link";
import { useActionState } from "react";
import { useFormStatus } from "react-dom";
import { requestPasswordReset } from "@/lib/actions/reset";
import { Input, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";

function SubmitButton() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" size="lg" className="w-full" disabled={pending}>
      {pending ? "Sending…" : "Send reset link"}
    </Button>
  );
}

export default function ForgotPage() {
  const [state, action] = useActionState(requestPasswordReset, undefined);

  return (
    <div>
      <h1 className="text-2xl font-bold">Forgot your password?</h1>
      <p className="mt-1 text-sm text-muted">
        Enter your email and we&apos;ll send you a reset link.
      </p>

      <form action={action} className="mt-8 space-y-4">
        {state?.success && (
          <div className="rounded-lg bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
            {state.success}
          </div>
        )}
        <div>
          <Label htmlFor="email">Email</Label>
          <Input id="email" name="email" type="email" placeholder="you@company.com" required autoComplete="email" />
        </div>
        <SubmitButton />
      </form>

      <p className="mt-6 text-sm text-muted">
        <Link href="/login" className="font-medium text-brand hover:underline">
          Back to sign in
        </Link>
      </p>
    </div>
  );
}
