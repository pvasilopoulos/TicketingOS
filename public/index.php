<?php
declare(strict_types=1);

/*
 * TicketingOS (PHP edition) — front controller.
 * Works with Apache (.htaccess), nginx (try_files → index.php) and the
 * built-in server:  php -S localhost:8000 -t public public/index.php
 */

// Built-in server: let it serve real static files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) return false;
}

require dirname(__DIR__) . '/src/bootstrap.php';
require SRC . '/api.php';
require SRC . '/api_v1.php';
require SRC . '/public_api.php';

$ACTIONS = [
    // auth
    'login' => 'act_login', 'register' => 'act_register', 'logout' => 'act_logout',
    'forgot' => 'act_forgot', 'reset' => 'act_reset',
    // tickets
    'ticket-create' => 'act_ticket_create', 'ticket-update' => 'act_ticket_update', 'ticket-bulk' => 'act_ticket_bulk',
    'comment-add' => 'act_comment_add', 'comment-edit' => 'act_comment_edit', 'comment-delete' => 'act_comment_delete', 'comment-pin' => 'act_comment_pin',
    'attachment-delete' => 'act_attachment_delete', 'signature-save' => 'act_signature_save',
    'kb-from-ticket' => 'act_kb_from_ticket', 'person-erase' => 'act_person_erase', 'chat-settings-save' => 'act_chat_settings_save', 'ai-assist-save' => 'act_ai_assist_save', 'sms-settings-save' => 'act_sms_settings_save', 'sms-test' => 'act_sms_test', 'channel-settings-save' => 'act_channel_settings_save', 'viber-connect' => 'act_viber_connect', 'my-phone-save' => 'act_my_phone_save', 'backup-settings-save' => 'act_backup_settings_save', 'time-add' => 'act_time_add', 'org-rate-save' => 'act_org_rate_save', 'billing-settings-save' => 'act_billing_settings_save', 'time-delete' => 'act_time_delete', 'contract-save' => 'act_contract_save', 'contract-delete' => 'act_contract_delete', 'view-share' => 'act_view_share',
    'attachment-settings-save' => 'act_attachment_settings_save', 'ai-settings-save' => 'act_ai_settings_save', 'ai-test' => 'act_ai_test',
    'watcher-add' => 'act_watcher_add', 'watcher-role' => 'act_watcher_role', 'watcher-remove' => 'act_watcher_remove',
    'ticket-tag-add' => 'act_ticket_tag_add', 'ticket-tag-remove' => 'act_ticket_tag_remove',
    // user
    'notif-read' => 'act_notif_read', 'notif-read-all' => 'act_notif_read_all',
    'prefs-save' => 'act_prefs_save', 'device-remove' => 'act_device_remove',
    'view-create' => 'act_view_create', 'view-delete' => 'act_view_delete',
    'platform-enter' => 'act_platform_enter', 'platform-exit' => 'act_platform_exit',
    // admin
    'workspace-rename' => 'act_workspace_rename', 'category-create' => 'act_category_create', 'category-delete' => 'act_category_delete',
    'status-create' => 'act_status_create', 'status-update' => 'act_status_update', 'status-delete' => 'act_status_delete', 'status-move' => 'act_status_move',
    'priority-create' => 'act_priority_create', 'priority-update' => 'act_priority_update', 'priority-delete' => 'act_priority_delete', 'priority-move' => 'act_priority_move',
    'sla-save' => 'act_sla_save',
    'tag-create' => 'act_tag_create', 'tag-rename' => 'act_tag_rename', 'tag-delete' => 'act_tag_delete',
    'canned-create' => 'act_canned_create', 'canned-update' => 'act_canned_update', 'canned-delete' => 'act_canned_delete',
    'rule-create' => 'act_rule_create', 'rule-toggle' => 'act_rule_toggle', 'rule-delete' => 'act_rule_delete',
    'policy-save' => 'act_policy_save', 'email-save' => 'act_email_save', 'email-test' => 'act_email_test',
    'nav-save' => 'act_nav_save',
    'macro-create' => 'act_macro_create', 'macro-delete' => 'act_macro_delete', 'macro-apply' => 'act_macro_apply',
    'ticket-merge' => 'act_ticket_merge', 'ticket-link' => 'act_ticket_link', 'ticket-unlink' => 'act_ticket_unlink',
    'kb-save' => 'act_kb_save', 'kb-delete' => 'act_kb_delete', 'kb-helpful' => 'act_kb_helpful',
    'member-create' => 'act_member_create', 'member-update' => 'act_member_update', 'member-status' => 'act_member_status', 'member-password' => 'act_member_password',
    'group-save' => 'act_group_save', 'group-delete' => 'act_group_delete', 'group-routing' => 'act_group_routing',
    'org-save' => 'act_org_save', 'org-delete' => 'act_org_delete', 'org-member' => 'act_org_member',
    'field-save' => 'act_field_save', 'field-delete' => 'act_field_delete', 'field-move' => 'act_field_move', 'ticket-fields' => 'act_ticket_fields',
    'csat-settings' => 'act_csat_settings', 'csat-answer' => 'act_csat_answer',
    'report-schedule-save' => 'act_report_schedule_save', 'report-schedule-delete' => 'act_report_schedule_delete', 'report-schedule-send' => 'act_report_schedule_send',
    'apikey-create' => 'act_apikey_create', 'apikey-revoke' => 'act_apikey_revoke',
    'webhook-save' => 'act_webhook_save', 'webhook-delete' => 'act_webhook_delete', 'webhook-test' => 'act_webhook_test', 'webform-save' => 'act_webform_save',
    'branding-save' => 'act_branding_save', 'template-save' => 'act_template_save', 'ticket-form-save' => 'act_ticket_form_save', 'csat-send' => 'act_csat_send', 'notify-matrix-save' => 'act_notify_matrix_save', 'notify-rules-save' => 'act_notify_rules_save',
    'notify-templates-save' => 'act_notify_templates_save', 'notify-retry' => 'act_notify_retry', 'notify-test' => 'act_notify_test', 'notify-run' => 'act_notify_run', 'template-delete' => 'act_template_delete',
    'platform-home' => 'act_platform_home', 'subticket-create' => 'act_subticket_create', 'cc-add' => 'act_cc_add', 'cc-remove' => 'act_cc_remove', 'admin-perms-save' => 'act_admin_perms_save', 'impersonate' => 'act_impersonate', 'impersonate-stop' => 'act_impersonate_stop',
    'role-save' => 'act_role_save', 'role-delete' => 'act_role_delete', 'agent-perms-save' => 'act_agent_perms_save', 'security-save' => 'act_security_save', 'member-role' => 'act_member_role', 'member-remove' => 'act_member_remove',
];

