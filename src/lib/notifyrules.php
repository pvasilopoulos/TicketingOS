<?php
declare(strict_types=1);

/*
 * Rule-based notifications, run by the cron (bin/cron.php, every minute):
 * SLA warning / missed, still unassigned, reminder to the customer, and the
 * optional automatic close of tickets waiting too long on the customer.
 * TicketAlert remembers what was already sent so nothing repeats. Each rule only
 * looks at tickets that crossed its limit within the last day, so turning the
 * feature on (or a first cron run) never floods people about old tickets.
 */

/** Mark an alert as sent; false if it already was. */
function alert_once(string $ticketId, string $kind): bool
{
    try {
        db_insert('TicketAlert', ['ticketId' => $ticketId, 'kind' => mb_substr($kind, 0, 180), 'createdAt' => now_iso()]);
        return true;
    } catch (PDOException) {
        return false; // duplicate key: already alerted
    }
}

/** Staff who should hear about a ticket that needs attention: its assignees, else its group, else admins. */
function attention_recipients(array $ticket, string $tenantId, bool $withAdmins = false): array
{
    $ids = ticket_assignee_ids($ticket);
    if (!$ids && !empty($ticket['groupId'])) $ids = pluck(group_members($ticket['groupId']), 'id');
    if (!$ids || $withAdmins) {
        $ids = array_merge($ids, pluck(q_all('SELECT "id" FROM "User" WHERE "tenantId" = ? AND "active" = 1 AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\')', [$tenantId]), 'id'));
    }
    return array_values(array_unique($ids));
}

