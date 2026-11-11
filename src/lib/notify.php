<?php
declare(strict_types=1);

/*
 * Notification fan-out: in-app (picked up live by the SSE stream), Web Push and
 * email — honouring user preferences intersected with the tenant policy.
 */

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

function user_prefs(string $userId, string $tenantId, ?array $policy = null): array
{
    $policy ??= tenant_policy($tenantId);
    $row = q_one('SELECT * FROM "NotificationPreference" WHERE "userId" = ?', [$userId]);
    // Users without their own prefs inherit the tenant defaults.
    $p = ['channels' => $policy['defaults'], 'quietStart' => null, 'quietEnd' => null, 'priorityThreshold' => '', 'scope' => 'all'];
    if (!$row) return $p;
    return [
        'channels' => parse_channel_matrix($row['prefs']),
        'quietStart' => $row['quietStart'] !== null ? (int) $row['quietStart'] : null,
        'quietEnd' => $row['quietEnd'] !== null ? (int) $row['quietEnd'] : null,
        'priorityThreshold' => (string) $row['priorityThreshold'],
        'scope' => $row['scope'] ?: 'all',
    ];
}

function in_quiet_hours(array $p): bool
{
    if ($p['quietStart'] === null || $p['quietEnd'] === null || $p['quietStart'] === $p['quietEnd']) return false;
    $mins = (int) date('G') * 60 + (int) date('i');
    return $p['quietStart'] < $p['quietEnd']
        ? $mins >= $p['quietStart'] && $mins < $p['quietEnd']
        : $mins >= $p['quietStart'] || $mins < $p['quietEnd']; // overnight window
}

/**
 * Fan a ticket event out to each recipient's enabled channels.
 * $o: tenantId, recipients[], category, ticket{id,number,subject,priority},
 *     inAppType, inAppMessage, pushBody, emailLine
 */
function dispatch(array $o): void
{
    $recipients = array_values(array_unique(array_filter($o['recipients'])));
    if (!$recipients) return;

    $priorities = tenant_priorities($o['tenantId']);
    $policy = tenant_policy($o['tenantId']);
    $orderOf = function (string $key) use ($priorities): int {
        foreach ($priorities as $p) if ($p['key'] === $key) return $p['order'];
        return 0;
    };
    $ticketOrder = $orderOf($o['ticket']['priority']);

    $pushIds = [];
    $emailIds = [];
    $now = now_iso();
    foreach ($recipients as $userId) {
        $prefs = user_prefs($userId, $o['tenantId'], $policy);
        // Scope only gates the broadcast "new ticket" to all staff.
        if ($o['category'] === 'new_ticket' && $prefs['scope'] !== 'all') continue;

        $pref = $prefs['channels'][$o['category']];
        $inApp = $pref['inApp'] && $policy['allowInApp'];
        $push = $pref['push'] && $policy['allowPush'];
        $email = $pref['email'] && $policy['allowEmail'];
        $meets = !$prefs['priorityThreshold'] || $ticketOrder >= $orderOf($prefs['priorityThreshold']);

        if ($inApp) {
            db_insert('Notification', [
                'id' => cuid(), 'type' => $o['inAppType'], 'message' => $o['inAppMessage'], 'read' => 0,
                'createdAt' => $now, 'tenantId' => $o['tenantId'], 'userId' => $userId, 'ticketId' => $o['ticket']['id'],
            ]);
        }
        if ($push && $meets && !in_quiet_hours($prefs)) $pushIds[] = $userId;
        if ($email && $meets) $emailIds[] = $userId;
    }

    $t = $o['ticket'];
    if ($pushIds) {
        send_push_to_users($pushIds, [
            'title' => mb_substr("#{$t['number']} · {$t['subject']}", 0, 80),
            'body' => $o['pushBody'],
            'url' => "/tickets/{$t['id']}",
            'tag' => "ticket-{$t['id']}",
        ]);
    }
    if ($emailIds) {
        email_tenant_users($o['tenantId'], $emailIds, [
            'subject' => "[#{$t['number']}] {$t['subject']}",
            'html' => '<p>' . e($o['emailLine']) . '</p><p><a href="' . e(app_url() . "/tickets/{$t['id']}") . '">View ticket #' . (int) $t['number'] . '</a></p>',
        ]);
    }
}