// Never leak secrets/old input for these fields back into flash state.
const SECRET_FIELDS = ['password', 'confirm', 'smtpPassword', 'msClientSecret', '_csrf', 'token', 'apiKey'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = request_path();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

try {
    /* ---------- Public endpoints (no session / CSRF) ---------- */
    if (str_starts_with($path, '/api/v1/')) api_v1($method, $path);
    if ($path === '/cron') {
        // For hosts without a CLI cron: call GET /cron?key=CRON_KEY every few minutes.
        $k = (string) env('CRON_KEY', '');
        if ($k === '' || !hash_equals($k, (string) ($_GET['key'] ?? ''))) abort(403);
        json_out(run_cron());
    }
    if ($path === '/widget.js') public_widget_js();
    if ($path === '/brand/logo') brand_logo_out();
    // Viber / WhatsApp webhooks (signed by the app; no session).
    if (preg_match('#^/hooks/(viber|whatsapp)/([a-z0-9-]+)$#', $path, $m)) {
        $tenant = q_one('SELECT * FROM "Tenant" WHERE "slug" = ?', [$m[2]]);
        if (!$tenant) abort(404);
        $m[1] === 'viber' ? public_viber_hook($tenant) : public_whatsapp_hook($tenant);
    }
    if (preg_match('#^/support/([a-z0-9-]+)/chat(?:/(start|send|poll|close|typing|rate|upload|file|human))?$#', $path, $m)) {
        $tenant = q_one('SELECT * FROM "Tenant" WHERE "slug" = ?', [$m[1]]);
        if (!$tenant) abort(404);
        public_chat($tenant, $m[2] ?? '');
    }
    if (preg_match('#^/support/([a-z0-9-]+)(/kb)?$#', $path, $m)) {
        $tenant = q_one('SELECT * FROM "Tenant" WHERE "slug" = ?', [$m[1]]);
        if (!$tenant || !webform_settings($tenant['id'])['enabled']) abort(404);
        // The form may be embedded in the customer's own website.
        header_remove('X-Frame-Options');
        header("Content-Security-Policy: frame-ancestors *");
        if (!empty($m[2])) public_kb_suggest($tenant);
        $result = $method === 'POST' ? support_submit($tenant, $_POST) : null;
        page_title(webform_settings($tenant['id'])['title']);
        view('pages/support', ['tenant' => $tenant, 'result' => $result, 'embed' => isset($_GET['embed'])], 'base');
    }
    if (preg_match('#^/csat/([a-f0-9]{40})$#', $path, $m)) {
        $survey = q_one('SELECT * FROM "CsatSurvey" WHERE "tokenHash" = ?', [hash('sha256', $m[1])]);
        if (!$survey) abort(404);
        // One-click rating from the email link (?r=1..5) is recorded right away.
        if ($method === 'GET' && isset($_GET['r']) && !$survey['answeredAt']) $survey = csat_answer($survey, (int) $_GET['r'], '');
        if ($method === 'POST') {
            verify_csrf();
            $survey = csat_answer($survey, (int) ($_POST['rating'] ?? $survey['rating'] ?? 0), (string) ($_POST['comment'] ?? ''));
            $survey['thanked'] = true;
        }
        page_title('How did we do?');
        view('pages/csat', ['survey' => $survey, 'token' => $m[1]], 'base');
    }

    /* ---------- Mutations ---------- */
    if ($method === 'POST' && preg_match('#^/action/([a-z-]+)$#', $path, $m)) {
        $fn = $ACTIONS[$m[1]] ?? null;
        if (!$fn) abort(404);
        verify_csrf();
        $state = $fn($_POST) ?? ['ok' => true];
        audit_action($m[1], $_POST, $state);
        if (is_ajax() && isset($state['back']) && !isset($state['error'])) { flash_set((string) ($_POST['_form'] ?? ''), $state); json_out(['redirect' => $state['back']]); }
        if (is_ajax()) json_out($state, isset($state['error']) ? 422 : 200);
        $form = (string) ($_POST['_form'] ?? '');
        if ($form !== '') {
            if (isset($state['error'])) {
                $state['old'] = array_diff_key(array_filter($_POST, 'is_string'), array_flip(SECRET_FIELDS));
            }
            flash_set($form, $state);
        }
        // An action may send the user somewhere specific (e.g. the role it just created).
        redirect(safe_back($state['back'] ?? $_POST['_back'] ?? ($_SERVER['HTTP_REFERER'] ?? null)));
    }

    /* ---------- API ---------- */
    if (preg_match('#^/api/attachments/([A-Za-z0-9-]+)$#', $path, $m) && $method === 'GET') api_attachment($m[1]);
    if ($path === '/api/editor/images' && $method === 'POST') api_editor_image();
    if ($path === '/api/push/subscribe' && $method === 'POST') api_push_subscribe();
    if ($path === '/api/push/unsubscribe' && $method === 'POST') api_push_unsubscribe();
    if ($path === '/api/push/test' && $method === 'POST') api_push_test();
    if ($path === '/api/stream' && $method === 'GET') api_stream();
    if ($path === '/api/search' && $method === 'GET') api_search();
    if ($path === '/api/presence' && $method === 'POST') api_presence();
    if ($path === '/api/kb/suggest' && $method === 'GET') api_kb_suggest();
    if ($path === '/api/ai' && $method === 'POST') api_ai();
    if ($path === '/api/chat/list' && $method === 'GET') api_chat_list();
    if ($path === '/api/chat/messages' && $method === 'GET') api_chat_messages();
    if ($path === '/api/chat/reply' && $method === 'POST') api_chat_reply();
    if ($path === '/api/chat/action' && $method === 'POST') api_chat_action();
    if ($path === '/api/chat/upload' && $method === 'POST') api_chat_upload();
    if ($path === '/api/messenger/list' && $method === 'GET') api_messenger_list();
    if ($path === '/api/messenger/messages' && $method === 'GET') api_messenger_messages();
    if ($path === '/api/messenger/start' && $method === 'POST') api_messenger_start();
    if ($path === '/api/messenger/send' && $method === 'POST') api_messenger_send();
    if ($path === '/api/messenger/upload' && $method === 'POST') api_messenger_upload();
    if ($path === '/api/messenger/action' && $method === 'POST') api_messenger_action();
    if ($path === '/reports/export.xlsx' && $method === 'GET') report_export_xlsx();
    if ($path === '/settings/backup.zip' && $method === 'GET') backup_download_now();
    if (preg_match('#^/settings/backups/([A-Za-z0-9._-]+)$#', $path, $m) && $method === 'GET') backup_file_download($m[1]);
    if ($path === '/settings/export/org.zip' && $method === 'GET') org_export_download();
    if ($path === '/settings/export/person.json' && $method === 'GET') person_export_download();
    if ($path === '/reports/billing.xlsx' && $method === 'GET') billing_export_xlsx();
    if (preg_match('#^/tickets/([A-Za-z0-9]+)/files.zip$#', $path, $m) && $method === 'GET') ticket_files_zip($m[1]);
    if ($path === '/api/customers' && $method === 'GET') api_customers_search();
    if ($path === '/api/people' && $method === 'GET') api_people_search();
    if ($path === '/api/tickets/similar' && $method === 'GET') api_similar_tickets();
    if ($path === '/manifest.webmanifest') api_manifest();
    if ($path === '/tickets/export' && $method === 'GET') export_tickets_csv();
    if ($path === '/settings/audit.csv' && $method === 'GET') export_audit_csv();
    if ($path === '/reports/email-preview' && $method === 'GET') report_email_preview();
    if ($path === '/dev/mail' && $method === 'GET') dev_mail_view();

    if ($method !== 'GET' && $method !== 'HEAD') abort(405, 'Method not allowed');

    /* ---------- Pages ---------- */
    $guestOnly = function () {
        $u = current_user();
        if ($u) redirect(home_for($u));
    };

    switch (true) {
        case $path === '/':
            $u = current_user();
            redirect($u ? home_for($u) : '/login');

        case $path === '/login':
            $guestOnly();
            page_title('Sign in');
            view('pages/login', [], 'auth');
        case $path === '/register':
            $guestOnly();
            if (!signup_open()) redirect('/login');
            page_title('Create workspace');
            view('pages/register', [], 'auth');
        case $path === '/forgot':
            $guestOnly();
            page_title('Forgot password');
            view('pages/forgot', [], 'auth');
        case $path === '/reset':
            $guestOnly();
            page_title('Reset password');
            view('pages/reset', ['token' => (string) ($_GET['token'] ?? '')], 'auth');

        case $path === '/dashboard':
            view('pages/dashboard', [], 'app');
        case $path === '/tickets':
            view('pages/tickets', [], 'app');
        case $path === '/tickets/new':
            view('pages/ticket_new', [], 'app');
        case (bool) preg_match('#^/tickets/([A-Za-z0-9]+)$#', $path, $m):
            view('pages/ticket_show', ['id' => $m[1]], 'app');
        case (bool) preg_match('#^/tickets/([A-Za-z0-9]+)/print$#', $path, $m):
            require SRC . '/views/pages/ticket_print.php';
            throw new HttpExit();
        case $path === '/team':
            // User management lives in Settings → Users & roles.
            redirect('/settings' . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . $_SERVER['QUERY_STRING'] : '') . '#users');
        case $path === '/settings':
            view('pages/settings', [], 'app');
        case $path === '/kb':
            view('pages/kb', [], 'app');
        case $path === '/kb/new':
            view('pages/kb_edit', ['id' => null], 'app');
        case (bool) preg_match('#^/kb/([A-Za-z0-9]+)/edit$#', $path, $m):
            view('pages/kb_edit', ['id' => $m[1]], 'app');
        case (bool) preg_match('#^/kb/([A-Za-z0-9]+)$#', $path, $m):
            view('pages/kb_show', ['id' => $m[1]], 'app');
        case $path === '/reports':
            view('pages/reports', [], 'app');
        case $path === '/messenger':
            view('pages/messenger', [], 'base');
        case $path === '/widget-preview':
            require SRC . '/views/pages/widget_preview.php';
            throw new HttpExit();
        case $path === '/chat':
            view('pages/chat', [], 'app');
        case $path === '/about':
            view('pages/about', [], 'app');
        case $path === '/notifications':
            view('pages/notifications', [], 'app');

        case $path === '/platform':
            view('pages/platform', [], 'platform');
        case (bool) preg_match('#^/platform/tenants/([A-Za-z0-9]+)$#', $path, $m):
            view('pages/platform_tenant', ['id' => $m[1]], 'platform');

        default:
            abort(404);
    }
} catch (HttpExit) {
    // Response already sent.
} catch (Throwable $e) {
    error_log((string) $e);
    if (!headers_sent()) http_response_code(500);
    if (env('APP_DEBUG', '0') === '1') {
        echo '<pre style="padding:1rem;white-space:pre-wrap">' . e((string) $e) . '</pre>';
    } else {
        echo is_ajax() ? json_encode(['error' => 'Something went wrong']) : 'Something went wrong.';
    }
}
