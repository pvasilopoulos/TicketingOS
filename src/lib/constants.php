<?php
declare(strict_types=1);

// Central config for roles, statuses, priorities & notification categories.

const ROLES = ['SUPER_ADMIN', 'ADMIN', 'AGENT', 'CUSTOMER'];
const TENANT_ROLES = ['ADMIN', 'AGENT', 'CUSTOMER'];

const ROLE_LABELS = [
    'SUPER_ADMIN' => 'Platform Admin',
    'ADMIN' => 'Administrator',
    'AGENT' => 'Agent',
    'CUSTOMER' => 'Customer',
];

// Per-ticket team membership roles.
const WATCHER_ROLES = ['VIEWER', 'COLLABORATOR'];
const WATCHER_ROLE_LABELS = ['VIEWER' => 'Viewer', 'COLLABORATOR' => 'Collaborator'];
const WATCHER_ROLE_HINTS = [
    'VIEWER' => 'Can view & is notified',
    'COLLABORATOR' => 'Can view, manage & reply',
];

// Defaults seeded for every new tenant.
const DEFAULT_STATUSES = [
    ['key' => 'OPEN', 'label' => 'Open', 'color' => '#3b82f6', 'isClosed' => false],
    ['key' => 'PENDING', 'label' => 'Pending', 'color' => '#f59e0b', 'isClosed' => false],
    ['key' => 'RESOLVED', 'label' => 'Resolved', 'color' => '#10b981', 'isClosed' => true],
    ['key' => 'CLOSED', 'label' => 'Closed', 'color' => '#94a3b8', 'isClosed' => true],
];

const DEFAULT_PRIORITIES = [
    ['key' => 'LOW', 'label' => 'Low', 'color' => '#94a3b8', 'order' => 0],
    ['key' => 'MEDIUM', 'label' => 'Medium', 'color' => '#0ea5e9', 'order' => 1],
    ['key' => 'HIGH', 'label' => 'High', 'color' => '#f97316', 'order' => 2],
    ['key' => 'URGENT', 'label' => 'Urgent', 'color' => '#ef4444', 'order' => 3],
];

// Default resolution targets (hours) per priority.
const DEFAULT_SLA = ['URGENT' => 4, 'HIGH' => 24, 'MEDIUM' => 72, 'LOW' => 168];

const AVATAR_COLORS = ['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ef4444', '#14b8a6'];

const NOTIFY_CATEGORIES = ['new_ticket', 'reply', 'mention', 'assigned', 'status', 'team'];
const CATEGORY_LABELS = [
    'new_ticket' => 'New ticket created',
    'reply' => 'New reply',
    'mention' => "You're @mentioned",
    'assigned' => 'Assigned to you',
    'status' => 'Status changed',
    'team' => 'Added to a ticket',
];
const CHANNELS = ['inApp', 'push', 'email'];

/** Slugify a label into a stable uppercase key (statuses / priorities). */
function key_from_label(string $label, string $fallback): string
{
    $k = strtoupper(trim($label));
    $k = preg_replace('/[^A-Z0-9]+/', '_', $k);
    $k = trim($k, '_');
    $k = substr($k, 0, 40);
    return $k !== '' ? $k : $fallback;
}

function is_watcher_role(string $v): bool
{
    return in_array($v, WATCHER_ROLES, true);
}

function role_label(string $role): string
{
    return ROLE_LABELS[$role] ?? $role;
}
