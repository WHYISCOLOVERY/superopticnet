# SuperOptic MGS - cari yarim qalan addimlar

Bu fayl son sertname tələblərini `D:\xampp\htdocs\superopticnet` PHP portalının real vəziyyəti ilə tutuşdurur.

## Bu batch-də tamamlanan

- Mesaj mərkəzi yaradıldı: fərdi mesaj, qrup mesajı, test rejimi, kanal seçimi.
- Mesaj fallback qaydası əlavə edildi: WhatsApp -> app/portal -> Telegram -> SMS.
- Hazır şablonlar əlavə edildi: ödəniş, xidmət bitməsi, xidmət aktivləşməsi, modem qızması, modem flap, retention, ad günü və bayram mesajları.
- Müştəri profilinə mesaj tarixçəsi əlavə edildi.
- Employee portalına `Mesaj merkezi` keçidi əlavə edildi.
- Billing müştəri səhifəsi yaradıldı: müştəri siyahısı, yeni PPPoE/IPoE servis yaratma, tarif/pool seçimi, avtomatik IP ayırma, MAC/VLAN/OLT/PON/ONT sahələri və Radius sync.
- MyGov ID gələcək inteqrasiyası üçün passiv SSO modulu yaradıldı: login start/callback skeleti, state/nonce saxlanması, identity mapping və admin readiness səhifəsi.

## Hələ yarımçıq qalan əsas bloklar

- Real provider adapterləri: SMS provider, WhatsApp API, Telegram bot və app push hələ DB növbəsindən real göndərimə qoşulmayıb.
- MyGov real OAuth/OpenID adapteri hələ qoşulmayıb: token exchange, ID token signature validation və userinfo mapping API məlumatları gələndə tamamlanacaq.
- Billing UI köhnə sistemdəki kimi tam səhifə-səhifə bölünməyib: ödənişlər, nağd ödəniş, müraciətlər, statistikalar və FreeRADIUS aktiv/NAS/log alt səhifələri ayrıca qurulmalıdır.
- Müştəri yaratma formu birinci işlək versiyadır; çoxlu PPPoE/IPoE servisin bir müqavilə altında idarəsi, OLT/PON canlı seçim dropdown-u və edit modalı ayrıca tamamlanmalıdır.
- FreeRADIUS real Proxmox tətbiqi hazırlanıb, amma production serverə tətbiq edilməyib.
- OLT/ONT canlı axtarış və müştəri profilinə avtomatik ONT bağlama hissəsi bu checkout-da hələ yalnız payload səviyyəsindədir.
- Campaign səhifəsi yoxdur: 60+ gün ödəniş etməyən müştərilərə kampaniya seçib mesaj göndərmə UI ayrıca lazımdır.
- Payment terminal inteqrasiyası hələ mock/local DB axınındadır; provider webhook/API bağlanmalıdır.

## Növbəti ən düzgün icra sırası

1. Billing UI-ni böl: `billing-customers.php`, `billing-payments.php`, `billing-cash-payment.php`, `billing-requests.php`, `billing-statistics.php`, `radius-active.php`.
2. Müştəri yaratma modalını billing tələblərinə uyğun genişləndir.
3. Mesaj provider adapter workerini yaz: queued mesajları providerə göndərib statusu `sent/failed` yeniləsin.
4. FreeRADIUS payment/suspend cron skriptini production-ready et.
5. Müştəri profilində ONT/ticket/payment/message birlikdə tam operator görünüşünü ayrıca admin səhifədə qur.

## 2026-05-19 update

- Internal employee customer profile completed in `billing-customer-profile.php`: main profile, billing, internet services, modem/ONT, payment extract, statement extract, tickets, message history, Radius auth, Radius post-auth and Radius accounting.
- Customer-facing `portal.php` remains restricted: no Radius status, pool/zona, VLAN or OLT/PON location is shown to customer; modem temperature remains visible.
- Billing customer list now links directly to the internal customer profile.

## 2026-05-19 billing reference update

