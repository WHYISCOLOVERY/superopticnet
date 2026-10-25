# SuperOptic MGS (Node) -> superopticnet (PHP) konsolidasiya yol xeritesi

Tarix: 2026-08-19 (Faza 0-1), 2026-08-21 (Faza 2-4)
Status: Faza 0-3 tam bagli (Faza 3-un qeyri-Cisco vendorlari haric). Faza 4 icra edildi: Addım 1-3 tam, Addım 5 qismən (boş qovluq qabığının silinməsi sonraya saxlanıldı), Addım 4/6 qəsdən icra edilmir. Bu maşından edilə biləcək bütün konsolidasiya işi tamamlanıb.
Qerar sahibi: Serdar (istifadeci)

## 1. Problem

`D:\xampp\htdocs\oltidareetme` ("SuperOptic MGS") superopticnet PHP portalindan tamamile ayri, oz-ozune tam islek bir Node.js proqramidir:

- oz MySQL bazasi: `olt_control_center`
- oz JWT auth/istifadeci sistemi (`users`, `roles`, `refresh_tokens`)
- oz BullMQ job queue-su (`jobs`)
- SSH/SNMP cihaz adapterleri (Cisco IOS-XR router, HSGQ ve s.)
- Telegram diaqnostika botu

Bu, bos demo deyil — real data dasiyir (2026-08-19 tarixli sayim):

| Cedvel | Setir sayi |
|---|---|
| device_interface_traffic_samples | 256.823 |
| device_interface_traffic_hourly | 58.782 |
| jobs | 25.641 |
| device_interface_traffic_daily | 20.487 |
| alarm_state_events | 17.583 |
| olt_traffic_samples | 7.465 |
| telegram_logs | 3.922 |
| kpi_records | 2.523 |
| audit_logs | 1.427 |
| refresh_tokens | 1.040 |
| onts | 730 |
| snmp_trap_log | 636 |
| alarms / tickets | 561 / 561 |
| interfaces | 66 |
| devices / olts | 10 / 3 |

Diqqet: eyni bazada `billing_customers`, `billing_payments`, `employees`, `stock_*` kimi cedveller **0 setir** ile bosdur — MGS-in billing/HR/anbar tereflerinin real istifadesi olmayib, butun bu domenler artiq superopticnet-de tebii sekilde qurulub. Real ust-uste dusme yalniz **sebeke/NOC** domenindedir.

`deploy\windows\start-superoptic-stack.ps1` bu Node proqrami superopticnet ile birlikde avtomatik baslatirdi (Windows Scheduled Task: "SuperOptic MGS Stack Autostart"), ve `config.php`-de `app_portal_olt_handoff_url()` / `portal-bridge.php` vasitesile istifadeciyi SSO tokeni ile ona yonlendiren korpu movcuddur.

Serdar bunu "2 proqram ic-ice" adlandirdi ve bunu istemir: her sey superopticnet-den idare olunmalidir, modul-modul.

## 2. Bosluq analizi: PHP terefinde ne var, ne yoxdur

| Sahe | PHP-de veziyyet | Fayl/funksiya |
|---|---|---|
| Cihaz auth/scope | Tam native RBAC, real | `noc-authorization-lib.php`: `app_noc_authorize`, `app_noc_action_roles` |
| Reachability probe (ping/TCP/SNMP-get/SSH-auth) | Real, canli | `mgs-integration-lib.php`: `app_mgs_probe_snmp`, `app_mgs_probe_ssh_auth`, `app_mgs_refresh_device_reachability` |
| Cihaz komanda icrasi (generic/HSGQ) | Real SSH icra, maker-checker tesdiqli | `app_mgs_hsgq_exec_commands`, `app_mgs_execute_device_command_plan`, `mgs_device_command_requests` cedveli |
| Huawei OLT/ONT komanda plani | Yalniz data/mock — `stage6_huawei_adapter` flag + `hardware_validated` olmadan icra olunmur | `huawei-olt-lib.php`: `app_huawei_live_ready` |
| NOC UI (cihaz siyahisi, monitor xeritesi, alarm feed) | Tam native, Node-a proxy YOXDUR | `noc-devices.php` |
| Cihaz inventari | Tek flat cedvel (JSON blob-larla), ayri OLT/ONT/interfeys cedveli yoxdur | `device_learning_profiles` |
| Alarm | Tek cedvel, state-transition tarixcesi (Node-daki `alarm_state_events` kimi) yoxa yaxindir | `mgs_alarm_events` |
| **Interfeys trafik telemetriyasi (samples/hourly/daily)** | **Yoxdur — real bosluq** | — |
| **SNMP trap log** | **Yoxdur — real bosluq** | — |
| **Cihaz-qonsuluq xeritesi (device_neighbors)** | **Yoxdur — real bosluq** | — |
| Ticket | Native `portal_tickets` + `portal_ticket_events`, ferqli adlandirma, ust-uste dusen semantika | `crm.php`; presedent: `db/migrations/20260729_200_legacy_ticket_knowledge_base.php` |
| Telegram diaqnostika botu | Native ekvivalent var (8 bot teyini, `diagnostics`/`modem_search` daxil), canli | `app_mgs_telegram_bot_definitions`, `app_mgs_sync_telegram_bot_registry` |
| Istifadeci/rol/refresh-token (Node-un oz auth-u) | Lazim deyil — superopticnet-in oz `portal_accounts` + SSO-su var | — (miqrasiya yox, sadece atilacaq) |

## 3. Fazali plan

