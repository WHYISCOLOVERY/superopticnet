# SuperOptik Plugin Powered UI Proposals

Bu sened hazirda qosulu olan eklentileri SuperOptik MGS sisteminde real istifade planina cevirir. Meqsed yalniz yeni gorunus deyil: her modul daha aydin, daha suretli, rol esasli ve yoxlana bilen islemelidir.

## Canli Eklenti Statusu

| Eklenti | Status | SuperOptik ucun real istifade |
| --- | --- | --- |
| Statsig | Islek | Riskli UI ve modul deyisiklikleri gate ile acilacaq. Ilk gate-ler yaradilib: `mgs_new_module_shell`, `mgs_bonus_dashboard_v2`, `mgs_noc_scan_selectable_results`, `mgs_statistics_ajax_charts`, `mgs_data_analytics_report_embed`. |
| Linear | Islek | Plugin-enabled roadmap `SuperOptik Plugin Enabled Product System` layihesinde `SAR-5` - `SAR-9` meseleleri ile izlenir. |
| Data Analytics | Islek | `operations-center.php?section=reports` icinde Analytics Center baslayib; elave olaraq plugin usage report render edildi. |
| RewriteLy | Qismen islek | UI sozleri, bos state, xeta ve tesdiq mesajlari daha tebii Azerbaycan diline salinacaq. Tool diagnose/adapt edir, final sozluk lokal review ile sabitlenecek. |
| Teams | Islek | Profil yoxlandi. HR, maliyye, NOC ve approval bildirisleri ucun real kanal/chat hedefleri secilenden sonra istifade olunacaq. |
| Sales / Gmail | Plan hazir | Borclu musteri, passiv musteri, bitmekde olan xidmet ve korporativ follow-up ucun draft axinlari qurulacaq. Gonderis yalniz tesdiqle olacaq. |
| Adobe Express | Islek | Operator telimi, release elanlari ve modul qaydalari ucun template tapildi. |
| Figma | Input gozleyir | Real design-to-code ucun Figma file/node URL lazimdir. URL verilmeden fayl inspect etmek mumkun deyil. |
| Mobbin | Bloklanib | Paid plan teleb edir. Plan aktivlesmeden login/dashboard reference cekmek olmur. |
| Canva | Bloklanib | Fillable brand template ucun paid plan teleb edir. Adobe Express alternativ kimi islekdir. |

## Dizayn Qaydalari

1. Esas portal yalniz yeddi esas sahe gostermelidir: Maliyye, HR, Anbar, Billing, AI, Mesaj Merkezi, Statistika ve Sebeke.
2. Her modulda basliqlar yan-yana uzun button setri kimi yox, qruplasdirilmis modul nav kimi gorunmelidir.
3. Uzun formalar sehifede alt-alta yigilmeyecek; `Yarat`, `Duzelis et`, `Transfer et`, `Medaxil et` kimi emeliyyatlar modal ve ya drawer icinde acilacaq.
4. Cedveller default 50 setir gosterecek, qalanlari pagination ile acilacaq.
5. Filter ve statistika secimleri butun sehifeni reload etmeyecek; yalniz netice paneli yenilenecek.
6. KPI qutularinda reqemler dasmayacaq: compact money format, min/max width ve wrap qaydasi mecburi olacaq.
7. Rol esasli gorunus standart olacaq: emekdas yalniz oz roluna aid modul ve alt basliqlari gorecek.

## Dil Standarti

RewriteLy yoxlamasindan sonra UI dili ucun bu sade qaydani saxlayiriq:

- Primary action: `Yarat`, `Saxla`, `Tesdiqle`
- Secondary action: `Bax`, `Filterle`, `Ixrac et`, `Yenile`
- Riskli action: `Legv et`, `Sil`, `Geri qaytar`
- Status: `Aktiv`, `Passiv`, `Gozlemede`, `Tesdiqlenib`, `Reddedilib`, `Xetali`
- Empty state: `Melumat tapilmadi`, `Bu bolme ucun qeyd yoxdur`, `Filteri deyisib yeniden yoxlayin`
- Success message: `Qeyd saxlanildi`, `Melumat yenilendi`, `Emeliyyat tamamlandi`
- Error message: `Emeliyyat alinmadi`, `Melumat yoxlanilmadi`, `Icazeniz yoxdur`

