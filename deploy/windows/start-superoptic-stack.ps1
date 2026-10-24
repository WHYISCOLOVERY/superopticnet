[CmdletBinding()]
param(
    [string]$XamppRoot = "D:\xampp",
    [string]$WebRoot = "D:\xampp\htdocs\superopticnet",
    [string]$MgsRoot = "D:\xampp\htdocs\oltidareetme",
    [string]$WhatsappBridgeRoot = "D:\xampp\htdocs\superopticnet\whatsapp-bridge",
    [int]$ApachePort = 80,
    [int]$MysqlPort = 3306,
    [int]$MgsPort = 3000,
    [int]$WhatsappBridgePort = 4001,
    [int]$SnmpTrapListenPort = 1162,
    [switch]$SkipMgs,
    [switch]$StartMgs,
    [switch]$SkipWhatsappBridge,
    [switch]$SkipWorkers,
    [switch]$StartSnmpTrapListener,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

function Write-StackLog {
    param([string]$Message)
    $line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Write-Host $line
    if (Test-Path -LiteralPath $WebRoot) {
        $logDir = Join-Path $WebRoot "tmp"
        New-Item -ItemType Directory -Force -Path $logDir | Out-Null
        Add-Content -LiteralPath (Join-Path $logDir "superoptic-stack-start.log") -Value $line
    }
}

function Test-TcpOpen {
    param([string]$HostName, [int]$Port, [int]$TimeoutMs = 800)
    try {
        $client = New-Object Net.Sockets.TcpClient
        $async = $client.BeginConnect($HostName, $Port, $null, $null)
        $ok = $async.AsyncWaitHandle.WaitOne($TimeoutMs, $false)
        if ($ok) { $client.EndConnect($async) }
        $client.Close()
        return $ok
    } catch {
        return $false
    }
}

function Start-ServiceOrBatch {
    param(
        [string]$Label,
        [string[]]$ServiceNames,
        [string]$BatchPath,
        [int]$Port
    )

    if (Test-TcpOpen -HostName "127.0.0.1" -Port $Port) {
        Write-StackLog "$Label already listens on port $Port"
        return
    }

    foreach ($serviceName in $ServiceNames) {
        $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
        if ($service) {
            if ($DryRun) {
                Write-StackLog "[DRY-RUN] Start-Service $serviceName"
                return
            }
            if ($service.Status -ne "Running") {
                Write-StackLog "Starting Windows service $serviceName"
                Start-Service -Name $serviceName
            } else {
                Write-StackLog "$serviceName service already running"
            }
            return
        }
    }

    if (Test-Path -LiteralPath $BatchPath) {
        if ($DryRun) {
            Write-StackLog "[DRY-RUN] Start-Process $BatchPath"
            return
        }
        Write-StackLog "Starting $Label with $BatchPath"
        Start-Process -FilePath $BatchPath -WorkingDirectory (Split-Path -Parent $BatchPath) -WindowStyle Hidden
        return
    }

    Write-StackLog "$Label start source not found. Checked services and $BatchPath"
}

function Start-MgsNode {
    if ($SkipMgs) {
        Write-StackLog "MGS Node start skipped by flag"
        return
    }
    if (-not $StartMgs) {
        Write-StackLog "MGS Node start disabled by default (superopticnet is now the single system of record; pass -StartMgs to opt back in, see docs/39_MGS_NODE_CONSOLIDATION.md)"
        return
    }
    if (Test-TcpOpen -HostName "127.0.0.1" -Port $MgsPort) {
        Write-StackLog "MGS already listens on port $MgsPort"
        return
    }
    if (!(Test-Path -LiteralPath $MgsRoot)) {
        Write-StackLog "MGS root not found: $MgsRoot"
        return
    }

    $npm = Get-Command npm.cmd -ErrorAction SilentlyContinue
    $node = Get-Command node.exe -ErrorAction SilentlyContinue
    $startCmd = Join-Path $MgsRoot "start-mgs.cmd"
    if ($DryRun) {
        Write-StackLog "[DRY-RUN] Start MGS from $MgsRoot"
        return
    }
    if (Test-Path -LiteralPath $startCmd) {
        Write-StackLog "Starting MGS with start-mgs.cmd"
        Start-Process -FilePath $startCmd -WorkingDirectory $MgsRoot -WindowStyle Hidden
        return
    }
    if ($npm) {
        Write-StackLog "Starting MGS with npm start"
        Start-Process -FilePath "powershell.exe" -ArgumentList @(
            "-NoProfile",
            "-ExecutionPolicy", "Bypass",
            "-Command",
            "cd '$MgsRoot'; npm.cmd start"
        ) -WindowStyle Hidden
        return
    }
    if ($node -and (Test-Path -LiteralPath (Join-Path $MgsRoot "server.js"))) {
        Write-StackLog "Starting MGS with node server.js"
        Start-Process -FilePath $node.Source -ArgumentList "server.js" -WorkingDirectory $MgsRoot -WindowStyle Hidden
        return
    }
    Write-StackLog "Node/npm not found; billing remains available through native fallback"
}

function Start-WhatsappBridge {
    if ($SkipWhatsappBridge) {
        Write-StackLog "WhatsApp bridge start skipped by flag"
        return
    }
    if (Test-TcpOpen -HostName "127.0.0.1" -Port $WhatsappBridgePort) {
        Write-StackLog "WhatsApp bridge already listens on port $WhatsappBridgePort"
        return
    }
    if (!(Test-Path -LiteralPath $WhatsappBridgeRoot)) {
        Write-StackLog "WhatsApp bridge root not found: $WhatsappBridgeRoot"
        return
    }

    $npm = Get-Command npm.cmd -ErrorAction SilentlyContinue
    $node = Get-Command node.exe -ErrorAction SilentlyContinue
    if ($DryRun) {
        Write-StackLog "[DRY-RUN] Start WhatsApp bridge from $WhatsappBridgeRoot"
        return
    }
    if (-not $node) {
        Write-StackLog "Node.js not found; WhatsApp bridge cannot start"
        return
    }
    if ($npm -and !(Test-Path -LiteralPath (Join-Path $WhatsappBridgeRoot "node_modules"))) {
        Write-StackLog "Installing WhatsApp bridge npm dependencies"
        Start-Process -FilePath "powershell.exe" -ArgumentList @(
            "-NoProfile",
            "-ExecutionPolicy", "Bypass",
            "-Command",
            "cd '$WhatsappBridgeRoot'; npm.cmd install --omit=dev"
        ) -Wait -WindowStyle Hidden
    }

    Write-StackLog "Starting WhatsApp bridge on port $WhatsappBridgePort"
    $logDir = Join-Path $WebRoot "tmp"
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
    Start-Process `
        -FilePath $node.Source `
        -ArgumentList "server.js" `
        -WorkingDirectory $WhatsappBridgeRoot `
        -RedirectStandardOutput (Join-Path $logDir "whatsapp-bridge.out.log") `
        -RedirectStandardError (Join-Path $logDir "whatsapp-bridge.err.log") `
        -WindowStyle Hidden
}

function Start-SnmpTrapListener {
    # Opt-in, same as MGS Node above: this is a brand new always-on UDP
    # listener (docs/39_MGS_NODE_CONSOLIDATION.md Faza 1), so it must not
    # silently start running on a machine that didn't ask for it. A missed
    # trap while the listener is down is not silently lost data in the way a
    # missed HTTP request would be -- the source device just re-sends the
    # next state-change trap -- so there is no urgency to make this default-on.
    if (-not $StartSnmpTrapListener) {
        Write-StackLog "SNMP trap listener start disabled by default (pass -StartSnmpTrapListener to opt in, see docs/39_MGS_NODE_CONSOLIDATION.md)"
        return
    }
    if (Test-TcpOpen -HostName "127.0.0.1" -Port $SnmpTrapListenPort) {
        Write-StackLog "SNMP trap listener already listens on port $SnmpTrapListenPort"
        return
    }
    $php = Join-Path $XamppRoot "php\php.exe"
    $listenerScript = Join-Path $WebRoot "tools\snmp-trap-listener.php"
    if (!(Test-Path -LiteralPath $php) -or !(Test-Path -LiteralPath $listenerScript)) {
        Write-StackLog "SNMP trap listener start source not found. Checked $php and $listenerScript"
        return
    }
    if ($DryRun) {
        Write-StackLog "[DRY-RUN] Start SNMP trap listener on port $SnmpTrapListenPort"
        return
    }

    Write-StackLog "Starting SNMP trap listener on port $SnmpTrapListenPort"
    $logDir = Join-Path $WebRoot "tmp"
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
    Start-Process `
        -FilePath $php `
        -ArgumentList @("$listenerScript", "--port=$SnmpTrapListenPort") `
        -WorkingDirectory $WebRoot `
        -RedirectStandardOutput (Join-Path $logDir "snmp-trap-listener.out.log") `
        -RedirectStandardError (Join-Path $logDir "snmp-trap-listener.err.log") `
        -WindowStyle Hidden
}

function Sync-WhatsappPairingWorkerToken {
    if ($SkipWhatsappBridge) {
        return
    }

    $php = Join-Path $XamppRoot "php\php.exe"
    $configureScript = Join-Path $WebRoot "tools\configure-whatsapp-pairing-worker.php"
    $localConfig = Join-Path $WebRoot "config.local.php"
    if (!(Test-Path -LiteralPath $php) -or !(Test-Path -LiteralPath $configureScript)) {
        Write-StackLog "WhatsApp pairing token sync skipped; PHP or configure tool is missing"
        return
    }
    if ($DryRun) {
        Write-StackLog "[DRY-RUN] Sync WhatsApp pairing-worker token"
        return
    }

    $previousDbPass = [Environment]::GetEnvironmentVariable("DB_PASS", "Process")
    try {
        if ([string]::IsNullOrWhiteSpace($previousDbPass) -and (Test-Path -LiteralPath $localConfig)) {
            $configPath = $localConfig.Replace('\', '/').Replace("'", "\\'")
            $dbPass = & $php -r "`$c=require '$configPath';fwrite(STDOUT,(string)(`$c['db_pass']??''));"
            if (-not [string]::IsNullOrWhiteSpace([string]$dbPass)) {
                $env:DB_PASS = [string]$dbPass
            }
        }

        for ($attempt = 1; $attempt -le 15; $attempt++) {
            $output = & $php $configureScript 2>&1
            if ($LASTEXITCODE -eq 0) {
                Write-StackLog "WhatsApp pairing-worker token synchronized"
                return
            }
            Start-Sleep -Seconds 1
        }
        Write-StackLog "WhatsApp pairing-worker token sync did not complete"
    } finally {
        if ([string]::IsNullOrWhiteSpace($previousDbPass)) {
            Remove-Item Env:DB_PASS -ErrorAction SilentlyContinue
        } else {
            $env:DB_PASS = $previousDbPass
        }
        $dbPass = $null
        $output = $null
    }
}

function Start-WorkerSupervisor {
    if ($SkipWorkers) {
        Write-StackLog "Worker supervisor start skipped by flag"
        return
    }

    $workerStarter = Join-Path $WebRoot "deploy\windows\start-superoptic-workers.ps1"
    if (!(Test-Path -LiteralPath $workerStarter)) {
        Write-StackLog "Worker supervisor script not found: $workerStarter"
        return
    }

    if ($DryRun) {
        Write-StackLog "[DRY-RUN] Start worker supervisor from $workerStarter"
        return
    }

    Write-StackLog "Starting worker supervisor"
    Start-Process -FilePath "powershell.exe" -ArgumentList @(
        "-WindowStyle", "Hidden",
        "-NoProfile",
        "-ExecutionPolicy", "Bypass",
        "-File", "`"$workerStarter`"",
        "-WebRoot", "`"$WebRoot`""
    ) -WindowStyle Hidden
}

Write-StackLog "SuperOptic stack autostart begin"
Start-ServiceOrBatch -Label "Apache" -ServiceNames @("Apache2.4", "apache2.4") -BatchPath (Join-Path $XamppRoot "apache_start.bat") -Port $ApachePort
Start-ServiceOrBatch -Label "MySQL" -ServiceNames @("mysql", "mysql80", "mariadb", "mysqlxampp") -BatchPath (Join-Path $XamppRoot "mysql_start.bat") -Port $MysqlPort
Start-MgsNode
Sync-WhatsappPairingWorkerToken
Start-WhatsappBridge
Start-SnmpTrapListener
Start-WorkerSupervisor
Write-StackLog "SuperOptic stack autostart end"
