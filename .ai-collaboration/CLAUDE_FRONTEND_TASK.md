# Claude frontend tapsirigi - SAR-5

Status: `READY`

Evvelce `TASK.md`, `FILE_OWNERSHIP.md` ve `BACKEND_CONTRACT.md` oxu.

## Real tapsiriq

1. `views/finance/module.php`, `views/hr/module.php`,
   `views/inventory/module.php` ve `views/billing/module.php` daxilindeki boyuk
   inline `<style>` bloklarini yeni `css/operations-modules.css` faylina cixar.
2. Ortak qaydalari birlesdir; modul-spesifik class ve davranislari qoru.
3. `views/_module-group-nav.php` daxilindeki inline `<style>` blokunu da yeni
   stylesheet-e dasi.
4. Yalniz bu bes view faylinda gorunen basliq, izah, nav label ve aria metnlerini
   duzgun Azerbaycan diline sal. Texniki key/value/data atributlarini deyisme.
5. Basliq action-larinin wrap, KPI mebleglerinin overflow, modal viewport ve
   mobil nav davranisini tamamla.
6. Yeni framework, gradient, orb, boyuk radius ve agir animasiya elave etme.
7. Backend, DB, API, permission, CSRF ve biznes mentiqine toxunma.
8. Neticeni `.ai-collaboration/CLAUDE_FRONTEND_RESULT.md` faylinda yeni
   `SAR-5 shell kecidi` bolmesi ile qeyd et.

## Yoxlama

- Inline style bloklari hedef view-lardan cixmalidir.
- CSS brace balansi ve PHP syntax manual yoxlanmalidir.
- Deyisen butun fayllar netice hesabatinda sadalanmalidir.
