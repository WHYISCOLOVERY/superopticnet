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
    [string]$RadiusSqlHost = "127.0.0.1",
    [int]$RadiusSqlPort = 3306,
    [string]$RadiusSqlDb = "superoptic_portal",
    [string]$RadiusSqlUser = "radius",
    [string]$RadiusCoreNasIp = "85.132.57.218",
    [string]$RadiusServerHost = "",
    [int]$RadiusAuthPort = 1812,
    [int]$RadiusAcctPort = 1813,
    [string]$MgsInternalUrl = "http://127.0.0.1:3000",
    [switch]$OverwriteConfig,
    [switch]$InstallWingetPackages,
    [switch]$SkipMgsNpmInstall,
    [switch]$SkipStackAutostart,
    [switch]$SkipScheduledTasks,
    [switch]$NonInteractive,
    [switch]$RotateSecrets,
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
        $existingContent = Get-Content -LiteralPath $configPath -Raw
        if ($existingContent -match "'(db_pass|radius_sql_password|radius_shared_secret|mgs_service_shared_secret)'\s*=>\s*'[^']+'") {
            Write-Warn "Existing config.local.php may contain file-based secrets. Environment values take precedence; rotate them and use -OverwriteConfig during a controlled scrub."
        }
        return
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
        radius_sql_host = $RadiusSqlHost
        radius_sql_port = [string]$RadiusSqlPort
        radius_sql_db = $RadiusSqlDb
        radius_sql_user = $RadiusSqlUser
        radius_core_nas_ip = $RadiusCoreNasIp
        radius_auth_port = [string]$RadiusAuthPort
        radius_acct_port = [string]$RadiusAcctPort
        mgs_internal_url = $MgsInternalUrl
        billing_customer_url = "$AppUrl/portal-login.php"
        billing_employee_url = "$AppUrl/staff-login.php"
        mygov_enabled = "0"
        mygov_mock_enabled = "0"
    }
    if ($RadiusServerHost -ne "") {
        $values["radius_server_ip"] = $RadiusServerHost
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

function Assert-PortNumber {
    param([string]$Name, [int]$Port)
    if ($Port -lt 1 -or $Port -gt 65535) {
        throw "$Name must be between 1 and 65535"
    }
}

function Test-RadiusPortReadiness {
    Assert-PortNumber -Name "RadiusAuthPort" -Port $RadiusAuthPort
    Assert-PortNumber -Name "RadiusAcctPort" -Port $RadiusAcctPort
    if ($RadiusAuthPort -eq $RadiusAcctPort) {
        throw "RADIUS auth/accounting ports must be different"
    }
    if ($RadiusAuthPort -ne 1812) {
        Write-Warn "Non-standard RADIUS authentication port configured: $RadiusAuthPort (standard is 1812/UDP)."
    }
    if ($RadiusAcctPort -ne 1813) {
        Write-Warn "Non-standard RADIUS accounting port configured: $RadiusAcctPort (standard is 1813/UDP)."
    }
    if ($DryRun) {
        Write-Host "[DRY-RUN] Would resolve/check RADIUS UDP endpoint $RadiusServerHost on $RadiusAuthPort/$RadiusAcctPort without changing Windows Firewall."
        return
    }

    if ($RadiusServerHost -eq "") {
        Write-Warn "RadiusServerHost is empty; endpoint checks for UDP $RadiusAuthPort/$RadiusAcctPort were skipped."
    } else {
        try {
            [void][Net.Dns]::GetHostAddresses($RadiusServerHost)
            $isLocal = $RadiusServerHost -in @("127.0.0.1", "localhost", "::1")
            $udpCommand = Get-Command Get-NetUDPEndpoint -ErrorAction SilentlyContinue
            if ($isLocal -and $udpCommand) {
                $listeners = @(Get-NetUDPEndpoint -ErrorAction SilentlyContinue | Select-Object -ExpandProperty LocalPort)
                if ($RadiusAuthPort -notin $listeners) {
                    Write-Warn "No local UDP listener detected on RADIUS authentication port $RadiusAuthPort."
                }
                if ($RadiusAcctPort -notin $listeners) {
                    Write-Warn "No local UDP listener detected on RADIUS accounting port $RadiusAcctPort."
                }
            } else {
                Write-Warn "RADIUS uses UDP; DNS resolution passed, but authenticated traffic is required to prove remote reachability on $RadiusAuthPort/$RadiusAcctPort."
            }
        } catch {
            Write-Warn "RADIUS server host does not resolve: $RadiusServerHost"
        }
    }
    Write-Warn "Windows Firewall was not changed. Permit RADIUS UDP only between explicit NAS and RADIUS server addresses."
}

function Initialize-ServiceSecrets {
    $scriptPath = Join-Path $WebRoot "deploy\windows\Set-SuperOptic-Secrets.ps1"
    Assert-Path $scriptPath "Secret bootstrap script"
    $parameters = @{
        Target = "Machine"
        DbHost = $DbHost
        DbPort = $DbPort
        DbName = $DbName
        DbUser = $DbUser
        RadiusDbHost = $RadiusSqlHost
        RadiusDbPort = $RadiusSqlPort
        RadiusDbName = $RadiusSqlDb
        RadiusDbUser = $RadiusSqlUser
    }
    if ($DryRun) {
        $parameters.DryRun = $true
    } elseif ($NonInteractive) {
        $parameters.NonInteractive = $true
    }
    if ($RotateSecrets) {
        $parameters.Rotate = $true
    }
    Write-Step "OS service secrets yoxlanilir/qurasdirilir"
    & $scriptPath @parameters
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
        Write-Host "[DRY-RUN] Scheduled task: $Name -> $Command $Arguments /SC $Schedule$modifierText /RU LOCAL SERVICE /RL LIMITED"
        return
    }

    $scheduledTaskCommands = @('New-ScheduledTaskAction', 'New-ScheduledTaskTrigger', 'New-ScheduledTaskPrincipal', 'Register-ScheduledTask')
    foreach ($commandName in $scheduledTaskCommands) {
        if (-not (Get-Command $commandName -ErrorAction SilentlyContinue)) {
            throw "ScheduledTasks module command is missing: $commandName"
        }
    }

    Invoke-IfNeeded "Scheduled task yaradilir: $Name" {
        $action = New-ScheduledTaskAction -Execute $Command -Argument $Arguments
        if ($Schedule.ToUpperInvariant() -eq 'MINUTE') {
            $interval = 1
            if ($Modifier -ne '') {
                $parsedInterval = 0
                if (-not [int]::TryParse($Modifier, [ref]$parsedInterval) -or $parsedInterval -lt 1) {
                    throw "Invalid minute schedule modifier for $Name"
                }
                $interval = $parsedInterval
            }
            $trigger = New-ScheduledTaskTrigger `
                -Once `
                -At (Get-Date).AddMinutes(1) `
                -RepetitionInterval (New-TimeSpan -Minutes $interval)
        } elseif ($Schedule.ToUpperInvariant() -eq 'WEEKLY') {
            $trigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Sunday -At '03:00'
        } elseif ($Schedule.ToUpperInvariant() -eq 'DAILY') {
            $trigger = New-ScheduledTaskTrigger -Daily -At '03:00'
        } else {
            throw "Unsupported scheduled task cadence: $Schedule"
        }
        $principal = New-ScheduledTaskPrincipal -UserId 'NT AUTHORITY\LOCAL SERVICE' -LogonType ServiceAccount -RunLevel Limited
        $settings = New-ScheduledTaskSettingsSet `
            -StartWhenAvailable `
            -MultipleInstances IgnoreNew `
            -ExecutionTimeLimit (New-TimeSpan -Hours 2)
        Register-ScheduledTask `
            -TaskName $Name `
            -Action $action `
            -Trigger $trigger `
            -Principal $principal `
            -Settings $settings `
            -Force | Out-Null
    }
}

function Ensure-PortalServiceAcl {
    $runtimePaths = @(
        (Join-Path $WebRoot 'data'),
        (Join-Path $WebRoot 'tmp')
    )
    if ($DryRun) {
        Write-Host "[DRY-RUN] LOCAL SERVICE gets read/execute on $WebRoot and modify only on data/tmp"
        return
    }

    & icacls.exe $WebRoot /grant '*S-1-5-19:(OI)(CI)RX' /T /C /Q | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "LOCAL SERVICE read ACL could not be applied to portal root" }
    foreach ($path in $runtimePaths) {
        New-Item -ItemType Directory -Force -Path $path | Out-Null
        & icacls.exe $path /grant '*S-1-5-19:(OI)(CI)M' /T /C /Q | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "LOCAL SERVICE runtime ACL could not be applied: $path" }
    }
}

