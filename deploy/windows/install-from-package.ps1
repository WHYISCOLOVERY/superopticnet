[CmdletBinding()]
param(
    [string]$PackageRoot = $PSScriptRoot,
    [string]$XamppRoot = "D:\xampp",
    [string]$WebRoot = "D:\xampp\htdocs\superopticnet",
    [string]$MgsRoot = "D:\xampp\htdocs\oltidareetme",
    [switch]$DryRun,
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$InstallerArgs = @()
)

$ErrorActionPreference = "Stop"

function Write-Step {
    param([string]$Message)
    Write-Host "[SuperOptic Package] $Message" -ForegroundColor Cyan
}

function Copy-SafeTree {
    param([string]$Source, [string]$Destination)
    if ($DryRun) {
        Write-Host "[DRY-RUN] Copy $Source -> $Destination"
        return
    }
    if (-not (Test-Path -LiteralPath $Source)) {
        throw "Source tapilmadi: $Source"
    }
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    robocopy.exe $Source $Destination /E /R:1 /W:1 /NFL /NDL /NP | Out-Null
    if ($LASTEXITCODE -ge 8) {
        throw "robocopy failed: $Source -> $Destination"
    }
}

$payloadZip = Join-Path $PackageRoot "SuperOptic-MGS-Payload.zip"
$payloadDir = Join-Path $PackageRoot "payload"

if ((Test-Path -LiteralPath $payloadZip) -and -not (Test-Path -LiteralPath $payloadDir)) {
    Write-Step "Payload zip acilir"
    if (-not $DryRun) {
        Expand-Archive -LiteralPath $payloadZip -DestinationPath $payloadDir -Force
    }
}

$webPayload = Join-Path $payloadDir "superopticnet"
$mgsPayload = Join-Path $payloadDir "oltidareetme"

Write-Step "Fayllar server pathlerine kopyalanir"
Copy-SafeTree -Source $webPayload -Destination $WebRoot
Copy-SafeTree -Source $mgsPayload -Destination $MgsRoot

$installer = Join-Path $WebRoot "deploy\windows\install-superoptic-mgs.ps1"
if (-not (Test-Path -LiteralPath $installer)) {
    throw "Installer tapilmadi: $installer"
}

Write-Step "Server installer ishe salinir"
$forwardArgs = @("-WebRoot", $WebRoot, "-MgsRoot", $MgsRoot, "-XamppRoot", $XamppRoot)
if ($DryRun) {
    $forwardArgs += "-DryRun"
}
$forwardArgs += $InstallerArgs
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $installer @forwardArgs
if ($LASTEXITCODE -ne 0) {
    throw "install-superoptic-mgs.ps1 exit code $LASTEXITCODE"
}
