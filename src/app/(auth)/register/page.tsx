"use client";

import Link from "next/link";
import { useActionState } from "react";
import { useFormStatus } from "react-dom";
import { registerAction } from "@/lib/actions/auth";
import { Input, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";

function SubmitButton() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" size="lg" className="w-full" disabled={pending}>
      {pending ? "Creating workspace…" : "Create workspace"}
    </Button>
  );
}

export default function RegisterPage() {
  const [state, action] = useActionState(registerAction, undefined);

  return (
    <div>
      <h1 className="text-2xl font-bold">Create your workspace</h1>
      <p className="mt-1 text-sm text-muted">
        Spin up an isolated helpdesk for your organization.
      </p>

      <form action={action} className="mt-8 space-y-4">
        {state?.error && (
          <div className="rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-3 py-2 text-sm text-red-700 dark:text-red-300">
            {state.error}
          </div>
        )}
        <div>
          <Label htmlFor="organizationName">Organization name</Label>
          <Input id="organizationName" name="organizationName" placeholder="Acme Inc" required />
        </div>
        <div>
          <Label htmlFor="name">Your name</Label>
          <Input id="name" name="name" placeholder="Jane Doe" required autoComplete="name" />
        </div>
        <div>
          <Label htmlFor="email">Work email</Label>
          <Input id="email" name="email" type="email" placeholder="you@company.com" required autoComplete="email" />
        </div>
        <div>
          <Label htmlFor="password">Password</Label>
          <Input id="password" name="password" type="password" placeholder="At least 6 characters" required autoComplete="new-password" />
        </div>
        <SubmitButton />
      </form>

      <p className="mt-6 text-sm text-muted">
        Already have an account?{" "}
        <Link href="/login" className="font-medium text-brand hover:underline">
          Sign in
        </Link>
      </p>
    </div>
  );
}
