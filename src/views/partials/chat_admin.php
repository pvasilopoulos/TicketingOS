<?php
/** Settings → Integrations → Live chat. @var array $me @var string $tenantId */
if (!can($me, 'settings.chat')) return;
$cc = chat_settings($tenantId);
$slug = (string) q_val('SELECT "slug" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
$wfOn = webform_settings($tenantId)['enabled'];
?>
<div class="rounded-xl border border-border bg-surface scroll-mt-24" id="chat">
  <?= card_header(titled('messages-square', 'Live chat'), $cc['enabled'] ? '<span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">On</span>' : '<span class="rounded-full bg-surface-2 px-2 py-0.5 text-xs text-muted">Off</span>') ?>
  <form method="post" action="/action/chat-settings-save" data-ajax class="space-y-4 p-5">
    <?= form_meta() ?>
    <p class="text-sm text-muted">Visitors chat with your team from the website widget (the same embed code as the support form). Agents answer on the <a href="/chat" class="font-medium text-brand">Live chat</a> page. A chat nobody answers within the time below becomes a ticket and the visitor is told they’ll get an email; every chat is saved as a ticket when it ends.</p>
    <?php if (!$wfOn): ?><p class="rounded-lg bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-300">The support form & widget is off. Turn it on above so the widget appears on your site.</p><?php endif; ?>
    <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium"><input type="checkbox" name="enabled" <?= $cc['enabled'] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> Turn on live chat</label>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="<?= LABEL_CLS ?>">Title</label><input name="title" maxlength="60" value="<?= e($cc['title']) ?>" class="<?= INPUT_CLS ?>"></div>
      <div><label class="<?= LABEL_CLS ?>">Make it a ticket if unanswered after (minutes)</label><input type="number" name="ticketAfter" min="1" max="120" value="<?= (int) $cc['ticketAfter'] ?>" class="<?= INPUT_CLS ?>"></div>
      <div class="sm:col-span-2"><label class="<?= LABEL_CLS ?>">Greeting</label><input name="greeting" maxlength="300" value="<?= e($cc['greeting']) ?>" class="<?= INPUT_CLS ?>"></div>
    </div>
    <p class="text-xs text-muted">Test it: <a href="/support/<?= e($slug) ?>/chat" target="_blank" rel="noopener" class="font-medium text-brand">open the chat page</a>. Agents need the “Answer live chat” permission.</p>
    <button type="submit" class="<?= btn_cls() ?>">Save</button>
  </form>
</div>
