# 06 Billing

## Purpose

Billing controls customer service state, packages, balances, payments, provider APIs, debt, statements and service activation/deactivation.

## Required Billing Objects

- Package/tariff with speed, price, default day count and RADIUS attributes.
- Customer service with PPPoE/IPoE username, package, custom price, custom billing day and status.
- Add-on services such as IPTV, SIP phone, static IP, Cable TV or custom service lines.
- Balance movements: payment, charge, correction, refund, bonus, debt and expiration.
- Payment provider transaction with provider, terminal, transaction ID, status and callback logs.
- Invoice/receipt and monthly extract.

## Day and Charge Rules

- If a package has no custom day count, service period defaults to 30 days.
- Stable billing day can keep a fixed monthly due date even when payment happens earlier/later.
- Custom price overrides package price when active.
- Add-on service prices are added to internet/package charge.
- Expired customers are queued for notification and suspension according to policy.
- Successful full payment can reactivate service and trigger RADIUS sync.

## Payment Provider API

- Customer lookup by customer code/contract code returns masked name, payable balance and status.
- Payment callback posts amount, provider, transaction ID and timestamp.
- Callback must be idempotent by provider transaction ID.
- Provider IP whitelisting and rate limiting are required in production.
- Every lookup and callback must be logged.

## Required Pages

- Billing dashboard, payment list, cash payment, provider payments, packages, debtors, balance movements, invoices/receipts, reconciliation and billing health check.

## Safety and Audit

- Manual correction requires permission and reason.
- Deleting payments is forbidden; use reversal/correction.
- Payment-driven service activation must be auditable.
- AI may explain payment history but must not change balances without confirmed operator action.
