<?php
[$user, $tenantId] = require_tenant_user();
page_title('Notifications');
$state = flash('prefs');
$prefs = user_prefs($user['id'], $tenantId);
$priorities = tenant_priorities($tenantId);
$devices = q_all('SELECT p."id", p."userAgent", p."endpoint", p."createdAt", u."name" AS "uName", u."email" AS "uEmail", u."role" AS "uRole", u."avatarColor" AS "uColor", u."lastLoginAt" AS "uLogin"
    FROM "PushSubscription" p JOIN "User" u ON u."id" = p."userId" WHERE p."userId" = ? ORDER BY p."createdAt" DESC', [$user['id']]);
$myRole = user_role_label($user, $user['customRoleId'] ?? null ? key_by(q_all('SELECT "id","name" FROM "Role" WHERE "id" = ?', [$user['customRoleId']]), 'id') : []);
$quietOn = $prefs['quietStart'] !== null && $prefs['quietEnd'] !== null;
$nPolicy = tenant_policy($tenantId);
$nMatrix = notify_matrix($tenantId);
$nRole = notify_role($user);
$staffMe = is_staff($user['role']);
$myDigest = $prefs['digest'] ?? $nMatrix['_digest'][$nRole];
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
              <?php foreach (EVENT_ROLES[$nRole] as $c): ?>
                <tr class="border-t border-border">
                  <td class="px-3 py-2.5"><span class="block"><?= e(CATEGORY_LABELS[$c]) ?></span><span class="block text-xs text-muted"><?= e(CATEGORY_HINTS[$c]) ?></span></td>
                  <?php foreach (CHANNELS as $ch):
                      $allowed = ['inApp' => $nPolicy['allowInApp'], 'push' => $nPolicy['allowPush'], 'email' => $nPolicy['allowEmail']][$ch];
                      $cell = $nMatrix[$nRole][$c][$ch];
                      $on = channel_on($user, $c, $ch, $prefs, $nMatrix, $nPolicy);
                      $fixed = !$allowed || $cell['lock']; ?>
                    <td class="px-3 py-2.5"><div class="flex items-center justify-center gap-1" <?= $fixed ? 'title="' . (!$allowed ? 'Turned off for the whole workspace' : 'Set by your workspace') . '"' : '' ?>>
                      <input type="checkbox" name="ch_<?= $c ?>_<?= $ch ?>" <?= $on ? 'checked' : '' ?> <?= $fixed ? 'disabled' : '' ?> class="h-4 w-4 rounded border-border accent-brand disabled:opacity-50">
                      <?php if ($fixed): ?><?= icon('lock', 11, 'text-muted') ?><?php endif; ?>
                    </div></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="grid gap-5 border-t border-border p-5 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <label class="<?= LABEL_CLS ?>">Emails</label>
            <select name="digest" class="<?= SELECT_CLS ?> sm:w-80"><?= select_options(DIGEST_MODES, $myDigest) ?></select>
            <p class="mt-1 text-xs text-muted">A summary groups updates into one email instead of one per event.</p>
          </div>
          <?php if ($staffMe): ?>
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
          <?php endif; ?>
          <div class="sm:col-span-2" data-quiet>
            <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
              <input type="checkbox" name="quietEnabled" data-quiet-toggle <?= $quietOn ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand">
              Quiet hours <span class="text-muted">— push waits until they end</span>
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
      <?= card_header('Your devices', $devices ? '<span class="text-xs text-muted">' . count($devices) . ' receiving push notifications</span>' : '') ?>
      <div class="divide-y divide-border">
        <?php if (!$devices): ?>
          <p class="px-5 py-6 text-center text-sm text-muted">No devices registered. Use the 🔔 in the top bar to enable push on this device.</p>
        <?php endif; ?>
        <?php foreach ($devices as $d):
            $info = describe_user_agent($d['userAgent']);
            $thisOne = $d['userAgent'] !== null && $d['userAgent'] === ($_SERVER['HTTP_USER_AGENT'] ?? '');
            $ic = ['phone' => 'smartphone', 'tablet' => 'tablet', 'desktop' => 'monitor'][$info['type']];
            $title = $info['device'] . ($info['browser'] !== '' ? ' · ' . $info['browser'] : ''); ?>
          <div class="px-4 py-4 sm:px-5">
            <div class="flex items-start gap-3">
              <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl <?= $thisOne ? 'bg-brand/10 text-brand' : 'bg-surface-2 text-muted' ?>"><?= icon($ic, 20) ?></span>
              <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-medium">
                  <span class="truncate"><?= e($title) ?></span>
                  <?php if ($thisOne): ?><span class="rounded-full bg-brand/10 px-2 py-0.5 text-[11px] font-semibold text-brand">This device</span><?php endif; ?>
                </p>
                <div class="mt-1.5 flex min-w-0 items-center gap-2 text-xs">
                  <?= avatar($d['uName'], $d['uColor'], 20) ?>
                  <span class="min-w-0 truncate"><span class="text-muted">Signed in as</span> <span class="font-medium"><?= e($d['uName']) ?></span> <span class="text-muted">· <?= e($d['uEmail']) ?> · <?= e($myRole) ?></span></span>
                </div>
                <dl class="mt-1.5 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs sm:grid-cols-[auto_1fr_auto_1fr]">
                  <dt class="text-muted">System</dt><dd class="truncate"><?= e($info['os'] ?: '—') ?></dd>
                  <dt class="text-muted">Browser</dt><dd class="truncate"><?= e($info['browser'] ?: '—') ?></dd>
                  <dt class="text-muted">Push via</dt><dd class="truncate"><?= e(push_service_name($d['endpoint'])) ?></dd>
                  <dt class="text-muted">Last sign-in</dt><dd class="truncate"><?= $d['uLogin'] ? e(format_date($d['uLogin'])) . ' · ' . e(time_ago($d['uLogin'])) : '—' ?></dd>
                  <dt class="text-muted">Added</dt><dd class="truncate" title="<?= e(format_date($d['createdAt'])) ?>"><?= e(format_date($d['createdAt'])) ?> · <?= e(time_ago($d['createdAt'])) ?></dd>
                </dl>
                <?php if ($d['userAgent']): ?>
                  <details class="mt-1.5 text-xs text-muted">
                    <summary class="cursor-pointer select-none hover:text-fg">Technical details</summary>
                    <p class="mt-1 break-all font-mono text-[11px]"><?= e($d['userAgent']) ?></p>
                  </details>
                <?php endif; ?>
              </div>
              <form method="post" action="/action/device-remove" data-ajax data-confirm="Stop push notifications on <?= e($title) ?>?">
                <?= form_meta() ?><input type="hidden" name="id" value="<?= e($d['id']) ?>">
                <button type="submit" aria-label="Remove <?= e($title) ?>" title="Stop notifications on this device" class="<?= ICON_BTN_DANGER ?>"><?= icon('trash-2', 15) ?></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
