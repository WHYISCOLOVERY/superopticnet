[CmdletBinding()]
param(
    [string]$WebRoot = "D:\xampp\htdocs\superopticnet",
    [string]$XamppRoot = "D:\xampp",
    [string]$MgsRoot = "D:\xampp\htdocs\oltidareetme",
    [string]$WhatsappBridgeRoot = "D:\xampp\htdocs\superopticnet\whatsapp-bridge",
    [string]$TaskName = "SuperOptic MGS Stack Autostart",
    [string]$WorkerTaskName = "SuperOptic MGS Worker Supervisor",
    [switch]$SkipMgs,
    [switch]$SkipWhatsappBridge,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

$starter = Join-Path $WebRoot "deploy\windows\start-superoptic-stack.ps1"
$workerStarter = Join-Path $WebRoot "deploy\windows\start-superoptic-workers.ps1"
$statusPath = Join-Path $WebRoot "tmp\windows-autostart-status.json"
if (!(Test-Path -LiteralPath $starter)) {
    throw "Autostart script not found: $starter"
}
if (!(Test-Path -LiteralPath $workerStarter)) {
    throw "Worker autostart script not found: $workerStarter"
}

$arguments = @(
    "-NoProfile",
    "-ExecutionPolicy", "Bypass",
    "-File", "`"$starter`"",
    "-WebRoot", "`"$WebRoot`"",
    "-XamppRoot", "`"$XamppRoot`"",
    "-MgsRoot", "`"$MgsRoot`"",
    "-WhatsappBridgeRoot", "`"$WhatsappBridgeRoot`""
)
if ($SkipMgs) {
    $arguments += "-SkipMgs"
}
if ($SkipWhatsappBridge) {
    $arguments += "-SkipWhatsappBridge"
}

$workerArguments = @(
    "-NoProfile",
    "-ExecutionPolicy", "Bypass",
    "-File", "`"$workerStarter`"",
    "-WebRoot", "`"$WebRoot`""
)

function Get-SuperOpticTask {
    param([string]$Name)
    return Get-ScheduledTask -TaskName $Name -ErrorAction SilentlyContinue
}

function Install-SuperOpticTaskWithSchtasks {
    param(
        [string]$Name,
        [string[]]$Arguments
    )
    $taskRun = "powershell.exe $($Arguments -join ' ')"
    $output = & schtasks.exe /Create /TN $Name /SC ONSTART /RU SYSTEM /RL HIGHEST /TR $taskRun /F 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "schtasks fallback failed for $Name : $($output -join ' ')"
    }
}

if ($DryRun) {
    Write-Host "[DRY-RUN] Register-ScheduledTask $TaskName at startup as SYSTEM"
    Write-Host "powershell.exe $($arguments -join ' ')"
    Write-Host "[DRY-RUN] Register-ScheduledTask $WorkerTaskName at startup as SYSTEM"
    Write-Host "powershell.exe $($workerArguments -join ' ')"
    exit 0
}

$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument ($arguments -join " ")
$workerAction = New-ScheduledTaskAction -Execute "powershell.exe" -Argument ($workerArguments -join " ")
$stackSettings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 2) `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
$workerSettings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 2) `
    -ExecutionTimeLimit (New-TimeSpan -Days 30)

try {
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $stackSettings -Principal $principal -Force | Out-Null
    Register-ScheduledTask -TaskName $WorkerTaskName -Action $workerAction -Trigger $trigger -Settings $workerSettings -Principal $principal -Force | Out-Null

    $stackTask = Get-SuperOpticTask -Name $TaskName
    $workerTask = Get-SuperOpticTask -Name $WorkerTaskName
    if ($null -eq $workerTask) {
        Install-SuperOpticTaskWithSchtasks -Name $WorkerTaskName -Arguments $workerArguments
        $workerTask = Get-SuperOpticTask -Name $WorkerTaskName
    }
    if ($null -eq $stackTask) {
        Install-SuperOpticTaskWithSchtasks -Name $TaskName -Arguments $arguments
        $stackTask = Get-SuperOpticTask -Name $TaskName
    }

    if ($null -eq $stackTask -or $null -eq $workerTask) {
        throw "Scheduled task verification failed. Stack exists: $($null -ne $stackTask); Worker exists: $($null -ne $workerTask)"
    }
    if (($stackTask.Principal.UserId -ne "SYSTEM") -or ($workerTask.Principal.UserId -ne "SYSTEM")) {
        throw "Scheduled task principal verification failed. Stack=$($stackTask.Principal.UserId); Worker=$($workerTask.Principal.UserId)"
    }
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $statusPath) | Out-Null
    @{
        installed_at = (Get-Date).ToString("o")
        mode = "system_boot"
        stack_task = $TaskName
        worker_task = $WorkerTaskName
        principal = "SYSTEM"
        stack_actions = 1
        worker_actions = 1
        stack_script = $starter
        worker_script = $workerStarter
        whatsapp_bridge_root = $WhatsappBridgeRoot
    } | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $statusPath -Encoding UTF8
    Write-Host "SuperOptic boot autostart tasks installed."
    Write-Host "Stack task: $TaskName"
    Write-Host "Worker task: $WorkerTaskName"
    Write-Host "Script: $starter"
} catch {
    $fallbackTaskName = "$TaskName (User Logon Fallback)"
    Write-Warning "Boot-level autostart requires Administrator rights. Installing current-user logon fallback instead."
    try {
        $trigger = New-ScheduledTaskTrigger -AtLogOn
        Register-ScheduledTask -TaskName $fallbackTaskName -Action @($action, $workerAction) -Trigger $trigger -Settings $stackSettings -Force | Out-Null
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $statusPath) | Out-Null
        @{
            installed_at = (Get-Date).ToString("o")
            mode = "user_logon_fallback"
            stack_task = $fallbackTaskName
            worker_task = $fallbackTaskName
            principal = $env:USERNAME
            stack_actions = 2
            worker_actions = 2
            stack_script = $starter
            worker_script = $workerStarter
            whatsapp_bridge_root = $WhatsappBridgeRoot
        } | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $statusPath -Encoding UTF8
        Write-Host "SuperOptic user-logon autostart fallback installed."
        Write-Host "Task: $fallbackTaskName"
    } catch {
        $startupDir = [Environment]::GetFolderPath("Startup")
        if ([string]::IsNullOrWhiteSpace($startupDir)) {
            throw
        }
        $cmdPath = Join-Path $startupDir "SuperOptic-MGS-Stack-Autostart.cmd"
        $cmd = "@echo off`r`n" +
            "PowerShell -NoProfile -ExecutionPolicy Bypass -File `"$starter`" -WebRoot `"$WebRoot`" -XamppRoot `"$XamppRoot`" -MgsRoot `"$MgsRoot`" -WhatsappBridgeRoot `"$WhatsappBridgeRoot`"`r`n" +
            "start `"`" PowerShell -WindowStyle Hidden -NoProfile -ExecutionPolicy Bypass -File `"$workerStarter`" -WebRoot `"$WebRoot`"`r`n"
        Set-Content -LiteralPath $cmdPath -Value $cmd -Encoding ASCII
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $statusPath) | Out-Null
        @{
            installed_at = (Get-Date).ToString("o")
            mode = "startup_folder_fallback"
            launcher = $cmdPath
            principal = $env:USERNAME
            stack_script = $starter
            worker_script = $workerStarter
            whatsapp_bridge_root = $WhatsappBridgeRoot
        } | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $statusPath -Encoding UTF8
        Write-Host "SuperOptic Startup folder fallback installed."
        Write-Host "Launcher: $cmdPath"
    }
    Write-Host "Script: $starter"
    Write-Host "For true server boot autostart, rerun this script from an elevated PowerShell window."
}
