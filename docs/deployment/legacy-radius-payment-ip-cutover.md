# Legacy billing/RADIUS cutover

Production cutover completed on 2026-10-21. The live targets are:

- Billing HTTP/HTTPS NAT: public `85.132.57.220` ports 80/443 map to `192.168.100.201`; the legacy VM's full 1:1 mapping has been removed and the inbound ACL is limited to those ports.
- RADIUS auth/accounting: `10.99.99.12:1812/1813`.
- Legacy VM 100: stopped, retained for rollback.

The guarded tools are:

- `tools/legacy-cutover-readiness.php` — read-only pre/post gates.
- `deploy/ubuntu-legacy-billing-cutover-helper.sh` — backup, payment reconciliation, ingress pause, worker activation and postcheck.
- `deploy/windows/invoke-legacy-billing-cutover.ps1` — orchestration and rollback.
- `tools/repair-radius-auth-from-vault.php` — restores active PPPoE `radcheck` rows from the encrypted vault without exposing secrets.

Rollback must pause new payment ingress first, disable external delivery, point RADIUS back to `10.99.99.10`, restore billing TCP 80/443 NAT to `192.168.100.11`, start VM 100, and only then resume payment ingress. Do not run both billing writers at the same time.
