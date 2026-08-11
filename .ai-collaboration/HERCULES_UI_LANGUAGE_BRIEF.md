# Hercules product brief - SAR-5

## Istifadeci problemi

Maliyye, HR, Anbar ve Billing emekdaslari eyni portalda ferqli shell, qarisiq dil,
uzun action setirleri ve mobilde daralan KPI/metn qurulusu ile qarsilasir. Esas
auditoriya az telimle sistemi istifade etmeli olan orta yasli emekdaslardir.

## En kicik tam is axini

1. Emekdas roluna uygun modula daxil olur.
2. Cari bolmeni basliq ve qrup naviqasiyasindan bir baxishda anlayir.
3. Esas emeliyyat basliqda gorunur; ikinci dereceli bolmeler qruplanir.
4. Filter ve cedvel neticesi sehife shell-ini itirmeden yenilenir.
5. Modal acilanda basliq, forma ve action viewport daxilinde qalir.

## Melumat ve icaze ferziyyeleri

- Movcud backend context ve permission source of truth-dur.
- Gorunmeyen action CSS ile gizledilmir; server rol qaydasi saxlanir.
- Gorunen label cevirmek DB/action key-ni deyismir.

## Veziyyetler

- Loading: netice paneli solgunlasir, shell yerinde qalir.
- Empty: sade basliq, qisa izah ve mumkundurse bir esas action.
- Error: texniki stack yox, istifadeci ucun hereket ede bilen mesaj.
- Approval: tesdiq gozleyen emeliyyat status metni ve rengle gosterilir.

## Qebul meyarlari

- Desktop 1280px ve mobil 390px-de sehife horizontal dasmir.
- Bir modul daxilinde ic-ice naviqasiya scroll-u yoxdur.
- KPI meblegi qutudan dasmir; action duymeleri ust-uste dusmur.
- Eyni action eyni soz ve vizual tona malikdir.
- Filter/statistika tam sehife reload etmir.

## Sahibliy

- Hercules: UX review ve qebul meyarlari.
- Claude: ayrilmis view/CSS implementasiyasi.
- Codex: contract, inteqrasiya, test ve final acceptance.
