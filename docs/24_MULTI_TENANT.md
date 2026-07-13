# 24 Multi Tenant

## Purpose

Prepare MGS for multi-tenant, branch-aware and zone-aware operation.

## Required Scopes

- Tenant/company.
- Branch.
- Zone.
- Department.
- Role.
- Employee.
- Customer group.

## Required Data Isolation

- Customers belong to tenant/branch/zone where applicable.
- Devices and OLTs belong to tenant/branch/zone.
- Inventory stock can be tenant/branch scoped.
- Employees have scoped access.
- Provider APIs and credentials can be tenant-specific.
- Reports can be tenant/branch filtered.
- Backups record tenant context where applicable.

## Permission Rules

- Super admin can manage tenants only when authorized.
- Branch staff see only permitted branch/zone data.
- Customer portal sees only own data.
- Superpilot must never cross tenant/branch boundaries unless the user has permission.

## Future Requirements

- Tenant-aware migrations.
- Tenant-aware API tokens.
- Tenant-aware audit logs.
- Tenant-aware billing provider mappings.
- Tenant-level feature flags and configuration.
