# Payment provider production cutover checklist

## SuperOptic

- [ ] `billing.superoptic.net` routes `/api/provider/` to the unified portal.
- [ ] `/api/health.php` returns HTTP 200 through the public TLS origin.
- [ ] Emanat and United Payment have separate public key IDs and private secrets.
- [ ] Shared legacy provider credentials are disabled.
- [ ] Provider production source IPs are configured.
- [ ] TLS certificate has at least 14 days remaining.
- [ ] Database backup and rollback point are verified.
- [ ] Payment outbox worker and timer are active.
- [ ] RADIUS action worker is active or deliberately isolated for the test.
- [ ] Duplicate payment, payload mismatch, retry, and status tests pass.
- [ ] Failed RADIUS action reaches retry/manual reconciliation without losing
      the payment.
- [ ] Audit records include provider, transaction ID, state, and correlation ID.
- [ ] No credential appears in source, documentation, logs, screenshots, or
      support tickets.

## Provider

- [ ] Test and production source IPs supplied.
- [ ] Custom auth/signature/idempotency headers confirmed.
- [ ] Clock is NTP synchronized.
- [ ] Retry reuses the same transaction ID and idempotency key.
- [ ] Provider has tested customer not found, invalid signature, timeout,
      duplicate, and payment status flows.
- [ ] Provider technical and incident contacts supplied.

## Activation gate

Production traffic starts only after both sides sign a test result containing:

- test customer code;
- provider transaction ID;
- SuperOptic correlation ID;
- payment amount;
- final payment state;
- UTC and Asia/Baku timestamps;
- explicit rollback owner.
