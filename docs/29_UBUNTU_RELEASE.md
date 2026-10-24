# 29 Ubuntu Release Bundle

## Purpose

`deploy/build-ubuntu-release.ps1` builds a clean Ubuntu deployment ZIP on Windows. The archive has no wrapper directory and contains:

- `payload/superopticnet/`
- `payload/oltidareetme/` when the sibling project exists
- `manifest.json` with component, size and per-file SHA-256 metadata
- `SHA256SUMS` for every payload file and `manifest.json`

The output directory also receives `<archive>.zip.sha256`, which verifies the ZIP before extraction.

## Build

Run from the `superopticnet` repository in Windows PowerShell 5.1 or newer:

```powershell
.\deploy\build-ubuntu-release.ps1
```

The defaults are:

- web root: the repository containing the script
- MGS root: sibling `oltidareetme`
- output root: `<source-drive>:\superoptic-release`
- version: current local timestamp in `yyyyMMdd-HHmmss` format

Use an explicit release version for a named artifact:

```powershell
.\deploy\build-ubuntu-release.ps1 -Version 2026.07.17
```

Override paths when the repositories are elsewhere:

```powershell
.\deploy\build-ubuntu-release.ps1 `
  -WebRoot D:\xampp\htdocs\superopticnet `
  -MgsRoot D:\xampp\htdocs\oltidareetme `
  -OutputRoot D:\superoptic-release `
  -Version 2026.07.17
```

`oltidareetme` is optional. If that directory does not exist, the bundle contains only `payload/superopticnet`. The output root must be outside both source trees. Existing artifacts with the same version are never overwritten.

## Dry Run

Dry-run performs source enumeration, exclusions and the complete secret-pattern scan. It returns the planned paths, component state, file count, byte count and exclusion count as JSON, but creates no output directory, staging directory or archive.

```powershell
.\deploy\build-ubuntu-release.ps1 -Version release-candidate -DryRun
```

Exit codes:

- `0`: dry-run is clean, or the bundle was created successfully
- `2`: secret-pattern findings blocked the bundle
- `1`: invalid path, duplicate version, I/O failure or another build error

## Exclusions

The builder excludes these items at any depth:

- VCS, CI metadata and dependency data: `.git`, `.github`, `.hg`, `.svn`, `node_modules`
- local configuration: `config.local.php`, `.env*`
- runtime data: `tmp`, `logs`, log files, PID files, runtime caches and browser-session caches
- local agent workspaces: `.agents`, `.claude`, `.codex`, `.tools`, `.ai-collaboration`
- development-only portal fixtures and mutable snapshots: `data/mock-portal.php`, `data/legacy-*`, `data/test-*`, token/rate-limit/settings/audit JSON files
- test output: `test-results`, coverage, Playwright/Allure reports, JUnit/result files and `*.trx`
- secret material: private-key/certificate stores, SSH private-key names, credential/secret data files, `.npmrc`, `.pypirc`, `.netrc` and `.htpasswd`
- directory links, junctions and file reparse points, so payload discovery cannot escape a source root

Test source directories are retained; only generated test results are removed. `.env.example` is also removed because the release rule excludes all `.env*` files. Configure secrets on the Ubuntu host through the documented secret bootstrap, never by adding a populated file to the archive.

## Secret Gate

Every included text/config/source file is scanned before any output or staging directory is created. Normal builds scan the copied staging payload again before ZIP creation, closing the window where a source file could change after the first scan. The scan detects:

- private-key headers
- common AWS, GitHub, GitLab, Slack, Stripe, Google, npm, model-provider `sk-*`, JWT and private-key credential formats
- concrete literals assigned to password, secret, token and key-like names
- URLs with embedded credentials

Recognized placeholders and environment-variable references are allowed. Synthetic smoke/contract literals are ignored by the generic assignment rule, but concrete provider signatures are blocked in every file, including tests. A finding returns JSON containing only the bundle-relative path, line number and rule ID. The matching value is not printed. Any finding exits with code `2`, and no ZIP or checksum artifact is created.

Treat the scan as a release guard, not as proof that no secret exists. It intentionally scans text-like files and known credential formats; rotate credentials separately if repository history or source provenance is uncertain.

## Verify

Verify the archive on Windows before transfer:

```powershell
Get-FileHash D:\superoptic-release\superoptic-ubuntu-2026.07.17.zip -Algorithm SHA256
Get-Content D:\superoptic-release\superoptic-ubuntu-2026.07.17.zip.sha256
```

After transferring both files to Ubuntu, verify and extract into a new directory:

```bash
sha256sum -c superoptic-ubuntu-2026.07.17.zip.sha256
mkdir superoptic-ubuntu-2026.07.17
unzip -q superoptic-ubuntu-2026.07.17.zip -d superoptic-ubuntu-2026.07.17
cd superoptic-ubuntu-2026.07.17
sha256sum -c SHA256SUMS
```

Inspect `manifest.json`, take application/database restore points, and then deploy the two payload directories according to the Ubuntu installation and cutover procedure. Create production configuration and secrets on the server after extraction; they are deliberately absent from the bundle.

## Contract Test

Run the self-contained smoke test from Windows. It uses temporary fixture repositories and cleans them afterward:

```powershell
D:\xampp\php\php.exe .\tools\ubuntu-release-bundle-smoke.php
```

The contract covers dry-run side effects, both payload components, exclusions, manifest content, internal and external SHA-256 verification, and the no-artifact behavior when a secret pattern is found.
