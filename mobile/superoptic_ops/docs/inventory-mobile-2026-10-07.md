# Mobile inventory integration — 2026-10-07

Ticket resolution now loads employee-owned devices, cable drums and stock from `technician/inventory-options.php`. The UI sends persistent IDs with material quantities; server-side ownership and stock checks are authoritative.

- Consumables use the existing `app_inventory_consume_employee_stock` service, including company/location balance changes, document, audit and lifecycle entries.
- Employee-owned serialized assets also consume their product balance, then use `app_mgs_inventory_install_asset` for customer assignment and asset location. A serial-specific reference movement avoids counting quantity twice.
- Legacy technician-location assets retain the existing lifecycle installation flow.
- Cable drum meters decrement the selected drum's unreserved remaining length and record a cable lifecycle movement and work-order material link. This follows the separate drum representation; reconciling historical drum balances with aggregate stock remains an integration-test requirement.
- Ticket, proof, OTP consumption, inventory mutations and outbox result share a transaction. Failures roll back all database changes; the proof file is removed by the existing cleanup handler.
- Older queued material payloads lacking inventory IDs are rejected with a clear message instead of silently posting untracked consumption.

Validation: PHP lint, 10 isolated inventory guard tests, authenticated read-only inventory API, Flutter analyze and three Flutter tests passed. No operational inventory was consumed in tests.

Outstanding: isolated successful closure/rollback/concurrency tests, persistent offline inventory selection cache, warehouse mobile assignment/return confirmation, physical-device test. The phone was not present in ADB on 2026-10-07.
