<?php
/**
 * Settings → Notifications & email (settings.notifications): who gets what, timing,
 * email texts and the delivery log.
 * @var array $me @var string $tenantId @var callable $s
 */
$policy = tenant_policy($tenantId);
$matrix = notify_matrix($tenantId);
$rules = notify_rules($tenantId);
$tpls = notify_templates($tenantId);
$ms = $s('notify-matrix');
$rs = $s('notify-rules');
$ts = $s('notify-tpl');
$ls = $s('notify-log');
$chIcon = ['inApp' => 'bell', 'push' => 'smartphone', 'email' => 'mail'];
$activeRole = isset(NOTIFY_ROLES[$_GET['role'] ?? '']) ? $_GET['role'] : 'agent';

// Delivery log
$logFilter = in_array($_GET['nlog'] ?? '', ['pending', 'digest', 'sent', 'failed', 'skipped'], true) ? $_GET['nlog'] : '';
$counts = array_column(q_all('SELECT "status", COUNT(*) AS n FROM "NotificationJob" WHERE "tenantId" = ? AND "createdAt" >= ? GROUP BY "status"', [$tenantId, now_iso(-7 * 86400)]), 'n', 'status');
$log = q_all('SELECT j.*, u."name" AS "uName", u."email" AS "uEmail", t."number" AS "tNumber" FROM "NotificationJob" j JOIN "User" u ON u."id" = j."userId" LEFT JOIN "Ticket" t ON t."id" = j."ticketId"
    WHERE j."tenantId" = ?' . ($logFilter ? ' AND j."status" = ?' : '') . ' ORDER BY j."createdAt" DESC LIMIT 60', $logFilter ? [$tenantId, $logFilter] : [$tenantId]);
$cronAt = cron_last_run();
$cronOk = $cronAt && ts($cronAt) > microtime(true) - 600;
$emailOn = (bool) (email_settings($tenantId)['enabled'] ?? false);
$statusTone = ['sent' => 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400', 'pending' => 'bg-sky-500/10 text-sky-700 dark:text-sky-300', 'sending' => 'bg-sky-500/10 text-sky-700',
    'digest' => 'bg-violet-500/10 text-violet-700 dark:text-violet-300', 'failed' => 'bg-red-500/10 text-red-700 dark:text-red-400', 'skipped' => 'bg-surface-2 text-muted'];
?>
<!-- Health -->
<div class="grid gap-3 sm:grid-cols-3">
  <div class="<?= cx('rounded-xl border p-4', $cronOk ? 'border-border bg-surface' : 'border-amber-300/60 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10') ?>">
    <p class="flex items-center gap-2 text-sm font-semibold"><?= icon($cronOk ? 'circle-check' : 'triangle-alert', 15, $cronOk ? 'text-emerald-600' : 'text-amber-600') ?> Background jobs</p>
    <p class="mt-1 text-xs text-muted"><?= $cronAt ? 'Last run ' . e(time_ago($cronAt)) : 'Never ran' ?>. <?= $cronOk ? 'Retries, reminders, SLA alerts and summaries are running.' : 'Set a cron every minute: php bin/cron.php (Plesk → Scheduled Tasks).' ?></p>
    <form method="post" action="/action/notify-run" data-ajax class="mt-2"><?= form_meta('notify-log') ?><button type="submit" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('play', 13) ?> Run now</button></form>
  </div>
  <div class="<?= cx('rounded-xl border p-4', $emailOn ? 'border-border bg-surface' : 'border-amber-300/60 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10') ?>">
    <p class="flex items-center gap-2 text-sm font-semibold"><?= icon('mail', 15, $emailOn ? 'text-emerald-600' : 'text-amber-600') ?> Email</p>
    <p class="mt-1 text-xs text-muted"><?= $emailOn ? 'Email sending is on.' : 'Email isn’t set up — emails are skipped. Set it up in the card below.' ?></p>
  </div>
  <div class="rounded-xl border border-border bg-surface p-4">
    <p class="flex items-center gap-2 text-sm font-semibold"><?= icon('send', 15, 'text-brand') ?> Try it</p>
    <p class="mt-1 text-xs text-muted">Sends a push and an email to you and shows the result.</p>
    <form method="post" action="/action/notify-test" data-ajax class="mt-2"><?= form_meta('notify-log') ?><button type="submit" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('send', 13) ?> Send test to me</button></form>
  </div>
</div>

<!-- Who gets what -->
<div class="rounded-xl border border-border bg-surface" id="notify-rules">
  <?= card_header(titled('bell-ring', 'Who gets what')) ?>
  <form method="post" action="/action/notify-matrix-save" class="space-y-5 px-5 pb-5" data-tabs>
    <?= form_meta('notify-matrix', '/settings#notifications') ?>
    <input type="hidden" name="_role" value="<?= e($activeRole) ?>" data-role-field>
    <?= banner($ms, true) ?>
    <div>
      <p class="text-sm font-semibold">Channels for the whole workspace</p>
      <p class="mb-2 text-xs text-muted">Turning one off stops it for everyone, whatever the rules below say.</p>
      <div class="flex flex-wrap gap-4">
        <?php foreach ([['allowInApp', 'In-app', 'bell'], ['allowPush', 'Push', 'smartphone'], ['allowEmail', 'Email', 'mail']] as [$n, $l, $ic]): ?>
          <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="<?= $n ?>" <?= $policy[$n] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"><?= icon($ic, 14, 'text-muted') ?> <?= $l ?></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="flex flex-wrap gap-1 rounded-xl bg-surface-2 p-1 text-sm font-medium" role="tablist">
      <?php foreach (NOTIFY_ROLES as $role => $label): ?>
        <button type="button" role="tab" data-tab="<?= $role ?>" aria-selected="<?= $role === $activeRole ? 'true' : 'false' ?>" onclick="this.form.querySelector('[data-role-field]').value='<?= $role ?>'"
                class="flex-1 rounded-lg px-3 py-1.5 text-muted transition aria-selected:bg-surface aria-selected:text-fg aria-selected:shadow-sm cursor-pointer"><?= e($label) ?></button>
      <?php endforeach; ?>
    </div>

    <?php foreach (NOTIFY_ROLES as $role => $label): ?>
      <div data-tab-panel="<?= $role ?>" <?= $role === $activeRole ? '' : 'hidden' ?> class="space-y-3">
        <div class="overflow-x-auto rounded-xl border border-border">
          <table class="w-full min-w-[34rem] text-sm">
            <thead><tr class="border-b border-border bg-surface-2/50 text-xs text-muted">
              <th class="px-3 py-2 text-left font-medium">Event</th>
              <?php foreach (CHANNELS as $ch): ?><th class="px-3 py-2 font-medium"><span class="inline-flex items-center gap-1"><?= icon($chIcon[$ch], 12) ?><?= CHANNEL_LABELS[$ch] ?></span></th><?php endforeach; ?>
            </tr></thead>
            <tbody class="divide-y divide-border">
              <?php foreach (EVENT_ROLES[$role] as $ev): ?>
                <tr>
                  <td class="px-3 py-2.5"><span class="block font-medium"><?= e(CATEGORY_LABELS[$ev]) ?></span><span class="block text-xs text-muted"><?= e(CATEGORY_HINTS[$ev]) ?></span></td>
                  <?php foreach (CHANNELS as $ch): $cell = $matrix[$role][$ev][$ch]; ?>
                    <td class="px-3 py-2.5 text-center">
                      <div class="inline-flex items-center gap-2">
                        <input type="checkbox" name="on[<?= $role ?>][<?= $ev ?>][<?= $ch ?>]" <?= $cell['on'] ? 'checked' : '' ?> title="On by default" class="h-4 w-4 rounded border-border accent-brand">
                        <label class="inline-flex cursor-pointer items-center text-muted has-[:checked]:text-amber-600" title="Lock: people can’t change it"><input type="checkbox" name="lock[<?= $role ?>][<?= $ev ?>][<?= $ch ?>]" <?= $cell['lock'] ? 'checked' : '' ?> class="sr-only"><?= icon('lock', 13) ?></label>
                      </div>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <label class="text-sm font-medium" for="dg-<?= $role ?>">Emails for <?= e(strtolower($label)) ?></label>
          <select id="dg-<?= $role ?>" name="digest[<?= $role ?>]" class="<?= SELECT_CLS ?> w-auto"><?= select_options(DIGEST_MODES, $matrix['_digest'][$role]) ?></select>
          <span class="text-xs text-muted">Default; people can change it unless you lock email above.</span>
        </div>
      </div>
    <?php endforeach; ?>
    <p class="flex items-center gap-1.5 text-xs text-muted"><?= icon('lock', 12, 'text-amber-600') ?> Click the lock next to a box to fix that choice for everyone with the role (it turns amber). Unlocked boxes are only the default.</p>
    <button type="submit" class="<?= btn_cls() ?>"><?= icon('check', 16) ?> Save rules</button>
  </form>
</div>

<!-- Timing & automations -->
<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('clock', 'Timing & automations')) ?>
  <form method="post" action="/action/notify-rules-save" class="space-y-4 px-5 pb-5">
    <?= form_meta('notify-rules', '/settings#notifications') ?>
    <?= banner($rs, true) ?>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="<?= LABEL_CLS ?>">Warn before the SLA deadline (minutes)</label><input type="number" name="slaWarnMinutes" min="5" max="1440" value="<?= $rules['slaWarnMinutes'] ?>" class="<?= INPUT_CLS ?>"></div>
      <div><label class="<?= LABEL_CLS ?>">“Still unassigned” after (minutes)</label><input type="number" name="unassignedMinutes" min="5" max="10080" value="<?= $rules['unassignedMinutes'] ?>" class="<?= INPUT_CLS ?>"></div>
      <div><label class="<?= LABEL_CLS ?>">Status that means “waiting on the customer”</label><select name="waitingStatus" class="<?= SELECT_CLS ?>"><?= select_options(array_column(array_filter(tenant_statuses($tenantId), fn ($st) => !$st['isClosed']), 'label', 'key'), $rules['waitingStatus']) ?></select></div>
      <div></div>
      <label class="flex items-start gap-3 rounded-xl border border-border p-3"><input type="checkbox" name="remindersOn" <?= $rules['remindersOn'] ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-border accent-brand">
        <span class="text-sm"><span class="font-medium">Remind the customer</span> after <input type="number" name="reminderDays" min="1" max="60" value="<?= $rules['reminderDays'] ?>" class="mx-1 h-8 w-16 rounded-md border border-border bg-surface px-2 text-sm"> days of waiting</span></label>
      <label class="flex items-start gap-3 rounded-xl border border-border p-3"><input type="checkbox" name="autoCloseOn" <?= $rules['autoCloseOn'] ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-border accent-brand">
        <span class="text-sm"><span class="font-medium">Close automatically</span> after <input type="number" name="autoCloseDays" min="1" max="120" value="<?= $rules['autoCloseDays'] ?>" class="mx-1 h-8 w-16 rounded-md border border-border bg-surface px-2 text-sm"> days of waiting <span class="block text-xs text-muted">The customer is told and can reply to open it again.</span></span></label>
    </div>
    <p class="text-xs text-muted">These run in the background jobs, so they need the cron.</p>
    <button type="submit" class="<?= btn_cls() ?>"><?= icon('check', 16) ?> Save timing</button>
  </form>
