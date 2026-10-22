# SUPEROPTIC MGT — VAHİD ISP İDARƏETMƏ, OSS/BSS VƏ AI AGENT SİSTEMİ

## 1. İcraçı nəticə

SuperOptic üçün qurulan sistem sadəcə müştəri qeydiyyatı və ödəniş qəbul edən billing proqramı deyil. Düzgün tamamlandıqda bu sistem aşağıdakıların hamısını birləşdirən **ISP Operating System** olmalıdır:

- müştəri və abunəlik idarəetməsi;
- billing, ödəniş, borc və gəlir nəzarəti;
- FreeRADIUS, PPPoE, NAS və CGNAT idarəetməsi;
- OLT, ONT, router, switch və server monitorinqi;
- maliyyə, kassa, bank, xərc, əməkhaqqı və aktivlərin uçotu;
- anbar, kabel, modem, material, təhvil və geri qaytarma;
- HR, davamiyyət, bonus və əməkdaş performansı;
- operator, texnik və müştəri müraciətlərinin idarəsi;
- SMS, WhatsApp və digər kommunikasiya kanalları;
- vahid statistika və qərar dəstəyi;
- şirkəti analiz edən, iş yaradan və nəticəni yoxlayan AI agentlər.

İlkin layihə vizyonunda da sistem Billing Brain, Network Brain, CRM Brain, HR Brain, Inventory Brain və Security Brain kimi funksional beyinlərə, ortaq event sisteminə və təhlükəsiz əməliyyat qatına bölünmüşdü. Əsas prinsiplər root-cause analizi, insan təsdiqi, tam audit, təhlükəsiz uğursuzluq, SLA və real məlumat olmadan qərar verməməkdir.

Mənim yekun qiymətləndirməm:

> **Sistemin əsas ideyası çox güclüdür. Lakin hazırda problem funksiyanın azlığı deyil, funksiyaların parçalanması, eyni məlumatın bir neçə yerdə saxlanılması və kritik əməliyyatların vahid service, permission, audit və transaction qatından keçməməsidir.**

SuperOptic üçün ən düzgün yol bütün sistemi yenidən sıfırdan yazmaq deyil. Əvvəl mövcud sistemi etibarlı **modular monolith** vəziyyətinə gətirmək, sonra yalnız şəbəkə monitorinqi, RADIUS, bildiriş və analitika kimi uyğun hissələri ayrıca servislərə ayırmaq lazımdır.

---

# 2. SuperOptic MGT-nin əsas missiyası

Sistemin məqsədi əməkdaşa onlarla səhifə göstərmək deyil. Sistem əməkdaşa həmin anda nə etməli olduğunu göstərməlidir.

Hər əməkdaş sistemə daxil olduqda aşağıdakı sualların cavabını görməlidir:

1. Bu gün hansı işləri görməliyəm?
2. Hansı iş gecikib?
3. Hansı müştəri və ya şəbəkə problemi kritikdir?
4. Məndən hansı təsdiq gözlənilir?
5. Son etdiyim əməliyyatın nəticəsi nə oldu?
6. Performansım necə qiymətləndirilir?
7. AI mənə hansı problemi və ya fürsəti göstərir?

CEO isə sistemə daxil olduqda bunları görməlidir:

- bu gün nə qədər gəlir daxil olub;
- nə qədər ödəniş gecikir;
- hansı ərazilərdə müştəri itkisi artır;
- hansı şəbəkə hissəsində risk yaranır;
- hansı əməkdaş və şöbə yaxşı və ya zəif işləyir;
- hansı anbar aktivləri itir, istifadəsiz qalır və ya normadan artıq sərf olunur;
- hansı investisiya daha çox gəlir gətirəcək;
- şirkətin 30, 90 və 365 günlük proqnozu necədir.

---

# 3. Sistemin ideal ümumi quruluşu

SuperOptic MGT aşağıdakı səkkiz əsas iş sahəsinə bölünməlidir:

| Əsas sahə | Məqsəd |
|---|---|
| İş masası | Rol üzrə tapşırıqlar, kritik bildirişlər və gündəlik fəaliyyət |
| Müştəri xidməti və Billing | Müştəri, ödəniş, paket, borc, müraciət və xidmət |
| Şəbəkə və sahə əməliyyatları | NOC, OLT/ONT, RADIUS, IP, NAT, texnik və quraşdırma |
| Maliyyə | Gəlir, xərc, kassa, bank, qaimə, əməkhaqqı və aktiv |
| Anbar | Material, avadanlıq, kabel, təhvil, geri qaytarma və RMA |
| HR və əməkdaş idarəetməsi | İşçi profili, davamiyyət, performans, bonus və sənəd |
| Mesaj Mərkəzi | SMS, WhatsApp, zəng sonrası geri dönüş və kampaniyalar |
| Hesabatlar və idarəetmə | KPI, statistika, qaydalar, rollar, audit və AI nəzarəti |

Audit sənədlərində mövcud sistemdə Billing, Maliyyə, HR, Anbar, Şəbəkə/NOC, Mesaj Mərkəzi, WhatsApp, Statistika və AI modullarının olduğu təsdiqlənib. Ancaq eyni müştəri, ödəniş, statistika, cihaz və mesaj məlumatlarının müxtəlif səhifələrdə təkrarlandığı müəyyən edilib.

## 3.1 İş masası

İş masası hər rol üçün fərqli olmalıdır.

Operator üçün:

- müştəri axtar;
- ödəniş al;
- yeni müraciət aç;
- geri zəng et;
- gecikmiş müraciətlər;
- cavabsız WhatsApp mesajları;
- yeni qoşulmuş müştəriyə nəzarət.

Texnik üçün:

- bu günün iş marşrutu;
- təyin edilmiş quraşdırmalar;
- açıq nasazlıqlar;
- anbardan götürüləcək materiallar;
- müştəriyə təhvil veriləcək avadanlıq;
- geri qaytarılacaq material;
- SLA-sı yaxınlaşan işlər.

Maliyyə üçün:

- günün daxilolmaları;
- təsdiq gözləyən xərclər;
- kassa-bank uyğunsuzluğu;
- provider reconciliation fərqləri;
- maaş və bonus siyahısı;
- gecikmiş borclar;
- bağlanmamış maliyyə dövrü.

NOC üçün:

- kritik şəbəkə hadisələri;
- bir neçə müştəriyə təsir edən ortaq problem;
- yüksək trafik və tutum riski;
- optik siqnal zəifləmələri;
- RADIUS və PPPoE anomaliyaları;
- təsdiq gözləyən şəbəkə əməliyyatları.

CEO üçün:

- şirkət sağlamlıq indeksi;
- gəlir və pul axını;
- aktiv və borclu müştəri sayı;
- churn riski;
- şəbəkə keyfiyyəti;
- əməkdaş məhsuldarlığı;
- investisiya və böyümə imkanları.

---

# 4. Müştəri xidməti və Billing sistemi

## 4.1 Vahid müştəri profili

Müştəri ilə bağlı bütün məlumat bir profildə olmalıdır:

### Ümumi məlumat

- müştəri kodu;
- ad, soyad və əlaqə məlumatları;
- müqavilə və identifikasiya məlumatları;
- yaşayış və xidmət ünvanı;
- xəritə koordinatı;
- filial, zona və ərazi;
- fərdi və ya korporativ müştəri;
- VIP və ya kritik müştəri statusu.

### Xidmət məlumatı

- aktiv paket;
- sürət;
- aylıq ödəniş;
- xidmətin başlama tarixi;
- PPPoE istifadəçi adı;
- RADIUS statusu;
- statik və ya dinamik IP;
- NAS/router;
- VLAN;
- OLT, PON portu və ONT;
- modem və digər avadanlıq.