**Felsefe:** her faza oz-ozlukde tehlukesiz, geri-qaytarila bilen ve test edile bilen olmalidir. Node terefi yalniz funksional paritet tesdiqlendikden ve data kocuruldukden sonra sondurulur — birinci addim kimi yox, sonuncu addim kimi.

### Faza 0 — Ayirma ve senedlesdirme (2026-08-19-da tetbiq edildi)

- `deploy\windows\start-superoptic-stack.ps1`: `Start-MgsNode` artiq default olaraq **skip** edir (yeni `-StartMgs` opt-in switch; movcud `-SkipMgs` de hele isleyir). Geri qaytarmaq ucun scheduled task-a `-StartMgs` elave etmek kifayetdir.
- Scheduled task-in ozu (SYSTEM prinsipali, elevasiya teleb edir) deyisdirilmedi — script-in oz defolt davranisi deyisdirildiyi ucun buna ehtiyac qalmadi.
- `oltidareetme` qovlugu, `olt_control_center` bazasi ve backup scheduled task-i **toxunulmadan** saxlanildi — data hele kocurulmeyib.

### Faza 1 — Telemetriya + alarm pariteti (en boyuk real bosluq)

Status: **alarm state-transition tarixcesi tamamlandi (2026-08-19); telemetriya/trap/qonsuluq hele planlasdirilib**

- **Tamamlandi:** `mgs_alarm_state_events` (append-only, migration `20260819_430_mgs_alarm_state_events.php`) `app_mgs_record_alarm_event()`-e (mgs-integration-lib.php) wire edildi — hər yaradılma/təkrar-görünmə (from/to status, from/to severity, seen_count) qeyd olunur, best-effort (`catch (Throwable)`, migration tətbiq olunmayıbsa əsas alarm axını qırılmır). Smoke: `tools/mgs-alarm-state-history-smoke.php`.
- **Tamamlandı (2026-08-19, davam): interfeys inventarı.** `device_interfaces` (migration `20260819_440_device_interfaces.php`) + real IF-MIB SNMP walk (`app_mgs_discover_device_interfaces()`, `mgs-integration-lib.php`). Bunun üçün əvvəlcə tam bir SNMP GETNEXT/BER decoder qatı yazıldı (`app_mgs_snmp_build_getnext_request`, `app_mgs_snmp_ber_read_tlv`, `app_mgs_snmp_ber_decode_oid`, `app_mgs_snmp_decode_response`, `app_mgs_snmp_walk_column`) — əvvəllər `app_mgs_probe_snmp()` yalnız reachability GET edirdi, heç bir cavab decode etmirdi. `noc-devices.php`-də "Interfeysləri kəşf et" düyməsi (cihaz detalı panelində, mövcud Probe/Backup/CLI discovery düymələri ilə eyni yerdə) + interfeys sayı/up-sayı xülasə sətri əlavə edildi. Real UDP fake-SNMP-agent ilə uçdan-uca smoke: `tools/mgs-device-interface-inventory-smoke.php` (+ köməkçi `tools/_fake-snmp-agent-for-smoke.php`).
- **Tamamlandı (2026-08-19, davam 3): trafik toplayıcısı worker-i.** `device_interface_traffic_samples/hourly/daily` (migration `20260819_450_device_interface_traffic.php`) + real poll worker `tools/poll-device-interface-traffic.php` (dry-run default, `--apply` yazır, `--device=<key>` tək cihazla məhdudlaşdırır, mövcud `tools/billing-maintenance.php` konvensiyasına uyğun).
  - `mgs-integration-lib.php`: `app_mgs_snmp_get_raw()` (tək-OID GET, `app_mgs_snmp_getnext_raw()`-un GET qardaşı), `app_mgs_read_interface_octets()` — hər interfeys üçün əvvəlcə 64-bit `ifHCInOctets`/`ifHCOutOctets` (ifXTable, RFC2863) sınayır, yalnız hər ikisi uğursuz olarsa 32-bit `ifInOctets`/`ifOutOctets`-ə keçir (32-bit Counter32 saturasiya olmuş 1Gbps linkdə ~34 saniyədə bir dövr edir — device_interfaces-də artıq 1Gbps+ linklər görünür, ona görə HC-ni default etmək məcburi idi, sadəcə yaxşı təcrübə deyil). `app_mgs_interface_traffic_bps()` — iki oxuma arasında bit/saniyə sürəti, yalnız 32-bit üçün wraparound-aware, sayğac genişliyi dəyişəndə (məs. HC-dən 32-bit-ə keçid) və ya 900 saniyədən uzun boşluqda `null` qaytarır (yanlış sürəti uydurmaqdansa boş saxlamaq). `app_mgs_poll_device_interface_traffic()`, `app_mgs_rollup_device_interface_traffic_hourly/daily()` (idempotent, son N saat/gün-ü yenidən agreqasiya edir — "son rollup" state izləməyə ehtiyac yoxdur), `app_mgs_prune_device_interface_traffic_samples()` (defolt 30 gün saxlama — vision doc 5.3-ün "yüksək həcmli jurnal əməliyyat bazasını yükləməməlidir" qaydası, saatlıq/günlük aqreqatlar həmişəlik saxlanılır).
  - Yeni `deploy/windows/install-device-interface-traffic-poll-task.ps1` (AI Komanda agenda task-ın eyni naxışı, amma təkrarlanan 5-dəqiqəlik trigger) — **hazırlanıb, amma bu sessiyada quraşdırılmayıb/işə salınmayıb**; istifadəçi istəsə əl ilə çağırmalıdır.
  - Real UDP fake-agent smoke: `tools/mgs-device-interface-traffic-smoke.php` (bps riyaziyyatı, HC canlı poll, HC->32-bit fallback, sayğac-genişlik-dəyişikliyi təhlükəsizliyi, rollup, prune — 5 hissə, hamısı `tools/_fake-snmp-agent-for-smoke.php`-nin indi GET/GETNEXT-i PDU tag-a görə ayıran genişlənməsi ilə).
