<?php
/** Settings → General → Attachments. @var array $me @var string $tenantId @var callable $s */
if (!can($me, 'settings.attachments')) return;
$att = attachment_settings($tenantId);
$serverMax = server_upload_limit_mb();
$attState = $s('attachments');
?>
<!-- Attachments -->
<div class="rounded-xl border border-border bg-surface" id="attachments">
  <?= card_header(titled('paperclip', 'Attachments')) ?>
  <form method="post" action="/action/attachment-settings-save" data-ajax class="space-y-5 p-5">
    <?= form_meta('attachments') ?>
    <div class="grid gap-4 sm:grid-cols-2">
      <div>
        <label for="att-max" class="<?= LABEL_CLS ?>">Largest file (MB)</label>
        <input id="att-max" name="maxMB" type="number" min="1" max="100" value="<?= (int) $att['maxMB'] ?>" class="<?= INPUT_CLS ?>">
        <p class="mt-1 text-xs <?= $att['maxMB'] > $serverMax ? 'text-amber-700 dark:text-amber-400' : 'text-muted' ?>">This server accepts up to <?= $serverMax ?> MB per upload (PHP <code>upload_max_filesize</code> / <code>post_max_size</code>)<?= $att['maxMB'] > $serverMax ? ' — the lower limit applies.' : '.' ?></p>
      </div>
      <div>
        <label for="att-count" class="<?= LABEL_CLS ?>">Files per message</label>
        <input id="att-count" name="maxFiles" type="number" min="1" max="20" value="<?= (int) $att['maxFiles'] ?>" class="<?= INPUT_CLS ?>">
      </div>
    </div>
    <fieldset>
      <legend class="<?= LABEL_CLS ?>">Allowed file types</legend>
      <div class="grid gap-2 sm:grid-cols-2">
        <?php foreach (ATTACHMENT_TYPES as $k => [$label, $exts]): ?>
          <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-border px-3 py-2.5 hover:bg-surface-2">
            <input type="checkbox" name="types[]" value="<?= e($k) ?>" <?= in_array($k, $att['types'], true) ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-border accent-brand">
            <span class="min-w-0"><span class="block text-sm font-medium"><?= e($label) ?></span><span class="block text-xs text-muted"><?= e(implode(', ', array_map(fn ($x) => '.' . $x, array_keys($exts)))) ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="mt-2 text-xs text-muted">Files are checked by their extension and always downloaded — only images and PDFs open in the viewer.</p>
    </fieldset>
    <div class="space-y-2">
      <label class="flex cursor-pointer items-start gap-2.5 text-sm"><input type="checkbox" name="customerUploads" <?= $att['customerUploads'] ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-border accent-brand">
        <span><span class="font-medium">Customers can attach files</span><span class="block text-xs text-muted">When off, only staff can add files to tickets.</span></span></label>
      <label class="flex cursor-pointer items-start gap-2.5 text-sm"><input type="checkbox" name="staffOnly" <?= $att['staffOnly'] ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-border accent-brand">
        <span><span class="font-medium">Staff-only files</span><span class="block text-xs text-muted">Agents can mark the files of a public reply as visible to staff only (e.g. logs or screenshots with internal data).</span></span></label>
    </div>
    <p class="text-xs text-muted">Deleting files: people can remove their own within <?= MESSAGE_EDIT_MINUTES ?> minutes; anyone else needs the “Delete files” permission. Every deletion is written to the ticket history and the audit log.</p>
    <?= inline_error($attState) ?>
    <button type="submit" class="<?= btn_cls() ?>">Save attachment settings</button>
  </form>
</div>
