<?php
declare(strict_types=1);

/*
 * TicketingOS (PHP edition) — bootstrap.
 * Loads configuration from .env, then every library module.
 */

define('ROOT', dirname(__DIR__));
define('SRC', ROOT . '/src');
define('VIEWS', SRC . '/views');

require SRC . '/lib/env.php';
load_env(ROOT . '/.env');

date_default_timezone_set(env('APP_TIMEZONE', date_default_timezone_get()) ?: 'UTC');

if (env('APP_DEBUG', '0') === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

foreach ([
    'constants', 'version', 'db', 'helpers', 'http', 'session', 'auth', 'crypto', 'sanitize',
    'storage', 'tenant', 'email', 'smtp', 'push', 'notify', 'search', 'queries', 'nav', 'permissions', 'view', 'icons', 'branding',
    'audit', 'groups', 'fields', 'csat', 'webhooks', 'reporting', 'ticketplus', 'notifyrules', 'ticketextras', 'billing', 'dataexport', 'chat', 'aiassist', 'messaging',
] as $mod) {
    require SRC . "/lib/{$mod}.php";
}

foreach (glob(SRC . '/actions/*.php') as $file) {
    require $file;
}

ensure_runtime_schema();
