<?php
/** About & what's new: the running version, server details (admins) and the changelog. */
[$user, $tenantId] = require_tenant_user();
page_title('About & what’s new');
$admin = $user['role'] === 'SUPER_ADMIN' || can($user, 'settings.*');
$release = app_release();
$releases = app_changelog();
$marker = (string) @file_get_contents(ROOT . '/storage/.schema-version');
$schemaOk = str_starts_with($marker, RUNTIME_SCHEMA_VERSION . '|');
$dbVersion = '';
if ($admin) { try { $dbVersion = db_driver() === 'mysql' ? (string) q_val('SELECT VERSION()') : 'SQLite ' . (string) q_val('SELECT sqlite_version()'); } catch (Throwable) {} }
$secIcon = ['Added' => ['sparkles', 'text-emerald-600 bg-emerald-500/10'], 'Improved' => ['zap', 'text-sky-600 bg-sky-500/10'], 'Changed' => ['rotate-ccw', 'text-amber-600 bg-amber-500/10'], 'Fixed' => ['check', 'text-violet-600 bg-violet-500/10'], 'Removed' => ['trash-2', 'text-red-600 bg-red-500/10'], 'Security' => ['shield', 'text-red-600 bg-red-500/10']];
$fmtDate = fn (string $d) => $d !== '' && strtotime($d) ? date('j M Y', strtotime($d)) : $d;
?>
<div class="mx-auto max-w-3xl">
  <?= page_header('About & what’s new', 'The version of the app you’re using and what changed in each release.') ?>

  <div class="mb-6 overflow-hidden rounded-xl border border-border bg-surface">
    <div class="flex flex-wrap items-center gap-4 p-5 sm:p-6">
      <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-brand/10 text-brand"><?= icon('headset', 28) ?></span>
      <div class="min-w-0 flex-1">
        <p class="text-sm text-muted"><?= e(app_name()) ?></p>
        <p class="text-2xl font-bold tracking-tight">Version <?= e(APP_VERSION) ?></p>
        <p class="text-sm text-muted"><?= $release && $release['date'] ? 'Released ' . e($fmtDate($release['date'])) : 'Release date unknown' ?></p>
      </div>
      <span class="rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400">Installed</span>
    </div>
    <?php if ($admin): ?>
      <dl class="grid border-t border-border text-sm sm:grid-cols-2">
        <?php foreach ([
            ['Database schema', 'v' . RUNTIME_SCHEMA_VERSION . ($schemaOk ? ' · up to date' : ' · will update on the next request')],
            ['Database', db_driver() === 'mysql' ? 'MySQL / MariaDB ' . $dbVersion : $dbVersion],
            ['PHP', PHP_VERSION . ' (' . PHP_SAPI . ')'],
            ['Upload limit', server_upload_limit_mb() . ' MB'],
            ['Server time', date('j M Y H:i') . ' · ' . date_default_timezone_get()],
            ['Changelog file', is_file(ROOT . '/CHANGELOG.md') ? count($releases) . ' releases' : 'missing'],
        ] as $i => [$k, $v]): ?>
          <div class="flex gap-3 border-border px-5 py-3 <?= $i >= 2 ? 'border-t' : ($i === 1 ? 'max-sm:border-t' : '') ?> <?= $i % 2 ? 'sm:border-l' : '' ?>">
            <dt class="w-32 shrink-0 text-muted"><?= e($k) ?></dt><dd class="min-w-0 break-words font-medium"><?= e($v) ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>
  </div>

  <h2 class="mb-3 text-lg font-semibold">What’s new</h2>
  <?php if (!$releases): ?>
    <p class="rounded-xl border border-border bg-surface p-6 text-sm text-muted">No release notes found.</p>
  <?php endif; ?>
  <ol class="relative space-y-4 border-l border-border pl-6">
    <?php foreach ($releases as $r): $current = $r['version'] === APP_VERSION; ?>
      <li class="relative" id="v<?= e(str_replace('.', '-', $r['version'])) ?>">
        <span class="absolute -left-[1.95rem] top-5 h-3.5 w-3.5 rounded-full border-2 border-surface <?= $current ? 'bg-brand ring-4 ring-brand/15' : 'bg-border' ?>"></span>
        <div class="rounded-xl border <?= $current ? 'border-brand/40' : 'border-border' ?> bg-surface p-5">
          <div class="mb-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h3 class="text-base font-semibold">v<?= e($r['version']) ?></h3>
            <span class="text-xs text-muted"><?= e($fmtDate($r['date'])) ?></span>
            <?php if ($current): ?><span class="rounded-full bg-brand/10 px-2 py-0.5 text-[11px] font-semibold text-brand">Your version</span><?php endif; ?>
          </div>
          <div class="space-y-3">
            <?php foreach ($r['sections'] as $title => $items): [$ic, $cls] = $secIcon[$title] ?? ['circle-dot', 'text-muted bg-surface-2']; ?>
              <div>
                <p class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted"><span class="grid h-5 w-5 place-items-center rounded-md <?= $cls ?>"><?= icon($ic, 12) ?></span><?= e($title) ?></p>
                <ul class="space-y-1 pl-1 text-sm leading-relaxed">
                  <?php foreach ($items as $it): ?><li class="flex gap-2"><span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-muted"></span><span><?= changelog_inline($it) ?></span></li><?php endforeach; ?>
                </ul>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>
</div>