### Maliyyə məlumatı

- cari balans;
- borc;
- son ödəniş;
- ödəniş tarixçəsi;
- bonus və güzəştlər;
- müqavilə və qəbzlər;
- provider transaction-ları.

### Texniki məlumat

- cari PPPoE sessiyası;
- verilmiş IP;
- sessiyanın başlama vaxtı;
- trafik;
- son əlaqə kəsilmələri;
- optik siqnal;
- ONT uptime;
- texniki müraciətlər;
- əvvəlki nasazlıqlar.

### Kommunikasiya

- SMS tarixçəsi;
- WhatsApp yazışmaları;
- zəng və geri dönüş qeydləri;
- müştərinin verdiyi qiymətlər;
- şikayət və məmnunluq göstəricisi.

Mövcud auditdə müştəri, profil, ödəniş, balans və RADIUS məlumatlarının ayrı-ayrı səhifələrdə təkrarlandığı müəyyən edilib. Düzgün qərar bunları silmək deyil, vahid müştəri profili daxilində tab və filtrlər kimi birləşdirməkdir.

## 4.2 Yeni müştərinin yaradılması

Yeni müştəri axını vahid transaction və ya idarə olunan saga kimi işləməlidir:

1. Müştəri məlumatları daxil edilir.
2. FIN, telefon və ünvan üzrə dublikat yoxlanılır.
3. Müştəri kodu yaradılır.
4. Zona və filial müəyyən edilir.
5. Paket seçilir.
6. PPPoE istifadəçi adı yaradılır.
7. IP pool və NAS seçilir.
8. RADIUS hesabı hazırlanır.
9. ONT serialı və OLT məlumatı yoxlanılır.
10. Müştəri və abunəlik bazaya yazılır.
11. Anbardan verilmiş modem müştəriyə bağlanır.
12. Quraşdırma işi texnikə açılır.
13. Aktivləşmədən sonra SMS göndərilir.
14. Müştəri onboarding nəzarətinə daxil edilir.
15. Bütün addımlar audit jurnalına yazılır.

İlkin sistem sənədlərində müştərinin yaradılması zamanı PPPoE, IP pool, RADIUS, ONT, balans, abunəlik, SMS və audit addımlarının birlikdə nəzərdə tutulduğu görünür.

Ancaq bütün addımlar bir transaction içində zorla saxlanmamalıdır. Router, RADIUS və SMS kimi xarici sistemlər uğursuz ola bilər. Buna görə hər addımın statusu ayrıca saxlanmalıdır:

- `customer_created`;
- `radius_pending`;
- `radius_created`;
- `installation_pending`;
- `equipment_assigned`;
- `activated`;
- `notification_sent`;
- `failed_requires_review`.

Beləliklə, yarımçıq qalan müştəri itmir və sistem “aktivləşdi” deyərək yanlış məlumat göstərmir.

## 4.3 Ödəniş axını

Ödəniş sistemin ən kritik maliyyə əməliyyatıdır.

İdeal ödəniş axını:

1. Unikal `idempotency_key` qəbul edilir.
2. Müştəri və məbləğ yoxlanılır.
3. Ödəniş transaction statusu `pending` yaradılır.
4. Maliyyə ledger yazısı hazırlanır.
5. Müştəri balansı dəyişdirilir.
6. Ödəniş uğurlu statusuna keçirilir.
7. Outbox event yaradılır.
8. RADIUS xidməti aktivləşdirilir və ya CoA göndərilir.
9. Nəticə yenidən oxunaraq təsdiqlənir.
10. Qəbz yaradılır.
11. SMS göndərilir.
12. Audit tamamlanır.

RADIUS əməliyyatı uğursuz olarsa ödəniş geri silinməməlidir. Müştərinin pulu qəbul olunub, amma şəbəkə aktivləşməyibsə:

- ödəniş `completed`;
- RADIUS əməliyyatı `failed_retrying`;
- operator və NOC üçün tapşırıq;
- avtomatik retry;
- müəyyən müddətdən sonra eskalasiya.

Mövcud audit ödənişin DB commit-dən sonra RADIUS-a yazıldığını, təkrar cəhdin duplicate ödəniş yarada biləcəyini və RADIUS failure compensation mexanizminin olmadığını göstərib. Eyni risk nağd/manual ödəniş axınında da var.

Bu problem həll olunmadan AI agentə ödəniş yazmaq səlahiyyəti verilməməlidir.

## 4.4 Borc və xidmətin dayandırılması

Borc sistemi yalnız “pul yoxdur, interneti kəs” məntiqi ilə işləməməlidir.

Sistem bunları nəzərə almalıdır:

- son 12 aylıq ödəniş intizamı;
- neçə gün gecikmə;
- müştərinin orta gecikmə müddəti;
- korporativ və ya fərdi olması;
- əvvəl verilmiş güzəştlər;
- açıq texniki problem;
- artıq ödənilmiş, amma provider-dən gec gəlmiş ödəniş;
- VIP və sosial əhəmiyyətli obyekt;
- müştərinin churn riski.

Mümkün qərarlar:

- xatırlatma SMS-i;
- güzəşt müddəti;
- operator geri zəngi;
- sürətin müvəqqəti məhdudlaşdırılması;
- tam dayandırma;
- borcun hissə-hissə ödənilməsi;
- rəhbər təsdiqi tələb edən istisna.

Dayandırma əməliyyatı RADIUS statusu ilə yoxlanılmalı, aktiv sessiya varsa CoA/Disconnect göndərilməli və əməliyyatın real nəticəsi təsdiqlənməlidir.

---

# 5. FreeRADIUS, PPPoE və CGNAT sistemi

## 5.1 FreeRADIUS-un rolu

FreeRADIUS billing məlumatını saxlayan əsas sistem olmamalıdır. Billing əsas həqiqət mənbəyidir, RADIUS isə şəbəkəyə giriş qərarını icra edir.

Əsas məlumatlar:

- `radcheck` — istifadəçi autentifikasiyası və parol;
- `radreply` — IP, pool, sürət və digər cavab atributları;
- `radacct` — sessiya və trafik məlumatları;
- `nas` — router və NAS cihazları;
- paket/RADIUS atribut xəritəsi;
- statik IP və dinamik pool;
- aktiv və deaktiv status.

RADIUS servisi aşağıdakı əməliyyatları təmin etməlidir:

- istifadəçi yarat;
- paket dəyiş;
- parol dəyiş;
- statik IP təyin et;
- hesabı aktivləşdir;
- hesabı deaktivləşdir;
- CoA göndər;
- sessiyanı kəs;
- sessiyanı və verilmiş IP-ni oxu;
- nəticəni billing sisteminə qaytar.

## 5.2 Billing və RADIUS arasında düzgün sərhəd

Billing deyir:

> “Bu müştərinin xidmət almaq hüququ var, paketi budur, sürəti budur.”

RADIUS deyir:

> “Bu hüququ router üzərində tətbiq etdim və ya tətbiq edə bilmədim.”

Router deyir:

> “Müştəri real olaraq qoşulub, bu IP-ni alıb və bu qədər trafik istifadə edib.”

Bu üç vəziyyət ayrılıqda saxlanmalıdır:

- biznes statusu;
- RADIUS konfiqurasiya statusu;
- real şəbəkə sessiya statusu.

Məsələn:

