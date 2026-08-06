<?php
declare(strict_types=1);

const APP_AUDIT_ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';
const APP_AUDIT_SCHEMA_VERSION = '2026-07-06-audit-ledger-v3';
const APP_AUDIT_SCHEMA_LOCK = 'superopticnet:audit-ledger-schema';
const APP_AUDIT_VERIFY_BATCH_SIZE = 250;

function app_audit_ensure_schema(PDO $db, bool $forceValidation = false): bool
{
    if ($db->inTransaction()) {
        throw new LogicException('Audit schema migration cannot run inside a transaction.');
    }

    try {
        $marker = $db->prepare(
            'SELECT schema_hash
               FROM security_audit_schema_migrations
              WHERE version_key = :version_key'
        );
        $marker->execute(['version_key' => APP_AUDIT_SCHEMA_VERSION]);
        $markerIsCurrent = hash_equals(
            hash('sha256', APP_AUDIT_SCHEMA_VERSION),
            (string) $marker->fetchColumn()
        );
        if ($markerIsCurrent && !$forceValidation) {
            return true;
        }

        app_audit_validate_schema($db);
        if ($markerIsCurrent) {
            return true;
        }
    } catch (RuntimeException) {
        // Only the versioned migration account may repair a missing or drifted schema.
    }

    $lock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lock->execute(['lock_name' => APP_AUDIT_SCHEMA_LOCK]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the audit schema migration lock.');
    }

    try {
        app_audit_apply_schema($db);
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => APP_AUDIT_SCHEMA_LOCK]);
        if ((int) $release->fetchColumn() !== 1) {
            throw new RuntimeException('Could not release the audit schema migration lock.');
        }
    }

    return true;
}