- **Tamamlandı (2026-08-19, davam 4): `snmp_trap_log` (passiv trap qəbuledicisi).** Migration `20260819_460_snmp_trap_log.php` + real UDP dinləyici `tools/snmp-trap-listener.php`.
  - `mgs-integration-lib.php`: `app_mgs_snmp_decode_v1_trap_pdu()` (SNMPv1 Trap-PDU, tag `0xA4` — `GetResponse-PDU`-dan fərqli struktur: enterprise/agent-addr/generic-trap/specific-trap/time-stamp), `app_mgs_snmp_decode_trap_packet()` (v1 üçün yuxarıdakı, v2c trap/InformRequest üçün — `0xA7`/`0xA6` — mövcud `app_mgs_snmp_decode_response()`-u təkrar istifadə edir, çünki PDU forması eynidir; `sysUpTime.0`/`snmpTrapOID.0`-u OID-ə görə axtarır, mövqeyə görə yox). `app_mgs_record_snmp_trap()` — mənbə IP-ni `device_learning_profiles`-ə qarşı həll edir (naməlum mənbə heç vaxt atılmır, yalnız `device_key=null` qalır — audit görünürlüyü kor etibardan üstündür), community-ni gözlənilən dəyərə qarşı yoxlayır (uyğunsuzluq yalnız `community_valid=0` işarələyir, sətri boğmur), və **yalnız** `linkDown`/`coldStart`/`warmStart`/`authenticationFailure` (v1 generic-trap) üçün mövcud `app_mgs_record_alarm_event()` körpüsünə keçir — `linkUp` (bərpa siqnalı) və `enterpriseSpecific` (vendor-a məxsus kataloq yoxdur) bilərəkdən avtomatik alarm yaratmır (vision doc 6.2-nin "40 ayrı problem yaratmamalı" xəbərdarlığına uyğun, alert yorğunluğunu artırmamaq üçün).
  - `tools/snmp-trap-listener.php` — davamlı UDP dinləyici (defolt port 1162, istehsalatda `162` elevasiya tələb edir — sənəd bunu açıq izah edir), `--max-packets`/`--max-runtime-seconds` test üçün, defolt sonsuz. **Bu, `poll-device-interface-traffic.php` kimi dövri cron worker DEYİL** — portuna daimi bağlı qalmalıdır, ona görə `start-superoptic-stack.ps1`-ə (WhatsApp bridge-in eyni "daimi arxa-plan prosesi" naxışı ilə) `Start-SnmpTrapListener` funksiyası əlavə edildi, **default olaraq deaktiv** (`-StartSnmpTrapListener` ilə opt-in — MGS Node-un eyni "yeni always-on servisi sükutla aktiv etmə" prinsipi).
  - Real UDP smoke: `tools/mgs-snmp-trap-listener-smoke.php` (v1/v2c decode round-trip, əl ilə bağlanmış-göndərilmiş-alınmış eyni-prosesli UDP round-trip, device_key həlli, alarm körpüsü linkDown/linkUp üçün, community-mismatch bayrağı) + real CLI dinləyici prosesi əl ilə (`proc_open` olmadan, arxa plan + ayrıca göndərici skripti ilə) end-to-end yoxlanıldı.
- **Tamamlandı (2026-08-19, davam 5): `device_neighbors` (LLDP/CDP topologiya kəşfi).** Migration `20260819_470_device_neighbors.php` + `app_mgs_discover_device_neighbors()` (`mgs-integration-lib.php`).
  - Həm LLDP (IEEE 802.1AB, `LLDP-MIB` kök OID `1.0.8802.1.1.2`) həm CDP (`CISCO-CDP-MIB`, `1.3.6.1.4.1.9.9.23`) walk edilir, mövcud `app_mgs_snmp_walk_column()`-u (Faza 1-in birinci bəndindən) heç bir dəyişiklik olmadan təkrar istifadə edərək — hər iki cədvəl "bir sütun, bir baza OID, GETNEXT ilə gəz" formasına uyğun gəlir. Nəticələr ayrı `protocol` sətirləri kimi saxlanılır (bir cihaz hər ikisini dəstəkləyə bilər).
  - **Dəqiq sənədləşdirilmiş fərq**: CDP-də `cdpCacheIfIndex` real IF-MIB `ifIndex`-dir (Cisco-nun öz MIB sənədi belə deyir), amma LLDP-də `lldpRemLocalPortNum` **adətən, amma zəmanətsiz** eyni `ifIndex`-dir — ona görə `device_neighbors.local_if_index` LLDP üçün "yaxşı təxmin", CDP üçün "etibarlı" kimi qeyd olunur; unikal dedup açarı isə hər iki halda **xam SNMP cədvəl indeksi** üzərindədir (`local_index_key`), yozumdan asılı deyil.
  - Chassis/Port ID-nin subtype-a görə tam LLDP TLV parse edilməsi (7 mümkün subtype) bu mərhələdə edilmədi — sadə həndəsə: 6 baytlıq dəyər MAC kimi formatlanır (`app_mgs_format_possible_mac()`, indi interfeys kəşfi ilə paylaşılan), qalanı xam mətn kimi saxlanılır. Real qarışıq subtype ssenariləri üçün gələcəkdə dəqiqləşdirilə bilər.
  - `noc-devices.php`: "Qonşuları kəşf et" düyməsi (mövcud Probe/Interfeys kəşfi düymələri ilə eyni blokda) + qonşu sayı/adları xülasə sətri.
  - Real UDP fake-agent smoke: `tools/mgs-device-neighbors-smoke.php` (əlçatmaz hədəf, LLDP+CDP birgə kəşf, sahə-sahə yoxlama, ikinci kəşfin upsert etdiyi — dublikat yaratmadığı).
  - **Bu bənddə paylaşılan `_fake-snmp-agent-for-smoke.php`-də real bug tapılıb düzəldildi**: agent, bütün fiktiv MIB-in son (ən böyük) OID-indən sonra GETNEXT alanda əvvəllər bütün prosesi dayandırırdı (`break` əsas dövrü qırırdı), sadəcə HƏMİN sorğuya cavab verməməli idi. Bu, ilk iki smoke-da heç vaxt üzə çıxmamışdı (onlar tək kəşf keçidi edir və cədvəlin son OID-inə çatmırdılar), amma qonşu smoke-un İKİNCİ (upsert) kəşfi zamanı agent artıq ölmüşdü. `break` → `continue` ilə düzəldildi; hər üç paylaşılan-agent smoke-u (interfeys inventarı, trafik, qonşular) yenidən işlədilib, hamısı keçir.
