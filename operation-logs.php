<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/audit-log-lib.php';

$user = billing_require_employee(true);
$lang = app_lang();

if (!app_audit_actor_can_view($user)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="' . e($lang) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>403</title><link rel="stylesheet" href="' . e(app_asset('css/portal.css')) . '"></head><body class="portal-body"><main class="portal-layout"><section class="portal-card"><h1>403</h1><p>Emeliyyat jurnalini yalniz admin rollari gore biler.</p><a class="portal-primary-btn portal-inline-link" href="portal.php">Esas portala qayit</a></section></main></body></html>';
    exit;
}

$db = app_db();
app_audit_ensure_schema($db);
$isSuperAdmin = app_audit_actor_can_manage($user);

function operation_logs_filters(array $source): array
{
    $filters = [];
    foreach (['module', 'action', 'status', 'date_from', 'date_to', 'actor', 'entity', 'search'] as $key) {
        $value = trim((string) ($source[$key] ?? ''));
        if ($value !== '') {
            $filters[$key] = $value;
        }
    }
    return $filters;
}

function operation_logs_query_url(array $base, array $overrides = []): string
{
    unset($base['result'], $base['error']);
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($base[$key]);
        } else {
            $base[$key] = $value;
        }
    }
    $query = http_build_query($base);
    return 'operation-logs.php' . ($query !== '' ? '?' . $query : '');
}

function operation_logs_export_url(array $base): string
{
    unset($base['result'], $base['error']);
    $query = http_build_query($base);
    return 'operation-logs-export.php' . ($query !== '' ? '?' . $query : '');
}

function operation_logs_json_preview(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    $raw = (string) $value;
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $raw;
    } catch (Throwable) {
        return $raw;
    }
}

function operation_logs_text(mixed $value, string $fallback = '-'): string
{
    $text = trim((string) ($value ?? ''));
    return $text !== '' ? $text : $fallback;
}

function operation_logs_actor_label(array $row): string
{
    $name = operation_logs_text($row['actor_name'] ?? null, 'system');
    $id = trim((string) ($row['actor_account_id'] ?? ''));
    return $id !== '' ? $name . ' #' . $id : $name;
}

function operation_logs_flash(string $status, string $error = ''): ?array
{
    $messages = [
        'audit_visible_updated' => ['type' => 'success', 'message' => 'Jurnal qeydi yenilendi.'],
        'audit_visible_deleted' => ['type' => 'success', 'message' => 'Jurnal qeydi gorunen siyahidan gizledildi. Ledger saxlanildi.'],
        'audit_forbidden' => ['type' => 'error', 'message' => 'Bu emeliyyati yalniz super admin ede biler.'],
        'audit_csrf_failed' => ['type' => 'error', 'message' => 'Sessiya tesdiqi ugursuz oldu. Sehifeni yenileyib tekrar edin.'],
        'audit_reason_required' => ['type' => 'error', 'message' => 'Deyisiklik ucun sebeb mutleq yazilmalidir.'],
        'audit_action_failed' => ['type' => 'error', 'message' => 'Jurnal emeliyyati tamamlanmadi.'],
    ];
    if (!isset($messages[$status])) {
        return null;
    }
    if ($status === 'audit_action_failed' && $error !== '') {
        $messages[$status]['message'] .= ' Texniki kod: ' . substr($error, 0, 120);
    }
    return $messages[$status];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $redirectStatus = 'audit_action_failed';
    try {
        if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
            $redirectStatus = 'audit_csrf_failed';
        } elseif (!$isSuperAdmin) {
            $redirectStatus = 'audit_forbidden';
        } elseif ($action === 'edit_visible_audit') {
            app_audit_edit_visible(
                $db,
                (int) ($_POST['action_id'] ?? 0),
                (string) ($_POST['summary'] ?? ''),
                (string) ($_POST['reason'] ?? ''),
                $user
            );
            $redirectStatus = 'audit_visible_updated';
        } elseif ($action === 'delete_visible_audit') {
            app_audit_delete_visible(
                $db,
                (int) ($_POST['action_id'] ?? 0),
                (string) ($_POST['reason'] ?? ''),
                $user
            );
            $redirectStatus = 'audit_visible_deleted';
        }
        header('Location: ' . operation_logs_query_url($_GET, ['tab' => 'operational', 'result' => $redirectStatus]));
        exit;
    } catch (InvalidArgumentException $exception) {
        $status = $exception->getMessage() === 'reason_required' ? 'audit_reason_required' : 'audit_action_failed';
        header('Location: ' . operation_logs_query_url($_GET, ['tab' => 'operational', 'result' => $status, 'error' => $exception->getMessage()]));
        exit;
    } catch (Throwable $exception) {
        header('Location: ' . operation_logs_query_url($_GET, ['tab' => 'operational', 'result' => 'audit_action_failed', 'error' => $exception->getMessage()]));
        exit;
    }
}

