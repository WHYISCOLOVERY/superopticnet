<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$configPath = $argv[1] ?? '';
$host = $argv[2] ?? '';
$outputPath = $argv[3] ?? '';
if ($configPath === '' || $host === '' || $outputPath === '' || !is_file($configPath)) {
    fwrite(STDERR, "Usage: php audit-legacy-live-db.php CONFIG HOST OUTPUT_JSON\n");
    exit(2);
}

$source = (string) file_get_contents($configPath);
$readLiteral = static function (string $name) use ($source): string {
    $pattern = '/\$' . preg_quote($name, '/') . '\s*=\s*([\'\"])(.*?)\1\s*;/s';
    if (!preg_match($pattern, $source, $matches)) {
        throw new RuntimeException("Missing config variable: {$name}");
    }
    return stripcslashes((string) $matches[2]);
};

$connections = [
    'billing' => [
        'database' => $readLiteral('db_name'),
        'username' => $readLiteral('db_user'),
        'password' => $readLiteral('db_pass'),
    ],
    'radius' => [
        'database' => $readLiteral('db_name_r'),
        'username' => $readLiteral('db_user_r'),
        'password' => $readLiteral('db_pass_r'),
    ],
];

$result = [
    'captured_at' => gmdate('c'),
    'host' => $host,
    'databases' => [],
];

foreach ($connections as $label => $connection) {
    $pdo = new PDO(
        'mysql:host=' . $host . ';port=3306;dbname=' . $connection['database'] . ';charset=utf8mb4',
        $connection['username'],
        $connection['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 8,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION TRANSACTION READ ONLY",
        ]
    );
    $pdo->exec('START TRANSACTION READ ONLY');
    $tables = $pdo->query(
        "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME"
    )->fetchAll();
    $tableReport = [];
    foreach ($tables as $table) {
        $name = (string) $table['TABLE_NAME'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            continue;
        }
        $count = (int) $pdo->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn();
        $columns = $pdo->prepare(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
             ORDER BY ORDINAL_POSITION"
        );
        $columns->execute(['table' => $name]);
        $tableReport[$name] = [
            'row_count' => $count,
            'data_bytes_estimate' => (int) ($table['DATA_LENGTH'] ?? 0),
            'index_bytes_estimate' => (int) ($table['INDEX_LENGTH'] ?? 0),
            'columns' => $columns->fetchAll(),
        ];
    }
    $pdo->rollBack();
    $result['databases'][$label] = [
        'database' => $connection['database'],
        'tables' => $tableReport,
    ];
}

$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) {
    throw new RuntimeException('Could not encode report.');
}
file_put_contents($outputPath, $encoded . PHP_EOL, LOCK_EX);

$summary = [];
foreach ($result['databases'] as $label => $database) {
    $summary[$label] = [
        'database' => $database['database'],
        'table_count' => count($database['tables']),
        'row_count' => array_sum(array_column($database['tables'], 'row_count')),
        'tables' => array_map(
            static fn (array $table): int => (int) $table['row_count'],
            $database['tables']
        ),
    ];
}
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
