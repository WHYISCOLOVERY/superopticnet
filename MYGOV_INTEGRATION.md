# MyGov ID inteqrasiya hazirligi

Bu modul real MyGov API məlumatları gələnə qədər passiv saxlanır. Məqsəd gələcəkdə yalnız OAuth/OpenID endpointlərini və client məlumatlarını qoşmaqla müştəri və əməkdaş girişini vahid SSO axınına keçirməkdir.

## Hazır olan hissələr

- Login start endpoint: `mygov-auth.php?role=customer` və `mygov-auth.php?role=employee`
- Callback endpoint: `mygov-auth.php?callback=1`
- State/nonce saxlanması: `sso_auth_states`
- MyGov identity mapping: `portal_account_identities`
- Admin readiness/mapping səhifəsi: `identity-integrations.php`
- Login səhifəsində MyGov ID düyməsi: `portal-login.php`

## Aktivləşdirmə üçün config

`config.local.php` daxilində:

```php
'mygov_enabled' => '1',
'mygov_client_id' => '...',
'mygov_client_secret' => '...',
'mygov_authorize_url' => 'https://...',
'mygov_token_url' => 'https://...',
'mygov_userinfo_url' => 'https://...',
'mygov_redirect_uri' => 'https://management.superoptic.net/mygov-auth.php?callback=1',
'mygov_scope' => 'openid profile fin',
```

## Real API qoşulanda tamamlanacaq adapter

`mygov-auth.php` callback hissəsində:

1. `code` token endpointinə göndərilir.
2. ID token signature, issuer, audience, expiry və nonce yoxlanır.
3. Userinfo endpointindən subject/FIN/ad/soyad məlumatı alınır.
4. `portal_account_identities` mapping-i ilə uyğun portal hesabı tapılır.
5. Hesab aktivdirsə `app_portal_sign_in()` ilə sessiya açılır.

## Təhlükəsizlik qaydası

`mygov_enabled=0` və ya endpoint/client secret boşdursa real giriş açılmır. Bu hal bilərəkdən belə saxlanılıb ki, yarımçıq identity inteqrasiyası ilə saxta login mümkün olmasın.
