<?php
declare(strict_types=1);

/* Settings → Integrations → AI assistant for customers. */
function act_ai_assist_save(array $in): ?array
{
    if (!($ctx = ctx_with('settings.ai'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $model = (string) ($in['model'] ?? '');
    $enabled = ($in['enabled'] ?? '') === 'on';
    if ($enabled && !ai_settings($tenantId)['enabled']) return ['error' => 'Turn on the AI assistant (with an API key) first'];
    tenant_setting_set($tenantId, 'aiAssist', [
        'enabled' => $enabled,
        'inMessages' => ($in['inMessages'] ?? '') === 'on',
        'onWebsite' => ($in['onWebsite'] ?? '') === 'on',
        'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 40) ?: 'AI assistant',
        'model' => isset(AI_MODELS[$model]) ? $model : '',
        'instructions' => mb_substr(trim((string) ($in['instructions'] ?? '')), 0, 6000),
        'dailyLimit' => max(10, min(20000, (int) ($in['dailyLimit'] ?? 500))),
    ]);
    return ['success' => $enabled ? 'The AI assistant now answers your customers' : 'Saved'];
}