function app_audit_apply_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS security_audit_schema_migrations (
            version_key VARCHAR(128) NOT NULL PRIMARY KEY,
            schema_hash CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS mgs_operation_actions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action_type VARCHAR(64) NOT NULL,
            entity_type VARCHAR(64) NOT NULL DEFAULT '',
            entity_id VARCHAR(191) NOT NULL DEFAULT '',
            status ENUM('planned','dry_run','pending','executed','failed','skipped','reversed') NOT NULL DEFAULT 'planned',
            actor_account_id BIGINT UNSIGNED DEFAULT NULL,
            actor_name VARCHAR(191) DEFAULT NULL,
            summary VARCHAR(255) DEFAULT NULL,
            ticket_no VARCHAR(32) DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            executed_at DATETIME DEFAULT NULL,
            KEY idx_mgs_operation_type (action_type, created_at),
            KEY idx_mgs_operation_entity (entity_type, entity_id),
            KEY idx_mgs_operation_actor (actor_account_id, created_at),
            KEY idx_mgs_operation_ticket (ticket_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $columns = [
        'module_key' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'operation_class' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'reason' => 'TEXT DEFAULT NULL',
        'before_json' => 'LONGTEXT DEFAULT NULL',
        'after_json' => 'LONGTEXT DEFAULT NULL',
        'request_ip' => 'VARCHAR(45) DEFAULT NULL',
        'user_agent' => 'VARCHAR(512) DEFAULT NULL',
        'request_id' => 'VARCHAR(128) DEFAULT NULL',
        'sensitivity' => "VARCHAR(32) NOT NULL DEFAULT 'normal'",
        'is_visible' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'edited_by_account_id' => 'BIGINT UNSIGNED DEFAULT NULL',
        'edited_at' => 'DATETIME DEFAULT NULL',
        'deleted_by_account_id' => 'BIGINT UNSIGNED DEFAULT NULL',
        'deleted_at' => 'DATETIME DEFAULT NULL',
    ];
    $columnStatement = $db->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = "mgs_operation_actions"
            AND COLUMN_NAME = :column'
    );
    foreach ($columns as $column => $definition) {
        $columnStatement->execute(['column' => $column]);
        if ((int) $columnStatement->fetchColumn() === 0) {
            $db->exec(sprintf('ALTER TABLE mgs_operation_actions ADD COLUMN `%s` %s', $column, $definition));
        }
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS security_audit_ledger (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action_id BIGINT UNSIGNED NOT NULL,
            module_key VARCHAR(64) NOT NULL DEFAULT '',
            operation_class VARCHAR(64) NOT NULL DEFAULT '',
            action_type VARCHAR(64) NOT NULL,
            entity_type VARCHAR(64) NOT NULL DEFAULT '',
            entity_id VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL,
            actor_account_id BIGINT UNSIGNED DEFAULT NULL,
            actor_name VARCHAR(191) DEFAULT NULL,
            summary VARCHAR(255) DEFAULT NULL,
            ticket_no VARCHAR(32) DEFAULT NULL,
            reason TEXT DEFAULT NULL,
            before_json LONGTEXT DEFAULT NULL,
            after_json LONGTEXT DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            request_ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(512) DEFAULT NULL,
            request_id VARCHAR(128) DEFAULT NULL,
            sensitivity VARCHAR(32) NOT NULL DEFAULT 'normal',
            is_visible TINYINT(1) NOT NULL DEFAULT 1,
            previous_hash CHAR(64) NOT NULL,
            event_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_security_audit_action (action_id),
            UNIQUE KEY uq_security_audit_hash (event_hash),
            KEY idx_security_audit_created (created_at),
            KEY idx_security_audit_module_action (module_key, action_type, created_at),
            KEY idx_security_audit_entity (entity_type, entity_id),
            KEY idx_security_audit_actor (actor_account_id, created_at),
            KEY idx_security_audit_request (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS security_audit_chain_state (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            current_hash CHAR(64) NOT NULL,
            last_ledger_id BIGINT UNSIGNED DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT chk_security_audit_chain_singleton CHECK (id = 1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $singletonConstraint = $db->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME = "security_audit_chain_state"
            AND CONSTRAINT_NAME = "chk_security_audit_chain_singleton"
            AND CONSTRAINT_TYPE = "CHECK"'
    );
    $singletonConstraint->execute();
    if ((int) $singletonConstraint->fetchColumn() === 0) {
        $db->exec(
            'ALTER TABLE security_audit_chain_state
             ADD CONSTRAINT chk_security_audit_chain_singleton CHECK (id = 1)'
        );
    }
    $state = $db->prepare(
        'INSERT IGNORE INTO security_audit_chain_state (id, current_hash, last_ledger_id)
         VALUES (1, :zero_hash, NULL)'
    );
    $state->execute(['zero_hash' => APP_AUDIT_ZERO_HASH]);

    $triggers = [
        'security_audit_ledger_bu' => [
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
            'action' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_audit_ledger is immutable'",
            'create' => "CREATE TRIGGER security_audit_ledger_bu BEFORE UPDATE ON security_audit_ledger
                         FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_audit_ledger is immutable'",
        ],
        'security_audit_ledger_bd' => [
            'timing' => 'BEFORE',
            'event' => 'DELETE',
            'action' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_audit_ledger is immutable'",
            'create' => "CREATE TRIGGER security_audit_ledger_bd BEFORE DELETE ON security_audit_ledger
                         FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_audit_ledger is immutable'",
        ],
    ];
    $triggerStatement = $db->prepare(
        'SELECT ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT, EVENT_OBJECT_TABLE
           FROM INFORMATION_SCHEMA.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE()
            AND TRIGGER_NAME = :trigger_name'
    );
    foreach ($triggers as $name => $definition) {
        $triggerStatement->execute(['trigger_name' => $name]);
        $current = $triggerStatement->fetch(PDO::FETCH_ASSOC);
        $valid = is_array($current)
            && (string) $current['EVENT_OBJECT_TABLE'] === 'security_audit_ledger'
            && (string) $current['ACTION_TIMING'] === $definition['timing']
            && (string) $current['EVENT_MANIPULATION'] === $definition['event']
            && app_audit_normalize_sql((string) $current['ACTION_STATEMENT'])
                === app_audit_normalize_sql($definition['action']);
        if (!$valid) {
            if (is_array($current)) {
                $db->exec('DROP TRIGGER `' . $name . '`');
            }
            $db->exec($definition['create']);
        }
    }

    app_audit_validate_schema($db);
    $marker = $db->prepare(
        'INSERT INTO security_audit_schema_migrations (version_key, schema_hash)
         VALUES (:version_key, :schema_hash)
         ON DUPLICATE KEY UPDATE version_key = VALUES(version_key)'
    );
    $marker->execute([
        'version_key' => APP_AUDIT_SCHEMA_VERSION,
        'schema_hash' => hash('sha256', APP_AUDIT_SCHEMA_VERSION),
    ]);
}

function app_audit_normalize_sql(string $sql): string
{
    return strtolower(trim((string) preg_replace('/\s+/', ' ', str_replace('`', '', $sql))));
}

function app_audit_normalize_column_type(string $type): string
{
    $type = strtolower(trim($type));
    return (string) preg_replace(
        '/\b(bigint|int|tinyint|smallint|mediumint)\(\d+\)/',
        '$1',
        $type
    );
}

function app_audit_normalize_column_default(mixed $value): ?string
{
    if ($value === null || strcasecmp((string) $value, 'NULL') === 0) {
        return null;
    }
    $value = strtolower(trim((string) $value));
    if (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
        $value = substr($value, 1, -1);
    }
    return str_replace('current_timestamp()', 'current_timestamp', $value);
}

function app_audit_normalize_column_extra(mixed $value): string
{
    $value = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));
    $value = str_replace(['default_generated', 'current_timestamp()'], ['', 'current_timestamp'], $value);
    return trim((string) preg_replace('/\s+/', ' ', $value));
}

function app_audit_validate_schema(PDO $db): void
{
    $c = static fn(string $type, string $nullable = 'NO', ?string $default = null, string $extra = ''): array => [
        'type' => app_audit_normalize_column_type($type),
        'nullable' => $nullable,
        'default' => app_audit_normalize_column_default($default),
        'extra' => app_audit_normalize_column_extra($extra),
    ];
    $tables = [
        'mgs_operation_actions' => [
            'id' => $c('bigint(20) unsigned', 'NO', null, 'auto_increment'),
            'action_type' => $c('varchar(64)'),
            'entity_type' => $c('varchar(64)', 'NO', "''"),
            'entity_id' => $c('varchar(191)', 'NO', "''"),
            'status' => $c("enum('planned','dry_run','pending','executed','failed','skipped','reversed')", 'NO', "'planned'"),
            'actor_account_id' => $c('bigint(20) unsigned', 'YES'),
            'actor_name' => $c('varchar(191)', 'YES'),
            'summary' => $c('varchar(255)', 'YES'),
            'ticket_no' => $c('varchar(32)', 'YES'),
            'payload_json' => $c('longtext', 'YES'),
            'created_at' => $c('datetime', 'NO', 'current_timestamp()'),
            'executed_at' => $c('datetime', 'YES'),
            'module_key' => $c('varchar(64)', 'NO', "''"),
            'operation_class' => $c('varchar(64)', 'NO', "''"),
            'reason' => $c('text', 'YES'),
            'before_json' => $c('longtext', 'YES'),
            'after_json' => $c('longtext', 'YES'),
            'request_ip' => $c('varchar(45)', 'YES'),
            'user_agent' => $c('varchar(512)', 'YES'),
            'request_id' => $c('varchar(128)', 'YES'),
            'sensitivity' => $c('varchar(32)', 'NO', "'normal'"),
            'is_visible' => $c('tinyint(1)', 'NO', '1'),
            'edited_by_account_id' => $c('bigint(20) unsigned', 'YES'),
            'edited_at' => $c('datetime', 'YES'),
            'deleted_by_account_id' => $c('bigint(20) unsigned', 'YES'),
            'deleted_at' => $c('datetime', 'YES'),
        ],
        'security_audit_ledger' => [
            'id' => $c('bigint(20) unsigned', 'NO', null, 'auto_increment'),
            'action_id' => $c('bigint(20) unsigned'),
            'module_key' => $c('varchar(64)', 'NO', "''"),
            'operation_class' => $c('varchar(64)', 'NO', "''"),
            'action_type' => $c('varchar(64)'),
            'entity_type' => $c('varchar(64)', 'NO', "''"),
            'entity_id' => $c('varchar(191)', 'NO', "''"),
            'status' => $c('varchar(32)'),
            'actor_account_id' => $c('bigint(20) unsigned', 'YES'),
            'actor_name' => $c('varchar(191)', 'YES'),
            'summary' => $c('varchar(255)', 'YES'),
            'ticket_no' => $c('varchar(32)', 'YES'),
            'reason' => $c('text', 'YES'),
            'before_json' => $c('longtext', 'YES'),
            'after_json' => $c('longtext', 'YES'),
            'payload_json' => $c('longtext', 'YES'),
            'request_ip' => $c('varchar(45)', 'YES'),
            'user_agent' => $c('varchar(512)', 'YES'),
            'request_id' => $c('varchar(128)', 'YES'),
            'sensitivity' => $c('varchar(32)', 'NO', "'normal'"),
            'is_visible' => $c('tinyint(1)', 'NO', '1'),
            'previous_hash' => $c('char(64)'),
            'event_hash' => $c('char(64)'),
            'created_at' => $c('datetime'),
        ],
        'security_audit_chain_state' => [
            'id' => $c('tinyint(3) unsigned'),
            'current_hash' => $c('char(64)'),
            'last_ledger_id' => $c('bigint(20) unsigned', 'YES'),
            'updated_at' => $c('datetime', 'NO', 'current_timestamp()', 'on update current_timestamp()'),
        ],
        'security_audit_schema_migrations' => [
            'version_key' => $c('varchar(128)'),
            'schema_hash' => $c('char(64)'),
            'applied_at' => $c('datetime', 'NO', 'current_timestamp()'),
        ],
    ];

    $tableStatement = $db->prepare(
        'SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $columnStatement = $db->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    foreach ($tables as $table => $requiredColumns) {
        $tableStatement->execute(['table_name' => $table]);
        if (strcasecmp((string) $tableStatement->fetchColumn(), 'InnoDB') !== 0) {
            throw new RuntimeException('Audit migration requires destructive repair: table engine ' . $table);
        }
        $columnStatement->execute(['table_name' => $table]);
        $actualColumns = [];
        foreach ($columnStatement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $actualColumns[(string) $column['COLUMN_NAME']] = [
                'type' => app_audit_normalize_column_type((string) $column['COLUMN_TYPE']),
                'nullable' => strtoupper((string) $column['IS_NULLABLE']),
                'default' => app_audit_normalize_column_default($column['COLUMN_DEFAULT']),
                'extra' => app_audit_normalize_column_extra($column['EXTRA']),
            ];
        }
        foreach ($requiredColumns as $column => $expected) {
            if (($actualColumns[$column] ?? null) !== $expected) {
                throw new RuntimeException(
                    'Audit migration requires destructive repair: column drift ' . $table . '.' . $column
                );
            }
        }
    }

    $requiredIndexes = [
        'mgs_operation_actions' => [
            'PRIMARY' => [0, ['id']],
            'idx_mgs_operation_type' => [1, ['action_type', 'created_at']],
            'idx_mgs_operation_entity' => [1, ['entity_type', 'entity_id']],
            'idx_mgs_operation_actor' => [1, ['actor_account_id', 'created_at']],
            'idx_mgs_operation_ticket' => [1, ['ticket_no']],
        ],
        'security_audit_ledger' => [
            'PRIMARY' => [0, ['id']],
            'uq_security_audit_action' => [0, ['action_id']],
            'uq_security_audit_hash' => [0, ['event_hash']],
            'idx_security_audit_created' => [1, ['created_at']],
            'idx_security_audit_module_action' => [1, ['module_key', 'action_type', 'created_at']],
            'idx_security_audit_entity' => [1, ['entity_type', 'entity_id']],
            'idx_security_audit_actor' => [1, ['actor_account_id', 'created_at']],
            'idx_security_audit_request' => [1, ['request_id']],
        ],
        'security_audit_chain_state' => [
            'PRIMARY' => [0, ['id']],
        ],
        'security_audit_schema_migrations' => [
            'PRIMARY' => [0, ['version_key']],
        ],
    ];
    $indexStatement = $db->prepare(
        'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE
           FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name
          ORDER BY INDEX_NAME, SEQ_IN_INDEX'
    );
    foreach ($requiredIndexes as $table => $indexes) {
        $indexStatement->execute(['table_name' => $table]);
        $actualIndexes = [];
        foreach ($indexStatement->fetchAll(PDO::FETCH_ASSOC) as $index) {
            $name = (string) $index['INDEX_NAME'];
            $actualIndexes[$name]['non_unique'] = (int) $index['NON_UNIQUE'];
            $actualIndexes[$name]['columns'][] = (string) $index['COLUMN_NAME'];
            $actualIndexes[$name]['sub_parts'][] = $index['SUB_PART'];
            $actualIndexes[$name]['types'][] = strtoupper((string) $index['INDEX_TYPE']);
        }
        foreach ($indexes as $name => [$nonUnique, $columns]) {
            $actual = $actualIndexes[$name] ?? null;
            if (!is_array($actual)
                || $actual['non_unique'] !== $nonUnique
                || $actual['columns'] !== $columns
                || array_filter($actual['sub_parts'], static fn(mixed $part): bool => $part !== null) !== []
                || array_unique($actual['types']) !== ['BTREE']
            ) {
                throw new RuntimeException(
                    'Audit migration requires destructive repair: index drift ' . $table . '.' . $name
                );
            }
        }
    }

    $checkStatement = $db->query(
        "SELECT cc.CHECK_CLAUSE
           FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
           JOIN INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc
             ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
            AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
          WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
            AND tc.TABLE_NAME = 'security_audit_chain_state'
            AND tc.CONSTRAINT_NAME = 'chk_security_audit_chain_singleton'
            AND tc.CONSTRAINT_TYPE = 'CHECK'"
    );
    $checkClause = $checkStatement !== false ? (string) $checkStatement->fetchColumn() : '';
    if (strtolower(str_replace(['`', ' ', '(', ')'], '', $checkClause)) !== 'id=1') {
        throw new RuntimeException(
            'Audit migration requires destructive repair: singleton CHECK constraint drift'
        );
    }

    $triggerStatement = $db->prepare(
        'SELECT ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
           FROM INFORMATION_SCHEMA.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :trigger_name'
    );
    foreach (['security_audit_ledger_bu' => 'UPDATE', 'security_audit_ledger_bd' => 'DELETE'] as $name => $event) {
        $triggerStatement->execute(['trigger_name' => $name]);
        $trigger = $triggerStatement->fetch(PDO::FETCH_ASSOC);
        $action = is_array($trigger) ? app_audit_normalize_sql((string) $trigger['ACTION_STATEMENT']) : '';
        if (!is_array($trigger)
            || (string) $trigger['ACTION_TIMING'] !== 'BEFORE'
            || (string) $trigger['EVENT_MANIPULATION'] !== $event
            || $action !== app_audit_normalize_sql(
                "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_audit_ledger is immutable'"
            )
        ) {
            throw new RuntimeException('Audit trigger validation failed: ' . $name);
        }
    }
}

function app_audit_sanitize(mixed $value): mixed
{
    if (is_object($value)) {
        $value = get_object_vars($value);
    }
    if (is_string($value)) {
        return app_audit_sanitize_string($value);
    }
    if (!is_array($value)) {
        return $value;
    }

    $sanitized = [];
    foreach ($value as $key => $item) {
        $sanitized[$key] = is_string($key) && app_audit_sensitive_key($key)
            ? '[REDACTED]'
            : app_audit_sanitize($item);
    }
    return $sanitized;
}

function app_audit_sensitive_key(string $key): bool
{
    $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $key));
    return preg_match(
        '/password|passwd|passphrase|token|secret|session|csrf|apikey|authorization|authheader|cookie|privatekey|publickey|snmpcommunity|communitystring|credentials?|clientcertificate|clientkey|psk|presharedkey/',
        $normalized
    ) === 1;
}

