<?php
declare(strict_types=1);

$library = dirname(__DIR__) . '/audit-log-lib.php';
if (!is_file($library)) {
    fwrite(STDERR, "RED: audit-log-lib.php is missing\n");
    exit(1);
}

require dirname(__DIR__) . '/config.php';
require_once $library;

$errors = [];
$librarySource = (string) file_get_contents($library);
$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};
$smokeCanonicalJson = static function (mixed $value): string {
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
        foreach ($item as $key => $nested) {
            $item[$key] = $normalize($nested);
        }
        return $item;
    };

    return json_encode(
        $normalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    );
};

foreach ([
    'app_audit_ensure_schema',
    'app_audit_sanitize',
    'app_audit_canonical_json',
    'app_audit_request_context',
    'app_audit_record',
    'app_audit_verify_chain',
    'app_audit_operation_page',
    'app_audit_ledger_page',
] as $functionName) {
    $expect(function_exists($functionName), $functionName . '_missing');
}

$sanitized = app_audit_sanitize([
    'name' => 'Audit Smoke',
    'password' => 'plain-secret',
    'nested' => [
        'api_key' => 'api-secret',
        'access_token' => 'access-secret',
        'clientSecret' => 'client-secret',
        'password_confirmation' => 'confirm-secret',
        'items' => [[
            'authorization' => 'Bearer secret',
            'request_url' => 'https://audit.test/callback?token=url-secret&safe=yes',
            'note' => 'Authorization: Basic dXNlcjpwYXNz password=note-secret',
        ]],
        'device_access' => [
            'private_key' => 'private-key-secret',
            'public_key' => 'public-key-material',
            'snmp_community' => 'snmp-secret',
            'community_string' => 'community-secret',
            'credentials' => 'device-login-secret',
            'client_certificate' => 'certificate-secret',
            'client_key' => 'client-key-secret',
            'psk' => 'psk-secret',
            'pre_shared_key' => 'shared-key-secret',
            'note' => 'snmp_community=free-snmp credentials=free-login private_key=free-key psk=free-psk',
        ],
    ],
]);
$expect(($sanitized['password'] ?? null) === '[REDACTED]', 'password_not_redacted');
$expect(($sanitized['nested']['api_key'] ?? null) === '[REDACTED]', 'api_key_not_redacted');
$expect(($sanitized['nested']['access_token'] ?? null) === '[REDACTED]', 'access_token_not_redacted');
$expect(($sanitized['nested']['clientSecret'] ?? null) === '[REDACTED]', 'client_secret_not_redacted');
$expect(($sanitized['nested']['password_confirmation'] ?? null) === '[REDACTED]', 'password_confirmation_not_redacted');
$expect(($sanitized['nested']['items'][0]['authorization'] ?? null) === '[REDACTED]', 'authorization_not_redacted');
$expect(
    !str_contains((string) ($sanitized['nested']['items'][0]['request_url'] ?? ''), 'url-secret'),
    'query_url_token_leaked'
);
$expect(
    !str_contains((string) ($sanitized['nested']['items'][0]['note'] ?? ''), 'dXNlcjpwYXNz')
        && !str_contains((string) ($sanitized['nested']['items'][0]['note'] ?? ''), 'note-secret'),
    'free_text_secret_leaked'
);
foreach ([
    'private_key', 'public_key', 'snmp_community', 'community_string', 'credentials',
    'client_certificate', 'client_key', 'psk', 'pre_shared_key',
] as $credentialKey) {
    $expect(
        ($sanitized['nested']['device_access'][$credentialKey] ?? null) === '[REDACTED]',
        'telecom_credential_not_redacted:' . $credentialKey
    );
}
$telecomNote = (string) ($sanitized['nested']['device_access']['note'] ?? '');
foreach (['free-snmp', 'free-login', 'free-key', 'free-psk'] as $secretValue) {
    $expect(!str_contains($telecomNote, $secretValue), 'telecom_free_text_secret_leaked:' . $secretValue);
}
$expect(
    app_audit_canonical_json(['z' => 1, 'a' => ['y' => 2, 'b' => 3]])
        === '{"a":{"b":3,"y":2},"z":1}',
    'canonical_json_order_failed'
);

