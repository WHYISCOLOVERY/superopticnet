[CmdletBinding()]
param(
    [string]$WebRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$MgsRoot,
    [string]$OutputRoot,
    [string]$Version = (Get-Date -Format "yyyyMMdd-HHmmss"),
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version 2.0

function Get-FullPath {
    param([Parameter(Mandatory = $true)][string]$Path)
    return [IO.Path]::GetFullPath($Path).TrimEnd([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
}

function Test-PathWithin {
    param(
        [Parameter(Mandatory = $true)][string]$Candidate,
        [Parameter(Mandatory = $true)][string]$Base
    )

    $candidateFull = Get-FullPath -Path $Candidate
    $baseFull = Get-FullPath -Path $Base
    if ($candidateFull.Equals($baseFull, [StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }

    $basePrefix = $baseFull + [IO.Path]::DirectorySeparatorChar
    return $candidateFull.StartsWith($basePrefix, [StringComparison]::OrdinalIgnoreCase)
}

function Test-IsExcludedDirectory {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$RelativePath
    )

    $excludedNames = @(
        ".git", ".github", ".hg", ".svn", ".cache", ".nyc_output",
        ".agents", ".claude", ".codex", ".tools", ".ai-collaboration",
        ".wwebjs_auth", ".wwebjs_cache", "node_modules", "tmp", "logs",
        "test-results", "test_results", "coverage", "playwright-report",
        "allure-results", "allure-report", ".ssh", ".aws", ".azure", ".kube",
        "secrets", "credentials"
    )
    if ($excludedNames -contains $Name) {
        return $true
    }

    $normalized = $RelativePath.Replace("\", "/")
    return $normalized -match "(?i)(^|/)runtime/(?:\.cache|cache)(?:/|$)"
}

function Test-IsExcludedFile {
    param(
        [Parameter(Mandatory = $true)][IO.FileInfo]$File,
        [Parameter(Mandatory = $true)][string]$RelativePath
    )

    $name = $File.Name
    $lowerName = $name.ToLowerInvariant()
    $extension = $File.Extension.ToLowerInvariant()
    $normalized = $RelativePath.Replace("\", "/")

    if ($normalized -match "(?i)(^|/)data/(?:legacy-|test-)") {
        return $true
    }
    if ($normalized -match "(?i)(^|/)data/mock-portal\.php$") {
        return $true
    }
    if ($normalized -match "(?i)(^|/)data/(?:area-interest-log\.jsonl|db-bootstrap-ok\.json|portal-password-overrides\.json|portal-tickets\.json|portal-users\.json|rate-limits\.json|security-events\.jsonl|system-settings\.json|ticket-sequence\.txt|used-token-jti\.json)$") {
        return $true
    }

    if ($lowerName -eq "config.local.php" -or $lowerName -like ".env*") {
        return $true
    }
    if ($lowerName -in @(".npmrc", ".pypirc", ".netrc", ".htpasswd", "auth.json")) {
        return $true
    }
    if ($extension -in @(".pem", ".key", ".pfx", ".p12", ".jks", ".keystore", ".kdbx")) {
        return $true
    }
    if ($lowerName -match "^id_(?:rsa|dsa|ecdsa|ed25519)(?:\..+)?$") {
        return $true
    }
    if ($lowerName -match "^(?:credentials|secrets?|service-account(?:-.+)?)\.(?:json|ya?ml|ini|conf|txt)$") {
        return $true
    }
    if ($lowerName -like "*.log" -or $lowerName -like "*.pid") {
        return $true
    }
    if ($lowerName -eq ".phpunit.result.cache" -or $lowerName -like "*.trx") {
        return $true
    }
    if ($lowerName -match "^(?:junit|coverage|test-results?|playwright-results?)[._-].*\.(?:xml|json|html)$") {
        return $true
    }

    return $false
}

function Get-ReleaseInventory {
    param(
        [Parameter(Mandatory = $true)][string]$SourceRoot,
        [Parameter(Mandatory = $true)][string]$Component
    )

    $files = New-Object "System.Collections.Generic.List[object]"
    $excludedEntries = 0
    $root = Get-FullPath -Path $SourceRoot
    $rootPrefix = $root + [IO.Path]::DirectorySeparatorChar
    $stack = New-Object "System.Collections.Generic.Stack[IO.DirectoryInfo]"
    $stack.Push((Get-Item -LiteralPath $root -Force))

    while ($stack.Count -gt 0) {
        $directory = $stack.Pop()
        foreach ($entry in Get-ChildItem -LiteralPath $directory.FullName -Force -ErrorAction Stop) {
            $relativePath = $entry.FullName.Substring($rootPrefix.Length)
            $isReparsePoint = (($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)
            if ($entry.PSIsContainer) {
                if ($isReparsePoint -or (Test-IsExcludedDirectory -Name $entry.Name -RelativePath $relativePath)) {
                    $excludedEntries++
                    continue
                }
                $stack.Push($entry)
                continue
            }

            if ($isReparsePoint -or (Test-IsExcludedFile -File $entry -RelativePath $relativePath)) {
                $excludedEntries++
                continue
            }

            $relativeUnix = $relativePath.Replace("\", "/")
            $files.Add([pscustomobject]@{
                Component = $Component
                SourcePath = $entry.FullName
                BundlePath = "payload/$Component/$relativeUnix"
                Bytes = [int64]$entry.Length
            })
        }
    }

    return [pscustomobject]@{
        Files = $files.ToArray()
        ExcludedEntries = $excludedEntries
    }
}

function Test-IsTextFile {
    param([Parameter(Mandatory = $true)][string]$Path)

    $extension = [IO.Path]::GetExtension($Path).ToLowerInvariant()
    $textExtensions = @(
        ".bat", ".cjs", ".cmd", ".conf", ".config", ".css", ".csv", ".graphql",
        ".htm", ".html", ".ini", ".js", ".json", ".jsx", ".lock", ".md", ".mjs",
        ".php", ".properties", ".ps1", ".py", ".sh", ".sql", ".svg", ".toml", ".ts",
        ".tsx", ".txt", ".vue", ".xml", ".yaml", ".yml"
    )
    if ($textExtensions -contains $extension) {
        return $true
    }

    return ([IO.Path]::GetFileName($Path).ToLowerInvariant() -in @("dockerfile", "makefile", "procfile"))
}

function Test-IsPlaceholderValue {
    param([Parameter(Mandatory = $true)][string]$Value)

    $candidate = $Value.Trim().Trim([char[]]@([char]34, [char]39, [char]96))
    if ([string]::IsNullOrWhiteSpace($candidate)) {
        return $true
    }
    if ($candidate -match "^(?:\$|%[^%]+%$|<[^>]+>$|\{\{.+\}\}$)" -or $candidate -match "(?:%[a-zA-Z]|\$\{|\{[A-Za-z0-9_]+\})") {
        return $true
    }
    if ($candidate -match "(?i)^(?:getenv|env|process\.env|system\.getenv)\b") {
        return $true
    }
    if ($candidate -cmatch '^[A-Z][A-Z0-9_]+$') {
        return $true
    }
    if ($candidate -match "^[*xX._-]+$") {
        return $true
    }

    $lower = $candidate.ToLowerInvariant()
    return $lower -match "^(?:change(?:me)?|replace(?:me)?|placeholder|example|sample|dummy|test|smoke|fake|mock|redacted|your|use|generate(?:d)?|insert|todo|unset|not[-_]?set|null|none|password|pass|strong|burada|ci[-_]?secret|secret|token)(?:$|[-_./])"
}

function Test-IsSensitiveValueKey {
    param([Parameter(Mandatory = $true)][string]$Key)

    $normalized = $Key.Trim().TrimStart('$').Replace("-", "_").Replace(".", "_").ToLowerInvariant()
    if ($normalized -match "_(?:label|message|error|failed|required|hint|help|title|text|source|name|field|column|status|type|path|file|url|count|present|configured|exists|encrypted|ciphertext)$") {
        return $false
    }
    if ($normalized -match "_key$" -and $normalized -notmatch "(?:^|_)(?:api|private|access|auth)_key$") {
        return $false
    }
    return $true
}

function Test-IsProbableSecretLiteral {
    param(
        [Parameter(Mandatory = $true)][string]$Key,
        [Parameter(Mandatory = $true)][string]$Value
    )

    if (-not (Test-IsSensitiveValueKey -Key $Key) -or (Test-IsPlaceholderValue -Value $Value)) {
        return $false
    }
    $candidate = $Value.Trim().Trim([char[]]@([char]34, [char]39, [char]96))
    if ($candidate -match "\s" -or $candidate.Length -lt 8) {
        return $false
    }

    $classes = 0
    if ($candidate -cmatch '[a-z]') { $classes++ }
    if ($candidate -cmatch '[A-Z]') { $classes++ }
    if ($candidate -match '[0-9]') { $classes++ }
    if ($candidate -match '[^A-Za-z0-9]') { $classes++ }
    return $classes -ge 3 -or $candidate.Length -ge 24
}

function Find-SecretPatterns {
    param([Parameter(Mandatory = $true)][object[]]$Files)

    $signaturePatterns = @(
        [pscustomobject]@{ Id = "private-key"; Markers = @("PRIVATE KEY"); Regex = [regex]::new("-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----") },
        [pscustomobject]@{ Id = "aws-access-key"; Markers = @("AKIA", "ASIA"); Regex = [regex]::new("\b(?:AKIA|ASIA)[A-Z0-9]{16}\b") },
        [pscustomobject]@{ Id = "github-token"; Markers = @("ghp_", "gho_", "ghu_", "ghs_", "ghr_", "github_pat_"); Regex = [regex]::new("\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})\b") },
        [pscustomobject]@{ Id = "gitlab-token"; Markers = @("glpat-"); Regex = [regex]::new("\bglpat-[A-Za-z0-9_-]{20,}\b") },
        [pscustomobject]@{ Id = "slack-token"; Markers = @("xoxb-", "xoxa-", "xoxp-", "xoxr-", "xoxs-"); Regex = [regex]::new("\bxox[baprs]-[A-Za-z0-9-]{20,}\b") },
        [pscustomobject]@{ Id = "stripe-live-key"; Markers = @("sk_live_"); Regex = [regex]::new("\bsk_live_[A-Za-z0-9]{16,}\b") },
        [pscustomobject]@{ Id = "google-api-key"; Markers = @("AIza"); Regex = [regex]::new("\bAIza[A-Za-z0-9_-]{35}\b") },
        [pscustomobject]@{ Id = "npm-token"; Markers = @("npm_"); Regex = [regex]::new("\bnpm_[A-Za-z0-9]{30,}\b") },
        [pscustomobject]@{ Id = "model-api-key"; Markers = @("sk-"); Regex = [regex]::new("\bsk-[A-Za-z0-9_-]{20,}\b") },
        [pscustomobject]@{ Id = "jwt-token"; Markers = @("eyJ"); Regex = [regex]::new("\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b") }
    )
    $sensitiveKey = '(?:password|passwd|pwd|secret|token|api[_-]?key|private[_-]?key|client[_-]?secret|access[_-]?key|auth[_-]?key)'
    $uppercaseSensitiveKey = '(?:PASSWORD|PASSWD|PWD|SECRET|TOKEN|API[_-]?KEY|PRIVATE[_-]?KEY|CLIENT[_-]?SECRET|ACCESS[_-]?KEY|AUTH[_-]?KEY)'
    $quotedKeyAssignment = [regex]::new(('(?i)(?:^|[\[{{}},])\s*["'']([A-Za-z0-9_.-]*{0}[A-Za-z0-9_.-]*)["'']\s*(?:=>|:|=)\s*(["''])([^"''\r\n]{{6,512}})\2' -f $sensitiveKey))
    $yamlKeyAssignment = [regex]::new(('(?i)^\s*([A-Za-z0-9_.-]*{0}[A-Za-z0-9_.-]*)\s*:\s*(["''])([^"''\r\n]{{6,512}})\2' -f $sensitiveKey))
    $quotedVariableAssignment = [regex]::new(('(?i)(?:^|[;\s])\$?([A-Za-z0-9_.-]*{0}[A-Za-z0-9_.-]*)\s*=\s*(["''])([^"''\r\n]{{6,512}})\2' -f $sensitiveKey))
    $environmentAssignment = [regex]::new(('^\s*(?:export\s+)?([A-Z0-9_.-]*{0}[A-Z0-9_.-]*)\s*=\s*([^\s#;]+)\s*(?:#.*)?$' -f $uppercaseSensitiveKey))
    $credentialUrl = [regex]::new('(?i)\b[a-z][a-z0-9+.-]*://[^/\s:@]+:([^@\s/]+)@')
    $sensitiveMarkers = @("password", "passwd", "pwd", "secret", "token", "api_key", "api-key", "apikey", "private_key", "private-key", "privatekey", "client_secret", "client-secret", "clientsecret", "access_key", "access-key", "accesskey", "auth_key", "auth-key", "authkey")
    $findings = New-Object "System.Collections.Generic.List[object]"
    $findingKeys = New-Object "System.Collections.Generic.HashSet[string]"
    $findingCount = 0

    foreach ($file in $Files) {
        if (-not (Test-IsTextFile -Path $file.SourcePath)) {
            continue
        }

        $normalizedBundlePath = $file.BundlePath.Replace("\", "/")
        $syntheticFixture = $normalizedBundlePath -match "(?i)(^|/)(?:tests?/|tools/[^/]*(?:smoke|contract|fixture|test)[^/]*)"
        $lineNumber = 0
        foreach ($line in [IO.File]::ReadLines($file.SourcePath)) {
            $lineNumber++
            $rules = New-Object "System.Collections.Generic.List[string]"
            foreach ($pattern in $signaturePatterns) {
                $hasMarker = $false
                foreach ($marker in $pattern.Markers) {
                    if ($line.IndexOf($marker, [StringComparison]::OrdinalIgnoreCase) -ge 0) {
                        $hasMarker = $true
                        break
                    }
                }
                if ($hasMarker -and $pattern.Regex.IsMatch($line)) {
                    $rules.Add($pattern.Id)
                }
            }

            $hasSensitiveMarker = $false
            foreach ($marker in $sensitiveMarkers) {
                if ($line.IndexOf($marker, [StringComparison]::OrdinalIgnoreCase) -ge 0) {
                    $hasSensitiveMarker = $true
                    break
                }
            }
            if ($hasSensitiveMarker -and -not $syntheticFixture) {
                foreach ($match in $quotedKeyAssignment.Matches($line)) {
                    if (Test-IsProbableSecretLiteral -Key $match.Groups[1].Value -Value $match.Groups[3].Value) {
                        $rules.Add("secret-literal")
                    }
                }
                foreach ($match in $yamlKeyAssignment.Matches($line)) {
                    if (Test-IsProbableSecretLiteral -Key $match.Groups[1].Value -Value $match.Groups[3].Value) {
                        $rules.Add("secret-literal")
                    }
                }
                foreach ($match in $quotedVariableAssignment.Matches($line)) {
                    if (Test-IsProbableSecretLiteral -Key $match.Groups[1].Value -Value $match.Groups[3].Value) {
                        $rules.Add("secret-literal")
                    }
                }
                foreach ($match in $environmentAssignment.Matches($line)) {
                    if (Test-IsProbableSecretLiteral -Key $match.Groups[1].Value -Value $match.Groups[2].Value) {
                        $rules.Add("secret-literal")
                    }
                }
            }
            if (-not $syntheticFixture -and $line.IndexOf("://", [StringComparison]::Ordinal) -ge 0 -and $line.IndexOf("@", [StringComparison]::Ordinal) -ge 0) {
                foreach ($match in $credentialUrl.Matches($line)) {
                    if (-not (Test-IsPlaceholderValue -Value $match.Groups[1].Value)) {
                        $rules.Add("credential-url")
                    }
                }
            }

            foreach ($rule in ($rules | Select-Object -Unique)) {
                $key = "$($file.BundlePath)|$lineNumber|$rule"
                if (-not $findingKeys.Add($key)) {
                    continue
                }
                $findingCount++
                if ($findings.Count -lt 100) {
                    $findings.Add([pscustomobject]@{
                        Path = $file.BundlePath
                        Line = $lineNumber
                        Rule = $rule
                    })
                }
            }
        }
    }

    return [pscustomobject]@{
        Count = $findingCount
        Findings = $findings.ToArray()
        Truncated = ($findingCount -gt $findings.Count)
    }
}

function Write-Utf8NoBom {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Content
    )
    $encoding = New-Object Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($Path, $Content, $encoding)
}

function Get-Sha256 {
    param([Parameter(Mandatory = $true)][string]$Path)
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Remove-SafeTempDirectory {
    param([Parameter(Mandatory = $true)][string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        return
    }
    $tempBase = Get-FullPath -Path ([IO.Path]::GetTempPath())
    $full = Get-FullPath -Path $Path
    if (-not (Test-PathWithin -Candidate $full -Base $tempBase) -or $full.Equals($tempBase, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to remove unsafe temporary path: $full"
    }
    Remove-Item -LiteralPath $full -Recurse -Force
}

if (-not (Test-Path -LiteralPath $WebRoot -PathType Container)) {
    throw "WebRoot not found: $WebRoot"
}
$resolvedWebRoot = Get-FullPath -Path (Resolve-Path -LiteralPath $WebRoot).Path

if ([string]::IsNullOrWhiteSpace($MgsRoot)) {
    $MgsRoot = Join-Path (Split-Path -Parent $resolvedWebRoot) "oltidareetme"
}
$mgsIncluded = Test-Path -LiteralPath $MgsRoot -PathType Container
$resolvedMgsRoot = if ($mgsIncluded) { Get-FullPath -Path (Resolve-Path -LiteralPath $MgsRoot).Path } else { Get-FullPath -Path $MgsRoot }

if ([string]::IsNullOrWhiteSpace($OutputRoot)) {
    $OutputRoot = Join-Path ([IO.Path]::GetPathRoot($resolvedWebRoot)) "superoptic-release"
}
$resolvedOutputRoot = Get-FullPath -Path $OutputRoot

if ($Version -notmatch "^[A-Za-z0-9][A-Za-z0-9._-]*$") {
    throw "Version may contain only letters, digits, dot, underscore and dash."
}
if ((Test-PathWithin -Candidate $resolvedOutputRoot -Base $resolvedWebRoot) -or ($mgsIncluded -and (Test-PathWithin -Candidate $resolvedOutputRoot -Base $resolvedMgsRoot))) {
    throw "OutputRoot must be outside payload source trees: $resolvedOutputRoot"
}

$archiveName = "superoptic-ubuntu-$Version.zip"
$zipPath = Join-Path $resolvedOutputRoot $archiveName
$zipChecksumPath = "$zipPath.sha256"
if (-not $DryRun -and ((Test-Path -LiteralPath $zipPath) -or (Test-Path -LiteralPath $zipChecksumPath))) {
    throw "Release output already exists for version '$Version'."
}

$webInventory = Get-ReleaseInventory -SourceRoot $resolvedWebRoot -Component "superopticnet"
$mgsInventory = if ($mgsIncluded) { Get-ReleaseInventory -SourceRoot $resolvedMgsRoot -Component "oltidareetme" } else { $null }
$files = @($webInventory.Files)
if ($mgsIncluded) {
    $files += @($mgsInventory.Files)
}
$files = @($files | Sort-Object BundlePath)

$totalBytes = [int64]0
foreach ($file in $files) {
    $totalBytes += $file.Bytes
}
$excludedEntries = [int]$webInventory.ExcludedEntries
if ($mgsIncluded) {
    $excludedEntries += [int]$mgsInventory.ExcludedEntries
}

$scan = Find-SecretPatterns -Files $files
$componentSummary = @(
    [pscustomobject]@{ Name = "superopticnet"; Included = $true; FileCount = @($files | Where-Object Component -eq "superopticnet").Count },
    [pscustomobject]@{ Name = "oltidareetme"; Included = [bool]$mgsIncluded; FileCount = @($files | Where-Object Component -eq "oltidareetme").Count }
)

if ($scan.Count -gt 0) {
    [pscustomobject]@{
        Status = "blocked"
        Reason = "secret-pattern-findings"
        FindingCount = $scan.Count
        Findings = $scan.Findings
        FindingsTruncated = $scan.Truncated
        ArchiveCreated = $false
    } | ConvertTo-Json -Depth 6
    exit 2
}

if ($DryRun) {
    [pscustomobject]@{
        Status = "dry-run"
        ArchiveCreated = $false
        WouldCreate = @($zipPath, $zipChecksumPath)
        Components = $componentSummary
        FileCount = $files.Count
        TotalBytes = $totalBytes
        ExcludedEntries = $excludedEntries
        SecretFindingCount = 0
    } | ConvertTo-Json -Depth 6
    exit 0
}

$tempRunRoot = Join-Path ([IO.Path]::GetTempPath()) ("superoptic-ubuntu-release-" + [Guid]::NewGuid().ToString("N"))
$stagingRoot = Join-Path $tempRunRoot "bundle"
$tempZip = Join-Path $tempRunRoot $archiveName
$tempChecksum = "$tempZip.sha256"
$publishedZip = $false

try {
    New-Item -ItemType Directory -Path $stagingRoot -Force | Out-Null
    $manifestFiles = New-Object "System.Collections.Generic.List[object]"
    $stagedScanFiles = New-Object "System.Collections.Generic.List[object]"

    foreach ($file in $files) {
        $relativeWindows = $file.BundlePath.Replace("/", [IO.Path]::DirectorySeparatorChar)
        $destination = Join-Path $stagingRoot $relativeWindows
        $destinationDirectory = Split-Path -Parent $destination
        if (-not (Test-Path -LiteralPath $destinationDirectory -PathType Container)) {
            New-Item -ItemType Directory -Path $destinationDirectory -Force | Out-Null
        }
        Copy-Item -LiteralPath $file.SourcePath -Destination $destination -Force
        $stagedScanFiles.Add([pscustomobject]@{
            Component = $file.Component
            SourcePath = $destination
            BundlePath = $file.BundlePath
            Bytes = [int64](Get-Item -LiteralPath $destination).Length
        })
        $manifestFiles.Add([pscustomobject]@{
            Path = $file.BundlePath
            Bytes = [int64](Get-Item -LiteralPath $destination).Length
            Sha256 = Get-Sha256 -Path $destination
        })
    }

    $stagedScan = Find-SecretPatterns -Files $stagedScanFiles.ToArray()
    if ($stagedScan.Count -gt 0) {
        [pscustomobject]@{
            Status = "blocked"
            Reason = "staged-secret-pattern-findings"
            FindingCount = $stagedScan.Count
            Findings = $stagedScan.Findings
            FindingsTruncated = $stagedScan.Truncated
            ArchiveCreated = $false
        } | ConvertTo-Json -Depth 6
        exit 2
    }

    $manifest = [ordered]@{
        SchemaVersion = 1
        Bundle = "superoptic-ubuntu-release"
        Version = $Version
        CreatedUtc = [DateTimeOffset]::UtcNow.ToString("o")
        Components = $componentSummary
        FileCount = $manifestFiles.Count
        TotalBytes = $totalBytes
        Files = $manifestFiles.ToArray()
    }
    $manifestPath = Join-Path $stagingRoot "manifest.json"
    Write-Utf8NoBom -Path $manifestPath -Content (($manifest | ConvertTo-Json -Depth 8) + "`n")

    $checksumLines = New-Object "System.Collections.Generic.List[string]"
    foreach ($entry in $manifestFiles) {
        $checksumLines.Add("$($entry.Sha256)  $($entry.Path)")
    }
    $checksumLines.Add("$(Get-Sha256 -Path $manifestPath)  manifest.json")
    Write-Utf8NoBom -Path (Join-Path $stagingRoot "SHA256SUMS") -Content (($checksumLines -join "`n") + "`n")

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zipArchive = [IO.Compression.ZipFile]::Open($tempZip, [IO.Compression.ZipArchiveMode]::Create)
    try {
        $stagingPrefix = (Get-FullPath -Path $stagingRoot) + [IO.Path]::DirectorySeparatorChar
        foreach ($stagedFile in Get-ChildItem -LiteralPath $stagingRoot -Recurse -File -Force | Sort-Object FullName) {
            $entryName = $stagedFile.FullName.Substring($stagingPrefix.Length).Replace("\", "/")
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zipArchive,
                $stagedFile.FullName,
                $entryName,
                [IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally {
        $zipArchive.Dispose()
    }
    $archiveHash = Get-Sha256 -Path $tempZip
    Write-Utf8NoBom -Path $tempChecksum -Content ("$archiveHash  $archiveName`n")

    New-Item -ItemType Directory -Path $resolvedOutputRoot -Force | Out-Null
    Move-Item -LiteralPath $tempZip -Destination $zipPath
    $publishedZip = $true
    Move-Item -LiteralPath $tempChecksum -Destination $zipChecksumPath

    [pscustomobject]@{
        Status = "ok"
        ArchiveCreated = $true
        Zip = $zipPath
        ZipSha256 = $zipChecksumPath
        ArchiveHash = $archiveHash
        Components = $componentSummary
        FileCount = $manifestFiles.Count
        TotalBytes = $totalBytes
        ExcludedEntries = $excludedEntries
        SecretFindingCount = 0
    } | ConvertTo-Json -Depth 6
} catch {
    if ($publishedZip -and (Test-Path -LiteralPath $zipPath)) {
        Remove-Item -LiteralPath $zipPath -Force
    }
    if (Test-Path -LiteralPath $zipChecksumPath) {
        Remove-Item -LiteralPath $zipChecksumPath -Force
    }
    throw
} finally {
    Remove-SafeTempDirectory -Path $tempRunRoot
}
