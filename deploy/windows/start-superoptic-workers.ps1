[CmdletBinding()]
param(
    [string]$WebRoot = "D:\xampp\htdocs\superopticnet",
    [string]$PhpExe = "D:\xampp\php\php.exe",
    [int]$LoopSeconds = 30,
    [switch]$Once,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

function Write-WorkerLog {
    param([string]$Message)
    $line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Write-Host $line
    if (Test-Path -LiteralPath $WebRoot) {
        $logDir = Join-Path $WebRoot "tmp"
        New-Item -ItemType Directory -Force -Path $logDir | Out-Null
        Add-Content -LiteralPath (Join-Path $logDir "superoptic-worker-supervisor.log") -Value $line
    }
}

function Invoke-Worker {
    param(
        [string]$Name,
        [string]$Tool,
        [string[]]$Arguments
    )

    $toolPath = Join-Path $WebRoot $Tool
    if (!(Test-Path -LiteralPath $toolPath)) {
        Write-WorkerLog "$Name skipped; tool missing: $toolPath"
        return
    }

    $argLine = @($toolPath) + $Arguments
    if ($DryRun) {
        Write-WorkerLog "[DRY-RUN] $Name -> $PhpExe $($argLine -join ' ')"
        return
    }

    $startedAt = Get-Date
    try {
        $output = & $PhpExe @argLine 2>&1
        $exit = $LASTEXITCODE
        $duration = [int]((Get-Date) - $startedAt).TotalSeconds
        $summary = ($output | Select-Object -First 1) -join " "
        Write-WorkerLog "$Name exit=$exit duration=${duration}s $summary"
    } catch {
        Write-WorkerLog "$Name failed: $($_.Exception.Message)"
    }
}

function Should-Run {
    param([hashtable]$State, [string]$Name, [int]$EverySeconds)
    $now = Get-Date
    if (!$State.ContainsKey($Name)) {
        $State[$Name] = $now
        return $true
    }
    $last = [datetime]$State[$Name]
    if (($now - $last).TotalSeconds -ge $EverySeconds) {
        $State[$Name] = $now
        return $true
    }
    return $false
}

if (!(Test-Path -LiteralPath $PhpExe)) {
    throw "PHP executable not found: $PhpExe"
}
if (!(Test-Path -LiteralPath $WebRoot)) {
    throw "Web root not found: $WebRoot"
}

$mutex = New-Object System.Threading.Mutex($false, "Global\SuperOpticMgsWorkerSupervisor")
$hasLock = $mutex.WaitOne(0)
if (!$hasLock) {
    Write-WorkerLog "Another worker supervisor instance is already running."
    exit 0
}

try {
    Write-WorkerLog "SuperOptic worker supervisor started"
    $state = @{}
    do {
        if (Should-Run $state "billing_maintenance" 300) {
            Invoke-Worker "billing_maintenance" "tools\billing-maintenance.php" @("--apply", "--limit=250", "--notice-limit=100", "--sync-radius", "--sync-limit=200", "--queue-notices", "--channel=auto")
        }
        if (Should-Run $state "communication_queue" 120) {
            Invoke-Worker "communication_queue" "tools\process-communication-queue.php" @("--apply", "--limit=50", "--channel=all")
        }
        if (Should-Run $state "radius_coa_approved_only" 60) {
            Invoke-Worker "radius_coa_approved_only" "tools\process-radius-session-actions.php" @("--limit=50")
        }
        if (Should-Run $state "modem_bot" 60) {
            Invoke-Worker "modem_bot" "tools\process-telegram-modem-search-bot.php" @("--once")
        }
        if (Should-Run $state "security_anomaly" 300) {
            Invoke-Worker "security_anomaly" "tools\security-anomaly-scan.php" @("--record", "--live")
        }
        if (Should-Run $state "network_olt_ticket_rules" 300) {
            Invoke-Worker "network_olt_ticket_rules" "tools\process-network-olt-ticket-rules.php" @("--apply")
        }
        if (Should-Run $state "system_optimizer" 86400) {
            Invoke-Worker "system_optimizer" "tools\system-maintenance-optimizer.php" @("--apply", "--json")
        }
        if (Should-Run $state "profile_backup" 604800) {
            Invoke-Worker "profile_backup" "tools\device-backup-worker.php" @("--apply", "--limit=10", "--type=profile_snapshot")
        }

        if ($Once) {
            break
        }
        Start-Sleep -Seconds ([Math]::Max(10, $LoopSeconds))
    } while ($true)
} finally {
    if ($hasLock) {
        $mutex.ReleaseMutex() | Out-Null
    }
    $mutex.Dispose()
    Write-WorkerLog "SuperOptic worker supervisor stopped"
}
