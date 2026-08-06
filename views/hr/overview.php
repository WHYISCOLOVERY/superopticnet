<?php
declare(strict_types=1);
$hrStatistics = (array) ($hrContext['data']['statistics'] ?? []);
$overviewEmployees = (array) ($hrContext['data']['employees'] ?? []);
$overviewDocuments = (array) ($hrContext['data']['documents'] ?? []);
?>
<div data-hr-overview>
    <div class="hr-kpis"><?php foreach (array_slice((array) ($hrStatistics['kpis'] ?? []), 0, 6) as $kpi): ?><article class="hr-kpi"><span><?= e((string) ($kpi['label'] ?? '-')); ?></span><strong><?= ($kpi['kind'] ?? 'money') === 'count' ? e(number_format((float) ($kpi['value'] ?? 0), 0, '.', ' ')) : e(billing_money($kpi['value'] ?? 0)); ?></strong></article><?php endforeach; ?></div>
    <div class="hr-shortcuts">
        <?php foreach (['employees' => ['Emekdaslar','Maas bazasi ve vezife melumatlari'], 'attendance' => ['Davamiyyet','Is qrafiki ve kart profili'], 'leave-travel' => ['Mezuniyyet ve ezamiyyet','Qanuni hesablamalar ve statuslar'], 'performance' => ['Performans','Sicil bali, ceza ve mukafat'], 'documents' => ['Senedler','Tamliq ve son tarix nezareti'], 'statistics' => ['Statistika','Yalniz HR chartlari']] as $key => $shortcut): ?><a class="hr-shortcut" data-hr-nav href="<?= e(app_hr_url($key, [], $hrLang)); ?>"><strong><?= e($shortcut[0]); ?></strong><small><?= e($shortcut[1]); ?></small></a><?php endforeach; ?>
        <a class="hr-shortcut" href="<?= e('operations-center.php?' . http_build_query(['section' => 'finance', 'finance_tab' => 'employee-bonuses', 'bonus_view' => 'employee-detail', 'employee_id' => (int) ($hrContext['user']['id'] ?? 0)])); ?>"><strong>Menim bonuslarim</strong><small>Oz bonus detali ve musteri izleri</small></a>
    </div>
    <div class="portal-grid portal-grid-2" style="margin-top:14px">
        <article class="portal-muted-card"><div class="portal-card-head"><strong>Aktiv emekdaslar</strong><a data-hr-nav href="<?= e(app_hr_url('employees', [], $hrLang)); ?>">Hamisi</a></div><?php foreach ($overviewEmployees as $row): ?><div class="spog-list-item"><strong><?= e((string) ($row['full_name'] ?? '-')); ?></strong><small><?= e((string) ($row['job_title'] ?? $row['login'] ?? '-')); ?></small></div><?php endforeach; ?><?php if ($overviewEmployees === []): ?><p class="hr-empty">Aktiv emekdas yoxdur.</p><?php endif; ?></article>
        <article class="portal-muted-card"><div class="portal-card-head"><strong>Sened tamligi riski</strong><a data-hr-nav href="<?= e(app_hr_url('documents', [], $hrLang)); ?>">Hamisi</a></div><?php foreach ($overviewDocuments as $row): ?><div class="spog-list-item"><strong><?= e((string) ($row['employee_name'] ?? '-')); ?></strong><small><?= e((string) ($row['complete_percent'] ?? 0)); ?>% tamam / <?= e((string) ($row['missing_count'] ?? 0)); ?> catismir</small></div><?php endforeach; ?><?php if ($overviewDocuments === []): ?><p class="hr-empty">Sened riski yoxdur.</p><?php endif; ?></article>
    </div>
</div>