| Billing | RADIUS | Sessiya | Nəticə |
|---|---|---|---|
| Aktiv | Aktiv | Aktiv | Normal |
| Aktiv | Aktiv | Yoxdur | Müştəri cihazı sönülü və ya texniki problem |
| Aktiv | Uğursuz | Yoxdur | Sistem inteqrasiya problemi |
| Deaktiv | Aktiv | Aktiv | Kritik uyğunsuzluq |
| Ödənilib | Deaktiv | Yoxdur | Təcili müdaxilə tələb olunur |

## 5.3 CGNAT və hüquqi jurnal

CGNAT jurnalı billing transaction cədvəlinə qarışdırılmamalıdır.

Ayrıca yüksək həcmli jurnal saxlanmalıdır:

- daxili müştəri IP-si;
- daxili port;
- xarici public IP;
- xarici port;
- destination IP və port;
- başlanğıc və bitmə vaxtı;
- NAS/router;
- müştəri və sessiya əlaqəsi;
- timezone və dəqiq timestamp.

Bu məlumat əməliyyat bazasını yükləməməlidir. Yüksək həcmli NAT və sessiya jurnalları ayrıca saxlama sisteminə yazılmalı, billing panelindən yalnız axtarış və qanuni export üçün çağırılmalıdır.

---

# 6. Şəbəkə, NOC və sahə əməliyyatları

## 6.1 Vahid NOC iş sahəsi

Hazırkı sistemdə NOC, MGS integration, device monitor, backup, RADIUS/IP/NAT və Operations Center girişlərinin bir-birini təkrarlaması müşahidə olunub. Audit qərarı bunların vahid `Şəbəkə və sahə` iş sahəsində birləşdirilməsidir.

Əsas görünüşlər:

### Canlı şəbəkə

- core router;
- uplink-lər;
- BGP və routing;
- trafik;
- CPU və memory;
- packet loss, latency və jitter;
- kritik interfeyslər;
- servis və server statusları.

### OLT/ONT və optik şəbəkə

- OLT-lər;
- PON portları;
- ONT sayı;
- online/offline ONT;
- RX/TX power;
- temperatur;
- məsafə;
- flap sayı;
- port tutumu;
- splitter və ərazi əlaqəsi.

### Müştəri diaqnostikası

Müştəri kodu, PPPoE username, IP, ONT serialı və ya MAC ilə axtarış:

- billing statusu;
- RADIUS statusu;
- aktiv sessiya;
- verilmiş IP;
- NAS;
- router interfeysi;
- OLT/PON/ONT;
- optik siqnal;
- trafik;
- son kəsilmələr;
- açıq ticket;
- mümkün səbəblər;
- tövsiyə olunan addımlar.

### Sahə və quraşdırma

- yeni qoşulmalar;
- texniki nasazlıqlar;
- texnik təyinatı;
- xəritə və marşrut;
- SLA;
- anbardan götürüləcək material;
- görülən işin sübutu;
- müştəri təsdiqi.

## 6.2 Root-cause mühərriki

Sistem hər offline ONT üçün ayrıca alarm açmamalıdır.

Məsələn, eyni PON-da 40 ONT bir anda offline olursa sistem:

- 40 ayrı problem yaratmamalı;
- ortaq PON, splitter, uplink və ya enerji problemini axtarmalı;
- bir əsas incident yaratmalı;
- 40 müştərini həmin incident-ə bağlamalı;
- təsir olunan aylıq gəliri hesablamalı;
- müştərilərə vahid məlumat göndərməli;
- problemi NOC və sahə komandasına yönəltməlidir.

Alarm correlation belə işləməlidir:

1. Hadisələri topla.
2. Eyni cihaz və ərazidə olanları qruplaşdır.
3. Parent-child topologiyanı yoxla.
4. Keçici hadisələri debounce et.
5. Root-cause ehtimalını hesabla.
6. Təsir olunan müştəriləri tap.
7. Prioritet təyin et.
8. Incident və iş əmri yarat.
9. Həll sonrası bütün bağlı hadisələri yoxla.

## 6.3 Digital twin

Şəbəkənin rəqəmsal modeli saxlanmalıdır:

`Müştəri → ONT → PON portu → OLT → uplink switch → aggregation → core router → internet`

Bu model imkan verəcək:

- cihaz sıradan çıxsa neçə müştərinin təsirlənəcəyini hesablamaq;
- texniki işdən əvvəl risk simulyasiyası;
- alternativ yolun olub-olmamasını görmək;
- yeni müştəri üçün tutum yoxlamaq;
- hansı əraziyə investisiya etməyin daha sərfəli olduğunu hesablamaq;
- problem zamanı müştərinin tam yolunu izləmək.

## 6.4 Şəbəkə əmrlərinin təhlükəsizliyi

AI və adi əməkdaşlar routerə sərbəst komanda göndərməməlidir.

Əməliyyat rejimləri:

1. **Observe only** — yalnız oxu.
2. **Suggest** — problemi və həlli təklif et.
3. **Supervised execute** — mühəndis təsdiqi ilə icra et.
4. **Autonomous low-risk** — yalnız əvvəlcədən təsdiqlənmiş aşağı riskli əməliyyatlar.

Core router üçün başlanğıc rejim `observe only` olmalıdır.

Aşağıdakı əməliyyatlar həmişə insan təsdiqi tələb etməlidir:

- BGP və routing dəyişiklikləri;
- NAT dəyişiklikləri;
- core interfeys əməliyyatları;
- böyük VLAN dəyişiklikləri;
- kütləvi ONT və ya müştəri əməliyyatları;
- config rollback;
- credential və cihaz əlavə edilməsi.

AI tərəfindən avtomatik edilə biləcək aşağı risk əməliyyatları:

- read-only diaqnostika;
- ticket yaratmaq;
- SMS draft hazırlamaq;
- təkrar alarmı birləşdirmək;
- test edilmiş halda ONT reboot;
- test edilmiş RADIUS CoA;
- log və backup yoxlaması.

Sistemin ilkin arxitekturasında da cihaz driver qatı, dry-run, manual approval, config versioning və rollback əsas prinsip kimi müəyyən edilmişdi.

---

# 7. Maliyyə sistemi

## 7.1 Əsas prinsip: vahid ledger

Maliyyə sistemi müxtəlif cədvəllərdə əlaqəsiz gəlir və xərc yazmamalıdır.

Bütün maliyyə əməliyyatları vahid ledger-dən keçməlidir:

- müştəri ödənişi;
- provider ödənişi;
- kassa mədaxili;
- bank köçürməsi;
- xərc;
- təchizatçı borcu;
- əməkhaqqı;
- bonus;
- əsas vəsait alışı;
- silinmə;
- düzəliş;
- geri qaytarma.

Hər ledger yazısında:

- əməliyyat növü;
- məbləğ;
- debit hesab;
- kredit hesab;
- filial;
- qarşı tərəf;
- sənəd;
- mənbə modul;
- mənbə transaction;
- yaradan şəxs;
- təsdiqləyən şəxs;
- status;
- tarix;
- reversal əlaqəsi olmalıdır.

Mövcud auditdə gəlir və xərcin uyğunsuz ledger-lərə yazılması, bəzi maliyyə səhifələrinin açılış zamanı schema və seed dəyişməsi, e-qaimə importunun atomik olmaması və asset write-off əməliyyatının kifayət qədər təsdiq mexanizminə malik olmaması aşkarlanıb.

## 7.2 Maliyyə iş axınları

### Gəlir və ödəniş

- günlük daxilolmalar;
- provider üzrə ödəniş;
- nağd ödəniş;
- bank daxilolması;
- qaytarılmış və naməlum ödəniş;
- billing reconciliation.

### Xərc və borc