</div>

<!-- Email texts -->
<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('file-text', 'Email texts')) ?>
  <form method="post" action="/action/notify-templates-save" class="space-y-3 px-5 pb-5">
    <?= form_meta('notify-tpl', '/settings#notifications') ?>
    <?= banner($ts, true) ?>
    <p class="text-sm text-muted">Leave empty to use the built-in text. You can use <?= implode(' ', array_map(fn ($p) => '<code class="rounded bg-surface-2 px-1 text-xs">' . e($p) . '</code>', NOTIFY_PLACEHOLDERS)) ?>.</p>
    <div class="divide-y divide-border rounded-xl border border-border">
      <?php foreach (NOTIFY_CATEGORIES as $ev): $tv = $tpls[$ev] ?? []; ?>
        <details class="group px-4 py-3" <?= ($tv['subject'] ?? '') !== '' || ($tv['line'] ?? '') !== '' ? 'open' : '' ?>>
          <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-medium [&::-webkit-details-marker]:hidden"><?= icon('chevron-right', 14, 'text-muted transition group-open:rotate-90') ?><?= e(CATEGORY_LABELS[$ev]) ?><?php if (($tv['subject'] ?? '') !== '' || ($tv['line'] ?? '') !== ''): ?><span class="rounded-full bg-brand/10 px-1.5 text-[10px] font-semibold text-brand">Custom</span><?php endif; ?></summary>
          <div class="mt-3 grid gap-2">
            <input name="tpl[<?= $ev ?>][subject]" value="<?= e($tv['subject'] ?? '') ?>" placeholder="Subject — default: [#{ticket.number}] {ticket.subject}" class="<?= INPUT_CLS ?>">
            <textarea name="tpl[<?= $ev ?>][line]" rows="2" placeholder="Message line — default: the built-in sentence for this event" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40"><?= e($tv['line'] ?? '') ?></textarea>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="<?= btn_cls() ?>"><?= icon('check', 16) ?> Save texts</button>
  </form>
