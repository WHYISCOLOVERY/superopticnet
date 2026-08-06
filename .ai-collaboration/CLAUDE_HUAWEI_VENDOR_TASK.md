# Claude Code - Huawei vendor paketi

Status: `BLOCKED_UNTIL_STAGE0_ACCEPTED_AND_CLAUDE_LIMIT_RESET`

Claude Code weekly limit reset: `2026-07-24 00:00 Asia/Baku`.

## Meqsed

SmartAX MA5608T `V800R019C10` ve MA5800-X7 `V100R024C10` ucun source-backed, read-only, default bagli vendor katalogu ve parser fixture qatini yaratmaq.

## Baslamazdan evvel

1. `docs/stage0/03_STAGE0_RESULT_2026-07-22.md` oxu.
2. Stage 0 ucun ayrica `GO-LIVE ACCEPTED` qeydi yoxdursa product kodunu deyisme.
3. `docs/vendor/huawei/01_OFFICIAL_SOURCE_AND_CATALOG_PLAN.md` oxu.
4. `docs/27_HUAWEI_MA5608T_MA5800X7.md`, `tools/huawei-readonly-adapter.py` ve smoke testini oxu.
5. Dirty worktree-de istifadeci/Codex deyisikliklerini geri qaytarma.

## Birinci slice - katalog ve test

Stage 0 gate acildiqdan sonra ilkin write scope:

- `vendors/huawei/**`
- `tools/huawei-readonly-adapter.py`
- `tools/huawei-readonly-adapter-smoke.py`
- `docs/27_HUAWEI_MA5608T_MA5800X7.md`
- `.ai-collaboration/CLAUDE_HUAWEI_VENDOR_RESULT.md`

Bu slice-da `mgs-integration-lib.php`, DB, API ve UI deyisdirilmir.

Telebler:

1. Source URL, release, risk, context, parser, status ve hardware validation saheleri olan JSON schema yarat.
2. Resmi menbede yalniz emr adi tesdiqlenen entry-ni `source_confirmed_syntax_unverified` kimi saxla; executable etme.
3. Exact syntax ve real fixture olmayan Huawei private OID yazma.
4. Huawei enterprise tree bulk walk, SNMP SET ve butun write/disruptive CLI-ni redd et.
5. Standard MIB-II/IF-MIB/ENTITY-MIB planini qoruyub katalogdan yukle.
6. Version, board, port, ONT state, last-down, optical ve alarm parserleri ucun sanitizasiya olunmus synthetic fixture testleri yaz; synthetic neticeni hardware validation kimi sayma.
7. Existing hard gate ve `hardware_validated=false` qalmalidir.

## Ikinci slice - backend inteqrasiyasi

Bu slice ucun ayrica Codex review ve fayl sahibliyi tesdiqi teleb olunur. Yalniz bundan sonra:

- `huawei-vendor-lib.php` kimi dar service qati;
- `mgs-integration-lib.php` vendor dispatch;
- `noc-authorization-lib.php` object-scope contract;
- audit correlation;
- read-only transport interface;
- real cihaz testinin skip contracti.

UI, write action, ONT restart/provision, config backup ve production feature flag acilmasi bu tapşiriga daxil deyil.

## Qadağalar

- Internet forumundan kopyalanmis OID-ni resmi MIB olmadan aktiv etme.
- `display current-configuration` collect etme.
- Host, username, password, SNMP community/auth/priv key-ni koda, fixture-e, DB notes-a ve log-a yazma.
- `--dangerously-skip-permissions`, `git reset --hard`, `git clean` istifade etme.
- Real avadanliq olmadan live statusu passed yazma.

## Test

```powershell
python -m py_compile tools/huawei-readonly-adapter.py tools/huawei-readonly-adapter-smoke.py
python tools/huawei-readonly-adapter-smoke.py
python tools/huawei-readonly-adapter.py catalog
php tools/full-system-regression.php
```

Neticede deyisdirilen fayllari, testleri, tesdiqlenmeyen syntax/OID-leri ve real hardware ucun qalan addimlari `.ai-collaboration/CLAUDE_HUAWEI_VENDOR_RESULT.md` faylina yaz.