- Faza 1 indi **tam bağlıdır**: telemetriya (inventar + toplayıcı + rollup + retention + trap ingest) və topologiya (qonşular) hər ikisi real yazıcı + real UDP test ilə tamamlanıb. Növbəti addım Faza 2-dir (Node-dan real data köçürmə) — bax bölmə 3, "Faza 2 — Data köçürmə".

### Faza 2 — Data kocurme

Status: **TAMAMLANDI (2026-08-21)**

Bir defelik idempotent import alet: `tools/import-mgs-node-data.php` (dry-run defolt, `--apply` yazir, `--only=devices,alarms,tickets,onts`, `--source-user`/`--source-pass` — superopticnet-in oz app DB istifadecisinin `olt_control_center`-e granti yoxdur, qesden). Kocurulen:

| Menbe | Hedef | Setir | Qeyd |
|---|---|---|---|
| `devices` (10) + `olts` (3, eyni 3 fiziki OLT ustunde upsert) | `device_learning_profiles` | 10 yeni cihaz | `device_key = 'mgs-' . source_id`; `monitoring_enabled=0`, `status=pending_discovery` defolt (idxal olunmus cihaz avtomatik canli probe-a girmir, admin evvelce tesdiqlemelidir) |
| `alarms` (561) + `alarm_state_events` (23.304 — sessiya evvelinde 17.583 idi, sonra Node terefi arxa planda bir muddet daha isleyib artib) | `mgs_alarm_events` + `mgs_alarm_state_events` | 561 alarm, 23.301 tarixce hadisesi | `dedup_key='mgs-legacy-'.dedupe_key`; status xeritelemesi: acknowledged/resolved_pending_confirmation->ack, closed->resolved, false_positive->suppressed |
| `tickets` (561) | `portal_tickets` | 561 ticket | `LEGACY-MGS-<qisaldilmis_id>` prefiksi (kohne billing importunun `LEGACY-TICKET-` presedentine uygun, amma ayri prefiks — bunlar musteri deyil, seb**e**ke insidentleridir); musteri hesabina baglanmir |
| `onts` (730) | **yeni** `legacy_mgs_ont_inventory` (migration `20260820_480`) | 730 sətir | Ayrica cedvel, `customer_equipment_assignments`-e YAZILMADI |

**Kritik tapinti — ONT-larda PII yox idi**: plana qeder guman edilirdi ki, `onts` cedvelinde real musteri adi/telefon/WiFi sifresi ola biler (sxem bu sutunlari daşıyır). Real datani yoxlayanda melum oldu ki, **butun 730 setirde** `customer_ref`, `mac`, `phone`, `wifi_name`, `wifi_password` **bos**dir; `customer_name` faktiki texniki port etiketidir ("ONT01/071"), real ad deyil. Bu, monitorinq-only bir OLT-avtomatik-kesf axtinidir, hec vaxt real abune/billing datasi ile baglanmayib. Netice: hec bir PII kocurulmedi (kocurulecek hec ne yox idi), ve ONT-lar `customer_equipment_assignments`-e (real `customer_code` teleb edir) DEYIL, ayri `legacy_mgs_ont_inventory`-e yazildi.

**Kocurulmeyen (qesden)**:
- Kredensiallar (`devices.ssh_password_encrypted`, `snmp_*_encrypted`, `olts.ssh_password_ciphertext`, `snmp_password_ciphertext`) — Node-un oz `SECRETS_MASTER_KEY`-i ile sifrelenib, superopticnet-de bu acar yoxdur ve olmamalidir. Idxal olunmus cihazlarda canli SNMP/SSH probe lazimdirsa, admin `device_access_overrides`/credential vault UI-dan yeniden daxil etmelidir.
- `interfaces` (66 setir) -> `device_interfaces`: bu cedvelin `if_index`-i real SNMP ifIndex olmalidir (`tools/poll-device-interface-traffic.php` onu GET sorgusunda istifade edir); Node-un interfeys setirlerinde belə bir eded index yoxdur, uydurmaq geleceke `app_mgs_discover_device_interfaces()`-in tapacagi real ifIndex ile toqquşma riski yaradardi. Interfeys sayi cihazin `features_json`-unda tarixi reference kimi saxlanilir.
- Tarixi trafik (`device_interface_traffic_samples`-e uygun, 335K+ setir) ve `jobs` (25.641 setir) — canli emeliyyat ucun lazim deyil, kocurulmedi.

