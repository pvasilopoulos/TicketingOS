<?php
/**
 * Settings → Integrations (settings.integrations): support form & widget, API keys, webhooks.
 * @var array $me @var string $tenantId @var callable $s
 */
$tenant = q_one('SELECT "slug","name" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
$wf = webform_settings($tenantId);
$formUrl = app_url() . '/support/' . $tenant['slug'];
$keys = q_all('SELECT k.*, u."name" AS "owner" FROM "ApiKey" k JOIN "User" u ON u."id" = k."userId" WHERE k."tenantId" = ? ORDER BY k."revokedAt" IS NOT NULL, k."createdAt" DESC', [$tenantId]);
$hooks = q_all('SELECT * FROM "Webhook" WHERE "tenantId" = ? ORDER BY "createdAt"', [$tenantId]);
$deliveries = [];
foreach ($hooks as $h) $deliveries[$h['id']] = q_all('SELECT "id","event","status","attempts","responseCode","error","createdAt" FROM "WebhookDelivery" WHERE "webhookId" = ? ORDER BY "createdAt" DESC LIMIT 8', [$h['id']]);
$ks = $s('apikeys');
$ws = $s('webhooks');
$fs = $s('webform');
$copyBox = fn (string $value, string $label = 'Copy') => '<div class="flex gap-2"><input readonly value="' . e($value) . '" class="h-9 min-w-0 flex-1 rounded-lg border border-border bg-surface-2 px-3 font-mono text-xs" onclick="this.select()">'
    . '<button type="button" data-copy="' . e($value) . '" class="' . btn_cls('outline', 'sm') . '">' . icon('copy', 13) . ' ' . e($label) . '</button></div>';
$toggle = fn (string $name, bool $on) => '<span class="relative mt-0.5 inline-flex shrink-0 items-center"><input type="checkbox" name="' . $name . '" ' . ($on ? 'checked' : '') . ' class="peer sr-only">'
    . '<span class="h-5 w-9 rounded-full bg-border transition-colors peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40"></span>'
    . '<span class="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4"></span></span>';

$hookForm = function (?array $h) use ($toggle): string {
    $events = $h ? (json_decode($h['events'], true) ?: []) : ['ticket.created', 'ticket.closed'];
    $out = '<form method="post" action="/action/webhook-save" class="space-y-4">' . form_meta('webhooks', '/settings#integrations')
        . ($h ? '<input type="hidden" name="id" value="' . e($h['id']) . '">' : '')
        . '<div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_10rem]">'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Name</label><input name="name" maxlength="60" value="' . e($h['name'] ?? '') . '" placeholder="Slack #support" class="' . INPUT_CLS . '"></div>'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Endpoint URL</label><input name="url" type="url" required value="' . e($h['url'] ?? '') . '" placeholder="https://hooks.slack.com/services/…" class="' . INPUT_CLS . '"></div>'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Format</label><select name="format" class="' . SELECT_CLS . '">' . select_options(WEBHOOK_FORMATS, $h['format'] ?? 'json') . '</select></div></div>'
        . '<div><span class="mb-1.5 block text-xs font-medium text-muted">Events</span><div class="grid gap-1.5 sm:grid-cols-2">';
    foreach (WEBHOOK_EVENTS as $k => $label) {
        $out .= '<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="events[' . $k . ']" ' . (in_array($k, $events, true) ? 'checked' : '') . ' class="h-4 w-4 rounded border-border accent-brand">'
            . e($label) . ' <code class="text-[11px] text-muted">' . e($k) . '</code></label>';
    }
    $out .= '</div></div><div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">'
        . '<label class="flex items-center gap-2">' . $toggle('active', !$h || (int) $h['active']) . ' Active</label>'
        . ($h ? '<label class="flex items-center gap-2"><input type="checkbox" name="rotate" class="h-4 w-4 rounded border-border accent-brand"> Generate a new signing secret</label>' : '')
        . '</div><div class="flex flex-wrap gap-2"><button type="submit" class="' . btn_cls('primary', 'sm') . '">' . icon('check', 14) . ($h ? ' Save' : ' Add webhook') . '</button>';
    if ($h) {
        $out .= '<button type="submit" formaction="/action/webhook-test" formnovalidate class="' . btn_cls('outline', 'sm') . '">' . icon('send', 14) . ' Send test</button>'
            . '<button type="submit" formaction="/action/webhook-delete" formnovalidate data-confirm="Delete the webhook “' . e($h['name']) . '”?" class="' . btn_cls('ghost', 'sm') . ' text-red-600">' . icon('trash-2', 14) . ' Delete</button>';
    }
    return $out . '</div></form>';
};
?>
<!-- Support form & widget -->
<div class="rounded-xl border border-border bg-surface">
  <div class="flex flex-wrap items-start gap-4 p-5 sm:p-6">
    <span class="hidden h-11 w-11 shrink-0 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 sm:grid"><?= icon('globe', 22) ?></span>
    <div class="min-w-0 flex-1">
      <h2 class="flex flex-wrap items-center gap-2 font-semibold">Support form & website widget <?= $wf['enabled'] ? '<span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">Live</span>' : '<span class="rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium text-muted">Off</span>' ?></h2>
      <p class="mt-0.5 text-sm text-muted">Let anyone open a ticket without an account. New senders become customers (joined to their organization by email domain) and get a link to follow their ticket. Protected against spam with a hidden field, timing and rate limits.</p>
    </div>
    <?php if ($wf['enabled']): ?><a href="<?= e($formUrl) ?>" target="_blank" rel="noopener" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('external-link', 14) ?> Open form</a><?php endif; ?>
  </div>
  <form method="post" action="/action/webform-save" class="space-y-4 border-t border-border p-5 sm:p-6">
    <?= form_meta('webform', '/settings#integrations') ?>
    <?= banner($fs, true) ?>
    <label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-border p-4">
      <span><span class="block text-sm font-medium">Accept requests from the public form</span><span class="block text-xs text-muted"><?= e($formUrl) ?></span></span>
      <?= $toggle('enabled', $wf['enabled']) ?>
    </label>
    <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto]">
      <div><label class="mb-1 block text-xs font-medium text-muted">Title</label><input name="title" maxlength="80" value="<?= e($wf['title']) ?>" class="<?= INPUT_CLS ?>"></div>
      <div><label class="mb-1 block text-xs font-medium text-muted">Intro text</label><input name="intro" maxlength="300" value="<?= e($wf['intro']) ?>" class="<?= INPUT_CLS ?>"></div>
      <div><label class="mb-1 block text-xs font-medium text-muted">Colour</label><input type="color" name="color" value="<?= e($wf['color']) ?>" class="h-10 w-14 cursor-pointer rounded-lg border border-border bg-surface p-1"></div>
    </div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="showCategory" <?= $wf['showCategory'] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> Let visitors pick a category (fields for that category appear too)</label>
    <button type="submit" class="<?= btn_cls('primary', 'sm') ?>"><?= icon('check', 14) ?> Save</button>
    <?php if ($wf['enabled']): ?>
      <div class="space-y-3 rounded-xl bg-surface-2/50 p-4">
        <div><p class="mb-1.5 text-xs font-semibold text-muted">Direct link</p><?= $copyBox($formUrl) ?></div>
        <div><p class="mb-1.5 text-xs font-semibold text-muted">Website widget — paste before &lt;/body&gt;</p><?= $copyBox('<script src="' . app_url() . '/widget.js" data-workspace="' . $tenant['slug'] . '" data-color="' . $wf['color'] . '" data-label="Help" async></script>', 'Copy code') ?></div>
      </div>
    <?php endif; ?>
  </form>
