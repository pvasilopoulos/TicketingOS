<?php
declare(strict_types=1);

/*
 * Audit log: who changed what in a workspace.
 *
 * Admin-type actions are logged centrally by the action dispatcher
 * (public/index.php → audit_action) using AUDITED_ACTIONS, so a new settings
 * action only needs an entry here. Sign-ins and API/exports log explicitly.
 */

/** action name => [category, label of what happened]. */
const AUDITED_ACTIONS = [
    // Users & access
    'member-create' => ['users', 'Added a user'], 'member-update' => ['users', 'Changed a user'],
    'member-status' => ['users', 'Changed sign-in access'], 'member-password' => ['users', 'Reset a password'],
    'member-remove' => ['users', 'Removed a user'], 'member-role' => ['users', 'Changed a role'],
    'role-save' => ['roles', 'Saved a role'], 'role-delete' => ['roles', 'Deleted a role'],
    'agent-perms-save' => ['roles', 'Changed Agent permissions'], 'admin-perms-save' => ['roles', 'Changed Administrator permissions'], 'security-save' => ['security', 'Changed password rules'],
    'group-save' => ['users', 'Saved a group'], 'group-delete' => ['users', 'Deleted a group'],
    'group-members' => ['users', 'Changed group members'], 'group-routing' => ['users', 'Changed category routing'],
    'org-save' => ['users', 'Saved an organization'], 'org-delete' => ['users', 'Deleted an organization'],
    'org-member' => ['users', 'Changed organization membership'], 'field-move' => ['settings', 'Reordered ticket fields'],
    // Workspace settings
    'workspace-rename' => ['settings', 'Renamed the workspace'], 'nav-save' => ['settings', 'Changed the bottom menu'],
    'category-create' => ['settings', 'Added a category'], 'category-delete' => ['settings', 'Deleted a category'],
    'status-create' => ['settings', 'Added a status'], 'status-update' => ['settings', 'Changed a status'],
    'status-delete' => ['settings', 'Deleted a status'], 'priority-create' => ['settings', 'Added a priority'],
    'priority-update' => ['settings', 'Changed a priority'], 'priority-delete' => ['settings', 'Deleted a priority'],
    'sla-save' => ['settings', 'Changed SLA targets'], 'policy-save' => ['settings', 'Changed the notification policy'],
    'email-save' => ['settings', 'Changed email settings'],
    'tag-create' => ['settings', 'Added a tag'], 'tag-rename' => ['settings', 'Renamed a tag'], 'tag-delete' => ['settings', 'Deleted a tag'],
    'canned-create' => ['settings', 'Added a canned reply'], 'canned-update' => ['settings', 'Changed a canned reply'],
    'canned-delete' => ['settings', 'Deleted a canned reply'], 'macro-create' => ['settings', 'Saved a macro'],
    'macro-delete' => ['settings', 'Deleted a macro'], 'rule-create' => ['settings', 'Added an automation'],
    'rule-toggle' => ['settings', 'Turned an automation on/off'], 'rule-delete' => ['settings', 'Deleted an automation'],
    'field-save' => ['settings', 'Saved a ticket field'], 'field-delete' => ['settings', 'Deleted a ticket field'],
    'csat-settings' => ['settings', 'Changed satisfaction surveys'],
    'kb-save' => ['content', 'Saved an article'], 'kb-delete' => ['content', 'Deleted an article'],
    // Integrations
    'apikey-create' => ['integrations', 'Created an API key'], 'apikey-revoke' => ['integrations', 'Revoked an API key'],
    'webhook-save' => ['integrations', 'Saved a webhook'], 'webhook-delete' => ['integrations', 'Deleted a webhook'],
    'webform-save' => ['integrations', 'Changed the support form'],
    'branding-save' => ['settings', 'Changed the app name or logo'],
    'template-save' => ['settings', 'Saved a ticket template'], 'ticket-form-save' => ['settings', 'Changed the new-ticket form'],
    'notify-matrix-save' => ['settings', 'Changed who gets which notifications'], 'notify-rules-save' => ['settings', 'Changed notification timing'],
    'notify-templates-save' => ['settings', 'Changed notification email texts'], 'template-delete' => ['settings', 'Deleted a ticket template'],
    // These two redirect, so they log themselves (src/actions/platform.php); listed here for labels.
    'impersonate' => ['security', 'Signed in as another user'], 'impersonate-stop' => ['security', 'Returned to their own account'],
    'report-schedule-save' => ['reports', 'Saved a scheduled report'], 'report-schedule-delete' => ['reports', 'Deleted a scheduled report'],
    // Tickets (bulk / destructive only; single changes are in each ticket's timeline)
    'ticket-bulk' => ['tickets', 'Bulk-edited tickets'], 'ticket-merge' => ['tickets', 'Merged tickets'],
];

