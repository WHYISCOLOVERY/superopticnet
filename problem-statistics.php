<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

app_session_boot();
$lang = app_lang();
$user = app_portal_current_user();

if ($user === null || ($user['role'] ?? '') !== 'employee') {
    header('Location: portal-login.php?status=auth_required' . ($lang !== 'az' ? '&lang=' . rawurlencode($lang) : ''));
    exit;
}

$from = clean_text((string) ($_GET['from'] ?? date('Y-m-01')));
$to = clean_text((string) ($_GET['to'] ?? date('Y-m-d')));
$groupBy = clean_text((string) ($_GET['group_by'] ?? 'day'));
$stats = array_merge([
    'from' => $from,
    'to' => $to,
    'group_by' => in_array($groupBy, ['day', 'week', 'month'], true) ? $groupBy : 'day',
    'periods' => [],
    'rows' => [],
    'totals' => [],
    'total_count' => 0,
    'open_count' => 0,
    'closed_count' => 0,
    'hourly' => [],
    'top_hour' => '-',
    'top_hour_count' => 0,
    'problem_breakdown' => [],
    'employee_breakdown' => [],
    'recent_tickets' => [],
], app_db_problem_statistics($from, $to, $groupBy));

$topReason = $stats['totals'] !== [] ? (string) array_key_first($stats['totals']) : '-';
$topReasonCount = $topReason !== '-' ? (int) ($stats['totals'][$topReason] ?? 0) : 0;
$maxHourly = 1;
foreach ($stats['hourly'] as $row) {
    $maxHourly = max($maxHourly, (int) ($row['total'] ?? 0));
}
$maxReason = 1;
foreach ($stats['problem_breakdown'] as $row) {
    $maxReason = max($maxReason, (int) ($row['total'] ?? 0));
}
$maxEmployee = 1;
foreach ($stats['employee_breakdown'] as $row) {
    $maxEmployee = max($maxEmployee, (int) ($row['total'] ?? 0));
}
$closedRate = problem_stats_percent((int) $stats['closed_count'], (int) $stats['total_count']);
$openRate = problem_stats_percent((int) $stats['open_count'], (int) $stats['total_count']);
$closedDeg = (int) round(($closedRate / 100) * 360);
$openDeg = min(360, $closedDeg + (int) round(($openRate / 100) * 360));
$topReasonsVisual = array_slice($stats['problem_breakdown'], 0, 6);
$topEmployeesVisual = array_slice($stats['employee_breakdown'], 0, 6);
$tablePageSize = 50;
$dynamicRowsAll = is_array($stats['rows']) ? $stats['rows'] : [];
$recentTicketsAll = is_array($stats['recent_tickets']) ? $stats['recent_tickets'] : [];
$dynamicRowsTotal = count($dynamicRowsAll);
$recentTicketsTotal = count($recentTicketsAll);
$dynamicRowsTotalPages = max(1, (int) ceil($dynamicRowsTotal / $tablePageSize));
$recentTicketsTotalPages = max(1, (int) ceil($recentTicketsTotal / $tablePageSize));
$dynamicRowsPageNumber = max(1, min($dynamicRowsTotalPages, (int) ($_GET['rows_page'] ?? 1)));
$recentTicketsPageNumber = max(1, min($recentTicketsTotalPages, (int) ($_GET['tickets_page'] ?? 1)));
$dynamicRowsPage = array_slice($dynamicRowsAll, ($dynamicRowsPageNumber - 1) * $tablePageSize, $tablePageSize);
$recentTicketsPage = array_slice($recentTicketsAll, ($recentTicketsPageNumber - 1) * $tablePageSize, $tablePageSize);
$legacyKnowledge = [];
$db = app_db();
if ($db instanceof PDO) {
    try {
        $legacyKnowledge = $db->query(
            'SELECT problem_title, ticket_count, linked_customer_count, unlinked_ticket_count,
                    resolved_ticket_count, cause_examples_json, solution_examples_json,
                    first_seen_at, last_seen_at
             FROM legacy_ticket_knowledge_base
             ORDER BY ticket_count DESC, problem_title
             LIMIT 20'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($legacyKnowledge as &$knowledgeRow) {
            $knowledgeRow['cause_examples'] = app_db_json_decode(
                (string) ($knowledgeRow['cause_examples_json'] ?? ''),
                []
            );
            $knowledgeRow['solution_examples'] = app_db_json_decode(
                (string) ($knowledgeRow['solution_examples_json'] ?? ''),
                []
            );
        }
        unset($knowledgeRow);
    } catch (Throwable $exception) {
        app_runtime_error_reference('problem_statistics_legacy_knowledge', $exception);
        $legacyKnowledge = [];
    }
}

