<?php
declare(strict_types=1);

/**
 * Aggregate-only business analysis for Aygun. GET + department service token.
 * Read-only; never submits proposals or performs module writes.
 */

require dirname(__DIR__, 2) . '/business-analysis-lib.php';
require_once dirname(__DIR__, 2) . '/crew-service-auth-lib.php';

app_send_security_headers();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function business_analysis_fail(int $status, string $reason): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    business_analysis_fail(405, 'method_not_allowed');
}

$db = app_db();
if (!$db instanceof PDO) {
    business_analysis_fail(503, 'database_unavailable');
}
$actor = app_crew_service_actor($db, app_crew_service_bearer());
if ($actor === null) {
    business_analysis_fail(401, 'auth_required');
}

try {
    $result = app_business_analysis_snapshot($db, $actor, (string) ($_GET['module'] ?? ''));
} catch (Throwable) {
    business_analysis_fail(500, 'analysis_unavailable');
}
if (empty($result['ok'])) {
    business_analysis_fail(($result['reason'] ?? '') === 'module_denied' ? 403 : 422, (string) ($result['reason'] ?? 'analysis_unavailable'));
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