function Restart-PortalWebRuntime {
    if ($DryRun) {
        Write-Host "[DRY-RUN] Apache would be fully restarted after Machine environment rotation"
        return
    }

    foreach ($serviceName in @('Apache2.4', 'xamppapache')) {
        $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
        if ($service) {
            Restart-Service -Name $serviceName -Force
            Start-Sleep -Seconds 2
            if (-not (Test-TcpPort -HostName '127.0.0.1' -Port 80)) {
                throw "Apache service did not return on port 80 after environment restart"
            }
            Write-Step "Apache service yeni Machine environment ile restart edildi"
            return
        }
    }

    $stopBatch = Join-Path $XamppRoot 'apache_stop.bat'
    $startBatch = Join-Path $XamppRoot 'apache_start.bat'
    Assert-Path $stopBatch 'XAMPP Apache stop script'
    Assert-Path $startBatch 'XAMPP Apache start script'

    if (Test-TcpPort -HostName '127.0.0.1' -Port 80) {
        Start-Process -FilePath $stopBatch -WorkingDirectory $XamppRoot -WindowStyle Hidden -Wait
        $deadline = (Get-Date).AddSeconds(20)
        while ((Test-TcpPort -HostName '127.0.0.1' -Port 80) -and (Get-Date) -lt $deadline) {
            Start-Sleep -Milliseconds 250
        }
        if (Test-TcpPort -HostName '127.0.0.1' -Port 80) {
            throw "Existing XAMPP Apache process did not stop; refusing stale environment inheritance"
        }
    }

    Start-Process -FilePath $startBatch -WorkingDirectory $XamppRoot -WindowStyle Hidden
    $deadline = (Get-Date).AddSeconds(30)
    while (-not (Test-TcpPort -HostName '127.0.0.1' -Port 80) -and (Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 250
    }
    if (-not (Test-TcpPort -HostName '127.0.0.1' -Port 80)) {
        throw "XAMPP Apache did not start with the rotated Machine environment"
    }
    Write-Step "XAMPP Apache yeni Machine environment ile restart edildi"
}

function Test-ServiceEnvironmentVisibility {
    if ($DryRun) {
        Write-Host "[DRY-RUN] Apache and LOCAL SERVICE secret visibility would be probed without displaying values"
        return
    }
    $probeScript = Join-Path $WebRoot 'deploy\windows\Test-SuperOptic-ServiceEnvironment.ps1'
    Assert-Path $probeScript 'Service environment visibility probe'
    $localBaseUrl = 'http://localhost/' + (Split-Path -Leaf $WebRoot)
    & $probeScript -WebRoot $WebRoot -PhpPath $php -BaseUrl $localBaseUrl
    if ($LASTEXITCODE -ne 0) {
        throw "Service environment visibility probe failed"
    }
}

function Register-MgsStartupTask {
    param([string]$NodePath)
    $taskName = "SuperOptic MGS Node Service"
    $serverPath = Join-Path $MgsRoot "server.js"
    if ($DryRun) {
        Write-Host "[DRY-RUN] Scheduled task: $taskName -> $NodePath $serverPath /SC ONSTART /RU LOCAL SERVICE with runtime-only modify access"
        return
    }

    $runtimePaths = @(
        (Join-Path $MgsRoot "data"),
        (Join-Path $MgsRoot "logs"),
        (Join-Path $MgsRoot "runtime")
    )
    & icacls.exe $MgsRoot /grant '*S-1-5-19:(OI)(CI)RX' /T /C /Q | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "LOCAL SERVICE read ACL could not be applied to MGS root" }
    foreach ($legacyEnvFile in @(Get-ChildItem -LiteralPath $MgsRoot -Force -Filter ".env*" -File -ErrorAction SilentlyContinue)) {
        & icacls.exe $legacyEnvFile.FullName /deny '*S-1-5-19:R' /Q | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "LOCAL SERVICE deny ACL could not be applied: $($legacyEnvFile.FullName)" }
    }
    foreach ($path in $runtimePaths) {
        New-Item -ItemType Directory -Force -Path $path | Out-Null
        & icacls.exe $path /grant '*S-1-5-19:(OI)(CI)M' /T /C /Q | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "LOCAL SERVICE runtime ACL could not be applied: $path" }
    }

    $action = New-ScheduledTaskAction -Execute $NodePath -Argument "`"$serverPath`""
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $principal = New-ScheduledTaskPrincipal -UserId "NT AUTHORITY\LOCAL SERVICE" -LogonType ServiceAccount -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet `
        -StartWhenAvailable `
        -MultipleInstances IgnoreNew `
        -RestartCount 3 `
        -RestartInterval (New-TimeSpan -Minutes 2) `
        -ExecutionTimeLimit (New-TimeSpan -Days 30)
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
    Start-ScheduledTask -TaskName $taskName
    Write-Step "MGS Node startup task yaradildi: $taskName"
}

