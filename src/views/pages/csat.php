<?php
/**
 * Public CSAT page (/csat/{token}): one-click rating from the email, then an optional comment.
 * @var array $survey @var string $token
 */
$ticket = q_one('SELECT "number","subject","tenantId" FROM "Ticket" WHERE "id" = ?', [$survey['ticketId']]);
$tenant = q_one('SELECT "name" FROM "Tenant" WHERE "id" = ?', [$survey['tenantId']]);
$cfg = csat_settings($survey['tenantId']);
$rated = (int) ($survey['rating'] ?? 0);
?>
<div class="min-h-screen bg-bg px-4 py-12 sm:py-20">
  <div class="mx-auto max-w-md rounded-2xl border border-border bg-surface p-6 text-center shadow-sm sm:p-8">
    <p class="text-sm font-medium text-muted"><?= e($tenant['name'] ?? '') ?></p>
    <?php if (!empty($survey['thanked'])): ?>
      <span class="mx-auto mt-4 grid h-12 w-12 place-items-center rounded-full bg-emerald-500/10 text-emerald-600"><?= icon('circle-check', 24) ?></span>
      <h1 class="mt-3 text-xl font-semibold">Thank you!</h1>
      <p class="mt-1 text-sm text-muted">Your feedback helps us improve.</p>
    <?php else: ?>
      <h1 class="mt-2 text-xl font-semibold"><?= e($cfg['question']) ?></h1>
      <p class="mt-1 text-sm text-muted">Ticket #<?= (int) ($ticket['number'] ?? 0) ?> · <?= e($ticket['subject'] ?? '') ?></p>
      <form method="post" class="mt-6 space-y-4">
        <?= csrf_field() ?>
        <div class="flex justify-center gap-2">
          <?php foreach (CSAT_FACES as $n => $face): ?>
            <label class="cursor-pointer" title="<?= e(CSAT_LABELS[$n]) ?>">
              <input type="radio" name="rating" value="<?= $n ?>" <?= $rated === $n ? 'checked' : '' ?> required class="peer sr-only">
              <span class="grid h-14 w-14 place-items-center rounded-2xl border border-border text-3xl transition peer-checked:scale-110 peer-checked:border-brand peer-checked:bg-brand/10 hover:bg-surface-2"><?= $face ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if ($rated): ?><p class="text-sm text-emerald-700 dark:text-emerald-400">Thanks — we recorded “<?= e(CSAT_LABELS[$rated]) ?>”. You can change it or add a comment.</p><?php endif; ?>
        <textarea name="comment" rows="3" maxlength="2000" placeholder="Anything you’d like to add? (optional)" class="<?= str_replace(' h-10', '', INPUT_CLS) ?> py-2"><?= e($survey['comment'] ?? '') ?></textarea>
        <button type="submit" class="<?= btn_cls('primary', 'lg') ?> w-full justify-center">Send feedback</button>
      </form>
    <?php endif; ?>
  </div>
</div>
