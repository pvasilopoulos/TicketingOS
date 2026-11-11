<?php
declare(strict_types=1);

/*
 * REST API v1 — JSON over HTTPS, authenticated with an API key:
 *   Authorization: Bearer tos_xxxxxxxx_…
 *
 * A key acts as the admin/agent who created it (their permissions apply) and
 * has a scope: "read" (GET only) or "write". 120 requests per minute per key.
 */

function api_error(int $status, string $code, string $message): never
{
    json_out(['error' => ['code' => $code, 'message' => $message]], $status);
}

/** Authenticate the request; returns [user, tenantId, key]. */
function api_auth(): array
{
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+(tos_[a-f0-9]{8}_[a-f0-9]{40})$/i', trim($h), $m)) {
        api_error(401, 'unauthorized', 'Send an API key: Authorization: Bearer tos_…');
    }
    $key = q_one('SELECT * FROM "ApiKey" WHERE "tokenHash" = ? AND "revokedAt" IS NULL', [hash('sha256', $m[1])]);
    if (!$key) api_error(401, 'unauthorized', 'Invalid or revoked API key');
    $user = q_one('SELECT * FROM "User" WHERE "id" = ? AND "tenantId" = ? AND "active" = 1', [$key['userId'], $key['tenantId']]);
    if (!$user || !is_staff($user['role'])) api_error(403, 'forbidden', 'The owner of this key can no longer use the API');
    if (!rate_allow('api:' . $key['id'], 120, 60)) {
        header('Retry-After: 60');
        api_error(429, 'rate_limited', 'Too many requests (limit 120 per minute)');
    }
    if (!$key['lastUsedAt'] || $key['lastUsedAt'] < now_iso(-60)) db_update('ApiKey', ['lastUsedAt' => now_iso()], '"id" = ?', [$key['id']]);
    return [$user, $key['tenantId'], $key];
}

function api_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) api_error(400, 'invalid_json', 'The request body must be a JSON object');
    return $data;
}

function api_need(array $user, string $perm): void
{
    if (!can($user, $perm)) api_error(403, 'forbidden', "The key owner lacks the “{$perm}” permission");
}

/** Full ticket JSON (brief + fields + tags). */
function api_ticket_full(array $t): array
{
    $out = api_ticket_brief($t);
    $out['description'] = $t['description'];
    $out['fields'] = ticket_field_values($t['id']) ?: (object) [];
    $out['tags'] = pluck(q_all('SELECT g."name" FROM "TicketTag" tt JOIN "Tag" g ON g."id" = tt."tagId" WHERE tt."ticketId" = ? ORDER BY g."name"', [$t['id']]), 'name');
    $out['assignee'] = $t['assigneeId'] ? q_one('SELECT "id","name","email" FROM "User" WHERE "id" = ?', [$t['assigneeId']]) : null;
    $out['assignees'] = array_map(fn ($u) => ['id' => $u['id'], 'name' => $u['name']], ticket_assignees($t));
    $csat = csat_latest_answer($t['id']);
    $out['satisfaction'] = $csat ? ['rating' => (int) $csat['rating'], 'comment' => $csat['comment'], 'answeredAt' => $csat['answeredAt']] : null;
    return $out;
}

function api_find_ticket(string $ref, array $user, string $tenantId): array
{
    $t = ctype_digit($ref)
        ? q_one('SELECT * FROM "Ticket" WHERE "tenantId" = ? AND "number" = ?', [$tenantId, (int) $ref])
        : q_one('SELECT * FROM "Ticket" WHERE "tenantId" = ? AND "id" = ?', [$tenantId, $ref]);
    if (!$t || !ticket_visible($t, $user)) api_error(404, 'not_found', 'Ticket not found');
    return $t;
}

/** Resolve a user reference (id or email) inside the tenant. */
function api_user_ref(string $tenantId, mixed $ref): ?array
{
    if (!is_string($ref) || $ref === '') return null;
    return q_one('SELECT * FROM "User" WHERE "tenantId" = ? AND (' . (str_contains($ref, '@') ? 'LOWER("email") = ?' : '"id" = ?') . ')', [$tenantId, str_contains($ref, '@') ? strtolower($ref) : $ref]);
}

