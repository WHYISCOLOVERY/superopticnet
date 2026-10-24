<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

putenv('APP_ENVIRONMENT=production');
putenv('APP_DEMO_FIXTURES_ENABLED=1');
require_once dirname(__DIR__) . '/billing-ui.php';

$root = dirname(__DIR__);
$checks = [];
$expect = static function (bool $ok, string $name) use (&$checks): void {
    $checks[$name] = $ok;
};

$expect(!app_demo_fixtures_enabled(), 'production_overrides_demo_flag');
$expect(billing_mock_customer_rows() === [], 'production_customer_fixture_empty');
$technicians = app_portal_mock_technicians();
$expectedTechnicians = app_db_available() ? app_db_technicians() : [];
$expect($technicians === $expectedTechnicians, 'production_technician_source_real_or_empty');
$portalFixture = app_mock_portal_data();
$expect(
    array_sum(array_map(static fn (mixed $rows): int => is_array($rows) ? count($rows) : 0, $portalFixture)) === 0,
    'production_portal_fixture_empty'
);

$messageSource = (string) file_get_contents($root . '/message-center.php');
$expect(
    str_contains($messageSource, "\$action === 'provider_test' && !app_demo_fixtures_enabled()"),
    'provider_test_post_blocked_in_production'
);
$expect(
    str_contains($messageSource, "\$templates === [] && app_demo_fixtures_enabled()"),
    'template_fixture_explicitly_gated'
);
$expect(
    str_contains($messageSource, "\$recent === [] && app_demo_fixtures_enabled()"),
    'recent_message_fixture_explicitly_gated'
);
$expect(
    str_contains($messageSource, "\$messageView === 'test' && !app_demo_fixtures_enabled()"),
    'provider_test_view_hidden_in_production'
);

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode(['ok' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($failed === [] ? 0 : 1);
