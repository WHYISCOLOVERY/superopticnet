param(
    [string]$WebRoot = "D:\xampp\htdocs\superopticnet",
    [string]$PhpExe = "D:\xampp\php\php.exe",
    [string]$TaskName = "SuperOptic AI Komanda Daily Agenda",
    [string]$RunAt = "06:00"
)

$ErrorActionPreference = "Stop"

$tool = Join-Path $WebRoot "tools\ai-team-daily-agenda.php"
if (!(Test-Path -LiteralPath $PhpExe)) {
    throw "PHP executable not found: $PhpExe"
}
if (!(Test-Path -LiteralPath $tool)) {
    throw "AI Komanda daily-agenda worker not found: $tool"
}

$logDir = Join-Path $WebRoot "tmp"
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$logFile = Join-Path $logDir "ai-team-daily-agenda-task.log"

# --apply stages high/critical advisor facts as pending_admin_review task
# drafts (never assigned, never sent -- a human admin still has to approve).
# Every run also writes an ai_team_agenda_runs heartbeat row, which the AI
# Komanda page surfaces as "Son icra".
$argument = "-NoProfile -ExecutionPolicy Bypass -Command `"& '$PhpExe' '$tool' --apply | Tee-Object -FilePath '$logFile' -Append`""
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $argument
$trigger = New-ScheduledTaskTrigger -Daily -At $RunAt
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 15)

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -RunLevel Highest -Force | Out-Null

Write-Host "SuperOptic AI Komanda daily-agenda task installed."
Write-Host "Task: $TaskName"
Write-Host "RunAt: $RunAt"
Write-Host "Log: $logFile"
