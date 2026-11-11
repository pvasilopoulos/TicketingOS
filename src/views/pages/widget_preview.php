<?php
/**
 * Preview of the website widget on a sample page (Settings → Integrations → Preview widget).
 * Standalone page — no app chrome — so the widget looks exactly as it does on a customer's site.
 */
[$user, $tenantId] = require_tenant_user();
if (!is_staff($user['role']) || !can($user, 'settings.webform')) abort(404);
$tenant = q_one('SELECT "slug","name" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
$wf = webform_settings($tenantId);
$chatOn = chat_settings($tenantId)['enabled'];
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Widget preview · <?= e($tenant['name']) ?></title>
<style>
  body { margin: 0; font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1e293b; background: #f8fafc; }
  .bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; padding: 10px 16px; background: #0f172a; color: #e2e8f0; font-size: 13px; }
  .bar a { color: #a5b4fc; }
  .bar .warn { color: #fcd34d; }
  .site { max-width: 760px; margin: 0 auto; padding: 48px 16px 120px; }
  .hero { padding: 40px 28px; border-radius: 16px; background: #fff; box-shadow: 0 1px 3px rgba(15, 23, 42, .08); }
  .hero h1 { margin: 0 0 8px; font-size: 28px; line-height: 1.2; }
  .line { height: 12px; margin: 14px 0; border-radius: 6px; background: #e2e8f0; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 24px; }
  .card { height: 120px; border-radius: 12px; background: #fff; box-shadow: 0 1px 3px rgba(15, 23, 42, .08); }
  .hint { margin-top: 28px; color: #64748b; }
</style>
</head>
<body>
<div class="bar">
  <strong>Widget preview</strong>
  <span>This is how the widget looks on your website.</span>
  <?php if (!$wf['enabled'] && !$chatOn): ?><span class="warn">The support form and live chat are both off, so the widget has nothing to show. Turn one on in Settings → Integrations.</span>
  <?php elseif (!$chatOn): ?><span class="warn">Live chat is off, so the widget opens the support form.</span><?php endif; ?>
  <a href="/settings#integrations">← Back to settings</a>
</div>
<div class="site">
  <div class="hero">
    <h1>Your website</h1>
    <p>Sample page. Click the <strong>Help</strong> button at the bottom right to open the widget.</p>
    <div class="line" style="width:90%"></div><div class="line" style="width:75%"></div><div class="line" style="width:82%"></div>
  </div>
  <div class="grid"><div class="card"></div><div class="card"></div><div class="card"></div></div>
  <p class="hint">Messages you send here are real: they reach the Live chat page or become tickets. Keep the Live chat page open in another tab to answer yourself as an agent.</p>
</div>
<script src="/widget.js" data-workspace="<?= e($tenant['slug']) ?>" data-color="<?= e($wf['color']) ?>" data-label="Help" async></script>
</body>
</html>
<?php throw new HttpExit();
