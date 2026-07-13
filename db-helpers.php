<?php
declare(strict_types=1);

function app_db_settings(): array
{
    return [
        'host' => app_config('db_host', '127.0.0.1'),
        'port' => (int) app_setting('db_port', 3306),
        'name' => app_config('db_name', 'superoptic_portal'),
        'user' => app_config('db_user', 'root'),
        'pass' => (string) app_setting('db_pass', ''),
        'charset' => app_config('db_charset', 'utf8mb4'),
    ];
}

function app_db_is_enabled(): bool
{
    if (!extension_loaded('pdo_mysql')) {
        return false;
    }

    $settings = app_db_settings();
    return $settings['host'] !== '' && $settings['name'] !== '' && $settings['user'] !== '';
}

function app_db_database_name(string $name): string
{
    return preg_match('/^[a-zA-Z0-9_]+$/', $name) ? $name : 'superoptic_portal';
}

function app_db_schema_path(): string
{
    return __DIR__ . '/db/schema.sql';
}

function app_db_pdo(): ?PDO
{
    static $pdo = null;
    static $booted = false;

    if ($booted) {
        return $pdo;
    }

    $booted = true;

    if (!app_db_is_enabled()) {
        return null;
    }

    $settings = app_db_settings();
    $dbName = app_db_database_name($settings['name']);
    $serverDsn = sprintf('mysql:host=%s;port=%d;charset=%s', $settings['host'], $settings['port'], $settings['charset']);

    try {
        $serverPdo = new PDO($serverDsn, $settings['user'], $settings['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $serverPdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s_unicode_ci',
            $dbName,
            $settings['charset'],
            $settings['charset']
        ));

        $dbDsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $settings['host'],
            $settings['port'],
            $dbName,
            $settings['charset']
        );
        $pdo = new PDO($dbDsn, $settings['user'], $settings['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        app_db_ensure_schema($pdo);
    } catch (Throwable $exception) {
        $pdo = null;
    }

    return $pdo;
}

function app_db_ensure_schema(PDO $pdo): void
{
    static $schemaEnsured = false;

    if ($schemaEnsured) {
        return;
    }

    $schemaEnsured = true;
    $schema = @file_get_contents(app_db_schema_path());
    if (!is_string($schema) || trim($schema) === '') {
        return;
    }

    $pdo->exec($schema);
}

function app_db_json_encode(mixed $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : '[]';
}

function app_db_datetime(?string $value): ?string
{
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        return null;
    }

    $formats = ['d.m.Y H:i', 'd.m.Y H:i:s', 'Y-m-d H:i:s', DATE_ATOM];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    $timestamp = strtotime($value);
    return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
}

function app_db_ticket_row(array $ticket): array
{
    return [
        'ticket_no' => (string) ($ticket['id'] ?? ''),
        'customer_public_id' => (string) ($ticket['customer_id'] ?? ''),
        'subscriber_code' => (string) ($ticket['subscriber_code'] ?? ''),
        'contract_no' => (string) ($ticket['contract_no'] ?? ''),
        'customer_name' => (string) ($ticket['customer_name'] ?? ''),
        'phone' => (string) ($ticket['phone'] ?? ''),
        'address' => (string) ($ticket['address'] ?? ''),
        'area' => (string) ($ticket['area'] ?? ''),
        'subject' => (string) ($ticket['subject'] ?? ''),
        'message' => (string) ($ticket['message'] ?? ''),
        'status' => app_portal_normalize_ticket_status((string) ($ticket['status'] ?? 'Aciqdir')),
        'opened_at' => app_db_datetime((string) ($ticket['opened_at'] ?? '')),
        'updated_at' => app_db_datetime((string) ($ticket['updated_at'] ?? '')),
        'resolved_at' => app_db_datetime((string) ($ticket['resolved_at'] ?? '')),
        'assigned_technician_id' => (string) ($ticket['assigned_technician_id'] ?? ''),
        'assigned_technician_name' => (string) ($ticket['assigned_technician_name'] ?? ''),
        'resolution_reason' => (string) ($ticket['resolution_reason'] ?? ''),
        'resolution_note' => (string) ($ticket['resolution_note'] ?? ''),
        'handled_by' => (string) ($ticket['handled_by'] ?? ''),
        'created_by_role' => (string) ($ticket['created_by_role'] ?? ''),
        'created_by_name' => (string) ($ticket['created_by_name'] ?? ''),
        'telegram_message_ids_json' => app_db_json_encode($ticket['telegram_message_ids'] ?? []),
        'sync_source' => 'portal',
    ];
}

