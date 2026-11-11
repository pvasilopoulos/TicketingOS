<?php
/** @var ?string $id */
[$user, $tenantId] = require_tenant_user();
if (!can($user, 'kb.edit')) redirect('/kb');
$a = $id ? q_one('SELECT * FROM "KbArticle" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId]) : null;
if ($id && !$a) abort(404);
page_title($a ? 'Edit article' : 'New article');
$state = flash('kb-edit');
$failed = isset($state['error']);
$v = fn (string $k, string $d = '') => $failed ? old('kb-edit', $k, $d) : (string) ($a[$k] ?? $d);
$sections = pluck(q_all('SELECT DISTINCT "section" FROM "KbArticle" WHERE "tenantId" = ? AND "section" IS NOT NULL ORDER BY "section"', [$tenantId]), 'section');
$published = $failed ? (old('kb-edit', 'published') === 'on') : (bool) ($a['published'] ?? true);
?>
<div class="mx-auto max-w-3xl">
  <a href="<?= $a ? '/kb/' . e($a['id']) : '/kb' ?>" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"><?= icon('arrow-left', 16) ?> Back</a>
  <h1 class="text-2xl font-bold tracking-tight"><?= $a ? 'Edit article' : 'New article' ?></h1>
  <p class="mt-1 text-sm text-muted">Published articles are visible to everyone in this workspace and suggested to customers as they write a ticket.</p>

  <form method="post" action="/action/kb-save" class="mt-6 space-y-5 rounded-xl border border-border bg-surface p-5 sm:p-6" data-pending="Saving…">
    <?= form_meta('kb-edit') ?>
    <?php if ($a): ?><input type="hidden" name="id" value="<?= e($a['id']) ?>"><?php endif; ?>
    <?= banner($state) ?>
    <div>
      <label for="kb-title" class="<?= LABEL_CLS ?>">Title</label>
      <input id="kb-title" name="title" required maxlength="160" value="<?= e($v('title')) ?>" placeholder="How do I reset my password?" class="<?= INPUT_CLS ?>">
    </div>
    <div>
      <label for="kb-section" class="<?= LABEL_CLS ?>">Section</label>
      <input id="kb-section" name="section" list="kb-sections" maxlength="60" value="<?= e($v('section')) ?>" placeholder="e.g. Account, Billing" class="<?= INPUT_CLS ?>">
      <datalist id="kb-sections"><?php foreach ($sections as $sct): ?><option value="<?= e($sct) ?>"><?php endforeach; ?></datalist>
    </div>
    <div>
      <label class="<?= LABEL_CLS ?>">Article</label>
      <?= rich_editor('body', ['minHeight' => 260, 'placeholder' => 'Step-by-step answer…', 'html' => sanitize_rich_html($v('body'))]) ?>
    </div>
    <?php if (can($user, 'kb.publish')): ?>
    <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
      <input type="checkbox" name="published" <?= $published ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> Published
    </label>
    <?php else: ?>
      <p class="flex items-center gap-2 text-sm text-muted"><?= icon('lock', 14) ?> <?= $published && !empty($a) ? 'Published — only editors who can publish can take it down.' : 'Saved as a draft. Someone who can publish will make it public.' ?></p>
    <?php endif; ?>
    <button type="submit" class="<?= btn_cls() ?>"><?= icon('check', 16) ?> Save article</button>
  </form>
</div>
