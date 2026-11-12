<?php
declare(strict_types=1);

/*
 * Permanently deleting tickets (permission "tickets.delete": super admins, and
 * whoever a role or the super admin gives it to). Each deletion is in the audit
 * log with the ticket's number and subject, since the ticket itself is gone.
 */

/** Delete a ticket with everything that belongs to it, files on disk included. */
function ticket_delete(array $ticket, string $tenantId): void
{
    $id = $ticket['id'];
    $files = pluck(q_all('SELECT "id" FROM "Attachment" WHERE "ticketId" = ?', [$id]), 'id');
    db_tx(function () use ($id, $ticket, $tenantId) {
        // Things that only point at the ticket keep living without it.
        q('UPDATE "Ticket" SET "mergedIntoId" = NULL WHERE "mergedIntoId" = ?', [$id]);
        q('UPDATE "Ticket" SET "parentId" = NULL WHERE "parentId" = ?', [$id]);
        q('UPDATE "ChatSession" SET "ticketId" = NULL WHERE "ticketId" = ?', [$id]);
        // Everything that is part of the ticket goes with it (explicitly, not only through foreign keys).
        q('DELETE FROM "CommentRevision" WHERE "commentId" IN (SELECT "id" FROM "Comment" WHERE "ticketId" = ?)', [$id]);
        foreach (['Attachment', 'Comment', 'TicketEvent', 'TicketWatcher', 'TicketTag', 'TicketAssignee', 'TicketCc', 'TicketFieldValue',
                  'TicketPresence', 'TicketAlert', 'CsatSurvey', 'TimeEntry', 'Notification', 'NotificationJob'] as $table) {
            q('DELETE FROM ' . qi($table) . ' WHERE "ticketId" = ?', [$id]);
        }
        q('DELETE FROM "TicketLink" WHERE "ticketId" = ? OR "linkedId" = ?', [$id, $id]);
        q('DELETE FROM "Ticket" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId]);
        // Its number is never given to a new ticket.
        if ((int) $ticket['number'] > (int) tenant_setting($tenantId, 'ticketNumberFloor', 0)) {
            tenant_setting_set($tenantId, 'ticketNumberFloor', (int) $ticket['number']);
        }
    });
    foreach ($files as $f) @unlink(attachment_disk_path($tenantId, $f));
}

function can_delete_tickets(array $user): bool
{
    return is_staff($user['role']) && can($user, 'tickets.delete');
}

function act_ticket_delete(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!can_delete_tickets($user)) return ['error' => 'You don’t have permission to delete tickets'];
    $ticket = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$ticket || !ticket_visible($ticket, $user)) return ['error' => 'Ticket not found'];
    ticket_delete($ticket, $tenantId);
    return ['success' => 'Deleted ticket #' . (int) $ticket['number'] . ' “' . $ticket['subject'] . '”', 'back' => '/tickets'];
}

function act_ticket_bulk_delete(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!can_delete_tickets($user) || !can($user, 'tickets.bulk')) return ['error' => 'You don’t have permission to delete tickets'];
    $ids = json_decode((string) ($in['ids'] ?? '[]'), true);
    if (!is_array($ids) || !$ids) return ['error' => 'Select the tickets to delete first'];
    $numbers = [];
    foreach (array_slice($ids, 0, 100) as $id) {
        $ticket = find_ticket((string) $id, $tenantId);
        if (!$ticket || !ticket_visible($ticket, $user)) continue;
        ticket_delete($ticket, $tenantId);
        $numbers[] = '#' . (int) $ticket['number'];
    }
    if (!$numbers) return ['error' => 'Tickets not found'];
    return ['success' => 'Deleted ' . count($numbers) . ' ticket' . (count($numbers) === 1 ? '' : 's') . ': ' . implode(', ', $numbers)];
}
