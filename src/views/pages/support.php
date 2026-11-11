<?php
/**
 * Public support form (/support/{slug}), also embedded by the widget (?embed=1).
 * @var array $tenant @var ?array $result @var bool $embed
 */
$cfg = webform_settings($tenant['id']);
$categories = $cfg['showCategory'] ? q_all('SELECT "id","name" FROM "Category" WHERE "tenantId" = ? ORDER BY "name"', [$tenant['id']]) : [];
$fields = array_values(array_filter(tenant_fields($tenant['id']), fn ($f) => (int) $f['customerVisible']));
$old = fn (string $k) => e((string) ($result && isset($result['error']) ? ($_POST[$k] ?? '') : ''));
$oldCf = $result && isset($result['error']) ? (array) ($_POST['cf'] ?? []) : [];
$accent = $cfg['color'];
?>
<style>:root{--color-brand:<?= e($accent) ?>}</style>
<div class="<?= $embed ? 'min-h-screen bg-surface' : 'min-h-screen bg-bg px-4 py-10 sm:py-16' ?>">
  <div class="<?= $embed ? 'p-5' : 'mx-auto max-w-xl' ?>">
    <?php if (!$embed): ?>
      <div class="mb-6 flex items-center gap-2.5">
        <span class="grid h-9 w-9 place-items-center rounded-xl text-white" style="background:<?= e($accent) ?>"><?= icon('life-buoy', 18) ?></span>
        <span class="font-semibold"><?= e($tenant['name']) ?></span>
      </div>
    <?php endif; ?>

    <?php if ($result && isset($result['ok'])): $t = $result['ok']; ?>
      <div class="rounded-2xl border border-border bg-surface p-6 text-center <?= $embed ? '' : 'shadow-sm' ?>">
        <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-emerald-500/10 text-emerald-600"><?= icon('circle-check', 24) ?></span>
        <h1 class="mt-4 text-xl font-semibold">Request received</h1>
        <p class="mt-1 text-sm text-muted">Your ticket number is <strong class="text-fg">#<?= (int) $t['number'] ?></strong>. We’ve emailed you a confirmation and will reply there.</p>
        <a href="<?= e(strtok($_SERVER['REQUEST_URI'], '?') . ($embed ? '?embed=1' : '')) ?>" class="mt-5 inline-flex text-sm font-medium" style="color:<?= e($accent) ?>">Send another request</a>
      </div>
    <?php else: ?>
      <div class="<?= $embed ? '' : 'rounded-2xl border border-border bg-surface p-6 shadow-sm sm:p-8' ?>">
        <h1 class="text-xl font-semibold"><?= e($cfg['title']) ?></h1>
        <?php if ($cfg['intro']): ?><p class="mt-1 text-sm text-muted"><?= e($cfg['intro']) ?></p><?php endif; ?>
        <?php if ($result && isset($result['error'])): ?>
          <p class="mt-4 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-700 dark:text-red-300"><?= e($result['error']) ?></p>
        <?php endif; ?>
        <form method="post" class="mt-5 space-y-4" data-support-form data-kb-url="<?= e('/support/' . $tenant['slug'] . '/kb') ?>">
          <input type="hidden" name="stamp" value="<?= e(webform_stamp($tenant['id'])) ?>">
          <div class="hidden" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div><label class="<?= LABEL_CLS ?>" for="sf-name">Your name</label><input id="sf-name" name="name" required maxlength="80" value="<?= $old('name') ?>" autocomplete="name" class="<?= INPUT_CLS ?>"></div>
            <div><label class="<?= LABEL_CLS ?>" for="sf-email">Email</label><input id="sf-email" name="email" type="email" required value="<?= $old('email') ?>" autocomplete="email" class="<?= INPUT_CLS ?>"></div>
          </div>
          <div>
            <label class="<?= LABEL_CLS ?>" for="sf-subject">Subject</label>
            <input id="sf-subject" name="subject" required minlength="3" maxlength="140" value="<?= $old('subject') ?>" class="<?= INPUT_CLS ?>" autocomplete="off" data-support-subject>
            <div data-support-kb hidden class="mt-2 rounded-xl border border-border bg-surface-2/60 p-3">
              <p class="mb-1.5 text-xs font-semibold text-muted">These articles might help</p>
              <ul data-support-kb-list class="space-y-2 text-sm"></ul>
            </div>
          </div>
          <?php if ($categories): ?>
            <div><label class="<?= LABEL_CLS ?>" for="categoryId">Topic</label>
              <select id="categoryId" name="categoryId" class="<?= SELECT_CLS ?>"><?= select_options(['' => '— Choose —'] + array_column($categories, 'name', 'id'), ($result && isset($result['error'])) ? (string) ($_POST['categoryId'] ?? '') : '') ?></select></div>
          <?php endif; ?>
          <?= fields_form_block($fields, $oldCf) ?>
          <div><label class="<?= LABEL_CLS ?>" for="sf-message">How can we help?</label>
            <textarea id="sf-message" name="message" required rows="6" maxlength="20000" class="<?= str_replace(' h-10', '', INPUT_CLS) ?> py-2"><?= $old('message') ?></textarea></div>
          <button type="submit" class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold text-white transition hover:opacity-90 cursor-pointer" style="background:<?= e($accent) ?>"><?= icon('send', 16) ?> Send request</button>
          <p class="text-center text-[11px] text-muted">We’ll create an account for this email so you can follow your request.</p>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  var f = document.querySelector('[data-support-form]');
  if (!f) return;
  var cat = f.querySelector('[name=categoryId]');
  function sync() { var v = cat ? cat.value : ''; f.querySelectorAll('[data-field-category]').forEach(function (el) { var c = el.getAttribute('data-field-category'); var on = !c || c === v; el.hidden = !on; el.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !on; }); }); }
  if (cat) cat.addEventListener('change', sync);
  sync();
  var subj = f.querySelector('[data-support-subject]'), box = f.querySelector('[data-support-kb]'), list = f.querySelector('[data-support-kb-list]'), t;
  subj.addEventListener('input', function () {
    clearTimeout(t);
    t = setTimeout(function () {
      var q = subj.value.trim();
      if (q.length < 4) { box.hidden = true; return; }
      fetch(f.getAttribute('data-kb-url') + '?q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (d) {
        list.innerHTML = '';
        (d.items || []).forEach(function (it) { var li = document.createElement('li'); var b = document.createElement('strong'); b.textContent = it.title; var p = document.createElement('span'); p.className = 'block text-xs text-muted'; p.textContent = it.excerpt; li.appendChild(b); li.appendChild(p); list.appendChild(li); });
        box.hidden = !(d.items || []).length;
      }).catch(function () {});
    }, 350);
  });
})();
</script>
