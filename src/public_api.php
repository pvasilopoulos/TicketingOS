<?php
declare(strict_types=1);

/* Public / auxiliary endpoints: widget script, support-form KB suggestions, audit CSV, report preview. */

/** Embeddable widget: <script src="https://…/widget.js" data-workspace="acme" async></script> */
function public_widget_js(): never
{
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    $base = json_encode(app_url());
    echo <<<JS
(function () {
  var s = document.currentScript || document.querySelector('script[data-workspace]');
  if (!s || window.__tosWidget) return;
  window.__tosWidget = true;
  var slug = s.getAttribute('data-workspace'), color = s.getAttribute('data-color') || '#4f46e5', label = s.getAttribute('data-label') || 'Help';
  var base = {$base};
  var btn = document.createElement('button');
  btn.type = 'button';
  btn.setAttribute('aria-label', label);
  btn.textContent = label;
  btn.style.cssText = 'position:fixed;right:20px;bottom:20px;z-index:2147483000;border:0;border-radius:999px;padding:12px 18px;font:600 14px system-ui,sans-serif;color:#fff;background:' + color + ';box-shadow:0 8px 24px rgba(0,0,0,.2);cursor:pointer';
  var frame = null;
  btn.addEventListener('click', function () {
    if (frame) { var open = frame.style.display !== 'none'; frame.style.display = open ? 'none' : 'block'; btn.textContent = open ? label : '✕'; return; }
    frame = document.createElement('iframe');
    frame.src = base + '/support/' + encodeURIComponent(slug) + '?embed=1';
    frame.title = label;
    frame.style.cssText = 'position:fixed;right:20px;bottom:76px;z-index:2147483000;width:min(400px,calc(100vw - 40px));height:min(620px,calc(100vh - 110px));border:0;border-radius:16px;box-shadow:0 16px 48px rgba(0,0,0,.25);background:#fff';
    document.body.appendChild(frame);
    btn.textContent = '✕';
  });
  (document.body ? Promise.resolve() : new Promise(function (r) { document.addEventListener('DOMContentLoaded', r); })).then(function () { document.body.appendChild(btn); });
})();
JS;
    throw new HttpExit();
}

/** Published help-center articles matching what the visitor typed (support form). */
function public_kb_suggest(array $tenant): never
{
    $q = search_norm(mb_substr((string) ($_GET['q'] ?? ''), 0, 120));
    $words = array_values(array_filter(explode(' ', $q), fn ($w) => mb_strlen($w) >= 3));
    if (!$words) json_out(['items' => []]);
    $where = ['"tenantId" = ?', '"published" = 1'];
    $params = [$tenant['id']];
    $or = [];
    foreach (array_slice($words, 0, 6) as $w) { $or[] = "\"searchText\" LIKE ? ESCAPE '!'"; $params[] = like_arg($w); }
    $rows = q_all('SELECT "id","title","body" FROM "KbArticle" WHERE ' . implode(' AND ', $where) . ' AND (' . implode(' OR ', $or) . ') ORDER BY "helpful" DESC, "views" DESC LIMIT 4', $params);
    json_out(['items' => array_map(fn ($r) => ['title' => $r['title'], 'excerpt' => mb_substr(html_to_text($r['body']), 0, 220)], $rows)]);
}

/** Audit log as CSV (administrators). */
function export_audit_csv(): never
{
    [$user, $tenantId] = require_tenant_user();
    if (!can($user, 'audit.view')) abort(403, 'Forbidden');
    [$where, $params] = audit_filter_sql($tenantId, $_GET);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Time', 'Who', 'Action', 'Summary', 'IP', 'Details'], ',', '"', '\\');
    $stmt = q('SELECT * FROM "AuditLog" WHERE ' . $where . ' ORDER BY "createdAt" DESC LIMIT 100000', $params);
    while ($r = $stmt->fetch()) {
        fputcsv($out, [local_dt($r['createdAt'])?->format('Y-m-d H:i:s'), $r['actorName'] ?? 'System', $r['action'], $r['summary'], $r['ip'], $r['meta'] ?? ''], ',', '"', '\\');
    }
    fclose($out);
    throw new HttpExit();
}

/** WHERE for the audit log from filters: aq (text), acat (category), actor (user id), adays. */
function audit_filter_sql(string $tenantId, array $f): array
{
    $where = '"tenantId" = ?';
    $params = [$tenantId];
    $cat = (string) ($f['acat'] ?? '');
    if ($cat === 'signin') { $where .= ' AND "action" LIKE ?'; $params[] = 'sign-in%'; }
    elseif (isset(AUDIT_CATEGORIES[$cat])) {
        $acts = array_keys(array_filter(AUDITED_ACTIONS, fn ($a) => $a[0] === $cat));
        if ($cat === 'tickets') $acts[] = 'ticket-export';
        $where .= ' AND "action" IN (' . in_ph($acts) . ')';
        array_push($params, ...$acts);
    }
    if (!empty($f['actor'])) { $where .= ' AND "actorId" = ?'; $params[] = (string) $f['actor']; }
    if (($q = trim((string) ($f['aq'] ?? ''))) !== '') { $where .= " AND (LOWER(\"summary\") LIKE ? ESCAPE '!' OR LOWER(\"actorName\") LIKE ? ESCAPE '!')"; array_push($params, like_arg(mb_strtolower($q)), like_arg(mb_strtolower($q))); }
    if (in_array((int) ($f['adays'] ?? 0), [1, 7, 30, 90], true)) { $where .= ' AND "createdAt" >= ?'; $params[] = now_iso(-(int) $f['adays'] * 86400); }
    return [$where, $params];
}

