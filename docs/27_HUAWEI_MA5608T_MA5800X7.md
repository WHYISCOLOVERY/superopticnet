# Huawei SmartAX MA5608T və MA5800-X7 mərhələsi

## Başlama şərti

Bu mərhələ 26 iyul sistem keçidi sabitləşdikdən sonra başlanır. Cari buraxılışa canlı Huawei konfiqurasiya yazısı daxil edilmir.

Məqsəd HSGQ üçün qurulmuş öyrənilən command-map yanaşmasını ayrıca Huawei adapteri ilə genişləndirməkdir. HSGQ və Huawei əmrləri eyni profilə qarışdırılmamalıdır.

## Mərhələ 0: laboratoriya inventarı

Hər OLT üçün aşağıdakı məlumatlar toplanır:

- model: `SmartAX MA5608T` və ya `SmartAX MA5800-X7`;
- firmware/patch versiyası;
- chassis, control board, service board və uplink board siyahısı;
- management IP/VLAN və təsdiqlənmiş giriş üsulu;
- timezone/NTP, sysName və filial/POPS əlaqəsi;
- mövcud PON, ONT, VLAN, DBA, line/service profile və service-port sayları;
- konfiqurasiya backup üsulu və geri yükləmə proseduru.

Credential və real müştəri məlumatları sənədə yazılmır; sistemin şifrəli secret registry-si istifadə olunur.

## Mərhələ 1: yalnız oxu adapteri

İlk adapter yalnız aşağıdakı məlumatları oxuyur:

- cihaz əlçatanlığı, uptime, temperatur və board vəziyyəti;
- GPON port statusu və ONT sayı;
- ONT serial, model, run/last-down state və last-down cause;
- ONT/OLT optical RX/TX və temperatur;
- alarm və dəyişiklik snapshotları;
- config backup metadata və checksum.

Polling OLT/PON üzrə növbələnir, bütün ONT-lər eyni anda sorğulanmır. Son uğurlu snapshot cache-də saxlanılır və monitor səhifəsi canlı oxu alınmadıqda bunu açıq göstərir.

## Mərhələ 2: müştəri və inventar əlaqəsi

- Müştəri kodu -> OLT -> frame/slot/port -> ONT ID əlaqəsi.
- ONT serial/MAC inventar aktivinə bağlanır.
- Texnik quraşdırmanı təsdiqlədikdə anbar aktivi müştəriyə keçir.
- ONT dəyişdirilməsi və geri qaytarılması ayrıca sənəd və audit izi yaradır.
- Kütləvi PON/OLT nasazlığı fərdi ticket fırtınası yaratmır; bir parent incident altında qruplaşdırılır.

## Mərhələ 3: nəzarətli yazma əməliyyatları

Yazma əməliyyatları default olaraq bağlı feature flag arxasında olur:

- ONT qeydiyyatı və təsdiqlənmiş serial auth;
- əvvəlcədən təsdiqlənmiş line/service profile seçimi;
- paket/VLAN/service-port dəyişməsi;
- ONT disable/enable və restart;
- təsdiqlənmiş test ONT üçün Wi-Fi/credential workflow inteqrasiyası.

Hər yazma əməliyyatı üçün preview, operator səbəbi, ikinci təsdiq, before/after snapshot və dəyişdirilməz audit jurnalı tələb olunur. Bulk write ayrıca texniki təsdiq olmadan açılmır.

## Adapter müqaviləsi

Huawei adapteri vendor-dan asılı olmayan aşağıdakı nəticəni qaytarmalıdır:

```text
device_status
board_inventory
pon_ports
ont_identity
ont_state
optical_metrics
alarm_events
config_backup
action_preview
action_result
```

CLI mətnini UI birbaşa parse etmir. Adapter firmware profilini seçir, strukturlaşdırılmış nəticə qaytarır və tanınmayan output-u `unknown` kimi saxlayır.

## Test matrisi

1. Fixture output parser testləri: hər model və firmware üçün sanitizasiya olunmuş nümunə.
2. Read-only lab testləri: board, PON, ONT, optical və alarm saylarının OLT CLI ilə müqayisəsi.
3. Timeout/reconnect testləri: yarımçıq output, paging, prompt dəyişməsi və session limitləri.
4. Inventory/customer mapping testləri: serial təkrarı, ONT dəyişməsi və filial transferi.
5. Controlled write: yalnız ayrılmış test PON/ONT üzərində preview -> approve -> apply -> verify -> rollback.
6. Load test: növbəli polling və cache; production OLT CPU/session limitini keçməməlidir.
7. Security: secret maskalama, command allow-list, RBAC və audit tamlığı.

## Qəbul meyarları

- Hər iki model read-only inventory və optical testlərini keçir.
- Eyni ONT CLI və portal nəticəsində eyni serial/state/signal göstərir.
- Offline səbəbi və vaxtı monitor tooltip/detail panelində görünür.
- Alarm dedup və parent incident qruplaşdırması işləyir.
- Config backup verify və checksum uğurludur.
- Yazma əməliyyatı icazəsiz rol üçün mümkün deyil.
- Test ONT üzərində dəyişiklikdən sonra before/after və rollback sübutu mövcuddur.

Bu meyarlar tamamlanmadan Huawei adapteri production OLT-lərdə write mode-a keçirilməməlidir.