- xərc sorğusu;
- rəhbər təsdiqi;
- ödəniş;
- sənəd və qəbz;
- filial və xərc mərkəzi;
- büdcə ilə müqayisə.

### Kassa və bank

- kassanın açılması;
- kassir növbəsi;
- mədaxil və məxaric;
- gün sonu bağlanış;
- faktiki pul və sistem qalığı;
- uyğunsuzluğun araşdırılması.

### Qarşı tərəf və qaimə

- təchizatçı;
- müqavilə;
- e-qaimə importu;
- sətir-sətir nəticə;
- anbar mədaxili ilə əlaqə;
- borc və ödəniş.

### Əsas vəsait

- server, router, avtomobil, cihaz;
- alış dəyəri;
- amortizasiya;
- məsul əməkdaş;
- yerləşmə;
- servis xərci;
- silinmə və satış.

## 7.3 Maliyyə AI agenti

Maliyyə agenti:

- pul yazmamalı, əvvəlcə analiz etməlidir;
- kassa-bank fərqlərini tapmalıdır;
- duplicate və anormal ödənişləri aşkar etməlidir;
- provider reconciliation aparmalıdır;
- gecikmiş borcları qruplaşdırmalıdır;
- xərc artımının səbəbini göstərməlidir;
- büdcə və faktı müqayisə etməlidir;
- 30, 60 və 90 günlük cash-flow proqnozu verməlidir;
- təsdiq üçün ödəniş və xərc paketləri hazırlamalıdır.

Maliyyə agenti birbaşa ledger-ə SQL yazmamalıdır. Yalnız təsdiqlənmiş maliyyə service-lərini çağırmalıdır.

---

# 8. Anbar və aktivlərin idarə edilməsi

## 8.1 Anbarın əsas quruluşu

Anbar modulu aşağıdakı obyektləri ayırmalıdır:

- serial nömrəli cihazlar;
- miqdarla saxlanan materiallar;
- metr ilə saxlanan kabel;
- əsas vəsait;
- müştəriyə verilmiş avadanlıq;
- texnikin üzərində olan avadanlıq;
- təmir və RMA-da olan cihaz.

## 8.2 Tam həyat dövrü

Hər cihazın həyat dövrü:

`Sifariş → Mədaxil → Anbar → Texnikə təhvil → Müştəriyə quraşdırma → İstifadə → Geri qaytarma → Yoxlama → Yenidən istifadə / Təmir / RMA / Silinmə`

Heç bir cihaz və ya material “sadəcə yoxa çıxmamalıdır”.

Modem üçün:

- marka və model;
- serial nömrəsi;
- MAC;
- alış qiyməti;
- təchizatçı;
- zəmanət;
- anbar;
- texnik;
- müştəri;
- quraşdırılma tarixi;
- geri qaytarılma tarixi;
- vəziyyət;
- təmir tarixçəsi.

Kabel üçün:

- baraban nömrəsi;
- kabel növü;
- başlanğıc metr;
- texnikə verilən metr;
- işdə istifadə edilən metr;
- geri qaytarılan metr;
- faktiki qalıq;
- fərq;
- istifadə edilən müştəri və layihələr.

Mövcud anbar axınlarında core ledger əməliyyatlarının bir hissəsi mövcuddur, ancaq repair/RMA və inspection zəncirinin tam E2E olmadığı auditdə göstərilib. Buna görə bu sahə tamamlanmadan “işləkdir” kimi qiymətləndirilməməlidir.

## 8.3 Texnik və anbar əlaqəsi

Texnikə iş açıldıqda sistem avtomatik material ehtiyacını hazırlamalıdır:

- modem;
- adaptor;
- fast connector;
- kabel;
- patch cord;
- qutu və digər material.

Anbardar materialı verir, texnik mobil cihazdan qəbul edir. İş tamamlandıqda:

- istifadə edilən material;
- istifadə edilməyən və geri qaytarılan material;
- müştəriyə bağlanan cihaz;
- foto və ya sübut;
- müştəri təsdiqi qeyd olunur.

Texnikin stok qalığı həmişə görünməlidir.

## 8.4 Anbar AI agenti

Anbar agenti:

- itən və gec qaytarılan avadanlığı tapır;
- normadan artıq kabel və connector istifadəsini aşkar edir;
- aşağı stok səviyyəsini xəbər verir;
- alış ehtiyacını proqnozlaşdırır;
- təmirə gedən cihazların dövr müddətini izləyir;
- hər model üzrə nasazlıq faizini hesablayır;
- təchizatçı keyfiyyətini müqayisə edir;
- istifadə olunmayan aktivləri göstərir;
- anbar fərqləri üçün yoxlama tapşırığı açır.

---

# 9. HR və əməkdaş idarəetməsi

## 9.1 HR və sistem hesabı ayrılmalıdır

HR əməkdaşın iş profilini yaradır:

- şəxsi məlumat;
- vəzifə;
- şöbə;
- filial;
- rəhbər;
- işə başlama tarixi;
- əməkhaqqı;
- iş qrafiki;
- məzuniyyət;
- sənədlər.

Admin isə:

- sistem istifadəçi hesabı yaradır;
- rol verir;
- modul və əməliyyat icazəsi verir;
- MFA və giriş təhlükəsizliyini idarə edir.

HR əməkdaşa özbaşına super-admin və ya maliyyə səlahiyyəti verməməlidir. Bu sərhəd mövcud auditdə də düzgün qərar kimi saxlanılıb.

## 9.2 Əməkdaş performansı

Bütün əməkdaşlar eyni KPI ilə qiymətləndirilə bilməz.

### Operator KPI-ləri

- ilk cavab müddəti;
- həll edilən müraciət;
- düzgün yönləndirmə;
- məcburi geri dönüşlərin icrası;
- müştəri məmnunluğu;
- təkrar müraciət;
- yanlış və ya natamam qeyd.

### Texnik KPI-ləri

- vaxtında çatma;
- ilk səfərdə həll;
- təkrar nasazlıq;
- istifadə edilən material;
- quraşdırma keyfiyyəti;
- müştəri qiyməti;
- SLA;
- eyni ərazidə marşrut effektivliyi.

### Anbardar KPI-ləri

- stok dəqiqliyi;
- təhvil müddəti;
- sənədsiz hərəkət;
- itki;
- geri qaytarma və inspection müddəti;
- inventar fərqi.

### Maliyyə KPI-ləri

- reconciliation fərqi;
- bağlanış müddəti;
- səhv transaction;
- gecikmiş təsdiq;
- sənədsiz xərc;
- cash-flow proqnoz dəqiqliyi.

### NOC KPI-ləri

- mean time to detect;
- mean time to acknowledge;
- mean time to resolve;
- false-positive alarm;
- root-cause dəqiqliyi;
- təkrar incident;
- planlaşdırılmamış downtime;
- config dəyişikliyindən yaranan problem.

İlkin HR/KPI modelində ticket resolution, first response, false-positive, customer satisfaction və repeat issue kimi göstəricilər nəzərdə tutulmuşdu. Tətildə olan əməkdaşa iş verilməməsi və məzuniyyət dövrünün KPI-dən çıxarılması da düzgün prinsipdir.

## 9.3 Ədalətli KPI qaydaları

Sistem yalnız nəticəni deyil, şəraiti də nəzərə almalıdır:

- işçiyə verilməyən tapşırıq onun əleyhinə hesablanmır;
- başqa şöbədən asılı gecikmə ayrılır;
- müştərinin cavab verməməsi ayrıca statusdur;
- kütləvi şəbəkə incident-i texnikin şəxsi nasazlığı sayılmır;
- məzuniyyət və xəstəlik dövrü çıxılır;
- rəhbərin manual dəyişiklikləri audit olunur;
- işçinin KPI-yə etiraz mexanizmi olur.

