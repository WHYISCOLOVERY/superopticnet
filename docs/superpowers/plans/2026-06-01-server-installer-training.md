# Server Installer And Staff Training Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prepare SuperOptic MGS for one-time Windows server deployment and role-based staff training.

**Architecture:** The PHP billing portal remains the public/admin entry point under XAMPP/Apache/MySQL. The existing MGS Node application remains the operational engine for NOC, OLT/ONT, inventory, HR/KPI and automation; it is installed and started as a local Windows scheduled service task. FreeRADIUS is not installed by this package; the installer only records the RADIUS SQL/CoA connection details so the existing or future RADIUS server can be attached safely.

**Tech Stack:** Windows Server, XAMPP PHP/MySQL, Node.js 20+, Java runtime, SNMP tools, PowerShell, Windows Scheduled Tasks, SuperOptic PHP tools

---

### Task 1: Staff training documentation

**Files:**
- Create: `D:\xampp\htdocs\superopticnet\docs\role-training.md`

- [x] Document each operational role: super admin, operator, NOC engineer, technician, inventory, finance, HR/KPI, Superpilot user and customer portal support.
- [x] For every role, list the exact system areas, daily workflow, required checks, escalation rules and safety boundaries.
- [x] Keep customer portal guidance separate from employee workflows.

### Task 2: Windows server installation runbook

**Files:**
- Create: `D:\xampp\htdocs\superopticnet\docs\windows-server-install.md`

- [x] Document the target server/domain layout for `billing.superoptic.net`, `mgs.superoptic.net` and local services.
- [x] Document prerequisites and install order: XAMPP, PHP extensions, MySQL, Node, Java, SNMP tools, scheduled tasks and backup/update flow.
- [x] Document that FreeRADIUS is external and is connected by credentials after install.

### Task 3: Idempotent Windows installer script

**Files:**
- Create: `D:\xampp\htdocs\superopticnet\deploy\windows\install-superoptic-mgs.ps1`
- Create: `D:\xampp\htdocs\superopticnet\deploy\windows\SuperOptic-MGS-Install.cmd`

- [x] Add parameterized installer defaults for `D:\xampp\htdocs\superopticnet`, `D:\xampp\htdocs\oltidareetme`, MySQL and RADIUS.
- [x] Add dependency checks for PHP, MySQL, Node, Java, `snmpwalk` and `winget`.
- [x] Add safe config generation from `config.local.production.example.php` without overwriting existing `config.local.php` unless explicitly requested.
- [x] Add PHP DB bootstrap, MGS npm install/setup/start calls and scheduled task creation for billing maintenance, communication queue, modem bot, CoA worker and weekly backup.
- [x] Add dry-run mode for safe testing.

### Task 4: Installer package and EXE builder

**Files:**
- Create: `D:\xampp\htdocs\superopticnet\deploy\windows\build-installer-package.ps1`

- [x] Build a release folder with PHP portal, MGS Node app, installer scripts and docs.
- [x] Exclude runtime-heavy/generated folders such as `node_modules`, `tmp`, logs and test output.
- [x] Produce a zip package and, when `iexpress.exe` is available, a self-extracting `SuperOptic-MGS-Installer.exe`.

### Task 5: Verification

**Files:**
- Test: PowerShell parser checks for installer scripts
- Test: `D:\xampp\htdocs\superopticnet\tools\backup-center-smoke.php`
- Test: `D:\xampp\htdocs\superopticnet\tools\update-package-smoke.php`
- Test: `D:\xampp\htdocs\superopticnet\tools\provider-api-smoke.php`
- Test: `D:\xampp\htdocs\superopticnet\tools\provider-up-api-smoke.php`

- [ ] Run PowerShell syntax checks for the new scripts.
- [ ] Run the PHP smoke tests above.
- [ ] Run installer in `-DryRun` mode to verify command generation without changing services.
