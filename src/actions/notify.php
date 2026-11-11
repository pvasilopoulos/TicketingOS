<?php
declare(strict_types=1);

/* Settings → Notifications: rules per role, timing, email texts, delivery log. */

/** Channels allowed workspace-wide + the role × event × channel matrix (on / locked) + summary defaults. */
function act_notify_matrix_save(array $in): ?array
{
    if (!($ctx = ctx_with('settings.notifications'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $matrix = ['_digest' => []];
    foreach (NOTIFY_ROLES as $role => $_) {
        foreach (EVENT_ROLES[$role] as $ev) {
            foreach (CHANNELS as $ch) {
                $matrix[$role][$ev][$ch] = ['on' => ($in['on'][$role][$ev][$ch] ?? '') === 'on', 'lock' => ($in['lock'][$role][$ev][$ch] ?? '') === 'on'];
            }
        }
        $d = (string) ($in['digest'][$role] ?? 'instant');
        $matrix['_digest'][$role] = isset(DIGEST_MODES[$d]) ? $d : 'instant';
    }
    tenant_setting_set($tenantId, 'notifyMatrix', $matrix);
    // Workspace-wide switches stay in the original policy table.
    $data = [
        'allowInApp' => ($in['allowInApp'] ?? '') === 'on' ? 1 : 0, 'allowPush' => ($in['allowPush'] ?? '') === 'on' ? 1 : 0,
        'allowEmail' => ($in['allowEmail'] ?? '') === 'on' ? 1 : 0, 'updatedAt' => now_iso(),
    ];
    if (q_val('SELECT 1 FROM "TenantNotificationPolicy" WHERE "tenantId" = ?', [$tenantId])) {
        db_update('TenantNotificationPolicy', $data, '"tenantId" = ?', [$tenantId]);
    } else {
        db_insert('TenantNotificationPolicy', ['id' => cuid(), 'tenantId' => $tenantId, 'defaults' => '{}'] + $data);
    }
    return ['success' => 'Notification rules saved', 'back' => '/settings?role=' . urlencode((string) ($in['_role'] ?? 'agent')) . '#notifications'];
}

function act_notify_rules_save(array $in): ?array
{
    if (!($ctx = ctx_with('settings.notifications'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $statuses = array_column(tenant_statuses($tenantId), 'key');
    tenant_setting_set($tenantId, 'notifyRules', [
        'slaWarnMinutes' => (int) ($in['slaWarnMinutes'] ?? 60), 'unassignedMinutes' => (int) ($in['unassignedMinutes'] ?? 30),
        'remindersOn' => ($in['remindersOn'] ?? '') === 'on', 'reminderDays' => (int) ($in['reminderDays'] ?? 3),
        'autoCloseOn' => ($in['autoCloseOn'] ?? '') === 'on', 'autoCloseDays' => (int) ($in['autoCloseDays'] ?? 7),
        'waitingStatus' => in_array($in['waitingStatus'] ?? '', $statuses, true) ? $in['waitingStatus'] : 'PENDING',
    ]);
    return ['success' => 'Timing saved'];
}

function act_notify_templates_save(array $in): ?array
{
    if (!($ctx = ctx_with('settings.notifications'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $out = [];
    foreach (NOTIFY_CATEGORIES as $ev) {
        $subject = mb_substr(trim((string) ($in['tpl'][$ev]['subject'] ?? '')), 0, 200);
        $line = mb_substr(trim((string) ($in['tpl'][$ev]['line'] ?? '')), 0, 1000);
        if ($subject !== '' || $line !== '') $out[$ev] = ['subject' => $subject, 'line' => $line];
    }
    tenant_setting_set($tenantId, 'notifyTemplates', $out);
    return ['success' => 'Email texts saved'];
}

/** Delivery log: retry one job, or all failed ones. */
function act_notify_retry(array $in): ?array
{
    if (!($ctx = ctx_with('settings.notifications'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    if (($in['all'] ?? '') === '1') {
        $n = q('UPDATE "NotificationJob" SET "status" = \'pending\', "sendAfter" = ?, "attempts" = 0, "lastError" = NULL WHERE "tenantId" = ? AND "status" = \'failed\'', [now_iso(), $tenantId])->rowCount();
        notify_deliver_due(60);
        return ['success' => $n ? "Retrying $n message(s)" : 'Nothing failed'];
    }
    $id = str_in($in, 'id');
    if (!notify_retry($id, $tenantId)) return ['error' => 'That message can’t be retried'];
    notify_deliver_due(1, [$id]);
    $r = q_one('SELECT "status", "lastError" FROM "NotificationJob" WHERE "id" = ?', [$id]);
    return $r['status'] === 'sent' ? ['success' => 'Sent'] : ['error' => 'Still not sent: ' . ($r['lastError'] ?: $r['status'])];
}

/** Send a test push + email to yourself through the queue. */
function act_notify_test(array $in): ?array
{
    if (!($ctx = ctx_with('settings.notifications'))) return NOT_ALLOWED;
    [$user, $tenantId] = $ctx;
    $t = ['id' => '', 'number' => 0, 'subject' => 'Test notification', 'priority' => ''];
    $ids = [];
    $ids[] = notify_queue($tenantId, $user['id'], 'push', 'status', null, ['title' => app_name() . ' · test', 'body' => 'Push notifications reach this device.', 'url' => '/notifications', 'tag' => 'test'], now_iso());
    $ids[] = notify_queue($tenantId, $user['id'], 'email', 'status', null, ['subject' => app_name() . ' — test notification', 'line' => 'This is a test of your notification emails. If you can read it, email delivery works.',
        'bodyHtml' => '', 'ticket' => $t, 'actor' => '', 'customer' => false], now_iso());
    notify_deliver_due(2, $ids);
    $rows = q_all('SELECT "channel","status","lastError" FROM "NotificationJob" WHERE "id" IN (' . in_ph($ids) . ')', $ids);
    $txt = implode(' · ', array_map(fn ($r) => ucfirst($r['channel']) . ': ' . $r['status'] . ($r['lastError'] ? ' (' . $r['lastError'] . ')' : ''), $rows));
    return ['success' => 'Test sent — ' . $txt];
}

/** Hosts without a cron: send what's waiting now. */
function act_notify_run(array $in): ?array
{
    if (!($ctx = ctx_with('settings.notifications'))) return NOT_ALLOWED;
    $r = run_cron();
    return ['success' => 'Processed: ' . (int) $r['notifications'] . ' message(s), ' . (int) $r['digests'] . ' summary email(s), ' . array_sum($r['rules']) . ' rule alert(s)'];
}
