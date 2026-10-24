# SuperOptic United Payment kiosk and wallet API v1

Status: staging contract

Base URL after written activation:

`https://billing.superoptic.net/api/provider/up`

Acceptance-test base URL before activation:

`https://mgt.superoptic.net/api/provider/up`

## Authentication

All requests include:

```text
Authorization: Bearer <PUBLIC_KEY_ID>.<PRIVATE_SECRET>
X-Provider-Name: unitedpayment
X-Superoptic-Timestamp: <Unix time in seconds>
X-Superoptic-Signature: <lowercase hex HMAC-SHA256>
X-Correlation-ID: <provider request correlation id>
```

The signature input is:

```text
<timestamp>\n<raw HTTP request body>
```

`PUBLIC_KEY_ID` identifies United Payment's credential and may be shown in
operational logs. `PRIVATE_SECRET` is shown only once and must remain in United
Payment's secret manager. The HMAC signing key is the same `PRIVATE_SECRET`.
For GET requests the raw body is empty. The timestamp must be within 300
seconds of SuperOptic server time. Production source IPs are allowlisted in
addition to credential and HMAC validation.

## 1. Member lookup

```http
GET /api/provider/up/?memberId=2320025
```

Success:

```json
{
  "StatusCode": "00",
  "StatusDesc": "APPROVED",
  "memberInfo": "Example",
  "additionalInfo": "Customer",
  "description": "2320025",
  "balance": "25",
  "customer": {},
  "correlation_id": "req-..."
}
```

## 2. Insert payment

```http
POST /api/provider/up/insert-payment/
Content-Type: application/x-www-form-urlencoded
Idempotency-Key: unitedpayment:<toProcessId>
```

Exact body example:

```text
memberId=2320025&toProcessId=UP-20260728-000001&amount=25.00&channelId=1&channelDesc=Kiosk-001
```

Success:

```json
{
  "StatusCode": "00",
  "StatusDesc": "APPROVED",
  "paymentState": "service_action_pending",
  "correlationId": "req-...",
  "correlation_id": "req-..."
}
```

The exact request can be retried safely with the same `toProcessId` and
`Idempotency-Key`. A duplicate already recorded by the legacy UP contract can
return `E209`; the status endpoint must then be queried.

## 3. Payment status

```http
GET /api/provider/up/payment-status/?toProcessId=UP-20260728-000001
```

Success returns `StatusCode=00` plus `paymentState` and `correlationId`.

## UP status codes

| StatusCode | Meaning |
|---|---|
| `00` | Approved or transaction exists |
| `E000` | Customer or transaction not found |
| `E202` | Invalid request |
| `E209` | Duplicate transaction |

Authentication and transport failures use HTTP `401`, `403`, or `503` with a
JSON `code` value as described in `EMANAT_API_V1.md`.
