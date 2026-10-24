# Huawei MA5608T ve MA5800-X7 vendor katalog plani

## Meqsed ve status

Bu sened internetdeki resmi Huawei materiallarindan tesdiqlenen CLI/SNMP faktlarini vendor kataloguna cevirmek ucun source-of-truth plandir. Bu, production OLT inteqrasiyasini aktiv etmir.

Cari status:

- `tools/huawei-readonly-adapter.py` mock-only ve transport-suzdur;
- `HUAWEI_READONLY_DISCOVERY_ENABLED` default baglidir;
- her iki profilde `hardware_validated=false` qalir;
- Huawei private OID allowlist-i bos qalir;
- write/disruptive emr yoxdur.

## Resmi menbeler

| Menbe | Tesdiqlenen sahe | Istifade statusu |
|---|---|---|
| https://support.huawei.com/enterprise/en/optical-access/smartax-ma5608t-pid-9121152 | MA5608T support ve `V800R019C10` command reference ailesi | Release kataloqu; portal girisi teleb ede biler |
| https://support.huawei.com/enterprise/en/access-network/smartax-ma5800-pid-21484577 | MA5800 support ve `V100R024C10` command/product reference ailesi | Release kataloqu; portal girisi teleb ede biler |
| https://info.support.huawei.com/network/ptmngsys/Web/tsrev_access/en/content/access/15_odn_fault_location/coper_6015.html | `display ont optical-info`, `display port state`; CLI optik olcmede 2 dB-e qeder ferq ola biler | Emr adi tesdiqlenib, exact release syntax hele tesdiqlenmeyib |
| https://info.support.huawei.com/network/ptmngsys/Web/tsrev_access/en/content/access/19_gpononu_failure_to_auto_discover/trouble_510602.html | `display port info`, `display port state`, `display board`; port/board diaqnostikasi | Emr adi tesdiqlenib, interface context telebi ayrica saxlanir |
| https://info.support.huawei.com/network/ptmngsys/Web/tsrev_access/en/content/access/16_gpononu_failure_to_go_online/trouble_515102.html | `display alarm active alarmparameter frameid/slotid/portid`, `display ont alarm-state`, `display ont info` ve Last down cause | Namized read-only katalog |
| https://info.support.huawei.com/enterprise/zh/doc/EDOC1100108357 | `display ont autofind`, `display port info <port>`, GPON interface context numunesi | Namized read-only katalog; release/board tesdiqi teleb olunur |
| https://info.support.huawei.com/network/ptmngsys/Web/tsrev_ce/en/content/ce/05_edesk_dc_snmp_cannot_get_infromation_from_NMS/edesk_dc_snmp_cannot_get_infromation_from_NMS_edesk002.html | SNMP MIB view, ACL ve `display snmp-agent mib-view` yoxlamasi | SNMP preflight dizayni ucun |

## CLI katalog sinifleri

### Aktiv mock allowlist

Bu emrler cari mock adapterde qalir:

- `display version`
- `display board 0`
- `display alarm active`

### Resmi menbede tesdiqlenen, amma hele aktiv olmayan read-only namizedler

- `display board`
- `display port info`
- `display port state`
- `display ont autofind`
- `display ont info`
- `display ont optical-info`
- `display ont alarm-state`
- `display alarm active alarmparameter frameid/slotid/portid`
- `display snmp-agent mib-view`

Bu siyahi emr adlarini tesdiq edir. Frame/slot/port/ONT parametr formasi, prompt/view, privilege, paging ve output parseri MA5608T `V800R019C10` ve MA5800-X7 `V100R024C10` uzre ayrica tesdiqlenmeden executable allowlist-e kecmir.

### Kataloqda qadağan kimi saxlanacaq emrler

Resmi troubleshooting materialinda olsa da bunlar read-only vendor adapterine daxil edilmir:

- `port ont-auto-find ... enable`
- `port range ...`
- `port laser-switch ... on`
- `board reset ...`
- `ont confirm ...`
- `ont reset` / `ont reboot`
- `save`, `undo`, `load`, `rollback`
- `display current-configuration`

