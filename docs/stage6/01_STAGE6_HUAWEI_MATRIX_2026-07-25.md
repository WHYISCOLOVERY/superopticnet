# Stage 6 - Huawei OLT/ONT command + SNMP matrix (staged, hardware-gated)

Date: 2026-07-25
Status: Staged; live execution blocked until a real OLT is validated

## Goal

Prepare the exact SNMP walk steps and CLI command sequences for the operator's
Huawei equipment so that, the moment a real OLT is connected, we can run the
tests the operator named:

- OLT ilə bağlı məlumatları görürük? (read OLT info)
- İstədiyimizi (ONT-u) görürük? (read ONT data)
- Dəyişiklik edirik? (config change / ONT provisioning)
- VLAN ötürə bilirik? (VLAN / service-port push)

This mirrors the existing HSGQ adapter pattern (vendor command map +
per-device SNMP OID JSON, executed over SSH with authorization, dangerous-word
gating and audit). It is additive and does not touch the HSGQ path.

## Covered equipment

OLT profiles:
- `SmartAX MA5800-X7` — `V100R024C10` (MA5800 family)
- `SmartAX MA5608T` — `V800R019C10` (MA5600T family)

ONT models:
- `Huawei EG8141A5` — GPON HGU, 1×GE + 3×FE, 1×POTS, 2.4 GHz Wi-Fi
- `Huawei EchoLife EG8245W5-6T` — GPON HGU, 4×GE, 2×POTS, dual-band Wi-Fi, USB

## What was built

`huawei-olt-lib.php` (pure data + render/plan helpers, no transport):

- `app_huawei_olt_profiles()` / `app_huawei_ont_models()` — profile + capability
  catalog.
- `app_huawei_snmp_oids()` — 11 standard-MIB OIDs only (MIB-II system, IF-MIB
  counters, ENTITY-MIB board inventory). The Huawei enterprise tree
  `1.3.6.1.4.1.2011` is intentionally excluded, matching the read-only
  adapter's security model.
- `app_huawei_command_matrix()` — categorized CLI templates:
  - `read_olt`: `display version`, `display board 0`, `display board 0/{slot}`,
    `display alarm active`, `display port state 0/{slot}/{port}`, …
  - `read_ont`: `display ont info {port} {ont}`, `display ont optical-info`,
    `display ont register-info`, `display ont wan-info`, `display ont version`,
    `display ont info by-sn {sn}`
  - `config_change`: `ont add … sn-auth … ont-lineprofile-id … ont-srvprofile-id`,
    `ont modify … desc`, `ont port native-vlan …`, `save`
  - `vlan_push`: `vlan {vlan} smart`, `port vlan {vlan} 0/{slot} {port}`,
    `service-port {idx} vlan {vlan} gpon 0/{slot}/{port} ont {ont} gemport {gem}
    multi-service user-vlan {uservlan}`, `display service-port port …`
  - `ont_control`: `ont reset|activate|deactivate|delete {port} {ont}`
- `app_huawei_context_prefix()` / `app_huawei_render()` — build the enable →
  config → `interface gpon 0/{slot}` context and substitute
  `{frame}{slot}{port}{ont}{vlan}{gem}{uservlan}{sn}{lineprofile}{srvprofile}{sportidx}{desc}{ethport}`.
- `app_huawei_live_test_plan($model, $vars)` — the ordered plan a technician
  runs when the OLT arrives, grouped by the four intents above. Each step
  carries its context commands, the rendered command, an expected output marker
  and a `requires_apply` flag (read steps are safe; config/vlan/control steps
  are dangerous and stay dry-run until explicitly applied).

## Safety / gating (nothing runs live yet)

`app_huawei_live_ready($model, $device, $db)` is a hard gate:

- unsupported model → `unsupported_model`;
- flag `stage6_huawei_adapter` off → `feature_flag_disabled` (mode `mock_plan`);
- device without `hardware_validated=true` → `real_hardware_not_validated`
  (mode `mock_plan`), **even when the flag is on**.

Only a supported model, with the flag on, on a device explicitly marked
`hardware_validated`, returns `live`. Dangerous commands additionally go through
the existing `app_mgs_execute_device_command_plan` gating (NOC authorization,
management-path check, dangerous-word explicit apply, audit) when actually run.

## Verification

- `tools/stage6-huawei-matrix-smoke.php`: **54 pass / 0 fail (flag off), 55 / 0
  (flag on)** — mock only, no device contact. Asserts:
  - both OLT profiles + both ONT models with correct releases/capabilities;
  - all five command categories and representative commands present;
  - read commands never dangerous; provisioning/vlan/control commands dangerous;
  - SNMP OIDs are standard-tree only (no `1.3.6.1.4.1.2011` leak);
  - every plan command for both models renders with zero leftover placeholders
    (e.g. `display ont info 3 7`, context `interface gpon 0/2`);
  - the live gate stays closed by default, rejects unsupported models, and
    blocks unvalidated devices even with the flag on.
- PHP lint clean; migration `20260725_070_stage6_huawei_adapter` applied;
  rollback cycle verified (disable → migration rollback → reapply → re-enable).

## When the real OLT arrives

1. Register the device (vendor `HUAWEI`, model `MA5800-X7`/`MA5608T`) with real
   SSH/SNMP credentials in the existing credential registry.
2. Validate the hardware and set `hardware_validated=true` on the device.
3. Run `app_huawei_live_test_plan()` read steps first (safe) to confirm
   OLT/ONT visibility and SNMP walk, then apply config/vlan steps with explicit
   apply, comparing each `expect_marker` against the live output.
