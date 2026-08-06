# Aktiv tapsiriq

Status: `IN_PROGRESS`

## Round: Cross-module defect duzelisi (2026-08-02)

Bu round evvelki WhatsApp provider/monitorinq UX roundunu legv etmir, onu evez edir. Evvelki round-un tam kontrakti ve neticesi asagida "Evvelki round" bolmesinde ve git tarixinde qorunur.

## Rol bolgüsü (bu round üçün)

- **Claude Code**: tam implementasiya lideri. Bu roundda frontend ile yanasi backend, service, API, DB ve permission qatinda da duzelis edir. Istifadeci (Serdar) bu rolu bu tapsiriqda acıq sekilde tesdiqleyib; evvelki "Codex evvelceden kontrakt freeze edir, Claude yalniz frontend" modeli bu round ucun deyisib.
- **Codex**: implementasiyadan sonra, production-a cixmadan evvel review edir; regression ve production tehlukesizliyini yoxlayir.
- **Istifadeci (Serdar)**: scope ve sahiblik qerarini tesdiqleyib.

## Meqsed

`docs/32`-`38` funksional auditlerini kod ile yeniden tesdiqleyerek Billing, Maliyye, HR, Anbar, Sebeke/NOC, Mesaj Merkezi (SMS/Telegram/WhatsApp), Statistika, CRM, rol/admin konfiqurasiyasi ve portal shell-de en yuksek tesirli, koherent duzelis paketini tetbiq etmek.

## Tehlukesizlik serhedleri (deyismir)

- Production-a yazma/deploy, real SMS/Telegram/WhatsApp gonderisi, canli RADIUS/OLT/router emeliyyati yoxdur.
- Destruktiv schema deyisikliyi yoxdur; yeni schema yalniz versiyali migration + rollback ile.
- Movcud Stage 0/Stage 1 tehlukesizlik ve odenis idempotency arxitekturasi geriye getmir.
- Ikinci backend ve ya tekrar biznes qaydasi yaradilmir; movcud service/API qati istifade olunur.
- Dirty worktree-de istifadeci ve Codex-in movcud deyisiklikleri qorunur; geri qaytarilmir, silinmir.
- Sirr, token, parol ve musteri mexfi datasi log/kod/chat-a yazilmir.
- `--dangerously-skip-permissions`, `git reset --hard`, `git clean`, force-push istifade olunmur; commit ve push edilmir.

## Qebul ve review qapisi

- Her toxunulan is axini ucun fokuslu regression/contract smoke testi elave olunur ve ishe salinir.
- Toxunulan PHP fayllarda lint yoxlanilir.
- Netice bu sessiyanin sonunda `.ai-collaboration/CLAUDE_IMPLEMENTATION_RESULT.md`-de qeyd olunur.
- Bu sened production go-live tesdiqi vermir; go-live-dan evvel Codex review-u mecburidir.

## Evvelki round (COMPLETE, deyisdirilmeyib)

WhatsApp provider QR ve emekdas monitorinq UX. Tam kontrakt ve netice: git tarixi (`847a290`..`65dffbd` arasi commitler), `.ai-collaboration/CLAUDE_FRONTEND_RESULT.md`, `.ai-collaboration/CLAUDE_FRONTEND_TASK.md`, `.ai-collaboration/INTEGRATION_REPORT.md`. Bu fayllar deyisdirilmeyib.