</div>

<!-- API keys -->
<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('key-round', 'API keys'), '<a href="#api-docs" class="text-xs font-medium text-brand hover:underline">API reference</a>') ?>
  <div class="space-y-4 p-5 sm:p-6">
    <?php if ($ks && !empty($ks['token'])): ?>
      <div class="space-y-2 rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4">
        <p class="flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-400"><?= icon('circle-check', 16) ?> <?= e($ks['success']) ?></p>
        <?= $copyBox($ks['token'], 'Copy key') ?>
      </div>
    <?php elseif ($ks): ?><?= banner($ks, true) ?><?php endif; ?>
    <form method="post" action="/action/apikey-create" class="flex flex-wrap items-end gap-2">
      <?= form_meta('apikeys', '/settings#integrations') ?>
      <div class="min-w-0 flex-1 basis-48"><label class="mb-1 block text-xs font-medium text-muted">New key name</label><input name="name" required maxlength="60" placeholder="e.g. CRM sync" class="<?= INPUT_CLS ?>"></div>
      <div><label class="mb-1 block text-xs font-medium text-muted">Access</label><select name="scope" class="<?= SELECT_CLS ?>"><?= select_options(['read' => 'Read only', 'write' => 'Read & write'], 'read') ?></select></div>
      <button type="submit" class="<?= btn_cls('primary') ?>"><?= icon('plus', 15) ?> Create key</button>
    </form>
    <p class="text-xs text-muted">A key acts as you (<?= e($me['name']) ?>) with your permissions. Keys are shown once; only a hash is stored.</p>
    <?php if ($keys): ?>
      <ul class="divide-y divide-border rounded-xl border border-border">
        <?php foreach ($keys as $k): $revoked = (bool) $k['revokedAt']; ?>
          <li class="flex flex-wrap items-center gap-3 px-4 py-2.5 <?= $revoked ? 'opacity-60' : '' ?>">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-surface-2 text-muted"><?= icon('key-round', 14) ?></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-medium"><?= e($k['name']) ?> <code class="ml-1 text-xs font-normal text-muted">tos_<?= e($k['prefix']) ?>_…</code></span>
              <span class="block text-xs text-muted"><?= $k['scope'] === 'write' ? 'Read & write' : 'Read only' ?> · as <?= e($k['owner']) ?> · <?= $k['lastUsedAt'] ? 'used ' . e(time_ago($k['lastUsedAt'])) : 'never used' ?><?= $revoked ? ' · revoked ' . e(time_ago($k['revokedAt'])) : '' ?></span>
            </span>
            <?php if (!$revoked): ?>
              <form method="post" action="/action/apikey-revoke" data-confirm="Revoke “<?= e($k['name']) ?>”? Anything using it stops working.">
                <?= form_meta('apikeys', '/settings#integrations') ?><input type="hidden" name="id" value="<?= e($k['id']) ?>">
                <button type="submit" class="<?= btn_cls('ghost', 'sm') ?> text-red-600">Revoke</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<!-- Webhooks -->