function app_audit_sanitize_string(string $value): string
{
    $value = (string) preg_replace_callback(
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/%=-]+/i',
        static fn(array $match): string => $match[1] . ' [REDACTED]',
        $value
    );

    return (string) preg_replace_callback(
        '/(\b[a-z0-9_.-]*(?:password|passwd|passphrase|token|secret|session|csrf|api[_-]?key|apikey|authorization|auth[_-]?header|cookie|private[_-]?key|public[_-]?key|snmp[_-]?community|community[_-]?string|credentials?|client[_-]?(?:certificate|key)|psk|pre[_-]?shared[_-]?key)[a-z0-9_.-]*\s*(?:=|:)\s*)(?:"[^"]*"|\'[^\']*\'|[^\s&;,]+)/i',
        static fn(array $match): string => $match[1] . '[REDACTED]',
        $value
    );
}

function app_audit_canonical_json(mixed $value): string
{
    $normalize = static function (mixed $item) use (&$normalize): mixed {
        if (is_object($item)) {
            $item = get_object_vars($item);
        }
        if (!is_array($item)) {
            return $item;
        }
        if (array_is_list($item)) {
            return array_map($normalize, $item);
        }
        ksort($item, SORT_STRING);
        foreach ($item as $key => $value) {
            $item[$key] = $normalize($value);
        }
        return $item;
    };

    return json_encode(
        $normalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    );
}

