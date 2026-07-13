# Backend contract

Status: `PENDING_ACTIVE_TASK`

Konkret modul secilenden sonra Codex bu faylda asagidakilari sabitlesdirmelidir:

- endpoint ve HTTP metodu;
- session, CSRF ve rol telebleri;
- request parametrleri ve server-side validation;
- ugurlu ve xeta JSON numuneleri;
- pagination, filter ve sort parametrleri;
- frontend-e verilen view-model deyisenleri;
- deyisdirilmemeli biznes qaydalari;
- empty, loading ve error hallari;
- audit log telebleri.

## Umumi JSON formati

Yeni AJAX endpoint yaradilarse standart format:

```json
{
  "ok": true,
  "data": {},
  "message": ""
}
```

Xeta formati:

```json
{
  "ok": false,
  "message": "Istifadeci ucun aydin xeta mesaji",
  "errors": {
    "field": "Sahe uzre xeta"
  }
}
```

Movcud endpoint ferqli contract istifade edirse, uygunlasdirma geriye uyumlulugu pozmamalidir.
