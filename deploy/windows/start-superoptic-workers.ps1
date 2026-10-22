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

function Stop-WorkerProcessTree {
    param([int]$ProcessId)

    # PHP workers may open SSH/SNMP child processes. Stop descendants first so
    # inherited stdout handles cannot keep the supervisor waiting indefinitely.
    $children = Get-CimInstance Win32_Process -Filter "ParentProcessId = $ProcessId" -ErrorAction SilentlyContinue
    foreach ($child in @($children)) {
        Stop-WorkerProcessTree -ProcessId ([int]$child.ProcessId)
    }
    Stop-Process -Id $ProcessId -Force -ErrorAction SilentlyContinue
}

function Invoke-Worker {
    param(
        [string]$Name,
        [string]$Tool,
        [string[]]$Arguments,
        [int]$TimeoutSeconds = 240
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
    $logDir = Join-Path $WebRoot "tmp"
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
    $runId = [Guid]::NewGuid().ToString("N")
    $stdoutPath = Join-Path $logDir ("worker-{0}-{1}.out.tmp" -f $Name, $runId)
    $stderrPath = Join-Path $logDir ("worker-{0}-{1}.err.tmp" -f $Name, $runId)
    try {
        $process = Start-Process -FilePath $PhpExe -ArgumentList $argLine -WorkingDirectory $WebRoot `
            -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath -WindowStyle Hidden -PassThru
        $deadline = (Get-Date).AddSeconds([Math]::Max(10, $TimeoutSeconds))
        while ((Get-Date) -lt $deadline) {
            $process.Refresh()
            if ($process.HasExited) { break }
            Start-Sleep -Milliseconds 500
        }
        $process.Refresh()
        if (!$process.HasExited) {
            Stop-WorkerProcessTree -ProcessId $process.Id
            $stopDeadline = (Get-Date).AddSeconds(5)
            while ((Get-Date) -lt $stopDeadline) {
                $process.Refresh()
                if ($process.HasExited) { break }
                Start-Sleep -Milliseconds 200
            }
            $duration = [int]((Get-Date) - $startedAt).TotalSeconds
            Write-WorkerLog "$Name timed_out duration=${duration}s limit=${TimeoutSeconds}s; next workers continue"
            return
        }
        $process.Refresh()
        $exit = $process.ExitCode
        $output = @()
        if (Test-Path -LiteralPath $stdoutPath) { $output += Get-Content -LiteralPath $stdoutPath -ErrorAction SilentlyContinue }
        if (Test-Path -LiteralPath $stderrPath) { $output += Get-Content -LiteralPath $stderrPath -ErrorAction SilentlyContinue }
        $duration = [int]((Get-Date) - $startedAt).TotalSeconds
        $summary = ($output | Select-Object -First 1) -join " "
        Write-WorkerLog "$Name exit=$exit duration=${duration}s $summary"
    } catch {
        Write-WorkerLog "$Name failed: $($_.Exception.Message)"
    } finally {
        Remove-Item -LiteralPath $stdoutPath -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $stderrPath -Force -ErrorAction SilentlyContinue
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
            Invoke-Worker "billing_maintenance" "tools\billing-maintenance.php" @("--apply", "--limit=250", "--notice-limit=100", "--sync-radius", "--sync-limit=200", "--queue-notices", "--channel=auto") -TimeoutSeconds 240
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
        if (Should-Run $state "device_interface_traffic" 300) {
            # Reads interface counters from network devices and writes only
            # local telemetry samples/rollups; no device configuration write.
            Invoke-Worker "device_interface_traffic" "tools\poll-device-interface-traffic.php" @("--apply", "--summary") -TimeoutSeconds 240
        }
        if (Should-Run $state "mobile_daily_work_digest" 300) {
            Invoke-Worker "mobile_daily_work_digest" "tools\mobile-daily-work-digest.php" @()
        }
        if (Should-Run $state "mobile_operator_presence" 300) {
            Invoke-Worker "mobile_operator_presence" "tools\mobile-operator-presence-monitor.php" @()
        }
        if (Should-Run $state "mobile_location_compliance" 60) {
            Invoke-Worker "mobile_location_compliance" "tools\mobile-location-compliance-monitor.php" @()
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
