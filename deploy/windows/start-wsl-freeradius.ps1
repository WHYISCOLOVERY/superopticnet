[CmdletBinding()]
param(
    [string]$Distro = "Ubuntu-22.04",
    [string]$WebRoot = "D:\xampp\htdocs\superopticnet"
)

$ErrorActionPreference = "Stop"
$logDirectory = Join-Path $WebRoot "tmp"
$logPath = Join-Path $logDirectory "wsl-freeradius-watchdog.log"
$helperWindows = Join-Path $WebRoot "deploy\wsl-radius-client.sh"

New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null

function Write-WatchdogLog {
    param([string]$Message)
    $line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Add-Content -LiteralPath $logPath -Value $line
    Write-Output $line
}

if (!(Test-Path -LiteralPath $helperWindows)) {
    throw "WSL RADIUS helper not found: $helperWindows"
}

$drive = ([IO.Path]::GetPathRoot($helperWindows)).Substring(0, 1).ToLowerInvariant()
$relative = $helperWindows.Substring(2).Replace("\", "/")
$helperLinux = "/mnt/$drive$relative"

$distros = (& wsl.exe --list --quiet 2>$null) -replace "`0", "" | ForEach-Object { $_.Trim() } | Where-Object { $_ }
if ($distros -notcontains $Distro) {
    throw "WSL distro not found: $Distro"
}

& wsl.exe -d $Distro -u root -- bash $helperLinux status *> $null
if ($LASTEXITCODE -ne 0) {
    Write-WatchdogLog "FreeRADIUS is down; starting in $Distro"
    & wsl.exe -d $Distro -u root -- bash -lc "freeradius -C >/tmp/superoptic-freeradius-config-check.log 2>&1 && service freeradius restart"
    if ($LASTEXITCODE -ne 0) {
        Write-WatchdogLog "FreeRADIUS start failed"
        exit 2
    }
}

& wsl.exe -d $Distro -u root -- bash $helperLinux status *> $null
if ($LASTEXITCODE -ne 0) {
    Write-WatchdogLog "FreeRADIUS health check failed after start"
    exit 3
}

Write-WatchdogLog "FreeRADIUS is running in $Distro"