Sonuncu emr oxu emri olsa da credential ve sexsi melumat sizdira bildiyi ucun avtomatik collect edilmir.

## SNMP katalog qaydasi

Cari adapterde yalniz standart read-only obyektler aktivdir:

- MIB-II system: identity, object ID, uptime, name;
- ENTITY-MIB: physical inventory, serial, model ve firmware;
- IF-MIB: interface identity, admin/oper status ve alias.

Huawei enterprise root-u `1.3.6.1.4.1.2011`-dir, amma bu root-u butovlukde WALK etmek qadağandir. OLT-e aid private MIB column OID-leri yalniz asagidaki sertlerle elave oluna biler:

1. Exact release-e aid resmi MIB package elde edilir.
2. Paket fayl adlari ve SHA-256 checksum-lari katalogda saxlanir.
3. Symbolic object adi, numeric OID, access (`read-only`/`notify`), index modeli ve unit resmi MIB-den cixarilir.
4. MA5608T ve MA5800-X7 ucun eyni semantika ferz edilmir.
5. `SET` hec vaxt discovery adapterine daxil edilmir.
6. Bulk enterprise walk yerine exact column root allowlist istifade edilir.
7. Real cihaz `noSuchObject`, timeout ve ACL neticeleri sanitizasiya olunmus fixture-e cevrilir.

Private MIB package olmadan internet forumlarindaki OID-ler production kataloquna yazilmayacaq.

## Hedef vendor strukturu

Stage 0 qebulundan sonra Claude Code asagidaki strukturu yaratmalidir:

```text
vendors/huawei/
  catalog.schema.json
  ma5608t-v800r019c10.json
  ma5800-x7-v100r024c10.json
  parsers/
  fixtures/
  README.md
```

Her catalog entry bu saheleri saxlamalidir:

```json
{
  "key": "ont_optical_info",
  "kind": "cli",
  "risk": "read_only",
  "status": "source_confirmed_syntax_unverified",
  "command_family": "display ont optical-info",
  "context": "gpon_interface_or_global_release_specific",
  "source_url": "https://info.support.huawei.com/...",
  "release": "V800R019C10",
  "hardware_validated": false
}
```

Namized entry ile executable entry ferqli olmalidir. Yalniz `status=hardware_validated`, exact parametr validatoru ve parser fixture-i olan entry execution plan-a kece biler.

## Inteqrasiya serhedi

- Vendor katalogu `mgs_devices.vendor=HUAWEI` ve `adapter_key=huawei_readonly` ile secilmelidir.
- Her servis cagirisi `noc-authorization-lib.php` uzre rol ve object-scope yoxlamasindan kecmelidir.
- Credential yalniz secret reference kimi saxlanmalidir; catalog, DB notes, audit ve raw output-da olmamalidir.
- CLI/SNMP neticesi correlation ID ile audit jurnalina yazilmalidir.
- Timeout, rate limit, host-key validation, SNMPv3 authPriv ve management VLAN sertleri transport qatinda mecburidir.
- UI Stage 1-den evvel deyisdirilmir.

## Qebul testleri

- JSON schema ve source URL validation;
- command injection, newline, `;`, `|`, `&` bloklamasi;
- exact release/product gate;
- namized emrin executable plana dusmemesi;
- Huawei enterprise root bulk walk-in reddi;
- `SET` ve write/disruptive CLI emrlerinin reddi;
- parser fixture: version, board, port, ONT state, last-down, optical ve alarm;
- secret leakage scan;
- NOC rol/object-scope permission matrix;
- audit correlation test;
- real cihaz olmadiginda live testin `skipped`, hec vaxt `passed` sayilmamasi.

## Real avadanliqdan sonra

Real MA5608T ve MA5800-X7 qosulanda exact patch, PON board, SNMP MIB checksum, prompt, paging, privilege, output ve optical unit ayrica tesdiqlenir. Bundan evvel `hardware_validated` deyeri `false` qalmalidir.
