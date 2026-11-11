<?php
declare(strict_types=1);

/*
 * SMS, Viber and WhatsApp.
 *  - SMS: text notifications (ticket received, new reply, resolved; urgent tickets
 *    and SLA misses for staff) through Twilio or any SMS gateway with an HTTP API
 *    (most Greek providers). Sent through the notification queue with retries.
 *  - Viber (bot) and WhatsApp (Cloud API): customers write to your account, the
 *    message opens a ticket (or joins their open one), and agents' public replies
 *    on that ticket are sent back on the same app.
 * API addresses can be pointed elsewhere with VIBER_API_BASE / WHATSAPP_API_BASE /
 * TWILIO_API_BASE in .env (a proxy, or a local stand-in for testing).
 */

const CHAT_CHANNELS = ['viber' => 'Viber', 'whatsapp' => 'WhatsApp'];
const SMS_EVENTS = [
    'customer' => ['received' => 'Request received', 'reply' => 'New reply from support', 'status' => 'Ticket resolved'],
    'staff' => ['urgent' => 'New high / urgent ticket', 'assigned' => 'Ticket assigned to them', 'sla_breach' => 'SLA deadline missed'],
];

/* ---------- Phone numbers ---------- */

/** "+306912345678" from what people type ("69 1234 5678", "0030…", "+30 …"); null if it isn't a phone number. */
function phone_normalize(?string $raw, string $defaultCountry = '30'): ?string
{
    $s = preg_replace('/[^\d+]/', '', (string) $raw);
    if ($s === '' || $s === '+') return null;
    if (str_starts_with($s, '00')) $s = '+' . substr($s, 2);
    if (!str_starts_with($s, '+')) {
        // Greek numbers without the country code: 10 digits starting with 2 (landline) or 69 (mobile).
        $s = strlen($s) === 10 && preg_match('/^(2|69)/', $s) ? '+' . $defaultCountry . $s : '+' . ltrim($s, '0');
    }
    return preg_match('/^\+[1-9]\d{7,14}$/', $s) ? $s : null;
}

/* ---------- Settings ---------- */

function sms_settings(string $tenantId): array
{
    $s = tenant_setting($tenantId, 'sms', []);
    $ev = (array) ($s['events'] ?? []);
    return [
        'enabled' => (bool) ($s['enabled'] ?? false),
        'provider' => in_array($s['provider'] ?? '', ['twilio', 'http'], true) ? $s['provider'] : 'twilio',
        'twilioSid' => (string) ($s['twilioSid'] ?? ''),
        'twilioToken' => decrypt_secret($s['twilioToken'] ?? null),
        'hasTwilioToken' => !empty($s['twilioToken']),
        'from' => (string) ($s['from'] ?? ''),
        'httpUrl' => (string) ($s['httpUrl'] ?? ''),
        'httpFormat' => ($s['httpFormat'] ?? 'json') === 'form' ? 'form' : 'json',
        'httpBody' => (string) ($s['httpBody'] ?? '{"to": "{to}", "from": "{from}", "text": "{message}"}'),
        'httpAuth' => decrypt_secret($s['httpAuth'] ?? null),
        'hasHttpAuth' => !empty($s['httpAuth']),
        'events' => [
            'customer' => array_values(array_intersect((array) ($ev['customer'] ?? ['received', 'status']), array_keys(SMS_EVENTS['customer']))),
            'staff' => array_values(array_intersect((array) ($ev['staff'] ?? ['urgent']), array_keys(SMS_EVENTS['staff']))),
        ],
    ];
}

function channel_settings(string $tenantId, string $channel): array
{
    $s = tenant_setting($tenantId, $channel, []);
    $base = ['enabled' => (bool) ($s['enabled'] ?? false), 'welcome' => (string) ($s['welcome'] ?? 'Hi! Write your question and our support team will answer here.')];
    if ($channel === 'viber') return $base + [
        'token' => decrypt_secret($s['token'] ?? null), 'hasToken' => !empty($s['token']),
        'name' => mb_substr((string) ($s['name'] ?? ''), 0, 28) ?: 'Support',
    ];
    return $base + [
        'phoneNumberId' => (string) ($s['phoneNumberId'] ?? ''),
        'token' => decrypt_secret($s['token'] ?? null), 'hasToken' => !empty($s['token']),
        'appSecret' => decrypt_secret($s['appSecret'] ?? null), 'hasAppSecret' => !empty($s['appSecret']),
        'verifyToken' => (string) ($s['verifyToken'] ?? ''),
    ];
}

