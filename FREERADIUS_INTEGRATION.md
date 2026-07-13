# SuperOptic FreeRADIUS inteqrasiyasi

Bu portal MySQL-de FreeRADIUS-un standart SQL cedvellerini yaradir:

- `radcheck`
- `radreply`
- `radusergroup`
- `radgroupreply`
- `radacct`

Test PPPoE musterilerini Radius cedvellerine sync etmek:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tmp\sync-radius-test-users.php
```

DB-de konkret Radius cavabini yoxlamaq:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tmp\check-radius-db.php 1100000
```

Ubuntu FreeRADIUS ucun `sql`, `clients.conf` ve MySQL grant snippet-lerini yaratmaq:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tmp\render-freeradius-config.php
```

Ubuntu VM-de FreeRADIUS + MySQL-i tam qurmaq, SQL modulu aktiv etmek ve test user yaratmaq:

```bash
sudo bash deploy/setup-ubuntu-freeradius.sh
```

VM-de fayl yoxdursa Windows-da deploy qovlugundan lokal HTTP server ac ve Ubuntu-da fayli cek:

```bash
HOST=$(ip route | awk '/default/ {print $3; exit}')
wget -O setup-ubuntu-freeradius-lite.sh "http://${HOST}:8100/setup-ubuntu-freeradius-lite.sh" \
  || wget -O setup-ubuntu-freeradius-lite.sh "http://192.168.100.11:8100/setup-ubuntu-freeradius-lite.sh" \
  || wget -O setup-ubuntu-freeradius-lite.sh "http://10.251.137.92:8100/setup-ubuntu-freeradius-lite.sh"
chmod +x setup-ubuntu-freeradius-lite.sh
sudo bash setup-ubuntu-freeradius-lite.sh
```

Portal DB yerine lokal Ubuntu MySQL istifade edir. Production/portal DB-ye qosmaq ucun:

```bash
sudo INSTALL_MYSQL_SERVER=0 DB_HOST=192.168.80.1 DB_PASS='REAL_RADIUS_DB_PASS' bash deploy/setup-ubuntu-freeradius.sh
```

## Windows D: Docker FreeRADIUS

Ubuntu VM disk/dpkg problemi verirse Windows kompüterdə `D:\freeradius` qurulumu istifade olunur.

```powershell
PowerShell -ExecutionPolicy Bypass -File D:\freeradius\scripts\init-xampp-radius-db.ps1
PowerShell -ExecutionPolicy Bypass -File D:\freeradius\scripts\start-radius.ps1
PowerShell -ExecutionPolicy Bypass -File D:\freeradius\scripts\test-radius.ps1
```

Bu qurulum:

- Docker image: `superoptic/freeradius:3.2-mysql`
- Container: `superoptic-freeradius`
- DB host: `host.docker.internal`
- DB: `superoptic_portal`
- Auth port: `1812/udp`
- Accounting port: `1813/udp`
- Secret: `menim_gizli_acarim123`

Loglara baxmaq:

```powershell
PowerShell -ExecutionPolicy Bypass -File D:\freeradius\scripts\logs-radius.ps1
```

Core router `85.132.57.218` uygunluq testi:

```powershell
PowerShell -ExecutionPolicy Bypass -File D:\freeradius\scripts\test-core-style-radius.ps1
```

Bu test routere toxunmur. Container icinden `NAS-IP-Address = 85.132.57.218` ile auth ve accounting paketleri yaradib FreeRADIUS-un Cisco ASR1006 formatinda cavab verdiyini yoxlayir.

Gozlenen:

- Auth: `Access-Accept`
- Accounting Start/Stop: `Accounting-Response`
- `radacct.nasipaddress = 85.132.57.218`

Core router client ayari FreeRADIUS terefinde:

```conf
client sptech-asr1006-core {
    ipaddr = 85.132.57.218
    secret = menim_gizli_acarim123
    nas_type = cisco
    require_message_authenticator = auto
}
```

`require_message_authenticator = auto` real Cisco PPPoE uygunlugu ucun saxlanilir. `true` yazilsa, movcud ASR1006 request-lerinde Message-Authenticator yoxdursa auth kesile biler.

Hazir test:

```bash
radtest 1100000 UCuvsbLA2b 127.0.0.1 0 menim_gizli_acarim123
```

IPoE test user yaratmaq:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tmp\seed-ipoe-test-user.php 1200000 34:4A:C3:12:00:00 10.110.0.10
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tmp\check-radius-db.php 1200000
```

IPoE ucun parol yoxdursa portal `radcheck`-e `Auth-Type := Accept`, `radreply`-e `Framed-IP-Address` ve `Framed-IP-Netmask` yazir. PPPoE ucun `Cleartext-Password`, `Framed-Protocol = PPP` ve IP cavabi saxlanir.

