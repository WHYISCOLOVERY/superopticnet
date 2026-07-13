<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function app_copilot_normalize_text(string $text): string
{
    $text = mb_strtolower($text);
    $map = [
        'ə' => 'e', 'ı' => 'i', 'ö' => 'o', 'ü' => 'u', 'ğ' => 'g', 'ş' => 's', 'ç' => 'c',
        'Ə' => 'e', 'İ' => 'i', 'I' => 'i', 'Ö' => 'o', 'Ü' => 'u', 'Ğ' => 'g', 'Ş' => 's', 'Ç' => 'c',
        'ə' => 'e', 'ö' => 'o', 'ü' => 'u', 'ı' => 'i', 'ğ' => 'g', 'ş' => 's', 'ç' => 'c',
        'Ə' => 'e', 'Ö' => 'o', 'Ü' => 'u', 'I' => 'i', 'Ğ' => 'g', 'Ş' => 's', 'Ç' => 'c',
        'ə' => 'e', 'ö' => 'o', 'ü' => 'u', 'ı' => 'i', 'ğ' => 'g', 'ş' => 's', 'ç' => 'c',
        'Ə' => 'e', 'Ö' => 'o', 'Ü' => 'u', 'I' => 'i', 'Ğ' => 'g', 'Ş' => 's', 'Ç' => 'c',
    ];
    return strtr($text, $map);
}

function app_copilot_text_has_any(string $text, array $needles): bool
{
    $normalized = app_copilot_normalize_text($text);
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($normalized, app_copilot_normalize_text((string) $needle))) {
            return true;
        }
    }

    return false;
}

