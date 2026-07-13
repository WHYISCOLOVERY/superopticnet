[CmdletBinding()]
param(
    [string]$WebRoot = "D:\xampp\htdocs\superopticnet",
    [string]$MgsRoot = "D:\xampp\htdocs\oltidareetme",
    [string]$XamppRoot = "D:\xampp",
    [string]$AppUrl = "https://billing.superoptic.net",
    [string]$PublicSiteUrl = "https://superoptic.net/",
    [string]$DbHost = "127.0.0.1",
    [int]$DbPort = 3306,
    [string]$DbName = "superoptic_portal",
    [string]$DbUser = "superoptic_app",
    [string]$DbPass = "",
    [string]$RadiusSqlHost = "127.0.0.1",
    [int]$RadiusSqlPort = 3306,
    [string]$RadiusSqlDb = "superoptic_portal",
    [string]$RadiusSqlUser = "radius",
    [string]$RadiusSqlPassword = "",
    [string]$RadiusSharedSecret = "",
    [string]$RadiusCoreNasIp = "85.132.57.218",
    [string]$MgsInternalUrl = "http://127.0.0.1:3000",
    [string]$MgsServiceSecret = "",
    [switch]$OverwriteConfig,
    [switch]$InstallWingetPackages,
    [switch]$SkipMgsNpmInstall,
    [switch]$SkipStackAutostart,
    [switch]$SkipScheduledTasks,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

function Write-Step {
    param([string]$Message)
    Write-Host "[SuperOptic] $Message" -ForegroundColor Cyan
}

function Write-Warn {
    param([string]$Message)
    Write-Host "[WARN] $Message" -ForegroundColor Yellow
}

function Invoke-IfNeeded {
    param(
        [string]$Label,
        [scriptblock]$Action
    )
    if ($DryRun) {
        Write-Host "[DRY-RUN] $Label"
        return
    }
    Write-Step $Label
    & $Action
}

function Assert-Path {
    param([string]$Path, [string]$Name)
    if (-not (Test-Path -LiteralPath $Path)) {
        throw "$Name tapilmadi: $Path"
    }
}

function Get-FirstCommand {
    param([string[]]$Candidates)
    foreach ($candidate in $Candidates) {
        if (Test-Path -LiteralPath $candidate) {
            return $candidate
        }
        $cmd = Get-Command $candidate -ErrorAction SilentlyContinue
        if ($cmd) {
            return $cmd.Source
        }
    }
    return $null
}

function New-RandomSecret {
    $bytes = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
    return [Convert]::ToBase64String($bytes).TrimEnd("=").Replace("+", "x").Replace("/", "y")
}

function Read-SecretIfMissing {
    param([string]$CurrentValue, [string]$PromptText)
    if ($CurrentValue -ne "") {
        return $CurrentValue
    }
    if ($DryRun) {
        return "DRY_RUN_SECRET"
    }
    $secure = Read-Host -Prompt $PromptText -AsSecureString
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
    }
}