</div>

<!-- Delivery log -->
<div class="rounded-xl border border-border bg-surface" id="delivery-log">
  <?= card_header(titled('list-checks', 'Delivery log'), '<span class="text-xs text-muted">last 7 days</span>') ?>
  <div class="space-y-3 px-5 pb-5">
    <?= banner($ls, true) ?>
    <div class="flex flex-wrap items-center gap-1.5">
      <?php foreach (['' => 'All', 'sent' => 'Sent', 'pending' => 'Waiting', 'digest' => 'In a summary', 'failed' => 'Failed', 'skipped' => 'Skipped'] as $k => $l): ?>
        <a href="/settings?nlog=<?= $k ?>#notifications" class="<?= cx('rounded-full px-2.5 py-1 text-xs font-medium', $logFilter === $k ? 'bg-brand/10 text-brand' : 'bg-surface-2 text-muted hover:text-fg') ?>"><?= $l ?><?= $k !== '' ? ' · ' . (int) ($counts[$k] ?? 0) : '' ?></a>
      <?php endforeach; ?>
      <?php if (($counts['failed'] ?? 0) > 0): ?>
        <form method="post" action="/action/notify-retry" data-ajax class="ml-auto"><?= form_meta('notify-log') ?><input type="hidden" name="all" value="1"><button type="submit" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('rotate-ccw', 13) ?> Retry all failed</button></form>
      <?php endif; ?>
    </div>
    <div class="overflow-x-auto rounded-xl border border-border">
      <table class="w-full min-w-[40rem] text-sm">
        <thead><tr class="border-b border-border bg-surface-2/50 text-left text-xs text-muted"><th class="px-3 py-2 font-medium">When</th><th class="px-3 py-2 font-medium">To</th><th class="px-3 py-2 font-medium">What</th><th class="px-3 py-2 font-medium">Status</th><th></th></tr></thead>
        <tbody class="divide-y divide-border">
          <?php if (!$log): ?><tr><td colspan="5" class="px-3 py-8 text-center text-sm text-muted">Nothing here yet.</td></tr><?php endif; ?>
          <?php foreach ($log as $j): ?>
            <tr>
              <td class="whitespace-nowrap px-3 py-2 text-xs text-muted" title="<?= e(format_date($j['createdAt'])) ?>"><?= e(time_ago($j['createdAt'])) ?></td>
              <td class="px-3 py-2"><span class="block truncate font-medium"><?= e($j['uName']) ?></span><span class="block truncate text-xs text-muted"><?= e($j['uEmail']) ?></span></td>
              <td class="px-3 py-2"><span class="inline-flex items-center gap-1.5"><?= icon($chIcon[$j['channel']] ?? 'bell', 13, 'text-muted') ?><?= e(CATEGORY_LABELS[$j['event']] ?? $j['event']) ?></span><?php if ($j['tNumber']): ?> <a href="/tickets/<?= e($j['ticketId']) ?>" class="text-xs text-brand hover:underline">#<?= (int) $j['tNumber'] ?></a><?php endif; ?></td>
              <td class="px-3 py-2"><span class="<?= cx('rounded-full px-2 py-0.5 text-[11px] font-semibold', $statusTone[$j['status']] ?? 'bg-surface-2 text-muted') ?>"><?= e(['digest' => 'in summary', 'pending' => 'waiting'][$j['status']] ?? $j['status']) ?></span>
                <?php if ($j['status'] === 'pending' && (int) $j['attempts'] > 0): ?><span class="text-[11px] text-muted"> retry <?= (int) $j['attempts'] ?>/<?= NOTIFY_MAX_ATTEMPTS ?> <?= e(time_ago($j['sendAfter'])) ?></span><?php endif; ?>
                <?php if ($j['lastError']): ?><span class="mt-0.5 block max-w-xs truncate text-[11px] text-muted" title="<?= e($j['lastError']) ?>"><?= e($j['lastError']) ?></span><?php endif; ?></td>
              <td class="px-3 py-2 text-right"><?php if (in_array($j['status'], ['failed', 'skipped'], true)): ?>
                <form method="post" action="/action/notify-retry" data-ajax><?= form_meta('notify-log') ?><input type="hidden" name="id" value="<?= e($j['id']) ?>"><button type="submit" class="<?= btn_cls('ghost', 'sm') ?>"><?= icon('rotate-ccw', 13) ?> Resend</button></form>
              <?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