## 9.4 HR AI agenti

HR agenti:

- iş yükünü müqayisə edir;
- həddindən artıq və az yüklənən əməkdaşı göstərir;
- performans trendini analiz edir;
- təlim ehtiyacını tapır;
- davamiyyət anomaliyalarını göstərir;
- rəhbər üçün aylıq performans xülasəsi hazırlayır;
- bonus hesablamasını izah edir;
- işdən ayrılma riskini yalnız əsaslandırılmış göstəricilərlə bildirir.

AI əməkdaş haqqında gizli və sübutsuz psixoloji nəticə çıxarmamalıdır.

---

# 10. Operator, müraciət və müştəri təcrübəsi sistemi

## 10.1 Case və Work Order ayrılmalıdır

Müştərinin müraciəti `Case`, texniki icra isə `Work Order` olmalıdır.

Müştəri görür:

- müraciət nömrəsi;
- status;
- növbəti məlumat vaxtı;
- məsul şöbə;
- gözlənilən həll müddəti.

Daxildə görünür:

- təyin edilmiş texnik;
- texniki diaqnostika;
- istifadə olunan material;
- daxili qeydlər;
- eskalasiya;
- root-cause.

Bu yanaşma müştərinin şəxsi texnik nömrəsindən asılılığını azaldır və şirkət daxilində məsuliyyəti saxlayır.

## 10.2 Məcburi geri dönüş mühərriki

Sistem avtomatik olaraq geri dönüş tapşırıqları yaratmalıdır:

- yeni qoşulmadan 3 gün sonra;
- 14 gün sonra;
- 30 gün sonra;
- 90 gün sonra;
- texniki iş bağlandıqdan sonra;
- cavabsız zəngdən sonra;
- aşağı qiymətləndirmədən sonra;
- təkrar nasazlıqdan sonra;
- borca görə dayandırmadan əvvəl;
- churn niyyəti bildirildikdə.

Operator yalnız “danışdım” yaza bilməməlidir. Strukturlaşdırılmış nəticə seçməlidir:

- problem həll olunub;
- problem davam edir;
- müştəri cavab vermədi;
- yenidən əlaqə vaxtı;
- texniki iş tələb olunur;
- kommersiya təklifi tələb olunur;
- şikayət;
- xidmətin bağlanması niyyəti.

## 10.3 Ticket bağlanması

Texnik işi tamamladı deyə müştəri müraciəti avtomatik bağlanmamalıdır.

Bağlanma şərtləri:

- texniki nəticə qeyd olunub;
- material hərəkəti tamamlanıb;
- şəbəkə xidməti yoxlanılıb;
- müştəriyə məlumat verilib;
- müştəri təsdiq edib və ya əlaqə cəhdləri qaydaya uyğun tamamlanıb;
- təkrar problem yoxlaması aparılıb.

---

# 11. SMS sistemi

SMS ayrıca sadə “mesaj göndər” səhifəsi deyil, notification platforması olmalıdır.

## 11.1 SMS növləri

### Transactional

- xoş gəldiniz;
- istifadəçi adı və aktivləşmə;
- ödəniş qəbul edildi;
- borc xatırlatması;
- xidmət dayandırıldı;
- xidmət aktivləşdirildi;
- müraciət açıldı;
- texnik təyin edildi;
- texniki iş tamamlandı.

### Operational

- ərazi üzrə nasazlıq;
- planlı texniki iş;
- xidmətin bərpa olunması;
- təhlükəsizlik bildirişi.

### Marketing

- yeni paket;
- kampaniya;
- referral;
- sürət yüksəltmə;
- əlavə xidmət.

Transactional və marketing razılıqları ayrı saxlanmalıdır.

## 11.2 SMS gateway idarəsi

Sistem:

- bir neçə provider;
- failover;
- delivery status;
- retry;
- template version;
- opt-out;
- göndəriş limiti;
- dublikat qoruması;
- xərc hesabatı;
- kampaniya nəticəsi saxlamalıdır.

AI göndəriləcək mesajı hazırlaya bilər, amma böyük kampaniya rəhbər təsdiqi olmadan göndərilməməlidir.

---

# 12. WhatsApp monitorinqi və əməkdaş yazışmalarının analizi

## 12.1 Məqsəd

WhatsApp sistemi əməkdaşların şəxsi həyatını izləmək üçün deyil. Yalnız şirkətə məxsus korporativ nömrələrdə:

- müştəri mesajlarının cavabsız qalmamasını;
- cavab keyfiyyətini;
- gecikməni;
- şikayətləri;
- satış fürsətlərini;
- operator iş yükünü;
- müştəri tarixçəsini idarə etməlidir.

Şəxsi nömrə və şəxsi yazışma monitorinqi sistemə daxil edilməməlidir.

## 12.2 Vahid Canlı Monitor

Hər korporativ nömrə kart şəklində göstərilir:

- nömrə və məsul əməkdaş;
- qoşulub/ayrılıb statusu;
- son heartbeat;
- son aktivlik;
- açıq chat sayı;
- cavabsız mesaj;
- orta cavab müddəti;
- kritik müştəri;
- kart daxilində son mesajlar;
- genişlənən chat tarixçəsi.

Hazırkı auditdə Message Center və WhatsApp Monitor daxilində canlı monitor, chat, audit, performance və settings görünüşlərinin parçalandığı göstərilib. Düzgün qərar bunları bir Mesaj Mərkəzində birləşdirməkdir.

## 12.3 WhatsApp AI analizi

AI aşağıdakıları analiz edə bilər:

- cavabsız mesaj;
- uzun cavab gecikməsi;
- kobud və ya qeyri-peşəkar cavab;
- müştərinin əsəbi olması;
- təkrar şikayət;
- xidmət bağlama niyyəti;
- satış və paket yüksəltmə fürsəti;
- texniki problem;
- operatorun səhv məlumat verməsi;
- həll olunmadan bağlanan söhbət.

AI chatı özü “həll olunub” bağlamamalıdır. Sübut və nəticə tələb etməlidir.

## 12.4 Heartbeat və real status

WhatsApp bridge üçün:

- daimi heartbeat;
- son uğurlu mesaj;
- son error;
- queue sayı;
- session status;
- QR pairing statusu;
- stale timeout;
- avtomatik restart;
- admin alert olmalıdır.

Mövcud auditdə davamlı worker heartbeat-in olmadığı və boş tarixçədə production `TEST MESAJI` yarana bildiyi göstərilib. Bunlar production-a çıxmazdan əvvəl aradan qaldırılmalıdır.

---

# 13. Müştəri paneli

Müştəri paneli sadə, mobil və aydın olmalıdır.

## 13.1 Ana səhifə

- xidmət aktiv/deaktiv;
- cari paket;
- balans və borc;
- növbəti ödəniş tarixi;
- sürətli ödəniş;
- aktiv müraciət;
- ərazi üzrə nasazlıq;
- son bildirişlər.

## 13.2 Panel bölmələri

### Xidmətim

- paket;
- sürət;
- aylıq ödəniş;
- müqavilə;
- xidmət ünvanı;
- statik IP və əlavə xidmətlər.

### Ödənişlər

- online ödəniş;
- ödəniş tarixçəsi;
- qəbz;
- borc;
- bonus;
- avtomatik ödəniş imkanı.

### Dəstək

- yeni müraciət;
- müraciət statusu;
- foto əlavə etmək;
- uyğun vaxt seçmək;
- texnikin gəliş intervalı;
- görülən işə qiymət vermək.

