<?php
declare(strict_types=1);

if (!function_exists('app_noc_scan_value')) {
function app_noc_scan_value(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row) || is_array($row[$key]) || is_object($row[$key])) {
            continue;
        }
        $value = trim((string) $row[$key]);
        if ($value !== '') {
            return clean_text($value);
        }
    }

    return $default;
}

function app_noc_scan_ports(array $row): string
{
    $ports = [];
    foreach ((array) ($row['openPorts'] ?? $row['ports'] ?? []) as $item) {
        if (is_array($item)) {
            $port = trim((string) ($item['port'] ?? ''));
            $service = trim((string) ($item['service'] ?? $item['name'] ?? ''));
            if ($port !== '') {
                $ports[] = $service !== '' ? $port . '/' . $service : $port;
            }
            continue;
        }
        $port = trim((string) $item);
        if ($port !== '') {
            $ports[] = $port;
        }
    }

    $ports = array_values(array_unique($ports));
    return $ports !== [] ? implode(', ', $ports) : '-';
}

function app_noc_scan_row_model(array $row): array
{
    $ip = app_noc_scan_value($row, ['ipAddress', 'ip_address', 'ip', 'host', 'address'], '-');
    $name = app_noc_scan_value($row, ['existingDeviceName', 'hostname', 'hostName', 'name', 'deviceName'], '');
    if ($name === '' || $name === '-') {
        $name = $ip !== '-' ? 'Scan ' . $ip : 'Scan cihaz';
    }

    $type = strtolower(app_noc_scan_value($row, ['deviceType', 'guessedType', 'type'], 'auto'));
    if (!in_array($type, ['olt', 'router', 'switch', 'server', 'auto'], true)) {
        $type = 'auto';
    }

    return [
        'ip' => $ip,
        'name' => $name,
        'type' => $type,
        'vendor' => app_noc_scan_value($row, ['vendor', 'guessedVendor', 'manufacturer'], ''),
        'model' => app_noc_scan_value($row, ['model', 'guessedModel', 'product'], ''),
        'location' => app_noc_scan_value($row, ['location', 'area', 'branch'], ''),
        'status' => app_noc_scan_value($row, ['status', 'state', 'discoveryStatus'], 'tapildi'),
        'ports' => app_noc_scan_ports($row),
    ];
}

function app_noc_scan_results_from_state(array $state): array
{
    if (is_array($state['results'] ?? null)) {
        return $state['results'];
    }

    return array_is_list($state) ? $state : [];
}