## Ubuntu FreeRADIUS SQL modulu

```bash
sudo apt update
sudo apt install freeradius freeradius-mysql mysql-client
```

`/etc/freeradius/3.0/mods-available/sql` faylinda MySQL ayarlari:

```conf
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"

    server = "85.132.57.219"
    port = 3306
    login = "radius"
    password = "BURADA_RADIUS_DB_SIFRESI"
    radius_db = "superoptic_portal"

    read_clients = no
    client_table = "nas"
}
```

SQL modulu aktiv etmek:

```bash
sudo ln -s /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
sudo systemctl restart freeradius
```

`/etc/freeradius/3.0/sites-enabled/default` daxilinde `authorize`, `accounting`, `session`, `post-auth` bloklarinda `sql` aktiv olmalidir.

Debug-da bu setir gorunurse SQL modul hele aktiv deyil:

```text
Ignoring "sql" (see raddb/mods-available/README.rst)
```

Bu halda `mods-enabled/sql` linkini ve `sites-enabled/default` icindeki `sql` setirlerini yoxla.

## Ubuntu VM-de cari diaqnoz

Ubuntu-da bu hal normaldir:

```text
freeradius.service active (running)
0.0.0.0:1812
0.0.0.0:1813
127.0.0.1:18120
```

Bu o demekdir ki, servis ishleyir ve auth/accounting portlari aciqdir.

`sudo freeradius -X` eyni vaxtda servis ishleyerken acilsa bu xeta normaldir:

```text
Failed binding to auth address 127.0.0.1 port 18120 bound to server inner-tunnel: Address already in use
```

Debug testini duzgun etmek ucun:

```bash
sudo systemctl stop freeradius
sudo freeradius -X
```

Sonra ikinci terminalda:

```bash
radtest testuser testparol 127.0.0.1 0 menim_gizli_acarim123
```

Debug bitenden sonra:

```bash
sudo systemctl start freeradius
```

Eger `freeradius -X` cixishinda bu setir qalirsa, portal DB-si hele oxunmur:

```text
Ignoring "sql" (see raddb/mods-available/README.rst)
```

Bu halda `freeradius-mysql` qurulub `mods-enabled/sql` linki yaradilmali ve `sites-enabled/default` icinde `sql` setirleri aktiv edilmelidir.

## MySQL remote access: Ubuntu VM -> portal DB

VM `192.168.80.136/24` NAT sebekesindedirse, Windows/XAMPP MySQL hostu adeten VMnet gateway IP-sidir. Ubuntu-da yoxla:

```bash
ip route
```

Gateway meselen `192.168.80.1` gorunurse, FreeRADIUS SQL config-de `server` bu IP ola biler:

```conf
server = "192.168.80.1"
port = 3306
login = "radius"
password = "BURADA_RADIUS_DB_SIFRESI"
radius_db = "superoptic_portal"
```

Ubuntu-dan DB test:

```bash
mysql -h 192.168.80.1 -u radius -p superoptic_portal -e "select username,attribute,op,value from radcheck where username='1100000';"
```

Production DB birbasha istifade olunacaqsa:

```bash
mysql -h 85.132.57.219 -u radius -p superoptic_portal -e "select 1;"
```

Windows/XAMPP MySQL-de Radius user ucun minimal grant:

```sql
CREATE USER 'radius'@'192.168.80.%' IDENTIFIED BY 'BURADA_RADIUS_DB_SIFRESI';
GRANT SELECT, INSERT, UPDATE, DELETE ON superoptic_portal.radcheck TO 'radius'@'192.168.80.%';
GRANT SELECT, INSERT, UPDATE, DELETE ON superoptic_portal.radreply TO 'radius'@'192.168.80.%';
GRANT SELECT, INSERT, UPDATE, DELETE ON superoptic_portal.radusergroup TO 'radius'@'192.168.80.%';
GRANT SELECT, INSERT, UPDATE, DELETE ON superoptic_portal.radgroupreply TO 'radius'@'192.168.80.%';
GRANT SELECT, INSERT, UPDATE, DELETE ON superoptic_portal.radgroupcheck TO 'radius'@'192.168.80.%';
GRANT SELECT, INSERT, UPDATE, DELETE ON superoptic_portal.radacct TO 'radius'@'192.168.80.%';
GRANT SELECT, INSERT, UPDATE, DELETE ON superoptic_portal.radpostauth TO 'radius'@'192.168.80.%';
GRANT SELECT, INSERT, UPDATE, DELETE ON superoptic_portal.nas TO 'radius'@'192.168.80.%';
FLUSH PRIVILEGES;
```

XAMPP MySQL remote qebul etmese:

- MySQL config-de `bind-address=0.0.0.0` ve ya VMnet IP yazilmalidir.
- Windows Firewall-da TCP 3306 yalniz `192.168.80.0/24` ucun acilmalidir.
- Production-da `0.0.0.0/0` grant istifade olunmamalidir.