### Şəbəkə

Müştəriyə həddindən artıq texniki məlumat verilməməlidir:

- modem online/offline;
- son bağlantı;
- xidmət problemi var/yox;
- ərazi nasazlığı;
- sadə diaqnostika;
- Wi-Fi tövsiyələri.

### Bildirişlər

- SMS və WhatsApp razılığı;
- planlı iş;
- kampaniya;
- xidmət və ödəniş bildirişləri.

## 13.3 Müştəri panelində təhlükəsizlik

- OTP və ya təhlükəsiz login;
- sessiya nəzarəti;
- həssas məlumatın maskalanması;
- ödəniş və profil dəyişikliyi üçün əlavə təsdiq;
- cihaz və giriş tarixçəsi;
- şəxsi məlumat export və düzəliş prosesi.

---

# 14. Statistika və qərar dəstəyi

## 14.1 Vahid hesablama mühərriki

Ən vacib qayda:

> Eyni KPI yalnız bir yerdə hesablanmalıdır.

Hazırkı sistemdə statistikanın Billing, Maliyyə, HR, Anbar, WhatsApp, problem statistikası və qlobal statistikada təkrarlandığı müəyyən edilib. Vahid `statistics-center` bütün modullar üçün canonical hesablama mənbəyi olmalıdır.

Hər KPI üçün aşağıdakılar yazılı şəkildə müəyyən edilməlidir:

- adı;
- biznes mənası;
- formula;
- məlumat mənbəyi;
- tarix sahəsi;
- timezone;
- daxil edilən və çıxarılan statuslar;
- refresh müddəti;
- məsul sahibi;
- versiyası.

## 14.2 CEO göstəriciləri

- MRR və toplama faizi;
- aktiv müştəri;
- yeni qoşulma;
- ayrılan müştəri;
- churn;
- ARPU;
- borclu müştəri;
- satış kanalı;
- filial və ərazi gəlirliliyi;
- şəbəkə uptime;
- problemli PON və ərazi;
- texnik məhsuldarlığı;
- material və avadanlıq itkisi;
- cash-flow;
- EBITDA-ya yaxın əməliyyat göstəricisi;
- 30/90/365 günlük proqnoz.

## 14.3 Şəbəkə göstəriciləri

- peak və orta trafik;
- uplink utilization;
- packet loss;
- latency;
- BGP və interface stability;
- offline ONT;
- optical degradation;
- PON tutumu;
- PPPoE reconnect;
- RADIUS reject;
- DNS və servis sağlamlığı;
- hər incident-in müştəri və gəlir təsiri.

## 14.4 Müştəri göstəriciləri

- qoşulma müddəti;
- ilk 30 gündə problem;
- müraciət sayı;
- repeat issue;
- NPS/CSAT;
- churn riski;
- paket yüksəltmə ehtimalı;
- ödəniş intizamı;
- ərazi və texnologiya üzrə keyfiyyət.

---

# 15. Qayda və siyasət mühərriki

Şirkət qaydaları kod daxilində dağınıq `if` şərtləri olmamalıdır.

İdarəetmə panelində dəyişdirilə bilən qaydalar olmalıdır:

- borc və grace period;
- xidmət dayandırma qaydası;
- SMS vaxtı;
- ticket SLA;
- eskalasiya;
- texnik təyinatı;
- bonus;
- anbar limitləri;
- təsdiq məbləği;
- kampaniya eligibility;
- AI əməliyyat icazələri;
- alert threshold.

Hər qayda üçün:

- versiya;
- qüvvəyə minmə tarixi;
- dəyişdirən şəxs;
- əvvəlki və yeni dəyər;
- səbəb;
- təsir preview;
- rollback olmalıdır.

---

# 16. AI agent təşkilatı

## 16.1 Super Pilot — mərkəzi orkestrator

Super Pilot istifadəçinin yazdığı əmri anlayır, amma özü hər işi icra etmir.

Məsələn:

> “Bu ay ödəniş etməyən, son üç ayda yaxşı ödəniş tarixçəsi olan müştəriləri tap, operatorlara geri zəng tapşırığı aç və nəticəni mənə göstər.”

Super Pilot:

1. İstifadəçinin rolunu və icazəsini yoxlayır.
2. Billing agentdən müştəri siyahısı istəyir.
3. Maliyyə agentdən ödəniş meyarını yoxlatdırır.
4. CRM agentdən mövcud müraciətləri yoxlatdırır.
5. Tapşırıq yaradılmasının təsirini preview edir.
6. Təsdiq tələb olunursa gözləyir.
7. Task service ilə tapşırıqları yaradır.
8. Nəticəni yenidən oxuyur.
9. Audit yazır.
10. Sonradan icra vəziyyətini izləyir.

Super Pilot yeni SQL yazmamalıdır. Mövcud service və API-ləri çağırmalıdır.

## 16.2 CEO Strategiya agenti

CEO agentinin işi:

- bütün şöbələrin nəticəsini birləşdirmək;
- əsas riskləri prioritetləşdirmək;
- gəlir, xərc, şəbəkə və insan resursu əlaqəsini göstərmək;
- böyümə ssenariləri hazırlamaq;
- filial və ərazi gəlirliliyini müqayisə etmək;
- investisiya prioriteti vermək;
- rəhbər qərarlarının nəticəsini sonradan ölçmək.

CEO agenti şöbələrin işini özü icra etmir. Digər agentlərə analiz tapşırığı verir və yekun qərar paketi hazırlayır.

## 16.3 Maliyyə agenti

- gəlir və xərci analiz edir;
- reconciliation aparır;
- anormal əməliyyatı tapır;
- cash-flow proqnozu verir;
- büdcə aşımını göstərir;
- təsdiq paketi hazırlayır;
- nəticəni maliyyə rəhbərinə təqdim edir.

## 16.4 HR agenti

- əməkdaş yükünü və performansını analiz edir;
- təlim ehtiyacı müəyyən edir;
- bonus hesabatı hazırlayır;
- davamiyyət anomaliyalarını göstərir;
- şöbə rəhbərinə fəaliyyət planı təqdim edir.

## 16.5 Network/NOC agenti

- şəbəkəni monitorinq edir;
- alarm correlation aparır;
- root-cause ehtimalını müəyyən edir;
- təsir olunan müştəriləri tapır;
- diaqnostika əmrlərini işlədir;
- həll planı və dry-run hazırlayır;
- mühəndis təsdiqindən sonra aşağı riskli əməliyyatı icra edir;
- nəticəni yoxlayır;
- postmortem hazırlayır.

## 16.6 Billing və gəlir agenti

- borclu müştəriləri segmentləşdirir;
- yanlış balans və RADIUS uyğunsuzluğunu tapır;
- provider ödənişlərini yoxlayır;
- paket gəlirliliyini analiz edir;
- churn və retention siyahısı hazırlayır;
- operatorlara geri dönüş tapşırığı yönəldir.

## 16.7 Anbar agenti

- stok və hərəkətləri analiz edir;
- alış ehtiyacını proqnozlaşdırır;
- istifadə norması pozuntularını tapır;
- avadanlıq itkilərini göstərir;
- texnik üzərində gecikən materialları izləyir;
- inspection və inventar tapşırığı yaradır.

## 16.8 Customer Experience agenti

- ticket və yazışmaları analiz edir;
- cavabsız müştəriləri tapır;
- müştəri narazılığını aşkar edir;
- operatora cavab və fəaliyyət təklif edir;
- məcburi geri dönüş tapşırıqları yaradır;
- həll keyfiyyətini yoxlayır.

