<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
$errors = [];
$expect = static function (bool $condition, string $error) use (&$errors): void {
    if (!$condition) {
        $errors[] = $error;
    }
};

$required = [
    'api/public/website-lead.php',
    'website-lead-service.php',
    'db/migrations/20260729_180_public_site_ingress.php',
    'deploy/cpanel/public-config.php',
    'deploy/cpanel/muraciet-gonder.php',
    'deploy/cpanel/portal-login.php',
    'deploy/cpanel/.htaccess',
    'deploy/build-cpanel-public-site.ps1',
];
foreach ($required as $file) {
    $expect(is_file($root . '/' . $file), 'missing_file:' . $file);
}

$endpoint = (string) @file_get_contents($root . '/api/public/website-lead.php');
$expect(str_contains($endpoint, 'PUBLIC_SITE_INGRESS_KEY_ID'), 'gateway_key_id_env_missing');
$expect(str_contains($endpoint, 'PUBLIC_SITE_INGRESS_SECRET'), 'gateway_secret_env_missing');
$expect(str_contains($endpoint, 'hash_hmac'), 'gateway_hmac_validation_missing');
$expect(str_contains($endpoint, 'public_ingress_nonces'), 'gateway_replay_storage_missing');
$expect(str_contains($endpoint, 'request_replayed'), 'gateway_replay_response_missing');

$leadService = (string) @file_get_contents($root . '/website-lead-service.php');
$expect(str_contains($leadService, 'app_website_lead_create_crm_ticket'), 'website_lead_crm_service_missing');
$expect(str_contains($leadService, 'app_db_insert_ticket'), 'website_lead_does_not_persist_crm_ticket');
$expect(str_contains($leadService, 'app_portal_ticket_close_link'), 'website_lead_close_link_missing');
$expect(str_contains($leadService, "'crm_ticket_no'"), 'website_lead_ticket_result_missing');

$publicConfig = (string) @file_get_contents($root . '/deploy/cpanel/public-config.php');
foreach (['db.php', 'mgs-integration-lib.php', 'config.local.php'] as $forbidden) {
    $expect(!str_contains($publicConfig, $forbidden), 'public_config_references_internal_file:' . $forbidden);
}
$publicHandler = (string) @file_get_contents($root . '/deploy/cpanel/muraciet-gonder.php');
$expect(str_contains($publicHandler, 'X-Superoptic-Signature'), 'public_handler_signature_header_missing');
$expect(str_contains($publicHandler, 'CURLOPT_SSL_VERIFYPEER'), 'public_handler_tls_verification_missing');
$expect(!str_contains($publicHandler, 'TELEGRAM_BOT_TOKEN'), 'public_handler_contains_bot_secret_reference');

$pageHelpers = (string) @file_get_contents($root . '/page-helpers.php');
$expect(str_contains($pageHelpers, "app_config('portal_url'"), 'public_pages_portal_url_not_configurable');
$expect(!str_contains($pageHelpers, "page_url('portal-login.php', \$lang)"), 'public_pages_still_use_local_portal');

$migration = (string) @file_get_contents($root . '/db/migrations/20260729_180_public_site_ingress.php');
$expect(str_contains($migration, 'UNIQUE KEY uq_public_ingress_nonce'), 'ingress_nonce_unique_key_missing');

$result = [
    'status' => $errors === [] ? 'passed' : 'failed',
    'errors' => $errors,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($errors === [] ? 0 : 1);
