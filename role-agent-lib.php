<?php
declare(strict_types=1);

require_once __DIR__ . '/mgs-integration-lib.php';

function app_role_agent_password(): string
{
    return 'AgentTest!2026';
}

function app_role_agent_definitions(): array
{
    return [
        'operator' => [
            'login' => 'agent.operator',
            'full_name' => 'Role Agent Operator',
            'account_type' => 'operator',
            'employee_role' => 'operator',
            'phone' => '+994501001001',
            'telegram_chat_id' => 'role-agent-operator',
        ],
        'technician' => [
            'login' => 'agent.texnik',
            'full_name' => 'Role Agent Texnik',
            'account_type' => 'technician',
            'employee_role' => 'technician',
            'phone' => '+994501001002',
            'telegram_chat_id' => 'role-agent-texnik',
        ],
        'noc_engineer' => [
            'login' => 'agent.noc',
            'full_name' => 'Role Agent NOC Muhendisi',
            'account_type' => 'admin',
            'employee_role' => 'noc',
            'phone' => '+994501001003',
            'telegram_chat_id' => 'role-agent-noc',
        ],
        'financier' => [
            'login' => 'agent.finance',
            'full_name' => 'Role Agent Maliyeci',
            'account_type' => 'operator',
            'employee_role' => 'finance',
            'phone' => '+994501001004',
            'telegram_chat_id' => 'role-agent-finance',
        ],
        'warehouse' => [
            'login' => 'agent.warehouse',
            'full_name' => 'Role Agent Anbardar',
            'account_type' => 'operator',
            'employee_role' => 'warehouse',
            'phone' => '+994501001005',
            'telegram_chat_id' => 'role-agent-warehouse',
        ],
        'super_admin' => [
            'login' => 'agent.superadmin',
            'full_name' => 'Role Agent Super Admin',
            'account_type' => 'admin',
            'employee_role' => 'admin',
            'phone' => '+994501001006',
            'telegram_chat_id' => 'role-agent-superadmin',
        ],
    ];
}

function app_role_agent_assert(array &$failures, bool $condition, string $message, array $context = []): void
{
    if ($condition) {
        return;
    }

    $failures[] = [
        'message' => $message,
        'context' => $context,
    ];
}

function app_role_agent_ensure_employee(string $key, array $definition): ?array
{
    $payload = [
        'role' => 'employee',
        'account_type' => (string) $definition['account_type'],
        'employee_role' => (string) $definition['employee_role'],
        'login' => (string) $definition['login'],
        'password' => app_role_agent_password(),
        'full_name' => (string) $definition['full_name'],
        'email' => (string) $definition['login'] . '@superoptic.local',
        'phone' => (string) $definition['phone'],
        'category' => 'Role Agent QA',
        'area' => 'Role QA',
        'address' => 'Role based E2E test employee',
        'status' => 'Aktiv',
        'is_active' => 1,
        'responsible_areas' => ['Role QA'],
        'payload' => [
            'employee' => [
                'role_agent_key' => $key,
                'telegram_chat_id' => (string) $definition['telegram_chat_id'],
                'superpilot_enabled' => true,
                'permissions_note' => 'Role E2E agent for local production-readiness tests',
            ],
        ],
    ];

    return app_db_upsert_account($payload, false);
}

function app_role_agent_login_ok(string $login): bool
{
    $user = app_portal_login('employee', $login, app_role_agent_password());

    return is_array($user)
        && (string) ($user['role'] ?? '') === 'employee'
        && (string) ($user['login'] ?? '') === $login;
}

function app_role_agent_default_tariff(PDO $db): array
{
    $row = $db->query(
        "SELECT * FROM billing_tariffs
         WHERE status = 'active'
         ORDER BY service_type = 'pppoe' DESC, monthly_fee ASC, id ASC
         LIMIT 1"
    )->fetch();
    if (is_array($row)) {
        return $row;
    }

    $db->exec(
        "INSERT IGNORE INTO billing_tariffs
            (external_id, name, service_type, download_mbps, upload_mbps, monthly_fee, radius_group, status)
         VALUES
            ('role-agent-test-tariff', 'Role Agent Test 100M', 'pppoe', 100, 100, 25.00, 'ROLE_AGENT_TEST_100M', 'active')"
    );

    $row = $db->query("SELECT * FROM billing_tariffs WHERE external_id = 'role-agent-test-tariff' LIMIT 1")->fetch();
    return is_array($row) ? $row : [
        'name' => 'Role Agent Test 100M',
        'service_type' => 'pppoe',
        'monthly_fee' => '25.00',
        'radius_group' => 'ROLE_AGENT_TEST_100M',
    ];
}

