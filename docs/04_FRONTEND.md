# 04 Frontend

## Purpose

Define how the UI becomes a modern ISP command center while preserving current PHP-rendered pages during incremental migration.

## Current Frontend Rule

Current PHP pages remain the v1 frontend. Fix usability issues and extract reusable UI patterns, but do not rewrite the whole UI in one step.

## Target Frontend Stack

- React.
- TypeScript.
- Tailwind.
- Shadcn.
- Component-driven design system.
- API-first data fetching.
- Server-side pagination or virtual scrolling for large lists.

## UI Philosophy

- No table jungle.
- Summary first, details later.
- Use cards, charts, kanban, timelines, side panels, drawers, heatmaps and topology views.
- Use tables only where structured comparison or export workflows require them.
- Empty state cards replace blank tables.
- Action buttons must be visible in context.
- Screens must be responsive for desktop, tablet and mobile operations.

## Core Navigation

- Dashboard, Customers, Billing, Network / NOC, RADIUS, OLT / ONT, Tickets, Inventory, SMS & Notifications, Analytics, HR & KPI, Security Center, AI Copilot and Settings.

## Reusable Components

- StatCard, ChartCard, HeatmapCard.
- StatusBadge, RiskScoreBadge, QoEScoreBadge, HealthScore.
- SmartDataTable with server-side pagination.
- FilterDrawer, ActionDropdown, SidePanel, ConfirmDialog.
- Timeline, EventFeed, WizardForm, EmptyState, ErrorState, LoadingSkeleton.
- TopologyView, TreeView and MiniMap.

## Performance Rules

- Avoid rendering thousands of rows directly.
- Use skeleton loaders instead of blank pages.
- Keep menus compact and avoid nested page-in-page scroll traps.
- Load details lazily after row click or panel open.