function app_audit_request_context(array $overrides = []): array
{
    $requestIp = $overrides['request_ip'] ?? $overrides['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = $overrides['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $requestId = $overrides['request_id']
        ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['HTTP_X_CORRELATION_ID'] ?? '');
    $requestId = trim(app_audit_sanitize_string((string) $requestId));
    if ($requestId === '') {
        $requestId = bin2hex(random_bytes(16));
    }

    return [
        'request_ip' => substr(trim(app_audit_sanitize_string((string) $requestIp)), 0, 45),
        'user_agent' => substr(trim(app_audit_sanitize_string((string) $userAgent)), 0, 512),
        'request_id' => substr($requestId, 0, 128),
    ];
}

function app_audit_record(PDO $db, array $event): array
{
    $status = strtolower(trim(app_audit_sanitize_string((string) ($event['status'] ?? 'planned'))));
    $sensitivity = strtolower(trim(app_audit_sanitize_string((string) ($event['sensitivity'] ?? 'normal'))));
    if ($status === 'executed'
        && !in_array($sensitivity, ['normal', 'public'], true)
        && !$db->inTransaction()
    ) {
        throw new LogicException('Executed sensitive audit events require an active transaction.');
    }
    if (!in_array($status, ['planned', 'dry_run', 'pending', 'executed', 'failed', 'skipped'], true)) {
        throw new InvalidArgumentException('Unsupported audit status.');
    }

    $actionType = substr(trim(app_audit_sanitize_string((string) ($event['action_type'] ?? ''))), 0, 64);
    if ($actionType === '') {
        throw new InvalidArgumentException('action_type is required.');
    }

    $contextOverrides = is_array($event['context'] ?? null) ? $event['context'] : [];
    foreach (['request_ip', 'user_agent', 'request_id'] as $contextKey) {
        if (array_key_exists($contextKey, $event)) {
            $contextOverrides[$contextKey] = $event[$contextKey];
        }
    }
    $context = app_audit_request_context($contextOverrides);
    $before = array_key_exists('before', $event) ? app_audit_sanitize($event['before']) : null;
    $after = array_key_exists('after', $event) ? app_audit_sanitize($event['after']) : null;
    $payload = array_key_exists('payload', $event) ? app_audit_sanitize($event['payload']) : null;
    $createdAt = date('Y-m-d H:i:s');
    $executedAt = $event['executed_at'] ?? ($status === 'executed' ? $createdAt : null);
    $actorId = isset($event['actor_account_id']) && (int) $event['actor_account_id'] > 0
        ? (int) $event['actor_account_id']
        : null;

    $row = [
        'module_key' => substr(trim(app_audit_sanitize_string((string) ($event['module_key'] ?? ''))), 0, 64),
        'operation_class' => substr(trim(app_audit_sanitize_string((string) ($event['operation_class'] ?? ''))), 0, 64),
        'action_type' => $actionType,
        'entity_type' => substr(trim(app_audit_sanitize_string((string) ($event['entity_type'] ?? ''))), 0, 64),
        'entity_id' => substr(trim(app_audit_sanitize_string((string) ($event['entity_id'] ?? ''))), 0, 191),
        'status' => $status,
        'actor_account_id' => $actorId,
        'actor_name' => app_audit_nullable_text($event['actor_name'] ?? null, 191),
        'summary' => app_audit_nullable_text($event['summary'] ?? null, 255),
        'ticket_no' => app_audit_nullable_text($event['ticket_no'] ?? null, 32),
        'reason' => app_audit_nullable_text($event['reason'] ?? null),
        'before_json' => $before === null ? null : app_audit_canonical_json($before),
        'after_json' => $after === null ? null : app_audit_canonical_json($after),
        'payload_json' => $payload === null ? null : app_audit_canonical_json($payload),
        'request_ip' => $context['request_ip'] !== '' ? $context['request_ip'] : null,
        'user_agent' => $context['user_agent'] !== '' ? $context['user_agent'] : null,
        'request_id' => $context['request_id'],
        'sensitivity' => substr($sensitivity !== '' ? $sensitivity : 'normal', 0, 32),
        'is_visible' => !array_key_exists('is_visible', $event) || (bool) $event['is_visible'] ? 1 : 0,
        'created_at' => $createdAt,
        'executed_at' => $executedAt === null ? null : app_audit_sanitize_string((string) $executedAt),
    ];

    $ownsTransaction = !$db->inTransaction();
    $savepoint = '';
    try {
        if ($ownsTransaction) {
            $db->beginTransaction();
        } else {
            $savepoint = 'audit_' . bin2hex(random_bytes(6));
            $db->exec('SAVEPOINT ' . $savepoint);
        }

        $action = $db->prepare(
            'INSERT INTO mgs_operation_actions (
                module_key, operation_class, action_type, entity_type, entity_id, status,
                actor_account_id, actor_name, summary, ticket_no, reason, before_json, after_json,
                payload_json, request_ip, user_agent, request_id, sensitivity, is_visible,
                created_at, executed_at
             ) VALUES (
                :module_key, :operation_class, :action_type, :entity_type, :entity_id, :status,
                :actor_account_id, :actor_name, :summary, :ticket_no, :reason, :before_json, :after_json,
                :payload_json, :request_ip, :user_agent, :request_id, :sensitivity, :is_visible,
                :created_at, :executed_at
             )'
        );
        $action->execute($row);
        $actionId = (int) $db->lastInsertId();
        if ($actionId <= 0) {
            throw new RuntimeException('Operational audit action was not persisted.');
        }

        $stateStatement = $db->query(
            'SELECT current_hash, last_ledger_id FROM security_audit_chain_state WHERE id = 1 FOR UPDATE'
        );
        $state = $stateStatement !== false ? $stateStatement->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($state) || strlen((string) ($state['current_hash'] ?? '')) !== 64) {
            throw new RuntimeException('Audit chain state is unavailable.');
        }
        $previousHash = (string) $state['current_hash'];

        $ledgerRow = $row;
        unset($ledgerRow['executed_at']);
        $ledgerRow['action_id'] = $actionId;
        $canonicalPayload = app_audit_canonical_json(app_audit_ledger_payload($ledgerRow));
        $eventHash = hash('sha256', $previousHash . '|' . $canonicalPayload);
        $ledgerRow['previous_hash'] = $previousHash;
        $ledgerRow['event_hash'] = $eventHash;

        $ledger = $db->prepare(
            'INSERT INTO security_audit_ledger (
                action_id, module_key, operation_class, action_type, entity_type, entity_id, status,
                actor_account_id, actor_name, summary, ticket_no, reason, before_json, after_json,
                payload_json, request_ip, user_agent, request_id, sensitivity, is_visible,
                previous_hash, event_hash, created_at
             ) VALUES (
                :action_id, :module_key, :operation_class, :action_type, :entity_type, :entity_id, :status,
                :actor_account_id, :actor_name, :summary, :ticket_no, :reason, :before_json, :after_json,
                :payload_json, :request_ip, :user_agent, :request_id, :sensitivity, :is_visible,
                :previous_hash, :event_hash, :created_at
             )'
        );
        $ledger->execute($ledgerRow);
        $ledgerId = (int) $db->lastInsertId();
        if ($ledgerId <= 0) {
            throw new RuntimeException('Protected audit ledger row was not persisted.');
        }

        $updateState = $db->prepare(
            'UPDATE security_audit_chain_state
                SET current_hash = :event_hash, last_ledger_id = :ledger_id
              WHERE id = 1 AND current_hash = :previous_hash'
        );
        $updateState->execute([
            'event_hash' => $eventHash,
            'ledger_id' => $ledgerId,
            'previous_hash' => $previousHash,
        ]);
        if ($updateState->rowCount() !== 1) {
            throw new RuntimeException('Audit chain head was not updated.');
        }

        if ($ownsTransaction) {
            $db->commit();
        } else {
            $db->exec('RELEASE SAVEPOINT ' . $savepoint);
        }

        return [
            'ok' => true,
            'action_id' => $actionId,
            'ledger_id' => $ledgerId,
            'event_hash' => $eventHash,
        ];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        } elseif ($savepoint !== '' && $db->inTransaction()) {
            try {
                $db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $db->exec('RELEASE SAVEPOINT ' . $savepoint);
            } catch (Throwable $rollbackException) {
                $outerRollbackFailure = null;
                try {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                } catch (Throwable $outerRollbackException) {
                    $outerRollbackFailure = $outerRollbackException;
                }
                $message = 'Audit savepoint rollback failed after persistence error: '
                    . $rollbackException->getMessage();
                if ($outerRollbackFailure instanceof Throwable) {
                    $message .= '; outer transaction rollback also failed: '
                        . $outerRollbackFailure->getMessage();
                }
                throw new RuntimeException($message, 0, $exception);
            }
        }
        throw new RuntimeException('Audit persistence failed: ' . $exception->getMessage(), 0, $exception);
    }
}

