<?php
declare(strict_types=1);

/*
 * Web Push (RFC 8030) with VAPID (RFC 8292) and aes128gcm payload encryption
 * (RFC 8291), implemented with ext-openssl only — no Composer packages.
 */

function vapid_configured(): bool
{
    return (bool) (env('VAPID_PUBLIC_KEY') && env('VAPID_PRIVATE_KEY'))
        && !str_starts_with((string) env('VAPID_PUBLIC_KEY'), 'your-');
}

function openssl_cfg(): array
{
    $c = env('OPENSSL_CONF');
    // Windows PHP builds ship a config here but don't point OpenSSL at it.
    if (!$c && PHP_OS_FAMILY === 'Windows' && is_file($guess = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf')) $c = $guess;
    return $c ? ['config' => $c] : [];
}

/** DER SubjectPublicKeyInfo (PEM) for a raw uncompressed P-256 point. */
function p256_public_pem(string $point65): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point65;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** SEC1 ECPrivateKey (PEM) from raw d + public point. */
function p256_private_pem(string $d32, string $point65): string
{
    $der = hex2bin('30770201010420') . $d32 . hex2bin('a00a06082a8648ce3d030107a144034200') . $point65;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/** Generate a P-256 key pair → ['public' => 65-byte point, 'private' => 32-byte d, 'key' => OpenSSLAsymmetricKey]. */
function p256_generate(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC] + openssl_cfg());
    if (!$key) throw new RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
    $det = openssl_pkey_get_details($key)['ec'];
    $pad = fn ($v) => str_pad($v, 32, "\0", STR_PAD_LEFT);
    return ['public' => "\x04" . $pad($det['x']) . $pad($det['y']), 'private' => $pad($det['d']), 'key' => $key];
}

/** DER ECDSA signature → raw r||s (64 bytes). */
function ecdsa_der_to_raw(string $der): string
{
    $off = 2;
    if (ord($der[1]) & 0x80) $off += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $off++; // INTEGER tag
        $len = ord($der[$off++]);
        $int = substr($der, $off, $len);
        $off += $len;
        $int = ltrim($int, "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}

function vapid_header(string $endpoint): string
{
    $pub = b64url_decode((string) env('VAPID_PUBLIC_KEY'));
    $priv = b64url_decode((string) env('VAPID_PRIVATE_KEY'));
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');

    $h = b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = b64url_encode(json_encode([
        'aud' => $aud,
        'exp' => time() + 12 * 3600,
        'sub' => env('VAPID_SUBJECT', 'mailto:admin@example.com'),
    ]));
    $key = openssl_pkey_get_private(p256_private_pem($priv, $pub));
    if (!$key) throw new RuntimeException('Invalid VAPID private key');
    openssl_sign("$h.$c", $der, $key, OPENSSL_ALGO_SHA256);
    $jwt = "$h.$c." . b64url_encode(ecdsa_der_to_raw($der));
    return 'vapid t=' . $jwt . ', k=' . env('VAPID_PUBLIC_KEY');
}

/** Encrypt a payload for one subscription (aes128gcm content coding). */
function webpush_encrypt(string $payload, string $uaPublicB64, string $authB64): string
{
    $uaPublic = b64url_decode($uaPublicB64);
    $authSecret = b64url_decode($authB64);
    $local = p256_generate();

    $shared = openssl_pkey_derive(openssl_pkey_get_public(p256_public_pem($uaPublic)), $local['key'], 32);
    if ($shared === false) throw new RuntimeException('ECDH failed');

    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $local['public'], $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    $tag = '';
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $salt . pack('N', 4096) . chr(65) . $local['public'] . $ct . $tag;
}

/** Send one push. Returns the HTTP status code (0 on network failure). */
function webpush_send(array $sub, string $payload): int
{
    $body = webpush_encrypt($payload, $sub['p256dh'], $sub['auth']);
    try {
        [$status] = http_request('POST', $sub['endpoint'], [
            'Authorization: ' . vapid_header($sub['endpoint']),
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: 2419200',
            'Urgency: normal',
        ], $body, 10);
        return $status;
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Push a notification to every registered device of the given users.
 * Best-effort: dead subscriptions are pruned, errors never propagate.
 */
function send_push_to_users(array $userIds, array $payload): void
{
    if (!vapid_configured() || !$userIds) return;
    $subs = q_all('SELECT * FROM "PushSubscription" WHERE "userId" IN (' . in_ph($userIds) . ')', $userIds);
    $data = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach ($subs as $s) {
        try {
            $code = webpush_send($s, $data);
            if ($code === 404 || $code === 410) q('DELETE FROM "PushSubscription" WHERE "id" = ?', [$s['id']]);
        } catch (Throwable) {
            // ignore
        }
    }
}
