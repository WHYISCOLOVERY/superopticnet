<?php
declare(strict_types=1);

require_once __DIR__ . '/billing-ui.php';

$lang = app_lang();
$user = billing_require_employee(true);

$flash = null;
$db = app_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!app_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
        $flash = ['type' => 'error', 'message' => 'Sessiya yoxlamasi ugursuz oldu.'];
    } elseif (clean_text((string) ($_POST['action'] ?? '')) === 'link_mygov_identity') {
        $lookup = clean_text((string) ($_POST['account_lookup'] ?? ''));
        $providerSubject = clean_text((string) ($_POST['provider_subject'] ?? ''));
        $account = app_db_account_by_subscriber_code($lookup) ?? app_db_account_by_login($lookup);
        if ($account === null || $providerSubject === '') {
            $flash = ['type' => 'error', 'message' => 'Hesab ve MyGov subject/FIN doldurulmalidir.'];
        } else {
            $ok = app_sso_link_identity((int) $account['id'], 'mygov', [
                'provider_subject' => $providerSubject,
                'fin_code' => clean_text((string) ($_POST['fin_code'] ?? '')),
                'document_no' => clean_text((string) ($_POST['document_no'] ?? '')),
                'full_name' => clean_text((string) ($_POST['verified_full_name'] ?? $account['full_name'] ?? '')),
                'birth_date' => clean_text((string) ($_POST['verified_birth_date'] ?? '')),
                'linked_by' => (string) ($user['login'] ?? ''),
            ]);
            $flash = [
                'type' => $ok ? 'success' : 'error',
                'message' => $ok ? 'MyGov identity hesaba baglandi.' : 'Identity baglanmadi. Tekrar subject ola biler.',
            ];
        }
    }
}

