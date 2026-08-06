<?php

declare(strict_types=1);

require_once __DIR__ . '/../../chart-svg-lib.php';

$data = (array) ($billingContext['data'] ?? []);
$charts = (array) ($data['charts'] ?? []);
$format = static fn (mixed $value, string $kind): string => $kind === 'money'
    ? number_format((float) $value, 2, '.', ' ') . ' AZN'
    : number_format((float) $value, 0, '.', ' ');
$toneClass = static fn (string $tone): string => 'dash-tone-' . preg_replace('/[^a-z]/', '', strtolower($tone) ?: 'primary');
?>
<div data-billing-overview class="dash">

    <div class="dash-kpis">
        <?php foreach ((array) ($data['kpis'] ?? []) as $kpi): ?>
            <?php $tone = (string) ($kpi['tone'] ?? 'primary'); ?>
            <div class="dash-kpi <?= e($toneClass($tone)); ?>" data-billing-kpi>
                <span class="dash-kpi-icon"><i class="fa-solid <?= e((string) ($kpi['icon'] ?? 'fa-chart-simple')); ?>"></i></span>
                <span class="dash-kpi-body">
                    <span class="dash-kpi-label"><?= e((string) ($kpi['label'] ?? '-')); ?></span>
                    <strong class="dash-kpi-value"><?= e($format($kpi['value'] ?? 0, (string) ($kpi['kind'] ?? 'count'))); ?></strong>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="dash-charts">
        <section class="dash-chart-card dash-chart-wide">
            <div class="dash-chart-head">
                <div><strong>Son 14 gunun odenisleri</strong><small>Gunluk yigim dinamikasi</small></div>
                <span class="dash-chip">14 gun</span>
            </div>
            <?= app_chart_bars((array) ($charts['trend'] ?? []), ['height' => 160, 'money' => true]); ?>
        </section>
        <section class="dash-chart-card">
            <div class="dash-chart-head"><div><strong>Xidmet statusu</strong><small>Aktiv / riskli</small></div></div>
            <?= app_chart_donut((array) ($charts['services'] ?? []), ['center_label' => 'Xidmet']); ?>
        </section>
        <section class="dash-chart-card">
            <div class="dash-chart-head"><div><strong>Musteri statusu</strong><small>Aktiv / passiv</small></div></div>
            <?= app_chart_donut((array) ($charts['customers'] ?? []), ['center_label' => 'Musteri']); ?>
        </section>
    </div>

    <div class="dash-actions">
        <a class="portal-primary-btn portal-inline-link" href="billing-customers.php"><i class="fa-solid fa-users"></i> Musteriler</a>
        <a class="portal-ghost-btn portal-inline-link" href="billing-payments.php"><i class="fa-solid fa-wallet"></i> Odenisler</a>
        <a class="portal-ghost-btn portal-inline-link" href="billing-balances.php"><i class="fa-solid fa-receipt"></i> Balans ve borclar</a>
        <a class="portal-ghost-btn portal-inline-link" href="billing-statistics.php"><i class="fa-solid fa-chart-line"></i> Statistika</a>
    </div>

    <div class="dash-panels">
        <section class="dash-panel">
            <div class="dash-panel-head">
                <div><strong>Diqqet teleb edenler</strong><small>Borc ve yaxinlasan servis bitmeleri</small></div>
                <a href="billing-balances.php" class="portal-ghost-btn portal-inline-link">Hamisi</a>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table dash-table">
                    <thead><tr><th>Abonent</th><th>Borc</th><th>Bitme</th></tr></thead>
                    <tbody>
                        <?php foreach ((array) ($data['alerts'] ?? []) as $row): ?>
                            <tr data-billing-row>
                                <td><strong><?= e((string) ($row['subscriber_code'] ?? '-')); ?></strong><br><small><?= e((string) ($row['full_name'] ?? '-')); ?></small></td>
                                <td><span class="dash-badge dash-badge-danger"><?= e($format($row['debt_amount'] ?? 0, 'money')); ?></span></td>
                                <td><?= e((string) ($row['expires_at'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (($data['alerts'] ?? []) === []): ?>
                            <tr><td colspan="3" class="billing-empty">Aktiv xeberdarliq yoxdur.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="dash-panel">
            <div class="dash-panel-head">
                <div><strong>Son odenisler</strong><small>Billing daxilinde son qeydler</small></div>
                <a href="billing-payments.php" class="portal-ghost-btn portal-inline-link">Hamisi</a>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table dash-table">
                    <thead><tr><th>Abonent</th><th>Provider</th><th>Mebleg</th></tr></thead>
                    <tbody>
                        <?php foreach ((array) ($data['recent'] ?? []) as $row): ?>
                            <tr data-billing-row>
                                <td><strong><?= e((string) ($row['subscriber_code'] ?? '-')); ?></strong><br><small><?= e((string) ($row['full_name'] ?? '-')); ?></small></td>
                                <td><span class="dash-badge"><?= e((string) ($row['payment_method'] ?? '-')); ?></span></td>
                                <td><span class="dash-badge dash-badge-success"><?= e($format($row['amount'] ?? 0, 'money')); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (($data['recent'] ?? []) === []): ?>
                            <tr><td colspan="3" class="billing-empty">Odenis yoxdur.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
