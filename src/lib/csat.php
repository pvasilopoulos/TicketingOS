<?php
declare(strict_types=1);

/*
 * Customer satisfaction (CSAT) surveys.
 * When a ticket moves into a closed status the requester gets a 1–5 rating
 * request (email with one-click links + a prompt on the ticket page).
 */

const CSAT_LABELS = [1 => 'Very unhappy', 2 => 'Unhappy', 3 => 'Okay', 4 => 'Happy', 5 => 'Very happy'];
const CSAT_FACES = [1 => '😞', 2 => '🙁', 3 => '😐', 4 => '🙂', 5 => '😄'];

function csat_settings(string $tenantId): array
{
    $s = tenant_setting($tenantId, 'csat', []);
    return [
        'enabled' => (bool) ($s['enabled'] ?? false),
        'question' => (string) ($s['question'] ?? 'How would you rate the support you received?'),
    ];
}

/** Open a survey for a just-closed ticket (one pending survey per ticket). */
function csat_on_closed(array $ticket, string $tenantId): void
{
    $cfg = csat_settings($tenantId);
    if (!$cfg['enabled'] || !empty($ticket['mergedIntoId'])) return;
    $requester = q_one('SELECT * FROM "User" WHERE "id" = ?', [$ticket['requesterId']]);
    // Only customers are surveyed, and only when a staff member handled it.
    if (!$requester || is_staff($requester['role']) || !(int) ($requester['active'] ?? 1)) return;
    q('DELETE FROM "CsatSurvey" WHERE "ticketId" = ? AND "answeredAt" IS NULL', [$ticket['id']]);
    $raw = bin2hex(random_bytes(20));
    db_insert('CsatSurvey', [
        'id' => cuid(), 'tenantId' => $tenantId, 'ticketId' => $ticket['id'], 'agentId' => $ticket['assigneeId'] ?? null,
        'requesterId' => $requester['id'], 'tokenHash' => hash('sha256', $raw), 'rating' => null, 'comment' => null,
        'createdAt' => now_iso(), 'answeredAt' => null,
    ]);
    $base = app_url() . '/csat/' . $raw;
    $links = '';
    foreach (CSAT_FACES as $n => $face) {
        $links .= '<a href="' . e($base . '?r=' . $n) . '" style="display:inline-block;margin:0 4px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:12px;font-size:26px;text-decoration:none" title="' . e(CSAT_LABELS[$n]) . '">' . $face . '</a>';
    }
    send_tenant_email_safe($tenantId, [
        'to' => $requester['email'],
        'subject' => "How did we do? (#{$ticket['number']})",
        'html' => '<p>Hi ' . e($requester['name']) . ',</p><p>Your ticket <strong>#' . (int) $ticket['number'] . ' ' . e($ticket['subject']) . '</strong> was resolved.</p>'
            . '<p>' . e($cfg['question']) . '</p><p>' . $links . '</p><p style="color:#64748b;font-size:12px">One click is enough. You can add a comment on the next page.</p>',
    ]);
}

/** Pending survey of a ticket (for the in-app prompt). */
function csat_pending(string $ticketId): ?array
{
    return q_one('SELECT * FROM "CsatSurvey" WHERE "ticketId" = ? AND "answeredAt" IS NULL ORDER BY "createdAt" DESC LIMIT 1', [$ticketId]);
}

function csat_latest_answer(string $ticketId): ?array
{
    return q_one('SELECT * FROM "CsatSurvey" WHERE "ticketId" = ? AND "answeredAt" IS NOT NULL ORDER BY "answeredAt" DESC LIMIT 1', [$ticketId]);
}

/** Record an answer; returns the survey row. */
function csat_answer(array $survey, int $rating, string $comment): array
{
    $rating = max(1, min(5, $rating));
    $comment = mb_substr(trim($comment), 0, 2000);
    db_update('CsatSurvey', ['rating' => $rating, 'comment' => $comment ?: null, 'answeredAt' => now_iso()], '"id" = ?', [$survey['id']]);
    $ticket = q_one('SELECT * FROM "Ticket" WHERE "id" = ?', [$survey['ticketId']]);
    if ($ticket) {
        log_event($survey['tenantId'], $ticket['id'], $survey['requesterId'], 'CSAT', 'rated the support ' . $rating . '/5 (' . strtolower(CSAT_LABELS[$rating]) . ')');
        if ($survey['agentId'] && $rating <= 2) {
            dispatch([
                'tenantId' => $survey['tenantId'], 'recipients' => [$survey['agentId']], 'category' => 'status', 'ticket' => ticket_ref($ticket),
                'inAppType' => 'STATUS_CHANGED',
                'inAppMessage' => "Low satisfaction rating ($rating/5) on #{$ticket['number']}",
                'pushBody' => "Rated $rating/5" . ($comment ? ": $comment" : ''),
                'emailLine' => "The customer rated ticket #{$ticket['number']} $rating/5." . ($comment ? " Comment: $comment" : ''),
            ]);
        }
        webhook_emit($survey['tenantId'], 'csat.received', ['ticket' => api_ticket_brief($ticket), 'rating' => $rating, 'comment' => $comment ?: null]);
    }
    return q_one('SELECT * FROM "CsatSurvey" WHERE "id" = ?', [$survey['id']]);
}

/** CSAT stats for answered surveys since a date: [count, satisfied %, average]. */
function csat_stats(string $tenantId, string $since, ?string $until = null, ?string $orgId = null): array
{
    $p = [$tenantId, $since];
    if ($until) $p[] = $until;
    if ($orgId) $p[] = $orgId;
    $row = q_one('SELECT COUNT(*) AS n, SUM(CASE WHEN "rating" >= 4 THEN 1 ELSE 0 END) AS good, AVG("rating") AS avg
                  FROM "CsatSurvey" WHERE "tenantId" = ? AND "answeredAt" >= ?' . ($until ? ' AND "answeredAt" < ?' : '')
        . ($orgId ? ' AND "requesterId" IN (SELECT "userId" FROM "UserOrganization" WHERE "organizationId" = ?)' : ''), $p);
    $n = (int) ($row['n'] ?? 0);
    return ['count' => $n, 'pct' => $n ? (int) round($row['good'] / $n * 100) : null, 'avg' => $n ? round((float) $row['avg'], 1) : null];
}
