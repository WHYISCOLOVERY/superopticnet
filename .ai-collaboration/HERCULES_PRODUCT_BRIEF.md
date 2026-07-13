# Hercules product brief: SuperOptic operations portal

## Product goal

Make daily Finance, HR, Inventory, Billing, Messaging, Statistics and Network
work understandable to office and field employees with minimal training.

## Current design direction

- Open the operational workspace directly; avoid marketing-style hero sections.
- Keep modules separate and show employees only the modules allowed by role.
- Use short, natural Azerbaijani labels and familiar controls.
- Prefer focused modals for long creation forms when they reduce page length and
  nested scrolling.
- Keep tables compact, searchable without an extra submit click and paginated at
  no more than 50 rows per page.
- Update filters, charts and result regions through AJAX without reloading the
  full page.
- Use one page scrollbar, prevent horizontal overflow and include clear loading,
  empty and error states.

## First Hercules review scope

Review the Statistics and AI workspaces after the current implementation passes
visual and regression tests. Produce a bounded UX brief only; repository changes
remain with Codex and the assigned Claude frontend task.

Acceptance criteria:

1. Statistics metric and period changes update only the results region.
2. The `all` metric displays clearly titled sections in a readable sequence.
3. AI admin controls are absent for non-admin roles and direct access is denied.
4. Desktop and 390px mobile layouts have no clipped controls or nested scroll.
5. Azerbaijani labels describe employee actions, not internal implementation.