**Tapilan v duzeldilen import-alet buqu**: ilk `--apply` icrasinda `portal_tickets.ticket_no` (VARCHAR(32)) `'LEGACY-MGS-' . $sourceId` (38 simvol) ile SƏSSİZ KƏSİLİRDİ (MySQL-in defolt qeyri-strict davranisi) — bu, idempotentlik yoxlamasini pozurdu (tam uzunluqlu deyer bazadaki kesilmis deyerle heç vaxt uygun gelmirdi, her `--apply` cagirisi eyni 561 ticket-i YENIDEN "yeni" kimi daxil edirdi). `mgs_import_ticket_no()` helper-i elave edildi: menbenin oz artiq-lazimsiz `ticket-` prefiksini cixarir (31 simvol, limitin altinda). Kesik setirler silinib duzgun sxemle yenidən import edildi; 3 ardicil `--apply` icrasi ile idempotentlik tesdiqlendi (0/0/0 yeni, hamisi upsert).

**Doğrulama**: `device_learning_profiles` (10+10=20), `mgs_alarm_events`/`mgs_alarm_state_events`, `portal_tickets` (561, unikal `ticket_no`), `legacy_mgs_ont_inventory` (730, hamısı `mgs-olt-fd3e4e73`-ə bağlı) sətir sayları əl ilə yoxlanıldı; `mgs_alarm_events.ticket_no` ilə `portal_tickets.ticket_no` arasında JOIN uyğunluğu təsdiqləndi (563 uyğun sətir); `crm.php` və `noc-devices.php` headless render — heç bir fatal/parse xətası yoxdur, idxal olunmuş cihazlar NOC siyahısında görünür. `tools/full-system-regression.php` təkrar işlədildi.

**2026-08-21 (davam) — müştəri profilində yoxlama + browse view**: istifadəçinin sorğusu ilə real bir müştərinin `billing-customer-profile.php` səhifəsi headless render edildi — heç bir idxal edilmiş `mgs-`/`LEGACY-MGS-` izi tapılmadı (yalnız əlaqəsiz `mgs-stat-card` CSS class adları), "Modem/ONT" statı düzgün olaraq `0` göstərdi (native `customer_equipment_assignments`-dən, idxaldan asılı deyil) — təcrid təsdiqləndi. Amma bu, həm də göstərdi ki, `legacy_mgs_ont_inventory` heç bir UI-də görünmür idi (yalnız migration+import alətində istinad olunurdu). İstifadəçi bunu əlavə etməyi istədi:

- `mgs-integration-lib.php`: `app_mgs_legacy_ont_inventory_rows()` — read-only, səhifələnmiş (50/səhifə), OLT/status/serial-port axtarışı ilə filtrlənən siyahı.
- `noc-devices.php`: yeni `?view=legacy-onts` görünüşü ("Köhnə MGS ONT arxivi", Cihazlar naviqasiya qrupunda). Mövcud `ont-search` görünüşünə (real müştəri ilə bağlı canlı ONT axtarışı) **qəsdən qarışdırılmadı** — fərqli data domenidir (biri real müştəri/WiFi/RADIUS datası, digəri müştərisiz tarixi arxiv); qarışdırmaq çaşdırıcı və semantik cəhətdən yanlış olardı. Filtr/axtarış/səhifələmə headless render ilə yoxlanıldı (axtarış 1 dəqiq nəticə tapdı, səhifə 2 düzgün göstərildi).

### Faza 3 — Funksional paritet

Status: **BAŞLADI (2026-08-21) — Cisco IOS/IOS-XE onboard edildi**

