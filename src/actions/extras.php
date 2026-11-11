<?php
declare(strict_types=1);

/*
 * Groups, organizations, custom fields, CSAT, scheduled reports,
 * API keys, webhooks and the public support form.
 */

/** Tenant user holding a permission, or null. */
function ctx_with(string $perm): ?array
{
    $ctx = require_tenant_user();
    return can($ctx[0], $perm) ? $ctx : null;
}

const NOT_ALLOWED = ['error' => 'You don’t have permission to do that'];

/* ---------- Agent groups ---------- */

function act_group_save(array $in): ?array
{
    if (!($ctx = ctx_with('groups.manage'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $id = str_in($in, 'id');
    $name = mb_substr(str_in($in, 'name'), 0, 60);
    if (mb_strlen($name) < 2) return ['error' => 'Group name is too short'];
    $mode = isset(GROUP_ASSIGN_MODES[$in['assignMode'] ?? '']) ? $in['assignMode'] : 'none';
    if (q_val('SELECT 1 FROM "AgentGroup" WHERE "tenantId" = ? AND "name" = ?' . ($id ? ' AND "id" <> ?' : ''), $id ? [$tenantId, $name, $id] : [$tenantId, $name])) {
        return ['error' => 'A group with that name already exists'];
    }
    $data = ['name' => $name, 'description' => mb_substr(str_in($in, 'description'), 0, 200) ?: null, 'color' => safe_color(str_in($in, 'color'), '#0ea5e9'), 'assignMode' => $mode];
    if ($id) {
        if (!q_val('SELECT 1 FROM "AgentGroup" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId])) return ['error' => 'Group not found'];
        db_update('AgentGroup', $data, '"id" = ?', [$id]);
    } else {
        $id = cuid();
        db_insert('AgentGroup', ['id' => $id, 'tenantId' => $tenantId, 'createdAt' => now_iso(), 'lastAssignedId' => null] + $data);
    }
    // Members: staff of this workspace only.
    if (array_key_exists('members', $in) || ($in['membersSent'] ?? '') === '1') {
        $want = array_values(array_unique(array_filter((array) ($in['members'] ?? []), 'is_string')));
        $valid = $want ? pluck(q_all('SELECT "id" FROM "User" WHERE "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\') AND "id" IN (' . in_ph($want) . ')', [$tenantId, ...$want]), 'id') : [];
        db_tx(function () use ($id, $valid) {
            q('DELETE FROM "AgentGroupMember" WHERE "groupId" = ?', [$id]);
            foreach ($valid as $uid) db_insert('AgentGroupMember', ['groupId' => $id, 'userId' => $uid, 'createdAt' => now_iso()]);
        });
    }
    return ['success' => "Group “{$name}” saved", 'back' => '/settings?group=' . $id . '#groups'];
}

function act_group_delete(array $in): ?array
{
    if (!($ctx = ctx_with('groups.manage'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $g = q_one('SELECT * FROM "AgentGroup" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    if (!$g) return ['error' => 'Group not found'];
    q('UPDATE "Ticket" SET "groupId" = NULL WHERE "tenantId" = ? AND "groupId" = ?', [$tenantId, $g['id']]);
    q('UPDATE "Category" SET "groupId" = NULL WHERE "tenantId" = ? AND "groupId" = ?', [$tenantId, $g['id']]);
    q('DELETE FROM "AgentGroup" WHERE "id" = ?', [$g['id']]);
    return ['success' => "Deleted the group “{$g['name']}”", 'back' => '/settings#groups'];
}

/** Category → group routing for new tickets. */
function act_group_routing(array $in): ?array
{
    if (!($ctx = ctx_with('groups.manage'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $groups = array_column(tenant_groups($tenantId), 'id', 'id');
    foreach ((array) ($in['route'] ?? []) as $catId => $gid) {
        $gid = is_string($gid) && isset($groups[$gid]) ? $gid : null;
        q('UPDATE "Category" SET "groupId" = ? WHERE "id" = ? AND "tenantId" = ?', [$gid, (string) $catId, $tenantId]);
    }
    return ['success' => 'Routing saved: new tickets in these categories go to their group'];
}

/* ---------- Customer organizations ---------- */

function act_org_save(array $in): ?array
{
    if (!($ctx = ctx_with('orgs.manage'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $id = str_in($in, 'id');
    $name = mb_substr(str_in($in, 'name'), 0, 80);
    if (mb_strlen($name) < 2) return ['error' => 'Organization name is too short'];
    $domains = org_domains(str_in($in, 'domain'));
    foreach ($domains as $d) if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d)) return ['error' => "“{$d}” doesn’t look like a domain"];
    if (q_val('SELECT 1 FROM "Organization" WHERE "tenantId" = ? AND "name" = ?' . ($id ? ' AND "id" <> ?' : ''), $id ? [$tenantId, $name, $id] : [$tenantId, $name])) {
        return ['error' => 'An organization with that name already exists'];
    }
    $data = ['name' => $name, 'domain' => $domains ? implode(', ', $domains) : null, 'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000) ?: null];
    if ($id) {
        if (!q_val('SELECT 1 FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId])) return ['error' => 'Organization not found'];
        db_update('Organization', $data, '"id" = ?', [$id]);
    } else {
        $id = cuid();
        db_insert('Organization', ['id' => $id, 'tenantId' => $tenantId, 'createdAt' => now_iso()] + $data);
    }
    $added = 0;
    if (($in['attach'] ?? '') === 'on' && $domains) {
        foreach ($domains as $d) {
            foreach (q_all('SELECT "id" FROM "User" u WHERE "tenantId" = ? AND "role" = \'CUSTOMER\' AND LOWER("email") LIKE ? AND NOT EXISTS (SELECT 1 FROM "UserOrganization" x WHERE x."userId" = u."id" AND x."organizationId" = ?)', [$tenantId, '%@' . $d, $id]) as $cu) {
                add_user_org($cu['id'], $id);
                $added++;
            }
        }
    }
    return ['success' => "Saved “{$name}”" . ($added ? " and added $added existing customer(s)" : ''), 'back' => '/settings?org=' . $id . '#orgs'];
}

function act_org_delete(array $in): ?array
{
    if (!($ctx = ctx_with('orgs.manage'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $o = q_one('SELECT * FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    if (!$o) return ['error' => 'Organization not found'];
    $members = pluck(q_all('SELECT "userId" FROM "UserOrganization" WHERE "organizationId" = ?', [$o['id']]), 'userId');
    q('DELETE FROM "UserOrganization" WHERE "organizationId" = ?', [$o['id']]);
    q('UPDATE "User" SET "organizationId" = NULL, "orgManager" = 0 WHERE "organizationId" = ?', [$o['id']]);
    q('DELETE FROM "Organization" WHERE "id" = ?', [$o['id']]);
    foreach ($members as $uid) sync_primary_org($uid);
    return ['success' => "Deleted “{$o['name']}”. Its people stay as customers.", 'back' => '/settings#orgs'];
}

/** Add/remove a customer from an organization, or toggle manager. */
function act_org_member(array $in): ?array
{
    if (!($ctx = ctx_with('orgs.manage'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $u = q_one('SELECT * FROM "User" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'userId'), $tenantId]);
    if (!$u) return ['error' => 'Person not found'];
    $orgId = str_in($in, 'organizationId');
    $org = q_one('SELECT "id","name" FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [$orgId, $tenantId]);
    if (!$org) return ['error' => 'Organization not found'];
    if (($in['op'] ?? '') === 'remove') {
        remove_user_org($u['id'], $org['id']);
        return ['success' => "{$u['name']} removed from {$org['name']}"];
    }
    // Add (or change manager rights); other organizations of the person stay as they are.
    add_user_org($u['id'], $org['id'], $u['role'] === 'CUSTOMER' && ($in['orgManager'] ?? '') === '1');
    return ['success' => "{$u['name']} updated in {$org['name']}"];
}

/* ---------- Custom ticket fields ---------- */

function act_field_save(array $in): ?array
{
    if (!($ctx = ctx_with('settings.fields'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $id = str_in($in, 'id');
    $label = mb_substr(str_in($in, 'label'), 0, 60);
    if (mb_strlen($label) < 2) return ['error' => 'Field name is too short'];
    $type = isset(FIELD_TYPES[$in['type'] ?? '']) ? $in['type'] : 'text';
    $opts = [];
    if ($type === 'select') {
        $opts = array_values(array_unique(array_filter(array_map(fn ($o) => mb_substr(trim($o), 0, 80), preg_split('/\r?\n|,/', (string) ($in['options'] ?? ''))))));
        if (count($opts) < 2) return ['error' => 'A dropdown needs at least two options (one per line)'];
    }
    $categoryId = str_in($in, 'categoryId') ?: null;
    if ($categoryId && !q_val('SELECT 1 FROM "Category" WHERE "id" = ? AND "tenantId" = ?', [$categoryId, $tenantId])) return ['error' => 'Invalid category'];
    $data = [
        'label' => $label, 'type' => $type, 'options' => json_encode($opts, JSON_UNESCAPED_UNICODE),
        'required' => ($in['required'] ?? '') === 'on' ? 1 : 0, 'customerVisible' => ($in['customerVisible'] ?? '') === 'on' ? 1 : 0,
        'categoryId' => $categoryId,
    ];
    if ($id) {
        if (!q_val('SELECT 1 FROM "CustomField" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId])) return ['error' => 'Field not found'];
        db_update('CustomField', $data, '"id" = ?', [$id]);
        return ['success' => "Saved “{$label}”"];
    }
    $order = (int) q_val('SELECT COALESCE(MAX("order"), 0) FROM "CustomField" WHERE "tenantId" = ?', [$tenantId]) + 1;
    db_insert('CustomField', ['id' => cuid(), 'tenantId' => $tenantId, 'key' => field_key_from($label, $tenantId), 'order' => $order, 'createdAt' => now_iso()] + $data);
    return ['success' => "Added “{$label}”"];
}

function act_field_delete(array $in): ?array
{
    if (!($ctx = ctx_with('settings.fields'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $f = q_one('SELECT * FROM "CustomField" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    if (!$f) return ['error' => 'Field not found'];
    q('DELETE FROM "CustomField" WHERE "id" = ?', [$f['id']]);
    return ['success' => "Deleted “{$f['label']}” and its values"];
}

function act_field_move(array $in): ?array
{
    if (!($ctx = ctx_with('settings.fields'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $ids = pluck(tenant_fields($tenantId), 'id');
    $i = array_search(str_in($in, 'id'), $ids, true);
    if ($i === false) return ['error' => 'Field not found'];
    $j = ($in['dir'] ?? '') === 'up' ? $i - 1 : $i + 1;
    if ($j < 0 || $j >= count($ids)) return ['ok' => true];
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    foreach ($ids as $n => $fid) q('UPDATE "CustomField" SET "order" = ? WHERE "id" = ?', [$n + 1, $fid]);
    return ['ok' => true];
}

/** Edit a ticket's field values (staff with "edit"). */
function act_ticket_fields(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $ticket = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$ticket || !is_staff($user['role']) || !ticket_visible($ticket, $user) || !can($user, 'tickets.fields')) return NOT_ALLOWED;
    $values = validate_fields(fields_for($tenantId, $ticket['categoryId'], false), (array) ($in['cf'] ?? []), false);
    if (is_string($values)) return ['error' => $values];
    $changed = save_field_values($ticket['id'], $tenantId, $values);
    if (!$changed) return ['ok' => true];
    $defs = key_by(tenant_fields($tenantId), 'label');
    $parts = [];
    foreach ($changed as $label => [$old, $new]) $parts[] = "$label: " . field_display($defs[$label], $old) . ' → ' . field_display($defs[$label], $new);
    log_event($tenantId, $ticket['id'], $user['id'], 'FIELDS_CHANGED', 'updated ' . implode('; ', $parts));
    db_update('Ticket', ['updatedAt' => now_iso()], '"id" = ?', [$ticket['id']]);
    reindex_ticket($ticket['id']);
    webhook_emit($tenantId, 'ticket.updated', ['ticket' => api_ticket_brief(find_ticket($ticket['id'], $tenantId)), 'changes' => ['fields' => ['from' => array_map(fn ($c) => $c[0], $changed), 'to' => array_map(fn ($c) => $c[1], $changed)]], 'actor' => ['id' => $user['id'], 'name' => $user['name']]]);
    return ['ok' => true];
}

/* ---------- CSAT ---------- */

function act_csat_settings(array $in): ?array
{
    if (!($ctx = ctx_with('settings.csat'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $q = mb_substr(str_in($in, 'question'), 0, 200);
    tenant_setting_set($tenantId, 'csat', ['enabled' => ($in['enabled'] ?? '') === 'on', 'question' => $q ?: 'How would you rate the support you received?']);
    return ['success' => ($in['enabled'] ?? '') === 'on' ? 'Satisfaction surveys are on' : 'Satisfaction surveys are off'];
}

/** Answer a survey: by emailed token (no sign-in), or as the signed-in requester. */
function act_csat_answer(array $in): ?array
{
    $rating = (int) ($in['rating'] ?? 0);
    if ($rating < 1 || $rating > 5) return ['error' => 'Pick a rating from 1 to 5'];
    $token = str_in($in, 'token');
    if ($token !== '') {
        $s = q_one('SELECT * FROM "CsatSurvey" WHERE "tokenHash" = ?', [hash('sha256', $token)]);
        if (!$s) return ['error' => 'This survey link is invalid'];
        if ($s['answeredAt'] && $s['answeredAt'] < now_iso(-7 * 86400)) return ['error' => 'This survey has closed'];
    } else {
        [$user, $tenantId] = require_tenant_user();
        $ticket = find_ticket(str_in($in, 'ticketId'), $tenantId);
        if (!$ticket || $ticket['requesterId'] !== $user['id']) return NOT_ALLOWED;
        $s = csat_pending($ticket['id']) ?? csat_latest_answer($ticket['id']);
        if (!$s) return ['error' => 'There is no survey for this ticket'];
    }
    csat_answer($s, $rating, (string) ($in['comment'] ?? ''));
    return ['success' => 'Thanks for your feedback!'];
}

/* ---------- Scheduled reports ---------- */

function act_report_schedule_save(array $in): ?array
{
    if (!($ctx = ctx_with('reports.schedule'))) return NOT_ALLOWED;
    [$user, $tenantId] = $ctx;
    $id = str_in($in, 'id');
    $name = mb_substr(str_in($in, 'name'), 0, 80) ?: 'Helpdesk report';
    $freq = in_array($in['frequency'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $in['frequency'] : 'weekly';
    $days = in_array((int) ($in['days'] ?? 0), [1, 7, 30, 90], true) ? (int) $in['days'] : ($freq === 'daily' ? 1 : ($freq === 'monthly' ? 30 : 7));
    $want = array_values(array_unique(array_filter((array) ($in['recipients'] ?? []), 'is_string')));
    $staff = $want ? q_all('SELECT * FROM "User" WHERE "tenantId" = ? AND "active" = 1 AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\') AND "id" IN (' . in_ph($want) . ')', [$tenantId, ...$want]) : [];
    $recips = pluck(array_filter($staff, fn ($u) => can($u, 'reports.view')), 'id');
    if (!$recips) return ['error' => 'Pick at least one recipient who can view reports'];
    $data = [
        'name' => $name, 'frequency' => $freq, 'weekday' => max(1, min(7, (int) ($in['weekday'] ?? 1))), 'hour' => max(0, min(23, (int) ($in['hour'] ?? 8))),
        'days' => $days, 'recipients' => json_encode($recips), 'active' => ($in['active'] ?? 'on') === 'on' ? 1 : 0,
    ];
    $data['nextRunAt'] = report_next_run($data);
    if ($id) {
        if (!q_val('SELECT 1 FROM "ScheduledReport" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId])) return ['error' => 'Schedule not found'];
        db_update('ScheduledReport', $data, '"id" = ?', [$id]);
    } else {
        db_insert('ScheduledReport', ['id' => cuid(), 'tenantId' => $tenantId, 'createdById' => $user['id'], 'createdAt' => now_iso(), 'lastSentAt' => null] + $data);
    }
    return ['success' => "“{$name}” scheduled — next on " . format_date($data['nextRunAt'])];
}

function act_report_schedule_delete(array $in): ?array
{
    if (!($ctx = ctx_with('reports.schedule'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $n = q('DELETE FROM "ScheduledReport" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId])->rowCount();
    return $n ? ['success' => 'Schedule deleted'] : ['error' => 'Schedule not found'];
}

function act_report_schedule_send(array $in): ?array
{
    if (!($ctx = ctx_with('reports.schedule'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $s = q_one('SELECT * FROM "ScheduledReport" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    if (!$s) return ['error' => 'Schedule not found'];
    $r = report_send($s);
    if ($r['skipped']) return ['error' => $r['skipped'] . ' — use “Preview” to see the email.'];
    db_update('ScheduledReport', ['lastSentAt' => now_iso()], '"id" = ?', [$s['id']]);
    return ['success' => "Sent to {$r['sent']} recipient(s)"];
}

/* ---------- API keys & webhooks ---------- */

function act_apikey_create(array $in): ?array
{
    if (!($ctx = ctx_with('settings.api'))) return NOT_ALLOWED;
    [$user, $tenantId] = $ctx;
    $name = mb_substr(str_in($in, 'name'), 0, 60);
    if (mb_strlen($name) < 2) return ['error' => 'Give the key a name (e.g. “CRM sync”)'];
    $scope = ($in['scope'] ?? '') === 'write' ? 'write' : 'read';
    $prefix = substr(bin2hex(random_bytes(4)), 0, 8);
    $token = 'tos_' . $prefix . '_' . bin2hex(random_bytes(20));
    db_insert('ApiKey', [
        'id' => cuid(), 'tenantId' => $tenantId, 'userId' => $user['id'], 'name' => $name, 'prefix' => $prefix,
        'tokenHash' => hash('sha256', $token), 'scope' => $scope, 'lastUsedAt' => null, 'createdAt' => now_iso(), 'revokedAt' => null,
    ]);
    return ['success' => "Key “{$name}” created. Copy it now — it won’t be shown again.", 'token' => $token];
}

function act_apikey_revoke(array $in): ?array
{
    if (!($ctx = ctx_with('settings.api'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $n = q('UPDATE "ApiKey" SET "revokedAt" = ? WHERE "id" = ? AND "tenantId" = ? AND "revokedAt" IS NULL', [now_iso(), str_in($in, 'id'), $tenantId])->rowCount();
    return $n ? ['success' => 'Key revoked. Apps using it stop working immediately.'] : ['error' => 'Key not found'];
}

function act_webhook_save(array $in): ?array
{
    if (!($ctx = ctx_with('settings.api'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $id = str_in($in, 'id');
    $name = mb_substr(str_in($in, 'name'), 0, 60) ?: 'Webhook';
    $url = str_in($in, 'url');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) return ['error' => 'Enter a valid http(s) URL'];
    if (!webhook_url_allowed($url)) return ['error' => 'That address is not reachable from the internet'];
    $format = isset(WEBHOOK_FORMATS[$in['format'] ?? '']) ? $in['format'] : 'json';
    $events = array_values(array_intersect(array_keys(WEBHOOK_EVENTS), array_keys(array_filter((array) ($in['events'] ?? []), fn ($v) => $v === 'on'))));
    if (!$events) return ['error' => 'Pick at least one event'];
    $data = ['name' => $name, 'url' => $url, 'format' => $format, 'events' => json_encode($events), 'active' => ($in['active'] ?? 'on') === 'on' ? 1 : 0];
    if ($id) {
        if (!q_val('SELECT 1 FROM "Webhook" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId])) return ['error' => 'Webhook not found'];
        if (($in['rotate'] ?? '') === 'on') $data['secret'] = 'whsec_' . bin2hex(random_bytes(20));
        if ($data['active']) $data['failCount'] = 0;
        db_update('Webhook', $data, '"id" = ?', [$id]);
        return ['success' => "Webhook “{$name}” saved" . (isset($data['secret']) ? ' with a new signing secret' : '')];
    }
    db_insert('Webhook', ['id' => cuid(), 'tenantId' => $tenantId, 'secret' => 'whsec_' . bin2hex(random_bytes(20)), 'failCount' => 0, 'lastStatus' => null, 'lastError' => null, 'lastAt' => null, 'createdAt' => now_iso()] + $data);
    return ['success' => "Webhook “{$name}” added"];
}

function act_webhook_delete(array $in): ?array
{
    if (!($ctx = ctx_with('settings.api'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $n = q('DELETE FROM "Webhook" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId])->rowCount();
    return $n ? ['success' => 'Webhook deleted'] : ['error' => 'Webhook not found'];
}

/** Send a sample event right now and report what the endpoint answered. */
function act_webhook_test(array $in): ?array
{
    if (!($ctx = ctx_with('settings.api'))) return NOT_ALLOWED;
    [$user, $tenantId] = $ctx;
    $h = q_one('SELECT * FROM "Webhook" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    if (!$h) return ['error' => 'Webhook not found'];
    $sample = q_one('SELECT * FROM "Ticket" WHERE "tenantId" = ? ORDER BY "createdAt" DESC LIMIT 1', [$tenantId]);
    $payload = ['test' => true, 'ticket' => $sample ? api_ticket_brief($sample) : ['number' => 0, 'subject' => 'Test ticket', 'priority' => 'MEDIUM', 'requester' => ['name' => $user['name']]]];
    $id = cuid();
    db_insert('WebhookDelivery', [
        'id' => $id, 'webhookId' => $h['id'], 'tenantId' => $tenantId, 'event' => 'ticket.created',
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => 'pending', 'attempts' => WEBHOOK_MAX_ATTEMPTS - 1,
        'responseCode' => null, 'error' => null, 'nextAttemptAt' => now_iso(), 'createdAt' => now_iso(), 'deliveredAt' => null,
    ]);
    $d = q_one('SELECT d.*, w."url", w."secret", w."format", w."active", w."failCount" FROM "WebhookDelivery" d JOIN "Webhook" w ON w."id" = d."webhookId" WHERE d."id" = ?', [$id]);
    $ok = webhook_send($d, 8);
    $row = q_one('SELECT "responseCode","error" FROM "WebhookDelivery" WHERE "id" = ?', [$id]);
    return $ok ? ['success' => 'Test delivered (HTTP ' . $row['responseCode'] . ')'] : ['error' => 'Test failed: ' . ($row['error'] ?? 'unknown error')];
}

/* ---------- Public support form & widget ---------- */

function webform_settings(string $tenantId): array
{
    $s = tenant_setting($tenantId, 'webform', []);
    return [
        'enabled' => (bool) ($s['enabled'] ?? false),
        'title' => (string) ($s['title'] ?? 'Contact support'),
        'intro' => (string) ($s['intro'] ?? 'Tell us what you need and we’ll get back to you by email.'),
        'showCategory' => (bool) ($s['showCategory'] ?? true),
        'color' => safe_color($s['color'] ?? '#4f46e5', '#4f46e5'),
    ];
}

function act_webform_save(array $in): ?array
{
    if (!($ctx = ctx_with('settings.webform'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    tenant_setting_set($tenantId, 'webform', [
        'enabled' => ($in['enabled'] ?? '') === 'on',
        'title' => mb_substr(str_in($in, 'title'), 0, 80) ?: 'Contact support',
        'intro' => mb_substr(str_in($in, 'intro'), 0, 300),
        'showCategory' => ($in['showCategory'] ?? '') === 'on',
        'color' => safe_color(str_in($in, 'color'), '#4f46e5'),
    ]);
    return ['success' => ($in['enabled'] ?? '') === 'on' ? 'Support form is live' : 'Support form is off'];
}

/** Signed timestamp so the form can't be replayed or posted instantly by bots. */
function webform_stamp(string $tenantId): string
{
    $ts = (string) time();
    return $ts . '.' . substr(hash_hmac('sha256', $tenantId . '|' . $ts, app_secret()), 0, 24);
}

function webform_stamp_ok(string $tenantId, string $stamp): bool
{
    [$ts, $sig] = array_pad(explode('.', $stamp, 2), 2, '');
    if (!ctype_digit($ts) || !hash_equals(substr(hash_hmac('sha256', $tenantId . '|' . $ts, app_secret()), 0, 24), $sig)) return false;
    $age = time() - (int) $ts;
    return $age >= 3 && $age <= 6 * 3600;
}

function app_secret(): string
{
    return (string) (env('AUTH_SECRET', '') ?: 'tos-dev-secret');
}

/**
 * Handle POST /support/{slug}. Creates (or finds) the customer by email and
 * opens a ticket. Returns ['ok' => ticket] or ['error' => message].
 */
function support_submit(array $tenant, array $in): array
{
    $tenantId = $tenant['id'];
    $cfg = webform_settings($tenantId);
    if (!$cfg['enabled']) return ['error' => 'This form is not available'];
    // Bots: honeypot field and minimum fill time.
    if (trim((string) ($in['website'] ?? '')) !== '' || !webform_stamp_ok($tenantId, (string) ($in['stamp'] ?? ''))) {
        return ['error' => 'Please wait a moment and try again'];
    }
    if (!rate_allow('form:' . $tenantId . ':' . client_ip(), 5, 600)) return ['error' => 'Too many requests. Please try again in a few minutes.'];

    $name = mb_substr(str_in($in, 'name'), 0, 80);
    $email = strtolower(str_in($in, 'email'));
    if (mb_strlen($name) < 2) return ['error' => 'Please enter your name'];
    if (!is_valid_email($email)) return ['error' => 'Please enter a valid email address'];
    $text = trim((string) ($in['message'] ?? ''));
    if (mb_strlen($text) < 5) return ['error' => 'Please describe the issue'];
    $categoryId = $cfg['showCategory'] ? str_in($in, 'categoryId') : '';

    $user = q_one('SELECT * FROM "User" WHERE "email" = ?', [$email]);
    if ($user && $user['tenantId'] !== $tenantId) return ['error' => 'This email can’t be used here. Please use another address.'];
    if ($user && !(int) $user['active']) return ['error' => 'This account is deactivated'];
    $isNew = false;
    if (!$user) {
        $org = org_for_email($tenantId, $email);
        $count = (int) q_val('SELECT COUNT(*) FROM "User" WHERE "tenantId" = ?', [$tenantId]);
        $user = [
            'id' => cuid(), 'email' => $email, 'name' => $name, 'passwordHash' => hash_password(bin2hex(random_bytes(24))),
            'role' => 'CUSTOMER', 'active' => 1, 'organizationId' => $org['id'] ?? null, 'orgManager' => 0,
            'avatarColor' => AVATAR_COLORS[$count % count(AVATAR_COLORS)], 'createdAt' => now_iso(), 'updatedAt' => now_iso(), 'tenantId' => $tenantId,
        ];
        db_insert('User', $user); if (!empty($user['organizationId'])) add_user_org($user['id'], $user['organizationId']);
        $isNew = true;
    }
    $html = '<p>' . nl2br(e(mb_substr($text, 0, 20000)), false) . '</p>';
    $ticket = create_ticket($tenantId, $user, [
        'subject' => str_in($in, 'subject'), 'description' => $html, 'categoryId' => $categoryId,
        'fields' => (array) ($in['cf'] ?? []), 'source' => 'form',
    ]);
    if (is_string($ticket)) return ['error' => $ticket];

    // Confirmation: new customers get a link to set a password and follow the ticket.
    $link = app_url() . '/tickets/' . $ticket['id'];
    if ($isNew) {
        $r = send_reset_link($user, 72 * 3600, true);
        $link = $r['link'];
    }
    send_tenant_email_safe($tenantId, [
        'to' => $email,
        'subject' => "We received your request (#{$ticket['number']})",
        'html' => '<p>Hi ' . e($name) . ',</p><p>Thanks for contacting ' . e($tenant['name']) . '. Your ticket <strong>#' . (int) $ticket['number'] . ' ' . e($ticket['subject']) . '</strong> is open and an agent will reply soon.</p>'
            . '<p><a href="' . e($link) . '">' . ($isNew ? 'Set a password to follow your ticket' : 'View your ticket') . '</a></p>',
    ]);
    return ['ok' => $ticket];
}
