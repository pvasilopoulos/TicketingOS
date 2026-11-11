<?php
declare(strict_types=1);

/*
 * Load-test data: adds extra tenants full of tickets (keeps existing data).
 *   php bin/seed-bulk.php [--tenants=30] [--tickets=500] [--big=20000]
 *   --big    tickets in one extra large tenant ("Initech", admin@initech.dev)
 * All generated users have password "password123".
 */

require __DIR__ . '/../src/bootstrap.php';

$opt = getopt('', ['tenants::', 'tickets::', 'big::']);
$nTenants = (int) ($opt['tenants'] ?? 30);
$perTenant = (int) ($opt['tickets'] ?? 500);
$big = (int) ($opt['big'] ?? 20000);

mt_srand(42);
$pw = hash_password('password123');
$words = explode(' ', 'login password reset invoice payment refund crash upload mobile android iphone sync export csv report dashboard email notification slow timeout error 500 api webhook integration slack printer vpn wifi laptop license renewal billing vat address shipping order delayed broken screen keyboard account locked permission access denied database backup restore update install upgrade feature request dark mode language greek translation calendar');
$first = ['Maria', 'Nikos', 'Eleni', 'Giorgos', 'Anna', 'Kostas', 'Sofia', 'Dimitris', 'John', 'Emma', 'Lucas', 'Olivia', 'Liam', 'Mia', 'Noah', 'Chloe'];
$last = ['Papadopoulos', 'Georgiou', 'Nikolaou', 'Smith', 'Brown', 'Garcia', 'Miller', 'Rossi', 'Muller', 'Dubois'];

function rnd(array $a) { return $a[mt_rand(0, count($a) - 1)]; }
function sentence(array $words, int $n): string { $s = []; for ($i = 0; $i < $n; $i++) $s[] = rnd($words); return ucfirst(implode(' ', $s)); }

/** Multi-row INSERT in chunks (much faster than row-by-row). */
function bulk_insert(string $table, array $rows, int $chunk = 400): void
{
    if (!$rows) return;
    $cols = array_keys($rows[0]);
    $colSql = implode(', ', array_map('qi', $cols));
    $rowPh = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    foreach (array_chunk($rows, $chunk) as $part) {
        $params = [];
        foreach ($part as $r) foreach ($cols as $c) $params[] = $r[$c];
        q('INSERT INTO ' . qi($table) . " ($colSql) VALUES " . implode(', ', array_fill(0, count($part), $rowPh)), $params);
    }
}

