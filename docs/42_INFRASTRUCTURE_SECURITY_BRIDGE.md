# Infrastructure security bridge

Tarix: 2026-09-14

## Məqsəd

`D:\superoptic-agents` agent sistemi mövcud read-only Cisco kollektorunun
sanitised statusunu, management giriş watcher delta-sını və public saytın
HTTP/TLS vəziyyətini vahid təhlükəsizlik snapshotunda korrelyasiya edir.
Nəticə `infrastructure_threat_triage` workflow-u ilə NOC/SOC agentlərinə
göstərilir.

## Siqnal axını

1. Router kollektorunun lokal `status` nəticəsi oxunur; router parolu və xam
   CLI çıxışı agent kontekstinə daxil olmur.
2. `sec-watch.jsonl`-də son iki nümunə müqayisə edilir. Router logunda qalan
   köhnə `LOGIN_FAILED` cəmi aktiv hücum sayılmır; yalnız təzə müsbət delta
   aktiv management hücumu siqnalı yaradır.
3. Router data-plane severity və kateqoriyaları BGP, RADIUS, PPPoE, NAT, QFP,
   PLIM və interfeys delta-ları ilə təsnif edilir. Aqreqat router sayğacı
   müştəri cihazında virusun sübutu deyil.
4. Sayt serverə çıxarılandan sonra public HTTP/TLS check aktivləşdirilir.
   Serverdə lokal access-log yolu verilərsə, beş dəqiqəlik pəncərədə request
   burst, şübhəli path və 401/403/429 sıxlığı hesablanır. Xam query və log
   məzmunu agentə ötürülmür.
5. High/critical hadisə fingerprint ilə deduplicate olunur, sahib/NOC
   Telegram kanalına göndərilir və Crew proposal növbəsinə düşür.

Proposal ingest server tərəfində evidence tələb edir, rollback və verification
metadata-sını əlavə edir və modelin göndərdiyi executable `next_action` dəyərini
daima `manual_review` kimi saxlayır. Beləliklə köhnə producer belə maker-checker
qapısını keçə bilmir.

## Təhlükəsizlik sərhədi

- Monitor router, OLT, WAF, DNS, hosting və ya xidmət konfiqurasiyasını dəyişmir.
- `clear`, reload, restart, interface/BGP/PPPoE/NAT reset əməliyyatı yoxdur.
- ACL/WAF bloklama, giriş rotasiyası və restart yalnız target, TTL, diff və
  rollback sübutu olan insan təsdiqindən sonra canonical məhsul əməliyyatı ilə
  icra edilə bilər.
- Telegram xəbərdarlığı daxili əməliyyat bildirişidir; müştəriyə mesaj deyil.

## Agent runtime faylları

- `src/infrastructure_security.py` — sanitisation, freshness/delta və root-cause korrelyasiyası.
- `src/security_tools.py` — agentlər üçün read-only alət.
- `scripts/infrastructure_security_watch.py` — davamlı watcher, dedup, Telegram və proposal.
- `tests/test_infrastructure_security.py` — tarixi counter false-positive, aktiv delta,
  telemetry gap və web-log abuse testləri.

## Sayt/cPanel aktivləşdirmə

Sayt real serverə yüklənəndən sonra agent runtime environment-də bunlar verilir:

```text
SUPEROPTIC_PUBLIC_URL=https://www.superoptic.net
WEBSITE_SECURITY_MONITOR_ENABLED=1
WEB_ACCESS_LOG_PATH=<serverdə read-only access log yolu>
```

FTP/cPanel parolu agent promptuna, repoya və loga yazılmır. Deployment üçün
ayrıca məhdud səlahiyyətli SFTP və ya cPanel API girişi istifadə edilməlidir.
Monitor üçün fayl yazma və hosting admin səlahiyyəti lazım deyil.