- Live old billing structure was mirrored into grouped pages: `billing-dashboard.php`, `billing-payments.php`, `billing-cash-payment.php`, `billing-requests.php`, `billing-statistics.php`, `radius-active.php`, `message-center.php`, `radius-billing.php`.
- Shared grouped menu/header is centralized in `billing-ui.php` so Billing, PPPoE/IPoE, Message and FreeRadius pages are no longer a single long stacked flow.
- `billing-customer-profile.php` now supports adding multiple PPPoE/IPoE services under one customer contract and renders payment, statement, ticket, message and Radius log blocks together.
- `app_billing_sync_radius_account()` now syncs all customer `internet_services`, not only the first service, into `radcheck`, `radreply`, `radusergroup` and `billing_radius_services`.
- Verified with PHP lint, page render smoke tests and temporary multi-service Radius sync test.

## 2026-05-20 NAT log update

- `radius-natlog.php` added for NAT/source-destination search by source IP, destination IP, port, date, protocol, node and username/subscriber query.
- `RADIUS -> NAT log axtarisi` menu link added in `billing-ui.php`; `radius-active.php` quick action now points to the new NAT log page instead of the missing reports page.
- NAT log storage is ready through `radius_nat_logs` and `radius_nat_log_nodes`; `tools/import-radius-nat-logs.php` can ingest CSV/syslog files in dry-run/apply mode.
- Verified with PHP lint, legacy import verifier, billing smoke test and in-app browser smoke test.

## 2026-05-20 VLAN/MPLS definitions update

- `network_vlan_definitions` and `network_vlan_path_hops` tables added for VLAN service planning, customer/device/port mapping and hop-level command preview storage.
- `network-definitions.php` added as a safe planning page: creates VLAN/MPLS definitions without sending commands to live routers, switches or OLTs.
- `Network / NOC -> VLAN/MPLS definitions` menu link added.
- Verified with PHP lint, DB schema bootstrap, insert/delete smoke test and in-app browser smoke test.

## 2026-05-20 campaign/retention update

- `campaigns.php` added for CRM/retention campaigns: active customers, 1-day expiry, 60+ day unpaid, 30+ day inactive and high-risk groups.
- `SMS & Bildirisler -> Kampaniyalar` now opens the dedicated campaign page instead of only an anchor inside message center.
- Campaign queue uses existing `communication_messages` flow with test mode enabled by default; no provider API is called directly.
- Candidate stats use real count queries, while the preview list is capped to 100 rows for page performance.
- Verified with PHP lint, HTTP render timing, in-app browser smoke test, billing smoke test and legacy import verifier.

## 2026-05-20 communication queue worker update

- `tools/process-communication-queue.php` added for safe message queue processing.
- Default mode is dry-run; real sending/status changes require `--apply`.
- `app` channel is marked delivered as portal inbox; `telegram`, `sms` and `whatsapp` only send when provider config is present.
- Missing provider config keeps messages queued by default, preventing message loss; `--fail-unconfigured` can explicitly mark them failed.
- Verified with PHP lint, dry-run, temporary app-channel apply smoke test, temporary unconfigured SMS safety test, billing smoke test and legacy import verifier.

## 2026-05-20 single pane of glass update

- `operations-center.php` added as the first single-pane control page.
- Dashboard menu now includes `Operations Center`.
- The page combines customer, billing, Radius, tickets, message queue and VLAN/MPLS context with universal search.
- `billing-customer-profile.php` now has customer-context actions for payment, messaging, Radius, NAT log, VLAN/IP, ONT/optical and Operations Center lookup.
- Customer profile can create an operator ticket in context and returns back to the same customer profile instead of sending the operator to the generic CRM list.
- Inventory and HR blocks are shown as readiness/context cards until their dedicated schemas are fully connected; the page does not fabricate stock or attendance data.
- `message-center.php` now shows queue counts by channel, provider readiness and the safe CLI worker commands.
- Verified with PHP lint, in-app browser smoke tests for operations center and message center, billing smoke test and legacy import verifier.

## 2026-05-20 RADIUS IP pool engine update

