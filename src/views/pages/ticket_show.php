<?php
/** @var string $id */
[$user, $tenantId] = require_tenant_user();
$staff = is_staff($user['role']);

$ticket = q_one('SELECT * FROM "Ticket" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId]);
if (!$ticket) abort(404);

$requester = q_one('SELECT * FROM "User" WHERE "id" = ?', [$ticket['requesterId']]);
$assignee = $ticket['assigneeId'] ? q_one('SELECT "name" FROM "User" WHERE "id" = ?', [$ticket['assigneeId']]) : null;
$assigneeRows = ticket_assignees($ticket);
$assigneeIds = array_column($assigneeRows, 'id');
$category = $ticket['categoryId'] ? q_one('SELECT "name" FROM "Category" WHERE "id" = ?', [$ticket['categoryId']]) : null;
$watchers = q_all('SELECT w."role" AS "watchRole", u."id", u."name", u."avatarColor", u."role" FROM "TicketWatcher" w JOIN "User" u ON u."id" = w."userId" WHERE w."ticketId" = ? ORDER BY w."createdAt" ASC', [$id]);

$myWatch = null;
foreach ($watchers as $w) if ($w['id'] === $user['id']) $myWatch = $w;
// Customers may view a ticket only if they requested it or are on its team.
if (!ticket_visible($ticket, $user)) abort(404);
// Fine-grained staff permissions (see Settings → Users & roles).
$perm = fn (string $p) => $staff && can($user, $p);
$canInternal = $perm('tickets.internal');
// Disabled selects are not submitted, so locked fields are never sent.
$noPerm = fn (string $p, string $what) => $staff && !can($user, $p) ? 'disabled title="You don’t have permission to ' . $what . '"' : '';
$lockEdit = $noPerm('tickets.status', 'change the status');
$lockPriority = $noPerm('tickets.priority', 'change the priority');
$lockCategory = $noPerm('tickets.category', 'change the category');
$lockAssign = $noPerm('tickets.assign', 'assign tickets');
$lockGroup = $noPerm('tickets.group', 'move tickets between groups');

page_title('#' . $ticket['number'] . ' ' . $ticket['subject']);
$parentTicket = !empty($ticket['parentId']) ? q_one('SELECT "id","number","subject" FROM "Ticket" WHERE "id" = ? AND "tenantId" = ?', [$ticket['parentId'], $tenantId]) : null;
$children = $staff ? ticket_children($ticket['id']) : [];
$ccList = ticket_cc($ticket['id']);
$canCc = cc_allowed($ticket, $user);
$canManage = $staff || $ticket['requesterId'] === $user['id'] || $ticket['assigneeId'] === $user['id'] || ($myWatch['watchRole'] ?? null) === 'COLLABORATOR';
// Staff are limited by their role; customers by ticket ownership.
$canLink = $perm('tickets.link');
$canTeam = $canManage && (!$staff || can($user, 'tickets.team'));
$canTag = $canManage && (!$staff || can($user, 'tickets.tags'));
$canReply = !$staff || can($user, 'tickets.reply') || $ticket['requesterId'] === $user['id'];
$notesOnly = !$canReply && $canInternal;
// Groups, organization, custom fields, satisfaction.
$groups = $staff ? tenant_groups($tenantId) : [];
$group = !empty($ticket['groupId']) ? q_one('SELECT "id","name","color" FROM "AgentGroup" WHERE "id" = ?', [$ticket['groupId']]) : null;
$requesterOrgs = user_orgs($requester['id']);
$org = !empty($requester['organizationId']) ? q_one('SELECT "id","name","notes" FROM "Organization" WHERE "id" = ?', [$requester['organizationId']]) : null;
$ticketFields = fields_for($tenantId, $ticket['categoryId'], !$staff);
$fieldValues = $ticketFields ? ticket_field_values($ticket['id']) : [];
$canEditFields = $staff && can($user, 'tickets.fields');
$csatPending = !$staff && $ticket['requesterId'] === $user['id'] ? csat_pending($ticket['id']) : null;
$csatDone = csat_latest_answer($ticket['id']);
$orgTickets = $staff && $org ? (int) q_val('SELECT COUNT(*) FROM "Ticket" t JOIN "User" u ON u."id" = t."requesterId" WHERE t."tenantId" = ? AND u."organizationId" = ?', [$tenantId, $org['id']]) : 0;

$statuses = tenant_statuses($tenantId);
$priorities = tenant_priorities($tenantId);
$curStatus = resolve_status($statuses, $ticket['status']);
$curPriority = resolve_priority($priorities, $ticket['priority']);

$tags = q_all('SELECT g."id", g."name" FROM "TicketTag" tt JOIN "Tag" g ON g."id" = tt."tagId" WHERE tt."ticketId" = ? ORDER BY g."name" ASC', [$id]);
$allTags = q_all('SELECT "id","name" FROM "Tag" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId]);
$attachments = q_all('SELECT * FROM "Attachment" WHERE "ticketId" = ? ORDER BY "createdAt" ASC', [$id]);
$comments = q_all('SELECT c.*, u."name" AS "aName", u."avatarColor" AS "aColor", u."role" AS "aRole" FROM "Comment" c JOIN "User" u ON u."id" = c."authorId" WHERE c."ticketId" = ? ORDER BY c."createdAt" ASC', [$id]);
$events = q_all('SELECT e.*, u."name" AS "aName", u."avatarColor" AS "aColor" FROM "TicketEvent" e LEFT JOIN "User" u ON u."id" = e."actorId" WHERE e."ticketId" = ? ORDER BY e."createdAt" ASC', [$id]);

$canned = $staff ? q_all('SELECT "title","body" FROM "CannedResponse" WHERE "tenantId" = ? ORDER BY "title" ASC', [$tenantId]) : [];
// Staff can mention anyone; customers only people on this ticket and colleagues of their organization.
$mentionPool = $staff ? q_all('SELECT "id","name" FROM "User" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId])
    : array_merge(q_all('SELECT u."id", u."name" FROM "User" u WHERE u."id" IN (?, ?) OR u."id" IN (SELECT w."userId" FROM "TicketWatcher" w WHERE w."ticketId" = ?)',
        [$ticket['requesterId'], $ticket['assigneeId'] ?? '', $ticket['id']]), addable_people($user, $tenantId));
$mentionUsers = array_values(array_map(fn ($u) => ['id' => $u['id'], 'label' => $u['name']], array_column($mentionPool, null, 'id')));

$byComment = [];
foreach ($attachments as $a) $byComment[$a['commentId'] ?? ''][] = $a;

// Conversation tab: replies only. History tab: every change to the ticket, newest first.
$timeline = [];
foreach ($comments as $c) if ($canInternal || !$c['isInternal']) $timeline[] = ['kind' => 'comment', 'at' => $c['createdAt'], 'item' => $c];
$history = array_reverse($events);
$historyIcons = [
    'CREATED' => ['circle-plus', 'text-emerald-600 bg-emerald-500/10'], 'STATUS_CHANGED' => ['circle-dot', 'text-sky-600 bg-sky-500/10'],
    'REOPENED' => ['rotate-ccw', 'text-amber-600 bg-amber-500/10'], 'PRIORITY_CHANGED' => ['flag', 'text-orange-600 bg-orange-500/10'],
    'ASSIGNED' => ['user-round-check', 'text-indigo-600 bg-indigo-500/10'], 'CATEGORY_CHANGED' => ['folder', 'text-violet-600 bg-violet-500/10'],
    'GROUP_CHANGED' => ['users-round', 'text-cyan-600 bg-cyan-500/10'], 'FIELDS_CHANGED' => ['list-checks', 'text-slate-600 bg-slate-500/10'],
    'MEMBER_ADDED' => ['user-plus', 'text-teal-600 bg-teal-500/10'], 'MEMBER_REMOVED' => ['user-minus', 'text-rose-600 bg-rose-500/10'],
    'MEMBER_ROLE_CHANGED' => ['users-round', 'text-teal-600 bg-teal-500/10'], 'LINKED' => ['link', 'text-blue-600 bg-blue-500/10'],
    'MERGED' => ['git-merge', 'text-fuchsia-600 bg-fuchsia-500/10'], 'MACRO' => ['zap', 'text-yellow-600 bg-yellow-500/10'],
    'CSAT' => ['smile', 'text-pink-600 bg-pink-500/10'],
];
$replyCount = count($timeline) + 1;

$agents = $canManage ? q_all('SELECT "id","name" FROM "User" WHERE "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\') ORDER BY "name" ASC', [$tenantId]) : [];
// Agents who handle the requester's organization(s) come first in the assignee picker.
$orgAgentIds = $agents && $staff ? array_flip(pluck(q_all('SELECT DISTINCT auo."userId" FROM "UserOrganization" ruo JOIN "UserOrganization" auo ON auo."organizationId" = ruo."organizationId" WHERE ruo."userId" = ?', [$ticket['requesterId']]), 'userId')) : [];
if ($orgAgentIds) usort($agents, fn ($x, $y) => [isset($orgAgentIds[$y['id']]) ? 1 : 0, $x['name']] <=> [isset($orgAgentIds[$x['id']]) ? 1 : 0, $y['name']]);
$categories = $canManage ? q_all('SELECT "id","name" FROM "Category" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId]) : [];
$excluded = array_flip([$ticket['requesterId'], ...array_column($watchers, 'id')]);
// Is there anyone left to add? (The picker itself searches on the server.)
$candidates = $canManage && search_people($user, $tenantId, '', 'all', array_keys($excluded), 1)['data'];

$replyState = flash('reply');

$attachmentList = function (array $items): string {
    if (!$items) return '';
    $h = '<div class="mt-3 flex flex-wrap gap-2">';
    foreach ($items as $a) {
        $h .= '<a href="' . e($a['url']) . '" target="_blank" rel="noopener noreferrer" class="group inline-flex items-center gap-2 rounded-lg border border-border bg-surface-2 px-2.5 py-1.5 text-sm hover:border-brand/50 hover:bg-surface transition-colors">'
            . icon(str_starts_with($a['mime'], 'image/') ? 'image' : 'file-text', 15, 'text-muted')
            . '<span class="max-w-50 truncate">' . e($a['filename']) . '</span><span class="text-xs text-muted">' . e(format_bytes((int) $a['size'])) . '</span>'
            . icon('download', 14, 'text-muted opacity-0 group-hover:opacity-100 transition-opacity') . '</a>';
    }
    return $h . '</div>';
};

$message = function (string $name, string $color, string $role, string $date, string $body, array $files, bool $internal, bool $isRequester) use ($attachmentList): string {
    $h = '<div class="flex gap-3">' . avatar($name, $color, 38);
    $h .= '<div class="' . cx('min-w-0 flex-1 rounded-xl border p-4', $internal ? 'border-amber-300 dark:border-amber-500/40 bg-amber-50/70 dark:bg-amber-500/5' : 'border-border bg-surface') . '">';
    $h .= '<div class="mb-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"><span class="font-semibold">' . e($name) . '</span>';
    if (!$isRequester) $h .= '<span class="rounded bg-brand/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-brand">' . e(role_label($role)) . '</span>';
    if ($internal) $h .= '<span class="inline-flex items-center gap-1 rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-amber-700 dark:text-amber-300">' . icon('lock', 10) . ' Internal</span>';
    $h .= '<span class="text-xs text-muted">· ' . e(time_ago($date)) . '</span></div>';
    // Body is sanitized on write; legacy plain-text bodies are shown as text.
    $isHtml = (bool) preg_match('/<[a-z][\s\S]*>/i', $body);
    $h .= '<div class="rich">' . ($isHtml ? $body : '<p>' . nl2br(e($body)) . '</p>') . '</div>';
    $h .= $attachmentList($files);
    return $h . '</div></div>';
};

$row = fn (string $label, string $valueHtml) => '<div class="flex items-center justify-between gap-3"><span class="text-muted">' . e($label) . '</span><span class="text-right font-medium">' . $valueHtml . '</span></div>';
?>
<div>
  <a href="/tickets" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"><?= icon('arrow-left', 16) ?> Back to tickets</a>
  <?php if ($parentTicket): ?>
    <a href="/tickets/<?= e($parentTicket['id']) ?>" class="mb-4 flex items-center gap-2 rounded-xl border border-brand/30 bg-brand/5 px-3 py-2 text-sm hover:bg-brand/10">
      <?= icon('list-tree', 15, 'text-brand') ?><span class="text-muted">Sub-ticket of</span> <span class="min-w-0 truncate font-medium">#<?= (int) $parentTicket['number'] ?> <?= e($parentTicket['subject']) ?></span>
    </a>
  <?php endif; ?>

  <div class="flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
      <div class="flex items-center gap-2 text-sm text-muted">
        <span class="font-mono">#<?= (int) $ticket['number'] ?></span><span>·</span><span>opened <?= e(time_ago($ticket['createdAt'])) ?></span>
      </div>
      <h1 class="mt-1 text-xl font-bold tracking-tight break-words sm:text-2xl"><?= e($ticket['subject']) ?></h1>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <?= due_badge($ticket['dueAt'], $curStatus['isClosed']) ?>
      <?= priority_badge($curPriority['label'], $curPriority['color']) ?>
      <?= status_badge($curStatus['label'], $curStatus['color']) ?>
    </div>
  </div>

  <!-- Quick actions -->
  <?php
  $resolveKey = null;
  foreach ($statuses as $st) if ($st['isClosed']) { $resolveKey = $st['key']; break; }
  $quick = 'inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg border border-border bg-surface px-3 text-sm font-medium text-fg hover:bg-surface-2 active:bg-surface-2 transition-colors cursor-pointer';
  ?>
  <?php if ($ticket['mergedIntoId'] && ($into = q_one('SELECT "id","number","subject" FROM "Ticket" WHERE "id" = ?', [$ticket['mergedIntoId']]))): ?>
    <a href="/tickets/<?= e($into['id']) ?>" class="mt-3 flex items-center gap-2 rounded-xl border border-brand/30 bg-brand/5 px-4 py-3 text-sm text-fg hover:bg-brand/10">
      <?= icon('git-merge', 16, 'text-brand') ?>
      <span class="min-w-0">This ticket was merged into <strong>#<?= (int) $into['number'] ?></strong> <span class="text-muted">— <?= e($into['subject']) ?></span>. Continue the conversation there.</span>
      <?= icon('arrow-right', 16, 'ml-auto shrink-0 text-brand') ?>
    </a>
  <?php endif; ?>
  <?php $macros = $perm('macros.use') && $canReply ? q_all('SELECT "id","name","body","setStatus","setAssignee" FROM "Macro" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId]) : []; ?>
  <div class="-mx-4 mt-3 flex gap-2 px-4 max-sm:overflow-x-auto sm:mx-0 sm:flex-wrap sm:px-0">
    <?php if ($macros):
        $items = '';
        foreach ($macros as $m) {
            $items .= '<form method="post" action="/action/macro-apply" data-ajax>' . form_meta()
                . '<input type="hidden" name="ticketId" value="' . e($ticket['id']) . '"><input type="hidden" name="macroId" value="' . e($m['id']) . '">'
                . '<button type="submit" data-dropdown-close class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface-2 cursor-pointer">' . icon('wand-sparkles', 14, 'text-brand') . '<span class="min-w-0 flex-1 truncate">' . e($m['name']) . '</span></button></form>';
        }
        echo '<div class="shrink-0">' . dropdown(
            '<button type="button" data-dropdown-trigger class="' . $quick . '">' . icon('wand-sparkles', 15) . ' Macros ' . icon('chevron-down', 14, 'text-muted') . '</button>',
            '<div class="max-h-80 overflow-y-auto py-1"><p class="px-3 pb-1 pt-1.5 text-xs font-medium text-muted">Apply to this ticket</p>' . $items . '</div>',
            260, 'left', true,
        ) . '</div>';
    endif; ?>
    <?php if ($staff && $ticket['assigneeId'] !== $user['id'] && in_array($user['id'], array_column($agents, 'id'), true)): ?>
      <form method="post" action="/action/ticket-update" data-ajax class="shrink-0">
        <?= form_meta() ?><input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>"><input type="hidden" name="assigneeId" value="<?= e($user['id']) ?>">
        <button type="submit" class="<?= $quick ?>"><?= icon('user-plus', 15) ?> Assign to me</button>
      </form>
    <?php endif; ?>
    <?php if ($canManage && $resolveKey && !$curStatus['isClosed'] && !$lockEdit): ?>
      <form method="post" action="/action/ticket-update" data-ajax class="shrink-0">
        <?= form_meta() ?><input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>"><input type="hidden" name="status" value="<?= e($resolveKey) ?>">
        <button type="submit" class="<?= $quick ?> text-emerald-700 dark:text-emerald-400"><?= icon('circle-check', 15) ?> Mark <?= e(strtolower(resolve_status($statuses, $resolveKey)['label'])) ?></button>
      </form>
    <?php endif; ?>
    <?php if ($canLink && !$ticket['mergedIntoId']): ?>
      <form method="post" action="/action/ticket-link" data-ajax data-ask="Link to which ticket? (number, e.g. #42)" data-ask-field="target" data-ask-ok="Link" class="shrink-0">
        <?= form_meta() ?><input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>"><input type="hidden" name="target" value="">
        <button type="submit" class="<?= $quick ?>"><?= icon('link-2', 15) ?> Link ticket</button>
      </form>
    <?php endif; ?>
    <?php if ($perm('tickets.merge') && !$ticket['mergedIntoId']): ?>
      <form method="post" action="/action/ticket-merge" data-ajax data-ask="Merge this ticket into… (number, e.g. #42). Its replies, files and people move there and this ticket is closed." data-ask-field="target" data-ask-ok="Merge" class="shrink-0">
        <?= form_meta() ?><input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>"><input type="hidden" name="target" value="">
        <button type="submit" class="<?= $quick ?>"><?= icon('git-merge', 15) ?> Merge into…</button>
      </form>
    <?php endif; ?>
    <button type="button" data-share data-share-title="<?= e('#' . $ticket['number'] . ' ' . $ticket['subject']) ?>" class="<?= $quick ?>"><?= icon('link', 15) ?> <span data-share-label>Share link</span></button>
    <a href="#reply" data-jump-reply class="<?= $quick ?> lg:hidden"><?= icon('send', 15) ?> Reply</a>
  </div>

  <div class="mt-5 grid gap-5 lg:mt-6 lg:grid-cols-[1fr_300px] lg:gap-6">
    <!-- Conversation / History tabs -->
    <div class="min-w-0 space-y-4" data-tabs>
      <div class="flex gap-1 rounded-xl border border-border bg-surface-2/50 p-1" role="tablist">
        <?php foreach (['conversation' => ['message-square', 'Conversation', $replyCount], 'history' => ['history', 'History', count($history)]] as $tk => [$tic, $tl, $tn]): ?>
          <button type="button" role="tab" data-tab="<?= $tk ?>" aria-selected="<?= $tk === 'conversation' ? 'true' : 'false' ?>"
                  class="flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-muted transition-colors hover:text-fg cursor-pointer aria-selected:bg-surface aria-selected:text-fg aria-selected:shadow-sm sm:flex-none sm:px-4">
            <?= icon($tic, 15) ?><?= $tl ?><span class="rounded-full bg-surface-2 px-1.5 text-[11px] tabular-nums text-muted"><?= $tn ?></span>
          </button>
        <?php endforeach; ?>
      </div>

      <!-- History -->
      <div data-tab-panel="history" hidden class="rounded-xl border border-border bg-surface p-4 sm:p-5">
        <?php if (!$history): ?>
          <p class="py-6 text-center text-sm text-muted">No changes yet.</p>
        <?php else: ?>
          <ol class="relative space-y-4 before:absolute before:bottom-2 before:left-[15px] before:top-2 before:w-px before:bg-border">
            <?php foreach ($history as $ev): [$hic, $htone] = $historyIcons[$ev['type']] ?? ['clock', 'text-muted bg-surface-2']; ?>
              <li class="relative flex gap-3">
                <span class="<?= cx('relative z-10 grid h-8 w-8 shrink-0 place-items-center rounded-full ring-4 ring-surface', $htone) ?>"><?= icon($hic, 15) ?></span>
                <div class="min-w-0 flex-1 pt-1">
                  <p class="text-sm"><span class="font-medium"><?= e($ev['aName'] ?? 'Automation') ?></span> <?= e($ev['type'] === 'CREATED' && $ev['summary'] === '' ? 'created the ticket' : $ev['summary']) ?></p>
                  <p class="mt-0.5 text-xs text-muted"><?= e(format_date($ev['createdAt'])) ?> · <?= e(time_ago($ev['createdAt'])) ?></p>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>

      <!-- Conversation -->
      <div data-tab-panel="conversation" class="space-y-4">
      <?= $message($requester['name'], $requester['avatarColor'], $requester['role'], $ticket['createdAt'], $ticket['description'], $byComment[''] ?? [], false, true) ?>

      <?php foreach ($timeline as $it):
          $c = $it['item'];
          echo $message($c['aName'], $c['aColor'], $c['aRole'], $c['createdAt'], $c['body'], $byComment[$c['id']] ?? [], (bool) $c['isInternal'], $c['authorId'] === $ticket['requesterId']);
      endforeach; ?>

      <?php if ($csatPending || ($csatDone && !$staff && $ticket['requesterId'] === $user['id'])): $cur = (int) ($csatDone['rating'] ?? 0); ?>
        <div class="rounded-xl border border-brand/30 bg-brand/5 p-4">
          <p class="text-sm font-medium"><?= $csatPending ? e(csat_settings($tenantId)['question']) : 'Thanks for rating this ticket — you can change your answer.' ?></p>
          <form method="post" action="/action/csat-answer" data-ajax class="mt-3 flex flex-wrap items-center gap-2">
            <?= form_meta() ?><input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>">
            <?php foreach (CSAT_FACES as $n => $face): ?>
              <button type="submit" name="rating" value="<?= $n ?>" title="<?= e(CSAT_LABELS[$n]) ?>" class="<?= cx('grid h-11 w-11 place-items-center rounded-xl border text-2xl transition hover:scale-110 cursor-pointer', $cur === $n ? 'border-brand bg-brand/10' : 'border-border bg-surface') ?>"><?= $face ?></button>
            <?php endforeach; ?>
          </form>
        </div>
      <?php endif; ?>

      <div class="scroll-mt-20 pt-2" id="reply">
        <!-- Collision detection: who else is viewing / replying (polled by app.js) -->
        <div data-presence data-ticket="<?= e($ticket['id']) ?>" data-since="<?= e(now_iso()) ?>" hidden class="mb-2 flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2 text-sm"></div>
        <?php if (!$canReply && !$canInternal): ?>
          <p class="flex items-center gap-2 rounded-xl border border-dashed border-border p-4 text-sm text-muted"><?= icon('lock', 15) ?> Your role can view this ticket but not reply to it.</p>
        <?php else: ?>
        <form method="post" action="/action/comment-add" enctype="multipart/form-data" data-reply data-pending="Sending…" <?= $notesOnly ? 'data-internal="true"' : '' ?>
              class="rounded-xl border p-4 transition-colors border-border bg-surface data-[internal=true]:border-amber-300 dark:data-[internal=true]:border-amber-500/40 data-[internal=true]:bg-amber-50/60 dark:data-[internal=true]:bg-amber-500/5">
          <?= form_meta('reply') ?>
          <input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>">
          <?= inline_error($replyState, 'mb-2') ?>
          <div class="space-y-3">
            <?= rich_editor('body', [
                'minHeight' => 90,
                'placeholder' => 'Write a reply… use @ to mention',
                'mentions' => $mentionUsers,
                'canned' => $canned,
            ]) ?>
            <?= file_input('files') ?>
          </div>
          <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <?php if ($notesOnly): ?>
              <input type="hidden" name="isInternal" value="on">
              <span class="inline-flex items-center gap-1.5 text-sm text-amber-700 dark:text-amber-400"><?= icon('lock', 14) ?> Your role can only add internal notes</span>
            <?php elseif ($canInternal): ?>
              <label class="flex items-center gap-2 text-sm text-muted cursor-pointer select-none">
                <input type="checkbox" name="isInternal" data-internal-toggle class="h-4 w-4 rounded border-border accent-amber-500">
                Internal note
              </label>
            <?php else: ?><span></span><?php endif; ?>
            <button type="submit" data-reply-submit class="<?= btn_cls('primary', 'md', 'max-sm:flex-1') ?>">
              <span data-when="public" <?= $notesOnly ? 'hidden' : '' ?> class="inline-flex items-center gap-2"><?= icon('send', 16) ?> Send reply</span>
              <span data-when="internal" <?= $notesOnly ? '' : 'hidden' ?> class="inline-flex items-center gap-2"><?= icon('lock', 16) ?> Add internal note</span>
            </button>
          </div>
        </form>
        <?php endif; ?>
      </div>
      </div><!-- /conversation -->
    </div>

    <!-- Sidebar (phones: cards flow into the main grid; Properties goes first) -->
    <div class="space-y-6 max-lg:contents">
      <div class="rounded-xl border border-border bg-surface p-4 max-lg:order-first sm:p-5">
        <?php if ($canManage): ?>
          <form method="post" action="/action/ticket-update" data-ajax data-autosave class="grid grid-cols-2 gap-x-3 gap-y-3 lg:block lg:space-y-4">
            <?= form_meta() ?>
            <input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>">
            <div class="col-span-2 flex items-center justify-between">
              <h3 class="text-sm font-semibold">Properties</h3>
              <span data-spinner hidden><?= icon('loader-circle', 15, 'animate-spin text-muted') ?></span>
            </div>
            <div>
              <label for="s-status" class="<?= LABEL_CLS ?>">Status</label>
              <select id="s-status" name="status" <?= $lockEdit ?> class="<?= SELECT_CLS ?>"><?= select_options(array_column($statuses, 'label', 'key'), $ticket['status']) ?></select>
            </div>
            <div>
              <label for="s-priority" class="<?= LABEL_CLS ?>">Priority</label>
              <select id="s-priority" name="priority" <?= $lockPriority ?> class="<?= SELECT_CLS ?>"><?= select_options(array_column($priorities, 'label', 'key'), $ticket['priority']) ?></select>
            </div>
            <div class="col-span-2 lg:col-span-1">
              <p class="<?= LABEL_CLS ?>">Assignees</p>
              <?php $mayAssignOthers = can($user, 'tickets.assign'); ?>
              <?php if (!$staff): ?>
                <p class="flex min-h-10 items-center rounded-lg border border-border bg-surface-2/40 px-3 text-sm <?= $assigneeRows ? '' : 'text-muted' ?>"><?= $assigneeRows ? e(human_list(array_column($assigneeRows, 'name'))) : 'Unassigned' ?></p>
              <?php else: ?>
              <details class="group relative" data-multi-pick>
                <summary class="flex min-h-10 w-full cursor-pointer list-none items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm transition hover:border-brand/50 group-open:border-brand group-open:ring-2 group-open:ring-brand/40 [&::-webkit-details-marker]:hidden">
                  <?php if ($assigneeRows): ?>
                    <span class="flex shrink-0 -space-x-1.5"><?php foreach (array_slice($assigneeRows, 0, 3) as $ar) echo avatar($ar['name'], $ar['avatarColor'], 22, 'ring-2 ring-surface'); ?></span>
                    <span class="min-w-0 flex-1 truncate"><?= e(human_list(array_column($assigneeRows, 'name'))) ?></span>
                  <?php else: ?>
                    <span class="flex-1 text-muted">Unassigned</span>
                  <?php endif; ?>
                  <?= icon('chevron-down', 16, 'shrink-0 text-muted transition-transform group-open:rotate-180') ?>
                </summary>
                <div class="absolute left-0 right-0 z-30 mt-1 max-h-72 overflow-y-auto rounded-xl border border-border bg-surface p-1 shadow-xl">
                  <input type="hidden" name="assigneeIdsSent" value="1">
                  <?php foreach ($agents as $ag): $on = in_array($ag['id'], $assigneeIds, true); $isLead = ($assigneeIds[0] ?? null) === $ag['id'];
                      $lockThis = !$mayAssignOthers && $ag['id'] !== $user['id']; ?>
                    <label class="<?= cx('flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm', $lockThis ? 'opacity-60' : 'cursor-pointer hover:bg-surface-2') ?>">
                      <input type="checkbox" name="assigneeIds[]" value="<?= e($ag['id']) ?>" <?= $on ? 'checked' : '' ?> <?= $lockThis ? 'disabled' : '' ?> class="h-4 w-4 rounded border-border accent-brand">
                      <?php if ($lockThis && $on): ?><input type="hidden" name="assigneeIds[]" value="<?= e($ag['id']) ?>"><?php endif; ?>
                      <span class="min-w-0 flex-1 truncate"><?= e($ag['name']) ?><?= $ag['id'] === $user['id'] ? ' <span class="text-muted">(you)</span>' : '' ?></span>
                      <?php if (isset($orgAgentIds[$ag['id']])): ?><span class="shrink-0 rounded-full bg-sky-500/10 px-1.5 text-[10px] font-semibold text-sky-700 dark:text-sky-300" title="Handles this customer’s organization">Org</span><?php endif; ?>
                      <?php if ($isLead && count($assigneeIds) > 1): ?><span class="rounded-full bg-brand/10 px-1.5 text-[10px] font-semibold uppercase text-brand" title="Lead: counted in reports, SLA and satisfaction">Lead</span><?php endif; ?>
                    </label>
                  <?php endforeach; ?>
                  <?php if (!$mayAssignOthers): ?><p class="px-2.5 pb-1.5 pt-1 text-[11px] text-muted">You can add or remove yourself.</p><?php endif; ?>
                </div>
              </details>
              <?php endif; ?>
            </div>
            <div>
              <label for="s-category" class="<?= LABEL_CLS ?>">Category</label>
              <select id="s-category" name="categoryId" <?= $lockCategory ?> class="<?= SELECT_CLS ?>"><?= select_options(['' => '— None —'] + array_column($categories, 'name', 'id'), $ticket['categoryId'] ?? '') ?></select>
            </div>
            <?php if ($staff && $groups): ?>
              <div>
                <label for="s-group" class="<?= LABEL_CLS ?>">Group</label>
                <select id="s-group" name="groupId" <?= $lockGroup ?> class="<?= SELECT_CLS ?>"><?= select_options(['' => '— No group —'] + array_column($groups, 'name', 'id'), $ticket['groupId'] ?? '') ?></select>
              </div>
            <?php endif; ?>
          </form>
        <?php else: ?>
          <div class="space-y-3 text-sm">
            <h3 class="text-sm font-semibold">Details</h3>
            <?= $row('Status', status_badge($curStatus['label'], $curStatus['color'])) ?>
            <?= $row('Priority', priority_badge($curPriority['label'], $curPriority['color'])) ?>
            <?= $row('Category', $category ? e($category['name']) : '—') ?>
            <?= $row('Assigned to', $assigneeRows ? e(human_list(array_column($assigneeRows, 'name'))) : 'Unassigned') ?>
            <?php if ($group): ?><?= $row('Group', e($group['name'])) ?><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($ticketFields): ?>
      <!-- Custom fields -->
      <div class="rounded-xl border border-border bg-surface p-4 sm:p-5">
        <?php if ($canEditFields): ?>
          <form method="post" action="/action/ticket-fields" data-ajax data-autosave class="space-y-3">
            <?= form_meta() ?><input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>">
            <div class="flex items-center justify-between"><h3 class="text-sm font-semibold">Details</h3><span data-spinner hidden><?= icon('loader-circle', 15, 'animate-spin text-muted') ?></span></div>
            <?php foreach ($ticketFields as $f): ?>
              <div>
                <?php if ($f['type'] !== 'checkbox'): ?><label for="cf-<?= e($f['key']) ?>" class="<?= LABEL_CLS ?>"><?= e($f['label']) ?><?php if (!(int) $f['customerVisible']): ?> <span title="Only staff see this"><?= icon('lock', 11, 'inline text-muted') ?></span><?php endif; ?></label><?php endif; ?>
                <?= field_input(['required' => 0] + $f, $fieldValues[$f['key']] ?? '') ?>
              </div>
            <?php endforeach; ?>
          </form>
        <?php else: ?>
          <div class="space-y-3 text-sm">
            <h3 class="text-sm font-semibold">Details</h3>
            <?php foreach ($ticketFields as $f): ?><?= $row($f['label'], e(field_display($f, $fieldValues[$f['key']] ?? null))) ?><?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($csatDone && $staff): $r = (int) $csatDone['rating']; ?>
      <!-- Satisfaction -->
      <div class="rounded-xl border border-border bg-surface p-4 sm:p-5">
        <h3 class="mb-2 text-sm font-semibold">Customer satisfaction</h3>
        <p class="flex items-center gap-2"><span class="text-2xl"><?= CSAT_FACES[$r] ?></span><span class="text-sm font-medium <?= $r >= 4 ? 'text-emerald-700 dark:text-emerald-400' : ($r <= 2 ? 'text-red-700 dark:text-red-400' : '') ?>"><?= $r ?>/5 · <?= e(CSAT_LABELS[$r]) ?></span></p>
        <?php if ($csatDone['comment']): ?><p class="mt-2 rounded-lg bg-surface-2/60 p-2.5 text-sm">“<?= e($csatDone['comment']) ?>”</p><?php endif; ?>
        <p class="mt-1.5 text-xs text-muted"><?= e(time_ago($csatDone['answeredAt'])) ?></p>
      </div>
      <?php elseif ($staff):
          // Why there is (or isn't) a satisfaction survey on this ticket.
          $csatOn = csat_settings($tenantId)['enabled'];
          $openSurvey = csat_pending($ticket['id']);
          $isClosedNow = (bool) $curStatus['isClosed'];
          $reqIsCustomer = ($requester['role'] ?? '') === 'CUSTOMER';
          $why = !$csatOn ? 'Satisfaction surveys are turned off (Settings → Ticket fields & CSAT).'
              : (!$reqIsCustomer ? 'Surveys go to customers only — this ticket was opened by staff.'
              : ($openSurvey ? null
              : (!$isClosedNow ? 'Sent automatically when staff move the ticket to a closed status (e.g. Resolved).'
              : 'Not sent — the ticket was closed before surveys were on, by the customer, or by an automation.')));
          $canSend = $csatOn && $reqIsCustomer && $isClosedNow && $canManage; ?>
      <!-- Satisfaction survey status -->
      <div class="rounded-xl border border-border bg-surface p-4 sm:p-5">
        <h3 class="mb-2 flex items-center gap-1.5 text-sm font-semibold"><?= icon('smile', 15, 'text-muted') ?> Customer satisfaction</h3>
        <?php if ($openSurvey): ?>
          <p class="flex items-center gap-2 text-sm"><span class="h-2 w-2 shrink-0 rounded-full bg-amber-500"></span> Survey sent <?= e(time_ago($openSurvey['createdAt'])) ?> — waiting for <?= e($requester['name']) ?></p>
          <p class="mt-1 text-xs text-muted">They see it on this ticket<?= (email_settings($tenantId)['enabled'] ?? false) ? ' and got it by email' : ' (email isn’t set up, so not by email)' ?>.</p>
        <?php else: ?>
          <p class="text-xs text-muted"><?= e($why) ?></p>
        <?php endif; ?>
        <?php if ($canSend): ?>
          <form method="post" action="/action/csat-send" data-ajax class="mt-3">
            <?= form_meta() ?><input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>">
            <button type="submit" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('send', 13) ?> <?= $openSurvey ? 'Send again' : 'Send survey now' ?></button>
          </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($staff && empty($ticket['parentId']) && ($children || $canManage)):
          $doneKids = count(array_filter($children, fn ($c) => resolve_status($statuses, $c['status'])['isClosed'])); ?>
      <!-- Sub-tickets -->
      <div class="rounded-xl border border-border bg-surface p-5">
        <div class="mb-3 flex items-center justify-between gap-2">
          <h3 class="flex items-center gap-1.5 text-sm font-semibold"><?= icon('list-tree', 15, 'text-muted') ?> Sub-tickets</h3>
          <?php if ($children): ?><span class="text-xs tabular-nums text-muted"><?= $doneKids ?>/<?= count($children) ?> done</span><?php endif; ?>
        </div>
        <?php if ($children): ?>
          <div class="mb-3 h-1.5 overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full bg-emerald-500 transition-all" style="width:<?= round($doneKids / count($children) * 100) ?>%"></div></div>
          <ul class="space-y-1.5">
            <?php foreach ($children as $ch): $chs = resolve_status($statuses, $ch['status']); ?>
              <li><a href="/tickets/<?= e($ch['id']) ?>" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-surface-2">
                <?= $chs['isClosed'] ? icon('circle-check', 15, 'shrink-0 text-emerald-600') : icon('circle-dot', 15, 'shrink-0 text-muted') ?>
                <span class="min-w-0 flex-1 truncate <?= $chs['isClosed'] ? 'text-muted line-through' : '' ?>"><span class="text-muted">#<?= (int) $ch['number'] ?></span> <?= e($ch['subject']) ?></span>
                <?php if ($ch['aName']): ?><?= avatar($ch['aName'], $ch['aColor'], 20) ?><?php endif; ?>
              </a></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="text-xs text-muted">Split the work into smaller tickets with their own assignee and status.</p>
        <?php endif; ?>
        <?php if ($canManage): ?>
          <form method="post" action="/action/subticket-create" data-ajax class="mt-3 space-y-2 border-t border-border pt-3">
            <?= form_meta() ?><input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>">
            <input name="subject" required minlength="3" maxlength="140" placeholder="New sub-ticket…" class="<?= INPUT_CLS ?>">
            <div class="flex gap-2">
              <select name="assigneeId" class="<?= SELECT_CLS ?> min-w-0 flex-1"><?= select_options(['' => 'Unassigned'] + (can($user, 'tickets.assign') ? array_column($agents, 'name', 'id') : [$user['id'] => $user['name']]), '') ?></select>
              <button type="submit" class="<?= btn_cls('secondary') ?>"><?= icon('plus', 15) ?> Add</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($canCc): ?>
      <!-- CC by email -->
      <div class="rounded-xl border border-border bg-surface p-5">
        <h3 class="mb-1 flex items-center gap-1.5 text-sm font-semibold"><?= icon('at-sign', 15, 'text-muted') ?> CC by email</h3>
        <p class="mb-3 text-xs text-muted">They get public replies and status changes by email<?= email_settings($tenantId)['enabled'] ?? false ? '' : ' (once email is set up)' ?>.</p>
        <?php if ($ccList): ?>
          <ul class="mb-3 space-y-1">
            <?php foreach ($ccList as $em): ?>
              <li class="flex items-center gap-2 rounded-lg bg-surface-2 px-2.5 py-1.5 text-sm">
                <span class="min-w-0 flex-1 truncate"><?= e($em) ?></span>
                <form method="post" action="/action/cc-remove" data-ajax><?= form_meta() ?><input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>"><input type="hidden" name="email" value="<?= e($em) ?>">
                  <button type="submit" aria-label="Remove <?= e($em) ?>" class="text-muted hover:text-red-600 cursor-pointer"><?= icon('x', 14) ?></button></form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <form method="post" action="/action/cc-add" data-ajax class="flex gap-2">
          <?= form_meta() ?><input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>">
          <input name="emails" type="text" required placeholder="name@company.com" class="<?= INPUT_CLS ?> min-w-0 flex-1">
          <button type="submit" class="<?= btn_cls('secondary') ?>"><?= icon('plus', 15) ?></button>
        </form>
      </div>
      <?php elseif ($ccList && ticket_form_parts($tenantId)['cc']): ?>
      <div class="rounded-xl border border-border bg-surface p-5 text-sm">
        <h3 class="mb-2 text-sm font-semibold">CC by email</h3>
        <p class="text-muted"><?= e(implode(', ', $ccList)) ?></p>
      </div>
      <?php endif; ?>

      <!-- Team -->
      <div class="rounded-xl border border-border bg-surface p-5">
        <div class="mb-3 flex items-center justify-between">
          <h3 class="text-sm font-semibold">Team</h3>
          <?php if ($canTeam && $candidates): ?>
            <div data-watch-picker="<?= e($ticket['id']) ?>"><?= people_picker(['label' => 'Add', 'ticketId' => $ticket['id'], 'staff' => $staff]) ?></div>
          <?php endif; ?>
        </div>
        <?php if ($canTeam && !$staff && !$candidates): ?>
          <p class="mb-3 text-xs text-muted"><?= !user_org_ids($user['id']) ? 'You can add colleagues once your account belongs to an organization.' : 'Everyone from your organization is already on this ticket.' ?></p>
        <?php endif; ?>
        <?php if (!$watchers): ?>
          <p class="text-sm text-muted">No team members yet.</p>
        <?php else: ?>
          <ul class="space-y-2.5">
            <?php foreach ($watchers as $w): ?>
              <li class="flex items-center gap-2.5">
                <?= avatar($w['name'], $w['avatarColor'], 30) ?>
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium leading-tight"><?= e($w['name']) ?></p>
                  <p class="text-xs text-muted"><?= e(role_label($w['role'])) ?></p>
                </div>
                <?php if ($canTeam): ?>
                  <form method="post" action="/action/watcher-role" data-ajax data-autosave>
                    <?= form_meta() ?>
                    <input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>"><input type="hidden" name="userId" value="<?= e($w['id']) ?>">
                    <select name="role" title="<?= e(WATCHER_ROLE_HINTS[$w['watchRole']] ?? '') ?>" class="<?= SMALL_SELECT ?>"><?= select_options(WATCHER_ROLE_LABELS, $w['watchRole']) ?></select>
                  </form>
                  <form method="post" action="/action/watcher-remove" data-ajax>
                    <?= form_meta() ?>
                    <input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>"><input type="hidden" name="userId" value="<?= e($w['id']) ?>">
                    <button type="submit" aria-label="Remove <?= e($w['name']) ?>" class="text-muted hover:text-red-600 cursor-pointer disabled:opacity-50"><?= icon('x', 15) ?></button>
                  </form>
                <?php else: ?>
                  <span class="rounded bg-surface-2 px-2 py-0.5 text-xs text-muted"><?= e(WATCHER_ROLE_LABELS[$w['watchRole']] ?? $w['watchRole']) ?></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <!-- Requester -->
      <div class="rounded-xl border border-border bg-surface">
        <?= card_header('Requester') ?>
        <div class="flex items-center gap-3 p-5">
          <?= avatar($requester['name'], $requester['avatarColor'], 42) ?>
          <div class="min-w-0">
            <p class="truncate font-medium"><?= e($requester['name']) ?></p>
            <p class="truncate text-xs text-muted"><?= e($requester['email']) ?></p>
          </div>
        </div>
        <?php if ($org): ?>
          <div class="flex items-start gap-2.5 border-t border-border px-5 py-3 text-sm">
            <?= icon('building-2', 15, 'mt-0.5 shrink-0 text-muted') ?>
            <div class="min-w-0">
              <?php if ($staff): ?><a href="/tickets?org=<?= e($org['id']) ?>" class="font-medium hover:text-brand"><?= e($org['name']) ?></a><span class="text-xs text-muted"> · <?= $orgTickets ?> ticket<?= $orgTickets === 1 ? '' : 's' ?></span>
              <?php else: ?><span class="font-medium"><?= e($org['name']) ?></span><?php endif; ?>
              <?php if ($staff && $org['notes']): ?><p class="mt-1 whitespace-pre-line text-xs text-muted"><?= e($org['notes']) ?></p><?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
        <div class="space-y-2 border-t border-border px-5 py-4 text-sm">
          <?= $row('Created', e(format_date($ticket['createdAt']))) ?>
          <?= $row('Updated', e(time_ago($ticket['updatedAt']))) ?>
          <?php if ($staff && ($ticket['source'] ?? 'web') !== 'web'): ?><?= $row('Received via', e((['api' => 'API', 'form' => 'Support form'] + TICKET_CHANNELS)[$ticket['source']] ?? $ticket['source'])) ?><?php endif; ?>
          <?php if ($ticket['closedAt']) echo $row('Closed', e(time_ago($ticket['closedAt']))); ?>
        </div>
      </div>

      <!-- Linked tickets -->
      <?php $links = ticket_links($ticket['id'], $statuses); ?>
      <?php if ($staff && ($links || $canLink)): ?>
      <div class="rounded-xl border border-border bg-surface p-5">
        <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold"><?= icon('link-2', 15) ?> Linked tickets</h3>
        <?php if (!$links): ?><p class="text-sm text-muted">None. Use “Link ticket” to connect related requests.</p><?php endif; ?>
        <ul class="space-y-2">
          <?php foreach ($links as $l): ?>
            <li class="flex items-center gap-2">
              <a href="/tickets/<?= e($l['id']) ?>" class="flex min-w-0 flex-1 items-center gap-2 rounded-lg px-1 py-1 hover:bg-surface-2">
                <span class="h-2 w-2 shrink-0 rounded-full" style="background-color:<?= safe_color($l['statusDef']['color']) ?>" title="<?= e($l['statusDef']['label']) ?>"></span>
                <span class="font-mono text-xs text-muted">#<?= (int) $l['number'] ?></span>
                <span class="truncate text-sm"><?= e($l['subject']) ?></span>
              </a>
              <?php if ($canLink): ?>
                <form method="post" action="/action/ticket-unlink" data-ajax>
                  <?= form_meta() ?><input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>"><input type="hidden" name="otherId" value="<?= e($l['id']) ?>">
                  <button type="submit" aria-label="Unlink #<?= (int) $l['number'] ?>" title="Unlink" class="text-muted hover:text-red-600 cursor-pointer"><?= icon('unlink', 14) ?></button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <!-- Tags -->
      <div class="rounded-xl border border-border bg-surface p-5">
        <div class="mb-3 flex items-center justify-between">
          <h3 class="text-sm font-semibold">Tags</h3>
          <?php if ($canTag):
              $assigned = array_flip(array_column($tags, 'id'));
              $available = array_values(array_filter($allTags, fn ($t) => !isset($assigned[$t['id']])));
              $menu = '<div class="py-1"><form method="post" action="/action/ticket-tag-add" data-ajax class="p-2">' . form_meta()
                  . '<input type="hidden" name="ticketId" value="' . e($ticket['id']) . '">'
                  . '<input name="name" placeholder="New tag + Enter" autocomplete="off" class="h-8 w-full rounded-md border border-border bg-surface px-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40"></form>'
                  . '<div class="max-h-56 overflow-y-auto border-t border-border">';
              if (!$available) $menu .= '<p class="px-3 py-3 text-center text-xs text-muted">No more tags</p>';
              foreach ($available as $t) {
                  $menu .= '<form method="post" action="/action/ticket-tag-add" data-ajax>' . form_meta()
                      . '<input type="hidden" name="ticketId" value="' . e($ticket['id']) . '"><input type="hidden" name="tagId" value="' . e($t['id']) . '">'
                      . '<button type="submit" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-surface-2 transition-colors cursor-pointer">' . icon('hash', 13, 'text-muted') . e($t['name']) . '</button></form>';
              }
              $menu .= '</div></div>';
              echo dropdown('<button type="button" data-dropdown-trigger class="inline-flex items-center gap-1 text-xs font-medium text-brand hover:underline cursor-pointer disabled:opacity-50">' . icon('plus', 14) . ' Add</button>', $menu, 240);
          endif; ?>
        </div>
        <?php if (!$tags): ?>
          <p class="text-sm text-muted">No tags.</p>
        <?php else: ?>
          <div class="flex flex-wrap gap-1.5">
            <?php foreach ($tags as $t): ?>
              <span class="inline-flex items-center gap-1 rounded-full bg-surface-2 px-2.5 py-0.5 text-xs text-muted">
                #<?= e($t['name']) ?>
                <?php if ($canTag): ?>
                  <form method="post" action="/action/ticket-tag-remove" data-ajax class="inline-flex">
                    <?= form_meta() ?>
                    <input type="hidden" name="ticketId" value="<?= e($ticket['id']) ?>"><input type="hidden" name="tagId" value="<?= e($t['id']) ?>">
                    <button type="submit" aria-label="Remove <?= e($t['name']) ?>" class="text-muted hover:text-red-600 cursor-pointer"><?= icon('x', 12) ?></button>
                  </form>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
