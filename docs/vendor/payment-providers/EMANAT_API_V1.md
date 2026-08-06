# SuperOptic Emanat payment API v1

Status: staging contract

Base URL after written activation:

`https://billing.superoptic.net/api/provider`

Acceptance-test base URL before activation:

`https://mgt.superoptic.net/api/provider`

## Authentication

All requests include:

```text
Authorization: Bearer <PUBLIC_KEY_ID>.<PRIVATE_SECRET>
X-Provider-Name: emanat
X-Superoptic-Timestamp: <Unix time in seconds>
X-Superoptic-Signature: <lowercase hex HMAC-SHA256>
X-Correlation-ID: <provider request correlation id>
```

The signature input is the exact byte sequence:

```text
<timestamp>\n<raw HTTP request body>
```

`PUBLIC_KEY_ID` is safe to use as a credential identifier. `PRIVATE_SECRET` is
shown only once when the key is created and must be stored in Emanat's secret
manager. The HMAC signing key is the same `PRIVATE_SECRET`. The timestamp must
be within 300 seconds of SuperOptic server time. For GET requests the raw body
is empty.

Payment requests also include:

```text
Idempotency-Key: emanat:<transaction_id>
Content-Type: application/json
```

## 1. Customer lookup

```http
GET /api/provider/customer-lookup.php?provider=emanat&customer_code=2320025
```

Success:

```json
{
  "success": true,
  "provider": "emanat",
  "customer": {
    "customer_code": "2320025",
    "contract_no": "2320025",
    "full_name": "Example Customer",
    "status": "Aktiv",
    "is_active": true,
    "package": "100 Mbps",
    "balance": "0 AZN",
    "balance_amount": 0,
    "remaining_days": 25,
    "expires_at": "2026-08-22 12:00:00"
  },
  "correlation_id": "req-..."
}
```

Unknown customer returns HTTP `404` with `code=customer_not_found`.

## 2. Record payment

```http
POST /api/provider/payment.php
```

```json
{
  "provider": "emanat",
  "terminal_id": "EMN-001",
  "customer_code": "2320025",
  "transaction_id": "EMN-20260728-000001",
  "amount": "25.00",
  "paid_at": "2026-07-28T16:30:00+04:00"
}
```

Success:

```json
{
  "success": true,
  "duplicate": false,
  "external_id": "emanat:EMN-20260728-000001",
  "transaction_id": "EMN-20260728-000001",
  "amount": "25.00",
  "payment_state": "service_action_pending",
  "customer": {},
  "correlation_id": "req-..."
}
```

Retrying the exact request with the same transaction ID is idempotent and does
not create a second payment. Reusing the key with a different amount or customer
is rejected.

## 3. Payment status

```http
GET /api/provider/payment-status.php?provider=emanat&transaction_id=EMN-20260728-000001
```

The `payment_state` value is one of:

- `payment_recorded`
- `service_action_pending`
- `service_action_processing`
- `service_action_completed`
- `service_action_retrying`
- `manual_reconciliation_required`
- `reversed`

Unknown transactions return HTTP `404` with `code=payment_not_found`.

## HTTP error summary

| HTTP | Code | Meaning |
|---:|---|---|
| 400 | `unsupported_provider` | Provider name is invalid |
| 400 | `customer_code_required` | Customer code is missing |
| 400 | `transaction_id_required` | Transaction ID is missing |
| 400 | `amount_required` | Amount is missing or invalid |
| 401 | `unauthorized` | API credential is invalid |
| 401 | `credential_revoked` | API credential was revoked |
| 401 | `credential_expired` | API credential expired |
| 401 | `invalid_timestamp` | Timestamp is outside the accepted window |
| 401 | `invalid_signature` | HMAC signature is invalid |
| 403 | `source_ip_not_allowed` | Source IP is outside the allowlist |
| 403 | `scope_denied` | API credential does not have the required scope |
| 404 | `customer_not_found` | Customer does not exist |
| 500 | `payment_apply_failed` | Payment was not committed; retry safely |
| 503 | `provider_credentials_not_configured` | Provider is not activated |
