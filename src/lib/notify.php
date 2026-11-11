<?php
declare(strict_types=1);

/*
 * Notifications: in-app (written at once, picked up live by the SSE stream),
 * Web Push and email (queued in NotificationJob and sent in the background).
 *
 *  - Who gets what: a per-workspace matrix role × event × channel (Settings →
 *    Notifications), each cell on/off and optionally locked; people may change
 *    unlocked cells for themselves (Notifications page).
 *  - Push and email are queued: sent right after the response, retried with
 *    back-off by the cron, delayed (not dropped) during quiet hours, or rolled
 *    into an hourly / daily summary. Every attempt is in the delivery log.
 */

const NOTIFY_MAX_ATTEMPTS = 6;

function default_channels(): array
{
    $c = [];
    foreach (NOTIFY_CATEGORIES as $cat) $c[$cat] = ['inApp' => true, 'push' => true, 'email' => true];
    return $c;
}

function parse_channel_matrix(?string $json): array
{
    $base = default_channels();
    $stored = json_decode((string) $json, true);
    if (!is_array($stored)) return $base;
    foreach (NOTIFY_CATEGORIES as $c) {
        if (isset($stored[$c]) && is_array($stored[$c])) {
            foreach (CHANNELS as $ch) $base[$c][$ch] = ($stored[$c][$ch] ?? true) !== false;
        }
    }
    return $base;
}

function tenant_policy(string $tenantId): array
{
    $row = q_one('SELECT * FROM "TenantNotificationPolicy" WHERE "tenantId" = ?', [$tenantId]);
    return [
        'allowInApp' => $row ? (bool) $row['allowInApp'] : true,
        'allowPush' => $row ? (bool) $row['allowPush'] : true,
        'allowEmail' => $row ? (bool) $row['allowEmail'] : true,
        'defaults' => parse_channel_matrix($row['defaults'] ?? '{}'),
    ];
}

/** Role used for notification rules. */
function notify_role(array $user): string
{
    return match ($user['role']) { 'CUSTOMER' => 'customer', 'AGENT' => 'agent', default => 'admin' };
}

/**
 * Workspace rules: [role][event][channel] => ['on' => bool, 'lock' => bool] plus
 * ['_digest'][role] => instant|hourly|daily. Defaults come from the older single
 * policy matrix so existing workspaces keep behaving the same.
 */
function notify_matrix(string $tenantId): array
{
    static $cache = [];
    if (isset($cache[$tenantId])) return $cache[$tenantId];
    $policy = tenant_policy($tenantId);
    $saved = tenant_setting($tenantId, 'notifyMatrix');
    $saved = is_array($saved) ? $saved : [];
    $m = ['_digest' => []];
    foreach (NOTIFY_ROLES as $role => $_) {
        foreach (NOTIFY_CATEGORIES as $ev) {
            foreach (CHANNELS as $ch) {
                $cell = $saved[$role][$ev][$ch] ?? null;
                $m[$role][$ev][$ch] = [
                    'on' => is_array($cell) ? (bool) ($cell['on'] ?? true) : (bool) ($policy['defaults'][$ev][$ch] ?? true),
                    'lock' => is_array($cell) && !empty($cell['lock']),
                ];
            }
        }
        $d = $saved['_digest'][$role] ?? 'instant';
        $m['_digest'][$role] = isset(DIGEST_MODES[$d]) ? $d : 'instant';
    }
    return $cache[$tenantId] = $m;
}

/** Timing of the rule-based notifications (Settings → Notifications → Timing & automations). */
function notify_rules(string $tenantId): array
{
    $s = tenant_setting($tenantId, 'notifyRules');
    $s = is_array($s) ? $s : [];
    return [
        'slaWarnMinutes' => max(5, min(1440, (int) ($s['slaWarnMinutes'] ?? 60))),
        'unassignedMinutes' => max(5, min(10080, (int) ($s['unassignedMinutes'] ?? 30))),
        'remindersOn' => (bool) ($s['remindersOn'] ?? true),
        'reminderDays' => max(1, min(60, (int) ($s['reminderDays'] ?? 3))),
        'autoCloseOn' => (bool) ($s['autoCloseOn'] ?? false),
        'autoCloseDays' => max(1, min(120, (int) ($s['autoCloseDays'] ?? 7))),
        'waitingStatus' => (string) ($s['waitingStatus'] ?? 'PENDING'),
    ];
}

