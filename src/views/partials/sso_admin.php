<?php
/**
 * Settings → Security → Single sign-on (workspace) and Sign-in providers (super admins).
 * @var array $me @var string $tenantId @var callable $s
 */
$sso = sso_policy($tenantId);
$ssoFlash = $s('sso');
$ssoReady = sso_available();
$linked = array_column(q_all('SELECT i."provider", COUNT(*) AS "n" FROM "UserIdentity" i JOIN "User" u ON u."id" = i."userId" WHERE u."tenantId" = ? GROUP BY i."provider"', [$tenantId]), 'n', 'provider');
$ssoToggle = function (string $name, bool $on, string $title, string $hint, bool $disabled = false, string $value = 'on'): string {
    return '<label class="flex items-start justify-between gap-4 rounded-xl border border-border p-4' . ($disabled ? ' opacity-60' : ' cursor-pointer') . '">'
        . '<span><span class="block text-sm font-medium">' . $title . '</span><span class="block text-xs text-muted">' . $hint . '</span></span>'
        . '<span class="relative mt-0.5 inline-flex shrink-0 items-center">'
        . '<input type="checkbox" name="' . e($name) . '" value="' . e($value) . '" ' . ($on ? 'checked' : '') . ($disabled ? ' disabled' : '') . ' class="peer sr-only">'
        . '<span class="h-5 w-9 rounded-full bg-border transition-colors peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40"></span>'
        . '<span class="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4"></span>'
        . '</span></label>';
};
?>
<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('log-in', 'Single sign-on')) ?>
  <form method="post" action="/action/sso-save" class="space-y-5 p-5 sm:p-6" data-dirty>
    <?= form_meta('sso', '/settings#security') ?>
    <?= banner($ssoFlash, true) ?>
    <p class="text-sm text-muted">People sign in with their work Microsoft 365 or Google account instead of a password. The first time, the account with the same email is linked; after that the sign-in follows that Microsoft / Google account.</p>
    <?php if (!$ssoReady): ?>
      <p class="flex items-start gap-2 rounded-lg bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-400"><?= icon('info', 14, 'mt-px shrink-0') ?> No sign-in provider is set up on this platform yet<?= $me['role'] === 'SUPER_ADMIN' ? ' — add one under “Sign-in providers” below.' : '. Ask the platform’s super admin.' ?></p>
    <?php endif; ?>
    <div class="grid gap-3 sm:grid-cols-2">
      <?php foreach (SSO_PROVIDERS as $p => $label): $ready = sso_configured($p); $n = (int) ($linked[$p] ?? 0); ?>
        <?= $ssoToggle('providers[]', in_array($p, $sso['providers'], true), '<span class="inline-flex items-center gap-2">' . sso_logo($p) . e($label) . '</span>',
            $ready ? number_format($n) . ' linked account' . ($n === 1 ? '' : 's') : 'Not set up on this platform', !$ready && !in_array($p, $sso['providers'], true), $p) ?>
      <?php endforeach; ?>
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
      <?= $ssoToggle('staff', $sso['staff'], 'Staff can use it', 'Administrators and agents.') ?>
      <?= $ssoToggle('customers', $sso['customers'], 'Customers can use it', 'People who open tickets.') ?>
    </div>
    <?= $ssoToggle('autoCreate', $sso['autoCreate'], 'Customers of my organizations can join on their own', 'Someone whose email domain belongs to one of your organizations (Settings → Organizations → email domains) gets a customer account in it the first time they sign in. Everyone else needs an invitation.') ?>
    <?= $ssoToggle('requireAgents', $sso['requireAgents'], 'Agents must use single sign-on', 'Agents can’t sign in with a password. Administrators still can, so nobody is locked out if the provider is down.') ?>
    <div>
      <label for="msTenants" class="<?= LABEL_CLS ?>">Trusted Microsoft directories <span class="font-normal text-muted">(optional)</span></label>
      <textarea id="msTenants" name="msTenants" rows="2" placeholder="00000000-0000-0000-0000-000000000000" class="<?= INPUT_CLS ?> font-mono text-xs"><?= e(implode("\n", $sso['msTenants'])) ?></textarea>
      <p class="mt-1.5 text-xs text-muted">Microsoft vouches for an email only when its domain is verified in the person’s directory. If someone is told Microsoft didn’t confirm their email, add the directory (tenant) ID from that message — only for directories you know, such as your own or a customer’s.</p>
    </div>
    <button type="submit" data-dirty-save disabled class="<?= btn_cls() ?>"><?= icon('check', 16) ?> Save single sign-on</button>
  </form>
