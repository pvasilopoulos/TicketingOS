<?php
declare(strict_types=1);

/* Backups, exports and GDPR erasure (Settings → Backups & data). */

function data_ctx(string $perm): array
{
    [$user, $tenantId] = require_tenant_user();
    if (!can($user, $perm)) abort(403, 'Forbidden');
    if (!class_exists('ZipArchive')) abort(500, 'ZIP support is not installed on this server');
    return [$user, $tenantId];
}

function send_file_download(string $path, string $name, string $type = 'application/zip', bool $delete = true): never
{
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $name) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    if ($delete) @unlink($path);
    throw new HttpExit();
}

/** GET /settings/backup.zip?files=1 — a full backup right now. */
function backup_download_now(): never
{
    [$user, $tenantId] = data_ctx('data.export');
    @set_time_limit(300);
    $files = ($_GET['files'] ?? '') === '1';
    $tmp = tempnam(sys_get_temp_dir(), 'bk');
    $r = backup_build($tenantId, $tmp, $files);
    audit($tenantId, $user, 'backup-download', 'Downloaded a full backup (' . array_sum($r['tables']) . ' rows' . ($files ? ', ' . $r['files'] . ' files' : '') . ')', 'tenant', $tenantId);
    $slug = (string) q_val('SELECT "slug" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
    send_file_download($tmp, "backup-$slug-" . date('Y-m-d-His') . '.zip');
}

/** GET /settings/backups/<name> — one of the automatic daily backups. */
function backup_file_download(string $name): never
{
    [$user, $tenantId] = data_ctx('data.export');
    if (!preg_match('/^backup-\d{4}-\d{2}-\d{2}\.zip$/', $name)) abort(404);
    $path = backup_dir($tenantId) . '/' . $name;
    if (!is_file($path)) abort(404);
    audit($tenantId, $user, 'backup-download', "Downloaded the automatic backup $name", 'tenant', $tenantId);
    send_file_download($path, $name, 'application/zip', false);
}

/** GET /settings/export/org.zip?id=…&files=1 */
function org_export_download(): never
{
    [$user, $tenantId] = data_ctx('data.export');
    $org = q_one('SELECT * FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [(string) ($_GET['id'] ?? ''), $tenantId]);
    if (!$org) abort(404);
    @set_time_limit(300);
    $tmp = tempnam(sys_get_temp_dir(), 'ox');
    org_export_build($tenantId, $org['id'], $tmp, ($_GET['files'] ?? '') === '1');
    audit($tenantId, $user, 'org-export', "Exported the data of the organization “{$org['name']}”", 'org', $org['id']);
    send_file_download($tmp, 'organization-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($org['name'])) . '-' . date('Y-m-d') . '.zip');
}

function person_by_email(string $tenantId, string $email): ?array
{
    return q_one('SELECT * FROM "User" WHERE "tenantId" = ? AND LOWER("email") = ?', [$tenantId, strtolower(trim($email))]);
}

/** GET /settings/export/person.json?email=… — a person's data (GDPR access request). */
function person_export_download(): never
{
    [$user, $tenantId] = data_ctx('data.export');
    $p = person_by_email($tenantId, (string) ($_GET['email'] ?? ''));
    if (!$p) abort(404, 'Nobody with that email in this workspace');
    audit($tenantId, $user, 'person-export', "Exported the personal data of {$p['email']}", 'user', $p['id']);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="personal-data-' . date('Y-m-d') . '.json"');
    header('Cache-Control: private, no-store');
    echo json_encode(person_export($tenantId, $p), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    throw new HttpExit();
}

function act_person_erase(array $in): ?array
{
    if (!($ctx = ctx_with('data.erase'))) return NOT_ALLOWED;
    [$user, $tenantId] = $ctx;
    $p = person_by_email($tenantId, str_in($in, 'email'));
    if (!$p) return ['error' => 'Nobody with that email in this workspace'];
    if ($p['id'] === $user['id']) return ['error' => 'You can’t erase your own account'];
    if (in_array($p['role'], ['ADMIN', 'SUPER_ADMIN'], true) && !can($user, 'admin.admins')) return ['error' => 'Only people who manage administrators can erase an administrator'];
    if (strtolower(trim(str_in($in, 'confirm'))) !== strtolower($p['email'])) return ['error' => 'Type the person’s email again to confirm'];
    $r = person_erase($tenantId, $p, ($in['content'] ?? '') === 'on');
    audit($tenantId, $user, 'person-erase', "Erased the personal data of a person (account {$p['id']})" . ($r['messages'] || $r['files'] ? " — {$r['messages']} messages, {$r['files']} files removed" : ''), 'user', $p['id']);
    return ['success' => 'The person was anonymized' . ($r['messages'] || $r['files'] ? " ({$r['messages']} messages and {$r['files']} files removed)" : '')];
}

function act_backup_settings_save(array $in): ?array
{
    if (!($ctx = ctx_with('data.export'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    tenant_setting_set($tenantId, 'backup', ['daily' => ($in['daily'] ?? '') === 'on', 'keep' => max(1, min(60, (int) ($in['keep'] ?? 7))), 'files' => ($in['files'] ?? '') === 'on']);
    return ['success' => ($in['daily'] ?? '') === 'on' ? 'Daily backups are on — the first one is made by the next background run' : 'Daily backups are off'];
}