<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('zap', 'Webhooks'), '<button type="button" data-toggle-target="#hook-new" class="' . btn_cls('outline', 'sm') . '">' . icon('plus', 13) . ' Add webhook</button>') ?>
  <div class="space-y-3 p-5 sm:p-6">
    <?= banner($ws, true) ?>
    <p class="text-sm text-muted">Send events to Slack, Microsoft Teams or your own systems. JSON payloads are signed with <code class="rounded bg-surface-2 px-1 text-xs">X-TicketingOS-Signature: sha256=HMAC(secret, body)</code>. Failed deliveries are retried up to <?= WEBHOOK_MAX_ATTEMPTS ?> times.</p>
    <div id="hook-new" class="rounded-xl border border-dashed border-border p-4" <?= $hooks ? 'hidden' : '' ?>><?= $hookForm(null) ?></div>
    <?php foreach ($hooks as $h):
        $ok = (int) $h['failCount'] === 0 && $h['lastAt'];
        $statusBadge = !(int) $h['active'] ? '<span class="rounded-full bg-surface-2 px-2 py-0.5 text-[11px] text-muted">Paused</span>'
            : (!$h['lastAt'] ? '<span class="rounded-full bg-surface-2 px-2 py-0.5 text-[11px] text-muted">No deliveries yet</span>'
            : ($ok ? '<span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] text-emerald-700 dark:text-emerald-400">Healthy</span>'
                   : '<span class="rounded-full bg-red-500/10 px-2 py-0.5 text-[11px] text-red-700 dark:text-red-400">' . (int) $h['failCount'] . ' failing</span>')); ?>
      <details class="group rounded-xl border border-border">
        <summary class="flex cursor-pointer list-none flex-wrap items-center gap-3 px-4 py-3">
          <span class="grid h-8 w-8 place-items-center rounded-lg bg-pink-500/10 text-pink-600"><?= icon('zap', 14) ?></span>
          <span class="min-w-0 flex-1"><span class="flex items-center gap-2 text-sm font-medium"><?= e($h['name']) ?> <?= $statusBadge ?></span><span class="block truncate text-xs text-muted"><?= e(WEBHOOK_FORMATS[$h['format']] ?? $h['format']) ?> · <?= e($h['url']) ?></span></span>
          <?= icon('chevron-down', 16, 'text-muted transition-transform group-open:rotate-180') ?>
        </summary>
        <div class="space-y-4 border-t border-border p-4">
          <?= $hookForm($h) ?>
          <?php if ($h['format'] === 'json'): ?><div><p class="mb-1.5 text-xs font-semibold text-muted">Signing secret</p><?= $copyBox($h['secret']) ?></div><?php endif; ?>
          <?php if ($deliveries[$h['id']]): ?>
            <div>
              <p class="mb-1.5 text-xs font-semibold text-muted">Recent deliveries</p>
              <ul class="divide-y divide-border rounded-lg border border-border text-xs">
                <?php foreach ($deliveries[$h['id']] as $d): ?>
                  <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                    <span class="<?= $d['status'] === 'ok' ? 'text-emerald-600' : ($d['status'] === 'failed' ? 'text-red-600' : 'text-amber-600') ?>">● <?= e($d['status']) ?></span>
                    <code><?= e($d['event']) ?></code>
                    <span class="text-muted"><?= $d['responseCode'] ? 'HTTP ' . (int) $d['responseCode'] : '' ?> · <?= (int) $d['attempts'] ?> attempt(s) · <?= e(time_ago($d['createdAt'])) ?></span>
                    <?php if ($d['error']): ?><span class="basis-full truncate text-red-600"><?= e($d['error']) ?></span><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
