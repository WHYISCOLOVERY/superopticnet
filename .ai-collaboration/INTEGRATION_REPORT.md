# İnteqrasiya hesabatı

Status: `COMPLETE`

## İnteqrasiya nəticəsi

- Çat seçimi və mesaj sorğuları `number_id + chat_id` ilə şirkət nömrəsinə görə izolyasiya olunur.
- İlkin açılışda son 300 mesaj xronoloji ardıcıllıqla göstərilir.
- Çat siyahısı, yeni mesajlar və QR statusu tam səhifə yenilənmədən AJAX polling ilə təzələnir.
- Tam boş monitor ilk mesaj gəldikdə səhifə yenilənmədən çat siyahısına keçir.
- Mobil görünüşdə siyahı və seçilmiş çat ayrı ekran kimi işləyir; lazımsız iç-içə scroll yoxdur.
- QR sessiya açarı və Worker ID əsas istifadəçi görünüşündən yığcam `Texniki məlumat` bölməsinə keçirilib.
- Worker token-i yalnız `X-WhatsApp-Monitor-Token` header-i ilə qəbul edilir.
- Mövcud session, rol scope-u, CSRF, audit və super admin idarəetmə qaydaları qorunub.

## Agent bölgüsü

- Codex: backend contract, API, DB scope, təhlükəsizlik inteqrasiyası və testlər.
- Claude Code: view, CSS, responsive quruluş, accessibility və Azərbaycan dili cilası.
- Son Codex inteqrasiyası: canlı status mətnləri, QR status label-ları və regressiya müqavilələri.

## Yoxlamalar

- PHP lint: WhatsApp kitabxanası, səhifə, view-lar, API-lər və test alətləri keçdi.
- JavaScript syntax: monitor skripti və Playwright smoke skripti keçdi.
- Funksional smoke: chat ingest/render, modul və WhatsApp guard keçdi.
- Bridge health: `http://127.0.0.1:4001/health` cavabı `ok` oldu.
- Vizual smoke: 6/6 desktop və mobil ssenari keçdi; horizontal overflow və console xətası yoxdur.
- Qalan səkkiz WhatsApp səhifəsi Claude ilə eyni dil və responsive standartına gətirildi.
- Ümumi baxış KPI-ləri qruplaşdırıldı; nömrə/cihaz formalarında texniki sahələr `Əlavə seçimlər` bölməsinə keçirildi.
- Uzun cədvəllər mobil ekranda label-li kartlara çevrildi; monitorinq qeydi action formaları minimum 44px klik sahəsi ilə yenidən quruldu.
- Vizual smoke: `22/22` desktop və mobil ssenari keçdi; horizontal overflow və console xətası yoxdur.
- Tam sistem regressiyası: `117 pass`, `0 fail`, `0 timeout`, `4 skip`.

## Qalan xarici asılılıqlar

- Real router CoA, real OLT/ONT restart, ödənişli SMS göndərişi və canlı Telegram polling təhlükəsizlik səbəbi ilə kontrollu skip edilib.
- Bu skip-lər WhatsApp Monitoring modulunun qəbul meyarlarına mane olmur.
