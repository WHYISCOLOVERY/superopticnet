<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

app_session_boot();
$user = app_portal_current_user();
if ($user === null) {
    header('Location: portal-login.php?status=auth_required');
    exit;
}

$status = clean_text((string) ($_GET['status'] ?? ''));
$message = match ($status) {
    'portal_password_failed' => 'Cari parol və yeni parol tələblərini yoxlayın.',
    'password_change_required' => 'Davam etmək üçün ilkin parolu dəyişin.',
    default => !empty($user['must_change_password']) ? 'İlk giriş təhlükəsizliyi üçün yeni parol təyin edin.' : '',
};
?>
<!doctype html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Parolu dəyiş | SuperOptik</title>
    <link rel="stylesheet" href="css/portal.css">
    <style>
        body { margin: 0; background: #f4f8fb; color: #10243e; font-family: Arial, sans-serif; }
        .password-shell { min-height: 100vh; display: grid; place-items: center; padding: 24px; box-sizing: border-box; }
        .password-panel { width: min(100%, 460px); background: #fff; border: 1px solid #d8e1eb; border-radius: 8px; padding: 28px; box-shadow: 0 18px 45px rgba(16,36,62,.12); }
        .password-panel h1 { margin: 0 0 8px; font-size: 26px; }
        .password-panel p { margin: 0 0 22px; color: #5c6f87; line-height: 1.5; }
        .password-panel label { display: block; margin: 14px 0 6px; font-weight: 700; }
        .password-panel input { width: 100%; min-height: 46px; box-sizing: border-box; border: 1px solid #cbd6e2; border-radius: 7px; padding: 10px 12px; font-size: 16px; }
        .password-panel button { width: 100%; min-height: 48px; margin-top: 22px; border: 0; border-radius: 7px; background: #ff8552; color: #10243e; font-size: 16px; font-weight: 800; cursor: pointer; }
        .password-note { font-size: 13px; }
    </style>
</head>
<body>
<main class="password-shell">
    <section class="password-panel" aria-labelledby="passwordTitle">
        <h1 id="passwordTitle">Parolu dəyiş</h1>
        <p><?= e($message); ?></p>
        <form method="post" action="portal-actions.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
            <input type="hidden" name="action" value="update_password">
            <label for="currentPassword">Cari parol</label>
            <input id="currentPassword" type="password" name="current_password" required autocomplete="current-password">
            <label for="newPassword">Yeni parol</label>
            <input id="newPassword" type="password" name="new_password" minlength="14" required autocomplete="new-password">
            <label for="confirmPassword">Yeni parolu təkrar yazın</label>
            <input id="confirmPassword" type="password" name="confirm_password" minlength="14" required autocomplete="new-password">
            <p class="password-note">Ən az 14 simvol, böyük və kiçik hərf, rəqəm və xüsusi işarə istifadə edin.</p>
            <button type="submit">Parolu yenilə</button>
        </form>
    </section>
</main>
</body>
</html>