const AUDIT_CATEGORIES = [
    'users' => 'Users & groups', 'roles' => 'Roles', 'security' => 'Security & sign-in', 'settings' => 'Settings',
    'content' => 'Knowledge base', 'integrations' => 'Integrations', 'reports' => 'Reports', 'tickets' => 'Tickets',
];

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Write one audit entry (never throws into the caller). */
function audit(string $tenantId, ?array $actor, string $action, string $summary, ?string $targetType = null, ?string $targetId = null, array $meta = []): void
{
    try {
        db_insert('AuditLog', [
            'id' => cuid(), 'tenantId' => $tenantId, 'actorId' => $actor['id'] ?? null,
            'actorName' => $actor ? mb_substr($actor['name'], 0, 120) : null, 'action' => $action,
            'targetType' => $targetType, 'targetId' => $targetId, 'summary' => mb_substr($summary, 0, 500),
            'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ip' => client_ip(), 'createdAt' => now_iso(),
        ]);
    } catch (Throwable $e) {
        error_log('[audit] ' . $e->getMessage());
    }
}

/** Called by the dispatcher after a successful POST action. */
function audit_action(string $name, array $in, array $state): void
{
    if (!isset(AUDITED_ACTIONS[$name]) || isset($state['error'])) return;
    $user = current_user();
    if (!$user) return;
    $tenantId = effective_tenant_id($user);
    if (!$tenantId) return;
    [, $label] = AUDITED_ACTIONS[$name];
    // A readable line: the action's own success message, or label + the thing's name.
    $what = (string) ($in['name'] ?? $in['label'] ?? $in['title'] ?? '');
    $summary = is_string($state['success'] ?? null) ? $state['success'] : $label . ($what !== '' ? ': ' . $what : '');
    // Never store secrets or the one-time links shown in messages.
    $summary = preg_replace('#https?://\S+#', '[link]', $summary);
    $meta = [];
    foreach ($in as $k => $v) {
        if ($k[0] === '_' || in_array($k, SECRET_INPUTS, true)) continue;
        if (is_array($v)) { $meta[$k] = array_slice(array_keys(array_filter($v, fn ($x) => $x !== '' && $x !== null)), 0, 40); continue; }
        $v = (string) $v;
        if ($v === '') continue;
        $meta[$k] = mb_strlen($v) > 160 ? mb_substr($v, 0, 160) . '…' : $v;
    }
    $target = $in['userId'] ?? $in['id'] ?? $in['ticketId'] ?? null;
    audit($tenantId, $user, $name, $summary, explode('-', $name)[0], is_string($target) ? $target : null, $meta);
}

/** Inputs never written to the audit log. */
const SECRET_INPUTS = ['password', 'confirm', 'smtpPassword', 'msClientSecret', 'token', 'secret', 'body', 'description'];

/* ---------- Rate limiting for public endpoints ---------- */

/** Record a hit; true while the bucket stays within $max hits per $windowSec. */
function rate_allow(string $bucket, int $max, int $windowSec): bool
{
    $since = now_iso(-$windowSec);
    $n = (int) q_val('SELECT COUNT(*) FROM "RateHit" WHERE "bucket" = ? AND "createdAt" >= ?', [$bucket, $since]);
    if ($n >= $max) return false;
    db_insert('RateHit', ['id' => cuid(), 'bucket' => $bucket, 'createdAt' => now_iso()]);
    if (random_int(1, 200) === 1) q('DELETE FROM "RateHit" WHERE "createdAt" < ?', [now_iso(-86400)]);
    return true;
}
