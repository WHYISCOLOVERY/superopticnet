# NOC Scan And Update UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make IP scan results selectable before adding devices to monitoring, and expose update package file selection in the backup/update panel.

**Architecture:** Keep existing PHP/XAMPP pages and service APIs. `scan_network` continues to call `/api/service/network-scan`, but its success state is stored in session and rendered as a modal/list with per-host `create_monitor_device` forms. Update package handling stays behind the existing backup center action API, with the UI accepting either a typed path/URL or a selected `.zip` file path marker.

**Tech Stack:** PHP 8, MySQL/PDO, existing `billing-ui.php`, `mgs-integration-lib.php`, CLI smoke tests under `tools/`.

---

### Task 1: Lock RED Expectations

**Files:**
- Modify: `D:\xampp\htdocs\superopticnet\tools\native-network-monitor-smoke.php`
- Modify: `D:\xampp\htdocs\superopticnet\tools\backup-center-smoke.php`

- [ ] **Step 1: Add scan modal markers to NOC smoke**

`tools/native-network-monitor-smoke.php` must assert these markers in `noc-devices.php?view=monitor`:

```php
'data-noc-scan-dialog',
'data-noc-scan-results',
'data-scan-add-device',
'Seçilənləri monitora əlavə et',
'Scan tamamlandı. Tapılan cihazları seçib monitorinqə əlavə edin',
```

Expected before implementation: fail with missing modal/list markers.

- [ ] **Step 2: Add update file picker markers to backup smoke**

`tools/backup-center-smoke.php` must assert:

```php
'data-update-package-picker',
'type="file" name="update_package_file"',
'data-update-package-path',
'Fayl seç',
```

Expected before implementation: fail because current UI only exposes text inputs.

### Task 2: Render Selectable Scan Results

**Files:**
- Modify: `D:\xampp\htdocs\superopticnet\noc-devices.php`
- Modify: `D:\xampp\htdocs\superopticnet\operations-center.php`

- [ ] **Step 1: Change scan success text**

Change the scan success message from "data qatinda saxlanildi" to:

```php
'Scan tamamlandı. Tapılan cihazları seçib monitorinqə əlavə edin.'
```

- [ ] **Step 2: Keep scan results in session only**

Do not claim persistence until a `create_monitor_device` form is submitted. Store raw scan data in existing session state and read it as `$scanResults`.

- [ ] **Step 3: Add result cards**

Each host row renders:

```html
<article class="noc-scan-result" data-scan-add-device>
  <input type="checkbox" data-scan-select>
  <form method="post">
    <input type="hidden" name="action" value="create_monitor_device">
    <input name="device_ip" value="...">
    <input name="device_name" value="...">
    <select name="device_type">...</select>
    <button>Monitora əlavə et</button>
  </form>
</article>
```

### Task 3: Add Bulk Add UX

**Files:**
- Modify: `D:\xampp\htdocs\superopticnet\noc-devices.php`
- Modify: `D:\xampp\htdocs\superopticnet\operations-center.php`

- [ ] **Step 1: Add a small JavaScript helper**

The helper listens for `Seçilənləri monitora əlavə et` and submits checked per-host forms one by one. It uses normal POST fallback when JavaScript is unavailable.

- [ ] **Step 2: Preserve manual add**

The existing `Cihaz elave et` form stays available for manual entry.

### Task 4: Add Update Package File Picker

**Files:**
- Modify: `D:\xampp\htdocs\superopticnet\operations-center.php`

- [ ] **Step 1: Make forms multipart**

For `verify_update_package` and `apply_update_package`, set:

```html
enctype="multipart/form-data"
```

- [ ] **Step 2: Add visible file picker**

Each form gets:

```html
<input type="file" name="update_package_file" accept=".zip,application/zip" data-update-package-picker>
<input type="text" name="update_package" data-update-package-path>
```

The UI explains that typed path/URL still works.

- [ ] **Step 3: Add upload-to-temp handling if a file is selected**

Before calling `app_mgs_backup_center_action()`, move uploaded `.zip` to `tmp/update-packages/` and set `$options['update_package']` to that path. Reject missing/invalid extension.

### Task 5: Verify

**Files:**
- Test only.

- [ ] **Step 1: Run focused tests**

```powershell
D:\xampp\php\php.exe tools\native-network-monitor-smoke.php
D:\xampp\php\php.exe tools\backup-center-smoke.php
```

Expected: both exit 0.

- [ ] **Step 2: Run broader layout and regression checks**

```powershell
D:\xampp\php\php.exe tools\billing-ui-smoke.php
D:\xampp\php\php.exe tools\operations-module-smoke.php
D:\xampp\php\php.exe tools\mobile-layout-smoke.php
D:\xampp\php\php.exe -l noc-devices.php
D:\xampp\php\php.exe -l operations-center.php
```

Expected: all exit 0.
