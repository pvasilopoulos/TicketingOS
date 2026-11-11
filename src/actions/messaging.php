<?php
declare(strict_types=1);

/* SMS, Viber and WhatsApp: webhooks from the apps, settings and tests. */

/** POST /hooks/viber/{slug} — events from the Viber bot (signed with the bot token). */
function public_viber_hook(array $tenant): never
{
    $s = channel_settings($tenant['id'], 'viber');
    $raw = (string) file_get_contents('php://input');
    if (!$s['enabled'] || $s['token'] === '') json_out(['error' => 'Viber is not connected'], 404);
    $sig = (string) ($_SERVER['HTTP_X_VIBER_CONTENT_SIGNATURE'] ?? ($_GET['sig'] ?? ''));
    if (!hash_equals(hash_hmac('sha256', $raw, $s['token']), $sig)) json_out(['error' => 'Bad signature'], 403);
    $e = json_decode($raw, true) ?: [];
    $event = (string) ($e['event'] ?? '');
    if ($event === 'conversation_started') {
        // Viber shows this reply as the welcome message.
        json_out(['sender' => ['name' => $s['name']], 'type' => 'text', 'text' => $s['welcome']]);
    }
    if ($event === 'message') {
        $snd = (array) ($e['sender'] ?? []);
        $m = (array) ($e['message'] ?? []);
        $type = (string) ($m['type'] ?? 'text');
        $text = $type === 'text' ? (string) ($m['text'] ?? '') : channel_media_note($type, $m['media'] ?? null, $m['text'] ?? null);
        $id = (string) ($snd['id'] ?? '');
        if ($id !== '' && trim($text) !== '' && rate_allow('viber:' . $tenant['id'] . ':' . $id, 60, 3600)) {
            channel_inbound($tenant['id'], 'viber', $id, (string) ($snd['name'] ?? ''), null, $text);
        }
    }
    json_out(['status' => 0]);
}

/** GET (verification) / POST /hooks/whatsapp/{slug} — WhatsApp Cloud API webhooks (signed with the app secret). */
function public_whatsapp_hook(array $tenant): never
{
    $s = channel_settings($tenant['id'], 'whatsapp');
    if (!$s['enabled']) json_out(['error' => 'WhatsApp is not connected'], 404);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Meta checks the address when you save the webhook: echo the challenge if the verify token matches.
        if (($_GET['hub_mode'] ?? '') === 'subscribe' && $s['verifyToken'] !== '' && hash_equals($s['verifyToken'], (string) ($_GET['hub_verify_token'] ?? ''))) {
            header('Content-Type: text/plain');
            echo (string) ($_GET['hub_challenge'] ?? '');
            throw new HttpExit();
        }
        json_out(['error' => 'Verification failed'], 403);
    }
    $raw = (string) file_get_contents('php://input');
    $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    if ($s['appSecret'] === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $s['appSecret']), $sig)) json_out(['error' => 'Bad signature'], 403);
    $e = json_decode($raw, true) ?: [];
    foreach ((array) ($e['entry'] ?? []) as $entry) foreach ((array) ($entry['changes'] ?? []) as $ch) {
        $v = (array) ($ch['value'] ?? []);
        $names = [];
        foreach ((array) ($v['contacts'] ?? []) as $c) $names[(string) ($c['wa_id'] ?? '')] = (string) ($c['profile']['name'] ?? '');
        foreach ((array) ($v['messages'] ?? []) as $m) {
            $from = (string) ($m['from'] ?? '');
            $type = (string) ($m['type'] ?? 'text');
            $text = $type === 'text' ? (string) ($m['text']['body'] ?? '')
                : ($type === 'button' ? (string) ($m['button']['text'] ?? '') : channel_media_note($type, null, $m[$type]['caption'] ?? null));
            if ($from === '' || trim($text) === '' || !rate_allow('wa:' . $tenant['id'] . ':' . $from, 60, 3600)) continue;
            channel_inbound($tenant['id'], 'whatsapp', $from, $names[$from] ?? '', phone_normalize('+' . $from), $text);
        }
    }
    json_out(['ok' => true]);
}

/* ---------- Settings ---------- */

