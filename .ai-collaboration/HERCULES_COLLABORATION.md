# Hercules collaboration contract

## Purpose

Hercules supports product discovery, workflow simplification, information
architecture and bounded visual prototypes for the SuperOptic OSS/BSS portal.
It does not replace repository review, backend implementation or acceptance
testing.

## Ownership

- Hercules: user flows, simpler navigation, UX alternatives and prototype briefs.
- Claude: assigned frontend views, CSS and JavaScript after the backend contract
  and file ownership are frozen.
- Codex: PHP and SQL contracts, roles and permissions, integration, automated
  tests, regression and final acceptance.

## Handoff requirements

Every Hercules proposal must state:

1. The user problem and affected employee role.
2. The smallest complete workflow.
3. Data and permission assumptions.
4. Loading, empty, error and approval states.
5. Desktop and mobile acceptance criteria.
6. File ownership for Hercules, Claude and Codex.
7. The no-full-page-reload test where filters or charts are interactive.

## Safety boundaries

- Do not claim access to local files without a connected repository or supplied
  artefact.
- Do not invent business metrics, legal formulas, API responses or production
  readiness claims.
- Preserve role visibility, CSRF, approval gates, append-only audit logs and
  super-admin boundaries.
- Do not upload the local repository or create a remote without explicit owner
  approval.

## Hercules setup

- Organization skill: `superoptic-product-engineering`
- Visibility: organization
- Status: enabled
- Use in app builder: enabled
- GitHub connector: connected
- Read-only GitHub tools: always allowed
- Write/delete GitHub tools: ask for approval
