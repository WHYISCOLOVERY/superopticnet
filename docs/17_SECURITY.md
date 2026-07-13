# 17 Security

## Purpose

Security Center protects the MGS platform, customer data, employee accounts, provider APIs and network operations.

## Philosophy

Zero Trust:

- Everything authenticated.
- Everything authorized.
- Everything audited.
- Least privilege by default.
- Dangerous operations require explicit confirmation.

## Required Controls

- Secure login for staff and customers.
- Brute-force protection and lockout/rate limit.
- Password hashing and secure reset flows.
- JWT session protection for future API stack.
- RBAC for every module and action.
- MFA for privileged staff.
- SQL injection protection through prepared statements/validated queries.
- CSRF protection for state-changing forms.
- Provider API IP whitelist and signature/idempotency where available.
- Nginx rate limits for login, OTP, provider APIs and public APIs.
- Audit logs for all sensitive reads and writes.
- Backup and restore access controls.

## Security Anomaly Engine

- Detect top talkers.
- Detect upload abuse.
- Detect NAT fan-out.
- Detect session storm.
- Correlate suspicious traffic with RADIUS, NAT logs, tickets and customers.
- Recommend ticket, customer notification, investigation, rate-limit or quarantine.
- Do not auto-quarantine without policy and confirmation.

## AI Safety

- Superpilot must never reveal data outside user permission.
- AI actions are audited.
- AI can recommend but must not blindly execute service cut, stock movement, financial change or network disruption.

## Testing

- Security Red Team agent tests.
- Login brute-force tests.
- SQL injection checks for new APIs.
- Authorization checks for every new action.
- Audit-log presence checks.