- `ip_pools`, `ip_addresses`, `customer_ip_assignments` and `ip_assignment_history` tables added for Local/CGNAT, NAT public and Static public IP control.
- `ip-pools.php` added as the operator page for pool creation, utilization, free/used/reserved/blacklist status and active customer assignments.
- Existing `billing_ip_pools` are bridged into the new engine so legacy billing pools remain compatible while the smarter IP status model is introduced.
- Customer 360 profile now has an `IP/NAT` tab with local IP, NAT public IP, static IP, assignment history, conflict check and Radius sync action.
- New customer wizard and PPPoE/IPoE service form now accept NAT public IP and record local/static/NAT values into Radius service sync history.
- `billing_radius_services` now stores `nat_public_ip`; Radius replies still only send known safe attributes (`Framed-IP-Address`, `Framed-Pool`, netmask). Router-specific NAT `Cisco-AVPair` format is intentionally not sent until the exact production policy is defined.
- CRM ticket cards now expose context actions to customer 360, SPoG and SMS instead of forcing operators to search in separate pages.
- Verified with PHP lint, DB bootstrap, MySQL table/column checks, billing smoke test, communication queue dry-run, legacy import verifier and in-app browser smoke tests for IP pool, customer profile and CRM.

## 2026-05-20 IP engine hardening update

- IP assignment validation is centralized in `app_ip_validate_customer_ips()`: Local, NAT public and Static public IP fields must belong to matching active pool types.
- Duplicate role assignment is blocked: the same IP cannot be written as local/NAT/static at the same time.
- Customer create wizard, customer profile IP update and extra internet service creation all use the same validation.
- Customer profile now has `IP assignment release et`; it frees Local/NAT/Static IP records, writes history and returns the service to RADIUS pool mode.
- `app_billing_sync_radius_account()` no longer creates empty active IP assignment records when the service is in pool mode.
- Verified with PHP lint, type-validation smoke, billing flow smoke, legacy import verifier and in-app browser smoke for profile release UI and customer creation NAT field.

## 2026-05-20 IP pool bulk + Radius session safety update

- `ip-pools.php` now supports single IP status updates with explicit pool type, bulk IP status changes and CSV import (`ip,status,type,note`) for reserved/blacklist/free workflows.
- `radius_session_actions` table added for audit-only Radius session actions.
- `radius-active.php` now shows recent disconnect requests and lets admin/NOC users create a disconnect request for online sessions without sending live CoA or router commands.
- Duplicate pending disconnect requests for the same username are deduplicated within 15 minutes and only update the existing pending audit record.
- Verified with PHP lint, DB bootstrap, rollback helper smoke, IP pool smoke, billing flow smoke, legacy import verifier, communication queue dry-run and in-app browser smoke for IP pool/RADIUS pages.

## 2026-05-21 billing maintenance automation update

- `service_expired` message template added for customers whose billing period has ended.
- `app_message_queue_unique_for_account()` added so automated billing notices do not create duplicate daily messages per customer/template.
- `tools/billing-maintenance.php` now supports `--queue-notices`, `--notice-limit` and `--channel=auto|whatsapp|app|telegram|sms`.
- Maintenance dry-run now reports expiring-1-day and expired notice candidates before any DB change.
- Apply mode can refresh countdowns, suspend expired Radius services, optionally sync Radius and queue billing notices in the same scheduled command.
- `message-center.php` now shows the maintenance command beside the message queue worker commands.
- Verified with PHP lint, maintenance dry-run, rollback dedup smoke, billing flow smoke, legacy import verifier, communication queue dry-run and in-app browser smoke for message center.

## 2026-05-21 RADIUS NAS management update

- `radius-nas.php` added as a dedicated FreeRADIUS NAS management page.
- Admin/NOC users can add/update/delete NAS rows and mark `nasreload` timestamps without exposing shared secrets in the list.
- The page shows NAS count, active sessions, 24h sessions, 24h traffic and recent `radacct` accounting rows by NAS.
- `RADIUS -> NAS cihazları` menu now opens `radius-nas.php` instead of the generic Radius billing page.
- Verified with PHP lint, DB count smoke, in-app browser smoke, billing flow smoke, legacy import verifier, communication queue dry-run and billing maintenance dry-run.

## 2026-05-21 RADIUS logs audit update

