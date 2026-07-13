# Legacy Billing Analysis

Bu sened `billing.superoptic.net` kohne billing sisteminden cixarilan strukturu, lokal export/import veziyyetini ve SuperOptic MGS ucun novbeti inkisaf addimlarini saxlayir.

## Export Ve Gizlilik

- Esas musteri siyahisi: `data/legacy-billing/customers-base.json`
- Musteri profil detallari ve tarixceler: `data/legacy-billing/customers-profiles.jsonl`
- Export manifesti: `data/legacy-billing/export-manifest.json`
- Bu fayllar sexsi melumat saxladigi ucun `data/legacy-billing/` gitignore-a elave edilib.
- Chat cavablarinda FIN, telefon, sifre, qeyd ve musteri adlari kimi PII melumatlari gosterilmemelidir.

## Export Neticesi

- Kohne billing source musteri sayi: 5447
- Base export setri: 5447
- Profil export setri: 5447
- Unique profil kodu: 5447
- JSON parse error: 0
- Profil fetch error: 0
- Profil tables olan musteri: 5447
- Billing info olan musteri: 5447
- Lokal DB-de `legacy_billing_profile_sections` cedveli yaradildi.
- Full profil section importu: 5447 musteri / 54470 profil section cedveli.
- Profil sectionlari `portal_accounts.payload_json` icinde sisirdilmir; orada sadece yungul summary saxlanilir.

## Kohne Billingde Gorulen Faydali Axinlar

- PPPoE istifadeci siyahisi server-side pagination ile isleyir ve 5000+ musteride yuklenme problemi yaratmir.
- Musteri yaratma/redakte formasinda FIN, sexsiyyet vesiqesi seriyasi, unvan, zona, pool, IP tipi, avtomatik IP al, sifre yarat, sexsi panel sifresi, borc ayi, ayliq borc, stabil gun ve qeyd saheleri var.
- Musteri profilinde balans, qalan gun, upload/download, telefon, unvan, qeyd, RADIUS melumatlari, odenis tarixcesi, odenis cixarisi, musteri loglari, RADIUS loglari ve baglanti tarixcesi birlikde gosterilir.
- Profile elave xidmet kimi IPTV, SIP, Static IP ve Cable TV elave edile bilir.
- RADIUS aktiv listinde username, sifre, tarif, IP, MAC, VLAN, status, 24 saat trafik, sessiya sayi, son aktivlik ve baglantini kesme emeliyyati gorunur.
- RADIUS statistikasinda top download/upload, en cox disconnect olanlar, en cox sessiya acanlar, auth success/reject ve disconnect sebebleri var.
- NAT log sehifesinde source IP, destination IP, date ve port uzre axtaris nezerdedir.
- Definitions sehifesinde VLAN ve interface mapping saxlanilir.

## Bizim Sistemde Tamamlanmali Hisseler

- Musteri 360 profilinde kohne billingden gelen `old_billing.profile_tables` tab-larda oxunaqli gosterilmelidir: odenis tarixcesi, odenis cixarisi, musteri loglari, RADIUS loglari, baglanti tarixcesi ve login cehdleri.
- Musteri profilinde `Legacy melumat` paneli olmalidir: kohne billing kodu, FIN/seriya, kohne qeyd, kohne paket, borc ayi, stabil gun ve panel sifresi statusu.
- RADIUS aktiv listinde disconnect action hele tehlukesizlik ucun manual saxlanilib; merheleli RBAC + audit ile `session disconnect` duymesi elave edilmelidir.
- NAT log axtarisi bizim sistemde ayrica `RADIUS -> NAT log axtarisi` kimi quruldu; `radius_nat_logs` ve `radius_nat_log_nodes` indexed cedvellerinden axtaris edir, `tools/import-radius-nat-logs.php` ise CSV/syslog faylini dry-run/apply rejiminde ingest edir.
- Definitions/VLAN mapping `network-definitions.php` sehifesine kecirildi; VLAN ID, musteriler, cihaz IP/port, IP pool, hop/path ve command preview saxlanilir.
- SMS/WhatsApp/Telegram/App mesajlari ucun `communication_messages` queue prosessoru yaradildi: `tools/process-communication-queue.php` dry-run/apply rejiminde isleyir, provider config yoxdursa mesaji queued saxlayir.
- Single-pane emeliyyat paneli `operations-center.php` olaraq elave edildi: universal axtaris, musteri 360 linkleri, ticket/RADIUS/VLAN neticeleri, biznes hadiseleri ve modul hazirliq kartlari eyni sehifede gorunur.
- Musteri 360 profiline kontekst action paneli ve profilden birbasa ticket yaratma formu elave edildi; ticket yaradilandan sonra operator eyni profile qayidir.
- Kohne billingdeki top offender analitikasi bizim analitika sehifesinde kart/chart kimi daha aydin verilmelidir.
- Import tamligi ucun `tools/verify-legacy-billing-import.php` her migrationdan sonra isledilmelidir.
- Full legacy profil section importu ucun `tools/import-legacy-export-profiles.php --apply` isledilmelidir.

## Novbeti Icra Sirasi

1. Musteri profilinde legacy tab-lari aktiv saxlamaq ve dizaynini card/timeline formatina daha da sade etmek.
2. RADIUS statistikasinda top download/upload, disconnect ve session count kartlarini DB-den real gostermek.
3. NAT log sehifesi ucun UI skeleton + indexed search yaratmaq. Status: tamamlandi (`radius-natlog.php`).
4. VLAN/Definitions melumatlarini MPLS provisioning data modeli ile birlesdirmek. Status: plan/provisioning modeli tamamlandi (`network_vlan_definitions`, `network_vlan_path_hops`, `network-definitions.php`).
5. Kohne billingden gelen profil qeydlerini operator ucun axtarila bilen timeline formatina cevirmek.
6. Import verifier ve billing smoke testleri deployment check siyahisina elave etmek.
7. Provider API melumatlari gelen kimi SMS/WhatsApp endpointlerini queue worker configine qosmaq.