$context = app_audit_request_context([
    'request_ip' => '203.0.113.25',
    'user_agent' => 'audit-smoke/1.0 Bearer user-agent-secret',
    'request_id' => 'audit-smoke-request?token=request-secret',
]);
$expect(($context['request_ip'] ?? '') === '203.0.113.25', 'request_ip_override_failed');
$expect(!str_contains((string) ($context['user_agent'] ?? ''), 'user-agent-secret'), 'user_agent_secret_leaked');
$expect(!str_contains((string) ($context['request_id'] ?? ''), 'request-secret'), 'request_id_secret_leaked');

foreach ([
    'GET_LOCK(' => 'migration_get_lock_missing',
    'RELEASE_LOCK(' => 'migration_release_lock_missing',
    'APP_AUDIT_SCHEMA_VERSION' => 'migration_version_constant_missing',
    'LOCK IN SHARE MODE' => 'verification_chain_lock_missing',
    'id > :cursor' => 'verification_keyset_cursor_missing',
    'APP_AUDIT_VERIFY_BATCH_SIZE' => 'verification_batch_bound_missing',
    'Audit savepoint rollback failed' => 'savepoint_rollback_failure_escalation_missing',
    'COLUMN_TYPE' => 'schema_column_type_validation_missing',
    'IS_NULLABLE' => 'schema_nullability_validation_missing',
    'COLUMN_DEFAULT' => 'schema_default_validation_missing',
    'EXTRA' => 'schema_extra_validation_missing',
    'INFORMATION_SCHEMA.STATISTICS' => 'schema_index_validation_missing',
    'INFORMATION_SCHEMA.CHECK_CONSTRAINTS' => 'schema_check_clause_validation_missing',
    'Audit migration requires destructive repair' => 'schema_destructive_drift_error_missing',
] as $sourceMarker => $error) {
    $expect(str_contains($librarySource, $sourceMarker), $error);
}
$expect(
    !str_contains($librarySource, 'SELECT * FROM security_audit_ledger ORDER BY id ASC'),
    'verification_unbounded_query_present'
);

