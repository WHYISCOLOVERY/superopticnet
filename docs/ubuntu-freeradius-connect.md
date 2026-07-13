# Ubuntu FreeRADIUS-u SuperOptic MGS-ə Qoşmaq

Bu sənəd mövcud Ubuntu FreeRADIUS serverini yeni SuperOptic MGS/billing MySQL bazasına qoşmaq üçündür. Windows installer FreeRADIUS qurmur; RADIUS ayrıca Ubuntu üzərində qalır.

## Nə edir

- Ubuntu-da mövcud FreeRADIUS 3.x konfiqurasiyasını backup edir.
- `freeradius-mysql`, `freeradius-utils`, `mysql-client` yoxlayır/quraşdırır.
- `mods-available/sql` faylını SuperOptic MySQL DB-yə yönləndirir.
- `sites-enabled/default` və `inner-tunnel` içində `sql` modulunu aktiv edir.
- `clients.conf`-a core router üçün client əlavə edir.
- Routerə toxunmur və router config dəyişmir.
- İstəyə görə test PPPoE/IPoE userləri yaradır.

## Bir komanda ilə quraşdırma

Ubuntu serverdə scripti yerləşdir və bu formada işə sal:

```bash
sudo DB_HOST='85.132.57.219' \
  DB_PORT='3306' \
  DB_NAME='superoptic_portal' \
  DB_USER='radius' \
  DB_PASS='REAL_RADIUS_SQL_PASSWORD' \
  RADIUS_SECRET='REAL_RADIUS_SHARED_SECRET' \
  CORE_NAS_IP='85.132.57.218' \
  CREATE_TEST_USERS=0 \
  bash ubuntu-connect-existing-freeradius.sh
```

Əgər scripti Windows serverdən çəkəcəksənsə:

```bash
wget -O ubuntu-connect-existing-freeradius.sh http://85.132.57.219/superopticnet/deploy/ubuntu-connect-existing-freeradius.sh
chmod +x ubuntu-connect-existing-freeradius.sh
sudo DB_HOST='85.132.57.219' DB_NAME='superoptic_portal' DB_USER='radius' DB_PASS='REAL_RADIUS_SQL_PASSWORD' RADIUS_SECRET='REAL_RADIUS_SHARED_SECRET' CORE_NAS_IP='85.132.57.218' bash ubuntu-connect-existing-freeradius.sh
```

## Test user ilə yoxlama

Production bazaya test user yazmaq istəmirsənsə `CREATE_TEST_USERS=0` saxla. Labda test etmək üçün:

```bash
sudo DB_HOST='85.132.57.219' DB_NAME='superoptic_portal' DB_USER='radius' DB_PASS='REAL_RADIUS_SQL_PASSWORD' RADIUS_SECRET='REAL_RADIUS_SHARED_SECRET' CORE_NAS_IP='85.132.57.218' CREATE_TEST_USERS=1 bash ubuntu-connect-existing-freeradius.sh
```

Sonra:

```bash
radtest 1100000 UCuvsbLA2b 127.0.0.1 0 REAL_RADIUS_SHARED_SECRET
radtest 1200000 any-test-password 127.0.0.1 0 REAL_RADIUS_SHARED_SECRET
```

Gözlənən nəticə: `Access-Accept`.

## Debug

Servis işləyərkən `freeradius -X` port binding xətası verə bilər. Debug üçün:

```bash
sudo systemctl stop freeradius
sudo freeradius -X
```

İkinci terminalda test:

```bash
radtest 1100000 UCuvsbLA2b 127.0.0.1 0 REAL_RADIUS_SHARED_SECRET
```

Bitirdikdən sonra:

```bash
sudo systemctl start freeradius
```

## Router cutover zamanı

Script router config dəyişmir. Router hazırda hansı RADIUS secret və server IP ilə işləyəcəksə, Ubuntu FreeRADIUS-da da eyni dəyərlər olmalıdır.

Core client bloku script tərəfindən belə yaradılır:

```conf
client sptech-asr1006-core {
    ipaddr = 85.132.57.218
    secret = REAL_RADIUS_SHARED_SECRET
    nas_type = cisco
    require_message_authenticator = auto
}
```

`require_message_authenticator = auto` saxlanılır ki, mövcud Cisco ASR1006 request-lərində Message-Authenticator yoxdursa auth kəsilməsin.

## Minimum yoxlama checklist

- `sudo freeradius -C` konfiqurasiyanı uğurlu keçir.
- `sudo ss -lunp | grep -E '1812|1813'` portları göstərir.
- `radtest` Access-Accept qaytarır.
- SuperOptic billing `radcheck`, `radreply`, `radacct`, `radpostauth` cədvəllərini görür.
- CoA/disconnect üçün sonradan `radclient` test edilir.