/* ---------- HTTP ---------- */

/** One HTTP call to an outside API. Returns [status code, decoded JSON or raw text, error]. */
function messaging_http(string $method, string $url, array $headers, ?string $body, ?string $basicAuth = null): array
{
    if (!function_exists('curl_init')) return [0, null, 'The PHP curl extension is required'];
    $ch = curl_init($url);
    $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8];
    if ($body !== null) $opt[CURLOPT_POSTFIELDS] = $body;
    if ($basicAuth !== null) $opt[CURLOPT_USERPWD] = $basicAuth;
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $raw === false ? curl_error($ch) : '';
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [$code, $json ?? $raw, $err];
}

/* ---------- SMS ---------- */

/** Send one SMS. Returns ['ok' => bool, 'error' => ?string]. */
function sms_send(string $tenantId, string $to, string $text): array
{
    $s = sms_settings($tenantId);
    if (!$s['enabled']) return ['ok' => false, 'skip' => true, 'error' => 'SMS is off'];
    $to = phone_normalize($to);
    if (!$to) return ['ok' => false, 'skip' => true, 'error' => 'No valid mobile number'];
    $text = mb_substr(trim($text), 0, 459); // up to 3 SMS parts
    if ($s['provider'] === 'twilio') {
        if ($s['twilioSid'] === '' || $s['twilioToken'] === '' || $s['from'] === '') return ['ok' => false, 'skip' => true, 'error' => 'Twilio isn’t fully set up'];
        $base = rtrim((string) env('TWILIO_API_BASE', 'https://api.twilio.com'), '/');
        [$code, $res, $err] = messaging_http('POST', $base . '/2010-04-01/Accounts/' . rawurlencode($s['twilioSid']) . '/Messages.json',
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query(['To' => $to, 'From' => $s['from'], 'Body' => $text]), $s['twilioSid'] . ':' . $s['twilioToken']);
    } else {
        if (!preg_match('#^https?://#', $s['httpUrl'])) return ['ok' => false, 'skip' => true, 'error' => 'The SMS gateway URL isn’t set'];
        $json = $s['httpFormat'] === 'json';
        $enc = fn (string $v) => $json ? substr(json_encode($v, JSON_UNESCAPED_UNICODE), 1, -1) : rawurlencode($v);
        $body = strtr($s['httpBody'], ['{to}' => $enc($to), '{to_digits}' => $enc(ltrim($to, '+')), '{from}' => $enc($s['from']), '{message}' => $enc($text)]);
        $headers = ['Content-Type: ' . ($json ? 'application/json' : 'application/x-www-form-urlencoded'), 'Accept: application/json'];
        // Either a full header line ("X-Api-Key: …") or the value of Authorization ("Bearer …", "Basic …").
        if ($s['httpAuth'] !== '') $headers[] = preg_match('/^[A-Za-z0-9-]+:\s/', $s['httpAuth']) ? $s['httpAuth'] : 'Authorization: ' . $s['httpAuth'];
        [$code, $res, $err] = messaging_http('POST', $s['httpUrl'], $headers, $body);
    }
    if ($err !== '') return ['ok' => false, 'error' => 'Could not reach the SMS service: ' . $err];
    if ($code >= 200 && $code < 300) return ['ok' => true];
    $msg = is_array($res) ? (string) ($res['message'] ?? $res['error'] ?? json_encode($res)) : mb_substr((string) $res, 0, 200);
    return ['ok' => false, 'error' => "SMS service answered $code: $msg"];
}

