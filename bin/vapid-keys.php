<?php
declare(strict_types=1);

/*
 * Generate a VAPID key pair for Web Push.
 *   php bin/vapid-keys.php
 * Paste the output into your .env.
 */

require __DIR__ . '/../src/bootstrap.php';

$k = p256_generate();
echo 'VAPID_PUBLIC_KEY="' . b64url_encode($k['public']) . "\"\n";
echo 'VAPID_PRIVATE_KEY="' . b64url_encode($k['private']) . "\"\n";
