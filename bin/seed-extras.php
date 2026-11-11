<?php
declare(strict_types=1);

/*
 * Demo knowledge-base articles and macros for every tenant that has none yet.
 *   php bin/seed-extras.php
 * (bin/seed.php runs this automatically.)
 */

if (!defined('ROOT')) require __DIR__ . '/../src/bootstrap.php';

function seed_extras_for(string $tenantId): void
{
    $now = now_iso();
    $author = q_val('SELECT "id" FROM "User" WHERE "tenantId" = ? AND "role" = \'ADMIN\' ORDER BY "createdAt" LIMIT 1', [$tenantId]);

    if (!q_val('SELECT 1 FROM "KbArticle" WHERE "tenantId" = ?', [$tenantId])) {
        $articles = [
            ['Account', 'How do I reset my password?', '<p>If you can’t sign in:</p><ol><li><p>Open the sign-in page and choose <strong>Forgot?</strong>.</p></li><li><p>Enter the email address of your account.</p></li><li><p>Open the email we send you and click <strong>Reset my password</strong>. The link is valid for 1 hour.</p></li></ol><p>Still getting “invalid credentials” after a reset? Clear your browser’s saved password and type the new one by hand.</p>'],
            ['Account', 'Changing the email address on your account', '<p>For security reasons the email address can only be changed by an administrator of your workspace. Open a ticket from the address you use today and tell us the new one.</p>'],
            ['Billing', 'Which VAT rate is applied to my invoice?', '<p>Invoices use the VAT rate of the billing address on your account (24% standard, 13% or 6% reduced rates when your account is registered for them).</p><p>If the rate is wrong, reply to the invoice ticket with your VAT number and we will reissue it within 2 working days.</p>'],
            ['Billing', 'How do refunds work?', '<p>Refunds go back to the original payment method and normally appear within 5–10 working days. Partial refunds are shown as a credit note on your next invoice.</p>'],
            ['Technical', 'Uploading large files from the mobile app', '<p>The mobile app uploads files up to <strong>20 MB</strong>. For larger files:</p><ul><li><p>Use the web app on a computer (up to 10 MB per attachment, 5 per message), or</p></li><li><p>Share a link from your cloud storage in the ticket.</p></li></ul><p>Make sure the app is updated to the latest version from your app store.</p>'],
            ['Technical', 'Webhooks are not being delivered', '<p>Check these in order:</p><ol><li><p>The webhook URL responds with HTTP 2xx within 10 seconds.</p></li><li><p>Your firewall allows our outgoing IP addresses.</p></li><li><p>The signing secret in your integration matches the one in your settings.</p></li></ol><p>Failed deliveries are retried 5 times over 24 hours.</p>'],
            ['General', 'Exporting your data as CSV', '<p>Administrators can export tickets from <strong>Tickets → Export</strong>. The export respects the current filters and search, and opens correctly in Excel (UTF-8).</p>'],
        ];
        foreach ($articles as [$section, $title, $body]) {
            db_insert('KbArticle', [
                'id' => cuid(), 'tenantId' => $tenantId, 'title' => $title, 'body' => $body, 'section' => $section,
                'published' => 1, 'views' => random_int(5, 400), 'helpful' => random_int(0, 60),
                'searchText' => kb_search_text($title, $section, $body), 'authorId' => $author, 'createdAt' => $now, 'updatedAt' => $now,
            ]);
        }
    }

    if (!q_val('SELECT 1 FROM "Macro" WHERE "tenantId" = ?', [$tenantId])) {
        $pending = q_val('SELECT "key" FROM "TicketStatus" WHERE "tenantId" = ? AND "key" = \'PENDING\'', [$tenantId]);
        $resolved = q_val('SELECT "key" FROM "TicketStatus" WHERE "tenantId" = ? AND "isClosed" = 1 ORDER BY "order" LIMIT 1', [$tenantId]);
        $macros = [
            ['Ask for details → Pending', '<p>Hi {{requester.first_name}},</p><p>Thanks for reaching out about ticket #{{ticket.number}}. Could you share the steps to reproduce, a screenshot and roughly when it started? That will help us fix it faster.</p><p>— {{agent.name}}</p>', 0, $pending, null, 'me'],
            ['Resolved — closing', '<p>Hi {{requester.first_name}}, glad we could help! I’m marking this as resolved. Just reply if anything else comes up. 🙂</p>', 0, $resolved, null, null],
            ['Escalate to engineering', '<p>Escalated to engineering — reproduced, needs a fix in the next release.</p>', 1, null, 'HIGH', null],
            ['Take it', null, 0, null, null, 'me'],
        ];
        foreach ($macros as [$name, $body, $internal, $status, $prio, $assignee]) {
            db_insert('Macro', [
                'id' => cuid(), 'tenantId' => $tenantId, 'name' => $name, 'body' => $body, 'isInternal' => $internal,
                'setStatus' => $status ?: null, 'setPriority' => $prio, 'setAssignee' => $assignee, 'addTagId' => null, 'createdAt' => $now,
            ]);
        }
    }
}

foreach (pluck(q_all('SELECT "id" FROM "Tenant"'), 'id') as $tid) seed_extras_for($tid);
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) echo "Demo knowledge base and macros added.\n";
