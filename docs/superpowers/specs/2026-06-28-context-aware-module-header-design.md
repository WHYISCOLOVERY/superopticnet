# Context Aware Module Header Design

## Goal

Make each top-level module identify itself correctly after navigation. Billing pages retain the Billing home link and Billing navigation; Finance, HR, Inventory, Network, Statistics, Messaging, and AI pages use the main portal as their header home.

## Shared Header Rules

`billing-ui.php` remains the single header renderer. It already knows whether the active page belongs to the three Billing navigation groups. The same decision will now control the brand link:

- Billing active key: brand link is `billing-dashboard.php`.
- Non-Billing active key: brand link is `portal.php`.
- The existing `Esas portala qayit` action remains available.
- Billing navigation renders only for active Billing pages.

## Operations Center Titles

`operations-center.php` maps its active section to a visible page title:

| Section | Header title |
| --- | --- |
| `overview`, `search` | Operations Center |
| `finance` | Maliyye |
| `hr` | HR |
| `inventory` | Anbar |
| `noc`, `olt`, `technical` | Sebeke |
| `reports` | Statistika |
| `bots` | AI |

The HTML document title uses the same value so browser tabs and the on-page header cannot disagree.

## Verification

A focused smoke test verifies Billing and non-Billing brand destinations and the operations section-title map. Existing module access, operations module, and 42-page Billing UI tests provide regression coverage. Browser verification checks Finance, HR, Statistics, and Billing headers and links.
