# Huawei SmartAX MA5608T və MA5800-X7 read-only adapteri

## Status və sərhəd

Bu mərhələ yalnız aşağıdakı iki profil üçün transport-suz discovery scaffold yaradır:

- `SmartAX MA5608T` — `V800R019C10`;
- `SmartAX MA5800-X7` — `V100R024C10`.

Adapter production üçün bağlıdır. `HUAWEI_READONLY_DISCOVERY_ENABLED` feature flag-i default olaraq `false` sayılır və hər iki profildə `hardware_validated=false` hard gate-i var. Flag əl ilə açılsa belə real plan `real_hardware_not_validated` xətası ilə dayanır. Hazırda yalnız `mock_plan` və `mock_fixture` rejimləri işləyir; heç bir rejim cihaz şəbəkəsinə qoşulmur.

Bu slice yalnız aşağıdakı fayllardır:

- `tools/huawei-readonly-adapter.py` — allowlist, gate, plan və fixture parseri;
- `tools/huawei-readonly-adapter-smoke.py` — deterministik mock testləri;
- bu sənəd.

Application page, HSGQ adapteri, credential registry və canlı transport dəyişdirilməyib.

## Rəsmi istinad bazası

Scaffold 17 iyul 2026 tarixində aşağıdakı rəsmi Huawei istinadları əsasında daraldılıb:

1. [SmartAX MA5608T Support](https://support.huawei.com/enterprise/en/optical-access/smartax-ma5608t-pid-9121152) — `MA5600T&MA5603T&MA5608T V800R019C10 Commissioning Command Reference 01`.
2. [SmartAX MA5800 Support](https://support.huawei.com/enterprise/en/access-network/smartax-ma5800-pid-21484577) — `MA5800 V100R024C10 Command Reference 01` və `MA5800 V100R024C10 Product Documentation 01`.
3. [Huawei: Querying the Optical Power Using the CLI](https://info.support.huawei.com/network/ptmngsys/Web/tsrev_access/en/content/access/15_odn_fault_location/coper_6015.html) — `display ont optical-info` və `display port state` əmrlərinin oxu məqsədini, həmçinin CLI optik ölçməsinin məhdudiyyətini təsdiqləyir.
4. [Huawei: GPON ONU Failure to Go Online](https://info.support.huawei.com/network/ptmngsys/Web/tsrev_access/en/content/access/16_gpononu_failure_to_go_online/trouble_515101.html) — `display ont info` nəticəsindəki run state semantikasını təsdiqləyir.
5. [Huawei: OLT Fault](https://info.support.huawei.com/network/ptmngsys/Web/tsrev_access/en/content/access/19_gpononu_failure_to_auto_discover/trouble_510602.html) — `display board`, `display port info` və `display port state` əmrlərinin diaqnostik istifadəsini təsdiqləyir.

Huawei support portalında command reference mətninin tam görünməsi hesab icazəsi tələb edə bilər. Buna görə scaffold sənəddə adı keçən hər əmri avtomatik allowlist-ə əlavə etmir.

SNMP hissəsi yalnız standart MIB ağaclarından istifadə edir: MIB-II system (`RFC 1213`), IF-MIB (`RFC 2863`) və ENTITY-MIB (`RFC 4133`). Huawei enterprise ağacı `1.3.6.1.4.1.2011` allowlist-də yoxdur.

## Təhlükəsizlik modeli

Adapterdə host, port, username, password, SNMP community, auth/priv key və SSH/SNMP client kodu yoxdur. `transport` həmişə `none` qaytarır. Fixture-də credential-a bənzər field və ya `name=value` mətni aşkar edilərsə parser onu rədd edir; raw CLI transcript nəticəyə qaytarılmır.

CLI komandası yalnız tam normalizasiya olunmuş exact allowlist uyğunluğundan keçərsə qəbul edilir. Newline, `;`, `|` və `&` chaining simvolları ayrıca rədd olunur. `config`, `interface`, `ont`, `board reset`, `save`, `undo`, `display current-configuration` və allowlist-dən kənar bütün `display` variantları bloklanır.

Cari CLI allowlist:

| Məqsəd | Exact command | Qeyd |
|---|---|---|
| Versiya və uptime | `display version` | Exact product/release gate-i üçün |
| Board inventory | `display board 0` | Yalnız frame `0`; parser slot və frame/slot formasını qəbul edir |
| Aktiv alarm snapshot | `display alarm active` | Yalnız oxu; fixture parseri tanınmış alarm sətirlərini strukturlaşdırır |

SNMP yalnız aşağıdakı əməliyyatları qəbul edir:

- exact scalar OID üçün `get`;
- exact allowlisted table-column root üçün `walk`;
- `set` və başqa əməliyyatlar həmişə rədd olunur.

Allowlist system identity/uptime, ENTITY-MIB physical inventory və IF-MIB interface identity/status sütunları ilə məhduddur. Fixture table instance OID-ləri yalnız həmin sütun root-unun altında qəbul edilir.

## Versiya-aware fərqlər

| Mövzu | MA5608T `V800R019C10` | MA5800-X7 `V100R024C10` | Scaffold qərarı |
|---|---|---|---|
| Version token | `MA5600V800R019C10` | `MA5800V100R024C10` | Token exact match olmalıdır; başqa C release qəbul edilmir |
| Product token | `MA5608T` | `MA5800-X7` və ya banner-də `MA5800X7` | Family/release qarışığı rədd edilir |
| Board ünvanı | Kiçik chassis nəticəsi adətən slot şəklindədir | Nəticə frame/slot şəklində ola bilər | Parser həm `1`, həm `0/8` formasını saxlayır, slot mənası uydurmur |
| ONT query konteksti | Bu ailədə nümunələr çox vaxt GPON interface view daxilində `<port> <ont-id|all>` formasındadır | R024-də global F/S/P və interface-view variantlarını patch/board üzrə təsdiqləmək lazımdır | Heç biri allowlist-də deyil |
| Optik query | `display ont optical-info` adı rəsmi troubleshooting materialında var, lakin context və bulk `all` dəstəyi patch-dən asılı ola bilər | Eyni command adı var, çıxış sütunları PON texnologiyası və ONT capability-si ilə dəyişə bilər | Optik parser hardware fixture olmadan aktiv deyil |
| Port status | `display port state` çox vaxt GPON view kontekstinə bağlıdır | Syntax və çıxış GPON/XG-PON/XGS-PON board-a görə dəyişə bilər | Cari allowlist-ə daxil deyil |

`config` və `interface gpon ...` yalnız view dəyişmək üçün işlədilsə belə bu scaffold tərəfindən qəbul edilmir. Səbəb sadədir: read-only executor config view-a daxil olmadan işləməli və gələcək command substitution səhvlərinə yazma səthi açmamalıdır.

## Strukturlaşdırılmış nəticə

Fixture parser aşağıdakı vendor-neutral sahələri qaytarır:

```text
device_status
board_inventory
alarm_events
snmp_discovery.system
snmp_discovery.entities
snmp_discovery.interfaces
unsupported
```

`pon_ports`, `ont_identity`, `ont_state`, `optical_metrics` və Huawei private MIB nəticələri hazırda `pending_real_hardware_validation` və ya `matching_vendor_mib_package_not_validated` kimi işarələnir. Tanınmayan raw output saxlanmır.

## Mock fixture müqaviləsi

Fixture UTF-8 JSON object-dir və credential saxlamamalıdır:

```json
{
  "family": "MA5608T",
  "release": "V800R019C10",
  "cli": {
    "display version": "VERSION : MA5600V800R019C10\nPRODUCT : MA5608T\n",
    "display board 0": "0 H801MABO Active_normal\n",
    "display alarm active": ""
  },
  "snmp": {
    "1.3.6.1.2.1.1.1.0": "Huawei SmartAX MA5608T",
    "1.3.6.1.2.1.1.3.0": 12345,
    "1.3.6.1.2.1.1.5.0": "sanitized-lab-name"
  }
}
```

Parser profile metadata-sını, exact version/product banner-ini, bütün CLI command key-lərini və hər SNMP OID-ni parse etməzdən əvvəl yoxlayır.

## Deterministik testlər

Canlı device access tələb olunmur və test zamanı şəbəkə client-i yaradılmır:

```powershell
python -m py_compile tools/huawei-readonly-adapter.py tools/huawei-readonly-adapter-smoke.py
python tools/huawei-readonly-adapter-smoke.py
python tools/huawei-readonly-adapter.py catalog
python tools/huawei-readonly-adapter.py plan --family MA5608T --release V800R019C10 --mock
python tools/huawei-readonly-adapter.py plan --family MA5800-X7 --release V100R024C10 --mock
```

Production plan çağırışı default-da `feature_flag_disabled` qaytarır. Feature flag test üçün açılsa belə `hardware_validated=false` səbəbindən `real_hardware_not_validated` qaytarmalıdır.

## Dəstəklənməyən fərziyyələr

- SSH, Telnet və SNMP transport-un hazır olduğu fərz edilmir.
- Huawei private MIB OID-lərinin iki release arasında sabit olduğu fərz edilmir.
- ENTITY-MIB və IF-MIB implementasiyasının bütün board-ları və PON-ları tam göstərdiyi fərz edilmir.
- `ifIndex` ilə frame/slot/port arasında mapping fərz edilmir.
- GPON, XG-PON və XGS-PON output formatlarının eyni olduğu fərz edilmir.
- ONT serial, auth mode, last-down cause, optical RX/TX, temperature və alarm correlation hazırda dəstəklənmir.
- CLI optik güc dəyəri sahə ölçüsü hesab edilmir; Huawei özü CLI nəticəsi ilə real ölçü arasında fərq ola biləcəyini qeyd edir.
- Config backup oxu kimi görünməsinə baxmayaraq credential və şəxsi məlumat sızdıra bildiyi üçün `display current-configuration` qadağandır.
- Paging, prompt detection, session limit, reconnect və rate limiting transport olmadığı üçün implementasiya edilməyib.
- Heç bir write, preview, apply, rollback və application UI inteqrasiyası bu slice-a daxil deyil.

## Real hardware validation-dan sonra açılma şərti

Production gate yalnız ayrıca lab işi tamamlandıqdan sonra dəyişdirilə bilər:

1. Hər exact release və istifadə olunan patch üçün `display version` nəticəsi sanitizasiya edilir.
2. Read-only rol ilə üç allowlisted CLI command-ın privilege və output forması yoxlanır.
3. SNMP read-only ACL altında bütün standard OID-lər üçün GET/WALK nəticəsi və `noSuchObject` davranışı qeydə alınır.
4. MA5608T və MA5800-X7 board fixture-ləri real, sanitizasiya olunmuş output ilə əvəzlənir.
5. ONT/PON/optical command syntax-i hər PON board növü üçün command reference və lab nəticəsi ilə ayrıca təsdiqlənir; yalnız bundan sonra yeni exact command template allowlist-ə əlavə edilir.
6. Huawei MIB package release checksum-u və OID semantikası təsdiqlənmədən private OID əlavə edilmir.
7. Rate limit, timeout, paging, host-key validation, secret registry inteqrasiyası və audit ayrıca transport review-dan keçir.
8. Təhlükəsizlik review-u və operator sign-off-dan sonra profile-də `hardware_validated=true` edilir; feature flag bundan sonra mərhələli açıla bilər.

Bu şərtlər tamamlanmadan adapter production OLT-ə qoşulmamalı və write mode anlayışı əlavə edilməməlidir.