function api_v1(string $method, string $path): never
{
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
    if ($method === 'OPTIONS') { http_response_code(204); throw new HttpExit(); }
    [$user, $tenantId, $key] = api_auth();
    if ($method !== 'GET' && $key['scope'] !== 'write') api_error(403, 'read_only_key', 'This key is read-only');
    $route = substr($path, strlen('/api/v1'));

    // GET /me
    if ($route === '/me' && $method === 'GET') {
        $tenant = q_one('SELECT "id","name","slug" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
        json_out(['workspace' => $tenant, 'actingAs' => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role']], 'scope' => $key['scope'], 'permissions' => user_permissions($user, $tenantId)]);
    }

    // GET /tickets
    if ($route === '/tickets' && $method === 'GET') {
        $statuses = tenant_statuses($tenantId);
        $params = [];
        $sp = array_intersect_key($_GET, array_flip(['q', 'status', 'priority', 'category', 'group', 'org', 'source', 'assignee', 'tag', 'overdue']));
        $where = ticket_filter_sql($user, $tenantId, $sp, $params, closed_status_keys($statuses));
        if (!empty($_GET['updated_since'])) { $where .= ' AND t."updatedAt" >= ?'; $params[] = (string) $_GET['updated_since']; }
        $per = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $total = (int) q_val("SELECT COUNT(*) FROM \"Ticket\" t WHERE $where", $params);
        $rows = q_all("SELECT t.* FROM \"Ticket\" t WHERE $where ORDER BY t.\"updatedAt\" DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
        json_out(['data' => array_map('api_ticket_brief', $rows), 'meta' => ['page' => $page, 'perPage' => $per, 'total' => $total, 'pages' => (int) ceil($total / $per)]]);
    }

    // POST /tickets
    if ($route === '/tickets' && $method === 'POST') {
        $b = api_body();
        $requester = $user;
        if (!empty($b['requester']['email'])) {
            $email = strtolower((string) $b['requester']['email']);
            if (!is_valid_email($email)) api_error(422, 'invalid', 'requester.email is not a valid email');
            $requester = q_one('SELECT * FROM "User" WHERE "email" = ?', [$email]);
            if ($requester && $requester['tenantId'] !== $tenantId) api_error(422, 'invalid', 'requester.email belongs to another workspace');
            if (!$requester) {
                api_need($user, 'users.invite');
                $count = (int) q_val('SELECT COUNT(*) FROM "User" WHERE "tenantId" = ?', [$tenantId]);
                $requester = [
                    'id' => cuid(), 'email' => $email, 'name' => mb_substr((string) ($b['requester']['name'] ?? strstr($email, '@', true)), 0, 80),
                    'passwordHash' => hash_password(bin2hex(random_bytes(24))), 'role' => 'CUSTOMER', 'active' => 1,
                    'organizationId' => org_for_email($tenantId, $email)['id'] ?? null, 'orgManager' => 0,
                    'avatarColor' => AVATAR_COLORS[$count % count(AVATAR_COLORS)], 'createdAt' => now_iso(), 'updatedAt' => now_iso(), 'tenantId' => $tenantId,
                ];
                db_insert('User', $requester);
            }
        }
        $desc = (string) ($b['description'] ?? '');
        if ($desc !== '' && $desc === strip_tags($desc)) $desc = '<p>' . nl2br(e($desc), false) . '</p>';
        $categoryId = '';
        if (!empty($b['category'])) {
            $categoryId = (string) (q_val('SELECT "id" FROM "Category" WHERE "tenantId" = ? AND ("id" = ? OR LOWER("name") = ?)', [$tenantId, $b['category'], mb_strtolower((string) $b['category'])]) ?? '');
            if ($categoryId === '') api_error(422, 'invalid', 'Unknown category');
        }
        $t = create_ticket($tenantId, $requester, [
            'subject' => (string) ($b['subject'] ?? ''), 'description' => $desc, 'priority' => (string) ($b['priority'] ?? ''),
            'categoryId' => $categoryId, 'groupId' => (string) ($b['groupId'] ?? ''), 'fields' => (array) ($b['fields'] ?? []), 'source' => 'api',
        ], $user);
        if (is_string($t)) api_error(422, 'invalid', $t);
        http_response_code(201);
        json_out(['data' => api_ticket_full($t)], 201);
    }

    // /tickets/{ref}[/comments]
    if (preg_match('#^/tickets/([A-Za-z0-9]+)(/comments)?$#', $route, $m)) {
        $t = api_find_ticket($m[1], $user, $tenantId);
        $comments = !empty($m[2]);
        if (!$comments && $method === 'GET') json_out(['data' => api_ticket_full($t)]);
        if (!$comments && $method === 'PATCH') {
            $b = api_body();
            $in = [];
            foreach (['status', 'priority', 'groupId', 'categoryId'] as $k) if (array_key_exists($k, $b)) $in[$k] = (string) ($b[$k] ?? '');
            if (array_key_exists('assignee', $b)) {
                $a = $b['assignee'] === null || $b['assignee'] === '' ? null : api_user_ref($tenantId, $b['assignee']);
                if ($b['assignee'] && !$a) api_error(422, 'invalid', 'Unknown assignee');
                $in['assigneeId'] = $a['id'] ?? '';
            }
            if ($in && !update_ticket($t['id'], $in, $user, $tenantId)) api_error(422, 'not_applied', 'Nothing changed (invalid value or missing permission)');
            if (!empty($b['fields']) && is_array($b['fields'])) {
                api_need($user, 'tickets.fields');
                $values = validate_fields(fields_for($tenantId, $t['categoryId'], false), $b['fields'], false);
                if (is_string($values)) api_error(422, 'invalid', $values);
                if (save_field_values($t['id'], $tenantId, $values)) {
                    log_event($tenantId, $t['id'], $user['id'], 'FIELDS_CHANGED', 'updated ticket fields via the API');
                    reindex_ticket($t['id']);
                }
            }
            json_out(['data' => api_ticket_full(find_ticket($t['id'], $tenantId))]);
        }
        if ($comments && $method === 'GET') {
            $internal = can($user, 'tickets.internal');
            $rows = q_all('SELECT c."id", c."body", c."isInternal", c."createdAt", u."id" AS "authorId", u."name" AS "authorName", u."role" AS "authorRole"
                           FROM "Comment" c JOIN "User" u ON u."id" = c."authorId" WHERE c."ticketId" = ?' . ($internal ? '' : ' AND c."isInternal" = 0') . ' ORDER BY c."createdAt"', [$t['id']]);
            json_out(['data' => array_map(fn ($c) => ['id' => $c['id'], 'internal' => (bool) $c['isInternal'], 'body' => $c['body'], 'text' => html_to_text($c['body']),
                'author' => ['id' => $c['authorId'], 'name' => $c['authorName'], 'role' => $c['authorRole']], 'createdAt' => $c['createdAt']], $rows)]);
        }
        if ($comments && $method === 'POST') {
            $b = api_body();
            $internal = (bool) ($b['internal'] ?? false);
            api_need($user, $internal ? 'tickets.internal' : 'tickets.reply');
            $body = (string) ($b['body'] ?? '');
            if ($body !== '' && $body === strip_tags($body)) $body = '<p>' . nl2br(e($body), false) . '</p>';
            $body = sanitize_rich_html($body);
            if (mb_strlen(html_to_text($body)) < 1) api_error(422, 'invalid', 'body is required');
            $id = add_comment($t, $user, $tenantId, $body, $internal);
            json_out(['data' => ['id' => $id, 'internal' => $internal, 'body' => $body]], 201);
        }
        api_error(405, 'method_not_allowed', 'Method not allowed');
    }

    if ($route === '/users' && $method === 'GET') {
        api_need($user, 'users.*');
        $params = [$tenantId];
        $where = '"tenantId" = ?';
        if (in_array($_GET['role'] ?? '', TENANT_ROLES, true)) { $where .= ' AND "role" = ?'; $params[] = $_GET['role']; }
        if (!empty($_GET['q'])) { $where .= " AND (LOWER(\"name\") LIKE ? ESCAPE '!' OR LOWER(\"email\") LIKE ? ESCAPE '!')"; array_push($params, like_arg(mb_strtolower((string) $_GET['q'])), like_arg(mb_strtolower((string) $_GET['q']))); }
        $rows = q_all("SELECT \"id\",\"name\",\"email\",\"role\",\"customRoleId\",\"organizationId\",\"active\",\"lastLoginAt\",\"createdAt\" FROM \"User\" WHERE $where ORDER BY \"name\" LIMIT 200", $params);
        json_out(['data' => array_map(fn ($u) => ['active' => (bool) $u['active']] + $u, $rows)]);
    }
    if ($route === '/organizations' && $method === 'GET') {
        api_need($user, 'orgs.manage');
        json_out(['data' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name'], 'domains' => org_domains($o['domain']), 'createdAt' => $o['createdAt']], tenant_orgs($tenantId))]);
    }
    if ($route === '/groups' && $method === 'GET') {
        json_out(['data' => array_map(fn ($g) => ['id' => $g['id'], 'name' => $g['name'], 'assignMode' => $g['assignMode'], 'members' => pluck(group_members($g['id']), 'id')], tenant_groups($tenantId))]);
    }
    if ($route === '/fields' && $method === 'GET') {
        json_out(['data' => array_map(fn ($f) => ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'options' => $f['opts'], 'required' => (bool) $f['required'], 'categoryId' => $f['categoryId']], tenant_fields($tenantId))]);
    }
    if ($route === '/meta' && $method === 'GET') {
        json_out(['statuses' => array_map(fn ($s) => ['key' => $s['key'], 'label' => $s['label'], 'closed' => (bool) $s['isClosed']], tenant_statuses($tenantId)),
            'priorities' => array_map(fn ($p) => ['key' => $p['key'], 'label' => $p['label']], tenant_priorities($tenantId)),
            'categories' => q_all('SELECT "id","name" FROM "Category" WHERE "tenantId" = ? ORDER BY "name"', [$tenantId])]);
    }
    api_error(404, 'not_found', 'Unknown endpoint. See Settings → Integrations for the API reference.');
}
