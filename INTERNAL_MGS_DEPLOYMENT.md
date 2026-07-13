# SuperOptic MGS daxili deployment modeli

Bu model sistemin internetə public web kimi açılmadığı, amma Telegram mesajları üçün serverin internetə çıxışı olduğu ssenari üçündür.

## Şəbəkə prinsipi

- MGS / portal server daxili şəbəkədə qalır.
- Serverdən internetə yalnız outbound çıxış lazımdır: `https://api.telegram.org`.
- OLT, router, switch idarəetməsi yalnız daxili management VLAN/LAN üzərindən edilir.
- Public internetdən `technician-close.php`, MGS paneli, DB, SSH/SNMP portları açılmır.

## Tövsiyə olunan giriş

Texnik və əməkdaşlar ticket bağlama linkini aça bilmək üçün bu yollardan birini istifadə etməlidir:

- Ofis / daxili Wi-Fi / management LAN.
- WireGuard və ya OpenVPN ilə daxili şəbəkəyə qoşulma.
- Split DNS: məsələn `mgs.superoptic.local` daxildə MGS server IP-sinə həll olunur.

Telegram linki public internet linki olmamalıdır. Link daxili adrese getməlidir:

```text
http://mgs.superoptic.local/technician-close.php?token=...
```

Lokal test üçün hazır config:

```php
'app_url' => 'http://127.0.0.1/superopticnet',
'ticket_close_base_url' => 'http://127.0.0.1/superopticnet',
'olt_sso_entrypoint' => 'http://127.0.0.1:3000/api/auth/sso/consume',
'olt_sso_audience' => 'superoptic-mgs',
```

Production daxili serverdə bunu belə dəyişmək məntiqlidir:

```php
'app_url' => 'http://mgs.superoptic.local',
'ticket_close_base_url' => 'http://mgs.superoptic.local',
'olt_sso_entrypoint' => 'http://mgs.superoptic.local:3000/api/auth/sso/consume',
'olt_sso_audience' => 'superoptic-mgs',
```

Əgər HTTPS daxildə qurulacaqsa `http` əvəzinə `https` istifadə edilməlidir.

## Telegram ilə ticket bağlama axını

1. Müştəri müraciəti və ya avtomatik alarm ticket yaradır.
2. Server Telegram Bot API-yə outbound request ilə mesaj göndərir.
3. Mesajda ticket məlumatı və daxili `Bagla` linki olur.
4. Texnik linkə yalnız LAN/VPN üzərindən daxil olur.
5. `technician-close.php` tokeni yoxlayır, səbəb/qeyd və istifadə olunan materialları qəbul edir.
6. Ticket bağlanır, anbar qalığı düşülür.
7. Server yenə outbound Telegram API ilə qrupdakı mesajı silir və ya resolved kimi yeniləyir.

## Server public deyilsə webhook məsələsi

Telegram webhook inbound internet tələb edir. Server public web açılmayacaqsa webhook istifadə edilməməlidir.

Bu halda iki təhlükəsiz yol var:

- Əsas yol: Telegram mesajları Bot API `sendMessage` ilə outbound gedir, bağlama əməliyyatı daxili link üzərindən olur.
- Fallback yol: bot worker `getUpdates` long polling ilə outbound işləyə bilər. Bu, sadə təsdiqlər üçün uyğundur, amma kabel metri, modem SN/MAC, fast connector kimi detallı bağlama üçün daxili web form daha düzgündür.

MGS Node panelində Telegram ticket linki ayrıca `TICKET_RESOLVE_BASE_URL` dəyişəni ilə qurulur:

```env
APP_BASE_URL=http://mgs.superoptic.local:3000
TICKET_RESOLVE_BASE_URL=http://mgs.superoptic.local:3000
TELEGRAM_WEBHOOK_SECRET=use-a-long-random-webhook-secret
```

`TELEGRAM_WEBHOOK_SECRET` dolu qalmalıdır. Public inbound açılmasa belə endpoint təsadüfən açılarsa, secret olmayan request qəbul edilməyəcək. Server public açılmayacaqsa webhook yerinə link əsaslı bağlama və ya outbound `getUpdates` istifadə olunur.

## Statik IP olarsa

Server internetə `94.20.46.18` statik IP ilə çıxırsa, Telegram linkini həmin IP-yə yönləndirmək üçün inbound `443` port forwarding lazımdır. Ən düzgün variant IP-yə domen bağlamaqdır:

```text
ticket.superoptic.net -> 94.20.46.18
```

MGS Node tərəfdə:

```env
APP_BASE_URL=http://mgs.superoptic.local:3000
TICKET_RESOLVE_BASE_URL=https://ticket.superoptic.net
```

Bu link yalnız ticket bağlama üçün istifadə olunmalıdır. Reverse proxy public tərəfdə `/resolve-ticket`, `/api/ticket-resolve`, `/styles.css`, `/app.js` yollarını buraxmalı, qalan panel və idarəetmə API-lərini bağlamalıdır.

## Kənardan admin/operator girişi

Super admin və gecə operatorları üçün full panel public internetə açılmamalıdır. WireGuard VPN kifayət edir:

- `vpn.superoptic.net -> 94.20.46.18`
- Firewall/router: yalnız `51820/udp` WireGuard serverə forward edilir.
- MGS, portal, DB, SSH/SNMP portları public internetdən bağlı qalır.
- VPN-ə qoşulandan sonra panel daxili adla açılır: `http://mgs.superoptic.local:3000`.

Telegram ticket linkləri üçün iki variant saxlanılır:

- VPN-only: `TICKET_RESOLVE_BASE_URL=http://mgs.superoptic.local:3000`.
- Ticket-only public: `TICKET_RESOLVE_BASE_URL=https://ticket.superoptic.net`, reverse proxy yalnız ticket bağlama route-larını açır.

## Firewall qaydası

Minimum icazə:

- Outbound TCP 443: Telegram API və lazım olan update/check servisleri.
- Inbound LAN/VPN: HTTP/HTTPS MGS/portal.
- Inbound public internet: bağlı.
- DB, SSH, SNMP: yalnız serverin özündən və management LAN-dan.

## Nəticə

Bu modeldə ticket linkləri Telegram-da görünür, amma sistem public açılmır. Texniklər ticketi ya daxili şəbəkədən, ya da VPN ilə bağlayır. Bu həm anbar azalmasını, həm audit logu, həm də təhlükəsiz idarəetməni saxlayır.