function app_role_agent_reset_test_customer(PDO $db, string $subscriberCode, string $login): void
{
    foreach ([$login, $subscriberCode] as $username) {
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            try {
                $statement = $db->prepare("DELETE FROM {$table} WHERE username = :username");
                $statement->execute(['username' => $username]);
            } catch (Throwable) {
                // Some installs may not have all FreeRADIUS tables during early setup.
            }
        }
    }

    $existingByCode = app_db_account_by_subscriber_code($subscriberCode);
    $existingByLogin = app_db_account_by_login($login);
    foreach ([$existingByCode, $existingByLogin] as $account) {
        if (is_array($account) && (int) ($account['id'] ?? 0) > 0) {
            $accountId = (int) $account['id'];
            $db->prepare('DELETE r FROM billing_payment_reversals r JOIN billing_payment_operations o ON o.id = r.operation_id WHERE o.account_id = :id')->execute(['id' => $accountId]);
            $db->prepare('DELETE e FROM billing_payment_operation_events e JOIN billing_payment_operations o ON o.id = e.operation_id WHERE o.account_id = :id')->execute(['id' => $accountId]);
            $db->prepare('DELETE a FROM billing_payment_service_attempts a JOIN billing_payment_service_actions s ON s.id = a.service_action_id JOIN billing_payment_operations o ON o.id = s.operation_id WHERE o.account_id = :id')->execute(['id' => $accountId]);
            $db->prepare('DELETE b FROM billing_payment_outbox b JOIN billing_payment_service_actions s ON s.id = b.service_action_id JOIN billing_payment_operations o ON o.id = s.operation_id WHERE o.account_id = :id')->execute(['id' => $accountId]);
            $db->prepare('DELETE s FROM billing_payment_service_actions s JOIN billing_payment_operations o ON o.id = s.operation_id WHERE o.account_id = :id')->execute(['id' => $accountId]);
            $db->prepare('DELETE FROM billing_payment_operations WHERE account_id = :id')->execute(['id' => $accountId]);
            $db->prepare('DELETE FROM billing_payments WHERE account_id = :id')->execute(['id' => $accountId]);
            app_db_delete_account($accountId);
        }
    }
}

function app_role_agent_create_test_customer(PDO $db): array
{
    $subscriberCode = 'ROLEQA900001';
    $login = 'roleqa900001';
    app_role_agent_reset_test_customer($db, $subscriberCode, $login);

    $tariff = app_role_agent_default_tariff($db);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+15 days'));
    $password = bin2hex(random_bytes(16)) . 'Aa1!';
    $payload = [
        'profile' => [
            'full_name' => 'Role Agent Test Musteri',
            'phone' => '+994501009001',
            'address' => 'Role QA test unvani',
            'area' => 'Role QA',
        ],
        'billing' => [
            'service_type' => 'pppoe',
            'pppoe_username' => $subscriberCode,
            'pppoe_password' => $password,
            'tariff' => (string) ($tariff['name'] ?? 'Role Agent Test 100M'),
            'pool' => 'Pool 1 - Core',
            'ip_address' => '10.250.90.1',
            'status' => 'Aktiv',
            'radius_status' => 'Aktiv',
            'expires_at' => $expiresAt,
            'days_left' => 15,
            'default_days' => 30,
            'internet_monthly_fee' => number_format((float) ($tariff['monthly_fee'] ?? 25), 2, '.', '') . ' AZN',
        ],
        'internet_services' => [[
            'service_type' => 'pppoe',
            'username' => $subscriberCode,
            'password' => $password,
            'tariff' => (string) ($tariff['name'] ?? 'Role Agent Test 100M'),
            'pool' => 'Pool 1 - Core',
            'ip_address' => '10.250.90.1',
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]],
        'role_agent_test' => true,
    ];

    $account = app_db_upsert_account([
        'role' => 'customer',
        'account_type' => 'customer',
        'login' => $login,
        'password' => $password,
        'full_name' => 'Role Agent Test Musteri',
        'subscriber_code' => $subscriberCode,
        'contract_no' => 'ROLEQA-MQ-900001',
        'email' => 'roleqa900001@superoptic.local',
        'phone' => '+994501009001',
        'category' => 'Role Agent QA',
        'area' => 'Role QA',
        'address' => 'Role QA test unvani',
        'plan' => (string) ($tariff['name'] ?? 'Role Agent Test 100M'),
        'status' => 'Aktiv',
        'is_active' => 1,
        'payload' => $payload,
    ], false);

    if (is_array($account)) {
        app_billing_sync_radius_account($account, $password);
        $account = app_db_account_by_id((int) $account['id']) ?: $account;
    }

    return [
        'account' => $account,
        'password' => $password,
        'tariff' => $tariff,
        'subscriber_code' => $subscriberCode,
    ];
}

