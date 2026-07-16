# Aktiv tapşırıq

Status: `COMPLETE`

## Məqsəd

`SAR-5` birinci mərhələ: Maliyyə, HR, Anbar və Billing modullarının ortaq
shell quruluşunu sadə, responsive və eyni dil standartına keçirmək.

## Tamamlanan işlər

- Dörd modulun böyük inline CSS blokları `css/operations-modules.css` faylına
  çıxarıldı; ortaq qrup naviqasiyası NOC və Mesaj Mərkəzinə də qoşuldu.
- Başlıq, qrup naviqasiyası, KPI, filtr, cədvəl və modal responsive qaydaları
  eyniləşdirildi; uzun KPI məbləğləri qutudan daşmır.
- Modul daxilində təkrarlanan "Əsas portal" keçidləri və Anbarın təkrarlanan
  əməliyyat zolaqları çıxarıldı; əsas izahlı əməliyyat kartları saxlanıldı.
- Mobil Superpilot düyməsi sənəd axınına keçirildi və məzmunun üstünə düşmür.
- Mərkəzi tab/section adları və görünən statuslar düzgün Azərbaycan dilinə
  keçirildi; DB/action/query açarları dəyişmədi.
- Maliyyə xərcində əməkdaş siyahısı boş olsa belə "Məsul əməkdaş" sahəsi və
  aydın boş vəziyyət görünür.

## Müqavilə

`action`, `name`, `value`, URL parametrləri, DB status açarları, CSRF,
permission və AJAX contract-ları qorunub. Maliyyə, HR və Anbar statistika
naviqasiyası tam səhifə yenilənmədən işləyir.

## Yoxlama

- Modul vizual smoke: `11/11` keçdi (8 layout + 3 AJAX).
- Billing UI render smoke: `45/45` keçdi.
- Maliyyə, HR, Anbar və Billing separation smoke-ları keçdi.
- Tam sistem regresiyası: `130 pass`, `0 fail`, `0 timeout`, `4 skip`.
- Hesabat: `tmp/full-system-regression-20260716-184153.json`.

Skip-lər yalnız əvvəlcədən qəsdən dayandırılan canlı əməliyyatlardır: real
router CoA, OLT/ONT restart, ödənişli real SMS və Telegram live polling.
