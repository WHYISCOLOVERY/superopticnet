<?php
declare(strict_types=1);

require_once __DIR__ . '/../../design-system-lib.php';

$queue = (array) (($aiContext['data']['queue'] ?? []));
$items = (array) ($queue['items'] ?? []);
$sources = (array) ($queue['sources'] ?? []);
$query = is_array($aiContext['query'] ?? null) ? $aiContext['query'] : [];
$unavailableSources = array_filter($sources, static fn (array $s): bool => empty($s['available']));
$queueError = trim((string) ($queue['error'] ?? ''));
$isSuperAdmin = function_exists('app_portal_is_super_admin') && app_portal_is_super_admin((array) ($aiContext['user'] ?? []));
$workQueueFlash = $_SESSION['ai_work_queue_flash'] ?? null;
unset($_SESSION['ai_work_queue_flash']);

$moduleLabels = [
    'billing' => 'Billing', 'finance' => 'Maliyyə', 'hr' => 'HR', 'inventory' => 'Anbar',
    'network' => 'Şəbəkə/NOC', 'messaging' => 'Mesaj Mərkəzi', 'crm' => 'CRM',
];
$severityLabels = ['critical' => 'Kritik', 'high' => 'Yüksək', 'medium' => 'Orta', 'low' => 'Aşağı'];
?>
<section class="ai-panel" data-ai-work-queue>
    <h2>Təsdiq Mərkəzi - Birləşdirilmiş iş növbəsi</h2>
    <p>Bütün modullardan icra tələb edən işlərin salt-oxunan proyeksiyası. Bu səhifədən heç bir təsdiq, ödəniş, göndəriş və ya cihaz əməliyyatı icra edilmir - hər sətir öz kanonik modulunda idarə olunur.</p>

    <?php if (is_array($workQueueFlash)): ?>
        <div class="portal-alert is-<?= e((string) ($workQueueFlash['type'] ?? 'info')); ?>"><?= e((string) ($workQueueFlash['message'] ?? '')); ?></div>
    <?php endif; ?>

    <form class="ai-form" method="get" action="ai-work-queue.php" data-ai-filter>
        <label>Axtarış<input type="search" name="search" value="<?= e((string) ($query['search'] ?? '')); ?>" placeholder="basliq ve ya sebeb"></label>
        <label>Modul
            <select name="modules">
                <option value="">Bütün modullar</option>
                <?php foreach ($moduleLabels as $moduleKey => $moduleLabel): ?>
                    <option value="<?= e($moduleKey); ?>" <?= ((string) ($query['modules'] ?? '')) === $moduleKey ? 'selected' : ''; ?>><?= e($moduleLabel); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Ciddiyyət
            <select name="severity">
                <option value="">Hamısı</option>
                <?php foreach ($severityLabels as $severityKey => $severityLabel): ?>
                    <option value="<?= e($severityKey); ?>" <?= ((string) ($query['severity'] ?? '')) === $severityKey ? 'selected' : ''; ?>><?= e($severityLabel); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="portal-primary-btn" type="submit">Filtrlə</button>
    </form>

    <?php if ($queueError !== ''): ?>
        <?= app_design_system_state('error', 'İş növbəsi açıla bilmədi', $queueError === 'audit_unavailable' ? 'Audit jurnalı əlçatan deyil. Məlumat təhlükəsizlik üçün göstərilmədi.' : 'Məlumat mənbələri hazırda əlçatan deyil.'); ?>
    <?php elseif ($unavailableSources !== []): ?>
        <div class="ai-queue-source-warnings">
            <?php foreach ($unavailableSources as $sourceKey => $sourceState): ?>
                <?= app_design_system_state('error', 'Mənbə əlçatan deyil: ' . (string) $sourceKey, (string) ($sourceState['error'] ?? 'Naməlum xəta')); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($queueError !== ''): ?>
    <?php elseif ($items === []): ?>
        <?= app_design_system_state('empty', 'Aktiv iş yoxdur', 'Seçilmiş filtr üzrə növbədə heç bir iş tapılmadı.'); ?>
    <?php else: ?>
        <div class="ai-table-wrap ai-queue-table-wrap">
            <table class="portal-table ai-queue-table">
                <thead>
                    <tr>
                        <th>Ciddiyyət</th>
                        <th>Modul</th>
                        <th>İş</th>
                        <th>Vəziyyət</th>
                        <th>Sahiblik / əhatə</th>
                        <th>Yenilənib</th>
                        <th>Əməliyyat</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $severity = (string) ($item['severity'] ?? 'low');
                    $module = (string) ($item['source_module'] ?? '');
                    $isUnavailable = (string) ($item['data_status'] ?? '') === 'unavailable';
                    ?>
                    <tr data-ai-queue-row data-queue-item-id="<?= e((string) ($item['id'] ?? '')); ?>">
                        <td data-label="Ciddiyyət"><span class="ai-status is-<?= e($severity); ?>"><?= e($severityLabels[$severity] ?? $severity); ?></span></td>
                        <td data-label="Modul"><?= e($moduleLabels[$module] ?? $module); ?></td>
                        <td data-label="İş">
                            <strong><?= e((string) ($item['title'] ?? '')); ?></strong>
                            <p><?= e((string) ($item['reason'] ?? '')); ?></p>
                            <?php if ($isUnavailable): ?><small class="ai-queue-unavailable-flag">data əlçatan deyil</small><?php endif; ?>
                            <?php if (!empty($item['confirmable'])): ?><small class="ai-queue-advisor-flag">Advisor icra kontraktı mövcuddur (bu görünüşdən icra edilmir)</small><?php endif; ?>
                        </td>
                        <td data-label="Vəziyyət"><?= e(app_ui_status((string) ($item['status'] ?? ''), (string) ($item['status'] ?? '-'))); ?></td>
                        <td data-label="Sahiblik / əhatə"><?= e((string) ($item['owner_role'] ?? '')); ?><br><small><?= e((string) ($item['scope'] ?? 'company_wide')); ?></small></td>
                        <td data-label="Yenilənib"><?= e((string) ($item['source_timestamp'] ?? '-')); ?></td>
                        <td data-label="Əməliyyat">
                            <a class="portal-ghost-btn portal-inline-link" href="<?= e((string) ($item['deep_link'] ?? '#')); ?>" target="_blank" rel="noopener">Modula keç</a>
                            <?php if (!empty($item['confirmable']) && $isSuperAdmin): ?>
                                <details class="ai-queue-preview-details">
                                    <summary class="portal-ghost-btn portal-inline-link">Önizləmə hazırla</summary>
                                    <form class="portal-inline-form ai-queue-preview-form" method="post" action="ai-work-queue.php">
                                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="prepare_advisor_preview">
                                        <input type="hidden" name="proposal_id" value="<?= e((string) ($item['id'] ?? '')); ?>">
                                        <label>SKU<input type="text" name="sku" placeholder="anbar kodu" maxlength="64"></label>
                                        <label>Qeyd<input type="text" name="note" placeholder="ixtiyari" maxlength="255"></label>
                                        <button class="portal-ghost-btn" type="submit">Önizləməni al</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="portal-pagination">
            <span><?= e((string) ($queue['start'] ?? 0)); ?>-<?= e((string) ($queue['end'] ?? 0)); ?> / <?= e((string) ($queue['total'] ?? 0)); ?> nəticə</span>
            <div class="portal-pagination-actions">
                <?php
                $queuePage = max(1, (int) ($queue['page'] ?? 1));
                $queuePages = max(1, (int) ($queue['pages'] ?? 1));
                $pageUrlFor = static function (int $target) use ($query): string {
                    $pageQuery = $query;
                    $pageQuery['page'] = $target;
                    return 'ai-work-queue.php?' . http_build_query($pageQuery);
                };
                ?>
                <?php if ($queuePage > 1): ?><a data-ai-page class="portal-ghost-btn portal-inline-link" href="<?= e($pageUrlFor($queuePage - 1)); ?>">Əvvəlki</a><?php endif; ?>
                <span><?= $queuePage; ?> / <?= $queuePages; ?></span>
                <?php if ($queuePage < $queuePages): ?><a data-ai-page class="portal-ghost-btn portal-inline-link" href="<?= e($pageUrlFor($queuePage + 1)); ?>">Növbəti</a><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