/** Personal preferences (the Notifications page); 'own' tells whether the person saved any. */
function user_prefs(string $userId, string $tenantId, ?array $policy = null): array
{
    $policy ??= tenant_policy($tenantId);
    $row = q_one('SELECT * FROM "NotificationPreference" WHERE "userId" = ?', [$userId]);
    $p = ['channels' => $policy['defaults'], 'quietStart' => null, 'quietEnd' => null, 'priorityThreshold' => '', 'scope' => 'all', 'digest' => null, 'own' => false];
    if (!$row) return $p;
    $raw = json_decode((string) $row['prefs'], true) ?: [];
    return [
        'channels' => parse_channel_matrix($row['prefs']),
        'quietStart' => $row['quietStart'] !== null ? (int) $row['quietStart'] : null,
        'quietEnd' => $row['quietEnd'] !== null ? (int) $row['quietEnd'] : null,
        'priorityThreshold' => (string) $row['priorityThreshold'],
        'scope' => $row['scope'] ?: 'all',
        'digest' => isset(DIGEST_MODES[$raw['_digest'] ?? '']) ? $raw['_digest'] : null,
        'own' => true,
        'set' => array_keys(array_filter($raw, 'is_array')),
    ];
}

/**
 * Is a channel on for this person and event? Locked workspace cells win;
 * otherwise the person's own choice (if they saved one for that event), else the
 * workspace default. The workspace-wide channel switches always apply.
 */
function channel_on(array $user, string $event, string $channel, array $prefs, array $matrix, array $policy): bool
{
    $allow = ['inApp' => $policy['allowInApp'], 'push' => $policy['allowPush'], 'email' => $policy['allowEmail']][$channel];
    if (!$allow) return false;
    $cell = $matrix[notify_role($user)][$event][$channel] ?? ['on' => true, 'lock' => false];
    if ($cell['lock']) return $cell['on'];
    if ($prefs['own'] && in_array($event, $prefs['set'] ?? [], true)) return (bool) ($prefs['channels'][$event][$channel] ?? $cell['on']);
    return $cell['on'];
}

function in_quiet_hours(array $p): bool
{
    if ($p['quietStart'] === null || $p['quietEnd'] === null || $p['quietStart'] === $p['quietEnd']) return false;
    $mins = (int) date('G') * 60 + (int) date('i');
    return $p['quietStart'] < $p['quietEnd']
        ? $mins >= $p['quietStart'] && $mins < $p['quietEnd']
        : $mins >= $p['quietStart'] || $mins < $p['quietEnd']; // overnight window
}

/** When the person's quiet hours end (UTC ISO), assuming we're inside them. */
function quiet_hours_end(array $p): string
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $now = new DateTimeImmutable('now', $tz);
    $end = $now->setTime(intdiv((int) $p['quietEnd'], 60), (int) $p['quietEnd'] % 60);
    if ($end <= $now) $end = $end->modify('+1 day');
    return iso_from_ts((float) $end->format('U'));
}

/** Next hourly or daily (08:00 local) summary time, UTC ISO. */
function next_digest_at(string $mode): string
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $now = new DateTimeImmutable('now', $tz);
    $at = $mode === 'daily' ? $now->setTime(8, 0) : $now->setTime((int) $now->format('G'), 0)->modify('+1 hour');
    if ($at <= $now) $at = $at->modify('+1 day');
    return iso_from_ts((float) $at->format('U'));
}

/* ---------- Texts (Settings → Notifications → Email texts) ---------- */

const NOTIFY_PLACEHOLDERS = ['{ticket.number}', '{ticket.subject}', '{actor.name}', '{recipient.name}', '{status}', '{workspace}', '{app}'];

function notify_templates(string $tenantId): array
{
    $t = tenant_setting($tenantId, 'notifyTemplates');
    return is_array($t) ? $t : [];
}

function fill_placeholders(string $text, array $vars): string
{
    return strtr($text, $vars);
}

/**
 * Fan an event out to each recipient's channels.
 * $o: tenantId, recipients[], category, ticket{id,number,subject,priority},
 *     inAppType, inAppMessage, pushBody, emailLine,
 *     optional: actor (user row), bodyHtml (reply/description), status (label)
 */
