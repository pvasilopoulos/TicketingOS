"use client";

import { useActionState, useState } from "react";
import { useFormStatus } from "react-dom";
import { Mail, Send, CheckCircle2 } from "lucide-react";
import {
  saveEmailSettings,
  sendTestEmail,
  type EmailState,
} from "@/lib/actions/email";
import { Input, Select, Label } from "@/components/ui/Field";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";

export type EmailSettingsView = {
  enabled: boolean;
  provider: "SMTP" | "MICROSOFT365";
  fromName: string;
  fromEmail: string;
  smtpHost: string;
  smtpPort: string;
  smtpSecure: boolean;
  smtpUser: string;
  smtpPasswordSet: boolean;
  msTenantId: string;
  msClientId: string;
  msClientSecretSet: boolean;
  msSender: string;
};

function Banner({ state }: { state: EmailState }) {
  if (!state) return null;
  if (state.error)
    return (
      <div className="rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-3 py-2 text-sm text-red-700 dark:text-red-300">
        {state.error}
      </div>
    );
  if (state.success)
    return (
      <div className="flex items-center gap-2 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
        <CheckCircle2 size={15} /> {state.success}
      </div>
    );
  return null;
}

function SaveButton() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" disabled={pending}>
      {pending ? "Saving…" : "Save email settings"}
    </Button>
  );
}

function TestButton() {
  const { pending } = useFormStatus();
  return (
    <Button type="submit" variant="outline" disabled={pending}>
      <Send size={15} /> {pending ? "Sending…" : "Send test email"}
    </Button>
  );
}

export function EmailSettingsManager({ settings }: { settings: EmailSettingsView }) {
  const [saveState, saveAction] = useActionState<EmailState, FormData>(
    saveEmailSettings,
    undefined,
  );
  const [testState, testAction] = useActionState<EmailState, FormData>(
    sendTestEmail,
    undefined,
  );
  const [provider, setProvider] = useState(settings.provider);

  return (
    <Card>
      <CardHeader
        title={
          <span className="inline-flex items-center gap-2">
            <Mail size={16} /> Email notifications
          </span>
        }
      />

      <form action={saveAction} className="space-y-5 p-6">
        <Banner state={saveState} />

        <label className="flex items-center gap-3 rounded-lg border border-border bg-surface-2 px-3 py-2.5 cursor-pointer select-none">
          <input
            type="checkbox"
            name="enabled"
            defaultChecked={settings.enabled}
            className="h-4 w-4 rounded border-border accent-brand"
          />
          <span>
            <span className="block text-sm font-medium">Enable email notifications</span>
            <span className="block text-xs text-muted">
              Send ticket updates to requesters, assignees and the team by email.
            </span>
          </span>
        </label>

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="provider">Provider</Label>
            <Select
              id="provider"
              name="provider"
              value={provider}
              onChange={(e) => setProvider(e.target.value as typeof provider)}
            >
              <option value="SMTP">SMTP server</option>
              <option value="MICROSOFT365">Microsoft 365</option>
            </Select>
          </div>
          <div>
            <Label htmlFor="fromEmail">From email</Label>
            <Input
              id="fromEmail"
              name="fromEmail"
              type="email"
              placeholder="support@company.com"
              defaultValue={settings.fromEmail}
            />
          </div>
          <div className="sm:col-span-2">
            <Label htmlFor="fromName">From name</Label>
            <Input
              id="fromName"
              name="fromName"
              placeholder="Acme Support"
              defaultValue={settings.fromName}
            />
          </div>
        </div>

        {/* SMTP */}
        <fieldset className={provider === "SMTP" ? "space-y-4" : "hidden"}>
          <legend className="text-sm font-semibold">SMTP server</legend>
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="sm:col-span-2">
              <Label htmlFor="smtpHost">Host</Label>
              <Input id="smtpHost" name="smtpHost" placeholder="smtp.company.com" defaultValue={settings.smtpHost} />
            </div>
            <div>
              <Label htmlFor="smtpPort">Port</Label>
              <Input id="smtpPort" name="smtpPort" type="number" placeholder="587" defaultValue={settings.smtpPort} />
            </div>
            <div>
              <Label htmlFor="smtpUser">Username</Label>
              <Input id="smtpUser" name="smtpUser" placeholder="apikey / user" defaultValue={settings.smtpUser} />
            </div>
            <div>
              <Label htmlFor="smtpPassword">Password</Label>
              <Input
                id="smtpPassword"
                name="smtpPassword"
                type="password"
                placeholder={settings.smtpPasswordSet ? "•••••••• (set — leave blank to keep)" : "Password"}
              />
            </div>
            <label className="flex items-center gap-2 text-sm text-muted cursor-pointer self-end pb-2.5">
              <input type="checkbox" name="smtpSecure" defaultChecked={settings.smtpSecure} className="h-4 w-4 rounded border-border accent-brand" />
              Use TLS/SSL
            </label>
          </div>
        </fieldset>

        {/* Microsoft 365 */}
        <fieldset className={provider === "MICROSOFT365" ? "space-y-4" : "hidden"}>
          <legend className="text-sm font-semibold">Microsoft 365 (Graph)</legend>
          <p className="text-xs text-muted">
            Create an Entra ID app registration with the <code>Mail.Send</code>{" "}
            application permission (admin consent), then paste its details here.
          </p>
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="msTenantId">Directory (tenant) ID</Label>
              <Input id="msTenantId" name="msTenantId" placeholder="00000000-0000-0000-0000-000000000000" defaultValue={settings.msTenantId} />
            </div>
            <div>
              <Label htmlFor="msClientId">Application (client) ID</Label>
              <Input id="msClientId" name="msClientId" placeholder="00000000-0000-0000-0000-000000000000" defaultValue={settings.msClientId} />
            </div>
            <div>
              <Label htmlFor="msClientSecret">Client secret</Label>
              <Input
                id="msClientSecret"
                name="msClientSecret"
                type="password"
                placeholder={settings.msClientSecretSet ? "•••••••• (set — leave blank to keep)" : "Client secret value"}
              />
            </div>
            <div>
              <Label htmlFor="msSender">Send as (mailbox)</Label>
              <Input id="msSender" name="msSender" type="email" placeholder="support@company.com" defaultValue={settings.msSender} />
            </div>
          </div>
        </fieldset>

        <div className="flex items-center gap-3 pt-1">
          <SaveButton />
        </div>
      </form>

      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-6 py-4">
        <p className="text-xs text-muted">
          Send a test email to yourself using the currently saved settings.
        </p>
        <form action={testAction}>
          <TestButton />
        </form>
      </div>
      {testState && (
        <div className="px-6 pb-5">
          <Banner state={testState} />
        </div>
      )}
    </Card>
  );
}