function Start-MgsNode {
    param([string]$NodePath)
    $serverPath = Join-Path $MgsRoot "server.js"
    $logRoot = Join-Path $MgsRoot "logs"
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $stdout = Join-Path $logRoot "installer-start-$stamp.out.log"
    $stderr = Join-Path $logRoot "installer-start-$stamp.err.log"
    Invoke-IfNeeded "MGS Node protected environment ile bashladilir" {
        New-Item -ItemType Directory -Force -Path $logRoot | Out-Null
        $process = Start-Process `
            -FilePath $NodePath `
            -ArgumentList @($serverPath) `
            -WorkingDirectory $MgsRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput $stdout `
            -RedirectStandardError $stderr `
            -PassThru
        Start-Sleep -Seconds 2
        if ($process.HasExited -and $process.ExitCode -ne 0) {
            throw "MGS Node start failed; inspect installer-start logs"
        }
    }
}

Write-Step "Qurasdirma bashlayir"
Assert-Path $WebRoot "SuperOptic web root"
Assert-Path $MgsRoot "MGS root"
Assert-Path $XamppRoot "XAMPP root"
Assert-PortNumber -Name "DbPort" -Port $DbPort
Assert-PortNumber -Name "RadiusSqlPort" -Port $RadiusSqlPort
Test-RadiusPortReadiness

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

if ($DryRun) {
    Write-Host "[DRY-RUN] Would check MySQL TCP endpoint ${DbHost}:${DbPort}."
} elseif (-not (Test-TcpPort -HostName $DbHost -Port $DbPort)) {
    Write-Warn "MySQL ${DbHost}:${DbPort} cavab vermir. XAMPP MySQL service-ni qaldir."
}

Initialize-ServiceSecrets
Ensure-Config
Ensure-PortalServiceAcl
Restart-PortalWebRuntime

Invoke-IfNeeded "PHP syntax ve DB bootstrap yoxlanilir" {
    & $php -l (Join-Path $WebRoot "config.php")
    if ($LASTEXITCODE -ne 0) { throw "config.php syntax error" }
    & $php -r "require '$($WebRoot.Replace('\','/'))/config.php'; `$db = app_db(); if (!(`$db instanceof PDO)) { fwrite(STDERR, 'db_unavailable'); exit(2); } echo 'db_bootstrap_ok'.PHP_EOL;"
    if ($LASTEXITCODE -ne 0) { throw "DB bootstrap ugursuz oldu" }
    & $php (Join-Path $WebRoot "tools\secret-readiness-smoke.php")
    if ($LASTEXITCODE -ne 0) { throw "Secret readiness smoke ugursuz oldu" }
}
Test-ServiceEnvironmentVisibility

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
        if (Test-Path -LiteralPath (Join-Path $MgsRoot ".env")) {
            Write-Warn "Legacy MGS .env exists. Machine environment values take precedence; remove the file after a controlled secret rotation/scrub."
        }
        Invoke-IfNeeded "MGS DB migrations" {
            Push-Location $MgsRoot
            try {
                & $npm run migrate
                if ($LASTEXITCODE -ne 0) { throw "MGS migrations ugursuz oldu" }
            } finally {
                Pop-Location
            }
        }
        if (-not $SkipStackAutostart) {
            Register-MgsStartupTask -NodePath $node
        } else {
            Start-MgsNode -NodePath $node
        }
    }
}

if (-not $SkipScheduledTasks) {
    $phpArgsPrefix = "-NoProfile -ExecutionPolicy Bypass -Command "
    $maintenance = "`"& '$php' '$WebRoot\tools\billing-maintenance.php' --apply --sync-radius --queue-notices --channel=auto`""
    $queue = "`"& '$php' '$WebRoot\tools\process-communication-queue.php' --apply --limit=50 --channel=all`""
    $pg365Status = "`"& '$php' '$WebRoot\tools\pg365-status-worker.php' --apply --limit=100`""
    $coa = "`"& '$php' '$WebRoot\tools\process-radius-session-actions.php' --apply --limit=50`""
    $bot = "`"& '$php' '$WebRoot\tools\process-telegram-modem-search-bot.php' --once`""
    $backup = "`"& '$php' '$WebRoot\tools\device-backup-worker.php' --apply --limit=10 --type=profile_snapshot`""
    $optimizer = "`"& '$php' '$WebRoot\tools\system-maintenance-optimizer.php' --apply --json`""
    $security = "`"& '$php' '$WebRoot\tools\security-anomaly-scan.php' --record --live`""
    $networkOltRules = "`"& '$php' '$WebRoot\tools\process-network-olt-ticket-rules.php' --apply`""
    $interfaceTraffic = "`"& '$php' '$WebRoot\tools\poll-device-interface-traffic.php' --apply`""

    Register-ScheduledTaskCompat -Name "SuperOptic MGS Billing Maintenance" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $maintenance) -Schedule "MINUTE" -Modifier "5"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Communication Queue" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $queue) -Schedule "MINUTE" -Modifier "2"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS PG365 Status Worker" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $pg365Status) -Schedule "MINUTE" -Modifier "2"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Radius CoA Worker" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $coa) -Schedule "MINUTE" -Modifier "1"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Modem Bot" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $bot) -Schedule "MINUTE" -Modifier "1"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Weekly Backup" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $backup) -Schedule "WEEKLY"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Daily Optimizer" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $optimizer) -Schedule "DAILY"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Security Anomaly Scan" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $security) -Schedule "MINUTE" -Modifier "5"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS OLT Ticket Rules" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $networkOltRules) -Schedule "MINUTE" -Modifier "5"
    Register-ScheduledTaskCompat -Name "SuperOptic MGS Interface Traffic Poll" -Command "powershell.exe" -Arguments ($phpArgsPrefix + $interfaceTraffic) -Schedule "MINUTE" -Modifier "5"
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
            $args += "-SkipMgs"
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
Write-Host "RADIUS UDP: $RadiusAuthPort/$RadiusAcctPort"
