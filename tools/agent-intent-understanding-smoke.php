<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require_once $root . '/copilot-agent.php';
require_once $root . '/db.php';

$passes = [];
$failures = [];

function intent_check(string $name, bool $condition, array &$passes, array &$failures, string $detail = ''): void
{
    if ($condition) {
        $passes[] = $name;
        return;
    }
    $failures[] = $name . ($detail !== '' ? ' (' . $detail . ')' : '');
}

/* 1. Typo-tolerant customer-create classification (spec variants). */
$createVariants = [
    'musteri yarat', 'muster yarat', 'musder yarat', 'mustri yarat', 'mstr yarat',
    'yeni abuneci ac', 'yeni client elave et', 'yeni internet qos', 'musteri yarat astarada',
    'MÜŞTƏRİ YARAT', 'yeni müştəri əlavə et',
];
foreach ($createVariants as $variant) {
    $intent = app_copilot_agent_classify_intent($variant)['intent'];
    intent_check('classify create: "' . $variant . '"', $intent === 'customer_create', $passes, $failures, $intent);
}

/* 2. Other families classify to their own intent, not customer_create. */
$familyCases = [
    'inventory' => ['Əliyə HG8145X6 ver', '20 ədəd HG8145X6 mədaxil et', 'stok qalığını göstər'],
    'ticket' => ['2320025 üçün bilet aç internet yoxdur', 'tapşırıq yarat'],
    'report' => ['bu ayın hesabatını hazırla', 'həftəlik statistika'],
    'performance' => ['ən çox işləyən texnik kimdir', 'bu ay gecikən işçilər'],
    'analytics' => ['hansı rayonda internet problemi çoxdur', 'hansı avadanlıq daha çox sıradan çıxır'],
    'customer_show' => ['2320025 borcunu göstər', '2320025 ödəniş tarixçəsi'],
];
foreach ($familyCases as $expected => $samples) {
    foreach ($samples as $sample) {
        $intent = app_copilot_agent_classify_intent($sample)['intent'];
        intent_check($expected . ': "' . $sample . '"', $intent === $expected, $passes, $failures, $intent);
    }
}

/* 3. Area extraction is typo/suffix tolerant. */
intent_check('area: astarada -> Astara', app_copilot_agent_extract_area('musteri yarat astarada') === 'Astara', $passes, $failures, app_copilot_agent_extract_area('musteri yarat astarada'));
intent_check('area: lenkeranda matched', app_copilot_agent_extract_area('yeni abuneci lenkeranda') !== '', $passes, $failures);
intent_check('area: none when absent', app_copilot_agent_extract_area('yeni musteri yarat') === '', $passes, $failures);

/* 4. Field parser pulls labelled fields. */
$parsed = app_copilot_agent_parse_customer_fields('Yeni müştəri: Əli Vəliyev, telefon 0501234567, FIN 1ABC2DE, sənəd AA1234567, ünvan Astara ş. Heydər Əliyev 10');
intent_check('parse phone', ($parsed['phone'] ?? '') === '0501234567', $passes, $failures, $parsed['phone'] ?? '');
intent_check('parse fin', ($parsed['fin_code'] ?? '') === '1ABC2DE', $passes, $failures, $parsed['fin_code'] ?? '');
intent_check('parse document', ($parsed['document_no'] ?? '') === 'AA1234567', $passes, $failures, $parsed['document_no'] ?? '');
intent_check('parse area', ($parsed['area'] ?? '') === 'Astara', $passes, $failures, $parsed['area'] ?? '');
intent_check('parse full name', ($parsed['full_name'] ?? '') === 'Əli Vəliyev', $passes, $failures, $parsed['full_name'] ?? '');

/* 5. Guided flow: partial input asks specifically, never generic error. */
$db = app_db();
if ($db instanceof PDO) {
    $partial = app_copilot_agent_handle_message('musteri yarat astarada', ['id' => 1, 'full_name' => 'Test', 'account_type' => 'admin', 'employee_role' => 'admin'], $db);
    $partialText = (string) ($partial['text'] ?? '');
    intent_check('partial create does not say anlasilmadi', !str_contains(mb_strtolower($partialText), 'anlasilmadi') && !str_contains(mb_strtolower($partialText), 'anlaşılmadı'), $passes, $failures, mb_substr($partialText, 0, 60));
    intent_check('partial create asks for FIN/phone specifically', str_contains($partialText, 'FIN') && str_contains($partialText, 'telefon'), $passes, $failures);
    intent_check('partial create acknowledges Astara', str_contains($partialText, 'Astara'), $passes, $failures);

    /* 6. Unknown input never returns the generic error phrase; and the
     * deterministic guided-help fallback (used when the local LLM is offline)
     * lists capabilities instead of an error. */
    $unknown = app_copilot_agent_handle_message('qwerty zxcvbn asdf', ['id' => 1, 'account_type' => 'admin', 'employee_role' => 'admin'], $db);
    $unknownText = (string) ($unknown['text'] ?? '');
    intent_check('unknown input avoids generic error phrase', !str_contains(mb_strtolower($unknownText), 'anlasilmadi'), $passes, $failures, mb_substr($unknownText, 0, 60));
    $help = app_copilot_agent_guided_fallback('qwerty zxcvbn asdf');
    intent_check('guided-help fallback lists capabilities', str_contains((string) ($help['text'] ?? ''), 'müştəri') && str_contains((string) ($help['text'] ?? ''), 'anbar'), $passes, $failures);

    /* 7. Guided fallback for a detected family asks that family's question. */
    $reportGuide = app_copilot_agent_guided_fallback('bu ayin hesabati');
    intent_check('report guide mentions period example', str_contains((string) ($reportGuide['text'] ?? ''), 'hesabat'), $passes, $failures);
} else {
    $passes[] = 'db-dependent guided checks skipped (database unavailable)';
}

/* 8. create_customer is in the Stage 5 registry (billing, confirmation, reversible, not blocker). */
require_once $root . '/agent-workspace-lib.php';
$registry = app_agent_action_registry();
$entry = $registry['create_customer'] ?? [];
intent_check('create_customer registered', $entry !== [], $passes, $failures);
intent_check('create_customer needs confirmation', !empty($entry['confirmation_required']), $passes, $failures);
intent_check('create_customer is reversible', !empty($entry['reversible']), $passes, $failures);
intent_check('create_customer not a go-live blocker', empty($entry['go_live_blocker']), $passes, $failures);
intent_check('create_customer requires billing module', in_array('billing', app_agent_action_modules($entry), true), $passes, $failures);

echo 'agent_intent_pass=' . count($passes) . PHP_EOL;
if ($failures !== []) {
    echo 'agent_intent_fail=' . count($failures) . PHP_EOL;
    foreach ($failures as $failure) {
        echo 'FAIL: ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo 'agent_intent_fail=0' . PHP_EOL;
exit(0);
