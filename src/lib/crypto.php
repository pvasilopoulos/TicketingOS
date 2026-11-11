<?php
declare(strict_types=1);

// Symmetric encryption for secrets at rest (SMTP password, OAuth client secret).
// Key is derived from AUTH_SECRET; same format as the Node version
// ("enc:v1:" + base64(iv|tag|ciphertext), AES-256-GCM), so values are portable.

const ENC_PREFIX = 'enc:v1:';

function enc_key(): string
{
    return hash('sha256', auth_secret(), true);
}

function encrypt_secret(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', enc_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return ENC_PREFIX . base64_encode($iv . $tag . $ct);
}

function decrypt_secret(?string $value): string
{
    if (!$value || !str_starts_with($value, ENC_PREFIX)) return '';
    $raw = base64_decode(substr($value, strlen(ENC_PREFIX)), true);
    if ($raw === false || strlen($raw) < 28) return '';
    $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', enc_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $pt === false ? '' : $pt;
}

function is_encrypted(?string $value): bool
{
    return (bool) ($value && str_starts_with($value, ENC_PREFIX));
}