function app_audit_verify_chain(PDO $db): array
{
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }

    try {
        $state = $db->query(
            'SELECT current_hash, last_ledger_id
               FROM security_audit_chain_state
              WHERE id = 1
              LOCK IN SHARE MODE'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state) || strlen((string) ($state['current_hash'] ?? '')) !== 64) {
            throw new RuntimeException('Audit chain state is unavailable.');
        }

        $snapshotHash = (string) $state['current_hash'];
        $snapshotLastId = $state['last_ledger_id'] === null ? null : (int) $state['last_ledger_id'];
        $previousHash = APP_AUDIT_ZERO_HASH;
        $checked = 0;
        $cursor = 0;
        $lastId = null;
        $brokenId = null;

        $batch = $db->prepare(
            'SELECT * FROM security_audit_ledger
              WHERE id > :cursor
              ORDER BY id ASC
              LIMIT ' . APP_AUDIT_VERIFY_BATCH_SIZE
        );
        do {
            $batch->execute(['cursor' => $cursor]);
            $batchCount = 0;
            while ($row = $batch->fetch(PDO::FETCH_ASSOC)) {
                $batchCount++;
                $checked++;
                $lastId = (int) $row['id'];
                $expected = hash(
                    'sha256',
                    $previousHash . '|' . app_audit_canonical_json(app_audit_ledger_payload($row))
                );
                if (!hash_equals($previousHash, (string) $row['previous_hash'])
                    || !hash_equals($expected, (string) $row['event_hash'])
                ) {
                    $brokenId = $lastId;
                    break 2;
                }
                $previousHash = (string) $row['event_hash'];
                $cursor = $lastId;
            }
        } while ($batchCount === APP_AUDIT_VERIFY_BATCH_SIZE);

        if ($brokenId === null
            && (!hash_equals($previousHash, $snapshotHash) || $lastId !== $snapshotLastId)
        ) {
            $brokenId = $lastId ?? $snapshotLastId ?? 0;
        }
        $result = [
            'ok' => $brokenId === null,
            'broken_id' => $brokenId,
            'checked' => $checked,
        ];

        if ($ownsTransaction) {
            $db->commit();
        }
        return $result;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function app_audit_operation_page(PDO $db, array $filters = [], int $page = 1, int $perPage = 50): array
{
    return app_audit_page($db, 'mgs_operation_actions', $filters, $page, $perPage);
}

function app_audit_ledger_page(PDO $db, array $filters = [], int $page = 1, int $perPage = 50): array
{
    return app_audit_page($db, 'security_audit_ledger', $filters, $page, $perPage);
}

function app_audit_actor_can_view(array $actor): bool
{
    return function_exists('app_portal_is_admin') && app_portal_is_admin($actor);
}

function app_audit_actor_can_manage(array $actor): bool
{
    return function_exists('app_portal_is_super_admin') && app_portal_is_super_admin($actor);
}

function app_audit_edit_visible(PDO $db, int $actionId, string $summary, string $reason, array $actor): array
{
    $summary = app_audit_nullable_text($summary, 255) ?? '';
    if ($summary === '') {
        throw new InvalidArgumentException('summary_required');
    }

    return app_audit_visible_intervention(
        $db,
        $actionId,
        'journal_edit',
        $reason,
        $actor,
        static function (PDO $db, int $actionId, int $actorId, string $now) use ($summary, $reason): void {
            $statement = $db->prepare(
                'UPDATE mgs_operation_actions
                    SET summary = :summary,
                        reason = :reason,
                        edited_by_account_id = :actor_id,
                        edited_at = :edited_at
                  WHERE id = :id'
            );
            $statement->execute([
                'id' => $actionId,
                'summary' => $summary,
                'reason' => app_audit_required_reason($reason),
                'actor_id' => $actorId > 0 ? $actorId : null,
                'edited_at' => $now,
            ]);
        }
    );
}

function app_audit_delete_visible(PDO $db, int $actionId, string $reason, array $actor): array
{
    return app_audit_visible_intervention(
        $db,
        $actionId,
        'journal_delete',
        $reason,
        $actor,
        static function (PDO $db, int $actionId, int $actorId, string $now): void {
            $statement = $db->prepare(
                'UPDATE mgs_operation_actions
                    SET is_visible = 0,
                        deleted_by_account_id = :actor_id,
                        deleted_at = :deleted_at
                  WHERE id = :id'
            );
            $statement->execute([
                'id' => $actionId,
                'actor_id' => $actorId > 0 ? $actorId : null,
                'deleted_at' => $now,
            ]);
        }
    );
}

function app_audit_visible_intervention(
    PDO $db,
    int $actionId,
    string $eventType,
    string $reason,
    array $actor,
    callable $mutate
): array {
    if ($actionId <= 0) {
        throw new InvalidArgumentException('action_id_required');
    }
    if (!app_audit_actor_can_manage($actor)) {
        throw new RuntimeException('audit_manage_forbidden');
    }
    $reason = app_audit_required_reason($reason);

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        app_audit_ensure_schema($db);
        $db->beginTransaction();
    } else {
        $savepoint = 'audit_intervention_' . bin2hex(random_bytes(5));
        $db->exec('SAVEPOINT ' . $savepoint);
    }

    try {
        $lock = $db->prepare('SELECT * FROM mgs_operation_actions WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $actionId]);
        $beforeRow = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($beforeRow)) {
            throw new RuntimeException('audit_action_not_found');
        }

        $now = date('Y-m-d H:i:s');
        $actorId = app_audit_actor_id($actor);
        $mutate($db, $actionId, $actorId, $now);

        $afterStatement = $db->prepare('SELECT * FROM mgs_operation_actions WHERE id = :id');
        $afterStatement->execute(['id' => $actionId]);
        $afterRow = $afterStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($afterRow)) {
            throw new RuntimeException('audit_action_missing_after_intervention');
        }

        $event = app_audit_record($db, [
            'module_key' => 'security',
            'operation_class' => 'journal_intervention',
            'action_type' => $eventType,
            'entity_type' => 'mgs_operation_action',
            'entity_id' => (string) $actionId,
            'status' => 'executed',
            'actor_account_id' => $actorId > 0 ? $actorId : null,
            'actor_name' => app_audit_actor_name($actor),
            'summary' => $eventType === 'journal_delete'
                ? 'Visible audit journal event hidden'
                : 'Visible audit journal event edited',
            'reason' => $reason,
            'before' => app_audit_operation_snapshot($beforeRow),
            'after' => app_audit_operation_snapshot($afterRow),
            'sensitivity' => 'critical',
            'is_visible' => false,
        ]);

        if ($ownsTransaction) {
            $db->commit();
        } else {
            $db->exec('RELEASE SAVEPOINT ' . $savepoint);
        }

        return [
            'ok' => true,
            'action_id' => $actionId,
            'audit_action_id' => (int) ($event['action_id'] ?? 0),
            'ledger_id' => (int) ($event['ledger_id'] ?? 0),
        ];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        } elseif (!$ownsTransaction && isset($savepoint) && $db->inTransaction()) {
            try {
                $db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $db->exec('RELEASE SAVEPOINT ' . $savepoint);
            } catch (Throwable) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
            }
        }
        throw $exception;
    }
}