function app_noc_render_scan_results(array $scanResults, string $scanTarget, array $defaults = []): void
{
    $limit = max(1, min(100, (int) ($defaults['limit'] ?? 24)));
    $snmpRead = (string) ($defaults['snmp_read_community'] ?? app_environment_secret('MGS_SNMP_READ_COMMUNITY'));
    $snmpWrite = (string) ($defaults['snmp_write_community'] ?? app_environment_secret('MGS_SNMP_WRITE_COMMUNITY'));
    $snmpPort = (int) ($defaults['snmp_port'] ?? app_setting('mgs_snmp_port', 161));
    $snmpTrapPort = (int) ($defaults['snmp_trap_port'] ?? app_setting('mgs_snmp_trap_port', 162));
    $rows = array_slice(array_values(array_filter($scanResults, 'is_array')), 0, $limit);
    $hasRows = $rows !== [];
    ?>
    <section class="noc-scan-dialog <?= $hasRows ? 'is-open' : 'is-empty'; ?>" data-noc-scan-dialog role="dialog" aria-modal="true" aria-labelledby="nocScanDialogTitle" tabindex="-1"<?= $hasRows ? '' : ' hidden'; ?>>
        <div class="noc-scan-head">
            <div>
                <span class="portal-kicker">IP scan secimi</span>
                <h3 id="nocScanDialogTitle">Tapilan cihazlari monitora elave et</h3>
                <small><?= e($scanTarget !== '' ? $scanTarget : 'Scan neticesi'); ?><?= $hasRows ? ' / ' . e((string) count($rows)) . ' host' : ''; ?></small>
                <span hidden>Scan tamamlandi. Tapilan cihazlari secib monitorinqe elave edin</span>
            </div>
            <div class="noc-scan-actions">
                <button type="button" class="portal-ghost-btn noc-scan-close" data-scan-close>Bagla</button>
                <button type="button" class="portal-ghost-btn" data-scan-add-selected>Secilenleri monitora elave et</button>
            </div>
        </div>
        <div class="noc-scan-results" data-noc-scan-results>
            <?php if ($rows === []): ?>
                <div class="noc-scan-empty">
                    <span data-scan-add-device hidden></span>
                    <strong>Scan neticesi yoxdur</strong>
                    <small>IP scan etdikden sonra tapilan hostlar burada secim siyahisi kimi gosterilecek.</small>
                </div>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <?php $item = app_noc_scan_row_model($row); ?>
                    <article class="noc-scan-result" data-scan-add-device>
                        <label class="noc-scan-check"><input type="checkbox" data-scan-select> Sec</label>
                        <div class="noc-scan-summary">
                            <strong><?= e($item['name']); ?></strong>
                            <small><?= e($item['ip']); ?> / <?= e($item['status']); ?> / ports: <?= e($item['ports']); ?></small>
                        </div>
                        <form method="post" class="noc-scan-add-form" data-scan-add-form>
                            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                            <input type="hidden" name="action" value="create_monitor_device">
                            <input type="hidden" name="device_model" value="<?= e($item['model']); ?>">
                            <input type="hidden" name="ssh_port" value="22">
                            <input type="hidden" name="snmp_version" value="2c">
                            <input type="hidden" name="snmp_read_community" value="<?= e($snmpRead); ?>">
                            <input type="hidden" name="snmp_write_community" value="<?= e($snmpWrite); ?>">
                            <input type="hidden" name="snmp_port" value="<?= e((string) $snmpPort); ?>">
                            <input type="hidden" name="snmp_trap_port" value="<?= e((string) $snmpTrapPort); ?>">
                            <input type="hidden" name="driver_mode" value="real">
                            <input type="text" name="device_name" value="<?= e($item['name']); ?>" placeholder="Cihaz adi" required>
                            <input type="text" name="device_ip" value="<?= e($item['ip']); ?>" placeholder="IP" required>
                            <select name="device_type">
                                <?php foreach (['olt' => 'OLT', 'router' => 'Router', 'switch' => 'Switch', 'server' => 'Server', 'auto' => 'Auto'] as $value => $label): ?>
                                    <option value="<?= e($value); ?>" <?= $item['type'] === $value ? 'selected' : ''; ?>><?= e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="device_vendor" value="<?= e($item['vendor']); ?>" placeholder="Vendor">
                            <input type="text" name="device_location" value="<?= e($item['location']); ?>" placeholder="Lokasiya">
                            <button type="submit" class="portal-primary-btn">Monitora elave et</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php
}

function app_noc_render_scan_bulk_script(): void
{
    ?>
    <script>
    (() => {
        document.addEventListener('click', async (event) => {
            const button = event.target?.closest?.('[data-scan-add-selected]');
            if (!button) {
                return;
            }
            const root = button.closest('[data-noc-scan-dialog]');
            if (!root) {
                return;
            }
            const forms = Array.from(root.querySelectorAll('[data-scan-add-device]'))
                .filter((card) => card.querySelector('[data-scan-select]')?.checked)
                .map((card) => card.querySelector('[data-scan-add-form]'))
                .filter(Boolean);
            const results = root.querySelector('[data-noc-scan-results]');
            if (forms.length === 0) {
                results?.classList.add('is-attention');
                return;
            }
            event.preventDefault();
            button.disabled = true;
            const originalText = button.textContent;
            button.textContent = 'Elave olunur...';
            for (const form of forms) {
                await fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                });
            }
            button.textContent = originalText || 'Secilenleri monitora elave et';
            root.classList.remove('is-open');
            root.hidden = true;
            if (typeof window.SuperopticReloadNocContent === 'function') {
                window.SuperopticReloadNocContent(window.location.href, false);
            } else {
                window.location.reload();
            }
        });
        document.addEventListener('click', (event) => {
            const close = event.target?.closest?.('[data-scan-close]');
            if (!close) {
                return;
            }
            const root = close.closest('[data-noc-scan-dialog]');
            if (!root) {
                return;
            }
            root.classList.remove('is-open');
            root.hidden = true;
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }
            document.querySelectorAll('[data-noc-scan-dialog].is-open').forEach((root) => {
                root.classList.remove('is-open');
                root.hidden = true;
            });
        });
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelector('[data-noc-scan-dialog].is-open')?.focus();
        });
    })();
    </script>
    <?php
}
}
