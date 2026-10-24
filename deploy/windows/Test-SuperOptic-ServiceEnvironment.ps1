[CmdletBinding()]
param(
    [string]$WebRoot = "D:\xampp\htdocs\superopticnet",
    [string]$PhpPath = "D:\xampp\php\php.exe",
    [string]$BaseUrl = "http://localhost/superopticnet"
)

$ErrorActionPreference = "Stop"

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-IsAdministrator)) {
    throw "Run PowerShell as Administrator to verify LOCAL SERVICE environment visibility"
}
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP executable not found: $PhpPath"
}
if (-not (Test-Path -LiteralPath $WebRoot -PathType Container)) {
    throw "Web root not found: $WebRoot"
}

$taskName = "SuperOptic Secret Visibility " + [guid]::NewGuid().ToString("N")
$taskProbe = Join-Path $WebRoot "tools\task-secret-visibility.php"
$taskEvidence = Join-Path $WebRoot "tmp\task-secret-visibility.json"
$httpProbe = Join-Path $WebRoot "tools\service-secret-visibility.php"
Remove-Item -LiteralPath $taskEvidence -Force -ErrorAction SilentlyContinue

try {
    $action = New-ScheduledTaskAction -Execute $PhpPath -Argument "`"$taskProbe`""
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(5)
    $principal = New-ScheduledTaskPrincipal -UserId "NT AUTHORITY\LOCAL SERVICE" -LogonType ServiceAccount -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 2)
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
    Start-ScheduledTask -TaskName $taskName

    $deadline = (Get-Date).AddSeconds(30)
    while (-not (Test-Path -LiteralPath $taskEvidence) -and (Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 250
    }
    if (-not (Test-Path -LiteralPath $taskEvidence)) {
        throw "LOCAL SERVICE visibility probe did not produce evidence"
    }
    $taskResult = Get-Content -LiteralPath $taskEvidence -Raw | ConvertFrom-Json
    $httpRaw = (& $PhpPath $httpProbe "--base-url=$BaseUrl" | Out-String)
    $httpResult = $httpRaw | ConvertFrom-Json

    $combinedOk = [bool]$taskResult.ok -and [bool]$httpResult.ok
    [pscustomobject]@{
        ok = $combinedOk
        task_context = [string]$taskResult.context
        task_missing_count = @($taskResult.missing_keys).Count
        apache_context = [string]$httpResult.context
        apache_missing_count = @($httpResult.missing_keys).Count
        secrets_exposed = $false
    } | ConvertTo-Json
    if (-not $combinedOk) {
        throw "Apache or LOCAL SERVICE cannot see the complete managed secret set"
    }
} finally {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue
}