Qarisik dil formasi saxlanilmayacaq: `Create`, `Update`, `Save`, `Analytics`, `Not configured` kimi sozler UI-da lokal alternativle evez olunacaq.

## Modul Uzre Teklifler

### Maliyye

- Tablar: `Umumi baxis`, `Gelirler`, `Odenisler`, `Sirketler`, `Xercler`, `Qaimeler`, `Statistika`.
- Gelir/xerc formu modalda acilacaq.
- Borclu oldugumuz ve bize borclu olan terefler ayrica reyestr olacaq.
- Sirket yaratmaq maliyye icinde aydin buttonla olacaq.
- Statistika yalniz maliyye datasi ile isleyecek, billing statistikasina qarismayacaq.

### HR

- Tablar: `Umumi baxis`, `Emekdaslar`, `Davamiyyet`, `Mezuniyyet ve ezamiyyet`, `Maas ve vergiler`, `Senetler`, `Performans`, `Statistika`.
- Emekdas yaratmaq modalda olacaq.
- Isci yaradanda rol avtomatik verilmeyecek; rolu yalniz admin/super admin verecek.
- Net/gross maas, qanuni vergi/xerc analizi, mezuniyyet pulu, ezamiyyet hesabi ve sicil bali eyni profil daxilinde gorunecek.

### Anbar

- Tablar: `Umumi baxis`, `Medaxil`, `Transfer`, `Geri qaytarma`, `Tehvil-teslim`, `Qaliq`, `Statistika`.
- `Mal medaxil et` esas action kimi gorunmelidir.
- Filiala transfer, geri qaytarma ve silinme eyni audit zenciri ile saxlanmalidir.
- Geri qaytarma senedinde avtomatik sened nomresi, statusa uygun basliq, mal sirasi, ad, miqdar, MAC/SN, olcu vahidi ve qiymet saxlanacaq.

### Billing

- Musteri yaradanda FIN kod, vesiqe kodu, unvan ve mobil nomre mecburi olacaq.
- Movcud musteride bu melumatlar yoxdursa profil icinden update olunacaq.
- Billing statistikasi umumi baxisla qarismayacaq; her basliq oz funksiyasina aid qalacaq.

### Sebeke / NOC

- IP scan neticesi birbasa monitor qatinda saxlanmayacaq.
- Scan neticesi modalda gosterilecek; emekdas lazim olan cihazi secib monitora elave edecek.
- Monitor profil ayarlari user profilinde saxlanacaq.
- Tam ekran rejimi olacaq.
- Cihaz status rengleri: online yasil, offline qirmizi, xeta orange.

### Mesaj Merkezi

- Kanal chartlari: SMS, Telegram, WhatsApp, app, email.
- Sales/Gmail/Teams axinlari ile borc, passiv musteri, renewal ve korporativ follow-up draftlari yaradacaq.
- Real gonderis ucun istifadeci tesdiqi mecburidir.

### Statistika

- Secimler: gelir, xerc, borc, musteri, terminal gelirleri, hamisi.
- `Hamisi` secilende bolmeler alt-alta ayrica basliqlarla gorunecek.
- Tarix araligi butun chartlara eyni qaydada tetbiq olunacaq.
- Netice AJAX ile yenilenecek, sehife tam reload olmayacaq.

## Eklenti Ile Icra Ardiciilligi

1. `SAR-5`: UI language and shell standard - RewriteLy + lokal UI audit.
2. `SAR-6`: Statsig rollout - butun riskli UI deyisiklikleri gate arxasina kecir.
3. `SAR-7`: Data Analytics - customer-code payer coverage, branch profitability, support workload.
4. `SAR-8`: Sales/Gmail/Teams - customer outreach ve daxili approval bildirisleri.
5. `SAR-9`: Google Drive - HR senedleri, qaimeler, export arxivi.
6. Figma - yalniz real design URL geldikden sonra inspect + code implement.
7. Mobbin/Canva - paid plan aktivlesse reference/template workflow-a daxil edilir.

## Qebul Meyarlari

- Full regression `0 fail` qalmalidir.
- Her yeni sehife ve ya partial refresh ucun smoke test yazilmalidir.
- Her maliyye, anbar, HR, billing ve customer deyisikliyi audit log yazmalidir.
- Rol qaydasi pozulmamalidir: emekdas ozune aid olmayan funksiyani gormemelidir.
- Real SMS/email/Teams gonderisi tesdiqsiz icra olunmamalidir.