- `radius-logs.php` now works as a dedicated FreeRADIUS audit page instead of a hidden statistics anchor.
- Default log period is 7 days so recent auth/accounting rows are visible during operator review.
- The page shows period-based Accept/Reject, accounting, disconnect, online and traffic cards, plus grouped auth reply, terminate cause and NAS distribution.
- Auth and accounting tables link back to customer 360 profiles when a Radius username is mapped to a portal account.
- `radpostauth.pass` is intentionally not rendered in the UI; only operational fields such as username, reply, NAS, IP, MAC, time and traffic are shown.
- `RADIUS -> RADIUS logları` menu now opens `radius-logs.php`.
- Verified with PHP lint, DB count smoke and in-app browser smoke showing auth/accounting sections and 21 rendered log rows.

## 2026-05-21 Nginx gateway validation update

- `tools/validate-nginx-gateway.php` added as a static validator for the production Nginx/API gateway bundle.
- The validator checks required files, SuperOptic domains, upstream blocks, API route map, rate-limit zones, JSON logging, bot blocking, access-control maps, proxy/security headers, SSL policy, install include chain and `/api/health.php`.
- `deploy/nginx/README.md` now documents the validator command before server copy/reload.
- `sites-available/superoptic-gateway.conf` now references the prepared `$office_or_vpn_allowed` map for optional admin/VPN restriction instead of only raw allow/deny examples.
- Verified with PHP lint and gateway validator smoke.

## 2026-05-21 public status page update

- `status.php` added as a public read-only status page for `status.superoptic.net`.
- The page checks DB connectivity, customer table, Radius services/accounting, communication queue, open tickets and gateway file readiness without exposing secrets or admin actions.
- `status.superoptic.net/` and `/status.php` now proxy to `mgs_php_frontend/status.php`; `/api/health` remains JSON health.
- Gateway validator now also checks `status.php` and the status route.
- Verified with PHP lint, gateway validator smoke and local browser smoke.

## 2026-05-21 production readiness update

- `tools/production-readiness-check.php` added for local and production cutover checks.
- The checker verifies production-safe `app_url`, `ticket_close_base_url`, MySQL connectivity, DB credentials, required billing/RADIUS/ticket/message/IP tables, RADIUS secret/NAS IP, Telegram bot readiness, ticket close signing secret, billing mode, SSO/myGov URLs and gateway validator presence.
- `--config=path/to/config.php` support added so staging/production config files can be audited without replacing local `config.local.php`.
- `config.local.production.example.php` added as the production config template for `mgs.superoptic.net`, `billing.superoptic.net` and Radius cutover.
- `BILLING_OPERATIONS.md` now documents the readiness and gateway validator commands.
- Verified with PHP lint and local readiness smoke.

## 2026-05-21 system health admin UI update

- `system-health.php` added as an admin-only readiness dashboard for DB, required billing/RADIUS tables, RADIUS core settings, Telegram/ticket close readiness and Nginx/API gateway bundle checks.
- `Ayarlar -> System health` menu link added.
- The page masks secrets and only shows configured/missing/placeholder status, plus production cutover blockers and the exact CLI verification commands.
- Web checks are static/safe and do not execute shell commands from the browser.
- Verified with PHP lint, local readiness checker, Nginx gateway validator, billing smoke test and in-app browser smoke.

## 2026-05-21 communication provider adapter update