function app_role_agent_count_radius_rows(PDO $db, string $username): array
{
    $result = [];
    foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
        try {
            $statement = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE username = :username");
            $statement->execute(['username' => $username]);
            $result[$table] = (int) $statement->fetchColumn();
        } catch (Throwable) {
            $result[$table] = null;
        }
    }

    return $result;
}

function app_role_agent_inventory_report(PDO $db, string $sku): array
{
    $statement = $db->prepare(
        'SELECT i.sku, i.item_name, i.quantity_on_hand, COUNT(m.id) AS movement_count
         FROM inventory_stock_items i
         LEFT JOIN inventory_stock_movements m ON m.item_id = i.id
         WHERE i.sku = :sku
         GROUP BY i.id'
    );
    $statement->execute(['sku' => $sku]);
    $row = $statement->fetch();

    return is_array($row) ? $row : [];
}

function app_role_agent_cleanup_artifacts(PDO $db, array $artifacts): array
{
    $cleaned = [
        'customer' => false,
        'ticket' => false,
        'inventory' => false,
        'payment' => false,
    ];

    $subscriberCode = (string) ($artifacts['customer']['subscriber_code'] ?? '');
    $customerId = (int) ($artifacts['customer']['id'] ?? 0);
    $ticketNo = (string) ($artifacts['ticket_no'] ?? '');
    $sku = (string) ($artifacts['inventory_sku'] ?? '');
    $externalId = (string) ($artifacts['payment_external_id'] ?? '');

    if ($externalId !== '') {
        try {
            $statement = $db->prepare('DELETE FROM billing_payments WHERE external_id = :external_id');
            $cleaned['payment'] = $statement->execute(['external_id' => $externalId]);
        } catch (Throwable) {
            $cleaned['payment'] = false;
        }
    }

    if ($ticketNo !== '') {
        try {
            $ticket = app_db_ticket_find($ticketNo);
            if (is_array($ticket) && (int) ($ticket['id'] ?? 0) > 0) {
                $events = $db->prepare('DELETE FROM portal_ticket_events WHERE ticket_id = :ticket_id');
                $events->execute(['ticket_id' => (int) $ticket['id']]);
            }
            $statement = $db->prepare('DELETE FROM portal_tickets WHERE ticket_no = :ticket_no');
            $cleaned['ticket'] = $statement->execute(['ticket_no' => $ticketNo]);
        } catch (Throwable) {
            $cleaned['ticket'] = false;
        }
    }

    if ($sku !== '') {
        try {
            $statement = $db->prepare('DELETE FROM inventory_stock_items WHERE sku = :sku');
            $cleaned['inventory'] = $statement->execute(['sku' => $sku]);
        } catch (Throwable) {
            $cleaned['inventory'] = false;
        }
    }

    if ($subscriberCode !== '') {
        app_role_agent_reset_test_customer($db, $subscriberCode, 'roleqa900001');
        $cleaned['customer'] = $customerId <= 0 || app_db_account_by_id($customerId) === null;
    }

    return $cleaned;
}