## 16.9 Texnik və sahə agenti

- texniki işi prioritetləşdirir;
- yaxın ərazilərə görə marşrut qurur;
- material ehtiyacını hazırlayır;
- əvvəlki nasazlıqları göstərir;
- texnikin checklist-i tamamlamasını tələb edir;
- iş tamamlandıqdan sonra şəbəkə və müştəri təsdiqini yoxlayır.

## 16.10 Marketing və Growth agenti

- yeni müştəri mənbələrini müqayisə edir;
- kampaniya ROI-sini ölçür;
- paket yüksəltmə potensialını tapır;
- referral proqramını idarə edir;
- ərazi üzrə satış potensialını hesablayır;
- şəbəkə tutumu ilə satış kampaniyasını uyğunlaşdırır.

Marketinq agenti şəbəkədə tutum olmayan əraziyə kampaniya açmamalıdır.

## 16.11 Security və Compliance agenti

- səlahiyyətsiz girişləri;
- qeyri-adi export-u;
- təhlükəli router əmrlərini;
- credential risklərini;
- kütləvi məlumat dəyişikliklərini;
- şübhəli ödənişləri;
- audit boşluqlarını aşkarlayır.

## 16.12 Data və keyfiyyət agenti

- KPI-lərin düzgün hesablandığını yoxlayır;
- boş və ziddiyyətli məlumatı tapır;
- mock və real datanı ayırır;
- dashboard nəticəsini mənbə query ilə müqayisə edir;
- agentlərin verdiyi cavabların sübutunu yoxlayır.

---

# 17. Agentlərin iş yönəltmə modeli

Agentlər əməkdaşı idarə edən görünməz rəhbər kimi yox, qaydalarla işləyən rəqəmsal əməliyyat sistemi kimi işləməlidir.

Hər agent tapşırığı belə yaratmalıdır:

1. **Səbəb:** Tapşırıq niyə yaradılıb?
2. **Sübut:** Hansı məlumat əsasında?
3. **Prioritet:** Kritik, yüksək, orta və ya aşağı.
4. **Məsul rol:** Operator, texnik, NOC və s.
5. **Məsul əməkdaş:** İş yükü, ərazi və bacarığa əsasən.
6. **SLA:** İlk cavab və tamamlanma vaxtı.
7. **Checklist:** Tamamlanma üçün tələb olunan addımlar.
8. **Nəticə:** Strukturlaşdırılmış yekun.
9. **Yoxlama:** Sistem və ya rəhbər tərəfindən verification.
10. **Eskalasiya:** Vaxtında görülməsə növbəti məsul şəxs.

Agent heç vaxt yalnız “bu problemə bax” yazmamalıdır.

Məsələn:

> PON 1/3-də son 10 dəqiqədə 27 ONT offline olub. OLT uplink normaldır, PON optical power kəskin dəyişib. Ehtimal olunan səbəb splitter və ya feeder kabel problemidir. 27 müştəri, təxminən X AZN aylıq gəlir təsirlənir. Sahə texniki qrupu üçün kritik iş yaradılıb. İlk cavab: 15 dəqiqə. Tələb olunan yoxlama: ODF, splitter, feeder signal və foto sübut.

---

# 18. AI əməliyyatlarının təhlükəsizlik qaydası

AI agent hər əməliyyatda bu zəncirdən keçməlidir:

1. Intent müəyyən edilir.
2. İstifadəçi və agent icazəsi yoxlanılır.
3. Məlumat mənbəyi göstərilir.
4. Əməliyyatın təsiri preview olunur.
5. Lazım olduqda insan təsdiqi alınır.
6. Mövcud service/API çağırılır.
7. Nəticə read-back ilə yoxlanılır.
8. Audit yazılır.
9. Uğursuzluqda compensation və ya reversal edilir.
10. Əlaqəli şəxsə nəticə bildirilir.

Audit planında da agentə ilkin olaraq yalnız read-only axtarış, summary, report preview, ticket icmalı və təsdiq gözləyən action draft-ları verilməsi; yalnız idempotent, auditli və permission-li servislərin write üçün açılması qərarı göstərilib.

AI aşağıdakıları etməməlidir:

- birbaşa DB-yə SQL yazmaq;
- təsdiqsiz pul əməliyyatı;
- təsdiqsiz əməkhaqqı və bonus ödənişi;
- core router config dəyişmək;
- istifadəçi rolunu dəyişmək;
- hard delete;
- sübutsuz işçi qiymətləndirməsi;
- real metric olmadan alarm;
- uğursuz əməliyyatı uğurlu göstərmək.

---

# 19. Audit, icazə və təhlükəsizlik

## 19.1 Service-level permission

Menyunun gizlədilməsi təhlükəsizlik deyil.

Hər əməliyyat backend service qatında yoxlanmalıdır:

- kim edir;
- hansı rol;
- hansı filial;
- hansı müştəri və obyekt;
- hansı əməliyyat;
- hansı məbləğ;
- təsdiq tələb olunurmu;
- həmin şəxs öz əməliyyatını təsdiq edə bilərmi.

Auditdə bəzi NOC write action-larında service-level icazə yoxlamalarının tam olmadığı aşkarlanıb.

## 19.2 Maker-checker

Aşağıdakı əməliyyatlarda yaradan və təsdiqləyən ayrı olmalıdır:

- böyük xərc;
- asset silinməsi;
- maaş ödənişi;
- kütləvi bonus;
- rol dəyişikliyi;
- router konfiqurasiyası;
- böyük kampaniya;
- borc silinməsi;
- kütləvi müştəri deaktivləşməsi.

## 19.3 Secret idarəsi

Router, OLT, provider, WhatsApp və digər şifrələr DB-də və config fayllarında plaintext saxlanmamalıdır.

İstifadə olunmalıdır:

- environment secret;
- encrypted secret store;
- secret reference;
- rotation;
- access audit;
- maskalanmış UI.

## 19.4 Audit jurnalı

Audit yazısı aşağıdakıları saxlamalıdır:

- actor;
- rol;
- IP və cihaz;
- action;
- obyekt;
- əvvəlki dəyər;
- yeni dəyər;
- səbəb;
- təsdiq edən;
- correlation ID;
- service nəticəsi;
- error;
- timestamp.

Hard delete əvəzinə:

- void;
- reverse;
- deactivate;
- archive;
- correction istifadə olunmalıdır.

---

# 20. Texniki arxitektura üzrə real tövsiyə

İlkin master planda çoxsaylı Laravel və FastAPI mikroservisləri, PostgreSQL, TimescaleDB, Redis, Vault və Qdrant nəzərdə tutulmuşdu.

Bu uzunmüddətli istiqamət doğrudur, amma hazırkı mərhələdə bütün sistemi eyni anda mikroservislərə bölmək risklidir.

Ən faydalı arxitektura:

## Core modular monolith

Bir tətbiq daxilində, ancaq sərt modul sərhədləri ilə:

- Customer/Billing;
- Finance;
- HR;
- Inventory;
- CRM;
- Permissions;
- Audit;
- Policy.

Hər modulun:

- controller;
- service;
- repository;
- domain model;
- permission;
- event;
- test qatı ayrılmalıdır.

## Ayrı işləməli servislər

### RADIUS service

- FreeRADIUS bridge;
- CoA;
- disconnect;
- retry;
- NAS health;
- reconciliation.

### Network/NOC service

- SSH/SNMP;
- device drivers;
- monitoring;
- alarm correlation;
- topology;
- diagnostics.

### Notification service

- SMS;
- WhatsApp;
- Telegram;
- queue;
- delivery tracking;
- provider failover.