</div>

<?php if ($me['role'] === 'SUPER_ADMIN'):
    $plat = sso_platform();
    $pf = $s('sso-platform');
?>
<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('key-round', 'Sign-in providers (platform)')) ?>
  <div class="space-y-5 p-5 sm:p-6">
    <?= banner($pf, true) ?>
    <p class="text-sm text-muted">One app registration per provider serves every workspace; each workspace turns it on above. Only super admins see this. Secrets are stored encrypted.</p>
    <div class="grid gap-4 xl:grid-cols-2">
    <?php foreach (SSO_PROVIDERS as $p => $label): $c = $plat[$p]; ?>
      <form method="post" action="/action/sso-platform-save" class="space-y-3 rounded-xl border border-border p-4" data-pending="Saving…">
        <?= form_meta('sso-platform', '/settings#security') ?>
        <input type="hidden" name="provider" value="<?= e($p) ?>">
        <div class="flex items-center justify-between gap-3">
          <p class="inline-flex items-center gap-2 font-medium"><?= sso_logo($p) ?><?= e($label) ?></p>
          <?= $c['source'] ? '<span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-600">' . ($c['source'] === 'env' ? 'Set in .env' : 'Set up') . '</span>' : '<span class="rounded-full bg-surface-2 px-2 py-0.5 text-xs text-muted">Not set up</span>' ?>
        </div>
        <ol class="list-decimal space-y-1 pl-4 text-xs text-muted">
          <?php if ($p === 'microsoft'): ?>
            <li>Microsoft Entra admin center → App registrations → New registration. Supported account types: any organizational directory and personal Microsoft accounts.</li>
            <li>Redirect URI (Web): the address below. Then Certificates &amp; secrets → New client secret.</li>
            <li>Token configuration → Add optional claim → ID → <code>email</code> and <code>xms_edov</code>.</li>
          <?php else: ?>
            <li>Google Cloud console → APIs &amp; Services → OAuth consent screen (External), then Credentials → Create credentials → OAuth client ID → Web application.</li>
            <li>Authorized redirect URI: the address below.</li>
          <?php endif; ?>
        </ol>
        <div>
          <p class="<?= LABEL_CLS ?>">Redirect URI</p>
          <input readonly value="<?= e(sso_redirect_uri($p)) ?>" class="<?= INPUT_CLS ?> font-mono text-xs" onclick="this.select()">
        </div>
        <div>
          <label for="cid-<?= $p ?>" class="<?= LABEL_CLS ?>">Client ID</label>
          <input id="cid-<?= $p ?>" name="clientId" value="<?= e($c['source'] === 'settings' ? $c['clientId'] : '') ?>" autocomplete="off" class="<?= INPUT_CLS ?> font-mono text-xs">
        </div>
        <div>
          <label for="cs-<?= $p ?>" class="<?= LABEL_CLS ?>">Client secret</label>
          <input id="cs-<?= $p ?>" name="clientSecret" type="password" autocomplete="new-password" placeholder="<?= $c['source'] === 'settings' ? 'Saved — leave empty to keep it' : '' ?>" class="<?= INPUT_CLS ?> font-mono text-xs">
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="submit" class="<?= btn_cls() ?>"><?= icon('check', 16) ?> Save</button>
          <?php if ($c['source'] === 'settings'): ?>
            <button type="submit" name="clear" value="1" class="<?= btn_cls('outline') ?>" data-confirm="Remove <?= e($label) ?> sign-in from the platform? Nobody can use it until it is set up again."><?= icon('trash-2', 16) ?> Remove</button>
          <?php endif; ?>
        </div>
      </form>
    <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>