/** The SMS text for a notification. */
function sms_text(string $tenantId, array $p): string
{
    $tenant = (string) q_val('SELECT "name" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
    $t = $p['ticket'] ?? [];
    $line = trim(preg_replace('/\s+/', ' ', (string) ($p['line'] ?? '')));
    $url = app_url() . '/tickets/' . ($t['id'] ?? '');
    return mb_substr("$tenant: $line", 0, 300) . ($t ? " $url" : '');
}

/** Should this notification also go out as SMS? Called by dispatch() for each recipient. */
function sms_wanted(string $tenantId, array $u, string $event, array $ticket, int $ticketOrder, int $highOrder): bool
{
    static $cfg = [];
    $cfg[$tenantId] ??= sms_settings($tenantId);
    $s = $cfg[$tenantId];
    if (!$s['enabled'] || empty($u['phone'])) return false;
    if ($u['role'] === 'CUSTOMER') {
        if ($event === 'status') return in_array('status', $s['events']['customer'], true) && !empty($ticket['closedAt']);
        return in_array($event, $s['events']['customer'], true);
    }
    if ($event === 'new_ticket') return in_array('urgent', $s['events']['staff'], true) && $ticketOrder >= $highOrder;
    return in_array($event, $s['events']['staff'], true);
}

/* ---------- Viber & WhatsApp: inbound ---------- */

/**
 * A message from Viber / WhatsApp: find or create the customer, then add it to their
 * open ticket from this app (if any, updated in the last 14 days) or open a new one.
 */
function channel_inbound(string $tenantId, string $channel, string $externalId, string $name, ?string $phone, string $text): ?array
{
    $now = now_iso();
    $contact = q_one('SELECT * FROM "ChannelContact" WHERE "tenantId" = ? AND "channel" = ? AND "externalId" = ?', [$tenantId, $channel, $externalId]);
    $user = $contact ? q_one('SELECT * FROM "User" WHERE "id" = ? AND "active" = 1', [$contact['userId']]) : null;
    if (!$user && $phone) $user = q_one('SELECT * FROM "User" WHERE "tenantId" = ? AND "phone" = ? AND "active" = 1 ORDER BY "createdAt" LIMIT 1', [$tenantId, $phone]);
    if (!$user) {
        // No email from these apps: a placeholder address that is never mailed.
        $email = $channel . '-' . substr(hash('sha256', $tenantId . $externalId), 0, 16) . '@' . $channel . '.invalid';
        $user = find_or_create_customer($tenantId, $email, mb_substr($name !== '' ? $name : CHAT_CHANNELS[$channel] . ' customer', 0, 80));
        if (is_string($user)) { error_log("[$channel] $user"); return null; }
        if ($phone && empty($user['phone'])) { db_update('User', ['phone' => $phone], '"id" = ?', [$user['id']]); $user['phone'] = $phone; }
    }
    if ($contact) db_update('ChannelContact', ['userId' => $user['id'], 'name' => mb_substr($name, 0, 120), 'lastInboundAt' => $now], '"id" = ?', [$contact['id']]);
    else db_insert('ChannelContact', ['id' => cuid(), 'tenantId' => $tenantId, 'channel' => $channel, 'externalId' => $externalId, 'userId' => $user['id'], 'name' => mb_substr($name, 0, 120), 'phone' => $phone, 'createdAt' => $now, 'lastInboundAt' => $now]);

    $closed = closed_status_keys(tenant_statuses($tenantId));
    $open = q_one('SELECT * FROM "Ticket" WHERE "tenantId" = ? AND "requesterId" = ? AND "source" = ? AND "updatedAt" >= ?'
        . ($closed ? ' AND "status" NOT IN (' . in_ph($closed) . ')' : '') . ' ORDER BY "updatedAt" DESC LIMIT 1', [$tenantId, $user['id'], $channel, now_iso(-14 * 86400), ...$closed]);
    $html = '<p>' . nl2br(e($text), false) . '</p>';
    if ($open) {
        add_comment($open, $user, $tenantId, $html, false);
        return find_ticket($open['id'], $tenantId);
    }
    $subject = mb_substr(preg_replace('/\s+/', ' ', trim($text)), 0, 80) ?: CHAT_CHANNELS[$channel] . ' message';
    $t = create_ticket($tenantId, $user, ['subject' => $subject, 'description' => $html, 'source' => $channel], $user);
    if (is_string($t)) { error_log("[$channel] $t"); return null; }
    // Confirm on the same app (instead of email / SMS).
    notify_queue($tenantId, $user['id'], $channel, 'received', $t['id'], ['externalId' => $externalId,
        'text' => 'Thanks — we received your request #' . (int) $t['number'] . '. Our team will reply here.'], now_iso());
    notify_flush_after_response();
    return $t;
}

/** Text of an incoming Viber / WhatsApp message that isn't plain text. */
function channel_media_note(string $type, ?string $url, ?string $caption): string
{
    $label = ['picture' => 'a photo', 'image' => 'a photo', 'video' => 'a video', 'file' => 'a file', 'document' => 'a file', 'audio' => 'a voice message', 'location' => 'a location', 'sticker' => 'a sticker', 'contact' => 'a contact'][$type] ?? 'a ' . $type;
    return trim(($caption ? $caption . "\n" : '') . "[Sent $label" . ($url ? ": $url" : '') . ']');
}

/* ---------- Viber & WhatsApp: outbound ---------- */

/** Send a text to a Viber / WhatsApp contact. Returns ['ok' => bool, 'error' => ?string]. */
function channel_send(string $tenantId, string $channel, string $externalId, string $text): array
{
    $s = channel_settings($tenantId, $channel);
    if (!$s['enabled'] || $s['token'] === '') return ['ok' => false, 'skip' => true, 'error' => CHAT_CHANNELS[$channel] . ' isn’t connected'];
    $text = mb_substr(trim($text), 0, $channel === 'viber' ? 7000 : 4096);
    if ($channel === 'viber') {
        $base = rtrim((string) env('VIBER_API_BASE', 'https://chatapi.viber.com'), '/');
        [$code, $res, $err] = messaging_http('POST', $base . '/pa/send_message', ['Content-Type: application/json', 'X-Viber-Auth-Token: ' . $s['token']],
            json_encode(['receiver' => $externalId, 'min_api_version' => 1, 'sender' => ['name' => $s['name']], 'type' => 'text', 'text' => $text], JSON_UNESCAPED_UNICODE));
        if ($err !== '') return ['ok' => false, 'error' => 'Could not reach Viber: ' . $err];
        if ($code === 200 && (int) ($res['status'] ?? -1) === 0) return ['ok' => true];
        return ['ok' => false, 'error' => 'Viber: ' . (is_array($res) ? ($res['status_message'] ?? 'error ' . ($res['status'] ?? $code)) : "HTTP $code")];
    }
    if ($s['phoneNumberId'] === '') return ['ok' => false, 'skip' => true, 'error' => 'WhatsApp phone number ID is missing'];
    $base = rtrim((string) env('WHATSAPP_API_BASE', 'https://graph.facebook.com/v21.0'), '/');
    [$code, $res, $err] = messaging_http('POST', $base . '/' . rawurlencode($s['phoneNumberId']) . '/messages', ['Content-Type: application/json', 'Authorization: Bearer ' . $s['token']],
        json_encode(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => ltrim($externalId, '+'), 'type' => 'text', 'text' => ['preview_url' => true, 'body' => $text]], JSON_UNESCAPED_UNICODE));
    if ($err !== '') return ['ok' => false, 'error' => 'Could not reach WhatsApp: ' . $err];
    if ($code >= 200 && $code < 300) return ['ok' => true];
    $e = is_array($res) ? ($res['error'] ?? []) : [];
    // 131047: more than 24 hours since the customer last wrote — WhatsApp only allows approved templates then.
    if ((int) ($e['code'] ?? 0) === 131047) return ['ok' => false, 'skip' => true, 'error' => 'WhatsApp: the customer last wrote more than 24 hours ago, so a free reply isn’t allowed'];
    return ['ok' => false, 'error' => 'WhatsApp: ' . ($e['message'] ?? "HTTP $code")];
}

/** After a public staff reply on a Viber / WhatsApp ticket: queue it for that app (retried like other notifications). */
function channel_reply_hook(array $ticket, array $author, string $tenantId, string $bodyHtml, bool $isInternal): void
{
    $channel = $ticket['source'] ?? '';
    if ($isInternal || !isset(CHAT_CHANNELS[$channel]) || !is_staff($author['role'])) return;
    $contact = q_one('SELECT * FROM "ChannelContact" WHERE "tenantId" = ? AND "channel" = ? AND "userId" = ? ORDER BY "lastInboundAt" DESC LIMIT 1', [$tenantId, $channel, $ticket['requesterId']]);
    if (!$contact) return;
    $text = trim(html_to_text($bodyHtml));
    if ($text === '') return;
    notify_queue($tenantId, $ticket['requesterId'], $channel, 'reply', $ticket['id'], ['text' => first_name($author['name']) . ': ' . $text, 'externalId' => $contact['externalId']], now_iso());
    notify_flush_after_response();
}