function dispatch(array $o): void
{
    $recipients = array_values(array_unique(array_filter($o['recipients'])));
    if (!$recipients) return;
    $tenantId = $o['tenantId'];
    $event = $o['category'];
    $priorities = tenant_priorities($tenantId);
    $policy = tenant_policy($tenantId);
    $matrix = notify_matrix($tenantId);
    $orderOf = function (string $key) use ($priorities): int {
        foreach ($priorities as $p) if ($p['key'] === $key) return $p['order'];
        return 0;
    };
    $t = $o['ticket'];
    $ticketOrder = $orderOf((string) ($t['priority'] ?? ''));
    $users = key_by(q_all('SELECT "id","name","email","role","active" FROM "User" WHERE "id" IN (' . in_ph($recipients) . ')', $recipients), 'id');
    $tenantName = (string) q_val('SELECT "name" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
    $tpl = notify_templates($tenantId)[$event] ?? [];
    $now = now_iso();
    $queued = false;

    foreach ($recipients as $userId) {
        $u = $users[$userId] ?? null;
        if (!$u || !(int) ($u['active'] ?? 1)) continue;
        $prefs = user_prefs($userId, $tenantId, $policy);
        // Scope only gates the broadcast "new ticket" to all staff.
        if ($event === 'new_ticket' && $prefs['scope'] !== 'all') continue;
        $meets = !$prefs['priorityThreshold'] || $ticketOrder >= $orderOf($prefs['priorityThreshold']);

        $vars = [
            '{ticket.number}' => (string) ($t['number'] ?? ''), '{ticket.subject}' => (string) ($t['subject'] ?? ''),
            '{actor.name}' => (string) ($o['actor']['name'] ?? ''), '{recipient.name}' => (string) $u['name'],
            '{status}' => (string) ($o['status'] ?? ''), '{workspace}' => $tenantName, '{app}' => app_name(),
        ];
        $line = trim((string) ($tpl['line'] ?? '')) !== '' ? fill_placeholders($tpl['line'], $vars) : (string) $o['emailLine'];
        $subject = trim((string) ($tpl['subject'] ?? '')) !== '' ? fill_placeholders($tpl['subject'], $vars) : "[#{$t['number']}] {$t['subject']}";

        if (channel_on($u, $event, 'inApp', $prefs, $matrix, $policy)) {
            db_insert('Notification', [
                'id' => cuid(), 'type' => $o['inAppType'], 'message' => $o['inAppMessage'], 'read' => 0,
                'createdAt' => $now, 'tenantId' => $tenantId, 'userId' => $userId, 'ticketId' => $t['id'],
            ]);
        }
        if (!$meets) continue;
        if (channel_on($u, $event, 'push', $prefs, $matrix, $policy)) {
            notify_queue($tenantId, $userId, 'push', $event, $t['id'], [
                'title' => mb_substr("#{$t['number']} · {$t['subject']}", 0, 80), 'body' => (string) $o['pushBody'],
                'url' => "/tickets/{$t['id']}", 'tag' => "ticket-{$t['id']}",
            ], in_quiet_hours($prefs) ? quiet_hours_end($prefs) : $now);
            $queued = true;
        }
        if (channel_on($u, $event, 'email', $prefs, $matrix, $policy)) {
            $digest = $prefs['digest'] ?? ($matrix['_digest'][notify_role($u)] ?? 'instant');
            notify_queue($tenantId, $userId, 'email', $event, $t['id'], [
                'subject' => $subject, 'line' => $line, 'bodyHtml' => (string) ($o['bodyHtml'] ?? ''),
                'ticket' => ['id' => $t['id'], 'number' => (int) ($t['number'] ?? 0), 'subject' => (string) ($t['subject'] ?? '')],
                'actor' => (string) ($o['actor']['name'] ?? ''), 'customer' => $u['role'] === 'CUSTOMER',
            ], $digest === 'instant' ? $now : next_digest_at($digest), $digest === 'instant' ? 'pending' : 'digest');
            $queued = true;
        }
    }
    if ($queued) notify_flush_after_response();
}

function notify_queue(string $tenantId, string $userId, string $channel, string $event, ?string $ticketId, array $payload, string $sendAfter, string $status = 'pending'): string
{
    db_insert('NotificationJob', [
        'id' => $id = cuid(), 'tenantId' => $tenantId, 'userId' => $userId, 'channel' => $channel, 'event' => $event, 'ticketId' => $ticketId,
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => $status, 'attempts' => 0,
        'sendAfter' => $sendAfter, 'lastError' => null, 'createdAt' => now_iso(), 'sentAt' => null,
    ]);
    return $id;
}

/** Send what's due right after the response is out (the cron catches anything left). */
function notify_flush_after_response(): void
{
    static $registered = false;
    if ($registered || PHP_SAPI === 'cli') return;
    $registered = true;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try { notify_deliver_due(40); } catch (Throwable $e) { error_log('[notify] ' . $e->getMessage()); }
    });
}

