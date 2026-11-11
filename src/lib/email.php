<?php
declare(strict_types=1);

/* Tenant email delivery: SMTP (built-in client) or Microsoft 365 (Graph API). */

function email_settings(string $tenantId): ?array
{
    return q_one('SELECT * FROM "EmailSetting" WHERE "tenantId" = ?', [$tenantId]);
}

function html_to_plain(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('/<[^>]+>/', ' $0', $html)), ENT_QUOTES, 'UTF-8')));
}

/**
 * Send an email using the tenant's configured provider.
 * Returns ['ok' => bool, 'error' => ?string, 'skipped' => ?bool].
 */
function send_tenant_email(string $tenantId, array $msg): array
{
    $s = email_settings($tenantId);
    if (!$s || !$s['enabled']) return ['ok' => false, 'skipped' => true];
    $msg['text'] ??= html_to_plain($msg['html']);
    try {
        return $s['provider'] === 'MICROSOFT365' ? send_via_graph($s, $msg) : send_via_smtp($s, $msg);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage() ?: 'Send failed'];
    }
}

function send_via_smtp(array $s, array $msg): array
{
    if (!$s['smtpHost'] || !$s['smtpPort'] || !$s['fromEmail']) {
        return ['ok' => false, 'error' => 'SMTP is not fully configured'];
    }
    smtp_send([
        'host' => $s['smtpHost'],
        'port' => (int) $s['smtpPort'],
        'secure' => (bool) $s['smtpSecure'],
        'user' => $s['smtpUser'] ?: null,
        'pass' => $s['smtpUser'] ? decrypt_secret($s['smtpPassword']) : null,
    ], [
        'fromName' => $s['fromName'],
        'fromEmail' => $s['fromEmail'],
        'to' => $msg['to'],
        'subject' => $msg['subject'],
        'html' => $msg['html'],
        'text' => $msg['text'],
    ]);
    return ['ok' => true];
}

function http_request(string $method, string $url, array $headers, string $body, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException($err ?: 'Network error');
    return [$status, (string) $resp];
}

function send_via_graph(array $s, array $msg): array
{
    if (!$s['msTenantId'] || !$s['msClientId'] || !$s['msClientSecret'] || !$s['msSender']) {
        return ['ok' => false, 'error' => 'Microsoft 365 is not fully configured'];
    }
    // 1) Client-credentials token.
    [$st, $body] = http_request('POST',
        'https://login.microsoftonline.com/' . rawurlencode($s['msTenantId']) . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id' => $s['msClientId'],
            'client_secret' => decrypt_secret($s['msClientSecret']),
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]),
    );
    if ($st < 200 || $st >= 300) return ['ok' => false, 'error' => "Auth failed ($st): " . substr($body, 0, 200)];
    $token = json_decode($body, true)['access_token'] ?? '';

    // 2) Send on behalf of the configured sender mailbox.
    [$st, $body] = http_request('POST',
        'https://graph.microsoft.com/v1.0/users/' . rawurlencode($s['msSender']) . '/sendMail',
        ["Authorization: Bearer $token", 'Content-Type: application/json'],
        json_encode([
            'message' => [
                'subject' => $msg['subject'],
                'body' => ['contentType' => 'HTML', 'content' => $msg['html']],
                'toRecipients' => [['emailAddress' => ['address' => $msg['to']]]],
                'from' => ['emailAddress' => ['address' => $s['fromEmail'] ?: $s['msSender']]],
            ],
            'saveToSentItems' => false,
        ]),
    );
    if ($st < 200 || $st >= 300) return ['ok' => false, 'error' => "Graph send failed ($st): " . substr($body, 0, 200)];
    return ['ok' => true];
}

/** Fire-and-forget email that never throws into the caller. */
function send_tenant_email_safe(string $tenantId, array $msg): void
{
    try {
        send_tenant_email($tenantId, $msg);
    } catch (Throwable) {
        // best-effort: the in-app notification is already persisted
    }
}

/** Email several tenant users (best-effort; no-op when email is disabled). */
function email_tenant_users(string $tenantId, array $userIds, array $msg): void
{
    if (!$userIds) return;
    $s = email_settings($tenantId);
    if (!$s || !$s['enabled']) return;
    $emails = pluck(q_all('SELECT "email" FROM "User" WHERE "id" IN (' . in_ph($userIds) . ')', $userIds), 'email');
    foreach ($emails as $to) send_tenant_email_safe($tenantId, ['to' => $to] + $msg);
}
