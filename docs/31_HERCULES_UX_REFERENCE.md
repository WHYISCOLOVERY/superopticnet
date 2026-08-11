# Hercules UX reference

## Prototype

- App: `ISP Operations Dashboard`
- URL: `https://hercules.app/dashboard/app/01KXVA3HFE4TEQKEGZ5784CXZJ?threadId=01KXVA3HKH80BQ1F32991P1FHM`
- Scope: internal employee portal; no production data, credentials, source archive, or customer records were shared.

Hercules produced three bounded milestones: portal shell/navigation, module table views, and modal/mobile states. The prototype is a visual reference, not deployable source and not a replacement for the PHP permission, audit, approval, or data contracts.

## Rules to carry into implementation

- Keep module navigation grouped and role-aware: Maliyyə, HR, Anbar, Billing, AI, Mesaj mərkəzi, Statistika, and Şəbəkə/NOC.
- Use one page scrollbar. Long create/edit forms open in a focused modal without nested scrolling.
- Filters update their result region without a full-page reload and do not require a separate `Axtar` action.
- Tables show at most 50 rows per page and expose explicit loading, empty, error, and permission-denied states.
- Desktop pages remain compact and scannable. Mobile controls are at least 44px and primary actions remain visible without clipping.
- Use the existing white, teal, orange, and dark text tokens; radius is 8px or less and gradients are not introduced.
- Do not invent metrics. Empty metrics display a clear no-data state and preserve the selected date/filter context.
- Every destructive or financially material action retains its current approval, reason, role, and immutable audit requirements.

## Adoption order

1. Shared module header, grouped navigation, filters, table pagination, and state components.
2. Billing, Maliyyə, HR, and Anbar screens already covered by regression tests.
3. Mesaj mərkəzi, Statistika, AI, and Şəbəkə/NOC after their asynchronous and monitoring contracts pass.
4. Desktop and 390px mobile visual checks before each module is accepted.