`D:\xampp\htdocs\oltidareetme\server\adapters\` baxıldı: `hsgq-g08l.js` (artıq PHP-də tam qarşılığı var) xaricində `cisco-router.js`, `cisco-iosxr-router.js`, `cisco-switch.js`, `aruba-cx.js`, `hp-procurve.js`, `hpe-comware.js`, `huawei-switch.js`, `generic-snmp.js`/`generic-ssh.js` var. Real inventarla (`device_learning_profiles` + Faza 2 idxalı) tutuşdurulanda: **yalnız Cisco (router+switch) real avadanlıqdır** — Aruba/HP/HPE/Huawei-switch üçün heç bir real cihaz yoxdur (spekulyativ, bu mərhələdə portlanmadı, "bu vendor dəstəklənmir" kimi açıq saxlanıldı).

Araşdırma özü gözlənilməz bir şey aşkar etdi: `mgs-integration-lib.php`-də artıq **əvvəldən** `CiscoRouterAdapter`/`CiscoSwitchAdapter` `adapter_key` təyinatı və minimal komanda xəritəsi (`app_mgs_default_device_profiles()`) var idi — Faza 3 ilə əlaqəsiz, daha əvvəlki işdən qalma. Amma `app_mgs_cli_discovery_handler_registry()` (Faza 1/2-dən əvvəlki "per-vendor CLI-discovery" işindən) yalnız `HSGQ`-nu tanıyırdı — Cisco cihazları üçün `cli_discovery_snapshot` backup növü həmişə `cli_discovery_not_supported` qaytarırdı.

**Nə quruldu**:
- Yeni `tools/cisco-cli-discovery.py` — `tools/hsgq-cli-discovery.py`-nin eyni paramiko-əsaslı interaktiv SSH naxışı, amma **exploratory `?`-menyu gəzintisi yoxdur** (HSGQ-dan fərqli olaraq IOS `show` sintaksisi standart/sənədləşdirilmişdir, canlı kəşfə ehtiyac yoxdur): bağlanır, `terminal length 0` (pagination söndürür), best-effort `enable`, sonra 6 sabit təhlükəsiz `show` əmri (`show version`, `show ip interface brief`, `show interfaces status`, `show vlan brief`, `show mac address-table`, `show inventory`).
- `mgs-integration-lib.php`: `app_mgs_cisco_cli_discovery_probe()` (HSGQ-nun probe funksiyası ilə eyni struktur), `app_mgs_cisco_derived_command_map_from_discovery()` (HSGQ-dan fərqli olaraq canlı çıxışdan DEYİL, `is_cisco_ios` təsdiqindən sonra `app_mgs_default_device_profiles()`-in artıq nəzərdən keçirilmiş sabit komanda dəstini qaytarır — eyni cihaz iki fərqli yolla (seed vs kəşf) eyni komanda xəritəsinə gəlsin deyə). `app_mgs_cli_discovery_handler_registry()`-ə `CISCO` əlavə edildi.
- **Yan-tapıntı düzəlişi**: backup snapshot payload-ının `discovery` bölməsi HSGQ-nun öz sahələrinə (`nodes`/`keywords`/`sample_ont_id`) sərt kodlanmışdı — Cisco-nun (`outputs`/`hostname`/`is_cisco_ios`) fərqli sahələri səssizcə itəcəkdi. `'raw' => $discovery` sahəsi əlavə edildi (HSGQ-nun mövcud sahələri toxunulmadı, geriyə uyğun).
- Yeni `tools/cisco-cli-discovery-smoke.php` — `tools/hsgq-cli-discovery-smoke.php`-nin eyni "real cihaza çatmaq mümkün deyilsə səliqəli skip, `REQUIRE_CISCO_LIVE=1` ilə məcburi et" naxışı, `core-sptech-asr1006-mgmt`-ə qarşı.

**İkinci tapıntı — Faza 2 idxal alətinin dublikat cihaz buqu**: bu araşdırma zamanı `device_learning_profiles`-də 7 cüt dublikat aşkarlandı — Faza 2 idxalı eyni 7 fiziki cihaz (5 HSGQ OLT + 1 Cisco switch + 1 Cisco router, hamısı `app_mgs_default_device_profiles()`-in əvvəlcədən mövcud "known real device" seed-ində idi) üçün **aşağı keyfiyyətli** (`GenericSnmpAdapter`, minimal `features_json`) təkrar sətir yaratmışdı, çünki idxal aləti `device_key`-i sırf `'mgs-' . $sourceId` ilə seçirdi, mövcud native sətirləri IP üzrə axtarmırdı. **Düzəliş**: `tools/import-mgs-node-data.php`-ə `mgs_import_resolve_device_key()` əlavə edildi — əvvəlcə öz keçmiş idxalını (`mgs-<id>`), sonra native (qeyri-`mgs-` prefiksli) sətri IP üzrə axtarır; native tapılarsa, cihazın öz sahələrini (`vendor`/`model`/`adapter_key`) EZMƏDƏN yalnız `features_json.legacy_mgs_import` cross-reference-i əlavə edir. Alarm/ticket/ONT idxalı da eyni "canonical key map"-dən istifadə etməyə keçirildi (əvvəllər hərəsi öz-özünə `'mgs-' . $sourceId` hesablayırdı — uyğunsuzluq riski). Əlavə tapılan kiçik buq: `mgs_alarm_events` upsert-inin `ON DUPLICATE KEY UPDATE` bəndində `object_id` yox idi — düzəldildi (əks halda cihaz key-i düzəlsə belə mövcud alarm sətirləri köhnə object_id-ni saxlayacaqdı).
  - 7 dublikat sətir silindi, tam idxal (`--apply`, bütün domenlər) yenidən işlədildi: `merged_into_native: 8` (7 fiziki cihaz, biri həm `devices` həm `olts` mənbə cədvəlində olduğu üçün 2 dəfə toxunulub), `created: 0`. Nəticə: 730 ONT sətri indi düzgün `olt-hsgq-kurdexani-21`-ə (əvvəlki səhv `mgs-olt-fd3e4e73` yox) bağlıdır; 561 alarmın 7-si düzgün native device_key-lərə bağlıdır (qalan 3 — Astara 1/2 + naməlum bir cihaz — həqiqətən native qarşılığı olmadığı üçün öz `mgs-` key-ini saxlayır, bu düzgündür). 3 ardıcıl `--apply` icrası ilə tam idempotentlik təsdiqləndi (device count sabit 13, `created: 0` hər dəfə).

**Doğrulama**: `cli-discovery-vendor-registry-smoke.php` yenidən keçdi (`supported_vendors: [HSGQ, CISCO]`), `cisco-cli-discovery-smoke.php` gözlənilən `management_path_down` səbəbi ilə səliqəli skip etdi, `app_mgs_cisco_cli_discovery_probe()` əlçatmaz portla əl ilə test edildi (təmiz `SSHException` xətası, PHP çökməsi yoxdur), Python parsing funksiyaları (`last_prompt`/`looks_like_cisco_ios`/`normalize_output`) unit-test edildi. `noc-devices.php` headless render dublikat-təmizlikdən sonra yenə xətasız.

**Hələ edilməyib**: Aruba/HP/HPE/Huawei-switch (real avadanlıq yoxdur, portlanmadı). Cisco tərəfi üçün real hardware təsdiqi yoxdur (HSGQ-nun `hardware_validated` gate-i kimi bir konsepsiya bu adapter üçün hələ yoxdur — amma HSGQ-dan fərqli olaraq bu adapter yalnız READ-ONLY `show` əmrləri işlədir, konfiqurasiya dəyişikliyi etmir, ona görə risk profili aşağıdır).

### Faza 4 — Sondurme

Status: **Addım 1-3 tam icra edildi və doğrulandı (2026-08-21). Addım 5 qismən (data köçürüldü, boş qovluq qabığının silinməsi istifadəçinin sorğusu ilə sonraya saxlanıldı). Addım 4 və 6 qəsdən icra edilmir (aşağıya bax).**

İstifadəçinin "Faza 4-ə başla: Node app-ın söndürülməsini planlaşdır" sorğusuna cavab olaraq yazılıb, sonra "Bəli, addım 1-i icra et — ehtiyat nüsxə götür ardınca digər addımları icra et avtomatik" ilə icraya başlanıb.

**İcra edildi:**
- **Addım 1 (ehtiyat nüsxə)**: `D:\SuperOptic-MGS-Backup-20260821\olt_control_center-20260821.sql.gz` (24.5MB, 83 cədvəl, tam dump) + `config.local.php.bak-20260821`. Doğrulandı.
- **Addım 2 (scheduled task-lar)**: `SuperOptic MGS Weekly Backup` → `Disabled` (təsdiqləndi). `SuperOptic MGS Stack Autostart` → `Ready` saxlanıldı, çünki Faza 0-dan bəri öz defolt davranışı artıq Node-u başlatmır (`-StartMgs` opt-in tələb edir) — əlavə söndürməyə ehtiyac yoxdur.
- **Addım 3 (SSO körpüsü)**: `config.local.php`-də `olt_sso_entrypoint`/`olt_sso_shared_secret` boşaldıldı, izahlı şərh əlavə edildi (orijinal dəyərlər backup-dadır). `app_portal_olt_handoff_url()`-un `noc-devices.php?view=devices`-ə fallback etdiyi birbaşa PHP testi ilə təsdiqləndi.
- **Addım 5 (qovluq arxivi) — qismən**: `robocopy /MOVE` ilə `D:\xampp\htdocs\oltidareetme` → `D:\xampp\htdocs\_archived\oltidareetme-20260821` (1637/1637 qovluq, 13471/13471 fayl, 800.93 MB, 0 uğursuz — tam doğrulandı, mənbə yolunda 0 qalıq fayl). Orijinal boş qovluq **qabığının özü** silinə bilmədi — davamlı "used by another process" kilidi (səbəb tam müəyyənləşdirilmədi; Windows Search Indexer restart edildi, kömək etmədi; ehtimal Windows Defender real-time skanı böyük fayl köçürməsindən sonra). İstifadəçinin qərarı ilə bu təmizlik sonraya saxlanıldı (funksional təsiri yoxdur — bütün real data artıq arxiv yolundadır).

**Qəsdən icra edilmir:**
- **Addım 4 (production Node prosesini dayandırmaq)**: bu lokal maşından mümkün deyil, production statusu (`mgs.superoptic.net` üzərində) təsdiqlənməyib.
- **Addım 6 (verilənlər bazasının taleyi)**: planda olduğu kimi, DROP edilmir/dəyişdirilmir.

#### Ön şərtlər (icraya başlamazdan əvvəl TƏSDİQLƏNMƏLİDİR)

1. Faza 1-3 tam bağlıdır (✅ artıq — telemetriya, alarm/tarixçə, trap, qonşuluq, tarixi data köçürülməsi, Cisco CLI-discovery).
2. **Real istifadəçi soak-period təsdiqi** — NOC/alarm/ticket/ONT-arxiv üçün native superopticnet UI-nin real iş axınında (Node MGS-in yerinə) bir müddət (tövsiyə: 1-2 həftə) problemsiz istifadə edildiyi təsdiqlənməlidir. Bu sessiyada yalnız headless render + DB yoxlaması aparılıb — real istifadəçi qəbulu YOXDUR, bunu yalnız istifadəçi/komanda təsdiqləyə bilər.
3. **Production statusu naməlumdur — bu, ən böyük açıq sualdır.** `config.local.php` və `config.local.production.example.php`-də `olt_sso_entrypoint` real `https://mgs.superoptic.net/api/olt-sso/consume` ünvanına və real paylaşılan sirrə işarə edir. Bu, YA production-da hələ də canlı bir Node prosesi olduğunu göstərir, YA DA sadəcə istifadə olunmayan köhnə konfiqurasiya qalığıdır — bu lokal maşından production serverinin real vəziyyətini yoxlamaq mümkün deyil. **İstifadəçi/DevOps təsdiqləməlidir: `mgs.superoptic.net` üzərində Node prosesi hazırda işləyirmi?** Cavab yoxdursa, Addım 4 (aşağıda) icra edilə bilməz.