function app_copilot_resolve_customer(string $question, ?PDO $db = null): ?array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return null;
    }

    if (preg_match_all('/[A-Za-z0-9][A-Za-z0-9._-]{2,63}/', $question, $tokenMatches) > 0) {
        $tokens = array_values(array_unique(array_filter(array_map(
            static fn (string $token): string => trim($token, " \t\n\r\0\x0B.,;:!?()[]{}<>\"'"),
            $tokenMatches[0] ?? []
        ))));

        foreach ($tokens as $token) {
            if ($token === '' || (!preg_match('/\d/', $token) && !str_contains($token, '-'))) {
                continue;
            }

            $statement = $db->prepare(
                'SELECT * FROM portal_accounts
                 WHERE role = "customer"
                   AND (
                        LOWER(COALESCE(subscriber_code, "")) = LOWER(:token)
                        OR LOWER(COALESCE(contract_no, "")) = LOWER(:token)
                        OR LOWER(COALESCE(login, "")) = LOWER(:token)
                   )
                 LIMIT 1'
            );
            $statement->execute(['token' => $token]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        }
    }

    if (preg_match('/\b\d{4,12}\b/', $question, $match) === 1) {
        $code = (string) $match[0];
        $statement = $db->prepare(
            'SELECT * FROM portal_accounts
             WHERE role = "customer"
               AND (subscriber_code = :code OR contract_no = :code OR login = :code)
             LIMIT 1'
        );
        $statement->execute(['code' => $code]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    $lookup = app_db_customer_lookup($question, 1);
    if ($lookup !== []) {
        $id = (int) ($lookup[0]['id'] ?? 0);
        return $id > 0 ? app_db_account_by_id($id) : null;
    }

    return null;
}

function app_copilot_compact_rows(array $rows, int $limit = 8): array
{
    $result = [];
    foreach (array_slice($rows, 0, max(1, $limit)) as $row) {
        if (is_array($row)) {
            $result[] = array_filter($row, static fn ($value): bool => $value !== null && $value !== '');
        }
    }

    return $result;
}

function app_copilot_collect_customer_evidence(array $account, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    $payload = app_db_account_payload($account);
    $user = app_db_user_session_payload($account);
    $snapshot = app_db_customer_snapshot($user);
    $billing = is_array($snapshot['billing'] ?? null) ? $snapshot['billing'] : [];
    $profile = is_array($snapshot['profile'] ?? null) ? $snapshot['profile'] : [];
    $payments = is_array($snapshot['payments'] ?? null) ? $snapshot['payments'] : [];
    $statements = is_array($snapshot['statements'] ?? null) ? $snapshot['statements'] : [];
    $tickets = is_array($snapshot['tickets'] ?? null) ? $snapshot['tickets'] : [];
    $messages = is_array($snapshot['messages'] ?? null) ? $snapshot['messages'] : [];
    $services = is_array($snapshot['internet_services'] ?? null) ? $snapshot['internet_services'] : [];
    $modems = [];
    foreach (['ont', 'onts', 'modem', 'modems', 'equipment'] as $key) {
        if (is_array($payload[$key] ?? null)) {
            $modems[$key] = $payload[$key];
        }
    }

    $radiusRows = [];
    if ($db instanceof PDO) {
        try {
            $statement = $db->prepare(
                'SELECT username, service_type, status, local_ip, static_ip, nat_public_ip, mac_address, vlan_id, expires_at, last_seen_at, updated_at
                 FROM billing_radius_services
                 WHERE account_id = :account_id
                 ORDER BY updated_at DESC
                 LIMIT 10'
            );
            $statement->execute(['account_id' => (int) ($account['id'] ?? 0)]);
            $radiusRows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $radiusRows = [];
        }
    }

    return [
        'customer' => [
            'id' => (int) ($account['id'] ?? 0),
            'code' => (string) ($account['subscriber_code'] ?? ''),
            'contract_no' => (string) ($account['contract_no'] ?? ''),
            'full_name' => (string) ($account['full_name'] ?? ''),
            'phone' => (string) ($account['phone'] ?? ''),
            'area' => (string) ($account['area'] ?? ''),
            'address' => (string) ($account['address'] ?? ''),
            'status' => (string) ($account['status'] ?? ''),
            'package' => (string) ($account['plan'] ?? ''),
        ],
        'billing' => [
            'balance' => (string) ($billing['balance'] ?? ''),
            'monthly_total' => (string) ($billing['monthly_total'] ?? ''),
            'remaining_days' => (string) ($billing['remaining_days_raw'] ?? ''),
            'expires_at' => (string) ($billing['expires_at'] ?? ''),
            'radius_status' => (string) ($billing['radius_status'] ?? ''),
        ],
        'payments' => app_copilot_compact_rows($payments, 8),
        'statements' => app_copilot_compact_rows($statements, 8),
        'tickets' => app_copilot_compact_rows($tickets, 12),
        'messages' => app_copilot_compact_rows($messages, 10),
        'radius_services' => app_copilot_compact_rows($radiusRows, 10),
        'services' => app_copilot_compact_rows($services, 10),
        'modem_ont' => $modems,
        'profile_notes' => [
            'note' => (string) ($profile['note'] ?? $payload['note'] ?? ''),
            'legacy_note' => (string) ($payload['old_billing']['note'] ?? ''),
        ],
    ];
}

function app_copilot_customer_analysis(string $question, ?PDO $db = null): array
{
    $db = $db instanceof PDO ? $db : app_db();
    if (!$db instanceof PDO) {
        return [
            'ok' => false,
            'reason' => 'database_unavailable',
            'answer' => 'MySQL aktiv deyil; musteri melumatlari analiz edile bilmir.',
        ];
    }

    $account = app_copilot_resolve_customer($question, $db);
    if ($account === null) {
        return [
            'ok' => false,
            'reason' => 'customer_not_found',
            'answer' => 'Sualdan musteri kodu, login ve ya ad soyad etibarli sekilde tapilmadi.',
        ];
    }

    $evidence = app_copilot_collect_customer_evidence($account, $db);
    $questionNorm = app_copilot_normalize_text($question);
    $combinedText = app_copilot_normalize_text(json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    $findings = [];
    $recommendations = [];
    $confidence = 0.45;

    if (app_copilot_text_has_any($questionNorm, ['odenis', 'odeme', 'balans', 'neye cixib']) || app_copilot_text_has_any($combinedText, ['payment', 'odenis', 'balans'])) {
        $lastPayment = is_array($evidence['payments'][0] ?? null) ? $evidence['payments'][0] : [];
        $findings[] = [
            'type' => 'billing',
            'severity' => 'info',
            'summary' => 'Odenis/balans hissesi yoxlanildi.',
            'evidence' => [
                'balance' => $evidence['billing']['balance'] ?? '',
                'monthly_total' => $evidence['billing']['monthly_total'] ?? '',
                'last_payment' => $lastPayment,
            ],
        ];
        $recommendations[] = 'Odenis muzakiresinde son odenis, transaction ID ve balans hereketini musterinin profilinde Odenisler/Cixaris tabinda tutusdurun.';
        $confidence += 0.1;
    }

    if (app_copilot_text_has_any($combinedText . ' ' . $questionNorm, ['internet yoxdur', 'islemir', 'offline', 'online deyil', 'deaktiv', 'vaxt bitib', 'gunu bitib', 'radius'])) {
        $findings[] = [
            'type' => 'service_state',
            'severity' => app_copilot_text_has_any($combinedText . ' ' . $questionNorm, ['deaktiv', 'vaxt bitib', 'gunu bitib']) ? 'warning' : 'info',
            'summary' => 'Xidmet/RADIUS statusu ve bitme tarixi yoxlanildi.',
            'evidence' => [
                'billing' => $evidence['billing'],
                'radius_services' => array_slice($evidence['radius_services'], 0, 5),
            ],
        ];
        $recommendations[] = 'Status deaktiv ve ya vaxt bitibse once odenis/balans cixarisi yoxlanmali, aktivdirse RADIUS sessiya ve ONT online veziyyeti tutusdurulmalidir.';
        $confidence += 0.12;
    }

    if (app_copilot_text_has_any($combinedText . ' ' . $questionNorm, ['zeif', 'surret', 'sureti', 'slow', 'ping', 'packet loss', 'gec acilir', 'donur'])) {
        $findings[] = [
            'type' => 'quality',
            'severity' => 'warning',
            'summary' => 'Keyfiyyet/suret problemi ehtimali var.',
            'evidence' => [
                'services' => array_slice($evidence['services'], 0, 5),
                'tickets' => array_slice($evidence['tickets'], 0, 5),
                'radius_services' => array_slice($evidence['radius_services'], 0, 5),
            ],
        ];
        $recommendations[] = 'Speed/QoE ucun tarif limiti, VLAN/NAS sessiya, Wi-Fi kanal yuklenmesi ve ONT optik seviyyesi birlikde yoxlanmalidir.';
        $confidence += 0.1;
    }

    if (app_copilot_text_has_any($combinedText . ' ' . $questionNorm, ['qiril', 'get gel', 'sonub yan', 'restart', 'port xetasi', 'session-timeout', 'disconnect'])) {
        $findings[] = [
            'type' => 'flapping',
            'severity' => 'warning',
            'summary' => 'Muraciet/log metnlerinde qirilma ve ya yeniden qosulma izleri var.',
            'evidence' => [
                'tickets' => array_slice($evidence['tickets'], 0, 5),
                'radius_services' => array_slice($evidence['radius_services'], 0, 5),
            ],
        ];
        $recommendations[] = 'Texnik ilk once ONT power/adaptor, fiber patchcord, konnektor ve PON portda los/flap tarixcesini yoxlamalidir.';
        $confidence += 0.18;
    }

    if (app_copilot_text_has_any($combinedText . ' ' . $questionNorm, ['rx', 'tx', 'optik', 'signal', 'los', 'low power', '-28', '-29', '-30'])) {
        $findings[] = [
            'type' => 'optical',
            'severity' => 'warning',
            'summary' => 'Optik siqnal/RX/TX ile bagli qeydler var.',
            'evidence' => [
                'modem_ont' => $evidence['modem_ont'],
                'tickets' => array_slice($evidence['tickets'], 0, 3),
            ],
        ];
        $recommendations[] = 'Live OLT optical read ile ONT RX/TX, OLT RX/TX ve PON port seviyyesini yoxlayin; zeif siqnal varsa konnektor ve splitter xettini yoxlayin.';
        $confidence += 0.15;
    }

    if (app_copilot_text_has_any($combinedText . ' ' . $questionNorm, ['qiz', 'temperatur', 'temperature', 'overheat', 'isti'])) {
        $findings[] = [
            'type' => 'temperature',
            'severity' => 'warning',
            'summary' => 'Modem/ONT qizmasi ehtimali var.',
            'evidence' => ['modem_ont' => $evidence['modem_ont']],
        ];
        $recommendations[] = 'Modemin havalandirmasini, adaptorunu ve temperatur tarixcesini yoxlayin; tekrar edirse modem deyisimi teklif edin.';
        $confidence += 0.12;
    }

    if (app_copilot_text_has_any($combinedText . ' ' . $questionNorm, ['monolit', 'beton', '200 m2', '200m2', 'otaq', 'catmir', 'wifi', 'radiusu catmir'])) {
        $findings[] = [
            'type' => 'wifi_coverage',
            'severity' => 'info',
            'summary' => 'Ev daxili Wi-Fi ehate problemi ehtimali var.',
            'evidence' => [
                'address' => $evidence['customer']['address'] ?? '',
                'notes' => $evidence['profile_notes'],
                'tickets' => array_slice($evidence['tickets'], 0, 5),
            ],
        ];
        $recommendations[] = 'Texnik modem yerlesmesini, 2.4/5 GHz kanal yukunu ve mesh/repeater ehtiyacini qiymetlendirmelidir.';
        $confidence += 0.12;
    }

    if ($findings === []) {
        $findings[] = [
            'type' => 'general',
            'severity' => 'info',
            'summary' => 'Aciq ciddi texniki sebeb avtomatik secilmedi; musteri 360 melumatlari toplandi.',
            'evidence' => [
                'billing' => $evidence['billing'],
                'recent_tickets' => array_slice($evidence['tickets'], 0, 3),
                'radius_services' => array_slice($evidence['radius_services'], 0, 3),
            ],
        ];
        $recommendations[] = 'Operator RADIUS sessiya tarixcesi, ONT/optik tab ve son ticket qeydlərini musteri ile tutusdurmalidir.';
    }

    $confidence = min(0.92, max(0.25, $confidence));
    $customer = $evidence['customer'];
    $evidenceLines = [
        'Status: ' . (string) ($customer['status'] ?? '-') . ' / RADIUS: ' . (string) ($evidence['billing']['radius_status'] ?? '-'),
        'Balans: ' . (string) ($evidence['billing']['balance'] ?? '-') . ' / Aylig: ' . (string) ($evidence['billing']['monthly_total'] ?? '-') . ' / Qalan gun: ' . (string) ($evidence['billing']['remaining_days'] ?? '-'),
        'Son ticket: ' . (string) (($evidence['tickets'][0]['subject'] ?? $evidence['tickets'][0]['message'] ?? '-') ?: '-'),
    ];
    if (is_array($evidence['payments'][0] ?? null)) {
        $lastPayment = $evidence['payments'][0];
        $evidenceLines[] = 'Son odenis: ' . (string) ($lastPayment['amount'] ?? $lastPayment['payment'] ?? '-') . ' / ' . (string) ($lastPayment['created_at'] ?? $lastPayment['date'] ?? '-');
    }
    if (is_array($evidence['radius_services'][0] ?? null)) {
        $service = $evidence['radius_services'][0];
        $evidenceLines[] = 'RADIUS servis: ' . (string) ($service['username'] ?? '-') . ' / IP: ' . (string) ($service['local_ip'] ?? $service['static_ip'] ?? '-');
    }

    $findingLines = array_map(
        static fn (array $finding): string => '- ' . strtoupper((string) ($finding['severity'] ?? 'info')) . ': ' . (string) ($finding['summary'] ?? ''),
        array_slice($findings, 0, 5)
    );
    $recommendationLines = array_map(
        static fn (string $recommendation): string => '- ' . $recommendation,
        array_slice(array_values(array_unique($recommendations)), 0, 5)
    );
    $answer = implode("\n", [
        'Superpilot cavabi',
        'Musteri: ' . (string) ($customer['full_name'] ?? 'Musteri') . ' / Kod: ' . (string) ($customer['code'] ?? '') . ' / Paket: ' . (string) ($customer['package'] ?? '-'),
        'Etibar: ' . (string) round($confidence * 100) . '%',
        '',
        'Analiz:',
        implode("\n", $findingLines),
        '',
        'Subut:',
        '- ' . implode("\n- ", array_filter($evidenceLines, static fn (string $line): bool => trim($line) !== '')),
        '',
        'Tovsiye:',
        implode("\n", $recommendationLines),
        '',
        'Novbeti addim:',
        (string) ($recommendations[0] ?? 'Musteri 360 profilinde RADIUS, ONT/optik, ticket ve odenis tarixcesini yoxlayin.'),
    ]);

    return [
        'ok' => true,
        'mode' => 'read_only',
        'generated_at' => date(DATE_ATOM),
        'question' => $question,
        'customer' => $customer,
        'answer' => $answer,
        'confidence' => round($confidence, 2),
        'findings' => $findings,
        'recommendations' => array_values(array_unique($recommendations)),
        'evidence' => $evidence,
        'safety' => [
            'read_only' => true,
            'actions_executed' => false,
            'llm_optional' => true,
        ],
    ];
}
