[CmdletBinding()]
param(
    [string]$Distro = "Ubuntu-22.04",
    [string]$WebRoot = "D:\xampp\htdocs\superopticnet",
    [string]$TaskName = "SuperOptic WSL FreeRADIUS Watchdog"
)

$ErrorActionPreference = "Stop"
$watchdog = Join-Path $WebRoot "deploy\windows\start-wsl-freeradius.ps1"
if (!(Test-Path -LiteralPath $watchdog)) {
    throw "Watchdog script not found: $watchdog"
}

$arguments = @(
    "-NoProfile",
    "-NonInteractive",
    "-ExecutionPolicy", "Bypass",
    "-File", ('"{0}"' -f $watchdog),
    "-Distro", ('"{0}"' -f $Distro),
    "-WebRoot", ('"{0}"' -f $WebRoot)
) -join " "

$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $arguments
$logonTrigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$watchdogTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 5) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 2) `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -StartWhenAvailable

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger @($logonTrigger, $watchdogTrigger) `
    -Settings $settings `
    -Description "Keeps Ubuntu WSL FreeRADIUS running for SuperOptic billing authentication and accounting." `
    -Force | Out-Null

Start-ScheduledTask -TaskName $TaskName
Start-Sleep -Seconds 2
$task = Get-ScheduledTask -TaskName $TaskName
$info = Get-ScheduledTaskInfo -TaskName $TaskName

[pscustomobject]@{
    TaskName = $task.TaskName
    State = [string] $task.State
    User = [string] $task.Principal.UserId
    LastRunTime = $info.LastRunTime
    LastTaskResult = $info.LastTaskResult
    NextRunTime = $info.NextRunTime
    TriggerCount = @($task.Triggers).Count
} | ConvertTo-Json -Compress