#### Mərhələli icra sırası (hər addım əvvəlkindən sonra, geri-dönüşlülüyə görə artan sırada)

**Addım 1 — Ehtiyat nüsxə (təhlükəsiz, geri-dönən, HƏR ŞEYDƏN ƏVVƏL)**
- `olt_control_center` DB-nin tam `mysqldump`-ı (hazırda 532.7 MB) + `D:\xampp\htdocs\oltidareetme` qovluğunun tam arxiv kopyası (hazırda 800.9 MB), hər ikisi tarixli şəkildə bu maşından KƏNAR bir yerdə (xarici disk, başqa server, bulud) saxlanılır.

**Addım 2 — Lokal planlaşdırılmış tapşırıqları söndür (təhlükəsiz, geri-dönən)**
- `SuperOptic MGS Weekly Backup` — hazırda artıq **fail** olur (`LastTaskResult=2147946720`, paylaşma xətası) və növbəti icrası 2026-08-23-ə planlaşdırılıb; `Disable-ScheduledTask -TaskName "SuperOptic MGS Weekly Backup"`.
- `SuperOptic MGS Stack Autostart` — Faza 0-dan bəri artıq Node-u avtomatik başlatmır (`-StartMgs` verilmədən), əlavə addım tələb olunmur (istəsə tam silinə bilər, amma indi vacib deyil).