function app_audit_required_reason(string $reason): string
{
    $reason = app_audit_nullable_text($reason, 255) ?? '';
    if ($reason === '') {
        throw new InvalidArgumentException('reason_required');
    }
    return $reason;
}

function app_audit_actor_id(array $actor): int
{
    return max(0, (int) ($actor['id'] ?? $actor['account_id'] ?? $actor['actor_account_id'] ?? 0));
}

function app_audit_actor_name(array $actor): string
{
    return app_audit_nullable_text(
        $actor['full_name'] ?? $actor['name'] ?? $actor['login'] ?? 'system',
        191
    ) ?? 'system';
}

function app_audit_operation_snapshot(array $row): array
{
    $snapshot = $row;
    foreach (['before_json' => 'before', 'after_json' => 'after', 'payload_json' => 'payload'] as $jsonColumn => $targetKey) {
        if (array_key_exists($jsonColumn, $snapshot)) {
            $raw = $snapshot[$jsonColumn];
            $snapshot[$targetKey] = ($raw === null || $raw === '')
                ? null
                : json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            unset($snapshot[$jsonColumn]);
        }
    }
    return $snapshot;
}

function app_audit_nullable_text(mixed $value, ?int $maxLength = null): ?string
{
    if ($value === null) {
        return null;
    }
    $text = trim(app_audit_sanitize_string((string) $value));
    if ($text === '') {
        return null;
    }
    return $maxLength === null ? $text : substr($text, 0, $maxLength);
}

