<?php
declare(strict_types=1);

/*
 * Development only: point the demo workspaces' email at the local mail catcher
 * (bin/mail-catcher.php, 127.0.0.1:1025). Workspaces that already have email
 * settings are left alone.
 *   php bin/dev-mail-setup.php [slug ...]     (default: acme globex initech)
 */

require __DIR__ . '/../src/bootstrap.php';

$slugs = array_slice($argv, 1) ?: ['acme', 'globex', 'initech'];
foreach ($slugs as $slug) {
    $t = q_one('SELECT "id","name" FROM "Tenant" WHERE "slug" = ?', [$slug]);
    if (!$t) { echo "No workspace '$slug'\n"; continue; }
    if (email_settings($t['id'])) { echo "$slug already has email settings, skipped\n"; continue; }
    db_insert('EmailSetting', [
        'id' => cuid(), 'tenantId' => $t['id'], 'enabled' => 1, 'provider' => 'SMTP',
        'fromName' => $t['name'] . ' Support', 'fromEmail' => "support@$slug.test",
        'smtpHost' => '127.0.0.1', 'smtpPort' => 1025, 'smtpSecure' => 0, 'smtpUser' => null, 'smtpPassword' => null,
        'msTenantId' => null, 'msClientId' => null, 'msClientSecret' => null, 'msSender' => null, 'updatedAt' => now_iso(),
    ]);
    echo "$slug → local mail catcher (support@$slug.test)\n";
}
