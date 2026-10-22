<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/mobile-auth-lib.php';
require_once dirname(__DIR__, 4) . '/finance-module-lib.php';

$user = app_mobile_authenticate();
// Reuse the same permission as the existing finance ledger, not a mobile role alias.
if (!app_finance_actor_can_write($user)) {
    app_mobile_json_response(null, 403, 'Maliyyə bölməsinə giriş səlahiyyətiniz yoxdur.');
}
$db = app_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $input = app_mobile_request_body();
    if (trim((string)($input['reason'] ?? '')) === '' || trim((string)($input['payment_source'] ?? '')) === '') {
        app_mobile_json_response(null, 422, 'Səbəb və vəsaitin mənbəyi / təyinatı tələb olunur.');
    }
    // Mobile only creates entries; edits and approvals retain the existing web workflow.
    $input['entry_id'] = 0;
    $input['currency'] = 'AZN';
    $input['note'] = (string)$input['reason'];
    $input['idempotency_key'] = 'mobile-finance:' . (int)$user['id'] . ':' . (string)($input['client_uuid'] ?? '');
    $input['employee_account_id'] = (int)$user['id'];
    $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($amount === false || !is_finite($amount) || $amount <= 0) {
        app_mobile_json_response(null, 422, 'Məbləğ sıfırdan böyük olmalıdır.');
    }
    try {
        $result = app_mobile_idempotent_mutation(
            (string)($input['client_uuid'] ?? ''), (int)$user['id'], 'finance_entry', $input,
            static function (array $payload) use ($db, $user): array {
                // The ledger and mobile replay record commit in the same transaction.
                $saved = app_finance_save_entry($db, $payload, $user);
                if (empty($saved['ok'])) {
                    return ['status_code' => 422, 'data' => ['error' => 'Qeyd saxlanmadı. Məbləği və tarixi yoxlayın.']];
                }
                $status = $db->prepare('SELECT status FROM finance_entries WHERE id = ?');
                $status->execute([(int)$saved['id']]);
                return ['status_code' => 201, 'data' => ['id' => $saved['id'], 'status' => $status->fetchColumn()]];
            }
        );
        app_mobile_json_response($result['data'], $result['status_code'], $result['data']['error'] ?? null);
    } catch (Throwable $e) {
        app_mobile_json_response(null, 503, 'Qeyd saxlanmadı. Yenidən sınayın.');
    }
}
if ($method !== 'GET') {
    header('Allow: GET, POST');
    app_mobile_json_response(null, 405, 'Sorğu üsulu dəstəklənmir.');
}
$query = $_GET;
$query['range'] = $query['range'] ?? 'today';
$dates = app_finance_date_range($query);
if (empty($dates['valid'])) {
    app_mobile_json_response(null, 422, 'Tarix aralığı düzgün deyil.');
}
$params = [$dates['start_date'], $dates['end_date']];
$monthly = (new DateTimeImmutable($dates['start_date']))->diff(new DateTimeImmutable($dates['end_date']))->days > 90;
$bucket = $monthly ? 'DATE_FORMAT(transaction_date, "%Y-%m")' : 'transaction_date';
try {
    $entries = $db->prepare('SELECT id, entry_type, title, category, amount, currency, transaction_date, payment_source, note, status, created_by_name FROM finance_entries WHERE transaction_date BETWEEN ? AND ? ORDER BY transaction_date DESC, id DESC LIMIT 200');
    $entries->execute($params);
    $daily = $db->prepare('SELECT ' . $bucket . ' AS day, SUM(CASE WHEN entry_type = "income" THEN amount ELSE 0 END) AS income, SUM(CASE WHEN entry_type = "expense" THEN amount ELSE 0 END) AS expense FROM finance_entries WHERE transaction_date BETWEEN ? AND ? AND status = "posted" AND currency = "AZN" GROUP BY ' . $bucket . ' ORDER BY day');
    $daily->execute($params);
    app_mobile_json_response([
        'period' => $dates,
        'kpis' => app_finance_overview_kpis($db, $query),
        'daily' => $daily->fetchAll(PDO::FETCH_ASSOC),
        'entries' => $entries->fetchAll(PDO::FETCH_ASSOC),
        'entries_limit' => 200,
        'chart_scope' => 'finance_entries_posted_AZN',
        'chart_bucket' => $monthly ? 'month' : 'day',
    ]);
} catch (Throwable $e) {
    app_mobile_json_response(null, 503, 'Maliyyə hesabatı hazırda yüklənmədi.');
}
