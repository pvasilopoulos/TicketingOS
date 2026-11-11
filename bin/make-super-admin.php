<?php
declare(strict_types=1);

/*
 * Make an existing account a super admin (owner of the platform).
 * The account keeps its own workspace and also gets the platform console
 * (/platform) with access to every workspace.
 *   php bin/make-super-admin.php you@example.com
 * Without shell access, run the same in phpMyAdmin:
 *   UPDATE `User` SET `role` = 'SUPER_ADMIN', `customRoleId` = NULL, `organizationId` = NULL, `orgManager` = 0, `active` = 1
 *   WHERE `email` = 'you@example.com';
 */

require __DIR__ . '/../src/bootstrap.php';

$email = strtolower(trim((string) ($argv[1] ?? '')));
if (!is_valid_email($email)) {
    fwrite(STDERR, "Usage: php bin/make-super-admin.php <email>\n");
    exit(1);
}
$u = q_one('SELECT "id","name","role","tenantId" FROM "User" WHERE "email" = ?', [$email]);
if (!$u) {
    fwrite(STDERR, "No account with $email. Sign up at /register (or ask an admin to invite you), then run this again.\n");
    exit(1);
}
db_update('User', ['role' => 'SUPER_ADMIN', 'customRoleId' => null, 'organizationId' => null, 'orgManager' => 0, 'active' => 1, 'updatedAt' => now_iso()], '"id" = ?', [$u['id']]);
if ($u['tenantId']) audit($u['tenantId'], null, 'member-role', "{$u['name']} became a super admin (command line)", 'member', $u['id']);
$ws = $u['tenantId'] ? q_val('SELECT "name" FROM "Tenant" WHERE "id" = ?', [$u['tenantId']]) : null;
echo "{$u['name']} <$email> is now a super admin" . ($ws ? " (home workspace: $ws)" : '') . ".\n";