function make_tenant(string $name, string $slug, int $tickets, string $pw, array $words, array $first, array $last): void
{
    $t0 = microtime(true);
    $now = now_iso();
    $tid = cuid();
    db_insert('Tenant', ['id' => $tid, 'name' => $name, 'slug' => $slug, 'createdAt' => $now, 'updatedAt' => $now]);
    seed_tenant_defaults($tid);
    $cats = q_all('SELECT "id","name" FROM "Category" WHERE "tenantId" = ?', [$tid]);
    $statuses = tenant_statuses($tid);
    $prios = DEFAULT_SLA;

    $tags = [];
    foreach (['bug', 'urgent', 'mobile', 'payment', 'ui', 'integration', 'vip', 'hardware'] as $tg) {
        $tags[] = ['id' => cuid(), 'name' => $tg, 'tenantId' => $tid];
    }
    bulk_insert('Tag', $tags);

    $users = [];
    $users[] = ['id' => cuid(), 'email' => "admin@$slug.dev", 'name' => "$name Admin", 'passwordHash' => $pw, 'role' => 'ADMIN', 'avatarColor' => AVATAR_COLORS[0], 'createdAt' => $now, 'updatedAt' => $now, 'tenantId' => $tid];
    $nAgents = max(2, min(25, intdiv($tickets, 400)));
    $nCustomers = max(5, min(2000, intdiv($tickets, 4)));
    for ($i = 0; $i < $nAgents; $i++) {
        $users[] = ['id' => cuid(), 'email' => "agent$i@$slug.dev", 'name' => rnd($first) . ' ' . rnd($last), 'passwordHash' => $pw, 'role' => 'AGENT', 'avatarColor' => AVATAR_COLORS[($i + 1) % 8], 'createdAt' => $now, 'updatedAt' => $now, 'tenantId' => $tid];
    }
    for ($i = 0; $i < $nCustomers; $i++) {
        $users[] = ['id' => cuid(), 'email' => "customer$i@$slug-customer.com", 'name' => rnd($first) . ' ' . rnd($last), 'passwordHash' => $pw, 'role' => 'CUSTOMER', 'avatarColor' => AVATAR_COLORS[($i + 4) % 8], 'createdAt' => $now, 'updatedAt' => $now, 'tenantId' => $tid];
    }
    bulk_insert('User', $users);
    $agents = array_values(array_filter($users, fn ($u) => $u['role'] !== 'CUSTOMER'));
    $customers = array_values(array_filter($users, fn ($u) => $u['role'] === 'CUSTOMER'));

    $tRows = []; $cRows = []; $ttRows = [];
    for ($n = 1; $n <= $tickets; $n++) {
        $created = microtime(true) - mt_rand(0, 365 * 86400);
        $st = rnd($statuses);
        $prio = rnd(array_keys($prios));
        $req = rnd($customers);
        $asg = mt_rand(0, 4) ? rnd($agents) : null;
        $cat = mt_rand(0, 5) ? rnd($cats) : null;
        $subject = sentence($words, mt_rand(3, 7));
        $desc = sentence($words, mt_rand(12, 40)) . '.';
        $id = cuid();
        $ticketTags = [];
        if (mt_rand(0, 2) === 0) { $tg = rnd($tags); $ttRows[] = ['ticketId' => $id, 'tagId' => $tg['id']]; $ticketTags[] = $tg['name']; }
        $replies = [];
        for ($c = 0, $k = mt_rand(0, 4); $c < $k; $c++) {
            $at = $created + ($c + 1) * mt_rand(600, 7200);
            if ($at > microtime(true)) break; // replies never in the future
            $body = sentence($words, mt_rand(5, 25)) . '.';
            $internal = $c % 2 === 0 && mt_rand(0, 3) === 0; // only staff (even turns) write internal notes
            if (!$internal) $replies[] = $body;
            $cRows[] = ['id' => cuid(), 'body' => '<p>' . e($body) . '</p>', 'isInternal' => $internal ? 1 : 0, 'createdAt' => iso_from_ts($at),
                'tenantId' => $tid, 'ticketId' => $id, 'authorId' => ($c % 2 ? $req : ($asg ?? rnd($agents)))['id']];
        }
        $search = search_norm(implode(' · ', array_filter([$subject, $desc, $req['name'], $req['email'], $asg['name'] ?? '', $cat['name'] ?? '', ...$ticketTags, ...$replies])));
        $tRows[] = [
            'id' => $id, 'number' => $n, 'subject' => $subject, 'description' => '<p>' . e($desc) . '</p>',
            'status' => $st['key'], 'priority' => $prio, 'createdAt' => iso_from_ts($created), 'updatedAt' => iso_from_ts($created),
            'closedAt' => $st['isClosed'] ? iso_from_ts($created + 86400) : null, 'dueAt' => iso_from_ts($created + $prios[$prio] * 3600),
            'tenantId' => $tid, 'requesterId' => $req['id'], 'assigneeId' => $asg['id'] ?? null, 'categoryId' => $cat['id'] ?? null,
            'searchText' => $search,
        ];
        if (count($tRows) >= 2000) { flush_rows($tRows, $cRows, $ttRows); }
    }
    flush_rows($tRows, $cRows, $ttRows);
    printf("  %-24s %6d tickets, %4d users  (%.1fs)\n", $name, $tickets, count($users), microtime(true) - $t0);
}

function flush_rows(array &$t, array &$c, array &$tt): void
{
    db_tx(function () use ($t, $c, $tt) {
        bulk_insert('Ticket', $t, 200);
        bulk_insert('Comment', $c);
        bulk_insert('TicketTag', $tt);
    });
    $t = $c = $tt = [];
}

echo "Generating load-test data...\n";
$t0 = microtime(true);
if ($big > 0 && !q_val('SELECT 1 FROM "Tenant" WHERE "slug" = ?', ['initech'])) {
    make_tenant('Initech', 'initech', $big, $pw, $words, $first, $last);
}
for ($i = 1; $i <= $nTenants; $i++) {
    $slug = 'load-' . $i;
    if (q_val('SELECT 1 FROM "Tenant" WHERE "slug" = ?', [$slug])) continue;
    make_tenant('Load Test ' . $i, $slug, $perTenant, $pw, $words, $first, $last);
}
printf("Done in %.1fs. Totals: %d tenants, %d tickets, %d replies.\n", microtime(true) - $t0,
    q_val('SELECT COUNT(*) FROM "Tenant"'), q_val('SELECT COUNT(*) FROM "Ticket"'), q_val('SELECT COUNT(*) FROM "Comment"'));
echo "Big tenant login: admin@initech.dev / password123\n";
