<?php
[$user, $tenantId] = require_tenant_user();
page_title('Notifications');
$state = flash('prefs');
$prefs = user_prefs($user['id'], $tenantId);
$priorities = tenant_priorities($tenantId);
$devices = q_all('SELECT "id","userAgent","createdAt" FROM "PushSubscription" WHERE "userId" = ? ORDER BY "createdAt" DESC', [$user['id']]);
$quietOn = $prefs['quietStart'] !== null && $prefs['quietEnd'] !== null;
?>
<div>
  <?= page_header('Notifications', "Choose how and when you're notified.") ?>
  <div class="space-y-6">
    <form method="post" action="/action/prefs-save">
      <?= form_meta('prefs') ?>
      <div class="rounded-xl border border-border bg-surface">
        <?= card_header('Notify me about…') ?>
        <div class="overflow-x-auto p-2">
          <table class="w-full min-w-xl text-sm">
            <thead><tr class="text-xs text-muted">
              <th class="px-3 py-2 text-left font-medium">Event</th><th class="px-3 py-2 font-medium">In-app</th><th class="px-3 py-2 font-medium">Push</th><th class="px-3 py-2 font-medium">Email</th>
            </tr></thead>
            <tbody>
              <?php foreach (NOTIFY_CATEGORIES as $c): ?>
                <tr class="border-t border-border">
                  <td class="px-3 py-2.5"><?= e(CATEGORY_LABELS[$c]) ?></td>
                  <?php foreach (CHANNELS as $ch): ?>
                    <td class="px-3 py-2.5"><div class="flex items-center justify-center">
                      <input type="checkbox" name="ch_<?= $c ?>_<?= $ch ?>" <?= $prefs['channels'][$c][$ch] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand">
                    </div></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="grid gap-5 border-t border-border p-5 sm:grid-cols-2">
          <div>
            <label class="<?= LABEL_CLS ?>">Only notify for priority ≥</label>
            <select name="priorityThreshold" class="<?= SELECT_CLS ?>">
              <?= select_options(['' => 'Any priority'] + array_map(fn ($l) => "$l and above", array_column($priorities, 'label', 'key')), $prefs['priorityThreshold']) ?>
            </select>
            <p class="mt-1 text-xs text-muted">Applies to push &amp; email (in-app always kept).</p>
          </div>
          <div>
            <label class="<?= LABEL_CLS ?>">New-ticket scope</label>
            <select name="scope" class="<?= SELECT_CLS ?>">
              <?= select_options(['all' => 'All new tickets', 'assigned' => 'Only when assigned to me', 'watching' => 'Only tickets I watch'], $prefs['scope']) ?>
            </select>
            <p class="mt-1 text-xs text-muted">Limits the broadcast of newly created tickets.</p>
          </div>
          <div class="sm:col-span-2" data-quiet>
            <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
              <input type="checkbox" name="quietEnabled" data-quiet-toggle <?= $quietOn ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand">
              Quiet hours (no push)
            </label>
            <div class="mt-2 flex items-center gap-2 text-sm" <?= $quietOn ? '' : 'hidden' ?> data-quiet-fields>
              <input type="time" name="quietStart" value="<?= e(minutes_to_hhmm($prefs['quietStart']) ?: '22:00') ?>" class="h-9 rounded-lg border border-border bg-surface px-2">
              <span class="text-muted">to</span>
              <input type="time" name="quietEnd" value="<?= e(minutes_to_hhmm($prefs['quietEnd']) ?: '08:00') ?>" class="h-9 rounded-lg border border-border bg-surface px-2">
            </div>
          </div>
        </div>

        <div class="flex items-center gap-3 border-t border-border p-5">
          <button type="submit" class="<?= btn_cls() ?>">Save preferences</button>
          <?php if (!empty($state['success'])): ?>
            <span class="inline-flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400"><?= icon('circle-check', 15) ?> <?= e($state['success']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </form>

    <div class="rounded-xl border border-border bg-surface">
      <?= card_header('Your devices') ?>
      <div class="divide-y divide-border">
        <?php if (!$devices): ?>
          <p class="px-5 py-6 text-center text-sm text-muted">No devices registered. Use the 🔔 in the top bar to enable push on this device.</p>
        <?php endif; ?>
        <?php foreach ($devices as $d): ?>
          <div class="flex items-center gap-3 px-5 py-3">
            <?= icon('smartphone', 18, 'text-muted') ?>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm"><?= e($d['userAgent'] ?: 'Unknown device') ?></p>
              <p class="text-xs text-muted">added <?= e(time_ago($d['createdAt'])) ?></p>
            </div>
            <form method="post" action="/action/device-remove" data-ajax>
              <?= form_meta() ?><input type="hidden" name="id" value="<?= e($d['id']) ?>">
              <button type="submit" aria-label="Remove device" class="<?= ICON_BTN_DANGER ?>"><?= icon('trash-2', 15) ?></button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
