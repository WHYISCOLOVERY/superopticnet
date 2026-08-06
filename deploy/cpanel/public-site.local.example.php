<?php
declare(strict_types=1);

return [
    'site_url' => 'https://superoptic.net',
    'portal_url' => 'https://mgt.superoptic.net/portal-login.php',
    'mgs_lead_gateway_url' => 'https://mgt.superoptic.net/api/public/website-lead.php',
    'payment_url' => 'https://www.million.az/services/internet/Super%20Optik',
    'recaptcha_site_key' => '',
    'timezone' => 'Asia/Baku',
    'storage_path' => __DIR__ . '/data',

    // === mgt.superoptic.net lead gateway paylaşılan açarı ===
    // Bu İKİ dəyər mgt (85.132.57.222) tərəfindəki .env ilə EYNİ olmalıdır.
    // key_id: sərbəst identifikator (məs. superoptic-web-2026)
    // secret: ən azı 32 simvol təsadüfi sətir. Yaratmaq üçün:
    //   php -r "echo bin2hex(random_bytes(32));"
    'PUBLIC_SITE_INGRESS_KEY_ID' => '',
    'PUBLIC_SITE_INGRESS_SECRET' => '',

    // reCAPTCHA açıqdırsa (indi lazım deyil):
    // 'RECAPTCHA_SECRET' => '',
];
