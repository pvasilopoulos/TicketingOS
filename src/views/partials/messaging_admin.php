<?php
/** Settings → Integrations → SMS, Viber & WhatsApp. @var array $me @var string $tenantId */
if (!can($me, 'settings.messaging')) return;
$sms = sms_settings($tenantId);
$vb = channel_settings($tenantId, 'viber');
$wa = channel_settings($tenantId, 'whatsapp');
$slug = (string) q_val('SELECT "slug" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
$hook = fn (string $ch) => app_url() . '/hooks/' . $ch . '/' . $slug;
$onBadge = fn (bool $on) => $on ? '<span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">On</span>' : '<span class="rounded-full bg-surface-2 px-2 py-0.5 text-xs text-muted">Off</span>';
$copy = fn (string $v) => '<div class="flex gap-2"><input readonly value="' . e($v) . '" onclick="this.select()" class="h-9 min-w-0 flex-1 rounded-lg border border-border bg-surface-2 px-3 font-mono text-xs"><button type="button" data-copy="' . e($v) . '" class="' . btn_cls('outline', 'sm') . '">' . icon('copy', 13) . ' Copy</button></div>';
$secret = fn (string $name, bool $has, string $ph) => '<input name="' . $name . '" type="password" autocomplete="off" placeholder="' . ($has ? '•••••••• saved — type to replace' : e($ph)) . '" class="' . INPUT_CLS . '">';
?>
<!-- SMS -->
<div class="rounded-xl border border-border bg-surface scroll-mt-24" id="sms">
  <?= card_header(titled('smartphone', 'SMS notifications'), $onBadge($sms['enabled'])) ?>
  <form method="post" action="/action/sms-settings-save" data-ajax class="space-y-4 p-5" data-sms-form>
    <?= form_meta() ?>
    <p class="text-sm text-muted">Short text messages for the moments that matter. They go to people who have a mobile number (Settings → Users, or each person on their Notifications page), follow quiet hours, and are retried and logged like email.</p>
    <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium"><input type="checkbox" name="enabled" <?= $sms['enabled'] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> Send SMS notifications</label>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="<?= LABEL_CLS ?>">Provider</label>
        <select name="provider" data-sms-provider class="<?= SELECT_CLS ?>"><?= select_options(['twilio' => 'Twilio', 'http' => 'Other SMS gateway (HTTP API)'], $sms['provider']) ?></select></div>
      <div><label class="<?= LABEL_CLS ?>">Sender (name or number)</label><input name="from" maxlength="20" value="<?= e($sms['from']) ?>" placeholder="e.g. Softify or +3021…" class="<?= INPUT_CLS ?>">
        <p class="mt-1 text-xs text-muted">Names up to 11 Latin letters; your provider must allow it.</p></div>
    </div>
    <div class="grid gap-4 sm:grid-cols-2" data-sms-twilio <?= $sms['provider'] === 'twilio' ? '' : 'hidden' ?>>
      <div><label class="<?= LABEL_CLS ?>">Account SID</label><input name="twilioSid" value="<?= e($sms['twilioSid']) ?>" placeholder="AC…" class="<?= INPUT_CLS ?>"></div>
      <div><label class="<?= LABEL_CLS ?>">Auth token</label><?= $secret('twilioToken', $sms['hasTwilioToken'], 'From the Twilio console') ?></div>
    </div>
    <div class="space-y-3" data-sms-http <?= $sms['provider'] === 'http' ? '' : 'hidden' ?>>
      <div class="grid gap-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div><label class="<?= LABEL_CLS ?>">Gateway URL (POST)</label><input name="httpUrl" value="<?= e($sms['httpUrl']) ?>" placeholder="https://api.your-sms-provider.gr/v1/sms" class="<?= INPUT_CLS ?>"></div>
        <div><label class="<?= LABEL_CLS ?>">Body format</label><select name="httpFormat" class="<?= SELECT_CLS ?>"><?= select_options(['json' => 'JSON', 'form' => 'Form (key=value)'], $sms['httpFormat']) ?></select></div>
      </div>
      <div><label class="<?= LABEL_CLS ?>">Body</label><textarea name="httpBody" rows="3" class="<?= INPUT_CLS ?> h-auto py-2 font-mono text-xs"><?= e($sms['httpBody']) ?></textarea>
        <p class="mt-1 text-xs text-muted">Placeholders: <code>{to}</code> (+3069…), <code>{to_digits}</code> (3069…), <code>{from}</code>, <code>{message}</code>. Copy the fields from your provider’s API page.</p></div>
      <div><label class="<?= LABEL_CLS ?>">Authentication header</label><?= $secret('httpAuth', $sms['hasHttpAuth'], 'Bearer … or X-Api-Key: …') ?>
        <?php if ($sms['hasHttpAuth']): ?><label class="mt-2 flex items-center gap-2 text-xs text-muted"><input type="checkbox" name="clearHttpAuth" class="h-3.5 w-3.5 rounded border-border"> Remove the saved header</label><?php endif; ?></div>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
      <?php foreach (['customer' => 'To customers', 'staff' => 'To agents & admins'] as $grp => $label): ?>
        <fieldset class="rounded-xl border border-border p-4"><legend class="px-1 text-xs font-semibold uppercase tracking-wide text-muted"><?= $label ?></legend>
          <?php foreach (SMS_EVENTS[$grp] as $k => $l): ?>
            <label class="flex cursor-pointer items-center gap-2 py-1 text-sm"><input type="checkbox" name="<?= $grp ?>Events[]" value="<?= $k ?>" <?= in_array($k, $sms['events'][$grp], true) ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> <?= e($l) ?></label>
          <?php endforeach; ?>
        </fieldset>
      <?php endforeach; ?>
    </div>
    <div class="flex flex-wrap items-end gap-2">
      <button type="submit" class="<?= btn_cls() ?>">Save</button>
      <input name="testTo" placeholder="Test to: 69…" value="<?= e((string) ($me['phone'] ?? '')) ?>" class="<?= INPUT_CLS ?> w-44">
      <button type="submit" formaction="/action/sms-test" formnovalidate class="<?= btn_cls('outline') ?>"><?= icon('send', 14) ?> Send test SMS</button>
    </div>
  </form>
</div>

<!-- Viber -->
<div class="rounded-xl border border-border bg-surface scroll-mt-24" id="viber">
  <?= card_header(titled('message-circle', 'Viber'), $onBadge($vb['enabled'] && $vb['hasToken'])) ?>
  <form method="post" action="/action/channel-settings-save" data-ajax class="space-y-4 p-5">
    <?= form_meta() ?><input type="hidden" name="channel" value="viber">
    <p class="text-sm text-muted">Customers message your Viber bot; each conversation becomes a ticket (or joins their open one), and agents’ public replies on that ticket are sent back on Viber. Create a bot at <strong class="text-fg">partners.viber.com</strong> and paste its token.</p>
    <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium"><input type="checkbox" name="enabled" <?= $vb['enabled'] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> Receive tickets from Viber</label>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="<?= LABEL_CLS ?>">Bot token</label><?= $secret('token', $vb['hasToken'], 'From partners.viber.com') ?></div>
      <div><label class="<?= LABEL_CLS ?>">Sender name</label><input name="name" maxlength="28" value="<?= e($vb['name']) ?>" class="<?= INPUT_CLS ?>"></div>
      <div class="sm:col-span-2"><label class="<?= LABEL_CLS ?>">Welcome message</label><input name="welcome" maxlength="500" value="<?= e($vb['welcome']) ?>" class="<?= INPUT_CLS ?>"></div>
    </div>
    <div><p class="mb-1.5 text-xs font-medium text-muted">Webhook address (set automatically with “Connect”)</p><?= $copy($hook('viber')) ?></div>
    <div class="flex flex-wrap gap-2">
      <button type="submit" class="<?= btn_cls() ?>">Save</button>
      <?php if ($vb['hasToken']): ?><button type="submit" formaction="/action/viber-connect" formnovalidate class="<?= btn_cls('outline') ?>"><?= icon('plug', 14) ?> Connect to Viber</button><?php endif; ?>
    </div>
  </form>
</div>

<!-- WhatsApp -->
<div class="rounded-xl border border-border bg-surface scroll-mt-24" id="whatsapp">
  <?= card_header(titled('message-square', 'WhatsApp'), $onBadge($wa['enabled'] && $wa['hasToken'])) ?>
  <form method="post" action="/action/channel-settings-save" data-ajax class="space-y-4 p-5">
    <?= form_meta() ?><input type="hidden" name="channel" value="whatsapp">
    <p class="text-sm text-muted">Uses the WhatsApp Business Cloud API (Meta). Messages to your business number open tickets; agents’ replies go back on WhatsApp. WhatsApp allows free replies for 24 hours after the customer’s last message — after that, ask them to write again.</p>
    <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium"><input type="checkbox" name="enabled" <?= $wa['enabled'] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> Receive tickets from WhatsApp</label>
    <div class="grid gap-4 sm:grid-cols-3">
      <div><label class="<?= LABEL_CLS ?>">Phone number ID</label><input name="phoneNumberId" value="<?= e($wa['phoneNumberId']) ?>" placeholder="1234567890…" class="<?= INPUT_CLS ?>"></div>
      <div><label class="<?= LABEL_CLS ?>">Access token</label><?= $secret('token', $wa['hasToken'], 'Permanent system-user token') ?></div>
      <div><label class="<?= LABEL_CLS ?>">App secret</label><?= $secret('appSecret', $wa['hasAppSecret'], 'Meta app → Settings → Basic') ?></div>
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><p class="mb-1.5 text-xs font-medium text-muted">Callback URL (Meta → WhatsApp → Configuration)</p><?= $copy($hook('whatsapp')) ?></div>
      <div><p class="mb-1.5 text-xs font-medium text-muted">Verify token</p><?= $wa['verifyToken'] !== '' ? $copy($wa['verifyToken']) : '<p class="text-xs text-muted">Created when you save.</p>' ?></div>
    </div>
    <p class="text-xs text-muted">In Meta, subscribe the webhook to the <strong>messages</strong> field.</p>
    <button type="submit" class="<?= btn_cls() ?>">Save</button>
  </form>
</div>
<script>
document.addEventListener("change", function (e) {
  if (!e.target.matches("[data-sms-provider]")) return;
  var f = e.target.closest("[data-sms-form]");
  f.querySelector("[data-sms-twilio]").hidden = e.target.value !== "twilio";
  f.querySelector("[data-sms-http]").hidden = e.target.value !== "http";
});
</script>