</div>

<!-- API reference -->
<details class="group rounded-xl border border-border bg-surface" id="api-docs">
  <summary class="flex cursor-pointer list-none items-center gap-3 px-5 py-4">
    <span class="grid h-8 w-8 place-items-center rounded-lg bg-surface-2 text-muted"><?= icon('code', 15) ?></span>
    <span class="flex-1"><span class="block text-sm font-semibold">REST API reference</span><span class="block text-xs text-muted"><?= e(app_url()) ?>/api/v1</span></span>
    <?= icon('chevron-down', 16, 'text-muted transition-transform group-open:rotate-180') ?>
  </summary>
  <div class="space-y-4 border-t border-border p-5 text-sm">
    <p class="text-muted">JSON in, JSON out. Authenticate with <code class="rounded bg-surface-2 px-1 text-xs">Authorization: Bearer tos_…</code>. Limit: 120 requests per minute per key. Errors look like <code class="rounded bg-surface-2 px-1 text-xs">{"error":{"code","message"}}</code>.</p>
    <div class="overflow-x-auto rounded-xl border border-border">
      <table class="w-full min-w-[34rem] text-xs">
        <tbody class="divide-y divide-border">
          <?php foreach ([
              ['GET', '/me', 'Workspace, acting user and permissions'],
              ['GET', '/tickets', 'List — filters: q, status, priority, category, group, org, source, assignee=me|none, overdue=1, updated_since, page, per_page'],
              ['POST', '/tickets', 'Create — subject, description, priority, category, groupId, fields{}, requester{email,name}'],
              ['GET', '/tickets/{number}', 'One ticket with fields, tags, assignee, satisfaction'],
              ['PATCH', '/tickets/{number}', 'Update — status, priority, assignee (id/email/null), groupId, categoryId, fields{}'],
              ['GET', '/tickets/{number}/comments', 'Conversation (internal notes if the key may read them)'],
              ['POST', '/tickets/{number}/comments', 'Reply — body, internal (true for a note)'],
              ['GET', '/users · /organizations · /groups · /fields · /meta', 'Reference data'],
          ] as [$m, $path, $desc]): ?>
            <tr><td class="w-16 px-3 py-2"><span class="rounded px-1.5 py-0.5 font-mono text-[10px] font-semibold <?= $m === 'GET' ? 'bg-sky-500/10 text-sky-700 dark:text-sky-300' : ($m === 'POST' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/10 text-amber-700 dark:text-amber-300') ?>"><?= $m ?></span></td>
              <td class="whitespace-nowrap px-3 py-2 font-mono"><?= e($path) ?></td><td class="px-3 py-2 text-muted"><?= e($desc) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <pre class="overflow-x-auto rounded-xl bg-slate-900 p-4 text-xs leading-relaxed text-slate-100">curl -X POST <?= e(app_url()) ?>/api/v1/tickets \
  -H "Authorization: Bearer tos_…" -H "Content-Type: application/json" \
  -d '{"subject":"Printer offline","description":"Since this morning","priority":"HIGH",
       "requester":{"email":"jane@acme.com","name":"Jane"}}'</pre>
  </div>
</details>