function app_audit_ledger_payload(array $row): array
{
    $decode = static function (mixed $value): mixed {
        if ($value === null || $value === '') {
            return null;
        }
        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    };

    return [
        'action_id' => (int) $row['action_id'],
        'module_key' => (string) $row['module_key'],
        'operation_class' => (string) $row['operation_class'],
        'action_type' => (string) $row['action_type'],
        'entity_type' => (string) $row['entity_type'],
        'entity_id' => (string) $row['entity_id'],
        'status' => (string) $row['status'],
        'actor_account_id' => $row['actor_account_id'] === null ? null : (int) $row['actor_account_id'],
        'actor_name' => $row['actor_name'] === null ? null : (string) $row['actor_name'],
        'summary' => $row['summary'] === null ? null : (string) $row['summary'],
        'ticket_no' => $row['ticket_no'] === null ? null : (string) $row['ticket_no'],
        'reason' => $row['reason'] === null ? null : (string) $row['reason'],
        'before' => $decode($row['before_json']),
        'after' => $decode($row['after_json']),
        'payload' => $decode($row['payload_json']),
        'request_ip' => $row['request_ip'] === null ? null : (string) $row['request_ip'],
        'user_agent' => $row['user_agent'] === null ? null : (string) $row['user_agent'],
        'request_id' => $row['request_id'] === null ? null : (string) $row['request_id'],
        'sensitivity' => (string) $row['sensitivity'],
        'is_visible' => (int) $row['is_visible'],
        'created_at' => (string) $row['created_at'],
    ];
}

