# SuperOpticNet agent workflow review

Tarix: 2026-09-07

## Review nəticəsi

SuperOpticNet-də NOC, cihaz onboarding, alarm/ticket, billing/RADIUS, inventar, kommunikasiya və CrewAI proposal axınları ayrı PHP/lib/CLI fayllarına bölünmüşdü. Kod və Graphify xəritəsi bu canonical sərhədləri təsdiqlədi. Əsas inteqrasiya boşluğu agentin bu iş axınlarını strukturlaşdırılmış şəkildə oxuya bilməməsi idi; yalnız node reyestrini görürdü.

Bu boşluq üçün `config/agent_workflows.json` və `api/agent-ops/workflows.php` əlavə edildi. Endpoint yalnız service-token ilə, modul icazəsinə görə süzülmüş, credential/PII/xam cihaz cavabı olmadan workflow qaytarır. Workflow məlumatı icra icazəsi deyil.

## Canonical workflow-lar

1. `noc_network_health` — node scope → profile/path → SSH/SNMP read → health/alarm context → evidence.
2. `device_onboarding_and_learning` — owner IP/vendor → access profile → read-only discovery → command/interface/neighbor plan → approval gate.
3. `alarm_to_ticket_response` — alarm state history → əlaqəli device/customer → dedup/root-cause → ticket/notification proposal.
4. `billing_maintenance_and_radius` — customer/service/payment/RADIUS read → dry-run → sync/suspend/notice proposal → approved apply + audit.
5. `inventory_customer_equipment` — SKU/serial/stok → owner scope → movement preview → equipment assignment proposal → audit.
6. `communication_queue_delivery` — queue/provider readiness → dry-run → explicit send/apply → delivery/retry audit.
7. `crew_proposal_to_human_review` — workflow/context → facts vs assumptions → risk/evidence proposal → human queue → canonical product action.

Hər workflow-da beş sahə agent üçün məcburidir: mərhələlər, read operations, proposal operations, approval-required operations və evidence tələbləri. Beləliklə “hazırdır” yalnız son sübut mərhələsi keçəndə deyilə bilər.

## Qalan real boşluqlar

- SMS/WhatsApp/Telegram providerlərinin hamısı production providerə bağlanmayıb; queue worker dry-run və readiness mərhələsi ilə məhduddur.
- MyGov real OAuth/OIDC token/signature/userinfo mərhələsi tamamlanmayıb.
- Huawei OLT/ONT canlı command icrası hardware-validation gate-dən keçməyənə qədər yalnız plan/data səviyyəsindədir.
- Billing/RADIUS maintenance apply, customer message, inventory write və router/OLT config əməliyyatları insan təsdiqi və audit tələb edir.
- Tarixi trafik, trap və neighbor məlumatı native cədvəllərə köçürülüb, lakin uzunmüddətli poll/retention və alert threshold siyasətləri ayrıca operator qərarı tələb edir.

Bu boşluqlar agentə “uğursuz oldu” kimi gizlədilməməlidir; kataloqda approval/proposal kimi görünür və queue-də evidence ilə saxlanır.

## Doğrulama

- `tools/workflow-catalog-smoke.php`: 7 workflow, təkrarlanmayan ID, approval gate və secret-like sahə yoxlaması keçdi.
- `/api/agent-ops/workflows.php`: service-token ilə 7 workflow qaytardı.
- Python agent testləri: workflow catalog alətinin read-only audit hadisəsi təsdiqləndi.
- PHP endpoint lint və Python compile/test yoxlamaları uğurludur.
