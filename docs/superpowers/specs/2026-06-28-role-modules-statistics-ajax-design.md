# Role Based Portal Modules and AJAX Statistics Design

## Goal

Replace the mixed employee navigation with eight portal modules and ensure each employee sees and can open only the modules allowed for their `employee_role`. Update statistics charts without reloading the full page.

## Portal Modules

The employee portal exposes these module keys and landing routes:

| Key | Label | Route |
| --- | --- | --- |
| `finance` | Maliyye | `operations-center.php?section=finance` |
| `hr` | HR | `operations-center.php?section=hr` |
| `inventory` | Anbar | `operations-center.php?section=inventory` |
| `billing` | Billing | `billing-dashboard.php` |
| `ai` | AI | `mgs-copilot-context.php` |
| `messaging` | Mesaj merkezi | `message-center.php` |
| `statistics` | Statistika | `billing-statistics.php` |
| `network` | Sebeke | `noc-devices.php?view=monitor` |

## Role Matrix

| Employee role | Modules |
| --- | --- |
| `admin` | all modules |
| `finance` | finance, billing, statistics |
| `warehouse` | inventory |
| `hr` | hr, statistics |
| `operator`, `night_operator` | billing, messaging, statistics |
| `support` | billing, messaging, network, ai, statistics |
| `technician`, `engineer` | network, inventory |
| `noc` | network, ai, messaging, statistics |
| `sales` | finance, billing, messaging, statistics |
| empty or unknown | billing, messaging |

The module cards and direct page access use the same central authorization functions. An unauthorized module request redirects to `portal.php?status=module_forbidden`.

## Navigation

The main employee portal replaces the repeated quick-action and handoff module links with one eight-module grid filtered by role. Billing navigation contains only Billing dashboard, customers, payments, requests, packages, and Billing checks. Finance, HR, inventory, AI, messaging, statistics, and network links no longer appear inside Billing navigation.

## Statistics Update Flow

The statistics filter keeps its normal GET submission as a non-JavaScript fallback. JavaScript intercepts submission, requests `stats_partial=1`, and replaces only the element marked `data-statistics-results`. The fragment carries normalized start and end dates so the form remains synchronized with preset date ranges. Loading and error states are announced without discarding the previous chart.

## Verification

Smoke tests verify the role matrix, eight module definitions, Billing navigation boundaries, fragment markers, AJAX request code, and the existing chart selectors. PHP lint and browser checks verify the portal, denied routes, and chart replacement on desktop.