- `communication-provider.php` added as the shared provider adapter for app, Telegram, SMS and WhatsApp delivery.
- `tools/process-communication-queue.php` now uses the shared adapter instead of keeping provider logic inside the worker.
- `tools/test-communication-provider.php` added for dry-run/apply provider smoke tests. It sends `TEST MESAJI` only with `--apply` and does not create tickets, alarms or DB message rows.
- `message-center.php` now reads provider readiness from the shared adapter and shows provider test commands.
- `message-center.php` admin UI now includes a provider test panel. It defaults to dry-run and only sends a real `TEST MESAJI` when the explicit checkbox is enabled.
- `communication_delivery_attempts` now stores real worker delivery attempts with masked destinations, provider result summaries and sent/failed/skipped status; dry-run still does not write DB state.
- `communication_worker_runs` now stores real `--apply` queue worker run summaries, including scanned/sent/failed/left-queued counts, channel, duration and by-channel summary.
- `message-center.php` now shows a provider delivery audit table so admins can verify real queue worker attempts without reading raw logs.
- `message-center.php` now shows recent worker executions so cron/worker health can be checked from the UI.
- `message-center.php` now lets admins safely return only `failed` messages back to `queued` for retry after provider configuration or temporary delivery problems are fixed.
- Message history now supports status/channel/search filters, failed delivery reasons are grouped, and admins can bulk-return up to 50 matching failed messages to the provider queue without sending them immediately.
- Local and production config examples now include SMS and WhatsApp provider endpoint/token/sender keys.
- Verified with PHP lint, queue worker dry-run, provider dry-run tests, readiness checks, billing smoke, gateway validator and in-app browser smoke for message/system health pages.

## 2026-05-21 billing maintenance run audit update

- `billing_maintenance_runs` added as the audit table for real billing cron runs.
- `tools/billing-maintenance.php` now logs real `--apply` runs with countdown, expired, suspended, Radius sync, notice queue, partial failure and duration counters.
- Dry-run mode still does not write run audit rows and remains safe for review.
- `billing-dashboard.php` now shows recent maintenance executions so billing cron health is visible without reading CLI output.
- `system-health.php` and `tools/production-readiness-check.php` now include `billing_maintenance_runs` in required schema checks.

## 2026-05-21 billing operational readiness update

- `billing-operations-check-lib.php` added as the shared billing readiness engine.
- `tools/billing-operation-check.php` added for CLI verification of billing customer, tariff, IP pool, RADIUS SQL, payment amount, message templates, maintenance and UI page readiness.
- `billing-operations-check.php` added as the admin UI for the same checks, with grouped cards and direct CLI commands.
- Billing navigation now includes `Billing yoxlama`.
- `system-health.php` and `tools/production-readiness-check.php` now also check `billing_tariffs` and `billing_ip_pools`.

## 2026-05-21 billing UI smoke coverage update

- `tools/billing-ui-smoke.php` added to render the main billing pages with an employee session and a real sample customer.
- The smoke covers dashboard, customer list, customer 360 profile, cash payment, payments, statistics, RADIUS active/log/NAS pages, message center, IP pools and billing operations check.
- The tool fails on PHP fatal/warning/notice output, auth redirects, missing critical list/profile markers or empty page output.
- `billing-operations-check.php` and `BILLING_OPERATIONS.md` now document the UI smoke command.
- Verified with PHP lint and `tools/billing-ui-smoke.php` returning `status=ok`, `checked_pages=12`, `failed=0`.

## 2026-05-21 billing performance update

- `tools/optimize-billing-performance.php` added as an idempotent performance index installer with dry-run and `--apply` modes.
- Applied indexes for customer listing, latest RADIUS service lookup, payment extracts, message/ticket dashboards, maintenance runs, radacct online/update views and radpostauth reply/date analysis.
- `billing-payments.php` now calculates total/terminal/cash/today/month summary in one aggregate query instead of repeated full scans.
- Payment date filters now use indexed datetime ranges instead of wrapping `created_at` in `DATE()`.
- Unfiltered payment pages reuse total summary for filtered count/total instead of running a second count/sum scan.
- `billing-dashboard.php` now combines customer and payment summary lookups to reduce DB round trips.
- `billing-customers.php` now avoids the RADIUS service join for customer counts when the active filters do not need service fields.
- `billing-operations-check-lib.php` now reports performance index readiness.
- `db.php` now caches successful DB bootstrap/schema readiness in `data/db-bootstrap-ok.json`; normal page loads no longer run the heavy migration/seed cycle on every request.
- `billing-customers.php` now has an exact subscriber/contract/phone search fast path before falling back to wider LIKE/RADIUS service search.
- Billing/admin pages now load local `css/fontawesome-lite.css` instead of the external CDN FontAwesome stylesheet to avoid browser-side blocking on weak internet.
- `tools/billing-ui-smoke.php` now reports per-page duration so slow pages can be tracked in future UI work.
