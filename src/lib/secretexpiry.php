<?php
declare(strict_types=1);

/*
 * Reminders before an app secret expires: the Microsoft 365 client secret that
 * sends a workspace's email (Settings → Notifications & email), and the
 * platform's Microsoft / Google sign-in secrets (Settings → Security). Microsoft
 * secrets last at most 24 months; when one expires, email or sign-in stops.
 *
 * The date is entered next to the secret. The daily check notifies the people
 * who can fix it (in the app and by email) 30, 14, 7, 3 and 1 days before, on
 * the day, and once after it has expired.
 */

const SECRET_REMIND_DAYS = [0, 1, 3, 7, 14, 30];

/** Whole days from today until $date (Y-m-d); negative once it has passed. */
function secret_days_left(string $date): ?int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    $today = new DateTimeImmutable('today');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d) return null;
    return (int) $today->diff($d)->format('%r%a');
}

/** Reminder stage for this many days left: 'expired', '0', '1', … '30', or null (not yet). */
function secret_stage(?int $days): ?string
{
    if ($days === null) return null;
    if ($days < 0) return 'expired';
    foreach (SECRET_REMIND_DAYS as $t) if ($days <= $t) return (string) $t;
    return null;
}

/** A Y-m-d date from a form field, '' for empty, or null when it isn't a date. */
function secret_date_in(string $v): ?string
{
    $v = trim($v);
    if ($v === '') return '';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v ? $v : null;
}

/** "Expires in 12 days" / "Expires today" / "Expired 3 days ago". */
function secret_expiry_text(int $days): string
{
    if ($days < 0) return 'Expired ' . (-$days) . ' day' . ($days === -1 ? '' : 's') . ' ago';
    if ($days === 0) return 'Expires today';
    return 'Expires in ' . $days . ' day' . ($days === 1 ? '' : 's');
}

/** Warning box for a settings card when the secret expires within 30 days (or has). */
function secret_expiry_alert(?string $date, string $what): string
{
    $days = $date ? secret_days_left($date) : null;
    if ($days === null || $days > 30) return '';
    $bad = $days <= 7;
    return '<p class="flex items-start gap-2 rounded-lg p-3 text-xs ' . ($bad ? 'bg-red-500/10 text-red-700 dark:text-red-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400') . '">'
        . icon('triangle-alert', 14, 'mt-px shrink-0') . '<span><strong>' . e(secret_expiry_text($days)) . '</strong> (' . e(fmt_day($date)) . '): ' . e($what)
        . ' Create a new secret in Microsoft Entra (Certificates &amp; secrets), paste its <em>Value</em> here and update the date.</span></p>';
}

function fmt_day(string $date): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d ? $d->format('j M Y') : $date;
}

/** Tell these users (in the app and by email through their workspace). */
function secret_notify(string $tenantId, array $users, string $subject, string $line, string $link): void
{
    $now = now_iso();
    foreach ($users as $u) {
        db_insert('Notification', ['id' => cuid(), 'type' => 'SYSTEM', 'message' => $line, 'read' => 0, 'createdAt' => $now, 'tenantId' => $tenantId, 'userId' => $u['id'], 'ticketId' => null]);
        send_tenant_email_safe($tenantId, [
            'to' => $u['email'], 'subject' => $subject,
            'html' => notify_email_layout($tenantId, '<p>Hi ' . e(first_name($u['name'])) . ',</p><p>' . e($line) . '</p>'
                . '<p>Create a new client secret in Microsoft Entra admin center → App registrations → your app → Certificates &amp; secrets, then paste its <strong>Value</strong> in ' . e(app_name()) . ' and update the expiry date.</p>'
                . notify_button(app_url() . $link, 'Open settings')),
        ]);
    }
}

/** Daily (from cron): send the reminders that are due. Returns how many were sent. */
function secret_expiry_check(): int
{
    $sent = 0;
    // 1) Each workspace's Microsoft 365 email secret → its administrators.
    foreach (q_all('SELECT "tenantId", "msClientSecretExpires" FROM "EmailSetting" WHERE "provider" = ? AND "msClientSecretExpires" IS NOT NULL AND "msClientSecretExpires" <> \'\'', ['MICROSOFT365']) as $r) {
        $date = (string) $r['msClientSecretExpires'];
        $stage = secret_stage(secret_days_left($date));
        if (!$stage) continue;
        $key = $date . '|' . $stage;
        if (tenant_setting($r['tenantId'], 'emailSecretReminded') === $key) continue;
        tenant_setting_set($r['tenantId'], 'emailSecretReminded', $key);
        $days = (int) secret_days_left($date);
        $admins = q_all('SELECT "id", "name", "email" FROM "User" WHERE "tenantId" = ? AND "role" = \'ADMIN\' AND "active" = 1', [$r['tenantId']]);
        $line = 'The Microsoft 365 secret that sends this workspace’s email: ' . lcfirst(secret_expiry_text($days)) . ' (' . fmt_day($date) . ').' . ($days < 0 ? ' Emails are not being sent.' : ' After that, emails stop.');
        secret_notify($r['tenantId'], $admins, $days < 0 ? 'Email has stopped: the Microsoft 365 secret expired' : 'The Microsoft 365 email secret ' . lcfirst(secret_expiry_text($days)), $line, '/settings#notifications');
        $sent += count($admins);
    }
    // 2) The platform's sign-in secrets → super admins (through the workspace they belong to).
    $plat = sso_platform();
    foreach (SSO_PROVIDERS as $p => $label) {
        $date = (string) ($plat[$p]['expires'] ?? '');
        if ($plat[$p]['source'] !== 'settings' || $date === '') continue;
        $stage = secret_stage(secret_days_left($date));
        if (!$stage || sso_reminded($p) === $date . '|' . $stage) continue;
        sso_reminded($p, $date . '|' . $stage);
        $days = (int) secret_days_left($date);
        $line = "The $label sign-in secret: " . lcfirst(secret_expiry_text($days)) . ' (' . fmt_day($date) . ').' . ($days < 0 ? " Nobody can sign in with $label." : " After that, nobody can sign in with $label.");
        foreach (q_all('SELECT "id", "name", "email", "tenantId" FROM "User" WHERE "role" = \'SUPER_ADMIN\' AND "active" = 1 AND "tenantId" IS NOT NULL') as $u) {
            secret_notify($u['tenantId'], [$u], $days < 0 ? "$label sign-in has stopped: its secret expired" : "The $label sign-in secret " . lcfirst(secret_expiry_text($days)), $line, '/settings#security');
            $sent++;
        }
    }
    return $sent;
}