$db = app_db();
if (!$db instanceof PDO) {
    $errors[] = 'db_not_ready';
} else {
    try {
        // DDL is intentionally completed before the transactional data checks.
        $expect(app_audit_ensure_schema($db), 'schema_ensure_failed');
        $expect(app_audit_ensure_schema($db), 'schema_ensure_not_idempotent');
        $lockFree = $db->prepare('SELECT IS_FREE_LOCK(:lock_name)');
        $lockFree->execute(['lock_name' => 'superopticnet:audit-ledger-schema']);
        $expect((int) $lockFree->fetchColumn() === 1, 'migration_lock_not_released');

        $markerTable = 'security_audit_schema_migrations';
        $markerVersion = '2026-07-06-audit-ledger-v3';
        $markerCheck = $db->prepare('SHOW TABLES LIKE :table_name');
        $markerCheck->execute(['table_name' => $markerTable]);
        $markerExists = (bool) $markerCheck->fetchColumn();
        $expect($markerExists, 'migration_marker_table_missing');
        if ($markerExists) {
            $db->prepare('DELETE FROM security_audit_schema_migrations WHERE version_key = :version_key')
                ->execute(['version_key' => $markerVersion]);
            $expect(app_audit_ensure_schema($db), 'partial_migration_retry_failed');
            $versionCheck = $db->prepare(
                'SELECT COUNT(*) FROM security_audit_schema_migrations WHERE version_key = :version_key'
            );
            $versionCheck->execute(['version_key' => $markerVersion]);
            $expect((int) $versionCheck->fetchColumn() === 1, 'migration_version_marker_missing');
        }

        $requiredColumns = [
            'module_key', 'operation_class', 'reason', 'before_json', 'after_json',
            'request_ip', 'user_agent', 'request_id', 'sensitivity', 'is_visible',
            'edited_by_account_id', 'edited_at', 'deleted_by_account_id', 'deleted_at',
        ];
        $columnRows = $db->query('SHOW COLUMNS FROM mgs_operation_actions')->fetchAll(PDO::FETCH_ASSOC);
        $columns = array_column($columnRows, 'Field');
        foreach ($requiredColumns as $column) {
            $expect(in_array($column, $columns, true), 'schema_column_missing:' . $column);
        }

        $columnMetadata = $db->query(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND ((TABLE_NAME = 'security_audit_ledger' AND COLUMN_NAME IN ('id','event_hash','sensitivity','is_visible'))
                  OR (TABLE_NAME = 'security_audit_chain_state' AND COLUMN_NAME IN ('id','current_hash','last_ledger_id'))
                  OR (TABLE_NAME = 'mgs_operation_actions' AND COLUMN_NAME IN ('module_key','sensitivity','is_visible')))"
        )->fetchAll(PDO::FETCH_ASSOC);
        $metadata = [];
        foreach ($columnMetadata as $column) {
            $metadata[$column['TABLE_NAME'] . '.' . $column['COLUMN_NAME']] = $column;
        }
        $ledgerIdMetadata = $metadata['security_audit_ledger.id'] ?? [];
        $eventHashMetadata = $metadata['security_audit_ledger.event_hash'] ?? [];
        $ledgerIdType = (string) preg_replace(
            '/\b(bigint|int|tinyint|smallint|mediumint)\(\d+\)/',
            '$1',
            strtolower((string) ($ledgerIdMetadata['COLUMN_TYPE'] ?? ''))
        );
        $expect($ledgerIdType === 'bigint unsigned', 'ledger_id_column_type_invalid');
        $expect(($ledgerIdMetadata['IS_NULLABLE'] ?? '') === 'NO', 'ledger_id_nullability_invalid');
        $expect(str_contains((string) ($ledgerIdMetadata['EXTRA'] ?? ''), 'auto_increment'), 'ledger_id_auto_increment_missing');
        $expect(($eventHashMetadata['COLUMN_TYPE'] ?? '') === 'char(64)', 'event_hash_length_invalid');
        $expect(($eventHashMetadata['IS_NULLABLE'] ?? '') === 'NO', 'event_hash_nullability_invalid');
        $expect(
            trim((string) ($metadata['security_audit_ledger.sensitivity']['COLUMN_DEFAULT'] ?? ''), "'") === 'normal',
            'ledger_sensitivity_default_invalid'
        );
        $expect((string) ($metadata['security_audit_ledger.is_visible']['COLUMN_DEFAULT'] ?? '') === '1', 'ledger_visibility_default_invalid');
        $expect(($metadata['mgs_operation_actions.module_key']['IS_NULLABLE'] ?? '') === 'NO', 'operation_module_nullability_invalid');
        $expect(
            trim((string) ($metadata['mgs_operation_actions.module_key']['COLUMN_DEFAULT'] ?? ''), "'") === '',
            'operation_module_default_invalid'
        );
        $expect(($metadata['security_audit_chain_state.current_hash']['COLUMN_TYPE'] ?? '') === 'char(64)', 'chain_hash_length_invalid');

        $eventHashIndex = $db->query(
            "SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
               FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'security_audit_ledger'
                AND INDEX_NAME = 'uq_security_audit_hash'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $expect(
            count($eventHashIndex) === 1
                && (int) $eventHashIndex[0]['NON_UNIQUE'] === 0
                && (int) $eventHashIndex[0]['SEQ_IN_INDEX'] === 1
                && $eventHashIndex[0]['COLUMN_NAME'] === 'event_hash',
            'event_hash_unique_index_invalid'
        );
        $chainPrimary = $db->query(
            "SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
               FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'security_audit_chain_state'
                AND INDEX_NAME = 'PRIMARY'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $expect(
            count($chainPrimary) === 1
                && (int) $chainPrimary[0]['NON_UNIQUE'] === 0
                && (int) $chainPrimary[0]['SEQ_IN_INDEX'] === 1
                && $chainPrimary[0]['COLUMN_NAME'] === 'id',
            'chain_state_primary_key_invalid'
        );
        $chainCheck = (string) $db->query(
            "SELECT cc.CHECK_CLAUSE
               FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
               JOIN INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc
                 ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
              WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
                AND tc.TABLE_NAME = 'security_audit_chain_state'
                AND tc.CONSTRAINT_NAME = 'chk_security_audit_chain_singleton'
                AND tc.CONSTRAINT_TYPE = 'CHECK'"
        )->fetchColumn();
        $expect(
            strtolower(str_replace(['`', ' ', '(', ')'], '', $chainCheck)) === 'id=1',
            'chain_state_check_clause_invalid'
        );

        foreach (['mgs_operation_actions', 'security_audit_ledger', 'security_audit_chain_state'] as $table) {
            $statement = $db->prepare('SHOW TABLES LIKE :table_name');
            $statement->execute(['table_name' => $table]);
            $expect((bool) $statement->fetchColumn(), 'schema_table_missing:' . $table);

            $engine = $db->prepare(
                'SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
            );
            $engine->execute(['table_name' => $table]);
            $expect(strcasecmp((string) $engine->fetchColumn(), 'InnoDB') === 0, 'schema_engine_invalid:' . $table);
        }

        $triggerStatement = $db->query(
            "SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
               FROM INFORMATION_SCHEMA.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE()
                AND EVENT_OBJECT_TABLE = 'security_audit_ledger'"
        );
        $triggers = [];
        foreach ($triggerStatement->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
            $triggers[(string) $trigger['TRIGGER_NAME']] = $trigger;
        }
        foreach (['security_audit_ledger_bu' => 'UPDATE', 'security_audit_ledger_bd' => 'DELETE'] as $name => $event) {
            $trigger = $triggers[$name] ?? [];
            $action = strtolower((string) ($trigger['ACTION_STATEMENT'] ?? ''));
            $expect(($trigger['ACTION_TIMING'] ?? '') === 'BEFORE', 'trigger_timing_invalid:' . $name);
            $expect(($trigger['EVENT_MANIPULATION'] ?? '') === $event, 'trigger_event_invalid:' . $name);
            $expect(
                str_contains($action, "signal sqlstate '45000'")
                    && str_contains($action, 'security_audit_ledger is immutable'),
                'trigger_action_invalid:' . $name
            );
        }

        $db->exec('DROP TRIGGER security_audit_ledger_bu');
        $db->exec(
            "CREATE TRIGGER security_audit_ledger_bu BEFORE UPDATE ON security_audit_ledger
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'drifted audit trigger'"
        );
        $expect(app_audit_ensure_schema($db, true), 'trigger_drift_repair_schema_failed');
        $repairedAction = (string) $db->query(
            "SELECT ACTION_STATEMENT FROM INFORMATION_SCHEMA.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'security_audit_ledger_bu'"
        )->fetchColumn();
        $expect(
            str_contains(strtolower($repairedAction), 'security_audit_ledger is immutable'),
            'trigger_action_drift_not_repaired'
        );

        $singletonInsertBlocked = false;
        $singletonUpdateBlocked = false;
        $db->beginTransaction();
        try {
            $statement = $db->prepare(
                'INSERT INTO security_audit_chain_state (id, current_hash, last_ledger_id)
                 VALUES (2, :zero_hash, NULL)'
            );
            $statement->execute(['zero_hash' => str_repeat('0', 64)]);
        } catch (PDOException) {
            $singletonInsertBlocked = true;
        }
        try {
            $db->exec('UPDATE security_audit_chain_state SET id = 2 WHERE id = 1');
        } catch (PDOException) {
            $singletonUpdateBlocked = true;
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
        $expect($singletonInsertBlocked, 'chain_singleton_insert_guard_missing');
        $expect($singletonUpdateBlocked, 'chain_singleton_update_guard_missing');

        $guarded = false;
        try {
            app_audit_record($db, [
                'module_key' => 'smoke',
                'operation_class' => 'security',
                'action_type' => 'transaction_guard_probe',
                'status' => 'executed',
                'sensitivity' => 'sensitive',
            ]);
        } catch (LogicException) {
            $guarded = true;
        }
        $expect($guarded, 'executed_sensitive_event_allowed_without_transaction');

        $chainBefore = $db->query(
            'SELECT current_hash, last_ledger_id FROM security_audit_chain_state WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $token = bin2hex(random_bytes(5));
        $entityId = 'AUDIT-SMOKE-' . $token . '?api_key=entity-secret';
        $storedEntityId = 'AUDIT-SMOKE-' . $token . '?api_key=[REDACTED]';
        $db->beginTransaction();
        try {
            $result = app_audit_record($db, [
                'module_key' => 'security',
                'operation_class' => 'protected_write',
                'action_type' => 'audit_smoke_record',
                'entity_type' => 'audit_test',
                'entity_id' => $entityId,
                'status' => 'executed',
                'actor_account_id' => 987654,
                'actor_name' => 'Audit Smoke token=actor-secret',
                'summary' => 'Protected audit smoke ' . $token . ' Bearer summary-secret',
                'reason' => 'Verify ledger password=reason-secret client_secret=reason-client',
                'before' => ['password' => 'before-secret', 'value' => 1],
                'after' => ['token' => 'after-secret', 'value' => 2],
                'payload' => [
                    'cookie' => 'session-secret',
                    'safe' => 'visible',
                    'request_url' => 'https://audit.test/run?access_token=payload-secret&safe=1',
                ],
                'context' => $context,
                'sensitivity' => 'sensitive',
                'is_visible' => true,
            ]);

            $expect(($result['ok'] ?? false) === true, 'record_not_ok');
            $expect((int) ($result['action_id'] ?? 0) > 0, 'action_id_missing');
            $expect((int) ($result['ledger_id'] ?? 0) > 0, 'ledger_id_missing');
            $expect(strlen((string) ($result['event_hash'] ?? '')) === 64, 'event_hash_not_64_chars');

            $actionStatement = $db->prepare('SELECT * FROM mgs_operation_actions WHERE id = :id');
            $actionStatement->execute(['id' => (int) $result['action_id']]);
            $action = $actionStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $before = json_decode((string) ($action['before_json'] ?? ''), true);
            $after = json_decode((string) ($action['after_json'] ?? ''), true);
            $payload = json_decode((string) ($action['payload_json'] ?? ''), true);
            $expect(($before['password'] ?? null) === '[REDACTED]', 'stored_before_secret_not_redacted');
            $expect(($after['token'] ?? null) === '[REDACTED]', 'stored_after_secret_not_redacted');
            $expect(($payload['cookie'] ?? null) === '[REDACTED]', 'stored_payload_secret_not_redacted');
            $expect(($payload['safe'] ?? null) === 'visible', 'stored_safe_payload_changed');
            $expect(!str_contains((string) ($payload['request_url'] ?? ''), 'payload-secret'), 'stored_url_secret_leaked');
            $expect(($action['entity_id'] ?? '') === $storedEntityId, 'stored_entity_secret_not_redacted');
            $expect(!str_contains((string) ($action['actor_name'] ?? ''), 'actor-secret'), 'stored_actor_secret_leaked');
            $expect(!str_contains((string) ($action['summary'] ?? ''), 'summary-secret'), 'stored_summary_secret_leaked');
            $expect(
                !str_contains((string) ($action['reason'] ?? ''), 'reason-secret')
                    && !str_contains((string) ($action['reason'] ?? ''), 'reason-client'),
                'stored_reason_secret_leaked'
            );
            $expect(($action['request_ip'] ?? '') === '203.0.113.25', 'stored_request_ip_mismatch');
            $expect(!str_contains((string) ($action['user_agent'] ?? ''), 'user-agent-secret'), 'stored_user_agent_secret_leaked');
            $expect(!str_contains((string) ($action['request_id'] ?? ''), 'request-secret'), 'stored_request_id_secret_leaked');

            $ledgerStatement = $db->prepare('SELECT * FROM security_audit_ledger WHERE id = :id');
            $ledgerStatement->execute(['id' => (int) $result['ledger_id']]);
            $ledger = $ledgerStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $decodeJson = static function (mixed $value): mixed {
                if ($value === null || $value === '') {
                    return null;
                }
                return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
            };
            $storedEvent = [
                'action_id' => (int) ($ledger['action_id'] ?? 0),
                'module_key' => (string) ($ledger['module_key'] ?? ''),
                'operation_class' => (string) ($ledger['operation_class'] ?? ''),
                'action_type' => (string) ($ledger['action_type'] ?? ''),
                'entity_type' => (string) ($ledger['entity_type'] ?? ''),
                'entity_id' => (string) ($ledger['entity_id'] ?? ''),
                'status' => (string) ($ledger['status'] ?? ''),
                'actor_account_id' => ($ledger['actor_account_id'] ?? null) === null
                    ? null
                    : (int) $ledger['actor_account_id'],
                'actor_name' => ($ledger['actor_name'] ?? null) === null ? null : (string) $ledger['actor_name'],
                'summary' => ($ledger['summary'] ?? null) === null ? null : (string) $ledger['summary'],
                'ticket_no' => ($ledger['ticket_no'] ?? null) === null ? null : (string) $ledger['ticket_no'],
                'reason' => ($ledger['reason'] ?? null) === null ? null : (string) $ledger['reason'],
                'before' => $decodeJson($ledger['before_json'] ?? null),
                'after' => $decodeJson($ledger['after_json'] ?? null),
                'payload' => $decodeJson($ledger['payload_json'] ?? null),
                'request_ip' => ($ledger['request_ip'] ?? null) === null ? null : (string) $ledger['request_ip'],
                'user_agent' => ($ledger['user_agent'] ?? null) === null ? null : (string) $ledger['user_agent'],
                'request_id' => ($ledger['request_id'] ?? null) === null ? null : (string) $ledger['request_id'],
                'sensitivity' => (string) ($ledger['sensitivity'] ?? ''),
                'is_visible' => (int) ($ledger['is_visible'] ?? 0),
                'created_at' => (string) ($ledger['created_at'] ?? ''),
            ];
            $independentHash = hash(
                'sha256',
                (string) ($ledger['previous_hash'] ?? '') . '|' . $smokeCanonicalJson($storedEvent)
            );
            $expect(
                hash_equals((string) ($ledger['event_hash'] ?? ''), $independentHash),
                'independent_event_hash_recompute_failed'
            );

            $verification = app_audit_verify_chain($db);
            $expect(($verification['ok'] ?? false) === true, 'chain_verification_failed');
            $expect(($verification['broken_id'] ?? null) === null, 'chain_broken_id_present');
            $expect((int) ($verification['checked'] ?? 0) >= 1, 'chain_checked_count_invalid');

            $untrackedEvent = $storedEvent;
            $untrackedEvent['action_id'] = 900000000000000 + random_int(100000, 999999);
            $untrackedEvent['action_type'] = 'audit_untracked_probe';
            $untrackedEvent['entity_id'] = 'AUDIT-UNTRACKED-' . $token;
            $untrackedEvent['summary'] = 'Untracked audit continuation';
            $untrackedEvent['request_id'] = 'audit-untracked-' . $token;
            $untrackedEvent['created_at'] = date('Y-m-d H:i:s');
            $untrackedHash = hash(
                'sha256',
                (string) $ledger['event_hash'] . '|' . $smokeCanonicalJson($untrackedEvent)
            );
            $untrackedInsert = $db->prepare(
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
            $untrackedInsert->execute([
                'action_id' => $untrackedEvent['action_id'],
                'module_key' => $untrackedEvent['module_key'],
                'operation_class' => $untrackedEvent['operation_class'],
                'action_type' => $untrackedEvent['action_type'],
                'entity_type' => $untrackedEvent['entity_type'],
                'entity_id' => $untrackedEvent['entity_id'],
                'status' => $untrackedEvent['status'],
                'actor_account_id' => $untrackedEvent['actor_account_id'],
                'actor_name' => $untrackedEvent['actor_name'],
                'summary' => $untrackedEvent['summary'],
                'ticket_no' => $untrackedEvent['ticket_no'],
                'reason' => $untrackedEvent['reason'],
                'before_json' => $ledger['before_json'],
                'after_json' => $ledger['after_json'],
                'payload_json' => $ledger['payload_json'],
                'request_ip' => $untrackedEvent['request_ip'],
                'user_agent' => $untrackedEvent['user_agent'],
                'request_id' => $untrackedEvent['request_id'],
                'sensitivity' => $untrackedEvent['sensitivity'],
                'is_visible' => $untrackedEvent['is_visible'],
                'previous_hash' => $ledger['event_hash'],
                'event_hash' => $untrackedHash,
                'created_at' => $untrackedEvent['created_at'],
            ]);
            $untrackedLedgerId = (int) $db->lastInsertId();
            $untrackedVerification = app_audit_verify_chain($db);
            $expect(($untrackedVerification['ok'] ?? true) === false, 'untracked_ledger_row_not_detected');
            $expect(
                (int) ($untrackedVerification['broken_id'] ?? 0) === $untrackedLedgerId,
                'untracked_ledger_broken_id_invalid'
            );

            $operationPage = app_audit_operation_page($db, [
                'date_from' => date('Y-m-d'),
                'date_to' => date('Y-m-d'),
                'actor' => 'Audit Smoke',
                'module' => 'security',
                'action' => 'audit_smoke_record',
                'status' => 'executed',
                'entity' => $storedEntityId,
                'search' => $token,
            ], -10, 500);
            $expect(($operationPage['page'] ?? null) === 1, 'operation_page_not_clamped_low');
            $expect(($operationPage['per_page'] ?? null) === 50, 'operation_page_cap_failed');
            $expect(count($operationPage['rows'] ?? []) === 1, 'operation_filters_failed');

            $ledgerPage = app_audit_ledger_page($db, [
                'actor' => 'Audit Smoke',
                'module' => 'security',
                'action' => 'audit_smoke_record',
                'status' => 'executed',
                'entity' => $storedEntityId,
                'search' => $token,
            ], PHP_INT_MAX, 500);
            $expect(($ledgerPage['page'] ?? null) === 1, 'ledger_page_not_clamped_high');
            $expect(($ledgerPage['per_page'] ?? null) === 50, 'ledger_page_cap_failed');
            $expect(count($ledgerPage['rows'] ?? []) === 1, 'ledger_filters_failed');

            foreach (['UPDATE security_audit_ledger SET summary = summary WHERE id = :id',
                       'DELETE FROM security_audit_ledger WHERE id = :id'] as $sql) {
                $blocked = false;
                try {
                    $statement = $db->prepare($sql);
                    $statement->execute(['id' => (int) $result['ledger_id']]);
                } catch (PDOException $exception) {
                    $blocked = $exception->getCode() === '45000'
                        || str_contains($exception->getMessage(), 'immutable');
                }
                $expect($blocked, 'ledger_mutation_not_blocked');
            }
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }

        $actionCleanup = $db->prepare(
            'SELECT COUNT(*) FROM mgs_operation_actions
              WHERE action_type = "audit_smoke_record" AND entity_id = :entity_id'
        );
        $actionCleanup->execute(['entity_id' => $storedEntityId]);
        $expect((int) $actionCleanup->fetchColumn() === 0, 'rollback_left_smoke_operational_row');

        $ledgerCleanup = $db->prepare(
            'SELECT COUNT(*) FROM security_audit_ledger
              WHERE action_type = "audit_smoke_record" AND entity_id = :entity_id'
        );
        $ledgerCleanup->execute(['entity_id' => $storedEntityId]);
        $expect((int) $ledgerCleanup->fetchColumn() === 0, 'rollback_left_smoke_ledger_row');
        $untrackedCleanup = $db->prepare(
            'SELECT COUNT(*) FROM security_audit_ledger
              WHERE action_type = "audit_untracked_probe" AND entity_id = :entity_id'
        );
        $untrackedCleanup->execute(['entity_id' => 'AUDIT-UNTRACKED-' . $token]);
        $expect((int) $untrackedCleanup->fetchColumn() === 0, 'rollback_left_untracked_ledger_row');

        $chainAfter = $db->query(
            'SELECT current_hash, last_ledger_id FROM security_audit_chain_state WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $beforeLedgerId = ($chainBefore['last_ledger_id'] ?? null) === null
            ? null
            : (int) $chainBefore['last_ledger_id'];
        $afterLedgerId = ($chainAfter['last_ledger_id'] ?? null) === null
            ? null
            : (int) $chainAfter['last_ledger_id'];
        $expect(
            (string) ($chainAfter['current_hash'] ?? '') === (string) ($chainBefore['current_hash'] ?? '')
                && $afterLedgerId === $beforeLedgerId,
            'rollback_did_not_restore_chain_state'
        );

        $standaloneVerification = app_audit_verify_chain($db);
        $expect(($standaloneVerification['ok'] ?? false) === true, 'standalone_chain_verification_failed');
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $errors[] = 'exception:' . $exception->getMessage();
    }
}

echo json_encode([
    'ok' => $errors === [],
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === [] ? 0 : 1);