### Analytics service

- yüksək həcmli statistika;
- time-series;
- CGNAT;
- dashboard aggregation;
- ML və proqnoz.

### AI orchestration service

- agent routing;
- tool allowlist;
- approval;
- context;
- audit;
- model usage.

## Event sistemi

Modullar arasında `outbox + queue` işləməlidir.

Məsələn:

- `payment.received`;
- `customer.activated`;
- `radius.sync.requested`;
- `radius.sync.completed`;
- `equipment.assigned`;
- `ticket.created`;
- `incident.detected`;
- `employee.task.overdue`;
- `notification.send.requested`.

Bu model sistemi bir-birinə bağlı böyük PHP funksiyalarından çıxaracaq.

---

# 21. Mövcud sistemin əsas kritik boşluqları

Production genişlənməsindən əvvəl aşağıdakılar bağlanmalıdır:

1. Ödəniş və RADIUS arasında idempotency və compensation.
2. Maliyyədə vahid ledger.
3. Maliyyə səhifəsinin açılış zamanı schema və seed dəyişməsinin dayandırılması.
4. HR → Maliyyə payroll E2E axınının düzəldilməsi.
5. Anbar repair/RMA və inspection zəncirinin tamamlanması.
6. NOC write əməliyyatlarında service-level icazə.
7. SSH və provider şifrələrinin plaintext saxlanmasının dayandırılması.
8. Real router, CoA, disconnect və OLT testləri.
9. WhatsApp daimi heartbeat.
10. Production mock və test mesajlarının silinməsi.
11. Statistika üçün vahid formula və tarix semantikası.
12. Bütün kritik əməliyyatlarda audit, reversal və verification.
13. Müştəri, ödəniş, statistika və cihaz səhifələrinin birləşdirilməsi.
14. AI agentlərin birbaşa DB və təhlükəli əməliyyatlardan ayrılması.

---

# 22. İcra mərhələləri

## Mərhələ 0 — Sistemi təhlükəsizləşdir

- secret-ləri köçür;
- permission boşluqlarını bağla;
- mock datanı production-dan çıxar;
- payment/RADIUS idempotency qur;
- backup və rollback yoxla;
- tam audit əlavə et.

## Mərhələ 1 — Maliyyə və məlumat bütövlüyü

- vahid ledger;
- provider reconciliation;
- HR-payroll inteqrasiyası;
- anbar movement ledger;
- canonical balance və debt formulası;
- statistika data dictionary.

## Mərhələ 2 — Vahid iş axınları

- rol əsaslı İş masası;
- vahid müştəri profili;
- vahid “Ödəniş al” composer-i;
- vahid NOC workbench;
- vahid Mesaj Mərkəzi;
- vahid hesabat mühərriki.

## Mərhələ 3 — Read-only AI

- axtarış;
- summary;
- analiz;
- diaqnostika;
- risk və tövsiyə;
- task draft;
- report generation.

## Mərhələ 4 — Supervised AI

- təsdiqlə ticket yaratmaq;
- təsdiqlə SMS göndərmək;
- təsdiqlə task assign etmək;
- təsdiqlə RADIUS CoA;
- təsdiqlə aşağı riskli cihaz əməliyyatı;
- nəticə verification.

## Mərhələ 5 — Aşağı riskli avtomatlaşdırma

Yalnız uzun müddət test edilmiş əməliyyatlar:

- alarm deduplication;
- məcburi geri dönüş task-ları;
- gecikmə eskalasiyası;
- notification retry;
- müəyyən RADIUS reconciliation;
- report və gündəlik xülasələr;
- aşağı riskli ONT və sessiya əməliyyatları.

## Mərhələ 6 — Şirkətin böyümə sistemi

- ərazi gəlirliliyi;
- şəbəkə tutumu və satış əlaqəsi;
- investisiya planı;
- churn və retention;
- marketinq ROI;
- workforce planning;
- filial müqayisəsi;
- 50.000 müştəri üçün capacity forecast.

---

# 23. Son ideal görünüş

SuperOptic MGT ideal şəkildə belə işləməlidir:

Müştəri ödəniş edir. Sistem ödənişi unikal transaction kimi qəbul edir, ledger-ə yazır, balansı yeniləyir, RADIUS-u aktivləşdirir, sessiyanı yoxlayır, qəbz və SMS göndərir. RADIUS əməliyyatı alınmasa pulu itirmir və duplicate yaratmır; avtomatik retry və iş tapşırığı yaradır.

Şəbəkədə problem yaranır. Sistem yüzlərlə alarm yaratmır, root-cause tapır, təsirlənən müştəriləri və gəliri hesablayır, NOC və texnikə düzgün tapşırıq verir, müştəriləri məlumatlandırır, həll sonrası nəticəni yoxlayır və postmortem yaradır.

Texnik işə gedir. Sistemdən marşrutunu, müştərinin tarixini və götürməli olduğu materialı görür. Material anbardan rəqəmsal təhvil verilir. İş sonunda cihaz, kabel, foto, ölçmə və müştəri təsdiqi qeyd edilir.

Operator bütün müştəri tarixçəsini bir profildə görür. Müştərini başqa səhifələrdə axtarmır. Sistem ona nə deməli və hansı addımı atmalı olduğunu göstərir, ancaq son qərarı və kommunikasiya məsuliyyətini operator saxlayır.

Maliyyə agenti pul axınını izləyir, uyğunsuzluqları tapır və proqnoz verir. HR agenti ədalətli performans və iş yükü analizi aparır. Anbar agenti itkiləri və alış ehtiyacını göstərir. NOC agenti şəbəkəni analiz edir. Marketing agenti yalnız şəbəkə tutumu olan, gəlirli ərazilərə kampaniya təklif edir.

CEO agenti bütün bunları birləşdirərək rəhbərə belə cavab verir:

> “Bu ay gəlir artıb, amma üç ərazidə churn yüksəlib. Əsas səbəb ödəniş deyil, optik siqnal və təkrar texniki müraciətlərdir. İki PON-da tutum riski var. Birinci əraziyə 8.000 AZN investisiya ilə təxminən 300 yeni müştəri potensialı yaranır. İkinci ərazidə isə əvvəlcə xidmət keyfiyyəti düzəldilmədən marketinq kampaniyası başlatmaq məqsədəuyğun deyil. Bu həftə üçün NOC, texniki və satış komandalarına tapşırıq planı hazırlanıb.”

Bu nöqtədə SuperOptic MGT artıq proqram deyil.

Bu, şirkətin:

- yaddaşı;
- nəzarət sistemi;
- iş bölgüsü;
- qərar mexanizmi;
- rəqəmsal rəhbərliyi;
- böyümə mühərriki olur.

## Yekun qərar

SuperOptic üçün ən mükəmməl sistem ən çox səhifəsi və ən çox AI agenti olan sistem deyil.

Ən mükəmməl sistem:

- bir məlumatı bir dəfə saxlayan;
- bir işi bir service ilə icra edən;
- hər əməliyyatı yoxlayan;
- səhvi gizlətməyən;
- pul və şəbəkə əməliyyatını təhlükəsiz edən;
- əməkdaşa düzgün tapşırıq verən;
- müştərini unutmayan;
- rəhbərə real vəziyyəti göstərən;
- AI-ni nəzarətsiz deyil, sübut və qaydalarla işlədən sistemdir.

SuperOptic-in qurduğu baza bu istiqamətə uyğundur. İndi əsas məsələ yeni funksiyalar əlavə etməkdən əvvəl mövcud funksiyaları vahid service, ledger, permission, workflow, event və audit arxitekturasına bağlamaqdır.