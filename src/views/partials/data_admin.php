<?php
/** Settings → Backups & data. @var array $me @var string $tenantId @var callable $s */
$canExport = can($me, 'data.export');
$canErase = can($me, 'data.erase');
$bk = backup_settings($tenantId);
$bkList = $canExport ? backup_list($tenantId) : [];
$orgsAll = array_column(tenant_orgs($tenantId), 'name', 'id');
$zipOk = class_exists('ZipArchive');
?>
<?php if (!$zipOk): ?>
  <p class="rounded-xl border border-amber-500/40 bg-amber-500/5 p-4 text-sm text-amber-800 dark:text-amber-300">The PHP <code>zip</code> extension is missing on this server, so backups and exports can’t be created. Turn it on in Plesk → PHP settings.</p>
<?php endif; ?>

<?php if ($canExport): ?>
<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('database', 'Backups')) ?>
  <div class="space-y-5 p-5">
    <p class="text-sm text-muted">A backup is a ZIP with every record of this workspace as JSON (tickets, messages, people, settings, time, contracts…), optionally with the uploaded files. Password hashes, tokens and API keys are never included.</p>
    <div class="flex flex-wrap gap-2">
      <a href="/settings/backup.zip" class="<?= btn_cls('primary', 'sm') ?>"><?= icon('download', 14) ?> Download backup</a>
      <a href="/settings/backup.zip?files=1" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('archive', 14) ?> With files</a>
    </div>
    <form method="post" action="/action/backup-settings-save" data-ajax class="space-y-3 rounded-xl border border-border p-4">
      <?= form_meta() ?>
      <label class="flex cursor-pointer items-start gap-2.5 text-sm"><input type="checkbox" name="daily" <?= $bk['daily'] ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-border accent-brand">
        <span><span class="font-medium">Make a backup every day</span><span class="block text-xs text-muted">Stored on this server (storage/backups, not reachable from the web) by the background job. Copy them elsewhere too — a backup on the same server doesn’t protect against losing the server.</span></span></label>
      <div class="flex flex-wrap items-center gap-4 pl-6 text-sm">
        <label class="flex items-center gap-2">Keep the newest <input type="number" name="keep" min="1" max="60" value="<?= (int) $bk['keep'] ?>" class="h-8 w-16 rounded-lg border border-border bg-surface px-2"> days</label>
        <label class="flex items-center gap-2"><input type="checkbox" name="files" <?= $bk['files'] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> Include files</label>
        <button type="submit" class="<?= btn_cls('outline', 'sm', 'ml-auto') ?>">Save</button>
      </div>
    </form>
    <?php if ($bkList): ?>
      <ul class="divide-y divide-border rounded-xl border border-border text-sm">
        <?php foreach ($bkList as $b): ?>
          <li class="flex items-center gap-3 px-4 py-2.5"><?= icon('archive', 15, 'text-muted') ?><span class="min-w-0 flex-1 truncate"><?= e($b['name']) ?></span><span class="text-xs text-muted"><?= e(format_bytes((int) $b['size'])) ?></span>
            <a href="/settings/backups/<?= e($b['name']) ?>" class="<?= btn_cls('ghost', 'sm') ?>"><?= icon('download', 14) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('building-2', 'Export an organization')) ?>
  <form method="get" action="/settings/export/org.zip" class="flex flex-wrap items-end gap-3 p-5">
    <div class="min-w-48 flex-1"><label class="<?= LABEL_CLS ?>">Organization</label><select name="id" required class="<?= SELECT_CLS ?>"><option value="">Choose…</option><?= select_options($orgsAll, '') ?></select></div>
    <label class="flex h-10 items-center gap-2 text-sm"><input type="checkbox" name="files" value="1" class="h-4 w-4 rounded border-border accent-brand"> Include files</label>
    <button type="submit" class="<?= btn_cls('outline') ?>"><?= icon('download', 15) ?> Download ZIP</button>
    <p class="w-full text-xs text-muted">People, tickets, messages (including internal notes), time entries and contracts as CSV files that open in Excel — e.g. when a customer leaves or asks for their history.</p>
  </form>
</div>
<?php endif; ?>

<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('shield-check', 'Personal data (GDPR)')) ?>
  <div class="space-y-5 p-5">
    <?php if ($canExport): ?>
      <form method="get" action="/settings/export/person.json" class="flex flex-wrap items-end gap-3">
        <div class="min-w-56 flex-1"><label class="<?= LABEL_CLS ?>">Give a person their data</label><input type="email" name="email" required placeholder="name@company.com" class="<?= INPUT_CLS ?>"></div>
        <button type="submit" class="<?= btn_cls('outline') ?>"><?= icon('download', 15) ?> Download (JSON)</button>
      </form>
    <?php endif; ?>
    <?php if ($canErase): ?>
      <form method="post" action="/action/person-erase" data-ajax data-confirm="Erase this person’s personal data? This can’t be undone." class="space-y-3 rounded-xl border border-red-500/30 bg-red-500/5 p-4">
        <?= form_meta() ?>
        <p class="flex items-center gap-1.5 text-sm font-medium text-red-700 dark:text-red-300"><?= icon('user-x', 15) ?> Erase a person (right to be forgotten)</p>
        <p class="text-xs text-muted">Their name and email are replaced, the account is deactivated and removed from organizations, devices, saved views and notifications. Tickets stay for your records unless you also remove the content.</p>
        <div class="grid gap-3 sm:grid-cols-2">
          <div><label class="<?= LABEL_CLS ?>">Email of the person</label><input type="email" name="email" required class="<?= INPUT_CLS ?>"></div>
          <div><label class="<?= LABEL_CLS ?>">Type the email again</label><input type="email" name="confirm" required autocomplete="off" class="<?= INPUT_CLS ?>"></div>
        </div>
        <label class="flex cursor-pointer items-start gap-2.5 text-sm"><input type="checkbox" name="content" class="mt-0.5 h-4 w-4 rounded border-border accent-red-600">
          <span>Also remove their messages, ticket descriptions and uploaded files</span></label>
        <button type="submit" class="<?= btn_cls('danger', 'sm') ?>"><?= icon('trash-2', 14) ?> Erase personal data</button>
      </form>
    <?php endif; ?>
  </div>
</div>
