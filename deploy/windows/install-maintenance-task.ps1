param(
    [string]$WebRoot = "D:\xampp\htdocs\superopticnet",
    [string]$PhpExe = "D:\xampp\php\php.exe",
    [string]$TaskName = "SuperOptic MGS Daily Maintenance",
    [string]$RunAt = "03:20"
)

$ErrorActionPreference = "Stop"

$tool = Join-Path $WebRoot "tools\system-maintenance-optimizer.php"
if (!(Test-Path -LiteralPath $PhpExe)) {
    throw "PHP executable not found: $PhpExe"
}
if (!(Test-Path -LiteralPath $tool)) {
    throw "Maintenance optimizer not found: $tool"
}

$logDir = Join-Path $WebRoot "tmp"
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$logFile = Join-Path $logDir "system-maintenance-task.log"

$argument = "-NoProfile -ExecutionPolicy Bypass -Command `"& '$PhpExe' '$tool' --apply --json | Tee-Object -FilePath '$logFile' -Append`""
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $argument
$trigger = New-ScheduledTaskTrigger -Daily -At $RunAt
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -RunLevel Highest -Force | Out-Null

Write-Host "SuperOptic maintenance task installed."
Write-Host "Task: $TaskName"
Write-Host "RunAt: $RunAt"
Write-Host "Log: $logFile"
