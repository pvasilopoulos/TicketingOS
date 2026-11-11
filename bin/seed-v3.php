<?php
declare(strict_types=1);

/*
 * Demo data for groups, organizations, ticket fields, CSAT and the support form.
 * Idempotent: only fills tenants that have none of it yet.
 *   php bin/seed-v3.php
 * (bin/seed.php runs this automatically.)
 */

if (!defined('ROOT')) require __DIR__ . '/../src/bootstrap.php';

function seed_v3_for(string $tenantId): void
{
    $now = now_iso();
    $staff = q_all('SELECT "id","name","role" FROM "User" WHERE "tenantId" = ? AND "role" IN (\'ADMIN\',\'AGENT\') ORDER BY "createdAt"', [$tenantId]);
    $agents = array_values(array_filter($staff, fn ($u) => $u['role'] === 'AGENT'));
    $cats = array_column(q_all('SELECT "id","name" FROM "Category" WHERE "tenantId" = ?', [$tenantId]), 'id', 'name');

    if (!q_val('SELECT 1 FROM "AgentGroup" WHERE "tenantId" = ?', [$tenantId]) && $agents) {
        $defs = [
            ['Billing', 'Invoices, refunds and payments', '#f59e0b', 'round_robin', ['Billing']],
            ['Technical', 'Bugs, outages and integrations', '#0ea5e9', 'least_busy', ['Technical']],
        ];
        foreach ($defs as $i => [$name, $desc, $color, $mode, $routeCats]) {
            $gid = cuid();
            db_insert('AgentGroup', ['id' => $gid, 'tenantId' => $tenantId, 'name' => $name, 'description' => $desc, 'color' => $color, 'assignMode' => $mode, 'lastAssignedId' => null, 'createdAt' => $now]);
            $members = count($agents) > 1 ? [$agents[$i % count($agents)]] : $agents;
            if ($i === 1 && count($agents) > 1) $members = $agents;
            foreach ($members as $m) db_insert('AgentGroupMember', ['groupId' => $gid, 'userId' => $m['id'], 'createdAt' => $now]);
            foreach ($routeCats as $c) if (isset($cats[$c])) q('UPDATE "Category" SET "groupId" = ? WHERE "id" = ?', [$gid, $cats[$c]]);
            // Existing tickets of routed categories join the group.
            foreach ($routeCats as $c) if (isset($cats[$c])) q('UPDATE "Ticket" SET "groupId" = ? WHERE "tenantId" = ? AND "categoryId" = ? AND "groupId" IS NULL', [$gid, $tenantId, $cats[$c]]);
        }
    }

    if (!q_val('SELECT 1 FROM "Organization" WHERE "tenantId" = ?', [$tenantId])) {
        // One organization per customer email domain (most common first).
        $domains = [];
        foreach (q_all('SELECT "email" FROM "User" WHERE "tenantId" = ? AND "role" = \'CUSTOMER\' LIMIT 5000', [$tenantId]) as $u) {
            $d = email_domain($u['email']);
            $domains[$d] = ($domains[$d] ?? 0) + 1;
        }
        arsort($domains);
        foreach (array_slice($domains, 0, 3, true) as $d => $n) {
            $oid = cuid();
            $name = ucwords(str_replace(['-', '.com', '.dev', '.gr'], [' ', '', '', ''], $d));
            db_insert('Organization', ['id' => $oid, 'tenantId' => $tenantId, 'name' => $name, 'domain' => $d, 'notes' => 'Demo organization created from the email domain.', 'createdAt' => $now]);
            q('UPDATE "User" SET "organizationId" = ? WHERE "tenantId" = ? AND "role" = \'CUSTOMER\' AND LOWER("email") LIKE ?', [$oid, $tenantId, '%@' . $d]);
            // First person becomes the organization manager.
            $first = q_val('SELECT "id" FROM "User" WHERE "organizationId" = ? ORDER BY "createdAt" LIMIT 1', [$oid]);
            if ($first) q('UPDATE "User" SET "orgManager" = 1 WHERE "id" = ?', [$first]);
        }
    }

    if (!q_val('SELECT 1 FROM "CustomField" WHERE "tenantId" = ?', [$tenantId])) {
        $fields = [
            ['order_number', 'Order number', 'text', [], 0, 1, $cats['Billing'] ?? null],
            ['device', 'Device', 'select', ['Laptop', 'Phone', 'Tablet', 'Other'], 0, 1, $cats['Technical'] ?? null],
            ['internal_ref', 'Internal reference', 'text', [], 0, 0, null],
        ];
        foreach ($fields as $i => [$key, $label, $type, $opts, $req, $vis, $cat]) {
            db_insert('CustomField', ['id' => cuid(), 'tenantId' => $tenantId, 'key' => $key, 'label' => $label, 'type' => $type, 'options' => json_encode($opts),
                'required' => $req, 'customerVisible' => $vis, 'categoryId' => $cat, 'order' => $i + 1, 'createdAt' => $now]);
        }
    }
    if (tenant_setting($tenantId, 'csat') === null) tenant_setting_set($tenantId, 'csat', ['enabled' => true, 'question' => 'How would you rate the support you received?']);
    if (tenant_setting($tenantId, 'webform') === null) tenant_setting_set($tenantId, 'webform', ['enabled' => true, 'title' => 'Contact support', 'intro' => 'Tell us what you need and we’ll get back to you by email.', 'showCategory' => true, 'color' => '#4f46e5']);

    // A few answered surveys on closed tickets so reports have data.
    if (!q_val('SELECT 1 FROM "CsatSurvey" WHERE "tenantId" = ?', [$tenantId])) {
        $closed = closed_status_keys(tenant_statuses($tenantId));
        if ($closed) {
            $rows = q_all('SELECT "id","assigneeId","requesterId","closedAt" FROM "Ticket" WHERE "tenantId" = ? AND "status" IN (' . in_ph($closed) . ') AND "closedAt" IS NOT NULL ORDER BY "closedAt" DESC LIMIT 40', [$tenantId, ...$closed]);
            $comments = ['Quick and friendly, thanks!', 'Solved my problem in minutes.', 'Took a while but it works now.', null, null, 'Great support as always.', 'Had to explain it twice.'];
            foreach ($rows as $i => $t) {
                $rating = [5, 5, 4, 4, 5, 3, 4, 2, 5, 4][$i % 10];
                db_insert('CsatSurvey', ['id' => cuid(), 'tenantId' => $tenantId, 'ticketId' => $t['id'], 'agentId' => $t['assigneeId'], 'requesterId' => $t['requesterId'],
                    'tokenHash' => hash('sha256', bin2hex(random_bytes(16))), 'rating' => $rating, 'comment' => $comments[$i % count($comments)],
                    'createdAt' => $t['closedAt'], 'answeredAt' => $t['closedAt']]);
            }
        }
    }
}

foreach (q_all('SELECT "id","slug" FROM "Tenant"') as $t) {
    seed_v3_for($t['id']);
    echo "Groups, organizations, fields and surveys ready for {$t['slug']}\n";
}