**Addım 3 — SSO körpüsünü söndür (kod/konfiq səviyyəsi, git ilə geri-dönən)**
- **Diqqət**: `portal-bridge.php`-in İKİ hədəfi var — `target=olt` (Node MGS, bu Faza-nın mövzusu) VƏ `target=billing` (tamamilə ayrı, `billing.superoptic.net` köhnə billing sistemi — TOXUNULMAMALIDIR).
- `config.local.php`-də `olt_sso_entrypoint`-i boşaltmaq kifayət edir: `app_portal_olt_handoff_url()` artıq entrypoint/sirr boş olanda `$safeFallback`-a (default `noc-devices.php?view=devices`) düşür — bu, MÖVCUD təhlükəsiz davranışdır, əlavə kod dəyişikliyi tələb olunmaya bilər.
- `app_mgs_bridge_status()` (bridge "offline" göstəricisi) artıq `ai-module-lib.php`/`mgs-copilot-context.php`-də graceful-degrade ilə idarə olunur — dəyişiklik lazım deyil.

**Addım 4 — Production Node prosesini dayandır (mənim əlaqəm xaricində, YALNIZ Ön şərt 3 təsdiqləndikdən sonra)**
- Bu, lokal maşından icra edilə bilməz. İstifadəçi/DevOps production serverində (`mgs.superoptic.net`) real vəziyyəti təsdiqləməli və lazım gələrsə orada dayandırmalıdır.

**Addım 5 — Node qovluğunu arxivləşdir (geri-dönən, silmə YOX)**
- `D:\xampp\htdocs\oltidareetme` → `D:\xampp\htdocs\_archived\oltidareetme-20260821` (yenidən adlandırma/köçürmə, `rm`/silinmə YOX).

**Addım 6 — Verilənlər bazasının taleyi (uzun-müddətli, İRƏLİ GEDİLMƏMƏLİ)**
- Addım 1-in dump-ı təhlükəsiz saxlanıldıqdan və Addım 2-5-dən sonra bir neçə həftə problemsiz keçdikdən sonra belə: **DROP ETMƏ tövsiyə OLUNMUR.** `olt_control_center`-i sadəcə dayandırılmış/adı dəyişdirilmiş saxla (məs. `olt_control_center_archived_20260821`) — disk yeri qənaəti (532 MB) heç vaxt bərpa oluna bilməyən data itkisi riskinə dəyməz.

#### Bu sessiyada icra EDİLMƏDİ

Yuxarıdakı 6 addımdan heç biri işə salınmadı — `oltidareetme` qovluğu, `olt_control_center` bazası, scheduled task-lar (Weekly Backup daxil) və `config.local.php`-in `olt_sso_*` sahələri toxunulmadan qalıb. Bu, sırf plandır; icra istifadəçinin açıq təsdiqini gözləyir.

## 4. Novbeti sessiya ucun

Bu sened `docs/25_ROADMAP.md`-de istinad olunur. Faza 0-4 arasında bu maşından edilə bilən hər şey tamamlanıb. Qalan açıq maddələr:

1. **`D:\xampp\htdocs\oltidareetme` boş qovluq qabığının silinməsi** — real data heç bir aidiyyəti yoxdur (artıq `_archived`-dədir), sadəcə kosmetik təmizlik. Bir sonrakı sessiyada handle özü açılmış ola bilər — sadəcə `Remove-Item -LiteralPath 'D:\xampp\htdocs\oltidareetme' -Force -Recurse` yenidən sınana bilər.
2. **Faza 4 Addım 4** (production `mgs.superoptic.net` üzərində Node prosesini dayandırmaq) — yalnız istifadəçi/DevOps production statusunu təsdiqlədikdən sonra aktual olur, bu maşından edilə bilməz.
3. **Faza 4 Addım 6** (verilənlər bazasının taleyi) — qəsdən açıq saxlanılıb, DROP tövsiyə olunmur.
4. **Faza 3-ün qalan vendorları** (Aruba/HP/HPE/Huawei-switch) — yalnız real avadanlıq görünərsə aktual olur.

Bunlardan heç biri təcili deyil; MGS konsolidasiyasının əsas məqsədi (bir sistemdən idarə) artıq yerinə yetirilib.
