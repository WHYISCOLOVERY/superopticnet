<?php
declare(strict_types=1);

require dirname(__DIR__) . '/mgs-integration-lib.php';

$fixture = <<<'TXT'
PON/ONU  Serial  Temperature Voltage Bias TX RX OLT-RX Name
1/2  HG12345678  47.5 C  3.30 V  12.10 mA  2.30 dBm  -24.20 dBm  -29.10 dBm  home-a
2/7  HG87654321  41 C  3.28 V  11.80 mA  2.10 dBm  -18.60 dBm  -25.00 dBm
TXT;
$rows = app_mgs_parse_hsgq_ont_optical_all_rows($fixture);
$failures = [];
if (count($rows) !== 2) {
    $failures[] = 'row_count';
}
if (($rows[0]['pon'] ?? '') !== '1' || ($rows[0]['ont_id'] ?? '') !== '2' || ($rows[0]['serial'] ?? '') !== 'HG12345678') {
    $failures[] = 'identity_parse';
}
if (($rows[0]['temperature'] ?? null) !== 47.5 || ($rows[0]['ont_rx'] ?? null) !== -24.2 || ($rows[0]['olt_rx'] ?? null) !== -29.1) {
    $failures[] = 'metric_parse';
}
if (($rows[1]['olt_rx'] ?? null) !== -25.0 || ($rows[1]['name'] ?? '') !== '') {
    $failures[] = 'optional_name_parse';
}

$endpoint = (string) file_get_contents(dirname(__DIR__) . '/api/agent-ops/ont-optical-analysis.php');
foreach (['app_crew_service_actor', 'app_mgs_hsgq_live_ont_inventory', 'ont_analysis_matches',
          'ont_tx_threshold_dbm', 'ont_rx_threshold_dbm', 'temperature_threshold_c',
          'threshold_semantics', "'match_mode' => 'any'", "\$input['limit'] ?? 1500"] as $marker) {
    if (!str_contains($endpoint, $marker)) {
        $failures[] = 'endpoint_contract:' . $marker;
    }
}
foreach (['password', 'ssh_username', "['raw']"] as $forbidden) {
    if (str_contains($endpoint, $forbidden)) {
        $failures[] = 'endpoint_sensitive_output:' . $forbidden;
    }
}

echo json_encode(['ok' => $failures === [], 'failures' => $failures, 'parsed' => count($rows)], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failures === [] ? 0 : 1);
