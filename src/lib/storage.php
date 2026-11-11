<?php
declare(strict_types=1);

const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
const MAX_FILES = 5;
const MAX_IMAGE_SIZE = 5 * 1024 * 1024; // 5 MB

const IMAGE_MIME = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
const ALLOWED_MIME = [
    'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml',
    'application/pdf', 'text/plain', 'text/csv', 'application/zip',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

/** Private upload root (outside the web root). */
function storage_root(): string
{
    return ROOT . '/storage/uploads';
}

function attachment_disk_path(string $tenantId, string $id): string
{
    return storage_root() . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantId) . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Normalize $_FILES['x'] (single or multiple) into a list of file arrays. */
function uploaded_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f) return [];
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $name) {
        $out[] = [
            'name' => $name,
            'type' => $f['type'][$i],
            'tmp_name' => $f['tmp_name'][$i],
            'error' => $f['error'][$i],
            'size' => $f['size'][$i],
        ];
    }
    return $out;
}

function file_ok(array $f): bool
{
    return ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($f['size'] ?? 0) > 0 && is_uploaded_file($f['tmp_name']);
}

/**
 * Persist uploaded files and create Attachment rows.
 * Invalid files (wrong type / too large / empty) are skipped.
 */
function save_uploads(array $files, string $tenantId, string $uploaderId, string $ticketId, ?string $commentId = null): array
{
    $valid = array_values(array_filter($files, 'file_ok'));
    $valid = array_slice($valid, 0, MAX_FILES);
    $valid = array_filter($valid, fn ($f) => $f['size'] <= MAX_FILE_SIZE && in_array($f['type'], ALLOWED_MIME, true));

    $created = [];
    foreach ($valid as $f) {
        $id = uuid4();
        $path = attachment_disk_path($tenantId, $id);
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        if (!move_uploaded_file($f['tmp_name'], $path)) continue;
        $row = [
            'id' => $id,
            'filename' => mb_substr((string) $f['name'], 0, 200),
            'url' => "/api/attachments/$id",
            'size' => (int) $f['size'],
            'mime' => $f['type'],
            'createdAt' => now_iso(),
            'tenantId' => $tenantId,
            'ticketId' => $ticketId,
            'commentId' => $commentId,
            'uploaderId' => $uploaderId,
        ];
        db_insert('Attachment', $row);
        $created[] = $row;
    }
    return $created;
}

/** Persist one inline editor image (raster only). Returns its URL or null. */
function save_inline_image(array $f, string $tenantId, string $uploaderId): ?string
{
    if (!file_ok($f)) return null;
    if (!in_array($f['type'], IMAGE_MIME, true) || $f['size'] > MAX_IMAGE_SIZE) return null;
    // Double-check it really is an image.
    if (function_exists('getimagesize') && @getimagesize($f['tmp_name']) === false) return null;

    $id = uuid4();
    $path = attachment_disk_path($tenantId, $id);
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    if (!move_uploaded_file($f['tmp_name'], $path)) return null;

    db_insert('Attachment', [
        'id' => $id,
        'filename' => mb_substr((string) $f['name'], 0, 200) ?: 'image',
        'url' => "/api/attachments/$id",
        'size' => (int) $f['size'],
        'mime' => $f['type'],
        'createdAt' => now_iso(),
        'tenantId' => $tenantId,
        'ticketId' => null,
        'commentId' => null,
        'uploaderId' => $uploaderId,
    ]);
    return "/api/attachments/$id";
}