function problem_stats_bar_width(int $count, int $max): string
{
    if ($max <= 0 || $count <= 0) {
        return '0%';
    }

    return max(4, min(100, (int) round(($count / $max) * 100))) . '%';
}

function problem_stats_percent(int $count, int $total): int
{
    if ($total <= 0 || $count <= 0) {
        return 0;
    }

    return min(100, (int) round(($count / $total) * 100));
}

function problem_stats_page_url(string $pageParam, int $page): string
{
    $query = $_GET;
    $query[$pageParam] = max(1, $page);

    return 'problem-statistics.php?' . http_build_query($query);
}

function problem_stats_pagination(string $pageParam, int $currentPage, int $totalPages, int $totalRows, int $pageSize): void
{
    if ($totalRows <= $pageSize) {
        return;
    }

    $fromRow = (($currentPage - 1) * $pageSize) + 1;
    $toRow = min($totalRows, $currentPage * $pageSize);
    ?>
    <div class="stats-pagination" aria-label="Cedvel sehifeleme">
        <div class="stats-pagination-info">
            <?= e((string) $fromRow); ?>-<?= e((string) $toRow); ?> / <?= e((string) $totalRows); ?> gosterilir
        </div>
        <div class="stats-pagination-actions">
            <a class="portal-ghost-btn portal-inline-link <?= $currentPage <= 1 ? 'is-disabled' : ''; ?>" href="<?= e(problem_stats_page_url($pageParam, max(1, $currentPage - 1))); ?>">Evvelki 50</a>
            <span class="stats-page-count"><?= e((string) $currentPage); ?> / <?= e((string) $totalPages); ?></span>
            <a class="portal-ghost-btn portal-inline-link <?= $currentPage >= $totalPages ? 'is-disabled' : ''; ?>" href="<?= e(problem_stats_page_url($pageParam, min($totalPages, $currentPage + 1))); ?>">Novbeti 50</a>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Problem statistikasi</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .stats-filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;align-items:end}
        .stats-visual-board{display:grid;grid-template-columns:1.05fr 1.35fr 1fr 1fr;gap:22px;padding:24px;border-radius:34px;background:linear-gradient(135deg,#10192f 0%,#1a1230 48%,#17233c 100%);box-shadow:0 30px 80px rgba(15,25,47,.24);color:#fff}
        .stats-visual-card{position:relative;min-height:260px;padding:22px;border:1px solid rgba(255,255,255,.09);border-radius:28px;background:rgba(255,255,255,.06);box-shadow:inset 0 1px 0 rgba(255,255,255,.08)}
        .stats-visual-card h3{margin:0 0 8px;font-size:18px;color:#fff}
        .stats-visual-card small{color:rgba(255,255,255,.68)}
        .stats-ring-wrap{display:grid;place-items:center;min-height:210px}
        .stats-ring{position:relative;width:190px;height:190px;border-radius:50%;background:conic-gradient(#08bfe8 0deg <?= e((string) $closedDeg); ?>deg,#ff8954 <?= e((string) $closedDeg); ?>deg <?= e((string) $openDeg); ?>deg,rgba(255,255,255,.14) <?= e((string) $openDeg); ?>deg 360deg);box-shadow:0 18px 42px rgba(0,0,0,.25)}
        .stats-ring::before{content:"";position:absolute;inset:18px;border-radius:50%;background:#161429;box-shadow:inset 0 0 0 10px rgba(255,255,255,.06)}
        .stats-ring-core{position:absolute;inset:45px;display:grid;place-items:center;text-align:center;border-radius:50%;background:linear-gradient(145deg,#201a34,#111a2f);z-index:1}
        .stats-ring-core strong{font-size:34px;line-height:1;color:#fff}
        .stats-ring-core span{font-size:12px;color:#b8c8dc;text-transform:uppercase;letter-spacing:.09em}
        .stats-legend{display:grid;gap:9px;margin-top:8px}
        .stats-legend-item{display:flex;align-items:center;justify-content:space-between;gap:12px;color:#dce8f5;font-weight:800}
        .stats-dot{width:11px;height:11px;border-radius:50%;display:inline-block;margin-right:8px;background:#08bfe8}
        .stats-dot.is-orange{background:#ff8954}
        .stats-dot.is-pink{background:#f23c86}
        .stats-histogram{display:flex;align-items:end;gap:8px;height:188px;margin-top:22px;padding:0 4px 8px;border-bottom:1px solid rgba(255,255,255,.22)}
        .stats-histo-bar{position:relative;flex:1;min-width:8px;border-radius:12px 12px 4px 4px;background:linear-gradient(180deg,#f8bd5c,#ff704f);box-shadow:0 12px 24px rgba(255,112,79,.18)}
        .stats-histo-bar:nth-child(3n+1){background:linear-gradient(180deg,#0fd0ee,#0676bd)}
        .stats-histo-bar:nth-child(3n+2){background:linear-gradient(180deg,#f43f8f,#b31f72)}
        .stats-histo-bar.is-peak{outline:3px solid rgba(255,255,255,.28);box-shadow:0 0 24px rgba(12,208,238,.42)}
        .stats-histo-bar span{position:absolute;left:50%;bottom:-28px;transform:translateX(-50%);font-size:10px;color:rgba(255,255,255,.62)}
        .stats-histo-bar em{position:absolute;left:50%;top:-24px;transform:translateX(-50%);font-style:normal;font-size:11px;font-weight:900;color:#fff}
        .stats-progress-list{display:grid;gap:13px;margin-top:18px}
        .stats-progress-item{display:grid;grid-template-columns:120px 1fr 52px;gap:12px;align-items:center}
        .stats-progress-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:900;color:#fff}
        .stats-progress-track{height:18px;border-radius:999px;background:rgba(255,255,255,.16);overflow:hidden}
        .stats-progress-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#0fc9e8,#1f9ff0)}
        .stats-progress-item:nth-child(2n) .stats-progress-fill{background:linear-gradient(90deg,#ff7b55,#ffc45c)}
        .stats-progress-item:nth-child(3n) .stats-progress-fill{background:linear-gradient(90deg,#f43f8f,#ff6a9b)}
        .stats-progress-count{text-align:right;font-weight:900;color:#fff}
        .stats-section-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px}
        .stats-panel{padding:24px;border:1px solid rgba(81,104,135,.16);border-radius:28px;background:#fff;box-shadow:0 24px 60px rgba(24,52,86,.06)}
        .stats-panel h3{margin:0 0 6px;font-size:20px;color:#10243f}
        .stats-panel small{color:#617391}
        .stats-bars{display:grid;gap:12px;margin-top:18px}
        .stats-bar-row{display:grid;grid-template-columns:92px 1fr 72px;gap:14px;align-items:center}
        .stats-bar-label{font-weight:800;color:#10243f}
        .stats-bar-track{height:16px;border-radius:999px;background:#edf4f7;overflow:hidden}
        .stats-bar-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#0f8994,#ff884d)}
        .stats-bar-fill.is-green{background:linear-gradient(90deg,#159768,#70d7a2)}
        .stats-bar-fill.is-blue{background:linear-gradient(90deg,#1769e8,#59b2ff)}
        .stats-bar-count{text-align:right;font-weight:900;color:#10243f}
        .stats-reason-row{display:grid;grid-template-columns:minmax(180px,1fr) 1.4fr 90px 90px 90px;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid #edf2f6}
        .stats-reason-row:last-child{border-bottom:0}
        .stats-reason-title{font-weight:900;color:#10243f}
        .stats-mini{font-size:13px;color:#617391}
        .stats-table{width:100%;table-layout:fixed}
        .stats-table td,.stats-table th{white-space:normal;overflow-wrap:anywhere;word-break:break-word;vertical-align:top;line-height:1.35}
        .stats-table small{display:block;margin-top:4px;color:#617391;line-height:1.3}
        .stats-dynamic-table th:nth-child(1){width:18%}
        .stats-dynamic-table th:nth-child(2){width:34%}
        .stats-dynamic-table th:nth-child(n+3){width:12%}
        .stats-ticket-table th:nth-child(1){width:15%}
        .stats-ticket-table th:nth-child(2){width:14%}
        .stats-ticket-table th:nth-child(3){width:18%}
        .stats-ticket-table th:nth-child(4){width:12%}
        .stats-ticket-table th:nth-child(5){width:11%}
        .stats-ticket-table th:nth-child(6){width:15%}
        .stats-ticket-table th:nth-child(7){width:15%}
        .stats-ticket-main{display:grid;gap:4px;min-width:0}
        .stats-ticket-main strong{display:block;color:#10243f}
        .stats-ticket-main span{display:block;color:#617391;font-size:13px}
        .stats-ticket-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
        .stats-pagination{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-top:18px;padding:14px 16px;border:1px solid #e1e9f1;border-radius:22px;background:#f8fbfd}
        .stats-pagination-info{font-weight:800;color:#617391}
        .stats-pagination-actions{display:flex;align-items:center;gap:12px}
        .stats-page-count{font-weight:900;color:#10243f}
        .stats-pagination .is-disabled{pointer-events:none;opacity:.45}
        .status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:8px 12px;background:#e8f5f0;color:#09663f;font-weight:900}
        .status-pill.is-open{background:#fff0e7;color:#9a4217}
        .legacy-knowledge-list{display:grid;gap:12px}
        .legacy-knowledge-row{display:grid;grid-template-columns:minmax(190px,.8fr) minmax(0,1.4fr) minmax(0,1.4fr);gap:18px;padding:18px 0;border-bottom:1px solid #e7edf3}
        .legacy-knowledge-row:last-child{border-bottom:0}
        .legacy-knowledge-title{display:grid;align-content:start;gap:8px}
        .legacy-knowledge-title strong{font-size:17px;color:#10243f}
        .legacy-knowledge-metrics{display:flex;flex-wrap:wrap;gap:6px}
        .legacy-knowledge-example{display:grid;align-content:start;gap:6px;min-width:0}
        .legacy-knowledge-example span{font-weight:900;color:#10243f}
        .legacy-knowledge-example p{margin:0;color:#617391;line-height:1.45;overflow-wrap:anywhere}
        @media (max-width: 1100px){
            .stats-filters,.stats-section-grid,.stats-visual-board{grid-template-columns:1fr}
            .stats-reason-row{grid-template-columns:1fr}
        }
        @media (max-width: 700px){
            .stats-filters{grid-template-columns:1fr}
            .stats-panel{padding:18px}
            .stats-bar-row{grid-template-columns:72px 1fr 54px}
            .stats-visual-board{padding:16px;border-radius:26px}
            .stats-visual-card{min-height:auto;padding:18px}
            .stats-ring{width:160px;height:160px}
            .stats-ring-core{inset:40px}
            .stats-histogram{gap:4px}
            .stats-progress-item{grid-template-columns:1fr}
            .legacy-knowledge-row{grid-template-columns:1fr}
            .stats-pagination{align-items:stretch;flex-direction:column}
            .stats-pagination-actions{justify-content:space-between}
        }
    </style>
</head>
<body class="portal-body">
    <header class="portal-topbar">
        <div class="portal-topbar-inner">
            <a href="crm.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" class="portal-brand">
                <img src="images/favicon.png" alt="Super Optik Tech">
                <span>Problem statistikasi</span>
            </a>
            <div class="portal-topbar-actions">
                <a class="portal-ghost-btn portal-inline-link" href="crm.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">CRM-e qayit</a>
            </div>
        </div>
    </header>

    <main class="portal-layout">
        <section class="portal-hero portal-reveal">
            <div>
                <span class="portal-kicker">Ticket analitika</span>
                <h1>Problem sebebleri, saatlar ve emekdas performansi</h1>
                <p>Acilan ve baglanan biletler real ticket cedvelinden hesablanir. Smoke test ticketleri statistikaya daxil edilmir.</p>
            </div>
            <div class="portal-status-card">
                <span>Cem bilet</span>
                <strong><?= e((string) $stats['total_count']); ?></strong>
                <small>Top sebeb: <?= e($topReason); ?><?= $topReasonCount > 0 ? ' / ' . e((string) $topReasonCount) : ''; ?></small>
            </div>
        </section>

        <section class="portal-grid portal-grid-4 portal-reveal" data-auto-filter-results="problem-kpis" data-auto-filter-form="problem-statistics-filter">
            <article class="portal-metric-card">
                <span>Aciq</span>
                <strong><?= e((string) $stats['open_count']); ?></strong>
                <small>Helle gozleyen biletler</small>
            </article>
            <article class="portal-metric-card">
                <span>Hell olunub</span>
                <strong><?= e((string) $stats['closed_count']); ?></strong>
                <small>Baglanmis / resolved</small>
            </article>
            <article class="portal-metric-card">
                <span>Pik saat</span>
                <strong><?= e((string) $stats['top_hour']); ?></strong>
                <small><?= e((string) $stats['top_hour_count']); ?> bilet acilib</small>
            </article>
            <article class="portal-metric-card">
                <span>Tarix araligi</span>
                <strong><?= e((string) $stats['from']); ?> - <?= e((string) $stats['to']); ?></strong>
                <small><?= e((string) $stats['group_by']); ?> qruplashdirma</small>
            </article>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Filtr</h2>
                <small>Tarix araligini ve qruplashdirmani secin.</small>
            </div>
            <form class="stats-filters" method="get" id="problem-statistics-filter" data-auto-filter data-auto-filter-targets="problem-kpis,problem-visuals,problem-breakdown,problem-dynamics,problem-tickets">
                <?php if ($lang !== 'az'): ?>
                    <input type="hidden" name="lang" value="<?= e($lang); ?>">
                <?php endif; ?>
                <div class="portal-form-row">
                    <label for="statsFrom">Baslangic tarixi</label>
                    <input id="statsFrom" type="date" name="from" value="<?= e((string) $stats['from']); ?>">
                </div>
                <div class="portal-form-row">
                    <label for="statsTo">Bitis tarixi</label>
                    <input id="statsTo" type="date" name="to" value="<?= e((string) $stats['to']); ?>">
                </div>
                <div class="portal-form-row">
                    <label for="statsGroup">Qruplashdirma</label>
                    <select id="statsGroup" name="group_by">
                        <option value="day" <?= $stats['group_by'] === 'day' ? 'selected' : ''; ?>>Gunluk</option>
                        <option value="week" <?= $stats['group_by'] === 'week' ? 'selected' : ''; ?>>Heftelik</option>
                        <option value="month" <?= $stats['group_by'] === 'month' ? 'selected' : ''; ?>>Aylik</option>
                    </select>
                </div>
                <div class="portal-inline-inputs">
                    <button type="submit" class="portal-primary-btn">Goster</button>
                    <a class="portal-ghost-btn portal-inline-link" href="problem-statistics.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Sifirla</a>
                </div>
                <small data-auto-filter-status role="status" aria-live="polite"></small>
            </form>
        </section>

        <section class="portal-card portal-reveal" id="legacy-ticket-knowledge">
            <div class="portal-card-head">
                <div>
                    <h2>Köhnə ticket bilik bazası</h2>
                    <small>Müştəri məlumatları maskalanıb; səbəb və görülən iş nümunələri tarixi ticketlərdən götürülür.</small>
                </div>
                <span class="portal-pill"><?= e((string) count($legacyKnowledge)); ?> problem qrupu</span>
            </div>
            <?php if ($legacyKnowledge === []): ?>
                <div class="portal-alert">
                    <i class="fa-solid fa-database"></i>
                    <span>Tarixi analiz hazır deyil. Köhnə ticket importu və bilik bazası yenilənəndən sonra nəticələr burada görünəcək.</span>
                </div>
            <?php else: ?>
                <div class="legacy-knowledge-list">
                    <?php foreach ($legacyKnowledge as $knowledge): ?>
                        <?php
                        $cause = is_array($knowledge['cause_examples'][0] ?? null)
                            ? (string) ($knowledge['cause_examples'][0]['text'] ?? '')
                            : '';
                        $solution = is_array($knowledge['solution_examples'][0] ?? null)
                            ? (string) ($knowledge['solution_examples'][0]['text'] ?? '')
                            : '';
                        ?>
                        <article class="legacy-knowledge-row">
                            <div class="legacy-knowledge-title">
                                <strong><?= e((string) ($knowledge['problem_title'] ?? 'Kateqoriyasız problem')); ?></strong>
                                <div class="legacy-knowledge-metrics">
                                    <span class="portal-pill"><?= e((string) ($knowledge['ticket_count'] ?? 0)); ?> ticket</span>
                                    <span class="portal-pill"><?= e((string) ($knowledge['linked_customer_count'] ?? 0)); ?> müştəri</span>
                                    <?php if ((int) ($knowledge['unlinked_ticket_count'] ?? 0) > 0): ?>
                                        <span class="portal-pill"><?= e((string) $knowledge['unlinked_ticket_count']); ?> əlaqəsiz</span>
                                    <?php endif; ?>
                                </div>
                                <small><?= e((string) ($knowledge['first_seen_at'] ?? '-')); ?> - <?= e((string) ($knowledge['last_seen_at'] ?? '-')); ?></small>
                            </div>
                            <div class="legacy-knowledge-example">
                                <span>Problem qeydi</span>
                                <p><?= e($cause !== '' ? $cause : 'Qeyd nümunəsi yoxdur.'); ?></p>
                            </div>
                            <div class="legacy-knowledge-example">
                                <span>Görülən iş / həll</span>
                                <p><?= e($solution !== '' ? $solution : 'Həll qeydi yoxdur.'); ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="stats-visual-board portal-reveal" aria-label="Problem statistikasi vizual paneli" data-auto-filter-results="problem-visuals" data-auto-filter-form="problem-statistics-filter">
            <article class="stats-visual-card">
                <h3>Umumi veziyyet</h3>
                <small>Aciq ve hell olunan biletlerin nisbati.</small>
                <div class="stats-ring-wrap">
                    <div class="stats-ring">
                        <div class="stats-ring-core">
                            <div>
                                <strong><?= e((string) $stats['total_count']); ?></strong>
                                <span>cem bilet</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="stats-legend">
                    <div class="stats-legend-item"><span><i class="stats-dot"></i>Hell olunub</span><strong><?= e((string) $closedRate); ?>%</strong></div>
                    <div class="stats-legend-item"><span><i class="stats-dot is-orange"></i>Aciq qalib</span><strong><?= e((string) $openRate); ?>%</strong></div>
                    <div class="stats-legend-item"><span><i class="stats-dot is-pink"></i>Pik saat</span><strong><?= e((string) $stats['top_hour']); ?></strong></div>
                </div>
            </article>

            <article class="stats-visual-card">
                <h3>Saatliq yuklenme</h3>
                <small>Hansi saatlarda daha cox bilet acildigini gosterir.</small>
                <div class="stats-histogram">
                    <?php foreach ($stats['hourly'] as $row): ?>
                        <?php
                        $hourTotal = (int) ($row['total'] ?? 0);
                        $height = max(8, min(100, (int) round(($hourTotal / $maxHourly) * 100)));
                        $isPeak = (string) $row['hour'] === (string) $stats['top_hour'];
                        ?>
                        <div class="stats-histo-bar <?= $isPeak ? 'is-peak' : ''; ?>" style="height:<?= e((string) $height); ?>%" title="<?= e((string) $row['hour']); ?> - <?= e((string) $hourTotal); ?> bilet">
                            <?php if ($hourTotal > 0): ?><em><?= e((string) $hourTotal); ?></em><?php endif; ?>
                            <?php if (((int) substr((string) $row['hour'], 0, 2)) % 4 === 0): ?><span><?= e((string) $row['hour']); ?></span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="stats-visual-card">
                <h3>Top sebebler</h3>
                <small>En cox tekrarlanan problem tipleri ve paylari.</small>
                <div class="stats-progress-list">
                    <?php foreach ($topReasonsVisual as $row): ?>
                        <div class="stats-progress-item">
                            <span class="stats-progress-label" title="<?= e((string) $row['reason']); ?>"><?= e((string) $row['reason']); ?></span>
                            <span class="stats-progress-track">
                                <span class="stats-progress-fill" style="width:<?= e(problem_stats_bar_width((int) $row['total'], $maxReason)); ?>"></span>
                            </span>
                            <span class="stats-progress-count"><?= e((string) $row['total']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($topReasonsVisual === []): ?>
                        <div class="stats-mini">Bu tarix araliginda sebeb tapilmadi.</div>
                    <?php endif; ?>
                </div>
            </article>

            <article class="stats-visual-card">
                <h3>Emekdas paylanmasi</h3>
                <small>Bilet acan emekdaslar ayrica analiz olunur.</small>
                <div class="stats-progress-list">
                    <?php foreach ($topEmployeesVisual as $row): ?>
                        <div class="stats-progress-item">
                            <span class="stats-progress-label" title="<?= e((string) $row['opened_by']); ?>"><?= e((string) $row['opened_by']); ?></span>
                            <span class="stats-progress-track">
                                <span class="stats-progress-fill" style="width:<?= e(problem_stats_bar_width((int) $row['total'], $maxEmployee)); ?>"></span>
                            </span>
                            <span class="stats-progress-count"><?= e((string) $row['total']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($topEmployeesVisual === []): ?>
                        <div class="stats-mini">Bu tarix araliginda emekdas statistikasi yoxdur.</div>
                    <?php endif; ?>
                </div>
            </article>
        </section>

        <section class="portal-card portal-reveal" data-auto-filter-results="problem-breakdown" data-auto-filter-form="problem-statistics-filter">
            <div class="portal-card-head">
                <h2>Problem sebebleri</h2>
                <small>Her sebeb uzre cem, aciq, hell olunan ve orta hell vaxti.</small>
            </div>
            <?php if ($stats['problem_breakdown'] === []): ?>
                <div class="portal-empty-state">Secilen tarix araliginda bilet tapilmadi.</div>
            <?php else: ?>
                <?php foreach ($stats['problem_breakdown'] as $row): ?>
                    <div class="stats-reason-row">
                        <div>
                            <div class="stats-reason-title"><?= e((string) $row['reason']); ?></div>
                            <div class="stats-mini"><?= e((string) $row['percent']); ?>% pay</div>
                        </div>
                        <span class="stats-bar-track"><span class="stats-bar-fill is-green" style="width:<?= e(problem_stats_bar_width((int) $row['total'], $maxReason)); ?>"></span></span>
                        <strong><?= e((string) $row['total']); ?> cem</strong>
                        <span><?= e((string) $row['open_count']); ?> aciq</span>
                        <span><?= e((string) $row['closed_count']); ?> hell</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="portal-card portal-reveal" data-auto-filter-results="problem-dynamics" data-auto-filter-form="problem-statistics-filter">
            <div class="portal-card-head">
                <h2>Dinamika cedveli</h2>
                <small>Tarix ve problem sebebi uzre aciq/hell olunan bilet sayi.</small>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table stats-table stats-dynamic-table">
                    <thead>
                        <tr>
                            <th>Tarix</th>
                            <th>Problem sebebi</th>
                            <th>Cem</th>
                            <th>Aciq</th>
                            <th>Hell</th>
                            <th>Faiz</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($dynamicRowsAll === []): ?>
                            <tr><td colspan="6">Secilen tarix araliginda statistika yoxdur.</td></tr>
                        <?php else: ?>
                            <?php foreach ($dynamicRowsPage as $row): ?>
                                <tr>
                                    <td data-label="Tarix"><?= e((string) $row['period']); ?></td>
                                    <td data-label="Problem"><?= e((string) $row['reason']); ?></td>
                                    <td data-label="Cem"><?= e((string) $row['count']); ?></td>
                                    <td data-label="Aciq"><?= e((string) $row['open_count']); ?></td>
                                    <td data-label="Hell"><?= e((string) $row['closed_count']); ?></td>
                                    <td data-label="Faiz"><?= e((string) $row['percent']); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php problem_stats_pagination('rows_page', $dynamicRowsPageNumber, $dynamicRowsTotalPages, $dynamicRowsTotal, $tablePageSize); ?>
        </section>

        <section class="portal-card portal-reveal" data-auto-filter-results="problem-tickets" data-auto-filter-form="problem-statistics-filter">
            <div class="portal-card-head">
                <h2>Son biletler</h2>
                <small>Her sehifede maksimum 50 bilet gosterilir. Emekdas qeyd icinde deyil, ayrica sutunda gosterilir.</small>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table stats-table stats-ticket-table">
                    <thead>
                        <tr>
                            <th>Ticket / tarix</th>
                            <th>Kod / musteri</th>
                            <th>Elaqe / erazi</th>
                            <th>Problem</th>
                            <th>Status</th>
                            <th>Acan emekdas</th>
                            <th>Texnik / hell tarixi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentTicketsAll === []): ?>
                            <tr><td colspan="7">Secilen tarix araliginda bilet yoxdur.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentTicketsPage as $ticket): ?>
                                <?php
                                $status = (string) ($ticket['status'] ?? '');
                                $isClosed = (string) ($ticket['resolved_at'] ?? '') !== '' || in_array($status, ['Baglandi', 'Bağlandı', 'closed', 'resolved'], true);
                                ?>
                                <tr>
                                    <td data-label="Ticket / tarix">
                                        <div class="stats-ticket-main">
                                            <strong><?= e((string) $ticket['ticket_no']); ?></strong>
                                            <span><?= e((string) $ticket['opened_at']); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Kod / musteri">
                                        <div class="stats-ticket-main">
                                            <strong><?= e((string) ($ticket['subscriber_code'] ?? '-')); ?></strong>
                                            <span><?= e((string) $ticket['customer_name']); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Elaqe / erazi">
                                        <div class="stats-ticket-main">
                                            <strong><?= e((string) ($ticket['phone'] ?? '-')); ?></strong>
                                            <span><?= e((string) ($ticket['area'] ?? '-')); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Problem"><?= e((string) $ticket['subject']); ?></td>
                                    <td data-label="Status"><span class="status-pill <?= $isClosed ? '' : 'is-open'; ?>"><?= e($isClosed ? 'Hell olunub' : $status); ?></span></td>
                                    <td data-label="Acan emekdas"><?= e((string) $ticket['opened_by']); ?></td>
                                    <td data-label="Texnik / hell tarixi">
                                        <div class="stats-ticket-main">
                                            <strong><?= e((string) $ticket['assigned_to']); ?></strong>
                                            <span><?= e((string) ($ticket['resolved_at'] ?? '-')); ?></span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php problem_stats_pagination('tickets_page', $recentTicketsPageNumber, $recentTicketsTotalPages, $recentTicketsTotal, $tablePageSize); ?>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <?php require_once __DIR__ . '/copilot-widget.php'; app_render_copilot_widget(function_exists('app_lang') ? app_lang() : 'az'); ?>
</body>
</html>
