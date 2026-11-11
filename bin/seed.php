<?php
declare(strict_types=1);

/*
 * Load demo data (wipes existing tenants/users/tickets first).
 *   php bin/seed.php
 * All passwords: password123
 */

require __DIR__ . '/../src/bootstrap.php';

echo "🌱 Seeding database...\n";

$pw = hash_password('password123');

db_tx(function () use ($pw) {
    foreach (['Notification', 'Attachment', 'Comment', 'TicketTag', 'TicketWatcher', 'TicketEvent', 'Ticket', 'Tag', 'Category',
        'SavedView', 'PushSubscription', 'PasswordResetToken', 'NotificationPreference', 'User', 'Tenant'] as $t) {
        db()->exec('DELETE FROM ' . qi($t));
    }

    $now = now_iso();
    // Platform operator — sees every tenant, belongs to none.
    db_insert('User', [
        'id' => cuid(), 'email' => 'info@softify.gr', 'name' => 'Platform Operator', 'passwordHash' => $pw,
        'role' => 'SUPER_ADMIN', 'avatarColor' => '#0ea5e9', 'createdAt' => $now, 'updatedAt' => $now, 'tenantId' => null,
    ]);

    seed_demo_tenant($pw, [
        'name' => 'Acme Inc', 'slug' => 'acme', 'adminEmail' => 'admin@acme.dev',
        'agents' => [['Sofia Reyes', 'sofia@acme.dev'], ['Marcus Lee', 'marcus@acme.dev']],
        'customers' => [['Jamie Carter', 'jamie@acme-customer.com'], ['Priya Nair', 'priya@acme-customer.com'], ['Tom Becker', 'tom@acme-customer.com']],
    ]);
    seed_demo_tenant($pw, [
        'name' => 'Globex', 'slug' => 'globex', 'adminEmail' => 'admin@globex.dev',
        'agents' => [['Dana White', 'dana@globex.dev']],
        'customers' => [['Leo Fischer', 'leo@globex-customer.com'], ['Mia Rossi', 'mia@globex-customer.com']],
    ]);
});

function seed_user(string $pw, string $tenantId, string $name, string $email, string $role, string $color): array
{
    $now = now_iso();
    $u = ['id' => cuid(), 'email' => $email, 'name' => $name, 'passwordHash' => $pw, 'role' => $role,
        'avatarColor' => $color, 'createdAt' => $now, 'updatedAt' => $now, 'tenantId' => $tenantId];
    db_insert('User', $u);
    return $u;
}