$tab = strtolower(trim((string) ($_GET['tab'] ?? 'operational'))) === 'ledger' ? 'ledger' : 'operational';
$filters = operation_logs_filters($_GET);
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageData = $tab === 'ledger'
    ? app_audit_ledger_page($db, $filters, $page, 50)
    : app_audit_operation_page($db, $filters, $page, 50);
$rows = is_array($pageData['rows'] ?? null) ? $pageData['rows'] : [];
$result = (string) ($_GET['result'] ?? '');
$flash = operation_logs_flash($result, (string) ($_GET['error'] ?? ''));
$chainStatus = $tab === 'ledger' ? app_audit_verify_chain($db) : null;
$baseQuery = $_GET;
unset($baseQuery['result'], $baseQuery['error']);
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emeliyyat jurnali</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .audit-shell{display:flex;flex-direction:column;gap:18px}
        .audit-tabs{display:flex;gap:10px;flex-wrap:wrap}
        .audit-tabs a{display:inline-flex;align-items:center;gap:8px;min-height:42px;padding:10px 16px;border-radius:999px;background:#e8eef5;color:#10223b;text-decoration:none;font-weight:900}
        .audit-tabs a.is-active{background:#10223b;color:#fff}
        .audit-filter{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end}
        .audit-filter label{display:grid;gap:6px;font-weight:900;color:#10223b}
        .audit-filter input,.audit-filter select,.audit-inline-form input,.audit-inline-form textarea{width:100%;min-width:0;box-sizing:border-box;border:1px solid rgba(16,34,59,.12);border-radius:14px;padding:11px 12px;background:#fff;color:#10223b;font:inherit}
        .audit-filter-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .audit-table-wrap{overflow:auto;border:1px solid rgba(16,34,59,.08);border-radius:18px;background:#fff}
        .audit-table{width:100%;min-width:1120px;border-collapse:collapse;table-layout:fixed}
        .audit-table th,.audit-table td{padding:13px 12px;border-bottom:1px solid rgba(16,34,59,.08);vertical-align:top;text-align:left;overflow-wrap:anywhere}
        .audit-table th{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:#5b6c87;background:#f6f9fc}
        .audit-table td{font-weight:750;color:#142943}
        .audit-table small{display:block;margin-top:4px;color:#65768f;font-weight:650;line-height:1.35}
        .audit-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border-radius:999px;background:#e7f7f8;color:#08616a;font-weight:900;font-size:.78rem}
        .audit-pill.audit-muted{background:#edf1f6;color:#586b83}
        .audit-detail{display:block;margin-top:8px}
        .audit-detail summary{cursor:pointer;font-weight:900;color:#078696}
        .audit-detail pre{max-height:260px;overflow:auto;margin:8px 0 0;padding:12px;border-radius:14px;background:#0d223b;color:#e7f7ff;white-space:pre-wrap;font-size:.8rem}
        .audit-inline-form{display:grid;gap:8px;margin-top:8px}
        .audit-inline-form textarea{min-height:70px;resize:vertical}
        .audit-actions{display:grid;gap:10px}
        .audit-pagination{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;align-items:center}
        .audit-pagination a,.audit-pagination span{display:inline-flex;align-items:center;justify-content:center;min-width:38px;min-height:38px;padding:7px 11px;border-radius:12px;text-decoration:none;font-weight:900;background:#eef3f8;color:#10223b}
        .audit-pagination .is-active{background:#10223b;color:#fff}
        .audit-summary-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
        .audit-summary-row > *{min-width:0}
        @media (max-width:720px){
            .audit-filter-actions{display:grid;grid-template-columns:1fr;width:100%}
            .audit-filter-actions > *{justify-content:center;width:100%}
        }
    </style>
</head>
<body class="portal-body">
    <header class="portal-topbar">
        <div class="portal-topbar-inner">
            <a href="portal.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>" class="portal-brand">
                <img src="images/favicon.png" alt="Super Optik Tech">
                <span>Emeliyyat jurnali</span>
            </a>
            <div class="portal-topbar-actions">
                <a class="portal-ghost-btn portal-inline-link" href="admin-users.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Istifadeci idareetmesi</a>
                <a class="portal-ghost-btn portal-inline-link" href="portal.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Esas portala qayit</a>
            </div>
        </div>
    </header>

    <main class="portal-layout audit-shell" data-audit-log-root>
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="portal-hero portal-reveal">
            <div>
                <span class="portal-kicker">Admin control center</span>
                <h1>Emeliyyat jurnali</h1>
                <p>Kim hansi musteride, maliyyede, anbarda, HR-da ve icaze panelinde ne edib burada saxlanilir. Gorunen jurnal DB-de <code>mgs_operation_actions</code>, qorunan ledger ise <code>security_audit_ledger</code> cedvelinde qalir. Adminler baxir, yalniz super admin gorunen qeyde mudahile ede bilir.</p>
            </div>
            <div class="portal-status-card">
                <span>Rol</span>
                <strong><?= e($isSuperAdmin ? 'Super admin' : 'Admin'); ?></strong>
                <small><?= e((string) ($user['login'] ?? $user['full_name'] ?? '')); ?></small>
                <small><?= e($isSuperAdmin ? 'Gorunen jurnal qeydi sebeb yazilmaqla duzelde ve gizledile biler.' : 'Adminler yalniz baxa ve export ede biler; deyisiklik super admin ucundur.'); ?></small>
            </div>
        </section>

        <section class="portal-card portal-reveal">
            <div class="audit-summary-row">
                <div class="audit-tabs" aria-label="Audit jurnal tablari" data-audit-tabs>
                    <a href="<?= e(operation_logs_query_url($baseQuery, ['tab' => 'operational', 'page' => 1])); ?>" class="<?= $tab === 'operational' ? 'is-active' : ''; ?>">Operational Journal</a>
                    <a href="<?= e(operation_logs_query_url($baseQuery, ['tab' => 'ledger', 'page' => 1])); ?>" class="<?= $tab === 'ledger' ? 'is-active' : ''; ?>">Protected Ledger</a>
                </div>
                <div class="audit-filter-actions">
                    <a class="portal-ghost-btn portal-inline-link" href="<?= e(operation_logs_export_url($baseQuery)); ?>">CSV export</a>
                </div>
            </div>
        </section>

        <section class="portal-card portal-reveal" data-audit-filter-panel>
            <div class="portal-card-head">
                <h2>Filtr</h2>
                <small>Modul, tarix, emekdas, musteri/mal/sened ve serbest axtaris ile daraldin.</small>
            </div>
            <form method="get" class="audit-filter" data-audit-filter>
                <input type="hidden" name="tab" value="<?= e($tab); ?>">
                <label>
                    Modul
                    <input type="text" name="module" value="<?= e((string) ($_GET['module'] ?? '')); ?>" placeholder="billing, finance, inventory">
                </label>
                <label>
                    Emeliyyat
                    <input type="text" name="action" value="<?= e((string) ($_GET['action'] ?? '')); ?>" placeholder="customer_update">
                </label>
                <label>
                    Status
                    <select name="status">
                        <option value="">Hamisi</option>
                        <?php foreach (['planned', 'dry_run', 'pending', 'executed', 'failed', 'skipped'] as $rowStatus): ?>
                            <option value="<?= e($rowStatus); ?>" <?= (string) ($_GET['status'] ?? '') === $rowStatus ? 'selected' : ''; ?>><?= e($rowStatus); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Ilkin tarix
                    <input type="date" name="date_from" value="<?= e((string) ($_GET['date_from'] ?? '')); ?>">
                </label>
                <label>
                    Son tarix
                    <input type="date" name="date_to" value="<?= e((string) ($_GET['date_to'] ?? '')); ?>">
                </label>
                <label>
                    Emekdas
                    <input type="text" name="actor" value="<?= e((string) ($_GET['actor'] ?? '')); ?>" placeholder="ad ve ya ID">
                </label>
                <label>
                    Obyekt
                    <input type="text" name="entity" value="<?= e((string) ($_GET['entity'] ?? '')); ?>" placeholder="customer:100">
                </label>
                <label>
                    Axtaris
                    <input type="search" name="search" value="<?= e((string) ($_GET['search'] ?? '')); ?>" placeholder="sebeb, qeyd, request ID">
                </label>
                <div class="audit-filter-actions">
                    <button type="submit" class="portal-primary-btn">Uygula</button>
                    <a class="portal-ghost-btn portal-inline-link" href="<?= e(operation_logs_query_url([], ['tab' => $tab])); ?>" data-audit-clear>Temizle</a>
                </div>
            </form>
        </section>

        <section class="portal-card portal-reveal" data-audit-log-content>
            <div class="portal-card-head">
                <h2><?= $tab === 'ledger' ? 'Protected Ledger' : 'Operational Journal'; ?></h2>
                <small>
                    <?= e((string) ($pageData['total'] ?? 0)); ?> qeyd, sehife <?= e((string) ($pageData['page'] ?? 1)); ?>/<?= e((string) ($pageData['pages'] ?? 1)); ?>.
                    <?php if (is_array($chainStatus)): ?>
                        Chain: <?= e(($chainStatus['ok'] ?? false) ? 'OK' : 'problem'); ?>
                    <?php endif; ?>
                </small>
            </div>

            <div class="audit-table-wrap">
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>ID / tarix</th>
                            <th>Modul</th>
                            <th>Emeliyyat</th>
                            <th>Emekdas</th>
                            <th>Obyekt</th>
                            <th>Qeyd ve sebeb</th>
                            <th>Detallar</th>
                            <?php if ($tab === 'operational' && $isSuperAdmin): ?>
                                <th>Super admin</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="<?= $tab === 'operational' && $isSuperAdmin ? '8' : '7'; ?>">Bu filtre uygun jurnal qeydi tapilmadi.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $rowId = (int) ($row['id'] ?? 0);
                            $actionId = (int) ($row['action_id'] ?? $rowId);
                            $visible = (int) ($row['is_visible'] ?? 1) === 1;
                            ?>
                            <tr>
                                <td>
                                    <strong>#<?= e((string) $rowId); ?></strong>
                                    <?php if ($tab === 'ledger'): ?><small>action #<?= e((string) $actionId); ?></small><?php endif; ?>
                                    <small><?= e(operation_logs_text($row['created_at'] ?? null)); ?></small>
                                </td>
                                <td>
                                    <span class="audit-pill"><?= e(operation_logs_text($row['module_key'] ?? null, 'core')); ?></span>
                                    <small><?= e(operation_logs_text($row['operation_class'] ?? null)); ?></small>
                                </td>
                                <td>
                                    <?= e(operation_logs_text($row['action_type'] ?? null)); ?>
                                    <small><?= e(operation_logs_text($row['status'] ?? null)); ?></small>
                                </td>
                                <td><?= e(operation_logs_actor_label($row)); ?></td>
                                <td>
                                    <?= e(operation_logs_text($row['entity_type'] ?? null)); ?>
                                    <small><?= e(operation_logs_text($row['entity_id'] ?? null)); ?></small>
                                </td>
                                <td>
                                    <?= e(operation_logs_text($row['summary'] ?? null)); ?>
                                    <small>Sebeb: <?= e(operation_logs_text($row['reason'] ?? null)); ?></small>
                                    <?php if (!$visible): ?>
                                        <span class="audit-pill audit-muted">Gizledilib</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <details class="audit-detail" data-audit-detail>
                                        <summary>Detala bax</summary>
                                        <small>IP: <?= e(operation_logs_text($row['request_ip'] ?? null)); ?></small>
                                        <small>Request: <?= e(operation_logs_text($row['request_id'] ?? null)); ?></small>
                                        <?php if ($tab === 'ledger'): ?>
                                            <small>Hash: <?= e(operation_logs_text($row['event_hash'] ?? null)); ?></small>
                                        <?php endif; ?>
                                        <pre><?= e("Before:\n" . operation_logs_json_preview($row['before_json'] ?? null) . "\n\nAfter:\n" . operation_logs_json_preview($row['after_json'] ?? null) . "\n\nPayload:\n" . operation_logs_json_preview($row['payload_json'] ?? null)); ?></pre>
                                    </details>
                                </td>
                                <?php if ($tab === 'operational' && $isSuperAdmin): ?>
                                    <td>
                                        <div class="audit-actions">
                                            <form method="post" class="audit-inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="edit_visible_audit">
                                                <input type="hidden" name="action_id" value="<?= e((string) $actionId); ?>">
                                                <input type="text" name="summary" value="<?= e((string) ($row['summary'] ?? '')); ?>" placeholder="Yeni xulase">
                                                <textarea name="reason" required placeholder="Duzelis sebebi"></textarea>
                                                <button type="submit" class="portal-ghost-btn portal-inline-link">Duzelt</button>
                                            </form>
                                            <?php if ($visible): ?>
                                                <form method="post" class="audit-inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="delete_visible_audit">
                                                    <input type="hidden" name="action_id" value="<?= e((string) $actionId); ?>">
                                                    <textarea name="reason" required placeholder="Gizletme sebebi"></textarea>
                                                    <button type="submit" class="portal-primary-btn">Gizlet</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <nav class="audit-pagination" data-audit-pagination aria-label="Audit sehifeleme">
                <?php
                $currentPage = (int) ($pageData['page'] ?? 1);
                $pages = (int) ($pageData['pages'] ?? 1);
                $windowStart = max(1, $currentPage - 2);
                $windowEnd = min($pages, $currentPage + 2);
                ?>
                <?php if ($currentPage > 1): ?>
                    <a href="<?= e(operation_logs_query_url($baseQuery, ['page' => $currentPage - 1])); ?>">Evvelki</a>
                <?php endif; ?>
                <?php for ($i = $windowStart; $i <= $windowEnd; $i++): ?>
                    <?php if ($i === $currentPage): ?>
                        <span class="is-active"><?= e((string) $i); ?></span>
                    <?php else: ?>
                        <a href="<?= e(operation_logs_query_url($baseQuery, ['page' => $i])); ?>"><?= e((string) $i); ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($currentPage < $pages): ?>
                    <a href="<?= e(operation_logs_query_url($baseQuery, ['page' => $currentPage + 1])); ?>">Novbeti</a>
                <?php endif; ?>
            </nav>
        </section>
    </main>

    <script>
        (function () {
            const root = document.querySelector('[data-audit-log-root]');
            if (!root) {
                return;
            }
            const loadContent = function (url) {
                fetch(url, {headers: {'X-Requested-With': 'fetch'}})
                    .then((response) => response.text())
                    .then((html) => {
                        const parsed = new DOMParser().parseFromString(html, 'text/html');
                        const swaps = [
                            '[data-audit-tabs]',
                            '[data-audit-filter-panel]',
                            '[data-audit-log-content]'
                        ];
                        let changed = false;
                        swaps.forEach((selector) => {
                            const next = parsed.querySelector(selector);
                            const current = document.querySelector(selector);
                            if (next && current) {
                                current.replaceWith(next);
                                changed = true;
                            }
                        });
                        if (changed) {
                            history.pushState({}, '', url);
                        } else {
                            window.location.href = url;
                        }
                    })
                    .catch(() => {
                        window.location.href = url;
                    });
            };
            root.addEventListener('submit', function (event) {
                const form = event.target.closest('[data-audit-filter]');
                if (!form) {
                    return;
                }
                event.preventDefault();
                const params = new URLSearchParams(new FormData(form));
                params.delete('result');
                params.delete('error');
                params.set('page', '1');
                loadContent(form.action.split('?')[0] + '?' + params.toString());
            });
            root.addEventListener('click', function (event) {
                const link = event.target.closest('[data-audit-pagination] a, [data-audit-tabs] a, [data-audit-clear]');
                if (!link) {
                    return;
                }
                event.preventDefault();
                loadContent(link.href);
            });
        })();
    </script>
    <?php require_once __DIR__ . '/copilot-widget.php'; app_render_copilot_widget(function_exists('app_lang') ? app_lang() : 'az'); ?>
</body>
</html>
