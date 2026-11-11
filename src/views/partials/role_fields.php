<?php
/**
 * Name / description / colour fields of a custom role.
 * @var string $roleName @var string $roleDesc @var string $color @var array $swatches
 */
$fieldId = 'rf-' . substr(md5($roleName . $color . mt_rand()), 0, 6);
?>
<div class="mt-5 grid gap-4 border-t border-border pt-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
  <div>
    <label class="<?= LABEL_CLS ?>" for="<?= $fieldId ?>-n">Role name</label>
    <input id="<?= $fieldId ?>-n" name="name" value="<?= e($roleName) ?>" required maxlength="40" data-role-name placeholder="e.g. Junior agent" class="<?= INPUT_CLS ?>">
  </div>
  <div>
    <label class="<?= LABEL_CLS ?>" for="<?= $fieldId ?>-d">Description <span class="font-normal text-muted">(optional)</span></label>
    <input id="<?= $fieldId ?>-d" name="description" value="<?= e($roleDesc) ?>" maxlength="160" placeholder="Who is this role for?" class="<?= INPUT_CLS ?>">
  </div>
  <div class="sm:col-span-2">
    <span class="<?= LABEL_CLS ?>">Colour</span>
    <div class="flex flex-wrap items-center gap-2">
      <?php $matched = false; foreach ($swatches as $sw): $on = strcasecmp($sw, $color) === 0; $matched = $matched || $on; ?>
        <label class="cursor-pointer" title="<?= e($sw) ?>">
          <input type="radio" name="color" value="<?= e($sw) ?>" data-role-color <?= $on ? 'checked' : '' ?> class="peer sr-only">
          <span class="block h-7 w-7 rounded-full ring-2 ring-transparent ring-offset-2 ring-offset-surface transition peer-checked:ring-fg peer-focus-visible:ring-brand" style="background:<?= e($sw) ?>"></span>
        </label>
      <?php endforeach; ?>
      <label class="relative cursor-pointer" title="Custom colour">
        <input type="radio" name="color" value="<?= e($color) ?>" data-role-color data-custom-color-radio <?= $matched ? '' : 'checked' ?> class="peer sr-only">
        <span class="grid h-7 w-7 place-items-center rounded-full border border-dashed border-border text-muted ring-2 ring-transparent ring-offset-2 ring-offset-surface peer-checked:ring-fg" <?= $matched ? '' : 'style="background:' . e($color) . ';color:#fff"' ?>><?= icon('plus', 13) ?></span>
        <input type="color" value="<?= e($color) ?>" data-custom-color class="absolute inset-0 h-full w-full cursor-pointer opacity-0" aria-label="Custom colour">
      </label>
    </div>
  </div>
</div>