function seed_demo_tenant(string $pw, array $cfg): void
{
    $now = now_iso();
    $tid = cuid();
    db_insert('Tenant', ['id' => $tid, 'name' => $cfg['name'], 'slug' => $cfg['slug'], 'createdAt' => $now, 'updatedAt' => $now]);
    seed_tenant_defaults($tid, false);

    foreach ([
        ['Ask for more details', '<p>Thanks for reaching out! To help you faster, could you share a few more details — steps to reproduce, screenshots, and when it started?</p>'],
        ['Resolved — closing', "<p>Glad we could help! I'm marking this as resolved. Feel free to reply if anything else comes up. 🙂</p>"],
    ] as [$title, $body]) {
        db_insert('CannedResponse', ['id' => cuid(), 'tenantId' => $tid, 'title' => $title, 'body' => $body, 'createdAt' => $now, 'updatedAt' => $now]);
    }

    $admin = seed_user($pw, $tid, "{$cfg['name']} Admin", $cfg['adminEmail'], 'ADMIN', AVATAR_COLORS[0]);
    $agents = [];
    foreach ($cfg['agents'] as $i => [$n, $e]) $agents[] = seed_user($pw, $tid, $n, $e, 'AGENT', AVATAR_COLORS[($i + 1) % 8]);
    $customers = [];
    foreach ($cfg['customers'] as $i => [$n, $e]) $customers[] = seed_user($pw, $tid, $n, $e, 'CUSTOMER', AVATAR_COLORS[($i + 4) % 8]);

    $cats = [];
    foreach ([['Billing', '#f59e0b'], ['Technical', '#3b82f6'], ['Account', '#8b5cf6'], ['Feature Request', '#10b981'], ['General', '#64748b']] as [$n, $c]) {
        $id = cuid();
        db_insert('Category', ['id' => $id, 'name' => $n, 'color' => $c, 'tenantId' => $tid]);
        $cats[] = $id;
    }
    $tags = [];
    foreach (['bug', 'urgent', 'mobile', 'payment', 'ui', 'integration'] as $n) {
        $id = cuid();
        db_insert('Tag', ['id' => $id, 'name' => $n, 'tenantId' => $tid]);
        $tags[] = $id;
    }

    $lastAgent = $agents[count($agents) - 1];
    $lastCustomer = $customers[count($customers) - 1];
    $tickets = [
        ['Unable to log in after password reset', "I reset my password via the email link but the new password is not accepted on the login page. I keep getting 'invalid credentials'.", 'OPEN', 'HIGH', $customers[0], $agents[0], $cats[2], [$tags[0]]],
        ['Invoice shows wrong VAT amount', 'The invoice for October lists 24% VAT but our account is set to the reduced 13% rate. Please correct and reissue.', 'PENDING', 'MEDIUM', $customers[1], $lastAgent, $cats[0], [$tags[3]]],
        ['App crashes when uploading large files', 'On the mobile app, uploading a file larger than ~20MB causes an immediate crash. Happens on both iOS and Android.', 'OPEN', 'URGENT', $lastCustomer, $agents[0], $cats[1], [$tags[0], $tags[2]]],
        ['Request: dark mode for the dashboard', 'Would love a dark theme option for the dashboard — staring at the white screen all day is rough.', 'OPEN', 'LOW', $customers[0], null, $cats[3], [$tags[4]]],
        ['Webhook integration not firing', 'Our Slack webhook integration stopped delivering events yesterday around 14:00. No config changes on our side.', 'RESOLVED', 'HIGH', $customers[1], $lastAgent, $cats[1], [$tags[5]]],
        ['How do I export my data?', "I'd like to export all my tickets and account data as CSV. Is there an option for that somewhere?", 'CLOSED', 'LOW', $lastCustomer, $agents[0], $cats[4], []],
    ];

    $count = count($tickets);
    foreach ($tickets as $i => [$subject, $desc, $status, $prio, $req, $asg, $cat, $tagIds]) {
        $createdTs = microtime(true) - ($count - $i) * 86400;
        $created = iso_from_ts($createdTs);
        $ticketId = cuid();
        db_insert('Ticket', [
            'id' => $ticketId, 'number' => $i + 1, 'subject' => $subject, 'description' => '<p>' . e($desc) . '</p>',
            'status' => $status, 'priority' => $prio, 'createdAt' => $created, 'updatedAt' => $created,
            'closedAt' => in_array($status, ['RESOLVED', 'CLOSED'], true) ? now_iso() : null,
            'dueAt' => iso_from_ts($createdTs + DEFAULT_SLA[$prio] * 3600),
            'tenantId' => $tid, 'requesterId' => $req['id'], 'assigneeId' => $asg['id'] ?? null, 'categoryId' => $cat,
        ]);
        foreach ($tagIds as $tagId) db_insert('TicketTag', ['ticketId' => $ticketId, 'tagId' => $tagId]);
        db_insert('TicketEvent', ['id' => cuid(), 'tenantId' => $tid, 'ticketId' => $ticketId, 'actorId' => $req['id'], 'type' => 'CREATED', 'summary' => 'opened the ticket', 'createdAt' => $created]);

        if ($i < 3) {
            $author = ($asg ?? $admin)['id'];
            db_insert('Comment', ['id' => cuid(), 'body' => "<p>Thanks for reaching out — I'm looking into this now and will update you shortly.</p>", 'isInternal' => 0,
                'createdAt' => iso_from_ts($createdTs + 3600), 'tenantId' => $tid, 'ticketId' => $ticketId, 'authorId' => $author]);
            db_insert('Comment', ['id' => cuid(), 'body' => '<p>Internal: reproduced on staging, escalating to the platform team.</p>', 'isInternal' => 1,
                'createdAt' => iso_from_ts($createdTs + 7200), 'tenantId' => $tid, 'ticketId' => $ticketId, 'authorId' => $author]);
        }
        if ($asg) {
            db_insert('Notification', ['id' => cuid(), 'type' => 'TICKET_ASSIGNED', 'message' => 'You were assigned ticket #' . ($i + 1) . ": $subject",
                'read' => $i % 2 === 0 ? 1 : 0, 'createdAt' => $created, 'tenantId' => $tid, 'userId' => $asg['id'], 'ticketId' => $ticketId]);
        }
    }
}

db_tx(function () { foreach (pluck(q_all('SELECT "id" FROM "Ticket"'), 'id') as $id) reindex_ticket($id); });
require __DIR__ . '/seed-extras.php';
require __DIR__ . '/seed-v3.php';

echo "✅ Seed complete.\n\n";
echo "All passwords: password123\n\n";
echo "Platform operator (sees every tenant):\n  info@softify.gr\n\n";
echo "Tenant 'Acme Inc':\n  Admin:    admin@acme.dev\n  Agents:   sofia@acme.dev / marcus@acme.dev\n  Customers jamie@acme-customer.com / priya@acme-customer.com / tom@acme-customer.com\n\n";
echo "Tenant 'Globex':\n  Admin:    admin@globex.dev\n  Agent:    dana@globex.dev\n  Customers leo@globex-customer.com / mia@globex-customer.com\n";