function Set-PhpConfigValue {
    param(
        [string]$Content,
        [string]$Key,
        [string]$Value
    )
    $escaped = $Value.Replace("\", "\\").Replace("'", "\'")
    $pattern = "('$([regex]::Escape($Key))'\s*=>\s*)'[^']*'"
    if ($Content -match $pattern) {
        return [regex]::Replace($Content, $pattern, "`${1}'$escaped'")
    }
    $insert = "    '$Key' => '$escaped',`r`n"
    return $Content -replace "return \[`r?`n", "return [`r`n$insert"
}

function Ensure-Config {
    $configPath = Join-Path $WebRoot "config.local.php"
    $templatePath = Join-Path $WebRoot "config.local.production.example.php"
    Assert-Path $templatePath "Production config template"

    if ((Test-Path -LiteralPath $configPath) -and -not $OverwriteConfig) {
        Write-Step "config.local.php movcuddur, overwrite edilmedi"
        return
    }

    $effectiveDbPass = Read-SecretIfMissing $DbPass "MySQL app password"
    $effectiveRadiusSqlPassword = Read-SecretIfMissing $RadiusSqlPassword "RADIUS SQL password"
    $effectiveRadiusSecret = Read-SecretIfMissing $RadiusSharedSecret "RADIUS shared secret"
    if ($MgsServiceSecret -eq "") {
        $MgsServiceSecret = New-RandomSecret
    }

    $content = Get-Content -LiteralPath $templatePath -Raw
    $values = [ordered]@{
        app_url = $AppUrl
        public_site_url = $PublicSiteUrl
        ticket_close_base_url = $AppUrl
        billing_mode = "mysql"
        portal_storage_driver = "mysql"
        db_host = $DbHost
        db_port = [string]$DbPort
        db_name = $DbName
        db_user = $DbUser
        db_pass = $effectiveDbPass
        radius_sql_host = $RadiusSqlHost
        radius_sql_port = [string]$RadiusSqlPort
        radius_sql_db = $RadiusSqlDb
        radius_sql_user = $RadiusSqlUser
        radius_sql_password = $effectiveRadiusSqlPassword
        radius_shared_secret = $effectiveRadiusSecret
        radius_core_nas_ip = $RadiusCoreNasIp
        mgs_internal_url = $MgsInternalUrl
        mgs_service_shared_secret = $MgsServiceSecret
        billing_customer_url = "$AppUrl/portal-login.php"
        billing_employee_url = "$AppUrl/staff-login.php"
        mygov_enabled = "0"
        mygov_mock_enabled = "0"
    }
    foreach ($entry in $values.GetEnumerator()) {
        $content = Set-PhpConfigValue -Content $content -Key $entry.Key -Value $entry.Value
    }

    Invoke-IfNeeded "config.local.php yazilir" {
        Set-Content -LiteralPath $configPath -Value $content -Encoding UTF8
    }
}

function Test-TcpPort {
    param([string]$HostName, [int]$Port)
    try {
        $client = New-Object Net.Sockets.TcpClient
        $async = $client.BeginConnect($HostName, $Port, $null, $null)
        $ok = $async.AsyncWaitHandle.WaitOne(2000, $false)
        if ($ok) { $client.EndConnect($async) }
        $client.Close()
        return $ok
    } catch {
        return $false
    }
}

function Register-ScheduledTaskCompat {
    param(
        [string]$Name,
        [string]$Command,
        [string]$Arguments,
        [string]$Schedule,
        [string]$Modifier = ""
    )
    if ($DryRun) {
        $modifierText = if ($Modifier -ne "") { " /MO $Modifier" } else { "" }
        Write-Host "[DRY-RUN] Scheduled task: $Name -> $Command $Arguments /SC $Schedule$modifierText"
        return
    }

    & schtasks.exe /Query /TN $Name *> $null
    if ($LASTEXITCODE -eq 0) {
        Invoke-IfNeeded "Scheduled task yenilenir: $Name" {
            & schtasks.exe /Delete /TN $Name /F | Out-Null
        }
    }

    Invoke-IfNeeded "Scheduled task yaradilir: $Name" {
        $tr = "`"$Command`" $Arguments"
        $args = @("/Create", "/TN", $Name, "/TR", $tr, "/SC", $Schedule, "/F")
        if ($Modifier -ne "") {
            $args += @("/MO", $Modifier)
        }
        & schtasks.exe @args | Out-Null
    }
}

Write-Step "Qurasdirma bashlayir"
Assert-Path $WebRoot "SuperOptic web root"
Assert-Path $MgsRoot "MGS root"
Assert-Path $XamppRoot "XAMPP root"

$php = Get-FirstCommand @((Join-Path $XamppRoot "php\php.exe"), "php.exe")
$mysql = Get-FirstCommand @((Join-Path $XamppRoot "mysql\bin\mysql.exe"), "mysql.exe")
$node = Get-FirstCommand @("node.exe")
$npm = Get-FirstCommand @("npm.cmd")
$java = Get-FirstCommand @("java.exe")
$snmpwalk = Get-FirstCommand @("snmpwalk.exe")
$winget = Get-FirstCommand @("winget.exe")

if (-not $php) { throw "PHP tapilmadi. XAMPP PHP lazimdir." }
if (-not $mysql) { Write-Warn "mysql.exe tapilmadi; DB bootstrap PHP PDO ile davam edecek." }
if (-not $node) { Write-Warn "Node.js tapilmadi." }
if (-not $npm) { Write-Warn "npm.cmd tapilmadi." }
if (-not $java) { Write-Warn "Java tapilmadi." }
if (-not $snmpwalk) { Write-Warn "snmpwalk.exe tapilmadi. SNMP live walk ucun Net-SNMP qurasdirilmalidir." }

if ($InstallWingetPackages -and $winget) {
    Invoke-IfNeeded "Winget paketleri yoxlanilir/qurasdirilir" {
        & $winget install --id OpenJS.NodeJS.LTS --silent --accept-package-agreements --accept-source-agreements
        & $winget install --id EclipseAdoptium.Temurin.21.JRE --silent --accept-package-agreements --accept-source-agreements
        & $winget install --id Insecure.Nmap --silent --accept-package-agreements --accept-source-agreements
    }
} elseif (-not $winget) {
    Write-Warn "winget tapilmadi; dependency auto-install kecildi."
}

if (-not (Test-TcpPort -HostName $DbHost -Port $DbPort)) {
    Write-Warn "MySQL ${DbHost}:${DbPort} cavab vermir. XAMPP MySQL service-ni qaldir."
}

Ensure-Config

Invoke-IfNeeded "PHP syntax ve DB bootstrap yoxlanilir" {
    & $php -l (Join-Path $WebRoot "config.php")
    if ($LASTEXITCODE -ne 0) { throw "config.php syntax error" }
    & $php -r "require '$($WebRoot.Replace('\','/'))/config.php'; `$db = app_db(); if (!(`$db instanceof PDO)) { fwrite(STDERR, 'db_unavailable'); exit(2); } echo 'db_bootstrap_ok'.PHP_EOL;"
    if ($LASTEXITCODE -ne 0) { throw "DB bootstrap ugursuz oldu" }
}

if (-not $SkipMgsNpmInstall) {
    if (-not $node -or -not $npm) {
        Write-Warn "Node/npm yoxdur; MGS npm/start addimi kecildi."
    } else {
        Invoke-IfNeeded "MGS npm install" {
            Push-Location $MgsRoot
            try {
                & $npm install
                if ($LASTEXITCODE -ne 0) { throw "npm install ugursuz oldu" }
            } finally {
                Pop-Location
            }
        }
        Invoke-IfNeeded "MGS DB setup/start" {
            Push-Location $MgsRoot
            try {
                $env:MGS_NONINTERACTIVE = "1"
                if (Test-Path -LiteralPath ".\setup-db.cmd") {
                    & ".\setup-db.cmd"
                }
                if (Test-Path -LiteralPath ".\start-mgs.cmd") {
                    & ".\start-mgs.cmd"
                }
            } finally {
                Remove-Item Env:\MGS_NONINTERACTIVE -ErrorAction SilentlyContinue
                Pop-Location
            }
        }
    }
}

if (-not $SkipScheduledTasks) {
    $phpArgsPrefix = "-NoProfile -ExecutionPolicy Bypass -Command "
    $maintenance = "`"& '$php' '$WebRoot\tools\billing-maintenance.php' --apply --sync-radius --queue-notices --channel=auto`""
    $queue = "`"& '$php' '$WebRoot\tools\process-communication-queue.php' --apply --limit=50 --channel=all`""
    $coa = "`"& '$php' '$WebRoot\tools\process-radius-session-actions.php' --apply --limit=50`""
    $bot = "`"& '$php' '$WebRoot\tools\process-telegram-modem-search-bot.php' --once`""
    $backup = "`"& '$php' '$WebRoot\tools\device-backup-worker.php' --apply --limit=10 --type=profile_snapshot`""
    $optimizer = "`"& '$php' '$WebRoot\tools\system-maintenance-optimizer.php' --apply --json`""
    $security = "`"& '$php' '$WebRoot\tools\security-anomaly-scan.php' --record --live`""
    $networkOltRules = "`"& '$php' '$WebRoot\tools\process-network-olt-ticket-rules.php' --apply`""

    Register-ScheduledTaskCompat -Name "SuperOptic MGS Billing Maintenance" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $maintenance) -Schedule "MINUTE" -Modifier "5"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Communication Queue" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $queue) -Schedule "MINUTE" -Modifier "2"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Radius CoA Worker" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $coa) -Schedule "MINUTE" -Modifier "1"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Modem Bot" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $bot) -Schedule "MINUTE" -Modifier "1"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Weekly Backup" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $backup) -Schedule "WEEKLY"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Daily Optimizer" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $optimizer) -Schedule "DAILY"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Security Anomaly Scan" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $security) -Schedule "MINUTE" -Modifier "5"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS OLT Ticket Rules" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $networkOltRules) -Schedule "MINUTE" -Modifier "5"
}

if (-not $SkipStackAutostart) {
    $autostartInstaller = Join-Path $WebRoot "deploy\windows\install-autostart.ps1"
    if (Test-Path -LiteralPath $autostartInstaller) {
        Invoke-IfNeeded "SuperOptic stack autostart task qurasdirilir" {
            $args = @(
                "-NoProfile",
                "-ExecutionPolicy", "Bypass",
                "-File", $autostartInstaller,
                "-WebRoot", $WebRoot,
                "-XamppRoot", $XamppRoot,
                "-MgsRoot", $MgsRoot
            )
            if ($SkipMgsNpmInstall) {
                $args += "-SkipMgs"
            }
            & powershell.exe @args
            if ($LASTEXITCODE -ne 0) { throw "Autostart task qurasdirilmadi" }
        }
    } else {
        Write-Warn "Autostart installer tapilmadi: $autostartInstaller"
    }
}

Write-Step "Qurasdirma tamamlandi"
Write-Host "Web root: $WebRoot"
Write-Host "MGS root: $MgsRoot"
Write-Host "App URL: $AppUrl"
Write-Host "RADIUS SQL: ${RadiusSqlHost}:${RadiusSqlPort}/$RadiusSqlDb"