/* ---------- Delivery ---------- */

/** Send due push/email jobs; failures are retried with back-off (2, 4, 8… minutes). */
function notify_deliver_due(int $limit = 200, array $onlyIds = []): int
{
    // $onlyIds: send these right away (test button, resend from the log).
    $jobs = $onlyIds
        ? q_all('SELECT * FROM "NotificationJob" WHERE "status" = \'pending\' AND "id" IN (' . in_ph($onlyIds) . ')', $onlyIds)
        : q_all('SELECT * FROM "NotificationJob" WHERE "status" = \'pending\' AND "sendAfter" <= ? ORDER BY "sendAfter", "createdAt" LIMIT ' . max(1, $limit), [now_iso()]);
    foreach ($jobs as $j) {
        // Claim the job so an overlapping run doesn't send it twice.
        if (q('UPDATE "NotificationJob" SET "status" = \'sending\' WHERE "id" = ? AND "status" = \'pending\'', [$j['id']])->rowCount() !== 1) continue;
        $res = notify_send_job($j);
        $attempts = (int) $j['attempts'] + 1;
        if ($res['ok']) {
            db_update('NotificationJob', ['status' => 'sent', 'attempts' => $attempts, 'sentAt' => now_iso(), 'lastError' => $res['note'] ?? null], '"id" = ?', [$j['id']]);
        } elseif (!empty($res['skip'])) {
            db_update('NotificationJob', ['status' => 'skipped', 'attempts' => $attempts, 'lastError' => $res['error']], '"id" = ?', [$j['id']]);
        } else {
            $final = $attempts >= NOTIFY_MAX_ATTEMPTS;
            db_update('NotificationJob', ['status' => $final ? 'failed' : 'pending', 'attempts' => $attempts, 'lastError' => mb_substr((string) $res['error'], 0, 500),
                'sendAfter' => now_iso(60 * (2 ** $attempts))], '"id" = ?', [$j['id']]);
        }
    }
    return count($jobs);
}