$config = app_sso_provider_config('mygov');
$ready = app_sso_provider_ready('mygov');
$overview = app_sso_identity_overview();
$linked = [];
if ($db instanceof PDO) {
    $linked = $db->query(
        "SELECT i.*, a.full_name, a.login, a.subscriber_code, a.role
         FROM portal_account_identities i
         INNER JOIN portal_accounts a ON a.id = i.account_id
         WHERE i.provider = 'mygov'
         ORDER BY i.updated_at DESC
         LIMIT 50"
    )->fetchAll() ?: [];
} else {
    $mock = app_mock_portal_data();
    $firstCustomer = [];
    foreach (($mock['customers'] ?? []) as $login => $customer) {
        if (is_array($customer)) {
            $profile = is_array($customer['dashboard']['profile'] ?? null) ? $customer['dashboard']['profile'] : [];
            $firstCustomer = [
                'subscriber_code' => (string) ($customer['subscriber_code'] ?? $profile['subscriber_code'] ?? $login),
                'login' => (string) $login,
                'full_name' => (string) ($profile['full_name'] ?? $customer['name'] ?? $login),
                'role' => 'customer',
                'provider_subject' => 'mock-mygov-subject',
                'fin_code' => 'MOCKFIN',
                'verified_full_name' => (string) ($profile['full_name'] ?? $customer['name'] ?? $login),
                'last_verified_at' => date('Y-m-d H:i:s'),
            ];
            break;
        }
    }
    $linked = $firstCustomer !== [] ? [$firstCustomer] : [];
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyGov ID inteqrasiyasi</title>
    <link rel="stylesheet" href="css/fontawesome-lite.css">
    <link rel="stylesheet" href="<?= e(app_asset('css/portal.css')); ?>">
</head>
<body class="portal-body">
    <?php billing_render_header('MyGov ID inteqrasiyasi', 'identity-integrations', $lang); ?>

    <main class="portal-layout">
        <?php if ($flash !== null): ?>
            <div class="portal-alert portal-alert-<?= e($flash['type']); ?>" data-autohide-alert>
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?= e($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <section class="portal-hero portal-reveal">
            <div>
                <span class="portal-kicker">SSO / Identity</span>
                <h1>Müştəri və əməkdaş üçün MyGov ID hazırlıq modulu</h1>
                <p>Real API qoşulmayana qədər modul passivdir. State, nonce, provider identity mapping və callback skeleti hazır saxlanır.</p>
            </div>
            <div class="portal-status-card">
                <span>Konfiqurasiya</span>
                <strong><?= e($ready ? 'Hazirdir' : 'Passiv / natamam'); ?></strong>
                <small><?= e(!empty($config['enabled']) ? 'mygov_enabled=1' : 'mygov_enabled=0'); ?></small>
            </div>
        </section>

        <section class="portal-grid portal-grid-4 portal-reveal">
            <article class="portal-metric-card"><span>Bagli identity</span><strong><?= e((string) $overview['linked']); ?></strong><small>portal_account_identities</small></article>
            <article class="portal-metric-card"><span>Aktiv state</span><strong><?= e((string) $overview['states_active']); ?></strong><small>10 deqiqelik login state</small></article>
            <article class="portal-metric-card"><span>Bitmis state</span><strong><?= e((string) $overview['states_expired']); ?></strong><small>Temizleme cron ucun</small></article>
            <article class="portal-metric-card"><span>Mock</span><strong><?= e(!empty($config['mock_enabled']) ? 'Aktiv' : 'Passiv'); ?></strong><small>Yalniz test ucun</small></article>
        </section>

        <section class="mgs-action-strip portal-reveal">
            <article class="mgs-action-tile"><i class="fa-solid fa-key"></i><strong>OIDC skeleti</strong><small>State, nonce və callback qoruması əvvəlcədən ayrılıb.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-id-card"></i><strong>FIN mapping</strong><small>Müştəri və əməkdaş eyni provider identity modeli ilə bağlanır.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-shield-halved"></i><strong>Təhlükəsiz giriş</strong><small>API qoşulanda lokal parol əvəzinə vahid identifikasiya istifadə ediləcək.</small></article>
            <article class="mgs-action-tile"><i class="fa-solid fa-clipboard-check"></i><strong>Audit hazırdır</strong><small>Kim bağladı, nə vaxt yoxlandı və hansı hesaba düşdü saxlanır.</small></article>
        </section>

        <section class="portal-grid portal-grid-2 portal-reveal">
            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Gelecek API ayarlari</h2>
                    <small>Bu dəyərlər doldurulduqda giriş düyməsi real MyGov səlahiyyətləndirmə ünvanına yönləndiriləcək.</small>
                </div>
                <div class="mgs-command-actions" style="margin-bottom:16px;">
                    <a class="portal-ghost-btn portal-inline-link" href="admin-users.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Istifadeciler</a>
                    <a class="portal-ghost-btn portal-inline-link" href="mgs-copilot-context.php<?= $lang !== 'az' ? '?lang=' . rawurlencode($lang) : ''; ?>">Copilot context</a>
                </div>
                <div class="portal-profile-list">
                    <div><span>Client ID</span><strong><?= e((string) ($config['client_id'] !== '' ? 'doldurulub' : 'bos')); ?></strong></div>
                    <div><span>Client Secret</span><strong><?= e((string) ($config['client_secret'] !== '' ? 'doldurulub' : 'bos')); ?></strong></div>
                    <div><span>Authorize URL</span><strong><?= e((string) ($config['authorize_url'] !== '' ? 'doldurulub' : 'bos')); ?></strong></div>
                    <div><span>Token URL</span><strong><?= e((string) ($config['token_url'] !== '' ? 'doldurulub' : 'bos')); ?></strong></div>
                    <div><span>Userinfo URL</span><strong><?= e((string) ($config['userinfo_url'] !== '' ? 'doldurulub' : 'bos')); ?></strong></div>
                    <div><span>Redirect URI</span><strong><?= e((string) $config['redirect_uri']); ?></strong></div>
                </div>
            </article>

            <article class="portal-card">
                <div class="portal-card-head">
                    <h2>Identity-ni hesaba bagla</h2>
                    <small>Real API gələndə bu məlumat avtomatik userinfo-dan gələcək. İndi test və hazırlıq üçündür.</small>
                </div>
                <form method="post" class="portal-stacked-form">
                    <input type="hidden" name="action" value="link_mygov_identity">
                    <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                    <div class="portal-form-row portal-grid-slim">
                        <div><label>Hesab login/kod</label><input type="text" name="account_lookup" placeholder="1100000 / admin login" required></div>
                        <div><label>MyGov subject</label><input type="text" name="provider_subject" placeholder="mygov sub / FIN hash" required></div>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <div><label>FIN</label><input type="text" name="fin_code"></div>
                        <div><label>Şəxsiyyət seriyası</label><input type="text" name="document_no"></div>
                    </div>
                    <div class="portal-form-row portal-grid-slim">
                        <div><label>Təsdiqli ad soyad</label><input type="text" name="verified_full_name"></div>
                        <div><label>Doğum tarixi</label><input type="date" name="verified_birth_date"></div>
                    </div>
                    <button type="submit" class="portal-primary-btn">Identity bagla</button>
                </form>
            </article>
        </section>

        <section class="portal-card portal-reveal">
            <div class="portal-card-head">
                <h2>Bagli MyGov identity-lər</h2>
                <small>Gələcəkdə login bu mapping əsasında portal hesabına düşəcək.</small>
            </div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Hesab</th><th>Rol</th><th>Subject / FIN</th><th>Tesdiq</th><th>Son yoxlama</th></tr></thead>
                    <tbody>
                        <?php foreach ($linked as $identity): ?>
                            <tr>
                                <td><?= e((string) ($identity['subscriber_code'] ?: $identity['login'])); ?><br><small><?= e((string) $identity['full_name']); ?></small></td>
                                <td><?= e((string) $identity['role']); ?></td>
                                <td><?= e((string) $identity['provider_subject']); ?><br><small><?= e((string) ($identity['fin_code'] ?? '-')); ?></small></td>
                                <td><?= e((string) ($identity['verified_full_name'] ?? '-')); ?></td>
                                <td><?= e((string) ($identity['last_verified_at'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script src="<?= e(app_asset('script/portal.js')); ?>"></script>
    <?php require_once __DIR__ . '/copilot-widget.php'; app_render_copilot_widget(function_exists('app_lang') ? app_lang() : 'az'); ?>
</body>
</html>
