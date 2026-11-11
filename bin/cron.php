<?php
declare(strict_types=1);

/*
 * Periodic jobs: scheduled report emails, webhook retries, cleanup.
 * Run every 5 minutes, e.g. crontab:  * /5 * * * *  php /path/to/bin/cron.php
 * (or call GET /cron?key=CRON_KEY from an external scheduler).
 *   --log  also append the result line to storage/cron.log (used by the Windows task).
 */

require __DIR__ . '/../src/bootstrap.php';
require SRC . '/api.php';

$r = run_cron();
$line = date('c') . " reports sent: {$r['reports']}, webhook deliveries attempted: {$r['webhooks']}\n";
echo $line;
if (in_array('--log', $argv ?? [], true)) file_put_contents(ROOT . '/storage/cron.log', $line, FILE_APPEND | LOCK_EX);
