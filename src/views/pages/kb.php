<?php
[$user, $tenantId] = require_tenant_user();
page_title('Knowledge base');
$staff = can($user, 'kb.manage'); // editors: drafts, stats, edit buttons
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200);
$articles = kb_find($tenantId, $q, $staff, $q !== '' ? 50 : 500);
$sections = [];
foreach ($articles as $a) $sections[$a['section'] ?: 'General'][] = $a;
if ($q === '') ksort($sections, SORT_NATURAL | SORT_FLAG_CASE);
?>
<div>
  <?= page_header('Knowledge base', $staff ? 'Answers your customers can find on their own — suggested automatically when they open a ticket.' : 'Find answers before opening a ticket.',
      $staff ? '<a href="/kb/new" class="' . btn_cls() . '">' . icon('plus', 16) . ' New article</a>' : '') ?>

  <form method="get" action="/kb" class="relative mb-6" role="search">
    <?= icon('search', 18, 'pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted') ?>
    <input name="q" type="search" value="<?= e($q) ?>" placeholder="Search articles… e.g. reset password, invoice" class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-base placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-brand/40">
  </form>

  <?php if (!$articles): ?>
    <div class="rounded-xl border border-border bg-surface px-5 py-16 text-center">
      <?= icon('book-open', 36, 'mx-auto text-muted') ?>
      <p class="mt-3 font-medium"><?= $q !== '' ? 'No articles match' : 'No articles yet' ?></p>
      <p class="mt-1 text-sm text-muted">
        <?= $staff ? 'Write answers to common questions so customers can help themselves.' : 'Can’t find what you need?' ?>
        <a href="<?= $staff ? '/kb/new' : '/tickets/new' ?>" class="font-medium text-brand hover:underline"><?= $staff ? 'Write the first article' : 'Open a ticket' ?></a>
      </p>
    </div>
  <?php else: ?>
    <?php if ($q !== ''): ?><p class="mb-3 text-sm text-muted"><?= count($articles) ?> result<?= count($articles) === 1 ? '' : 's' ?> for “<?= e($q) ?>”</p><?php endif; ?>
    <div class="space-y-6">
      <?php foreach ($sections as $section => $list): ?>
        <section>
          <?php if ($q === ''): ?><h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted"><?= e($section) ?></h2><?php endif; ?>
          <div class="divide-y divide-border rounded-xl border border-border bg-surface">
            <?php foreach ($list as $a): ?>
              <a href="/kb/<?= e($a['id']) ?>" class="flex items-start gap-3 px-4 py-3.5 hover:bg-surface-2 active:bg-surface-2 sm:px-5">
                <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand/10 text-brand"><?= icon('book-open', 15) ?></span>
                <span class="min-w-0 flex-1">
                  <span class="flex items-center gap-2">
                    <span class="truncate font-medium"><?= e($a['title']) ?></span>
                    <?php if (!$a['published']): ?><span class="rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-medium uppercase text-amber-700 dark:text-amber-300">Draft</span><?php endif; ?>
                  </span>
                  <span class="mt-0.5 line-clamp-2 block text-sm text-muted"><?= e(mb_substr(html_to_text($a['body']), 0, 180)) ?></span>
                </span>
                <?php if ($staff): ?>
                  <span class="hidden shrink-0 text-right text-xs text-muted sm:block"><?= number_format((int) $a['views']) ?> views<br><?= number_format((int) $a['helpful']) ?> helpful</span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
