<?php
/**
 * Settings → Organizations (orgs.manage).
 * @var array $me @var string $tenantId @var callable $s
 */
$closed = closed_status_keys(tenant_statuses($tenantId));
$orgs = q_all('SELECT o.*,
    (SELECT COUNT(*) FROM "UserOrganization" uo WHERE uo."organizationId" = o."id") AS "people",
    (SELECT COUNT(*) FROM "UserOrganization" uo WHERE uo."organizationId" = o."id" AND uo."isManager" = 1) AS "managers"
  FROM "Organization" o WHERE o."tenantId" = ? ORDER BY o."name"', [$tenantId]);
$openByOrg = array_column(q_all('SELECT uo."organizationId" AS org, COUNT(*) AS n FROM "Ticket" t JOIN "UserOrganization" uo ON uo."userId" = t."requesterId"
    WHERE t."tenantId" = ?' . ($closed ? ' AND t."status" NOT IN (' . in_ph($closed) . ')' : '') . ' GROUP BY uo."organizationId"', [$tenantId, ...$closed]), 'n', 'org');
$os = $s('orgs');
$openOrg = (string) ($_GET['org'] ?? '');
$sel = $openOrg !== '' && $openOrg !== 'new' ? q_one('SELECT * FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [$openOrg, $tenantId]) : null;
$people = $sel ? q_all('SELECT u."id", u."name", u."email", u."avatarColor", u."role", uo."isManager" AS "orgManager", u."lastLoginAt",
    (SELECT COUNT(*) FROM "UserOrganization" x WHERE x."userId" = u."id") AS "orgCount"
    FROM "UserOrganization" uo JOIN "User" u ON u."id" = uo."userId" WHERE uo."organizationId" = ? ORDER BY (CASE WHEN u."role" = \'CUSTOMER\' THEN 1 ELSE 0 END), uo."isManager" DESC, u."name" LIMIT 300', [$sel['id']]) : [];
$candidates = $sel ? q_all('SELECT u."id", u."name", u."email", u."role" FROM "User" u WHERE u."tenantId" = ? AND u."active" = 1
    AND NOT EXISTS (SELECT 1 FROM "UserOrganization" x WHERE x."userId" = u."id" AND x."organizationId" = ?) ORDER BY (CASE WHEN u."role" = \'CUSTOMER\' THEN 1 ELSE 0 END), u."name" LIMIT 500', [$tenantId, $sel['id']]) : [];

$orgForm = fn (?array $o) => '<form method="post" action="/action/org-save" class="space-y-4">' . form_meta('orgs', '/settings#orgs')
    . ($o ? '<input type="hidden" name="id" value="' . e($o['id']) . '">' : '')
    . '<div class="grid gap-3 sm:grid-cols-2">'
    . '<div><label class="mb-1 block text-xs font-medium text-muted">Name</label><input name="name" required maxlength="80" value="' . e($o['name'] ?? '') . '" placeholder="Acme Corporation" class="' . INPUT_CLS . '"></div>'
    . '<div><label class="mb-1 block text-xs font-medium text-muted">Email domains</label><input name="domain" value="' . e($o['domain'] ?? '') . '" placeholder="acme.com, acme.co.uk" class="' . INPUT_CLS . '"></div></div>'
    . '<div><label class="mb-1 block text-xs font-medium text-muted">Notes <span class="font-normal">(only staff see these)</span></label><textarea name="notes" rows="2" maxlength="2000" class="' . str_replace(' h-10', '', INPUT_CLS) . ' py-2">' . e($o['notes'] ?? '') . '</textarea></div>'
    . '<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="attach" ' . ($o ? '' : 'checked') . ' class="h-4 w-4 rounded border-border accent-brand"> Add existing customers whose email matches these domains</label>'
    . '<div class="flex flex-wrap gap-2"><button type="submit" class="' . btn_cls('primary', 'sm') . '">' . icon('check', 14) . ($o ? ' Save' : ' Create organization') . '</button>'
    . ($o ? '<button type="submit" formaction="/action/org-delete" formnovalidate data-confirm="Delete “' . e($o['name']) . '”? Its people stay as customers." class="' . btn_cls('ghost', 'sm') . ' text-red-600">' . icon('trash-2', 14) . ' Delete</button>' : '')
    . '</div></form>';
?>
<div class="rounded-xl border border-border bg-surface">
  <div class="flex flex-wrap items-start gap-4 p-5 sm:p-6">
    <span class="hidden h-11 w-11 shrink-0 place-items-center rounded-xl bg-amber-500/10 text-amber-600 sm:grid"><?= icon('building-2', 22) ?></span>
    <div class="min-w-0 flex-1">
      <h2 class="font-semibold">Organizations</h2>
      <p class="mt-0.5 text-sm text-muted">Group customers by company. New customers join automatically by email domain. <strong class="text-fg">Organization managers</strong> see every ticket of their organization.</p>
    </div>
    <a href="/settings?org=new#orgs" data-toggle-target="#org-new" class="<?= btn_cls('primary', 'sm') ?>"><?= icon('plus', 14) ?> New organization</a>
  </div>
  <?php if ($os): ?><div class="px-5 pb-5 sm:px-6"><?= banner($os, true) ?></div><?php endif; ?>
  <div id="org-new" class="border-t border-border bg-surface-2/40 p-5" <?= $openOrg === 'new' ? '' : 'hidden' ?>><?= $orgForm(null) ?></div>
</div>

<?php if ($sel): ?>
  <div class="rounded-xl border border-brand/40 bg-surface">
    <div class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
      <span class="grid h-10 w-10 place-items-center rounded-xl bg-amber-500/10 text-amber-600"><?= icon('building-2', 18) ?></span>
      <div class="min-w-0 flex-1"><h3 class="truncate font-semibold"><?= e($sel['name']) ?></h3><p class="text-xs text-muted"><?= e($sel['domain'] ?: 'No domains') ?></p></div>
      <a href="/tickets?org=<?= e($sel['id']) ?>" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('ticket', 14) ?> Tickets</a>
      <a href="/settings#orgs" class="<?= btn_cls('ghost', 'sm') ?>"><?= icon('x', 14) ?> Close</a>
    </div>
    <div class="grid gap-6 p-5 lg:grid-cols-2">
      <div><?= $orgForm($sel) ?></div>
      <div>
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">People (<?= count($people) ?>)</p>
        <ul class="divide-y divide-border rounded-xl border border-border">
          <?php if (!$people): ?><li class="px-4 py-6 text-center text-sm text-muted">Nobody yet.</li><?php endif; ?>
          <?php foreach ($people as $p): ?>
            <li class="flex items-center gap-2.5 px-3 py-2">
              <?= avatar($p['name'], $p['avatarColor'], 28) ?>
              <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium"><?= e($p['name']) ?></span><span class="block truncate text-xs text-muted"><?= e($p['email']) ?><?= (int) $p['orgCount'] > 1 ? ' · also in ' . ((int) $p['orgCount'] - 1) . ' other' : '' ?></span></span>
              <?php if ($p['role'] !== 'CUSTOMER'): ?>
                <span class="rounded-full bg-sky-500/10 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:text-sky-300" title="Staff who handle this organization see all of its tickets"><?= icon('users-round', 11, 'inline -mt-px') ?> <?= e(role_label($p['role']) === 'Agent' ? 'Agent' : role_label($p['role'])) ?></span>
              <?php else: ?>
              <form method="post" action="/action/org-member" data-ajax>
                <?= form_meta('orgs') ?><input type="hidden" name="userId" value="<?= e($p['id']) ?>"><input type="hidden" name="organizationId" value="<?= e($sel['id']) ?>"><input type="hidden" name="orgManager" value="<?= (int) $p['orgManager'] ? '0' : '1' ?>">
                <button type="submit" title="<?= (int) $p['orgManager'] ? 'Remove manager rights' : 'Make manager (sees all of the organization’s tickets)' ?>" class="<?= cx('rounded-full px-2 py-0.5 text-[11px] font-medium cursor-pointer', (int) $p['orgManager'] ? 'bg-brand/10 text-brand' : 'bg-surface-2 text-muted hover:text-fg') ?>"><?= (int) $p['orgManager'] ? icon('crown', 11, 'inline -mt-px') . ' Manager' : 'Member' ?></button>
              </form>
              <?php endif; ?>
              <form method="post" action="/action/org-member" data-ajax>
                <?= form_meta('orgs') ?><input type="hidden" name="userId" value="<?= e($p['id']) ?>"><input type="hidden" name="organizationId" value="<?= e($sel['id']) ?>"><input type="hidden" name="op" value="remove">
                <button type="submit" aria-label="Remove from organization" title="Remove from organization" class="text-muted hover:text-red-600 cursor-pointer"><?= icon('x', 15) ?></button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($candidates): ?>
          <form method="post" action="/action/org-member" data-ajax class="mt-3 flex gap-2">
            <?= form_meta('orgs') ?><input type="hidden" name="organizationId" value="<?= e($sel['id']) ?>">
            <select name="userId" required class="h-9 min-w-0 flex-1 cursor-pointer rounded-lg border border-border bg-surface px-2.5 text-sm"><?= select_options(['' => 'Add a person…'] + array_combine(array_column($candidates, 'id'), array_map(fn ($c) => ($c['role'] !== 'CUSTOMER' ? '★ ' . role_label($c['role']) . ' — ' : '') . $c['name'] . ' · ' . $c['email'], $candidates)), '') ?></select>
            <button type="submit" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('plus', 14) ?> Add</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php require ROOT . '/src/views/partials/contracts_admin.php'; ?>
  </div>
<?php endif; ?>

<div class="rounded-xl border border-border bg-surface">
  <?php if (!$orgs): ?>
    <div class="p-10 text-center">
      <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-surface-2 text-muted"><?= icon('building-2', 20) ?></span>
      <p class="mt-3 text-sm font-medium">No organizations yet</p>
      <p class="text-xs text-muted">Add your customers’ companies and their email domains.</p>
    </div>
  <?php else: ?>
    <div class="hidden grid-cols-[minmax(0,1fr)_minmax(0,1fr)_6rem_6rem] gap-4 border-b border-border px-5 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted md:grid">
      <span>Organization</span><span>Domains</span><span class="text-right">People</span><span class="text-right">Open tickets</span>
    </div>
    <div class="divide-y divide-border">
      <?php foreach ($orgs as $o): ?>
        <a href="/settings?org=<?= e($o['id']) ?>#orgs" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2/50 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_6rem_6rem]">
          <span class="flex min-w-0 items-center gap-2.5"><span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-amber-500/10 text-xs font-semibold text-amber-700 dark:text-amber-400"><?= e(initials($o['name'])) ?></span><span class="truncate text-sm font-medium"><?= e($o['name']) ?></span></span>
          <span class="truncate text-xs text-muted max-md:col-start-1 max-md:pl-[2.6rem]"><?= e($o['domain'] ?: '—') ?></span>
          <span class="text-right text-sm tabular-nums max-md:row-start-1 max-md:col-start-2"><?= (int) $o['people'] ?><?php if ((int) $o['managers']): ?> <span class="text-xs text-muted">(<?= (int) $o['managers'] ?> mgr)</span><?php endif; ?></span>
          <span class="text-right text-sm tabular-nums max-md:hidden"><?= (int) ($openByOrg[$o['id']] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
