<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';
require_once __DIR__ . '/integration-api-client-lib.php';

$lang = app_lang();
$user = billing_require_employee(true);
if (!app_portal_is_super_admin($user)) {
    http_response_code(403);
    exit('Bu bolmeye yalniz Super Admin daxil ola biler.');
}

$db = app_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    exit('Database elcatmazdir.');
}

$flash = null;
$oneTimeCredential = $_SESSION['integration_api_one_time'] ?? null;
unset($_SESSION['integration_api_one_time']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!app_verify_csrf($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu. Sehifeni yenileyin.'];
    } else {
        $action = clean_text((string) ($_POST['action'] ?? ''));
        try {
            if ($action === 'create') {
                $created = app_integration_api_create($db, $user, [
                    'provider_slug' => $_POST['provider_slug'] ?? '',
                    'display_name' => $_POST['display_name'] ?? '',
                    'scopes' => $_POST['scopes'] ?? [],
                    'allowed_ips' => $_POST['allowed_ips'] ?? '',
                    'expires_at' => $_POST['expires_at'] ?? '',
                ]);
                $_SESSION['integration_api_one_time'] = $created;
                header('Location: api-integrations.php?created=1');
                exit;
            }
            if ($action === 'rotate') {
                $rotated = app_integration_api_rotate($db, $user, (int) ($_POST['client_id'] ?? 0));
                $_SESSION['integration_api_one_time'] = $rotated;
                header('Location: api-integrations.php?rotated=1');
                exit;
            }
            if ($action === 'revoke') {
                app_integration_api_revoke(
                    $db,
                    $user,
                    (int) ($_POST['client_id'] ?? 0),
                    (string) ($_POST['reason'] ?? '')
                );
                header('Location: api-integrations.php?revoked=1');
                exit;
            }
            throw new InvalidArgumentException('unknown_action');
        } catch (Throwable $exception) {
            $messages = [
                'provider_slug_required' => 'Provider kodunu yazin.',
                'display_name_required' => 'Inteqrasiya adini yazin.',
                'scope_required' => 'En azi bir API icazesi secin.',
                'allowed_ip_invalid' => 'IP siyahisinda sehv unvan ve ya CIDR var.',
                'expiry_invalid' => 'Bitme tarixi gelecek vaxt olmalidir.',
                'revoke_reason_required' => 'Legv sebebini yazin.',
                'active_integration_api_client_not_found' => 'Aktiv acar tapilmadi.',
                'integration_api_migration_required' => 'API credential migration-u hele icra olunmayib.',
            ];
            $code = $exception->getMessage();
            $flash = ['type' => 'error', 'message' => $messages[$code] ?? 'Emeliyyat saxlanmadi. Jurnali yoxlayin.'];
        }
    }
}

if (isset($_GET['revoked'])) {
    $flash = ['type' => 'success', 'message' => 'API acari legv edildi.'];
}

