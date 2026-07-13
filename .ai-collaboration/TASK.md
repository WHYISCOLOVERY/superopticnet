# Aktiv tapsiriq

Status: `COMPLETE`

## Cari məqsəd

WhatsApp Monitoring modulunun qalan səhifələrini eyni sadə, aydın və responsive
standarta gətirmək: ümumi baxış, canlı monitor, nömrələr, cihazlar, case-lər,
performans, ayarlar və audit. Mövcud worker, audit, permission, POST/CSRF və
real-time məntiqi qorunmalıdır.

Ilkin Git checkpoint: `6d8838a` (`chore: create local baseline checkpoint`).

## Qebul meyarlari

- Eyni musteri iki sirket nomresi ile yazisanda mesajlar qarismamalidir.
- Cat secimi `number_id + chat_id` scope-u ile aparilmalidir.
- Ilkin acilisda en son 300 mesaj xronoloji ardicilliqla gorunmelidir.
- Cat siyahisi ve secilmis yazisma orta yasli emekdas ucun aydin olmalidir.
- QR statusu, gozleme, ugur ve xeta hallari aydin gosterilmelidir.
- Mobil ekranda ic-ice lazimsiz scroll ve metn dasmasi olmamalidir.
- AJAX polling tam sehife reload yaratmamalidir.
- Super admin idare edir; uygun admin/monitor rollari hamisini, diger emekdas yalniz oz melumatini gorur.
- Claude backend, API, DB ve permission mentiqini deyismemelidir.

## Hazirliq neticesi

- Codex backend, API, DB, tehlukesizlik, inteqrasiya ve yekun test sahibidir.
- Claude frontend, view, CSS, JavaScript, responsivlik ve accessibility sahibidir.
- Agentler eyni anda eyni fayli deyismemelidir.
- Mock melumat, dagidici DB emeliyyati ve tehlukeli Git emri qadagandir.
- Her real tapsiriqdan evvel bu faylda status `READY` edilmeli, modul, scope ve qebul meyarlari yazilmalidir.

## Scope xaricinde

- Monitor sehifesinden mesaj gondermek.
- WhatsApp worker protokolunu deyismek.
- Yeni mock melumat yaratmaq.
- Diger portal modullarini bu kecidde yeniden dizayn etmek.

## Yekun nəticə

- Qəbul meyarları tamamlandı.
- Hədəf funksional və vizual testlər keçdi.
- Təmiz tam sistem regressiyası: `109 pass`, `0 fail`, `0 timeout`, `4 skip`.
- Hesabat: `tmp/full-system-regression-20260713-082033.json`.

## Aktiv ikinci mərhələ qəbul meyarları

- Bütün görünən mətnlər təbii və düzgün Azərbaycan dilində olmalıdır.
- Əsas əməliyyatlar dərhal görünməli, ikinci dərəcəli texniki məlumat yığcam təqdim edilməlidir.
- Cədvəllər desktop-da skan edilə bilən, mobil ekranda daşmayan kart/sətir görünüşündə olmalıdır.
- Form input, select, textarea və düymələri üst-üstə düşməməli, tam enli mobil düzülüşə keçməlidir.
- Online, offline, warning, disabled və case statusları həm rəng, həm mətnlə seçilməlidir.
- Canlı monitorun grid və tam ekran davranışı qorunmalıdır.
- Mövcud field name, hidden input, action, URL parametri, endpoint və rol şərtləri dəyişməməlidir.
- Loading, empty, readonly və permission halları sadə istifadəçi dili ilə görünməlidir.
- Desktop və mobil vizual smoke, PHP lint və tam sistem regressiyası keçməlidir.

## İkinci mərhələ nəticəsi

- Ümumi baxış, canlı monitor, nömrələr, cihazlar, monitorinq qeydləri, performans, ayarlar və audit səhifələri tamamlandı.
- Form/action/field/data contract müqayisəsi keçdi; silinmiş və ya dəyişmiş backend atributu yoxdur.
- Desktop və mobil vizual smoke: `22/22`, overflow `0`, console xətası `0`.
- Təmiz tam sistem regressiyası: `117 pass`, `0 fail`, `0 timeout`, `4 skip`.
- Hesabat: `tmp/full-system-regression-20260713-090235.json`.
