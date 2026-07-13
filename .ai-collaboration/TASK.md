# Aktiv tapsiriq

Status: `READY`

## Cari meqsed

WhatsApp Monitoring modulunda catlar, QR qosulmasi ve monitor ekranini sade,
aydın, suretli ve responsiv etmek. Movcud worker, audit, permission ve real-time
polling mentiqi qorunmalidir.

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
