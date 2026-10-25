<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/ui-language-lib.php';

$bonus = (array) ($financeContext['data']['bonus'] ?? []);
$bonusView = (string) ($bonus['view'] ?? 'dashboard');
$bonusMonth = (array) ($bonus['month'] ?? app_bonus_month_period());
$latestPeriod = is_array($bonus['latest_period'] ?? null) ? (array) $bonus['latest_period'] : null;
$selectedPeriod = is_array($bonus['selected_period'] ?? null) ? (array) $bonus['selected_period'] : $latestPeriod;
$selectedPeriodId = (int) ($selectedPeriod['id'] ?? 0);
$employees = (array) ($bonus['employees'] ?? []);
$canCalculate = !empty($bonus['can_calculate']);
$canManageRules = !empty($bonus['can_manage_rules']);
$bonusSettingsByKey = [];
foreach ((array) ($bonus['settings'] ?? []) as $settingRow) {
    $bonusSettingsByKey[(string) ($settingRow['setting_key'] ?? '')] = (array) $settingRow;
}
$bonusSettingGroups = [
    'Bonus meblegleri' => [
        ['new_connection_bonus_amount', 'Yeni qosulma bonusu', 'Yeni musteri qosulduqda hesablanan mebleg.', 'decimal', 'number', '0.01'],
        ['recurring_monthly_bonus_amount', 'Aktiv musteri ucun ayliq bonus', 'Qosulmadan sonraki aktiv aylar ucun mebleg.', 'decimal', 'number', '0.01'],
        ['recurring_bonus_month_limit', 'Aktiv bonusun ay sayi', 'Bir musteri uzre bonusun maksimum davam muddeti.', 'integer', 'number', '1'],
        ['area_quality_default_percent', 'Standart keyfiyyet faizi', 'Xususi qayda olmadiqda tetbiq edilen faiz.', 'decimal', 'number', '0.01'],
    ],
    'Hesablama ve bolgu' => [
        ['base_salary_customer_method', 'Musteri sayinin hesablanmasi', 'Sabit maas pillesi ucun istifade edilen musteri sayi.', 'string', 'select', ['rolling_3_month_average' => 'Son 3 ayin orta aktiv sayi', 'current_active' => 'Cari aktiv musteri sayi']],
        ['new_connection_payout_policy', 'Yeni qosulma bonusunun vaxti', 'Bonusun ne vaxt odenise dusduyunu secin.', 'string', 'select', ['after_30_days' => '30 aktiv gunden sonra', 'immediate' => 'Derhal']],
        ['active_day_method', 'Aktiv gun hesablanmasi', 'Faizin araliqla ve ya deqiq gun sayi ile hesablanmasi.', 'string', 'select', ['bracket' => 'Gun araliqlarina gore', 'exact' => 'Deqiq gun sayina gore']],
        ['new_connection_finder_percent', 'Yeni qosulma: tapan emekdas', 'Yeni qosulma bonusundan pay.', 'decimal', 'number', '0.01'],
        ['new_connection_installer_percent', 'Yeni qosulma: quran emekdas', 'Yeni qosulma bonusundan pay.', 'decimal', 'number', '0.01'],
        ['recurring_finder_percent', 'Ayliq bonus: tapan emekdas', 'Aktiv musteri bonusundan pay.', 'decimal', 'number', '0.01'],
        ['recurring_installer_percent', 'Ayliq bonus: quran emekdas', 'Aktiv musteri bonusundan pay.', 'decimal', 'number', '0.01'],
    ],
    'Isci ayrildiqda' => [
        ['inactive_employee_bonus_policy', 'Isci ayrildiqda bonus', 'Isden cixmis emekdasin aciq bonusunun aqibeti.', 'string', 'select', ['stop' => 'Dayandir', 'transfer_to_area_owner' => 'Erazi mesuluna otur', 'manual' => 'Maliyye elle qerar versin']],
    ],
];
$bonusViews = [
    'dashboard' => app_ui_label('dashboard'),
    'calculations' => 'Ayliq hesablamalar',
    'employee-detail' => 'Emekdas detali',
    'customer-traces' => 'Musteri-bonus izleri',
    'rules' => 'Qaydalar',
    'adjustments' => 'Duzelisler',
    'payout' => 'Odenise hazirliq',
    'audit' => 'Jurnal',
];
$bonusUrl = static fn (string $view, array $params = []): string => app_finance_url('employee-bonuses', ['bonus_view' => $view] + $params, $financeLang);
?>
<div data-finance-employee-bonuses>
    <style>
        .bonus-subnav{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 14px}.bonus-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.bonus-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.bonus-kpi{border:1px solid rgba(148,163,184,.28);border-radius:8px;padding:13px;background:#fff;min-width:0}.bonus-kpi span{display:block;color:var(--portal-muted);font-size:.78rem}.bonus-kpi strong{display:block;margin-top:5px;font-size:clamp(1rem,1.7vw,1.25rem);overflow-wrap:anywhere}.bonus-mini-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.bonus-form-inline{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end}.bonus-form-inline>*{min-width:0}.bonus-date-filter{grid-template-columns:repeat(3,minmax(180px,1fr)) auto}.bonus-rule-groups{display:grid;gap:12px}.bonus-rule-group{border:1px solid rgba(148,163,184,.3);border-radius:8px;background:#fff;overflow:hidden}.bonus-rule-group>summary{cursor:pointer;list-style:none;padding:15px 16px;font-weight:800;background:#f5f8fb}.bonus-rule-group>summary::-webkit-details-marker{display:none}.bonus-setting-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:12px}.bonus-setting-card{display:grid;grid-template-columns:minmax(0,1fr) minmax(150px,.65fr) auto;gap:10px;align-items:end;border:1px solid rgba(148,163,184,.25);border-radius:8px;padding:12px;min-width:0}.bonus-setting-copy{min-width:0}.bonus-setting-copy strong,.bonus-setting-copy small{display:block}.bonus-setting-copy small{margin-top:4px;color:var(--portal-muted)}.bonus-setting-card .portal-form-row{margin:0}.bonus-rule-forms{display:grid;grid-template-columns:1fr;gap:12px;margin-top:14px}.bonus-rule-form{border:1px solid rgba(148,163,184,.28);border-radius:8px;padding:14px;background:#fff;min-width:0}.bonus-rule-form h4{margin:0 0 4px}.bonus-rule-form>small{display:block;color:var(--portal-muted);margin-bottom:12px}.bonus-rule-form .bonus-form-inline{grid-template-columns:repeat(4,minmax(0,1fr))}.bonus-note{color:var(--portal-muted);font-size:.82rem}.bonus-table-actions{display:flex;gap:6px;flex-wrap:wrap}.bonus-status{border-radius:999px;padding:5px 9px;background:#e8edf3;font-weight:800;font-size:.78rem}.bonus-status.is-paid,.bonus-status.is-approved{background:#dff7ea;color:#166534}.bonus-status.is-pending_approval,.bonus-status.is-calculated,.bonus-status.is-recalculated{background:#fff1d6;color:#9a4b00}.bonus-status.is-cancelled{background:#ffe4e6;color:#9f1239}@media(max-width:1100px){.bonus-setting-grid{grid-template-columns:1fr}.bonus-rule-form .bonus-form-inline{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:900px){.bonus-kpi-grid,.bonus-mini-grid,.bonus-form-inline,.bonus-date-filter{grid-template-columns:1fr 1fr}.bonus-setting-card{grid-template-columns:1fr 1fr}.bonus-setting-copy{grid-column:1/-1}}@media(max-width:620px){.bonus-kpi-grid,.bonus-mini-grid,.bonus-form-inline,.bonus-date-filter,.bonus-rule-form .bonus-form-inline,.bonus-setting-card{grid-template-columns:1fr}.bonus-setting-copy{grid-column:auto}}
    </style>
    <nav class="bonus-subnav" aria-label="Bonus bolmeleri">
        <?php foreach ($bonusViews as $viewKey => $viewLabel): ?>
            <a class="portal-ghost-btn portal-inline-link<?= $bonusView === $viewKey ? ' is-active' : ''; ?>" data-finance-nav href="<?= e($bonusUrl($viewKey)); ?>"><?= e($viewLabel); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($bonusView === 'dashboard'): ?>
        <?php $dashboardPeriod = is_array($bonus['latest_period'] ?? null) ? (array) $bonus['latest_period'] : null; ?>
        <div class="portal-card-head">
            <div><strong>Bonus <?= e(strtolower(app_ui_label('dashboard'))); ?></strong><small>Son hesablanan dovr, qayda sayi ve gozleyen duzelisler.</small></div>
            <div class="bonus-actions">
                <a class="portal-primary-btn portal-inline-link" data-finance-nav href="<?= e($bonusUrl('calculations')); ?>">Ayliq hesabla</a>
                <a class="portal-ghost-btn portal-inline-link" data-finance-nav href="<?= e($bonusUrl('customer-traces')); ?>">Musteri bagla</a>
            </div>
        </div>
        <div class="bonus-kpi-grid">
            <article class="bonus-kpi"><span>Son dovr</span><strong><?= $dashboardPeriod ? e(app_bonus_period_label($dashboardPeriod)) : 'Yoxdur'; ?></strong></article>
            <article class="bonus-kpi"><span>Yekun odenis</span><strong><?= e(billing_money($dashboardPeriod['total_payable'] ?? 0)); ?></strong></article>
            <article class="bonus-kpi"><span>Gozleyen duzelis</span><strong><?= e((string) (int) ($bonus['pending_adjustments'] ?? 0)); ?></strong></article>
            <article class="bonus-kpi"><span>Xatirlatma</span><strong><?= e((string) (int) ($bonus['open_notifications'] ?? 0)); ?></strong></article>
        </div>
        <div class="portal-grid portal-grid-2" style="margin-top:14px">
            <article class="portal-muted-card">
                <strong>Top emekdaslar</strong>
                <div class="spog-list" style="margin-top:10px">
                    <?php foreach ((array) ($bonus['top_rows'] ?? []) as $row): ?>
                        <div class="spog-list-item"><strong><?= e((string) ($row['employee_name'] ?? '-')); ?> / <?= e(billing_money($row['total_payable'] ?? 0)); ?></strong><small><?= e(app_ui_term((string) ($row['employee_role'] ?? '-'))); ?> / <?= e((string) ($row['active_customer_count'] ?? 0)); ?> aktiv müştəri</small></div>
                    <?php endforeach; ?>
                    <?php if ((array) ($bonus['top_rows'] ?? []) === []): ?><div class="finance-empty">Hesablama yoxdur.</div><?php endif; ?>
                </div>
            </article>
            <article class="portal-muted-card">
                <strong>Aciq bonus bildirisleri</strong>
                <div class="spog-list" style="margin-top:10px">
                    <?php foreach ((array) (($bonus['notifications_page']['rows'] ?? [])) as $row): ?>
                        <div class="spog-list-item"><strong><?= e((string) ($row['notification_type'] ?? '-')); ?> / <?= e((string) ($row['employee_name'] ?? '-')); ?></strong><small><?= e((string) ($row['subscriber_code'] ?? '-')); ?> - <?= e((string) ($row['message'] ?? '')); ?></small></div>
                    <?php endforeach; ?>
                    <?php if ((array) (($bonus['notifications_page']['rows'] ?? [])) === []): ?><div class="finance-empty">Aciq bildiris yoxdur.</div><?php endif; ?>
                </div>
                <div class="bonus-actions" style="margin-top:10px"><a class="portal-ghost-btn portal-inline-link" data-finance-nav href="<?= e($bonusUrl('rules')); ?>"><?= e(app_ui_action('view_rules')); ?></a><a class="portal-ghost-btn portal-inline-link" data-finance-nav href="<?= e($bonusUrl('audit')); ?>"><?= e(app_ui_action('view_audit')); ?></a></div>
            </article>
        </div>
    <?php elseif ($bonusView === 'calculations'): ?>
        <div class="portal-card-head">
            <div><strong>Ayliq bonus hesablamasi</strong><small>Maas 1-5 arasi verildiyi ucun default olaraq kecen ay hesablanir.</small></div>
        </div>
        <?php if ($canCalculate): ?>
            <form class="bonus-form-inline" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                <input type="hidden" name="action" value="calculate_employee_bonus_period">
                <input type="hidden" name="finance_tab" value="employee-bonuses">
                <input type="hidden" name="bonus_view" value="calculations">
                <div class="portal-form-row"><label>Ay</label><input type="month" name="bonus_month" value="<?= e((string) ($bonusMonth['month'] ?? date('Y-m'))); ?>"></div>
                <span></span><span></span><button class="portal-primary-btn" type="submit"><?= e(app_ui_action('calculate_recalculate')); ?></button>
            </form>
        <?php endif; ?>
        <?php $pageData = (array) ($bonus['period_page'] ?? []); $periodRows = (array) ($pageData['rows'] ?? []); ?>
        <div class="portal-table-wrap" style="margin-top:14px"><table class="portal-table"><thead><tr><th><?= e(app_ui_label('period')); ?></th><th><?= e(app_ui_label('status')); ?></th><th>Emekdas</th><th><?= e(app_ui_label('base_salary')); ?></th><th>Yeni</th><th>12 ay</th><th>Duzelis</th><th>Yekun</th><th><?= e(app_ui_label('action')); ?></th></tr></thead><tbody>
            <?php foreach ($periodRows as $period): ?>
                <?php $status = (string) ($period['status'] ?? 'draft'); ?>
                <tr data-finance-row><td><?= e(app_bonus_period_label((array) $period)); ?></td><td><span class="bonus-status is-<?= e($status); ?>"><?= e(app_ui_status($status)); ?></span></td><td><?= e((string) ($period['employee_count'] ?? 0)); ?></td><td><?= e(billing_money($period['total_base_salary'] ?? 0)); ?></td><td><?= e(billing_money($period['total_new_connection_bonus'] ?? 0)); ?></td><td><?= e(billing_money($period['total_recurring_bonus'] ?? 0)); ?></td><td><?= e(billing_money($period['total_manual_adjustment'] ?? 0)); ?></td><td><strong><?= e(billing_money($period['total_payable'] ?? 0)); ?></strong></td><td><div class="bonus-table-actions">
                    <a class="portal-ghost-btn portal-inline-link" data-finance-nav href="<?= e($bonusUrl('employee-detail', ['period_id' => (int) ($period['id'] ?? 0)])); ?>">Detallar</a>
                    <a class="portal-ghost-btn portal-inline-link" href="<?= e($bonusUrl('calculations', ['bonus_export' => 'summary', 'period_id' => (int) ($period['id'] ?? 0)])); ?>"><?= e(app_ui_label('export_csv')); ?></a>
                    <?php if ($canCalculate && in_array($status, ['calculated', 'recalculated'], true)): ?><form method="post" class="portal-inline-form"><input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="change_employee_bonus_period_status"><input type="hidden" name="finance_tab" value="employee-bonuses"><input type="hidden" name="bonus_view" value="calculations"><input type="hidden" name="period_id" value="<?= e((string) ($period['id'] ?? 0)); ?>"><input type="hidden" name="next_status" value="pending_approval"><button class="portal-ghost-btn"><?= e(app_ui_action('send_for_approval')); ?></button></form><?php endif; ?>
                    <?php if ($canManageRules && $status === 'pending_approval'): ?><form method="post" class="portal-inline-form"><input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="change_employee_bonus_period_status"><input type="hidden" name="finance_tab" value="employee-bonuses"><input type="hidden" name="bonus_view" value="calculations"><input type="hidden" name="period_id" value="<?= e((string) ($period['id'] ?? 0)); ?>"><input type="hidden" name="next_status" value="approved"><button class="portal-primary-btn"><?= e(app_ui_action('approve')); ?></button></form><?php endif; ?>
                </div></td></tr>
            <?php endforeach; ?>
            <?php if ($periodRows === []): ?><tr><td colspan="9" class="finance-empty">Bonus periodi yoxdur.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php require __DIR__ . '/_pagination.php'; ?>
    <?php elseif ($bonusView === 'employee-detail'): ?>
        <div class="portal-card-head"><div><strong>Emekdas uzre bonus detali</strong><small>Emekdas yalniz oz bonusunu gorer; maliyye/admin hamisini gore biler.</small></div><div class="bonus-actions"><?php if ($selectedPeriodId > 0 && (int) ($bonus['employee_id'] ?? 0) > 0): ?><a class="portal-ghost-btn portal-inline-link" href="<?= e($bonusUrl('employee-detail', ['bonus_export' => 'items', 'period_id' => $selectedPeriodId, 'employee_id' => (int) ($bonus['employee_id'] ?? 0)])); ?>"><?= e(app_ui_label('export_csv')); ?></a><?php endif; ?></div></div>
        <form class="finance-filter bonus-date-filter" method="get" action="operations-center.php" data-finance-filter>
            <input type="hidden" name="section" value="finance"><input type="hidden" name="finance_tab" value="employee-bonuses"><input type="hidden" name="bonus_view" value="employee-detail">
            <div class="portal-form-row"><label>Ilkin tarix</label><input type="date" name="period_start" value="<?= e((string) ($bonus['selected_period_start'] ?? date('Y-m-01'))); ?>" required></div>
            <div class="portal-form-row"><label>Son tarix</label><input type="date" name="period_end" value="<?= e((string) ($bonus['selected_period_end'] ?? date('Y-m-t'))); ?>" required></div>
            <div class="portal-form-row"><label>Emekdas</label><select name="employee_id"><?php foreach ($employees as $employee): ?><option value="<?= e((string) ($employee['id'] ?? 0)); ?>"<?= (int) ($bonus['employee_id'] ?? 0) === (int) ($employee['id'] ?? 0) ? ' selected' : ''; ?>><?= e((string) ($employee['full_name'] ?? '-')); ?></option><?php endforeach; ?></select></div>
            <button class="portal-primary-btn" type="submit">Goster</button>
        </form>
        <p class="bonus-note">Tarix ve ya emekdas deyisdirilen kimi neticeler sehife yenilenmeden avtomatik gosterilir.</p>
        <?php $summary = is_array($bonus['employee_summary'] ?? null) ? (array) $bonus['employee_summary'] : []; ?>
        <div class="bonus-kpi-grid">
            <article class="bonus-kpi"><span>Sabit erazi maasi</span><strong><?= e(billing_money($summary['base_salary'] ?? 0)); ?></strong></article>
            <article class="bonus-kpi"><span>Yeni qosuntu</span><strong><?= e(billing_money($summary['new_connection_bonus'] ?? 0)); ?></strong></article>
            <article class="bonus-kpi"><span>12 ay aktiv bonus</span><strong><?= e(billing_money($summary['recurring_bonus'] ?? 0)); ?></strong></article>
            <article class="bonus-kpi"><span>Yekun</span><strong><?= e(billing_money($summary['total_payable'] ?? 0)); ?></strong></article>
        </div>
        <?php $employeeNotifications = (array) (($bonus['notifications_page']['rows'] ?? [])); ?>
        <?php if ($employeeNotifications !== []): ?>
            <article class="portal-muted-card" style="margin-top:14px">
                <strong>Emekdas uzre aciq bildirisler</strong>
                <div class="spog-list" style="margin-top:10px">
                    <?php foreach ($employeeNotifications as $row): ?><div class="spog-list-item"><strong><?= e((string) ($row['notification_type'] ?? '-')); ?> / <?= e((string) ($row['subscriber_code'] ?? '-')); ?></strong><small><?= e((string) ($row['message'] ?? '')); ?></small></div><?php endforeach; ?>
                </div>
            </article>
        <?php endif; ?>
        <?php $pageData = (array) ($bonus['items_page'] ?? []); $itemRows = (array) ($pageData['rows'] ?? []); ?>
        <div class="portal-table-wrap" style="margin-top:14px"><table class="portal-table"><thead><tr><th>Dovr</th><th>Tip</th><th>Musteri</th><th>Aktiv gun</th><th>Faiz</th><th>Keyfiyyet</th><th>Ay</th><th><?= e(app_ui_label('status')); ?></th><th>Mebleg</th><th>Sebeb</th></tr></thead><tbody>
            <?php foreach ($itemRows as $item): ?><tr data-finance-row><td><?= e((string) ($item['period_start'] ?? $bonus['selected_period_start'] ?? '-')); ?><br><small><?= e((string) ($item['period_end'] ?? $bonus['selected_period_end'] ?? '-')); ?></small></td><td><?= e(app_ui_term((string) ($item['item_type'] ?? '-'))); ?></td><td><?= e((string) ($item['subscriber_code'] ?? '-')); ?><br><small><?= e((string) ($item['customer_name'] ?? '')); ?></small></td><td><?= e((string) ($item['active_days'] ?? 0)); ?></td><td><?= e((string) ($item['active_percent'] ?? 0)); ?>%</td><td><?= e((string) ($item['quality_percent'] ?? 100)); ?>%</td><td><?= e((string) ($item['bonus_month_no'] ?? '-')); ?>/<?= e((string) ($item['bonus_month_limit'] ?? '-')); ?></td><td><?= e(app_ui_status((string) ($item['status'] ?? '-'))); ?></td><td><strong><?= e(billing_money($item['amount'] ?? 0)); ?></strong></td><td><?= e((string) ($item['reason'] ?? '')); ?></td></tr><?php endforeach; ?>
            <?php if ($itemRows === []): ?><tr><td colspan="10" class="finance-empty">Secilmis tarix araliginda hesablanmis bonus setri yoxdur.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php require __DIR__ . '/_pagination.php'; ?>
    <?php elseif ($bonusView === 'customer-traces'): ?>
        <div class="portal-card-head"><div><strong>Musteri-bonus izleri</strong><small>Her musteri ucun yalniz bir bonus sahibi secilir. Aylıq ve yeni qosulma bonusu hemin emekdasa yazilir.</small></div></div>
        <?php if ($canCalculate): ?>
            <form class="bonus-form-inline" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="save_customer_bonus_assignment"><input type="hidden" name="finance_tab" value="employee-bonuses"><input type="hidden" name="bonus_view" value="customer-traces">
                <div class="portal-form-row"><label>Musteri kodu</label><input name="subscriber_code" placeholder="100001" required></div>
                <?php foreach (['finder_employee_id' => 'Tapan/satis', 'installer_employee_id' => 'Qosan', 'area_responsible_employee_id' => 'Erazi mesulu', 'bonus_owner_employee_id' => 'Tek bonus sahibi'] as $field => $label): ?>
                    <div class="portal-form-row"><label><?= e($label); ?></label><select name="<?= e($field); ?>"<?= $field === 'bonus_owner_employee_id' ? ' required' : ''; ?>><option value="">Sec</option><?php foreach ($employees as $employee): ?><option value="<?= e((string) ($employee['id'] ?? 0)); ?>"><?= e((string) ($employee['full_name'] ?? '-')); ?></option><?php endforeach; ?></select></div>
                <?php endforeach; ?>
                <div class="portal-form-row"><label>Erazi</label><input name="assigned_area_id" placeholder="Baki / Astara"></div>
                <div class="portal-form-row"><label>Qeyd</label><input name="note" placeholder="Sebeb"></div>
                <button class="portal-primary-btn" type="submit">Bagla</button>
            </form>
        <?php endif; ?>
        <?php $pageData = (array) ($bonus['assignments_page'] ?? []); $assignmentRows = (array) ($pageData['rows'] ?? []); ?>
        <div class="portal-table-wrap" style="margin-top:14px"><table class="portal-table"><thead><tr><th>Musteri</th><th>Erazi</th><th>Bonus sahibi</th><th>Tapan</th><th>Qosan</th><th><?= e(app_ui_label('status')); ?></th><th>Yenilenib</th></tr></thead><tbody>
            <?php foreach ($assignmentRows as $row): ?><tr data-finance-row><td><?= e((string) ($row['subscriber_code'] ?? '-')); ?><br><small><?= e((string) ($row['customer_name'] ?? '')); ?></small></td><td><?= e((string) ($row['assigned_area_id'] ?? $row['area'] ?? '-')); ?></td><td><?= e((string) ($row['bonus_owner_name'] ?? $row['bonus_owner_employee_id'] ?? '-')); ?></td><td><?= e((string) ($row['finder_employee_id'] ?? '-')); ?></td><td><?= e((string) ($row['installer_employee_id'] ?? '-')); ?></td><td><?= e(app_ui_status((string) ($row['status'] ?? '-'))); ?></td><td><?= e((string) ($row['updated_at'] ?? '-')); ?></td></tr><?php endforeach; ?>
            <?php if ($assignmentRows === []): ?><tr><td colspan="7" class="finance-empty">Musteri-bonus baglantisi yoxdur.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php require __DIR__ . '/_pagination.php'; ?>
    <?php elseif ($bonusView === 'rules'): ?>
        <div class="portal-card-head"><div><strong>Bonus qaydalari / ayarlar</strong><small>Bu bolmede deyisiklik yalniz Super Admin ucundur.</small></div></div>
        <?php if ($canManageRules): ?>
            <div class="bonus-rule-groups">
                <?php $settingGroupIndex = 0; foreach ($bonusSettingGroups as $groupLabel => $definitions): ?>
                    <details class="bonus-rule-group"<?= $settingGroupIndex === 0 ? ' open' : ''; ?>>
                        <summary><?= e($groupLabel); ?></summary>
                        <div class="bonus-setting-grid">
                            <?php foreach ($definitions as $definition): ?>
                                <?php
                                [$settingKey, $settingLabel, $settingHelp, $valueType, $controlType, $controlMeta] = $definition;
                                $currentSetting = (array) ($bonusSettingsByKey[$settingKey] ?? []);
                                $currentValue = (string) ($currentSetting['setting_value'] ?? '');
                                $isPercent = str_contains($settingKey, 'percent');
                                ?>
                                <form class="bonus-setting-card" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="save_employee_bonus_setting"><input type="hidden" name="finance_tab" value="employee-bonuses"><input type="hidden" name="bonus_view" value="rules"><input type="hidden" name="setting_key" value="<?= e($settingKey); ?>"><input type="hidden" name="value_type" value="<?= e($valueType); ?>">
                                    <div class="bonus-setting-copy"><strong><?= e($settingLabel); ?></strong><small><?= e($settingHelp); ?> Hazirki deyer: <?= e($currentValue); ?></small></div>
                                    <div class="portal-form-row">
                                        <label>Yeni deyer</label>
                                        <?php if ($controlType === 'select'): ?>
                                            <select name="setting_value" required><?php foreach ((array) $controlMeta as $optionValue => $optionLabel): ?><option value="<?= e((string) $optionValue); ?>"<?= $currentValue === (string) $optionValue ? ' selected' : ''; ?>><?= e((string) $optionLabel); ?></option><?php endforeach; ?></select>
                                        <?php else: ?>
                                            <input type="number" name="setting_value" min="0"<?= $isPercent ? ' max="100"' : ''; ?> step="<?= e((string) $controlMeta); ?>" value="<?= e($currentValue); ?>" required>
                                        <?php endif; ?>
                                    </div>
                                    <button class="portal-primary-btn" type="submit">Saxla</button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php $settingGroupIndex++; endforeach; ?>
            </div>
            <div class="bonus-rule-forms">
                <form class="bonus-rule-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="save_employee_bonus_salary_tier"><input type="hidden" name="finance_tab" value="employee-bonuses"><input type="hidden" name="bonus_view" value="rules">
                    <h4>Musteri sayina gore sabit maas</h4>
                    <small>Yeni pille yaradin ve ya movcud pilleni secib yenileyin.</small>
                    <div class="bonus-form-inline">
                        <div class="portal-form-row"><label>Emeliyyat</label><select name="tier_id" data-bonus-rule-picker="salary"><option value="0">Yeni maas pillesi</option><?php foreach ((array) ($bonus['salary_tiers'] ?? []) as $row): ?><option value="<?= e((string) ($row['id'] ?? 0)); ?>" data-min-customers="<?= e((string) ($row['min_customers'] ?? 0)); ?>" data-max-customers="<?= e((string) ($row['max_customers'] ?? '')); ?>" data-salary-amount="<?= e((string) ($row['salary_amount'] ?? 0)); ?>" data-area-quality-percent="<?= e((string) ($row['area_quality_percent'] ?? 100)); ?>" data-active="<?= !empty($row['is_active']) ? '1' : '0'; ?>"><?= e((string) ($row['min_customers'] ?? 0)); ?> - <?= e((string) ($row['max_customers'] ?? 'sonsuz')); ?> musteri / <?= e(billing_money($row['salary_amount'] ?? 0)); ?></option><?php endforeach; ?></select></div>
                        <div class="portal-form-row"><label>Minimum musteri</label><input type="number" min="0" name="min_customers" required></div>
                        <div class="portal-form-row"><label>Maksimum musteri</label><input type="number" min="0" name="max_customers" placeholder="Bos qalarsa limitsiz"></div>
                        <div class="portal-form-row"><label>Sabit maas (AZN)</label><input type="number" step="0.01" min="0" name="salary_amount" required></div>
                        <div class="portal-form-row"><label>Keyfiyyet %</label><input type="number" step="0.01" min="0" max="100" name="area_quality_percent" value="100"></div>
                        <div class="portal-form-row"><label>Sebeb</label><input name="reason" placeholder="Deyisiklik sebebi"></div>
                        <label class="portal-check"><input type="checkbox" name="is_active" value="1" checked> Aktiv</label>
                        <button class="portal-primary-btn">Pilleni saxla</button>
                    </div>
                </form>
                <form class="bonus-rule-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="save_employee_bonus_active_day_bracket"><input type="hidden" name="finance_tab" value="employee-bonuses"><input type="hidden" name="bonus_view" value="rules">
                    <h4>Aktiv gun sayina gore bonus faizi</h4>
                    <small>Ay erzinde aktiv gun sayina uygun tetbiq olunacaq faizi yazin.</small>
                    <div class="bonus-form-inline">
                        <div class="portal-form-row"><label>Emeliyyat</label><select name="bracket_id" data-bonus-rule-picker="active-days"><option value="0">Yeni gun araligi</option><?php foreach ((array) ($bonus['active_day_brackets'] ?? []) as $row): ?><option value="<?= e((string) ($row['id'] ?? 0)); ?>" data-min-days="<?= e((string) ($row['min_days'] ?? 0)); ?>" data-max-days="<?= e((string) ($row['max_days'] ?? 0)); ?>" data-active-percent="<?= e((string) ($row['active_percent'] ?? 0)); ?>" data-active="<?= !empty($row['is_active']) ? '1' : '0'; ?>"><?= e((string) ($row['min_days'] ?? 0)); ?> - <?= e((string) ($row['max_days'] ?? 0)); ?> gun / <?= e((string) ($row['active_percent'] ?? 0)); ?>%</option><?php endforeach; ?></select></div>
                        <div class="portal-form-row"><label>Minimum gun</label><input type="number" min="0" name="min_days" required></div>
                        <div class="portal-form-row"><label>Maksimum gun</label><input type="number" min="0" name="max_days" required></div>
                        <div class="portal-form-row"><label>Bonus %</label><input type="number" step="0.01" min="0" max="100" name="active_percent" required></div>
                        <div class="portal-form-row"><label>Sebeb</label><input name="reason" placeholder="Deyisiklik sebebi"></div>
                        <label class="portal-check"><input type="checkbox" name="is_active" value="1" checked> Aktiv</label>
                        <span></span>
                        <button class="portal-primary-btn">Araligi saxla</button>
                    </div>
                </form>
                <form class="bonus-rule-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="save_employee_bonus_quality_rule"><input type="hidden" name="finance_tab" value="employee-bonuses"><input type="hidden" name="bonus_view" value="rules">
                    <h4>Problem ve keyfiyyet tesiri</h4>
                    <small>Problemin bonus keyfiyyet faizine nece tesir etdiyini secin.</small>
                    <div class="bonus-form-inline">
                        <div class="portal-form-row"><label>Emeliyyat</label><select name="quality_rule_id" data-bonus-rule-picker="quality"><option value="0">Yeni keyfiyyet qaydasi</option><?php foreach ((array) ($bonus['quality_rules'] ?? []) as $row): ?><option value="<?= e((string) ($row['id'] ?? 0)); ?>" data-problem-key="<?= e((string) ($row['problem_key'] ?? '')); ?>" data-problem-label="<?= e((string) ($row['problem_label'] ?? '')); ?>" data-problem-source="<?= e((string) ($row['problem_source'] ?? 'unknown')); ?>" data-severity="<?= e((string) ($row['severity'] ?? 'normal')); ?>" data-quality-percent="<?= e((string) ($row['quality_percent'] ?? 100)); ?>" data-active="<?= !empty($row['is_active']) ? '1' : '0'; ?>"><?= e((string) ($row['problem_label'] ?? '-')); ?> / <?= e((string) ($row['quality_percent'] ?? 100)); ?>%</option><?php endforeach; ?></select></div>
                        <div class="portal-form-row"><label>Qaydanin qisa kodu</label><input name="problem_key" placeholder="meselen: gecikmis_qosulma" required></div>
                        <div class="portal-form-row"><label>Basliq</label><input name="problem_label" placeholder="Isci sehvi" required></div>
                        <div class="portal-form-row"><label><?= e(app_ui_label('source')); ?></label><select name="problem_source"><option value="employee"><?= e(app_ui_status('employee')); ?></option><option value="network"><?= e(app_ui_status('network')); ?></option><option value="customer"><?= e(app_ui_status('customer')); ?></option><option value="unknown"><?= e(app_ui_status('unknown')); ?></option><option value="none"><?= e(app_ui_status('none')); ?></option></select></div>
                        <div class="portal-form-row"><label>Tesir seviyesi</label><select name="severity"><option value="normal">Normal</option><option value="minor">Kicik</option><option value="major">Ciddi</option><option value="critical">Kritik</option></select></div>
                        <div class="portal-form-row"><label>Keyfiyyet %</label><input type="number" step="0.01" min="0" max="100" name="quality_percent" value="100"></div>
                        <div class="portal-form-row"><label>Sebeb</label><input name="reason" placeholder="Deyisiklik sebebi"></div>
                        <label class="portal-check"><input type="checkbox" name="is_active" value="1" checked> Aktiv</label>
                        <button class="portal-primary-btn">Qayda saxla</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        <div class="bonus-mini-grid" style="margin-top:14px">
            <article class="portal-muted-card"><strong>Sabit maas pillesi</strong><div class="spog-list" style="margin-top:10px"><?php foreach ((array) ($bonus['salary_tiers'] ?? []) as $row): ?><div class="spog-list-item"><strong>#<?= e((string) ($row['id'] ?? 0)); ?> / <?= e((string) ($row['min_customers'] ?? 0)); ?> - <?= e((string) ($row['max_customers'] ?? '+')); ?></strong><small><?= e(billing_money($row['salary_amount'] ?? 0)); ?> / keyfiyyet <?= e((string) ($row['area_quality_percent'] ?? 100)); ?>% / <?= e(!empty($row['is_active']) ? app_ui_status('active') : app_ui_status('inactive')); ?></small></div><?php endforeach; ?></div></article>
            <article class="portal-muted-card"><strong>Aktiv gun araligi</strong><div class="spog-list" style="margin-top:10px"><?php foreach ((array) ($bonus['active_day_brackets'] ?? []) as $row): ?><div class="spog-list-item"><strong>#<?= e((string) ($row['id'] ?? 0)); ?> / <?= e((string) ($row['min_days'] ?? 0)); ?>-<?= e((string) ($row['max_days'] ?? 0)); ?> gun</strong><small><?= e((string) ($row['active_percent'] ?? 0)); ?>% / <?= e(!empty($row['is_active']) ? app_ui_status('active') : app_ui_status('inactive')); ?></small></div><?php endforeach; ?></div></article>
            <article class="portal-muted-card"><strong>Keyfiyyet qaydalari</strong><div class="spog-list" style="margin-top:10px"><?php foreach ((array) ($bonus['quality_rules'] ?? []) as $row): ?><div class="spog-list-item"><strong>#<?= e((string) ($row['id'] ?? 0)); ?> / <?= e((string) ($row['problem_label'] ?? '-')); ?></strong><small><?= e((string) ($row['problem_key'] ?? '-')); ?> / <?= e((string) ($row['problem_source'] ?? '-')); ?> / <?= e((string) ($row['quality_percent'] ?? 100)); ?>% / <?= !empty($row['is_active']) ? 'aktiv' : 'passiv'; ?></small></div><?php endforeach; ?></div></article>
        </div>
    <?php elseif ($bonusView === 'adjustments'): ?>
        <div class="portal-card-head"><div><strong>Duzelisler ve <?= e(strtolower(app_ui_label('manual_adjustment'))); ?></strong><small>Maliyye tesdiq gozleyen teklif yaradir; Super Admin yaratdigi duzelis avtomatik tesdiqlenir.</small></div></div>
        <?php if ($canCalculate): ?>
            <form class="bonus-form-inline" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="save_employee_bonus_adjustment"><input type="hidden" name="finance_tab" value="employee-bonuses"><input type="hidden" name="bonus_view" value="adjustments">
                <div class="portal-form-row"><label><?= e(app_ui_label('period')); ?></label><select name="period_id" required><?php foreach ((array) (($bonus['period_page']['rows'] ?? [])) as $period): ?><option value="<?= e((string) ($period['id'] ?? 0)); ?>"><?= e(app_bonus_period_label((array) $period)); ?></option><?php endforeach; ?></select></div>
                <div class="portal-form-row"><label>Emekdas</label><select name="employee_id" required><?php foreach ($employees as $employee): ?><option value="<?= e((string) ($employee['id'] ?? 0)); ?>"><?= e((string) ($employee['full_name'] ?? '-')); ?></option><?php endforeach; ?></select></div>
                <div class="portal-form-row"><label>Tip</label><select name="adjustment_type"><option value="bonus">Bonus</option><option value="penalty">Cerime</option></select></div>
                <div class="portal-form-row"><label>Mebleg</label><input type="number" step="0.01" min="0" name="amount" required></div>
                <div class="portal-form-row"><label>Sebeb</label><input name="reason" required></div><button class="portal-primary-btn"><?= e(app_ui_action('add')); ?></button>
            </form>
        <?php endif; ?>
        <?php $pageData = (array) ($bonus['adjustments_page'] ?? []); $adjustmentRows = (array) ($pageData['rows'] ?? []); ?>
        <div class="portal-table-wrap" style="margin-top:14px"><table class="portal-table"><thead><tr><th><?= e(app_ui_label('period')); ?></th><th>Emekdas</th><th>Tip</th><th>Mebleg</th><th><?= e(app_ui_label('status')); ?></th><th>Sebeb</th></tr></thead><tbody><?php foreach ($adjustmentRows as $row): ?><tr data-finance-row><td><?= e((string) ($row['period_start'] ?? '')); ?> - <?= e((string) ($row['period_end'] ?? '')); ?></td><td><?= e((string) ($row['employee_name'] ?? $row['employee_id'] ?? '-')); ?></td><td><?= e(app_ui_term((string) ($row['adjustment_type'] ?? '-'))); ?></td><td><?= e(billing_money($row['amount'] ?? 0)); ?></td><td><?= e(app_ui_status((string) ($row['status'] ?? '-'))); ?></td><td><?= e((string) ($row['reason'] ?? '')); ?></td></tr><?php endforeach; ?><?php if ($adjustmentRows === []): ?><tr><td colspan="6" class="finance-empty">Duzelis yoxdur.</td></tr><?php endif; ?></tbody></table></div>
        <?php require __DIR__ . '/_pagination.php'; ?>
    <?php elseif ($bonusView === 'payout'): ?>
        <div class="portal-card-head"><div><strong>Odenise hazirliq</strong><small>Tesdiqlenmis dovr odenende Maliyyede bonus xerc qeydi yaradilir.</small></div></div>
        <?php $pageData = (array) ($bonus['period_page'] ?? []); $periodRows = (array) ($pageData['rows'] ?? []); ?>
        <div class="portal-table-wrap"><table class="portal-table"><thead><tr><th><?= e(app_ui_label('period')); ?></th><th><?= e(app_ui_label('status')); ?></th><th>Yekun</th><th><?= e(app_ui_label('finance_entry')); ?></th><th><?= e(app_ui_label('action')); ?></th></tr></thead><tbody><?php foreach ($periodRows as $period): ?><?php $status = (string) ($period['status'] ?? 'draft'); ?><tr data-finance-row><td><?= e(app_bonus_period_label((array) $period)); ?></td><td><span class="bonus-status is-<?= e($status); ?>"><?= e(app_ui_status($status)); ?></span></td><td><strong><?= e(billing_money($period['total_payable'] ?? 0)); ?></strong></td><td><?= e((string) ($period['finance_entry_id'] ?? '-')); ?></td><td><?php if ($canCalculate && $status === 'approved'): ?><form method="post" class="portal-inline-form"><input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="change_employee_bonus_period_status"><input type="hidden" name="finance_tab" value="employee-bonuses"><input type="hidden" name="bonus_view" value="payout"><input type="hidden" name="period_id" value="<?= e((string) ($period['id'] ?? 0)); ?>"><input type="hidden" name="next_status" value="paid"><input name="reason" placeholder="Odenis qeydi" required><button class="portal-primary-btn"><?= e(app_ui_action('pay')); ?></button></form><?php else: ?><span class="bonus-note">Hazir deyil</span><?php endif; ?></td></tr><?php endforeach; ?><?php if ($periodRows === []): ?><tr><td colspan="5" class="finance-empty">Odenis dovru yoxdur.</td></tr><?php endif; ?></tbody></table></div>
        <?php require __DIR__ . '/_pagination.php'; ?>
    <?php elseif ($bonusView === 'audit'): ?>
        <div class="portal-card-head"><div><strong><?= e(app_ui_label('bonus_audit_journal')); ?></strong><small>Bonus qaydasi, hesablamasi, baglanti ve status deyisikliyi burada saxlanir.</small></div></div>
        <?php $pageData = (array) ($bonus['audit_page'] ?? []); $auditRows = (array) ($pageData['rows'] ?? []); ?>
        <div class="portal-table-wrap"><table class="portal-table"><thead><tr><th>Tarix</th><th><?= e(app_ui_label('action')); ?></th><th><?= e(app_ui_label('entity')); ?></th><th><?= e(app_ui_label('actor')); ?></th><th>Xulase</th><th>Sebeb</th></tr></thead><tbody><?php foreach ($auditRows as $row): ?><tr data-finance-row><td><?= e((string) ($row['created_at'] ?? '-')); ?></td><td><?= e((string) ($row['action_type'] ?? '-')); ?></td><td><?= e((string) ($row['entity_type'] ?? '-')); ?> #<?= e((string) ($row['entity_id'] ?? '')); ?></td><td><?= e((string) ($row['actor_name'] ?? '-')); ?></td><td><?= e((string) ($row['summary'] ?? '')); ?></td><td><?= e((string) ($row['reason'] ?? '')); ?></td></tr><?php endforeach; ?><?php if ($auditRows === []): ?><tr><td colspan="6" class="finance-empty">Jurnal qeydi yoxdur.</td></tr><?php endif; ?></tbody></table></div>
        <?php require __DIR__ . '/_pagination.php'; ?>
    <?php endif; ?>
</div>