## SQL ile acceptance test

Portal terefinde test musterisi hazirdir:

```bash
radtest 1100000 UCuvsbLA2b 127.0.0.1 0 menim_gizli_acarim123
```

Gozlenen netice:

```text
Access-Accept
Framed-IP-Address = 10.11.20.10
Service-Type = Framed-User
Framed-Protocol = PPP
```

Bu netice gelirse FreeRADIUS artiq portaldaki `radcheck`, `radreply`, `radusergroup` cedvellerinden oxuyur.

## NAS client

`/etc/freeradius/3.0/clients.conf`:

```conf
client sptech-core {
    ipaddr = CORE_ROUTER_IP
    secret = menim_gizli_acarim123
    nas_type = cisco
}

client localhost {
    ipaddr = 127.0.0.1
    secret = menim_gizli_acarim123
}
```

## No reply from server debug

Sende gorunen:

```text
No reply from server for ID ...
```

Bu adeten auth reject deyil, FreeRADIUS-un cavab vermemesi demekdir. Ilk yoxlama:

```bash
sudo systemctl status freeradius --no-pager
sudo ss -lunp | grep -E '1812|1813'
```

### `radtest 127.0.0.1` no reply verirse

Bu auth/parol problemi deyil. `Access-Reject` gelmirse FreeRADIUS cavab paketi qaytarmir.

En cox rast gelinen sebebler:

1. FreeRADIUS servisi dayandirilib, amma debug rejimi acilmayib.
2. `freeradius -X` servis ishleyerken acilib ve port bind xetasi ile dayanib.
3. `clients.conf` icinde `client localhost` secret-i `radtest`-de verilen secret ile eyni deyil.
4. SQL modul config xetasi sebebinden daemon start olmur.

Vacib detal: `client her_kes { ipaddr = 0.0.0.0/0 ... }` olsa bele, `127.0.0.1` ucun daha konkret `client localhost` secilir. Ona gore `localhost` secret mutleq test secret-i ile eyni olmalidir.

`/etc/freeradius/3.0/clients.conf`:

```conf
client localhost {
    ipaddr = 127.0.0.1
    secret = menim_gizli_acarim123
    nas_type = other
}
```

Sonra:

```bash
sudo freeradius -C
sudo systemctl restart freeradius
radtest 1100000 UCuvsbLA2b 127.0.0.1 0 menim_gizli_acarim123
```

Eger hele de `No reply` qalirsa, debug terminalda emal prosesini gormek lazimdir:

```bash
sudo systemctl stop freeradius
sudo freeradius -X
```

Debug penceresinde `Ready to process requests` gorunmelidir. Sonra ikinci terminalda:

```bash
radtest 1100000 UCuvsbLA2b 127.0.0.1 0 menim_gizli_acarim123
```

Debug penceresinde request gorunmurse:

- daemon 1812-de dinlemir;
- yanlish server/port test edilir;
- `freeradius -X` start olmayib.

Debug penceresinde `Ignoring request from unknown client 127.0.0.1` gorunurse:

- `clients.conf` icinde `client localhost` yoxdur ve ya oxunmur.

Debug penceresinde `Invalid Message-Authenticator` veya shared secret xeberdarligi gorunurse:

- `radtest` secret-i ile `client localhost` secret-i eyni deyil.

Debug penceresinde SQL xetasi gorunurse:

- evvel Ubuntu-dan MySQL testini et:

```bash
mysql -h 192.168.80.1 -u radius -p superoptic_portal -e "select username,attribute,op,value from radcheck where username='1100000';"
```

Bu netice gelmelidir:

```text
1100000 | Cleartext-Password | := | UCuvsbLA2b
```

`freeradius -X` servis ishleyerken verilse bele xeta normaldir:

```text
Failed binding to auth address 127.0.0.1 port 18120: Address already in use
```

Debug-u duzgun acmaq ucun evvel servisi dayandir:

```bash
sudo systemctl stop freeradius
sudo freeradius -X
```

Sonra bashqa terminalda test et:

```bash
radtest testuser testparol 127.0.0.1 0 menim_gizli_acarim123
```

Debug bitdikden sonra:

```bash
sudo systemctl start freeradius
```

`freeradius -X` cavabinda config xetasi varsa, onu duzeltmeden `radtest` cavab vermeyecek.

Local `users` fayli ile test edirsense setir bele olmalidir:

```conf
testuser Cleartext-Password := "testparol"
```

Sonra:

```bash
sudo systemctl restart freeradius
radtest testuser testparol 127.0.0.1 0 menim_gizli_acarim123
```

SQL modulu qosulandan sonra `testuser` yerine portaldaki `1100000` kimi musteriler yoxlanmalidir.