/** @return array{ok:bool, skip?:bool, error?:string, note?:string} */
function notify_send_job(array $j): array
{
    $u = q_one('SELECT * FROM "User" WHERE "id" = ?', [$j['userId']]);
    if (!$u || !(int) ($u['active'] ?? 1)) return ['ok' => false, 'skip' => true, 'error' => 'Person no longer active'];
    $p = json_decode((string) $j['payload'], true) ?: [];
    if ($j['channel'] === 'push') {
        if (!vapid_configured()) return ['ok' => false, 'skip' => true, 'error' => 'Push isn’t set up on the server (VAPID keys)'];
        $subs = q_all('SELECT * FROM "PushSubscription" WHERE "userId" = ?', [$u['id']]);
        if (!$subs) return ['ok' => false, 'skip' => true, 'error' => 'No device has push turned on'];
        $data = json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sent = 0;
        $errors = [];
        foreach ($subs as $s) {
            try {
                $code = webpush_send($s, $data);
                if ($code === 404 || $code === 410) { q('DELETE FROM "PushSubscription" WHERE "id" = ?', [$s['id']]); $errors[] = 'device expired'; continue; }
                if ($code >= 200 && $code < 300) $sent++; else $errors[] = "HTTP $code";
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($sent) return ['ok' => true, 'note' => "$sent device(s)" . ($errors ? '; ' . implode(', ', $errors) : '')];
        return ['ok' => false, 'error' => $errors ? implode(', ', array_unique($errors)) : 'Push failed'];
    }
    // Email
    $s = email_settings($j['tenantId']);
    if (!$s || !$s['enabled']) return ['ok' => false, 'skip' => true, 'error' => 'Email isn’t set up for this workspace'];
    $r = send_tenant_email($j['tenantId'], notify_email_message($j['tenantId'], $u, $p, $j['id']));
    if ($r['ok']) return ['ok' => true];
    if (!empty($r['skipped'])) return ['ok' => false, 'skip' => true, 'error' => 'Email isn’t set up for this workspace'];
    return ['ok' => false, 'error' => (string) ($r['error'] ?? 'Send failed')];
}

/* ---------- Email layout ---------- */

function notify_mail_host(): string
{
    return (string) (parse_url(app_url(), PHP_URL_HOST) ?: 'localhost');
}

/** A branded HTML email with the event line, the message itself and a button. */
function notify_email_message(string $tenantId, array $u, array $p, string $jobId): array
{
    $ticket = $p['ticket'] ?? [];
    $url = app_url() . '/tickets/' . ($ticket['id'] ?? '');
    $body = '<p style="margin:0 0 16px;font-size:15px;line-height:1.5;color:#0f172a">' . e((string) ($p['line'] ?? '')) . '</p>';
    if (trim((string) ($p['bodyHtml'] ?? '')) !== '') {
        $body .= '<div style="margin:0 0 20px;padding:14px 16px;border-left:3px solid #c7d2fe;background:#f8fafc;border-radius:8px;font-size:14px;line-height:1.55;color:#334155">'
            . ($p['actor'] ? '<div style="margin:0 0 6px;font-size:12px;font-weight:600;color:#64748b">' . e($p['actor']) . '</div>' : '')
            . sanitize_rich_html((string) $p['bodyHtml']) . '</div>';
    }
    if (($ticket['id'] ?? '') !== '') $body .= notify_button($url, 'View ticket #' . (int) ($ticket['number'] ?? 0));
    $host = notify_mail_host();
    return [
        'to' => $u['email'],
        'subject' => (string) ($p['subject'] ?? 'Update'),
        'html' => notify_email_layout($tenantId, $body, !empty($p['customer'])),
        'messageId' => '<' . $jobId . '@' . $host . '>',
        // All emails about one ticket form a single conversation in mail apps.
        'headers' => ($ticket['id'] ?? '') !== '' ? ['In-Reply-To: <ticket-' . $ticket['id'] . '@' . $host . '>', 'References: <ticket-' . $ticket['id'] . '@' . $host . '>'] : [],
    ];
}

function notify_button(string $url, string $label): string
{
    return '<a href="' . e($url) . '" style="display:inline-block;padding:11px 20px;border-radius:10px;background:#4f46e5;color:#ffffff;font-weight:600;font-size:14px;text-decoration:none">' . e($label) . '</a>';
}

function notify_email_layout(string $tenantId, string $inner, bool $customer = false): string
{
    $logo = brand_logo_url();
    $mark = $logo
        ? '<img src="' . e(app_url() . $logo) . '" alt="" width="32" height="32" style="width:32px;height:32px;border-radius:8px;vertical-align:middle">'
        : '<img src="' . e(app_url() . app_icon('/app-icons/logo-64.png')) . '" alt="" width="32" height="32" style="width:32px;height:32px;vertical-align:middle">';
    $ws = (string) q_val('SELECT "name" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
    return '<!doctype html><html><body style="margin:0;padding:24px 12px;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:16px;border:1px solid #e2e8f0">'
        . '<tr><td style="padding:20px 24px;border-bottom:1px solid #f1f5f9">' . $mark . '<span style="margin-left:10px;font-size:15px;font-weight:700;color:#0f172a;vertical-align:middle">' . e(app_name()) . '</span>'
        . ($ws !== '' ? '<span style="margin-left:6px;font-size:13px;color:#94a3b8;vertical-align:middle">· ' . e($ws) . '</span>' : '') . '</td></tr>'
        . '<tr><td style="padding:24px">' . $inner . '</td></tr>'
        . '<tr><td style="padding:16px 24px;border-top:1px solid #f1f5f9;font-size:12px;line-height:1.5;color:#94a3b8">'
        . ($customer ? 'You can reply on the ticket page.' : 'Sent by ' . e(app_name()) . '.')
        . ' <a href="' . e(app_url() . '/notifications') . '" style="color:#64748b">Choose which emails you get</a></td></tr>'
        . '</table></td></tr></table></body></html>';
}

/* ---------- Summaries (hourly / daily) ---------- */

/** Roll each person's due summary items into one email. */
function notify_send_digests(int $maxUsers = 100): int
{
    $due = q_all('SELECT DISTINCT "userId", "tenantId" FROM "NotificationJob" WHERE "status" = \'digest\' AND "sendAfter" <= ? LIMIT ' . $maxUsers, [now_iso()]);
    $n = 0;
    foreach ($due as $d) {
        $jobs = q_all('SELECT * FROM "NotificationJob" WHERE "status" = \'digest\' AND "userId" = ? AND "tenantId" = ? AND "sendAfter" <= ? ORDER BY "createdAt" LIMIT 200', [$d['userId'], $d['tenantId'], now_iso()]);
        if (!$jobs) continue;
        $u = q_one('SELECT * FROM "User" WHERE "id" = ?', [$d['userId']]);
        $ids = array_column($jobs, 'id');
        $s = email_settings($d['tenantId']);
        if (!$u || !(int) ($u['active'] ?? 1) || !$s || !$s['enabled']) {
            q('UPDATE "NotificationJob" SET "status" = \'skipped\', "lastError" = ? WHERE "id" IN (' . in_ph($ids) . ')', [!$u ? 'Person removed' : 'Email isn’t set up for this workspace', ...$ids]);
            continue;
        }
        // Group the lines by ticket.
        $byTicket = [];
        foreach ($jobs as $j) {
            $p = json_decode((string) $j['payload'], true) ?: [];
            $tid = $p['ticket']['id'] ?? '';
            $byTicket[$tid]['ticket'] = $p['ticket'] ?? [];
            $byTicket[$tid]['lines'][] = (string) ($p['line'] ?? '');
        }
        $inner = '<p style="margin:0 0 16px;font-size:15px;color:#0f172a"><strong>' . count($jobs) . ' update' . (count($jobs) === 1 ? '' : 's') . '</strong> on ' . count($byTicket) . ' ticket' . (count($byTicket) === 1 ? '' : 's') . '</p>';
        foreach ($byTicket as $tid => $g) {
            $inner .= '<div style="margin:0 0 14px;padding:12px 14px;border:1px solid #e2e8f0;border-radius:10px">'
                . '<a href="' . e(app_url() . '/tickets/' . $tid) . '" style="font-size:14px;font-weight:600;color:#4f46e5;text-decoration:none">#' . (int) ($g['ticket']['number'] ?? 0) . ' ' . e((string) ($g['ticket']['subject'] ?? '')) . '</a>'
                . '<ul style="margin:6px 0 0;padding-left:18px;font-size:13px;color:#334155">' . implode('', array_map(fn ($l) => '<li>' . e($l) . '</li>', array_unique($g['lines']))) . '</ul></div>';
        }
        $r = send_tenant_email($d['tenantId'], [
            'to' => $u['email'], 'subject' => app_name() . ': ' . count($jobs) . ' update' . (count($jobs) === 1 ? '' : 's') . ' on your tickets',
            'html' => notify_email_layout($d['tenantId'], $inner, $u['role'] === 'CUSTOMER'),
        ]);
        q('UPDATE "NotificationJob" SET "status" = ?, "sentAt" = ?, "attempts" = "attempts" + 1, "lastError" = ? WHERE "id" IN (' . in_ph($ids) . ')',
            [$r['ok'] ? 'sent' : 'failed', $r['ok'] ? now_iso() : null, $r['ok'] ? 'in a summary' : (string) ($r['error'] ?? 'Send failed'), ...$ids]);
        $n++;
    }
    return $n;
}

/** Retry a failed or skipped job now (delivery log). */
function notify_retry(string $jobId, string $tenantId): bool
{
    return q('UPDATE "NotificationJob" SET "status" = \'pending\', "sendAfter" = ?, "attempts" = 0, "lastError" = NULL WHERE "id" = ? AND "tenantId" = ? AND "status" IN (\'failed\', \'skipped\')', [now_iso(), $jobId, $tenantId])->rowCount() === 1;
}

/** Timestamp of the last cron run (written by run_cron). */
function cron_last_run(): ?string
{
    $v = trim((string) @file_get_contents(ROOT . '/storage/.cron-last'));
    return $v !== '' ? $v : null;
}
