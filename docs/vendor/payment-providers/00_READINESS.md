# Payment provider API readiness

Status: **STAGING CONTRACT - DO NOT USE AS A PRODUCTION ACTIVATION NOTICE**

The target production origin remains:

`https://billing.superoptic.net`

The pre-production acceptance origin is:

`https://mgt.superoptic.net`

The payment API routes described in this directory become production routes only
after SuperOptic sends a written activation notice. A DNS record, login page, or
successful TLS handshake alone is not an activation notice.

## Credential model

Each provider receives its own:

- public key ID;
- one-time private secret used in the bearer credential and HMAC-SHA256 signature;
- production source-IP allowlist;
- test and production activation dates.

Emanat and United Payment credentials are not interchangeable. No credential is
stored in this repository or embedded in the documentation.

The public key ID is an identifier, not an RSA/ECDSA public key. The private
secret is shown once and only its Argon2id hash is retained by SuperOptic.
If a provider requires asymmetric RSA/ECDSA exchange, that is a different
authentication profile and must be agreed before activation.

## Required provider information

Before production activation, each provider must return:

- legal provider name and technical contact;
- test and production source IP addresses;
- maximum timeout and retry policy;
- confirmation that custom HTTP headers are supported;
- confirmation that HMAC-SHA256 is supported;
- confirmation that the same transaction ID is reused on retries;
- planned test window.

## Operational contract

- TLS 1.2 or newer is required.
- Requests time out after 15 seconds on the provider side.
- Payment retries must reuse the same transaction ID and idempotency key.
- A successful payment response means the payment was durably recorded.
- RADIUS/service activation is asynchronous and is exposed through
  `payment_state`.
- Every response returns `X-Correlation-ID` and a JSON `correlation_id`.
- Provider credentials must be exchanged through an approved secure channel,
  never through email body, chat, source code, or a ticket comment.
