<?php
/** Settings → Integrations → AI assistant. @var array $me @var string $tenantId */
if (!can($me, 'settings.ai')) return;
$ai = ai_settings($tenantId);
?>
<!-- AI assistant -->
<div class="rounded-xl border border-border bg-surface" id="ai">
  <?= card_header(titled('sparkles', 'AI assistant (Claude)'), $ai['enabled'] ? '<span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">On</span>' : '<span class="rounded-full bg-surface-2 px-2 py-0.5 text-xs text-muted">Off</span>') ?>
  <div class="space-y-4 p-5">
    <p class="text-sm text-muted">Gives agents an <strong class="text-fg">AI</strong> menu next to “Send reply”: summarize the ticket, suggest a reply, make their text friendlier, shorter or more professional, fix spelling and translate. Agents always review the text before sending — nothing is sent automatically. Ticket text is sent to Anthropic’s API only when an agent uses the menu.</p>
    <form method="post" action="/action/ai-settings-save" data-ajax class="space-y-4">
      <?= form_meta('ai') ?>
      <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium"><input type="checkbox" name="enabled" <?= $ai['enabled'] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> Turn on the AI assistant</label>
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="ai-key" class="<?= LABEL_CLS ?>">Anthropic API key</label>
          <input id="ai-key" name="apiKey" type="password" autocomplete="off" placeholder="<?= $ai['hasKey'] ? '•••••••• saved — type to replace' : 'sk-ant-…' ?>" class="<?= INPUT_CLS ?>">
          <p class="mt-1 text-xs text-muted">Create one at console.anthropic.com → API keys. Stored encrypted.</p>
          <?php if ($ai['hasKey']): ?><label class="mt-2 flex cursor-pointer items-center gap-2 text-xs text-muted"><input type="checkbox" name="clearKey" class="h-3.5 w-3.5 rounded border-border accent-red-600"> Remove the saved key</label><?php endif; ?>
        </div>
        <div>
          <label for="ai-model" class="<?= LABEL_CLS ?>">Model</label>
          <select id="ai-model" name="model" class="<?= SELECT_CLS ?>"><?= select_options(AI_MODELS, $ai['model']) ?></select>
        </div>
      </div>
      <div class="space-y-2 rounded-xl border border-border p-4">
        <label class="flex cursor-pointer items-start gap-2.5 text-sm"><input type="checkbox" name="triage" <?= $ai['triage'] ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-border accent-brand">
          <span><span class="font-medium">Triage new tickets automatically</span><span class="block text-xs text-muted">Detects the customer’s mood (a “Frustrated” or “Angry” badge on the ticket) and suggests category and priority in the ticket history. Only tickets created after you turn this on.</span></span></label>
        <label class="ml-6 flex cursor-pointer items-start gap-2.5 text-sm"><input type="checkbox" name="triageApply" <?= $ai['triageApply'] ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-border accent-brand">
          <span><span class="font-medium">Also set category and priority</span><span class="block text-xs text-muted">The category only when the customer left it empty; the priority can change the deadline.</span></span></label>
      </div>
      <p class="text-xs text-muted">Who can use it: roles with the “AI assistant” permission (Settings → Users &amp; roles). Each person can make up to 30 requests per 10 minutes.</p>
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="<?= btn_cls() ?>">Save</button>
        <?php if ($ai['hasKey']): ?><button type="submit" formaction="/action/ai-test" formnovalidate class="<?= btn_cls('outline') ?>"><?= icon('zap', 15) ?> Test connection</button><?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php $as = ai_assist_settings($tenantId); ?>
<!-- AI assistant for customers -->
<div class="rounded-xl border border-border bg-surface scroll-mt-24" id="ai-assist">
  <?= card_header(titled('bot', 'AI assistant for customers'), $as['enabled'] && $as['ready'] ? '<span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">On</span>' : '<span class="rounded-full bg-surface-2 px-2 py-0.5 text-xs text-muted">Off</span>') ?>
  <form method="post" action="/action/ai-assist-save" data-ajax class="space-y-4 p-5">
    <?= form_meta('ai') ?>
    <p class="text-sm text-muted">Claude answers your customers right away, about anything they ask — not only from help-center articles. It knows your company from the instructions below, matching articles and (in the app) the customer’s recent tickets. Customers can tap <strong class="text-fg">Talk to a person</strong> at any time: the conversation goes to <a href="/chat" class="font-medium text-brand">Live chat</a> with everything said so far. Conversations the assistant solves on its own don’t become tickets.</p>
    <?php if (!$as['ready']): ?><p class="rounded-lg bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-300">Turn on the AI assistant above (with an API key) first.</p><?php endif; ?>
    <label class="flex cursor-pointer items-center gap-2.5 text-sm font-medium"><input type="checkbox" name="enabled" <?= $as['enabled'] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> Turn on the assistant for customers</label>
    <div class="grid gap-2 sm:grid-cols-2">
      <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-border p-3 text-sm"><input type="checkbox" name="inMessages" <?= $as['inMessages'] ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-border accent-brand"><span><span class="font-medium">In the app (Messages)</span><span class="block text-xs text-muted">A first option “<?= e($as['name']) ?>” when customers start a conversation.</span></span></label>
      <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-border p-3 text-sm"><input type="checkbox" name="onWebsite" <?= $as['onWebsite'] ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-border accent-brand"><span><span class="font-medium">In the website chat</span><span class="block text-xs text-muted">The assistant answers new website chats first; agents see them once the visitor asks for a person.</span></span></label>
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
      <div><label class="<?= LABEL_CLS ?>">Name</label><input name="name" maxlength="40" value="<?= e($as['name']) ?>" class="<?= INPUT_CLS ?>"></div>
      <div><label class="<?= LABEL_CLS ?>">Model</label><select name="model" class="<?= SELECT_CLS ?>"><?= select_options(['' => 'Same as above'] + AI_MODELS, $as['model']) ?></select></div>
      <div><label class="<?= LABEL_CLS ?>">Answers per day (cost limit)</label><input type="number" name="dailyLimit" min="10" max="20000" value="<?= (int) $as['dailyLimit'] ?>" class="<?= INPUT_CLS ?>"></div>
    </div>
    <div>
      <label class="<?= LABEL_CLS ?>">What the assistant should know about your company</label>
      <textarea name="instructions" rows="7" maxlength="6000" placeholder="e.g. We are an IT support company in Athens. Office hours Mon–Fri 09:00–17:00. We support Windows PCs, Microsoft 365, printers and networks. For new equipment or price questions, ask the customer to talk to a person. Our phone: 210 1234567." class="<?= INPUT_CLS ?> h-auto py-2"><?= e($as['instructions']) ?></textarea>
      <p class="mt-1 text-xs text-muted">Services, hours, contact details, what it may and may not promise. Customer messages are sent to Anthropic’s API to create the answers.</p>
    </div>
    <button type="submit" class="<?= btn_cls() ?>">Save</button>
  </form>
</div>