$migrationReady = app_integration_api_table_exists($db);
$clients = $migrationReady ? app_integration_api_clients($db) : [];
$scopeLabels = [
    'customer:read' => 'Musteri yoxlamasi',
    'payment:write' => 'Odenis qebulu',
    'payment:status' => 'Odenis statusu',
];
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API inteqrasiyalari</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
    <style>
        .api-secret-box{background:#ecf8f6;border:1px solid #8bcfc6;border-radius:8px;padding:18px;display:grid;gap:12px}
        .api-secret-grid{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end}
        .api-secret-value{display:block;overflow-wrap:anywhere;background:#fff;border:1px solid #cdd9e5;border-radius:6px;padding:11px;font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
        .api-scope-list{display:flex;gap:10px 18px;flex-wrap:wrap}
        .api-scope-list label{display:flex;align-items:center;gap:7px}
        .api-client-actions{display:flex;gap:8px;flex-wrap:wrap}
        .api-client-actions form{display:flex;gap:8px;align-items:center;margin:0}
        .api-client-actions input{min-width:170px}
        .api-key-id{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}
        @media(max-width:760px){.api-secret-grid{grid-template-columns:1fr}.api-client-actions form{width:100%;flex-wrap:wrap}.api-client-actions input{flex:1 1 180px}}
    </style>
</head>
<body class="portal-body">
<?php billing_render_header('API inteqrasiyalari', 'system-health', $lang); ?>
<main class="portal-layout">
    <?php if ($flash !== null): ?><div class="portal-alert portal-alert-<?= e($flash['type']); ?>"><span><?= e($flash['message']); ?></span></div><?php endif; ?>
    <?php if (!$migrationReady): ?>
        <div class="portal-alert portal-alert-error"><span>Credential cədvəli hazır deyil. CLI-dən versiyalanmış migration-u icra edin.</span></div>
    <?php endif; ?>

    <?php if (is_array($oneTimeCredential)): ?>
        <section class="api-secret-box" aria-labelledby="one-time-secret-title">
            <div>
                <strong id="one-time-secret-title">Yeni private secret yalnız indi göstərilir</strong>
                <p>Bu səhifəni yenilədikdən sonra private secret sistemdən oxuna bilməyəcək. Təchizatçıya təhlükəsiz kanal ilə təqdim edin.</p>
            </div>
            <div class="api-secret-grid">
                <div><label>Public key ID</label><code class="api-secret-value" id="api-public-key"><?= e((string) $oneTimeCredential['key_id']); ?></code></div>
                <button class="portal-ghost-btn" type="button" data-copy-target="api-public-key">Kopyala</button>
            </div>
            <div class="api-secret-grid">
                <div><label>Private secret</label><code class="api-secret-value" id="api-private-secret"><?= e((string) $oneTimeCredential['secret']); ?></code></div>
                <button class="portal-primary-btn" type="button" data-copy-target="api-private-secret">Kopyala</button>
            </div>
            <small>Authorization: <code>Bearer PUBLIC_KEY_ID.PRIVATE_SECRET</code>. Ödəniş sorğusunda timestamp və HMAC imzası da tələb olunur.</small>
        </section>
    <?php endif; ?>

    <section class="portal-card portal-reveal">
        <div class="portal-card-head">
            <div>
                <h1>Yeni API açarı yarat</h1>
                <small>Emanat, United Payment və gələcək ödəniş tərəfdaşları üçün ayrı, izlənə bilən açar yaradın.</small>
            </div>
        </div>
        <form method="post" class="portal-stacked-form portal-section-gap" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="create">
            <div class="portal-form-row portal-grid-slim">
                <div><label for="displayName">Inteqrasiya adi</label><input id="displayName" name="display_name" required placeholder="Emanat production"></div>
                <div><label for="providerSlug">Provider kodu</label><input id="providerSlug" name="provider_slug" required pattern="[A-Za-z0-9 _-]{2,64}" placeholder="emanat"></div>
            </div>
            <div>
                <label>API icazeleri</label>
                <div class="api-scope-list">
                    <?php foreach ($scopeLabels as $scope => $label): ?>
                        <label><input type="checkbox" name="scopes[]" value="<?= e($scope); ?>" checked> <?= e($label); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="portal-form-row portal-grid-slim">
                <div><label for="allowedIps">Icazeli IP/CIDR</label><input id="allowedIps" name="allowed_ips" placeholder="203.0.113.10, 203.0.113.0/28"><small>Bos saxlanarsa IP mehdudiyyeti olmur. Production ucun provider IP-si mutleq qeyd edilmelidir.</small></div>
                <div><label for="expiresAt">Bitme vaxti</label><input id="expiresAt" type="datetime-local" name="expires_at"><small>Bos saxlanarsa acar legv ve ya rotasiya edilene qeder aktivdir.</small></div>
            </div>
            <button class="portal-primary-btn" type="submit" <?= !$migrationReady ? 'disabled' : ''; ?>>Unikal acar yarat</button>
        </form>
    </section>

    <section class="portal-card portal-reveal">
        <div class="portal-card-head">
            <div><h2>API acar reyestri</h2><small>Private secret DB-də saxlanmır; yalnız hash saxlanır. İstifadə, rotasiya və ləğv jurnala yazılır.</small></div>
        </div>
        <div class="portal-table-wrap">
            <table class="portal-table">
                <thead><tr><th>Inteqrasiya</th><th>Public key ID</th><th>Icazeler</th><th>IP</th><th>Status</th><th>Son istifade</th><th>Emeliyyat</th></tr></thead>
                <tbody>
                <?php foreach ($clients as $client): ?>
                    <?php
                    $scopes = app_integration_api_decode_list($client['scopes_json'] ?? null);
                    $ips = app_integration_api_decode_list($client['allowed_ips_json'] ?? null);
                    $status = (string) ($client['status'] ?? 'revoked');
                    ?>
                    <tr>
                        <td><strong><?= e((string) $client['display_name']); ?></strong><small><?= e((string) $client['provider_slug']); ?></small></td>
                        <td class="api-key-id"><?= e((string) $client['key_id']); ?></td>
                        <td><?= e(implode(', ', array_map(static fn (string $scope): string => $scopeLabels[$scope] ?? $scope, $scopes))); ?></td>
                        <td><?= e($ips === [] ? 'Məhdud deyil' : implode(', ', $ips)); ?></td>
                        <td><span class="portal-pill"><?= e($status === 'active' ? 'Aktiv' : 'Legv edilib'); ?></span><?php if ((string) ($client['expires_at'] ?? '') !== ''): ?><small><?= e((string) $client['expires_at']); ?>-dək</small><?php endif; ?></td>
                        <td><?= e((string) ($client['last_used_at'] ?? 'Istifade olunmayib')); ?><small><?= e((string) ($client['last_used_ip'] ?? '')); ?></small></td>
                        <td>
                            <?php if ($status === 'active'): ?>
                                <div class="api-client-actions">
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="rotate"><input type="hidden" name="client_id" value="<?= (int) $client['id']; ?>"><button class="portal-ghost-btn" type="submit">Rotasiya et</button></form>
                                    <form method="post" data-confirm="Bu acari legv etmek istediyinize eminsiniz?"><input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="client_id" value="<?= (int) $client['id']; ?>"><input name="reason" required placeholder="Legv sebebi"><button class="portal-ghost-btn" type="submit">Legv et</button></form>
                                </div>
                            <?php else: ?>- <small><?= e((string) ($client['revoke_reason'] ?? '')); ?></small><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($clients === []): ?><tr><td colspan="7">Hələ API açarı yaradılmayıb.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="portal-card portal-reveal">
        <div class="portal-card-head"><div><h2>Qoşulma qaydası</h2><small>Endpoint sənədləri providerə təqdim edilən ayrıca PDF-də saxlanır.</small></div></div>
        <ol>
            <li><code>Authorization: Bearer PUBLIC_KEY_ID.PRIVATE_SECRET</code></li>
            <li><code>X-SuperOptic-Timestamp</code>: cari Unix vaxtı.</li>
            <li><code>X-SuperOptic-Signature</code>: <code>HMAC-SHA256(timestamp + "\n" + raw_body, PRIVATE_SECRET)</code>.</li>
            <li>Ödəniş POST-u üçün unikal <code>Idempotency-Key</code> göndərilməlidir.</li>
        </ol>
    </section>
</main>
<script>
document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        const target = document.getElementById(button.dataset.copyTarget);
        if (!target) return;
        await navigator.clipboard.writeText(target.textContent.trim());
        const original = button.textContent;
        button.textContent = 'Kopyalandi';
        window.setTimeout(() => { button.textContent = original; }, 1400);
    });
});
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});
</script>
</body>
</html>
