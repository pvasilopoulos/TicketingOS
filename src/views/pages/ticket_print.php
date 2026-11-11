<?php
/**
 * Printable ticket (browser "Save as PDF"). Standalone page — no app chrome.
 * Staff can include internal notes with ?internal=1.
 * @var array $m route match
 */
[$user, $tenantId] = require_tenant_user();
$ticket = find_ticket($m[1], $tenantId);
if (!$ticket || !ticket_visible($ticket, $user)) abort(404);
$staff = is_staff($user['role']);
$seesInternal = $staff && can($user, 'tickets.internal');
$withInternal = $seesInternal && ($_GET['internal'] ?? '') === '1';
$requester = q_one('SELECT "name","email" FROM "User" WHERE "id" = ?', [$ticket['requesterId']]);
$statuses = tenant_statuses($tenantId);
$status = resolve_status($statuses, $ticket['status']);
$priority = resolve_priority(tenant_priorities($tenantId), $ticket['priority']);
$assignees = ticket_assignees($ticket);
$comments = q_all('SELECT c.*, u."name" AS "aName", u."role" AS "aRole" FROM "Comment" c JOIN "User" u ON u."id" = c."authorId" WHERE c."ticketId" = ? ORDER BY c."createdAt" ASC', [$ticket['id']]);
$files = visible_attachments($ticket, $user, $comments);
$byComment = [];
foreach ($files as $f) $byComment[$f['commentId'] ?? ''][] = $f;
$workspace = (string) q_val('SELECT "name" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
$body = fn (string $b) => preg_match('/<[a-z][\s\S]*>/i', $b) ? $b : '<p>' . nl2br(e($b)) . '</p>';
$fileList = function (array $items): string {
    if (!$items) return '';
    return '<p class="files">📎 ' . implode(' · ', array_map(fn ($f) => e($f['filename']) . ' <span class="muted">(' . e(format_bytes((int) $f['size'])) . ')</span>', $items)) . '</p>';
};
audit($tenantId, $user, 'ticket-print', 'Printed ticket #' . (int) $ticket['number'], 'ticket', $ticket['id']);
header('Cache-Control: private, no-store');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e('#' . $ticket['number'] . ' ' . $ticket['subject']) ?></title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 14px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #0f172a; background: #f1f5f9; }
  .page { max-width: 820px; margin: 24px auto; background: #fff; padding: 40px 48px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  .bar { max-width: 820px; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; align-items: center; font-size: 13px; color: #475569; }
  .bar button, .bar a { font: inherit; border: 1px solid #cbd5e1; background: #fff; border-radius: 8px; padding: 7px 14px; cursor: pointer; color: inherit; text-decoration: none; }
  .bar button.primary { background: #4f46e5; color: #fff; border-color: #4f46e5; }
  header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #0f172a; padding-bottom: 16px; margin-bottom: 20px; }
  .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 15px; }
  .brand img { height: 28px; width: auto; }
  h1 { font-size: 22px; margin: 6px 0 0; line-height: 1.3; }
  .num { font-family: ui-monospace, Consolas, monospace; color: #64748b; font-size: 13px; }
  dl { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 20px; margin: 0 0 24px; font-size: 13px; }
  dt { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
  dd { margin: 2px 0 0; font-weight: 600; }
  .msg { border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin: 0 0 12px; break-inside: avoid-page; }
  .msg.internal { border-color: #fcd34d; background: #fffbeb; }
  .msg .head { display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: baseline; font-size: 13px; margin-bottom: 6px; }
  .msg .who { font-weight: 700; }
  .muted { color: #64748b; font-weight: 400; }
  .tag { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; border-radius: 4px; padding: 1px 6px; background: #eef2ff; color: #4338ca; }
  .tag.amber { background: #fef3c7; color: #92400e; }
  .body p { margin: 0 0 8px; } .body p:last-child { margin-bottom: 0; }
  .body img { max-width: 100%; height: auto; }
  .body blockquote { margin: 8px 0; padding-left: 12px; border-left: 3px solid #cbd5e1; color: #475569; }
  .body pre { white-space: pre-wrap; background: #f8fafc; padding: 8px; border-radius: 6px; font-size: 12px; }
  .body table { border-collapse: collapse; } .body td, .body th { border: 1px solid #cbd5e1; padding: 4px 8px; }
  .files { font-size: 12px; margin: 8px 0 0; }
  .deleted { font-style: italic; color: #94a3b8; }
  footer { margin-top: 28px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8; display: flex; justify-content: space-between; }
  @media print {
    body { background: #fff; }
    .bar { display: none; }
    .page { margin: 0; padding: 0; box-shadow: none; max-width: none; border-radius: 0; }
    a { color: inherit; }
    @page { margin: 16mm 14mm; }
  }
  @media (max-width: 640px) { .page { padding: 24px 18px; margin: 12px; } dl { grid-template-columns: repeat(2, 1fr); } .bar { padding: 0 12px; } }
</style>
</head>
<body>
<div class="bar">
  <?php if ($seesInternal): ?>
    <a href="?internal=<?= $withInternal ? '0' : '1' ?>"><?= $withInternal ? 'Hide internal notes' : 'Include internal notes' ?></a>
  <?php endif; ?>
  <button type="button" class="primary" onclick="window.print()">Print / Save as PDF</button>
</div>
<div class="page">
  <header>
    <div>
      <div class="num">#<?= (int) $ticket['number'] ?> · <?= e($workspace) ?></div>
      <h1><?= e($ticket['subject']) ?></h1>
    </div>
    <div class="brand"><img src="<?= e(brand_logo_url() ?? app_icon('/app-icons/logo.svg')) ?>" alt=""><span><?= e(app_name()) ?></span></div>
  </header>
  <dl>
    <div><dt>Status</dt><dd><?= e($status['label']) ?></dd></div>
    <div><dt>Priority</dt><dd><?= e($priority['label']) ?></dd></div>
    <div><dt>Requester</dt><dd><?= e($requester['name'] ?? '') ?><?= $staff ? '<br><span class="muted">' . e($requester['email'] ?? '') . '</span>' : '' ?></dd></div>
    <div><dt>Assigned to</dt><dd><?= $assignees ? e(human_list(array_column($assignees, 'name'))) : '—' ?></dd></div>
    <div><dt>Opened</dt><dd><?= e(format_date($ticket['createdAt'])) ?></dd></div>
    <div><dt><?= $ticket['closedAt'] ? 'Closed' : 'Last update' ?></dt><dd><?= e(format_date($ticket['closedAt'] ?: $ticket['updatedAt'])) ?></dd></div>
  </dl>

  <div class="msg">
    <div class="head"><span class="who"><?= e($requester['name'] ?? '') ?></span><span class="muted"><?= e(format_date($ticket['createdAt'])) ?></span></div>
    <div class="body"><?= $body((string) $ticket['description']) ?></div>
    <?= $fileList($byComment[''] ?? []) ?>
  </div>
  <?php foreach ($comments as $c):
      if ($c['isInternal'] && !$withInternal) continue; ?>
    <div class="msg<?= $c['isInternal'] ? ' internal' : '' ?>">
      <div class="head">
        <span class="who"><?= e($c['aName']) ?></span>
        <?php if ($c['authorId'] !== $ticket['requesterId']): ?><span class="tag"><?= e(role_label($c['aRole'])) ?></span><?php endif; ?>
        <?php if ($c['isInternal']): ?><span class="tag amber">Internal note</span><?php endif; ?>
        <span class="muted"><?= e(format_date($c['createdAt'])) ?><?= $c['editedAt'] && !$c['deletedAt'] ? ' · edited' : '' ?></span>
      </div>
      <?php if ($c['deletedAt']): ?>
        <p class="deleted">This message was deleted.</p>
      <?php else: ?>
        <div class="body"><?= $body((string) $c['body']) ?></div>
        <?= $fileList($byComment[$c['id']] ?? []) ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <footer><span><?= e(app_url() . '/tickets/' . $ticket['id']) ?></span><span>Printed <?= e(format_date(now_iso())) ?> by <?= e($user['name']) ?></span></footer>
</div>
<script>if (location.hash === "#print") window.addEventListener("load", function () { window.print(); });</script>
</body>
</html>
