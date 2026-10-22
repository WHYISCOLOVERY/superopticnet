<?php
declare(strict_types=1);

function app_customer_operator_allowed(array $actor): bool
{
    return ($actor['role'] ?? '') === 'employee'
        && (!isset($actor['is_active']) || (int) $actor['is_active'] === 1)
        && (app_portal_is_admin($actor) || in_array($actor['employee_role'] ?? '', ['operator', 'support', 'night_operator'], true));
}

function app_customer_identity_change_allowed(array $before, array $after, array $actor): bool
{
    if (app_portal_is_super_admin($actor)) return true;
    foreach (['full_name', 'address', 'fin_code'] as $field) {
        if (array_key_exists($field, $after) && trim((string) ($before[$field] ?? '')) !== trim((string) $after[$field])) return false;
    }
    return true;
}

function app_customer_operator_change(PDO $db, int $id, array $actor, array $input): array
{
    if (!app_customer_operator_allowed($actor)) return ['ok' => false, 'error' => 'İcazə yoxdur.'];
    $reason = trim((string) ($input['reason'] ?? ''));
    if ($reason === '') return ['ok' => false, 'error' => 'Əməliyyatın səbəbini yazın.'];
    $action = (string) ($input['action'] ?? '');
    $requestId = trim((string) ($input['request_id'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/', $requestId)) return ['ok' => false, 'error' => 'Sorğu identifikatoru etibarsızdır.'];
    if (!in_array($action, ['phone', 'cycle', 'debt', 'gift_days', 'borrow_days', 'package', 'reset_pppoe_password'], true)) return ['ok' => false, 'error' => 'Əməliyyat tanınmır.'];
    app_audit_ensure_schema($db);
    try {
        $db->beginTransaction();
        $q = $db->prepare('SELECT * FROM portal_accounts WHERE id=? AND role="customer" FOR UPDATE');
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Müştəri tapılmadı.');
        $payload = app_db_account_payload($row);
        foreach ($payload['customer_operator_history'] ?? [] as $event) {
            if (($event['request_id'] ?? '') === $requestId) {
                $db->rollBack();
                return ['ok' => true, 'message' => 'Bu əməliyyat artıq saxlanılıb.'];
            }
        }
        $before = $payload['billing'] ?? [];
        $billing = $before;
        $profile = $payload['profile'] ?? [];
        $services = $payload['internet_services'] ?? [];
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Baku'));
        $details = [];
        $phone = (string) ($row['phone'] ?? '');
        if ($action === 'phone') {
            $phone = normalize_phone((string) ($input['phone'] ?? ''));
            if (!preg_match('/^\+?[0-9]{9,15}$/', $phone)) throw new RuntimeException('Telefon nömrəsi düzgün deyil.');
            $profile['phone'] = $phone;
            $details = ['old_phone' => $row['phone'], 'new_phone' => $phone];
        } elseif ($action === 'reset_pppoe_password') {
            $username = trim((string) ($billing['pppoe_username'] ?? ($services[0]['username'] ?? $row['subscriber_code'] ?? '')));
            if ($username === '') throw new RuntimeException('PPPoE istifadəçi adı tapılmadı.');
            $password = trim((string) ($input['pppoe_password'] ?? ''));
            $generated = $password === '';
            if ($generated) {
                $password = 'SO' . bin2hex(random_bytes(6));
            }
            if (mb_strlen($password) < 8 || mb_strlen($password) > 64) throw new RuntimeException('PPPoE şifrəsi 8–64 simvol olmalıdır.');
            $billing['pppoe_username'] = $username;
            $billing['pppoe_password'] = $password;
            $updated = false;
            foreach ($services as &$service) {
                if (!$updated && strcasecmp((string) ($service['username'] ?? ''), $username) === 0) {
                    $service['password'] = $password;
                    $updated = true;
                }
            }
            unset($service);
            if (!$updated) {
                $services[] = ['service_type' => 'pppoe', 'username' => $username, 'password' => $password];
            }
            $details = ['username' => $username, 'generated' => $generated];
        } elseif ($action === 'cycle') {
            $mode = (string) ($input['mode'] ?? '');
            $day = filter_var($input['day'] ?? 0, FILTER_VALIDATE_INT);
            if (!in_array($mode, ['30_days', 'stable'], true) || ($mode === 'stable' && ($day < 1 || $day > 31))) throw new RuntimeException('Ödəniş rejimini və stabil günü düzgün seçin.');
            foreach (['stable_due_day', 'stable_day_of_month', 'billing_anchor_day', 'renewal_day'] as $key) {
                unset($billing[$key], $profile[$key]);
                foreach ($services as &$service) unset($service[$key]);
                unset($service);
            }
            $billing['default_days'] = $billing['billing_days'] = 30;
            foreach ($services as &$service) $service['default_days'] = 30;
            unset($service);
            if ($mode === 'stable') {
                $billing['stable_due_day'] = $profile['stable_due_day'] = $day;
                foreach ($services as &$service) $service['stable_due_day'] = $day;
                unset($service);
            }
            $details = ['mode' => $mode, 'day' => $mode === 'stable' ? $day : null];
        } elseif ($action === 'debt') {
            $kind = (string) ($input['kind'] ?? '');
            $amount = filter_var($input['amount'] ?? '', FILTER_VALIDATE_FLOAT);
            if (!in_array($kind, ['service', 'equipment'], true) || $amount === false || $amount <= 0 || $amount > 100000) throw new RuntimeException('Borc növünü və müsbət məbləği daxil edin.');
            $amount = round($amount, 2);
            $billing['debt_raw'] = number_format(app_billing_parse_money_amount($billing['debt_raw'] ?? 0) + $amount, 2, '.', '') . ' AZN';
            $details = ['kind' => $kind, 'amount' => $amount, 'document_no' => trim((string) ($input['document_no'] ?? ''))];
            if ($details['document_no'] === '') throw new RuntimeException('Xidmət və ya avadanlıq sənədinin nömrəsini yazın.');
        } elseif (in_array($action, ['gift_days', 'borrow_days'], true)) {
            $days = filter_var($input['days'] ?? '', FILTER_VALIDATE_INT);
            $label = $action === 'gift_days' ? 'hədiyyə' : 'borc';
            if ($days === false || $days < 1 || $days > 3) throw new RuntimeException('Yalnız 1–3 ' . $label . ' günü vermək olar.');
            $month = $now->format('Y-m');
            $monthlyKey = $action === 'gift_days' ? 'gift_days_months' : 'borrowed_days_months';
            $prior = (int) (($billing[$monthlyKey][$month]['days'] ?? 0));
            if ($prior + $days > 3) throw new RuntimeException('Bu müştəriyə həmin ay üzrə ümumilikdə ən çox 3 ' . $label . ' günü verilə bilər.');
            $expiry = app_billing_payload_primary_expires_at($payload, $billing);
            $base = max($now->getTimestamp(), strtotime($expiry) ?: 0);
            $billing['expires_at'] = date('Y-m-d H:i:s', $base + $days * 86400);
            $billing[$monthlyKey][$month] = ['days' => $prior + $days, 'actor_id' => (int) $actor['id'], 'at' => $now->format(DATE_ATOM)];
            if ($action === 'borrow_days') $billing['borrowed_days_pending'] = (int) ($billing['borrowed_days_pending'] ?? 0) + $days;
            $billing['status'] = $billing['radius_status'] = 'Aktiv';
            foreach ($services as &$service) {
                $service['expires_at'] = $billing['expires_at'];
                $service['status'] = 'active';
            }
            unset($service);
            $details = ['days' => $days, 'month' => $month, 'deduct_on_next_payment' => $action === 'borrow_days'];
        } else {
            $tariffId = filter_var($input['tariff_id'] ?? 0, FILTER_VALIDATE_INT);
            if ($tariffId === false || $tariffId <= 0) throw new RuntimeException('Paket seçilməyib.');
            $tariffStatement = $db->prepare('SELECT * FROM billing_tariffs WHERE id=? AND status="active" LIMIT 1');
            $tariffStatement->execute([$tariffId]);
            $tariff = $tariffStatement->fetch(PDO::FETCH_ASSOC);
            if (!$tariff) throw new RuntimeException('Paket tapılmadı.');
            $serviceType = strtolower((string) ($billing['service_type'] ?? $services[0]['service_type'] ?? 'pppoe'));
            if (strtolower((string) ($tariff['service_type'] ?? '')) !== $serviceType) throw new RuntimeException('Paket cari servis növünə uyğun deyil.');
            $oldTariff = (string) ($billing['tariff'] ?? $row['plan'] ?? '');
            $billing['tariff'] = (string) $tariff['name'];
            $profile['plan'] = (string) $tariff['name'];
            foreach ($services as &$service) {
                if (strtolower((string) ($service['service_type'] ?? $serviceType)) === $serviceType) {
                    $service['tariff'] = (string) $tariff['name'];
                    break;
                }
            }
            unset($service);
            $details = ['old_tariff' => $oldTariff, 'new_tariff' => (string) $tariff['name'], 'tariff_id' => (int) $tariff['id']];
        }
        $payload['billing'] = $billing;
        $payload['profile'] = $profile;
        $payload['internet_services'] = $services;
        $entry = $details + ['request_id' => $requestId, 'action' => $action, 'reason' => $reason, 'actor_id' => (int) $actor['id'], 'at' => $now->format(DATE_ATOM)];
        $payload['customer_operator_history'][] = $entry;
        if ($action === 'package') {
            $payload = app_billing_refresh_payload_totals($row, $payload, $tariff);
        }
        $q = $db->prepare('UPDATE portal_accounts SET phone=?,plan=?,payload_json=?,updated_at=NOW() WHERE id=?');
        $q->execute([$phone, $action === 'package' ? (string) $tariff['name'] : (string) ($row['plan'] ?? ''), app_db_json_encode($payload), $id]);
        if ($action === 'reset_pppoe_password') {
            if (function_exists('app_legacy_customer_secret_store')) {
                app_legacy_customer_secret_store($db, (int) $row['id'], 'pppoe_password', $password);
            }
            $row['payload_json'] = app_db_json_encode($payload);
            if (!app_billing_sync_radius_account($row, null, $db)) throw new RuntimeException('RADIUS yenilənmədi; əməliyyat saxlanmadı.');
        }
        if (in_array($action, ['gift_days', 'borrow_days', 'package'], true)) {
            if (in_array($action, ['gift_days', 'borrow_days'], true)) {
                $q = $db->prepare('UPDATE portal_accounts SET status="Aktiv",is_active=1 WHERE id=?');
                $q->execute([$id]);
                $row['status'] = 'Aktiv'; $row['is_active'] = 1;
            }
            if ($action === 'package') $row['plan'] = (string) $tariff['name'];
            $row['payload_json'] = app_db_json_encode($payload);
            if (!app_billing_sync_radius_account($row, null, $db)) throw new RuntimeException('RADIUS yenilənmədi; əməliyyat saxlanmadı.');
        }
        $auditBefore = $before;
        $auditAfter = $billing;
        if ($action === 'reset_pppoe_password') {
            unset($auditBefore['pppoe_password'], $auditAfter['pppoe_password']);
        }
        app_audit_record($db, ['module_key' => 'billing', 'operation_class' => 'customer_operator', 'action_type' => 'customer_' . $action, 'entity_type' => 'customer', 'entity_id' => (string) $id, 'status' => 'executed', 'actor_account_id' => (int) $actor['id'], 'actor_name' => $actor['full_name'] ?? '', 'summary' => 'Customer operator action', 'reason' => $reason, 'before' => $auditBefore, 'after' => $auditAfter, 'payload' => $entry, 'sensitivity' => 'high']);
        $db->commit();
        return ['ok' => true, 'message' => 'Əməliyyat saxlanıldı və tarixçəyə yazıldı.']
            + ($action === 'reset_pppoe_password' ? ['pppoe_password' => $password] : []);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => $e instanceof PDOException ? 'Əməliyyat saxlanmadı.' : $e->getMessage()];
    }
}