function app_db_user_password_hash(array $record): string
{
    $userId = (string) ($record['id'] ?? '');
    $overrides = function_exists('app_portal_password_overrides') ? app_portal_password_overrides() : [];
    $overrideHash = is_array($overrides) ? ($overrides[$userId] ?? null) : null;
    if (is_string($overrideHash) && $overrideHash !== '') {
        return $overrideHash;
    }

    $plain = (string) ($record['password'] ?? '');
    if ($plain === '') {
        $plain = bin2hex(random_bytes(8));
    }

    return password_hash($plain, PASSWORD_DEFAULT);
}

function app_db_sync_all_current_state(): bool
{
    $pdo = app_db_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }

    $portalData = app_mock_portal_data();
    $tickets = app_portal_ticket_records();

    try {
        $pdo->beginTransaction();

        $pdo->exec("DELETE FROM portal_user_areas");
        $pdo->exec("DELETE FROM portal_users WHERE sync_source = 'portal'");

        $userStmt = $pdo->prepare(
            'INSERT INTO portal_users (
                public_id, user_type, login, subscriber_code, full_name, employee_role,
                password_hash, phone, area, address, email, category, dashboard_json, sync_source
            ) VALUES (
                :public_id, :user_type, :login, :subscriber_code, :full_name, :employee_role,
                :password_hash, :phone, :area, :address, :email, :category, :dashboard_json, :sync_source
            )'
        );
        $areaStmt = $pdo->prepare('INSERT INTO portal_user_areas (user_public_id, area) VALUES (:user_public_id, :area)');

        foreach (($portalData['employees'] ?? []) as $login => $record) {
            if (!is_array($record)) {
                continue;
            }

            $userStmt->execute([
                'public_id' => (string) ($record['id'] ?? $login),
                'user_type' => 'employee',
                'login' => (string) $login,
                'subscriber_code' => null,
                'full_name' => (string) ($record['name'] ?? $login),
                'employee_role' => (string) ($record['employee_role'] ?? ''),
                'password_hash' => app_db_user_password_hash($record),
                'phone' => null,
                'area' => null,
                'address' => null,
                'email' => null,
                'category' => null,
                'dashboard_json' => app_db_json_encode($record['dashboard'] ?? []),
                'sync_source' => 'portal',
            ]);

            foreach ((array) ($record['areas'] ?? []) as $area) {
                $area = clean_text((string) $area);
                if ($area === '') {
                    continue;
                }
                $areaStmt->execute([
                    'user_public_id' => (string) ($record['id'] ?? $login),
                    'area' => $area,
                ]);
            }
        }

        foreach (($portalData['customers'] ?? []) as $login => $record) {
            if (!is_array($record)) {
                continue;
            }

            $profile = is_array($record['dashboard']['profile'] ?? null) ? $record['dashboard']['profile'] : [];
            $userStmt->execute([
                'public_id' => (string) ($record['id'] ?? $login),
                'user_type' => 'customer',
                'login' => (string) $login,
                'subscriber_code' => (string) ($record['subscriber_code'] ?? $login),
                'full_name' => (string) ($profile['full_name'] ?? $record['name'] ?? $login),
                'employee_role' => null,
                'password_hash' => app_db_user_password_hash($record),
                'phone' => (string) ($profile['phone'] ?? ''),
                'area' => (string) ($profile['area'] ?? ''),
                'address' => (string) ($profile['address'] ?? ''),
                'email' => (string) ($profile['email'] ?? ''),
                'category' => (string) ($profile['category'] ?? $record['category'] ?? ''),
                'dashboard_json' => app_db_json_encode($record['dashboard'] ?? []),
                'sync_source' => 'portal',
            ]);
        }

        $pdo->exec("DELETE FROM portal_tickets WHERE sync_source = 'portal'");
        $ticketStmt = $pdo->prepare(
            'INSERT INTO portal_tickets (
                ticket_no, customer_public_id, subscriber_code, contract_no, customer_name, phone, address, area,
                subject, message, status, opened_at, updated_at, resolved_at, assigned_technician_id,
                assigned_technician_name, resolution_reason, resolution_note, handled_by, created_by_role,
                created_by_name, telegram_message_ids_json, sync_source
            ) VALUES (
                :ticket_no, :customer_public_id, :subscriber_code, :contract_no, :customer_name, :phone, :address, :area,
                :subject, :message, :status, :opened_at, :updated_at, :resolved_at, :assigned_technician_id,
                :assigned_technician_name, :resolution_reason, :resolution_note, :handled_by, :created_by_role,
                :created_by_name, :telegram_message_ids_json, :sync_source
            )'
        );

        foreach ($tickets as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }
            $ticketStmt->execute(app_db_ticket_row($ticket));
        }

        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function app_db_record_ticket_event(string $ticketNo, string $eventType, string $actorName = '', array $payload = []): void
{
    $pdo = app_db_pdo();
    if (!$pdo instanceof PDO || $ticketNo === '' || $eventType === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO portal_ticket_events (ticket_no, event_type, actor_name, note, payload_json)
             VALUES (:ticket_no, :event_type, :actor_name, :note, :payload_json)'
        );
        $stmt->execute([
            'ticket_no' => $ticketNo,
            'event_type' => $eventType,
            'actor_name' => $actorName,
            'note' => (string) ($payload['note'] ?? ''),
            'payload_json' => app_db_json_encode($payload),
        ]);
    } catch (Throwable $exception) {
    }
}

