<?php
/** @var string $id */
[$user, $tenantId] = require_tenant_user();
$staff = can($user, 'kb.edit'); // editors: drafts, stats, edit buttons
$a = q_one('SELECT k.*, u."name" AS "authorName" FROM "KbArticle" k LEFT JOIN "User" u ON u."id" = k."authorId" WHERE k."id" = ? AND k."tenantId" = ?', [$id, $tenantId]);
if (!$a || (!$a['published'] && !$staff)) abort(404);
page_title($a['title']);
// Count customer/agent reads (not the author re-opening their draft).
if ($a['published'] && $a['authorId'] !== $user['id']) q('UPDATE "KbArticle" SET "views" = "views" + 1 WHERE "id" = ?', [$id]);
$fb = flash('kb-helpful-' . $id);
$related = array_values(array_filter(kb_find($tenantId, $a['title'], false, 5), fn ($r) => $r['id'] !== $id && ($r['score'] ?? 0) > 1));
?>
<div class="mx-auto max-w-3xl">
  <a href="/kb" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"><?= icon('arrow-left', 16) ?> Knowledge base</a>
  <article class="rounded-xl border border-border bg-surface p-5 sm:p-8">
    <p class="text-xs font-semibold uppercase tracking-wide text-brand"><?= e($a['section'] ?: 'General') ?></p>
    <h1 class="mt-1 text-2xl font-bold tracking-tight"><?= e($a['title']) ?></h1>
    <p class="mt-2 text-xs text-muted">
      Updated <?= e(time_ago($a['updatedAt'])) ?><?= $a['authorName'] ? ' by ' . e($a['authorName']) : '' ?>
      <?php if ($staff): ?> · <?= number_format((int) $a['views']) ?> views · <?= number_format((int) $a['helpful']) ?> found it helpful<?php endif; ?>
      <?php if (!$a['published']): ?> · <span class="font-medium text-amber-600">Draft — only staff can see it</span><?php endif; ?>
    </p>
    <div class="rich mt-6 text-[15px]"><?= $a['body'] ?></div>
  </article>

  <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-surface px-5 py-4">
    <?php if (!empty($fb['success'])): ?>
      <p class="inline-flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400"><?= icon('circle-check', 16) ?> <?= e($fb['success']) ?></p>
    <?php else: ?>
      <form method="post" action="/action/kb-helpful" class="flex items-center gap-3">
        <?= form_meta('kb-helpful-' . $id) ?><input type="hidden" name="id" value="<?= e($id) ?>">
        <span class="text-sm font-medium">Was this helpful?</span>
        <button type="submit" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('thumbs-up', 14) ?> Yes</button>
      </form>
    <?php endif; ?>
    <a href="/tickets/new" class="text-sm font-medium text-brand hover:underline">Still need help? Open a ticket →</a>
  </div>

  <?php if ($staff): ?>
    <div class="mt-4 flex gap-2">
      <a href="/kb/<?= e($id) ?>/edit" class="<?= btn_cls('secondary') ?>"><?= icon('pen-line', 15) ?> Edit</a>
      <?php if (can($user, 'kb.delete')): ?>
      <form method="post" action="/action/kb-delete" data-confirm-two-step>
        <?= form_meta() ?><input type="hidden" name="id" value="<?= e($id) ?>">
        <button type="button" data-confirm-start class="<?= btn_cls('ghost') ?> text-red-600"><?= icon('trash-2', 15) ?> Delete</button>
        <button type="submit" data-confirm-final hidden class="<?= btn_cls('danger') ?>">Confirm delete</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($related): ?>
    <h2 class="mb-2 mt-8 text-sm font-semibold">Related articles</h2>
    <div class="divide-y divide-border rounded-xl border border-border bg-surface">
      <?php foreach ($related as $r): ?>
        <a href="/kb/<?= e($r['id']) ?>" class="flex items-center gap-3 px-4 py-3 text-sm hover:bg-surface-2"><?= icon('book-open', 15, 'text-brand') ?><span class="truncate"><?= e($r['title']) ?></span></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
