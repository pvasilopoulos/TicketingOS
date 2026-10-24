"use client";

import Link from "next/link";
import { useActionState } from "react";
import { useFormStatus } from "react-dom";
import { resetPassword } from "@/lib/actions/reset";
import { Input, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";

function SubmitButton() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" size="lg" className="w-full" disabled={pending}>
      {pending ? "Updating…" : "Set new password"}
    </Button>
  );
}

export function ResetForm({ token }: { token: string }) {
  const [state, action] = useActionState(resetPassword, undefined);

  if (!token) {
    return (
      <div>
        <h1 className="text-2xl font-bold">Invalid link</h1>
        <p className="mt-1 text-sm text-muted">
          This reset link is missing its token.
        </p>
        <p className="mt-6 text-sm">
          <Link href="/forgot" className="font-medium text-brand hover:underline">
            Request a new link
          </Link>
        </p>
      </div>
    );
  }

  return (
    <div>
      <h1 className="text-2xl font-bold">Set a new password</h1>
      <p className="mt-1 text-sm text-muted">Choose a strong password you&apos;ll remember.</p>

      <form action={action} className="mt-8 space-y-4">
        {state?.error && (
          <div className="rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-3 py-2 text-sm text-red-700 dark:text-red-300">
            {state.error}
          </div>
        )}
        <input type="hidden" name="token" value={token} />
        <div>
          <Label htmlFor="password">New password</Label>
          <Input id="password" name="password" type="password" placeholder="At least 6 characters" required autoComplete="new-password" />
        </div>
        <div>
          <Label htmlFor="confirm">Confirm password</Label>
          <Input id="confirm" name="confirm" type="password" placeholder="Repeat password" required autoComplete="new-password" />
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