function app_db_reason_statistics(string $dateFrom, string $dateTo, string $group): array
{
    $reasons = app_portal_ticket_close_reasons();
    $group = in_array($group, ['daily', 'weekly', 'monthly'], true) ? $group : 'weekly';
    $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : date('Y-m-01', strtotime('-2 months'));
    $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : date('Y-m-d');

    $start = new DateTimeImmutable($dateFrom . ' 00:00:00');
    $end = new DateTimeImmutable($dateTo . ' 23:59:59');
    if ($end < $start) {
        [$start, $end] = [$end, $start];
    }

    $periods = [];
    $cursor = $group === 'monthly'
        ? $start->modify('first day of this month')
        : ($group === 'weekly' ? $start->modify('monday this week') : $start);

    while ($cursor <= $end) {
        $key = match ($group) {
            'monthly' => $cursor->format('Y-m'),
            'weekly' => $cursor->format('o-\WW'),
            default => $cursor->format('Y-m-d'),
        };
        $periods[$key] = [
            'label' => match ($group) {
                'monthly' => $cursor->format('M Y'),
                'weekly' => $cursor->format('d M') . ' - ' . $cursor->modify('+6 days')->format('d M'),
                default => $cursor->format('d.m.Y'),
            },
            'total' => 0,
            'reasons' => array_fill_keys($reasons, 0),
        ];
        $cursor = match ($group) {
            'monthly' => $cursor->modify('+1 month'),
            'weekly' => $cursor->modify('+1 week'),
            default => $cursor->modify('+1 day'),
        };
    }

    $overallCounts = array_fill_keys($reasons, 0);
    $overallTotal = 0;
    foreach (app_portal_ticket_records() as $ticket) {
        if (!is_array($ticket)) {
            continue;
        }

        $status = app_portal_normalize_ticket_status((string) ($ticket['status'] ?? ''));
        $reason = (string) ($ticket['resolution_reason'] ?? '');
        $resolvedAt = app_db_datetime((string) ($ticket['resolved_at'] ?? ''));
        if ($status !== 'Baglandi' || $reason === '' || $resolvedAt === null || !in_array($reason, $reasons, true)) {
            continue;
        }

        $resolvedDate = new DateTimeImmutable($resolvedAt);
        if ($resolvedDate < $start || $resolvedDate > $end) {
            continue;
        }

        $periodKey = match ($group) {
            'monthly' => $resolvedDate->format('Y-m'),
            'weekly' => $resolvedDate->format('o-\WW'),
            default => $resolvedDate->format('Y-m-d'),
        };

        if (!isset($periods[$periodKey])) {
            continue;
        }

        $periods[$periodKey]['reasons'][$reason]++;
        $periods[$periodKey]['total']++;
        $overallCounts[$reason]++;
        $overallTotal++;
    }

    $tableRows = [];
    foreach ($periods as $period) {
        foreach ($period['reasons'] as $reason => $count) {
            if ($count < 1) {
                continue;
            }

            $tableRows[] = [
                'period' => $period['label'],
                'reason' => $reason,
                'count' => $count,
                'percent' => $period['total'] > 0 ? round(($count / $period['total']) * 100, 1) : 0.0,
            ];
        }
    }

    $summaryRows = [];
    foreach ($overallCounts as $reason => $count) {
        $summaryRows[] = [
            'reason' => $reason,
            'count' => $count,
            'percent' => $overallTotal > 0 ? round(($count / $overallTotal) * 100, 1) : 0.0,
        ];
    }

    usort($summaryRows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

    return [
        'group' => $group,
        'from' => $start->format('Y-m-d'),
        'to' => $end->format('Y-m-d'),
        'period_labels' => array_column($periods, 'label'),
        'periods' => $periods,
        'reasons' => $reasons,
        'overall_total' => $overallTotal,
        'overall_rows' => $summaryRows,
        'table_rows' => $tableRows,
    ];
}