function app_audit_page(PDO $db, string $table, array $filters, int $page, int $perPage): array
{
    if (!in_array($table, ['mgs_operation_actions', 'security_audit_ledger'], true)) {
        throw new InvalidArgumentException('Unsupported audit page table.');
    }

    $perPage = max(1, min(50, $perPage));
    $where = [];
    $params = [];
    $addExact = static function (string $filter, string $column) use (&$where, &$params, $filters): void {
        $value = trim((string) ($filters[$filter] ?? ''));
        if ($value !== '') {
            $where[] = $column . ' = :' . $filter;
            $params[$filter] = $value;
        }
    };
    $addExact('module', 'module_key');
    $addExact('action', 'action_type');
    $addExact('status', 'status');

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $where[] = 'created_at >= :date_from';
        $params['date_from'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom . ' 00:00:00' : $dateFrom;
    }
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateTo !== '') {
        $where[] = 'created_at <= :date_to';
        $params['date_to'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo . ' 23:59:59' : $dateTo;
    }

    $actor = trim((string) ($filters['actor'] ?? ''));
    if ($actor !== '') {
        $where[] = '(actor_name LIKE :actor OR CAST(actor_account_id AS CHAR) = :actor_id)';
        $params['actor'] = '%' . $actor . '%';
        $params['actor_id'] = $actor;
    }
    $entity = trim((string) ($filters['entity'] ?? ''));
    if ($entity !== '') {
        $where[] = '(entity_type = :entity OR entity_id = :entity OR CONCAT(entity_type, ":", entity_id) = :entity)';
        $params['entity'] = $entity;
    }
    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(summary LIKE :search OR reason LIKE :search OR actor_name LIKE :search
                     OR entity_id LIKE :search OR request_id LIKE :search OR action_type LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $count = $db->prepare('SELECT COUNT(*) FROM ' . $table . $whereSql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($pages, $page));
    $offset = ($page - 1) * $perPage;

    $rows = $db->prepare(
        'SELECT * FROM ' . $table . $whereSql
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $rows->execute($params);

    return [
        'rows' => $rows->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $pages,
    ];
}
