<?php
declare(strict_types=1);

/*
 * Runs bin/cron.php every N minutes (default 5) for as long as it stays open.
 * Handy in development, or where no system scheduler is available.
 *   php bin/cron-loop.php [minutes]
 */

$minutes = max(1, (int) ($argv[1] ?? 5));
$php = PHP_BINARY;
$script = __DIR__ . '/cron.php';
echo "Running cron every $minutes minute(s). Ctrl+C to stop.\n";
while (true) {
    $started = time();
    // A fresh process each time, so a failure or memory growth never carries over.
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --log');
    sleep(max(1, $minutes * 60 - (time() - $started)));
}
