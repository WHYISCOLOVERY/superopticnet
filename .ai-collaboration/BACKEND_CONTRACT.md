# Backend contract - SAR-5 shell ve dil standarti

Status: `READY_FOR_FRONTEND`

## Render contract-i

- `operations-center.php` Maliyye, HR ve Anbar modul view-larini render edir.
- `billing-section-page.php` Billing modul view-ini render edir.
- Hedef entrypoint-ler `css/portal.css`-den sonra yeni
  `css/operations-modules.css` faylini yukleyecek.
- Partial endpointler eyni view-lari qaytarir; stylesheet esas sehifede bir defe yuklenir.

## Modul nav contract-i

`views/_module-group-nav.php` asagidaki deyisenleri qoruyur:

- `$moduleNavGroups`
- `$moduleNavActive`
- `$moduleNavAria`
- `$moduleNavName`
- `$moduleNavLinkAttribute`

`data-module-group-nav`, `data-module-nav-group`, `data-module-section` ve modul
uzre `data-finance-nav`, `data-hr-nav`, `data-inventory-nav` atributlari
deyismemelidir. Aktiv link ve `<details name>` davranisi qorunmalidir.

## Tehlukesizlik ve biznes qaydalari

- Rol/permission neticesine gore action-larin gorunmesi eyni qalir.
- Form POST, CSRF, action adlari ve URL parametrleri deyismir.
- Dil helper-i yalniz gorunen label qaytarir; DB key ve saxlanan deyerleri cevirmir.
- Filter/statistika submit ve AJAX intercept contract-lari qorunur.

## Responsive qebul

- 1280px ve 390px enlikde horizontal sehife overflow-u yoxdur.
- Modal panel viewport daxilinde qalir; yalniz modal body ehtiyac olduqda scroll edir.
- KPI `<strong>` metni parent qutudan dasmir.
- Basliq action-lari wrap edir ve klik sahesi minimum 40px qalir.
- Modul nav daxilinde ikinci horizontal scroll konteyneri yaranmir.