function act_sms_settings_save(array $in): ?array
{
    if (!($ctx = ctx_with('settings.messaging'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $cur = tenant_setting($tenantId, 'sms', []);
    $provider = ($in['provider'] ?? '') === 'http' ? 'http' : 'twilio';
    $url = trim((string) ($in['httpUrl'] ?? ''));
    if ($provider === 'http' && $url !== '' && !preg_match('#^https?://#', $url)) return ['error' => 'The gateway URL must start with https://'];
    $keep = fn (string $field) => ($v = trim((string) ($in[$field] ?? ''))) !== '' ? encrypt_secret($v) : ($cur[$field] ?? null);
    $enabled = ($in['enabled'] ?? '') === 'on';
    tenant_setting_set($tenantId, 'sms', [
        'enabled' => $enabled, 'provider' => $provider,
        'twilioSid' => trim((string) ($in['twilioSid'] ?? '')), 'twilioToken' => $keep('twilioToken'),
        'from' => mb_substr(trim((string) ($in['from'] ?? '')), 0, 20),
        'httpUrl' => $url, 'httpFormat' => ($in['httpFormat'] ?? '') === 'form' ? 'form' : 'json',
        'httpBody' => mb_substr((string) ($in['httpBody'] ?? ''), 0, 1000), 'httpAuth' => ($in['clearHttpAuth'] ?? '') === 'on' ? null : $keep('httpAuth'),
        'events' => [
            'customer' => array_values(array_intersect((array) ($in['customerEvents'] ?? []), array_keys(SMS_EVENTS['customer']))),
            'staff' => array_values(array_intersect((array) ($in['staffEvents'] ?? []), array_keys(SMS_EVENTS['staff']))),
        ],
    ]);
    return ['success' => $enabled ? 'SMS is on' : 'SMS settings saved'];
}

function act_sms_test(array $in): ?array
{
    if (!($ctx = ctx_with('settings.messaging'))) return NOT_ALLOWED;
    [$user, $tenantId] = $ctx;
    $to = phone_normalize((string) ($in['testTo'] ?? '')) ?? phone_normalize($user['phone'] ?? '');
    if (!$to) return ['error' => 'Enter a mobile number to send the test to'];
    $r = sms_send($tenantId, $to, app_name() . ': test message — SMS notifications work.');
    return $r['ok'] ? ['success' => "Test SMS sent to $to"] : ['error' => $r['error']];
}

function act_channel_settings_save(array $in): ?array
{
    if (!($ctx = ctx_with('settings.messaging'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $channel = ($in['channel'] ?? '') === 'whatsapp' ? 'whatsapp' : 'viber';
    $cur = tenant_setting($tenantId, $channel, []);
    $keep = fn (string $field) => ($v = trim((string) ($in[$field] ?? ''))) !== '' ? encrypt_secret($v) : ($cur[$field] ?? null);
    $enabled = ($in['enabled'] ?? '') === 'on';
    $data = ['enabled' => $enabled, 'token' => $keep('token'), 'welcome' => mb_substr(trim((string) ($in['welcome'] ?? '')), 0, 500)];
    if ($channel === 'viber') $data['name'] = mb_substr(trim((string) ($in['name'] ?? '')), 0, 28);
    else $data += ['phoneNumberId' => preg_replace('/\D/', '', (string) ($in['phoneNumberId'] ?? '')), 'appSecret' => $keep('appSecret'),
        'verifyToken' => (string) ($cur['verifyToken'] ?? '') ?: bin2hex(random_bytes(16))];
    if ($enabled && empty($data['token'])) return ['error' => 'Add the ' . ($channel === 'viber' ? 'bot token' : 'access token') . ' to turn ' . CHAT_CHANNELS[$channel] . ' on'];
    if ($enabled && $channel === 'whatsapp' && (empty($data['appSecret']) || $data['phoneNumberId'] === '')) return ['error' => 'Add the phone number ID and the app secret to turn WhatsApp on'];
    tenant_setting_set($tenantId, $channel, $data);
    return ['success' => CHAT_CHANNELS[$channel] . ($enabled ? ' is on' : ' settings saved')];
}

/** Tell Viber where to send messages (its set_webhook API needs a public https address). */
function act_viber_connect(array $in): ?array
{
    if (!($ctx = ctx_with('settings.messaging'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $s = channel_settings($tenantId, 'viber');
    if ($s['token'] === '') return ['error' => 'Save the bot token first'];
    $slug = (string) q_val('SELECT "slug" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
    $url = app_url() . '/hooks/viber/' . $slug;
    if (!str_starts_with($url, 'https://')) return ['error' => 'Viber needs a public https:// address — set APP_URL to your https domain'];
    $base = rtrim((string) env('VIBER_API_BASE', 'https://chatapi.viber.com'), '/');
    [$code, $res, $err] = messaging_http('POST', $base . '/pa/set_webhook', ['Content-Type: application/json', 'X-Viber-Auth-Token: ' . $s['token']],
        json_encode(['url' => $url, 'event_types' => ['delivered', 'failed', 'subscribed', 'unsubscribed', 'conversation_started'], 'send_name' => true]));
    if ($err !== '') return ['error' => 'Could not reach Viber: ' . $err];
    if ($code === 200 && (int) ($res['status'] ?? -1) === 0) return ['success' => 'Viber is connected: messages to your bot now open tickets'];
    return ['error' => 'Viber: ' . (is_array($res) ? ($res['status_message'] ?? 'error') : "HTTP $code")];
}

/** The person's own mobile number (Notifications page), for SMS. */
function act_my_phone_save(array $in): ?array
{
    [$user] = require_tenant_user();
    $raw = trim((string) ($in['phone'] ?? ''));
    $phone = $raw === '' ? null : phone_normalize($raw);
    if ($raw !== '' && !$phone) return ['error' => 'That doesn’t look like a mobile number (e.g. 6912345678 or +306912345678)'];
    db_update('User', ['phone' => $phone], '"id" = ?', [$user['id']]);
    return ['success' => $phone ? "Mobile number saved: $phone" : 'Mobile number removed'];
}
