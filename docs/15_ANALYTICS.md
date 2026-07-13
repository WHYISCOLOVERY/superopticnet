# 15 Analytics

## Purpose

Analytics turns customer, billing, network, RADIUS, ticket, inventory, HR and security data into operational decisions.

## Required Dashboards

- Revenue analytics.
- Customer growth and churn.
- Risk and debt.
- Package performance.
- Zone performance.
- Traffic analysis.
- RADIUS/auth analysis.
- NOC/device health.
- Ticket performance.
- Technician KPI.
- Inventory usage.
- Security anomaly trends.

## Insight Pages

- Customers who paid last month but not this month.
- Top zones by outage/ticket volume.
- Top degraded ONTs/PONs.
- Top traffic users and suspicious uploaders.
- Packages with low margin or high support cost.
- Employees with delayed tickets or high reopen rate.

## Data Strategy

- Use current-state tables for live dashboard widgets.
- Use rollups for trend charts.
- Use ClickHouse for high-volume traffic, RADIUS accounting, NAT logs and historical metrics.
- Do not run heavy historical scans from request/response UI pages.

## Output

- Charts.
- Heatmaps.
- Insight cards.
- Exportable reports.
- AI explanations.
- Campaign triggers for retention or debt recovery.
