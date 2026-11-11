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