/** Run every rule for every workspace; returns counts per rule. */
function notify_scan_rules(int $perRule = 100): array
{
    $out = ['slaWarning' => 0, 'slaBreach' => 0, 'unassigned' => 0, 'reminders' => 0, 'autoClosed' => 0];
    foreach (q_all('SELECT "id" FROM "Tenant"') as $tn) {
        $tenantId = $tn['id'];
        $rules = notify_rules($tenantId);
        $statuses = tenant_statuses($tenantId);
        $closed = closed_status_keys($statuses);
        $openSql = $closed ? ' AND t."status" NOT IN (' . in_ph($closed) . ')' : '';
        $now = now_iso();

        // SLA deadline approaching.
        $rows = q_all('SELECT t.* FROM "Ticket" t WHERE t."tenantId" = ? AND t."mergedIntoId" IS NULL AND t."dueAt" IS NOT NULL AND t."dueAt" > ? AND t."dueAt" <= ?' . $openSql . ' ORDER BY t."dueAt" LIMIT ' . $perRule,
            [$tenantId, $now, now_iso($rules['slaWarnMinutes'] * 60), ...$closed]);
        foreach ($rows as $t) {
            if (!alert_once($t['id'], 'slaw:' . $t['dueAt'])) continue;
            $mins = max(1, (int) round((ts($t['dueAt']) - microtime(true)) / 60));
            dispatch(['tenantId' => $tenantId, 'recipients' => attention_recipients($t, $tenantId), 'category' => 'sla_warning', 'ticket' => ticket_ref($t),
                'inAppType' => 'STATUS_CHANGED', 'inAppMessage' => "SLA: ticket #{$t['number']} is due in $mins min",
                'pushBody' => "Due in $mins minutes", 'emailLine' => "Ticket #{$t['number']} “{$t['subject']}” is due in $mins minutes and is still open."]);
            $out['slaWarning']++;
        }

        // SLA deadline missed.
        $rows = q_all('SELECT t.* FROM "Ticket" t WHERE t."tenantId" = ? AND t."mergedIntoId" IS NULL AND t."dueAt" IS NOT NULL AND t."dueAt" <= ? AND t."dueAt" > ?' . $openSql . ' ORDER BY t."dueAt" DESC LIMIT ' . $perRule,
            [$tenantId, $now, now_iso(-86400), ...$closed]);
        foreach ($rows as $t) {
            if (!alert_once($t['id'], 'slab:' . $t['dueAt'])) continue;
            dispatch(['tenantId' => $tenantId, 'recipients' => attention_recipients($t, $tenantId, true), 'category' => 'sla_breach', 'ticket' => ticket_ref($t),
                'inAppType' => 'STATUS_CHANGED', 'inAppMessage' => "SLA missed on ticket #{$t['number']}",
                'pushBody' => 'The resolution deadline passed', 'emailLine' => "The resolution deadline of ticket #{$t['number']} “{$t['subject']}” passed and it is still open."]);
            $out['slaBreach']++;
        }

        // Still unassigned.
        $rows = q_all('SELECT t.* FROM "Ticket" t WHERE t."tenantId" = ? AND t."mergedIntoId" IS NULL AND t."assigneeId" IS NULL AND t."parentId" IS NULL AND t."createdAt" <= ? AND t."createdAt" > ?' . $openSql . ' ORDER BY t."createdAt" DESC LIMIT ' . $perRule,
            [$tenantId, now_iso(-$rules['unassignedMinutes'] * 60), now_iso(-$rules['unassignedMinutes'] * 60 - 86400), ...$closed]);
        foreach ($rows as $t) {
            if (!alert_once($t['id'], 'unassigned')) continue;
            $staff = !empty($t['groupId']) ? pluck(group_members($t['groupId']), 'id')
                : pluck(q_all('SELECT "id" FROM "User" WHERE "tenantId" = ? AND "active" = 1 AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\')', [$tenantId]), 'id');
            dispatch(['tenantId' => $tenantId, 'recipients' => $staff, 'category' => 'unassigned', 'ticket' => ticket_ref($t),
                'inAppType' => 'NEW_TICKET', 'inAppMessage' => "Ticket #{$t['number']} has no one assigned",
                'pushBody' => 'Still unassigned — can you take it?', 'emailLine' => "Nobody has taken ticket #{$t['number']} “{$t['subject']}” yet."]);
            $out['unassigned']++;
        }

        // Waiting on the customer: reminder, then (optionally) close.
        $waiting = $rules['waitingStatus'];
        if (!in_array($waiting, array_column($statuses, 'key'), true) || in_array($waiting, $closed, true)) continue;
        if ($rules['autoCloseOn'] && $closed) {
            $closeKey = in_array('CLOSED', $closed, true) ? 'CLOSED' : $closed[0];
            $rows = q_all('SELECT t.* FROM "Ticket" t WHERE t."tenantId" = ? AND t."status" = ? AND t."updatedAt" <= ? AND t."mergedIntoId" IS NULL ORDER BY t."updatedAt" LIMIT ' . $perRule,
                [$tenantId, $waiting, now_iso(-$rules['autoCloseDays'] * 86400)]);
            foreach ($rows as $t) {
                db_update('Ticket', ['status' => $closeKey, 'closedAt' => now_iso(), 'updatedAt' => now_iso()], '"id" = ?', [$t['id']]);
                log_event($tenantId, $t['id'], null, 'STATUS_CHANGED', "closed the ticket automatically after {$rules['autoCloseDays']} days without a reply");
                dispatch(['tenantId' => $tenantId, 'recipients' => [$t['requesterId']], 'category' => 'auto_closed', 'ticket' => ticket_ref($t),
                    'inAppType' => 'STATUS_CHANGED', 'inAppMessage' => "Ticket #{$t['number']} was closed",
                    'pushBody' => 'Closed after no reply — reply to open it again', 'emailLine' => "We closed ticket #{$t['number']} “{$t['subject']}” because we didn’t hear back for {$rules['autoCloseDays']} days. Reply on the ticket any time to open it again."]);
                csat_on_closed(array_merge($t, ['status' => $closeKey]), $tenantId);
                $out['autoClosed']++;
            }
        }
        if ($rules['remindersOn']) {
            $rows = q_all('SELECT t.* FROM "Ticket" t WHERE t."tenantId" = ? AND t."status" = ? AND t."updatedAt" <= ? AND t."updatedAt" > ? AND t."mergedIntoId" IS NULL ORDER BY t."updatedAt" LIMIT ' . $perRule,
                [$tenantId, $waiting, now_iso(-$rules['reminderDays'] * 86400), now_iso(-$rules['reminderDays'] * 86400 - 86400)]);
            foreach ($rows as $t) {
                // One reminder per quiet period: new activity (updatedAt) allows another.
                if (!alert_once($t['id'], 'remind:' . $t['updatedAt'])) continue;
                dispatch(['tenantId' => $tenantId, 'recipients' => [$t['requesterId']], 'category' => 'reminder', 'ticket' => ticket_ref($t),
                    'inAppType' => 'STATUS_CHANGED', 'inAppMessage' => "We’re waiting for your reply on #{$t['number']}",
                    'pushBody' => 'We’re waiting for your reply', 'emailLine' => "We’re waiting for your reply on ticket #{$t['number']} “{$t['subject']}”. Reply on the ticket so we can continue."
                        . ($rules['autoCloseOn'] ? " Without a reply it will close automatically after {$rules['autoCloseDays']} days." : '')]);
                $out['reminders']++;
            }
        }
    }
    return $out;
}
