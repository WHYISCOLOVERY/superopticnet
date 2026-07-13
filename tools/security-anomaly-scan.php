<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require dirname(__DIR__) . '/security-anomaly-lib.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--record') {
        $options['record'] = true;
        continue;
    }
    if ($arg === '--live') {
        $options['test_mode'] = false;
        continue;
    }
    if (str_starts_with($arg, '--traffic-hours=')) {
        $options['traffic_window_hours'] = (int) substr($arg, 16);
        continue;
    }
    if (str_starts_with($arg, '--nat-minutes=')) {
        $options['nat_window_minutes'] = (int) substr($arg, 14);
        continue;
    }
}

$result = app_security_anomaly_run(app_db(), $options);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