/** What a scheduled report email looks like (also used when email is not set up). */
function report_email_preview(): never
{
    [$user, $tenantId] = require_tenant_user();
    if (!can($user, 'reports.view')) abort(403, 'Forbidden');
    $s = q_one('SELECT * FROM "ScheduledReport" WHERE "id" = ? AND "tenantId" = ?', [(string) ($_GET['id'] ?? ''), $tenantId])
        ?? ['name' => 'Weekly helpdesk report', 'days' => 7];
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($s['name']) . '</title><body style="margin:0;padding:24px;background:#f8fafc">'
        . report_email_html($tenantId, $s) . '</body>';
    throw new HttpExit();
}

/* ---------- Development mail viewer (/dev/mail) ---------- */

/** Parse a saved .eml from bin/mail-catcher.php into its headers and decoded parts. */
function dev_mail_parse(string $raw): array
{
    [$head, $body] = array_pad(preg_split("/\r?\n\r?\n/", $raw, 2), 2, '');
    $h = [];
    foreach (preg_split("/\r?\n(?![ \t])/", $head) as $line) {
        if (!str_contains($line, ':')) continue;
        [$k, $v] = explode(':', $line, 2);
        $h[strtolower(trim($k))] = iconv_mime_decode(trim($v), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: trim($v);
    }
    $html = $text = '';
    if (preg_match('/boundary="?([^";]+)"?/i', $h['content-type'] ?? '', $m)) {
        foreach (explode('--' . $m[1], $body) as $part) {
            [$ph, $pb] = array_pad(preg_split("/\r?\n\r?\n/", ltrim($part), 2), 2, '');
            $dec = stripos($ph, 'base64') !== false ? base64_decode($pb) : (stripos($ph, 'quoted-printable') !== false ? quoted_printable_decode($pb) : $pb);
            if (stripos($ph, 'text/html') !== false) $html = $dec;
            elseif (stripos($ph, 'text/plain') !== false) $text = $dec;
        }
    } else {
        $text = $body;
    }
    return ['h' => $h, 'html' => $html, 'text' => $text];
}

function dev_mail_view(): never
{
    if (env('APP_DEBUG', '0') !== '1') abort(404);
    $user = current_user();
    if (!$user) redirect('/login');
    if (!is_tenant_admin($user['role'])) abort(403, 'Forbidden');
    $dir = ROOT . '/storage/mail';
    $files = is_dir($dir) ? array_reverse(glob($dir . '/*.eml')) : [];
    $files = array_slice($files, 0, 200);
    $sel = basename((string) ($_GET['m'] ?? ''));
    if ($sel === '' && $files) $sel = basename($files[0]);
    $cur = $sel !== '' && is_file("$dir/$sel") ? dev_mail_parse((string) file_get_contents("$dir/$sel")) : null;

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dev mailbox</title>'
        . '<style>body{margin:0;font:14px system-ui,sans-serif;color:#0f172a;background:#f8fafc;display:flex;height:100vh}'
        . 'nav{width:340px;max-width:45%;overflow:auto;border-right:1px solid #e2e8f0;background:#fff}'
        . 'nav header{padding:14px 16px;border-bottom:1px solid #e2e8f0}nav header b{display:block;font-size:15px}nav header small{color:#64748b}'
        . 'nav a{display:block;padding:10px 16px;border-bottom:1px solid #f1f5f9;color:inherit;text-decoration:none}nav a.on{background:#eef2ff}'
        . 'nav a span{display:block;color:#64748b;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
        . 'main{flex:1;display:flex;flex-direction:column;min-width:0}.meta{padding:14px 18px;background:#fff;border-bottom:1px solid #e2e8f0}'
        . '.meta h1{font-size:17px;margin:0 0 6px}.meta div{color:#475569;font-size:13px}iframe{flex:1;border:0;background:#fff}'
        . 'pre{margin:0;padding:18px;white-space:pre-wrap}.empty{padding:40px;color:#64748b}'
        . '@media(max-width:700px){body{flex-direction:column;height:auto}nav{width:auto;max-width:none;max-height:40vh}iframe{min-height:70vh}}</style>'
        . '<nav><header><b>Dev mailbox</b><small>' . count($files) . ' message(s) caught by bin/mail-catcher.php · newest first</small></header>';
    foreach ($files as $f) {
        $b = basename($f);
        $m = dev_mail_parse((string) file_get_contents($f, false, null, 0, 4096));
        echo '<a href="?m=' . e(rawurlencode($b)) . '"' . ($b === $sel ? ' class="on"' : '') . '>' . e($m['h']['subject'] ?? '(no subject)')
            . '<span>To ' . e($m['h']['x-envelope-to'] ?? '') . ' · ' . e(date('j M H:i', filemtime($f))) . '</span></a>';
    }
    echo '</nav><main>';
    if (!$cur) {
        echo '<div class="empty">No emails yet. Close a ticket as staff or press “Send now” on a scheduled report.</div>';
    } else {
        echo '<div class="meta"><h1>' . e($cur['h']['subject'] ?? '') . '</h1><div>From ' . e($cur['h']['from'] ?? '') . ' → ' . e($cur['h']['x-envelope-to'] ?? '')
            . ' · ' . e($cur['h']['date'] ?? '') . '</div></div>';
        echo $cur['html'] !== ''
            ? '<iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="' . e('<base target="_blank">' . $cur['html']) . '"></iframe>'
            : '<pre>' . e($cur['text']) . '</pre>';
    }
    echo '</main>';
    throw new HttpExit();
}
