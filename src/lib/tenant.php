<?php
declare(strict_types=1);

/* Per-tenant statuses, priorities and SLA targets. */

function tenant_statuses(string $tenantId): array
{
    static $cache = [];
    if (isset($cache[$tenantId])) return $cache[$tenantId];
    $rows = q_all('SELECT "id","key","label","color","isClosed","order" FROM "TicketStatus" WHERE "tenantId" = ? ORDER BY "order" ASC', [$tenantId]);
    foreach ($rows as &$r) { $r['isClosed'] = (bool) $r['isClosed']; $r['order'] = (int) $r['order']; }
    return $cache[$tenantId] = $rows;
}

function tenant_statuses_flush(): void
{
    // Static caches live for one request; nothing persistent to flush.
}

function resolve_status(array $statuses, string $key): array
{
    foreach ($statuses as $s) if ($s['key'] === $key) return $s;
    return ['id' => $key, 'key' => $key, 'label' => $key, 'color' => '#94a3b8', 'isClosed' => false, 'order' => 999];
}

function initial_status_key(array $statuses): string
{
    return $statuses[0]['key'] ?? 'OPEN';
}

function closed_status_keys(array $statuses): array
{
    return array_values(array_map(fn ($s) => $s['key'], array_filter($statuses, fn ($s) => $s['isClosed'])));
}

function tenant_priorities(string $tenantId): array
{
    $rows = q_all('SELECT "id","key","label","color","order" FROM "TicketPriority" WHERE "tenantId" = ? ORDER BY "order" ASC', [$tenantId]);
    foreach ($rows as &$r) $r['order'] = (int) $r['order'];
    return $rows;
}

function resolve_priority(array $priorities, string $key): array
{
    foreach ($priorities as $p) if ($p['key'] === $key) return $p;
    return ['id' => $key, 'key' => $key, 'label' => $key, 'color' => '#94a3b8', 'order' => 0];
}

/** Default priority for new tickets: a mid-severity one, or the first. */
function default_priority_key(array $priorities): string
{
    if (!$priorities) return 'MEDIUM';
    return $priorities[intdiv(count($priorities) - 1, 2)]['key'];
}

/** Keys of the highest-severity priority (for "urgent" stats). */
function top_priority_keys(array $priorities): array
{
    if (!$priorities) return [];
    $max = max(array_map(fn ($p) => $p['order'], $priorities));
    return array_values(array_map(fn ($p) => $p['key'], array_filter($priorities, fn ($p) => $p['order'] === $max)));
}

function priority_exists(array $priorities, string $key): bool
{
    foreach ($priorities as $p) if ($p['key'] === $key) return true;
    return false;
}

/** A tenant's SLA targets as priority → hours (falls back to defaults). */
function tenant_sla(string $tenantId): array
{
    $map = DEFAULT_SLA;
    foreach (q_all('SELECT "priority","resolveHours" FROM "SlaTarget" WHERE "tenantId" = ?', [$tenantId]) as $r) {
        $map[$r['priority']] = (int) $r['resolveHours'];
    }
    return $map;
}

/** Deadline for a ticket given its creation time + priority. */
function compute_due_at(string $createdAtIso, string $priority, array $sla): ?string
{
    $hours = $sla[$priority] ?? 0;
    if ($hours <= 0) return null;
    return iso_from_ts(ts($createdAtIso) + $hours * 3600);
}

/** Seed the default workflow for a freshly created tenant. */
function seed_tenant_defaults(string $tenantId, bool $withCategories = true): void
{
    $now = now_iso();
    tenant_setting_set($tenantId, 'permissionsVersion', 2); // roles start on the current permission catalog
    if ($withCategories) {
        foreach ([['Billing', '#f59e0b'], ['Technical', '#3b82f6'], ['Account', '#8b5cf6'], ['General', '#64748b']] as [$n, $c]) {
            db_insert('Category', ['id' => cuid(), 'name' => $n, 'color' => $c, 'tenantId' => $tenantId]);
        }
    }
    foreach (DEFAULT_STATUSES as $i => $s) {
        db_insert('TicketStatus', [
            'id' => cuid(), 'tenantId' => $tenantId, 'key' => $s['key'], 'label' => $s['label'],
            'color' => $s['color'], 'isClosed' => $s['isClosed'] ? 1 : 0, 'order' => $i, 'createdAt' => $now,
        ]);
    }
    foreach (DEFAULT_PRIORITIES as $p) {
        db_insert('TicketPriority', [
            'id' => cuid(), 'tenantId' => $tenantId, 'key' => $p['key'], 'label' => $p['label'],
            'color' => $p['color'], 'order' => $p['order'],
        ]);
    }
    foreach (DEFAULT_SLA as $prio => $hours) {
        db_insert('SlaTarget', ['id' => cuid(), 'tenantId' => $tenantId, 'priority' => $prio, 'resolveHours' => $hours]);
    }
}