function app_role_agent_run_e2e_suite(bool $apply = true): array
{
    $startedAt = app_db_now();
    $failures = [];
    $warnings = [];
    $agents = [];
    $artifacts = [];
    $db = app_db();
    if (!$db instanceof PDO) {
        return [
            'status' => 'failed',
            'started_at' => $startedAt,
            'failures' => [['message' => 'Database connection is not ready', 'context' => []]],
            'warnings' => [],
            'agents' => [],
            'artifacts' => [],
        ];
    }

    app_mgs_ensure_schema($db);

    foreach (app_role_agent_definitions() as $key => $definition) {
        $employee = app_role_agent_ensure_employee($key, $definition);
        $loginOk = app_role_agent_login_ok((string) $definition['login']);
        app_role_agent_assert($failures, is_array($employee), 'Employee agent could not be created', ['role' => $key]);
        app_role_agent_assert($failures, $loginOk, 'Employee agent login failed', ['role' => $key, 'login' => $definition['login']]);
        $agents[$key] = [
            'employee_id' => is_array($employee) ? (int) ($employee['id'] ?? 0) : 0,
            'login' => (string) $definition['login'],
            'employee_role' => (string) $definition['employee_role'],
            'account_type' => (string) $definition['account_type'],
            'login_ok' => $loginOk,
        ];
    }

    if ($failures !== []) {
        return [
            'status' => 'failed',
            'started_at' => $startedAt,
            'failures' => $failures,
            'warnings' => $warnings,
            'agents' => $agents,
            'artifacts' => $artifacts,
        ];
    }

    $customerData = app_role_agent_create_test_customer($db);
    $customer = is_array($customerData['account'] ?? null) ? $customerData['account'] : null;
    app_role_agent_assert($failures, is_array($customer), 'Test customer could not be created');
    if (!is_array($customer)) {
        return [
            'status' => 'failed',
            'started_at' => $startedAt,
            'failures' => $failures,
            'warnings' => $warnings,
            'agents' => $agents,
            'artifacts' => $artifacts,
        ];
    }

    $subscriberCode = (string) ($customer['subscriber_code'] ?? $customerData['subscriber_code']);
    $radiusRows = app_role_agent_count_radius_rows($db, $subscriberCode);
    app_role_agent_assert($failures, ($radiusRows['radcheck'] ?? 0) > 0, 'Customer was not written to RADIUS radcheck', ['subscriber_code' => $subscriberCode, 'radius_rows' => $radiusRows]);
    app_role_agent_assert($failures, ($radiusRows['radreply'] ?? 0) > 0, 'Customer was not written to RADIUS radreply', ['subscriber_code' => $subscriberCode, 'radius_rows' => $radiusRows]);

    $artifacts['customer'] = [
        'id' => (int) ($customer['id'] ?? 0),
        'subscriber_code' => $subscriberCode,
        'login' => (string) ($customer['login'] ?? ''),
        'plan' => (string) ($customer['plan'] ?? ''),
        'radius_rows' => $radiusRows,
    ];

    $operator = app_db_account_by_login((string) app_role_agent_definitions()['operator']['login']);
    $technician = app_db_account_by_login((string) app_role_agent_definitions()['technician']['login']);
    $noc = app_db_account_by_login((string) app_role_agent_definitions()['noc_engineer']['login']);
    $financier = app_db_account_by_login((string) app_role_agent_definitions()['financier']['login']);
    $warehouse = app_db_account_by_login((string) app_role_agent_definitions()['warehouse']['login']);
    $superAdmin = app_db_account_by_login((string) app_role_agent_definitions()['super_admin']['login']);

    $ticketNo = app_db_next_ticket_no();
    $ticketOk = app_db_insert_ticket([
        'ticket_no' => $ticketNo,
        'customer_account_id' => (int) $customer['id'],
        'subscriber_code' => $subscriberCode,
        'contract_no' => (string) ($customer['contract_no'] ?? ''),
        'customer_name' => (string) ($customer['full_name'] ?? ''),
        'phone' => (string) ($customer['phone'] ?? ''),
        'address' => (string) ($customer['address'] ?? ''),
        'area' => (string) ($customer['area'] ?? ''),
        'subject' => 'Internet yoxlama',
        'message' => 'Role agent operator test bileti yaratdi.',
        'status' => 'Aciqdir',
        'assigned_technician_account_id' => is_array($technician) ? (int) ($technician['id'] ?? 0) : null,
        'assigned_technician_name' => is_array($technician) ? (string) ($technician['full_name'] ?? '') : '',
        'created_by_account_id' => is_array($operator) ? (int) ($operator['id'] ?? 0) : null,
        'created_by_role' => 'operator',
        'created_by_name' => is_array($operator) ? (string) ($operator['full_name'] ?? '') : 'Role Agent Operator',
        'metadata' => ['role_agent_test' => true],
    ]);
    app_role_agent_assert($failures, $ticketOk, 'Operator could not create ticket', ['ticket_no' => $ticketNo]);
    app_mgs_record_operation_action([
        'action_type' => 'role_agent_operator_ticket_created',
        'entity_type' => 'ticket',
        'entity_id' => $ticketNo,
        'status' => 'executed',
        'actor' => is_array($operator) ? $operator : [],
        'summary' => 'Operator role test created ticket',
        'ticket_no' => $ticketNo,
        'payload' => ['customer' => $subscriberCode],
    ]);
    $agents['operator']['ticket_created'] = $ticketOk;

    $ticketCloseOk = app_db_close_ticket($ticketNo, 'Role agent test hell edildi', 'Texnik test bileti bagladi.');
    app_role_agent_assert($failures, $ticketCloseOk, 'Technician could not close ticket', ['ticket_no' => $ticketNo]);
    $closedTicket = app_db_ticket_find($ticketNo);
    app_role_agent_assert($failures, is_array($closedTicket) && (string) ($closedTicket['status'] ?? '') === 'Baglandi', 'Closed ticket status was not persisted', ['ticket_no' => $ticketNo, 'ticket' => $closedTicket]);
    if (is_array($closedTicket)) {
        app_mgs_record_ticket_close($closedTicket, 'Role agent test hell edildi', 'Texnik test bileti bagladi.', is_array($technician) ? $technician : []);
    }
    $agents['technician']['ticket_closed'] = $ticketCloseOk;

    $sku = 'ROLEAGENT-ONT-001';
    try {
        $resetInventory = $db->prepare('DELETE FROM inventory_stock_items WHERE sku = :sku');
        $resetInventory->execute(['sku' => $sku]);
    } catch (Throwable) {
        // The inventory schema is created above; this only protects first-run edge cases.
    }

    $inventorySteps = [
        ['movement_type' => 'in', 'quantity' => 10, 'note' => 'Role agent medaxil'],
        ['movement_type' => 'issue', 'quantity' => 3, 'note' => 'Texnike tehvil'],
        ['movement_type' => 'return', 'quantity' => 1, 'note' => 'Texnikden geri qaytarma'],
        ['movement_type' => 'customer_install', 'quantity' => 2, 'note' => 'Musteriye qosuldu'],
    ];
    $inventoryOk = true;
    foreach ($inventorySteps as $step) {
        $result = app_mgs_record_inventory_movement([
            'sku' => $sku,
            'item_name' => 'Role Agent ONT test',
            'category' => 'ont',
            'unit' => 'eded',
            'movement_type' => $step['movement_type'],
            'quantity' => $step['quantity'],
            'ticket_no' => $ticketNo,
            'customer_account_id' => (int) $customer['id'],
            'technician_account_id' => is_array($technician) ? (int) ($technician['id'] ?? 0) : null,
            'actor_account_id' => is_array($warehouse) ? (int) ($warehouse['id'] ?? 0) : null,
            'actor_name' => is_array($warehouse) ? (string) ($warehouse['full_name'] ?? '') : 'Role Agent Anbardar',
            'note' => (string) $step['note'],
            'role_agent_test' => true,
        ], $apply);
        $inventoryOk = $inventoryOk && !empty($result['ok']);
    }
    $inventoryReport = app_role_agent_inventory_report($db, $sku);
    app_role_agent_assert($failures, $inventoryOk, 'Warehouse inventory movement failed', ['sku' => $sku]);
    app_role_agent_assert($failures, (float) ($inventoryReport['quantity_on_hand'] ?? 0) >= 6, 'Warehouse inventory report did not reflect movements', ['sku' => $sku, 'report' => $inventoryReport]);
    $agents['warehouse']['inventory_posted'] = $inventoryOk;
    $agents['warehouse']['inventory_report'] = $inventoryReport;

    $externalId = 'role-agent-fin-' . date('YmdHis');
    $paymentOk = app_billing_apply_customer_payment($subscriberCode, 1.23, 'role-agent-finance', 30, 'ROLE-FIN-' . date('YmdHis'), $externalId);
    app_role_agent_assert($failures, $paymentOk, 'Financier could not create billing payment', ['subscriber_code' => $subscriberCode]);
    $paymentStatement = $db->prepare(
        'SELECT COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount
         FROM billing_payments
         WHERE external_id = :external_id'
    );
    $paymentStatement->execute(['external_id' => $externalId]);
    $paymentReport = $paymentStatement->fetch();
    app_role_agent_assert($failures, is_array($paymentReport) && (int) ($paymentReport['total'] ?? 0) === 1, 'Financier payment report did not find created payment', ['external_id' => $externalId, 'report' => $paymentReport]);
    $agents['financier']['payment_created'] = $paymentOk;
    $agents['financier']['payment_report'] = is_array($paymentReport) ? $paymentReport : [];

    $nocActionOk = app_mgs_record_operation_action([
        'action_type' => 'role_agent_noc_diagnostics',
        'entity_type' => 'device',
        'entity_id' => '172.16.5.5',
        'status' => 'dry_run',
        'actor' => is_array($noc) ? $noc : [],
        'summary' => 'NOC role test diagnostics queued without live router change',
        'payload' => [
            'customer' => $subscriberCode,
            'vlan_550_connected' => false,
            'live_router_change' => false,
        ],
    ]);
    app_role_agent_assert($failures, $nocActionOk, 'NOC diagnostic action could not be recorded');
    $agents['noc_engineer']['diagnostic_action_recorded'] = $nocActionOk;

    $attendanceUserId = is_array($technician) ? (string) ($technician['id'] ?? '') : '';
    $attendanceUsers = app_mgs_service_get_json('/api/service/attendance', [], 20);
    if (!empty($attendanceUsers['ok']) && is_array($attendanceUsers['data']['employees'] ?? null)) {
        foreach ($attendanceUsers['data']['employees'] as $employee) {
            if (!is_array($employee)) {
                continue;
            }
            $role = strtolower((string) ($employee['role'] ?? ''));
            if ($role === 'technician') {
                $attendanceUserId = (string) ($employee['userId'] ?? $attendanceUserId);
                break;
            }
        }
    }

    $attendancePayload = [
        'userId' => $attendanceUserId,
        'branchId' => '',
        'cardCode' => 'ROLEQA-' . date('Ymd'),
        'workStartTime' => '09:00',
        'workEndTime' => '18:00',
        'lateGraceMinutes' => 10,
        'expectedWorkMinutes' => 480,
        'noteText' => 'Role agent attendance profile test',
    ];
    $attendanceResult = app_mgs_service_request_json('/api/service/attendance/profiles', 'POST', $attendancePayload, 20);
    if (empty($attendanceResult['ok'])) {
        $warnings[] = [
            'message' => 'Attendance native service unavailable; local KPI snapshot still tested',
            'context' => ['error' => (string) ($attendanceResult['error'] ?? 'service_unavailable')],
        ];
    }
    app_mgs_record_operation_action([
        'action_type' => 'role_agent_attendance_check',
        'entity_type' => 'employee',
        'entity_id' => is_array($technician) ? (string) ($technician['id'] ?? '') : '',
        'status' => empty($attendanceResult['ok']) ? 'dry_run' : 'executed',
        'actor' => is_array($superAdmin) ? $superAdmin : [],
        'summary' => 'Attendance profile checked for technician role',
        'payload' => $attendancePayload + ['service_ok' => !empty($attendanceResult['ok'])],
    ]);

    $kpi = app_mgs_rebuild_employee_work_snapshots($db);
    app_role_agent_assert($failures, !empty($kpi['ok']) && (int) ($kpi['updated'] ?? 0) > 0, 'KPI snapshot rebuild did not update employee rows', ['kpi' => $kpi]);
    $agents['technician']['kpi_snapshot'] = $kpi;
    $agents['super_admin']['attendance_checked'] = true;
    $agents['super_admin']['all_agent_logins_verified'] = !in_array(false, array_column($agents, 'login_ok'), true);
    app_role_agent_assert($failures, $agents['super_admin']['all_agent_logins_verified'], 'Super admin did not verify all agent logins');

    $artifacts += [
        'ticket_no' => $ticketNo,
        'inventory_sku' => $sku,
        'payment_external_id' => $externalId,
        'attendance_service_ok' => !empty($attendanceResult['ok']),
        'kpi' => $kpi,
    ];
    if ($failures === [] && $apply) {
        $artifacts['cleanup'] = app_role_agent_cleanup_artifacts($db, $artifacts);
    }

    return [
        'status' => $failures === [] ? 'ok' : 'failed',
        'started_at' => $startedAt,
        'finished_at' => app_db_now(),
        'apply_mode' => $apply,
        'failures' => $failures,
        'warnings' => $warnings,
        'agents' => $agents,
        'artifacts' => $artifacts,
    ];
}
