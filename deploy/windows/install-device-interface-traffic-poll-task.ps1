param(
    [string]$WebRoot = "D:\xampp\htdocs\superopticnet",
    [string]$PhpExe = "D:\xampp\php\php.exe",
    [string]$TaskName = "SuperOptic Device Interface Traffic Poll",
    [int]$IntervalMinutes = 5
)

$ErrorActionPreference = "Stop"

$tool = Join-Path $WebRoot "tools\poll-device-interface-traffic.php"
if (!(Test-Path -LiteralPath $PhpExe)) {
    throw "PHP executable not found: $PhpExe"
}
if (!(Test-Path -LiteralPath $tool)) {
    throw "Interface traffic poll worker not found: $tool"
}

$logDir = Join-Path $WebRoot "tmp"
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$logFile = Join-Path $logDir "device-interface-traffic-poll-task.log"

# --apply stores samples for every device_interfaces row, re-runs the
# hourly/daily rollups and prunes raw samples older than 30 days -- see
# docs/39_MGS_NODE_CONSOLIDATION.md Faza 1. Read-only SNMP GET against
# already-configured devices only; nothing is written to any network device.
$argument = "-NoProfile -ExecutionPolicy Bypass -Command `"& '$PhpExe' '$tool' --apply | Tee-Object -FilePath '$logFile' -Append`""
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $argument
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) -RepetitionDuration ([TimeSpan]::MaxValue)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes $IntervalMinutes)

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -RunLevel Highest -Force | Out-Null

Write-Host "SuperOptic device-interface-traffic-poll task installed."
Write-Host "Task: $TaskName"
Write-Host "Every: $IntervalMinutes minute(s)"
Write-Host "Log: $logFile"
