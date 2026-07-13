[CmdletBinding()]
param(
    [string]$WebRoot = "D:\xampp\htdocs\superopticnet",
    [string]$MgsRoot = "D:\xampp\htdocs\oltidareetme",
    [string]$OutputRoot = "D:\superoptic-release",
    [switch]$SkipExe
)

$ErrorActionPreference = "Stop"

function Resolve-SafeChild {
    param([string]$Base, [string]$Child)
    $baseFull = [IO.Path]::GetFullPath($Base)
    $childFull = [IO.Path]::GetFullPath((Join-Path $Base $Child))
    if (-not $childFull.StartsWith($baseFull, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Unsafe path: $childFull"
    }
    return $childFull
}

function Reset-Directory {
    param([string]$Path, [string]$SafeBase)
    $safe = [IO.Path]::GetFullPath($SafeBase)
    $full = [IO.Path]::GetFullPath($Path)
    if (-not $full.StartsWith($safe, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to reset outside output root: $full"
    }
    if (Test-Path -LiteralPath $full) {
        Remove-Item -LiteralPath $full -Recurse -Force
    }
    New-Item -ItemType Directory -Path $full -Force | Out-Null
}

function Copy-TreeWithRoboCopy {
    param([string]$Source, [string]$Destination, [string[]]$ExcludeDirs, [string[]]$ExcludeFiles)
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    $args = @($Source, $Destination, "/E", "/R:1", "/W:1", "/NFL", "/NDL", "/NP")
    if ($ExcludeDirs.Count -gt 0) {
        $args += "/XD"
        $args += $ExcludeDirs
    }
    if ($ExcludeFiles.Count -gt 0) {
        $args += "/XF"
        $args += $ExcludeFiles
    }
    & robocopy.exe @args | Out-Null
    if ($LASTEXITCODE -ge 8) {
        throw "robocopy failed from $Source to $Destination with exit $LASTEXITCODE"
    }
}

if (-not (Test-Path -LiteralPath $WebRoot)) { throw "WebRoot tapilmadi: $WebRoot" }
if (-not (Test-Path -LiteralPath $MgsRoot)) { throw "MgsRoot tapilmadi: $MgsRoot" }

$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$packageRoot = Resolve-SafeChild -Base $OutputRoot -Child "SuperOptic-MGS-Installer-$stamp"
$payloadRoot = Join-Path $packageRoot "payload"
$webPayload = Join-Path $payloadRoot "superopticnet"
$mgsPayload = Join-Path $payloadRoot "oltidareetme"

Reset-Directory -Path $packageRoot -SafeBase $OutputRoot
New-Item -ItemType Directory -Path $payloadRoot -Force | Out-Null

$commonExcludeDirs = @(".git", "node_modules", "tmp", "logs", "test-results", "runtime\.cache")
$commonExcludeFiles = @("*.log", "*.err.log", "*.out.log", ".mgs-server.pid", "config.local.php", ".env", ".env.*")

Copy-TreeWithRoboCopy -Source $WebRoot -Destination $webPayload -ExcludeDirs $commonExcludeDirs -ExcludeFiles $commonExcludeFiles
Copy-TreeWithRoboCopy -Source $MgsRoot -Destination $mgsPayload -ExcludeDirs $commonExcludeDirs -ExcludeFiles $commonExcludeFiles

Copy-Item -LiteralPath (Join-Path $WebRoot "deploy\windows\install-superoptic-mgs.ps1") -Destination (Join-Path $packageRoot "install-superoptic-mgs.ps1") -Force
Copy-Item -LiteralPath (Join-Path $WebRoot "deploy\windows\SuperOptic-MGS-Install.cmd") -Destination (Join-Path $packageRoot "SuperOptic-MGS-Install.cmd") -Force
Copy-Item -LiteralPath (Join-Path $WebRoot "deploy\windows\install-from-package.ps1") -Destination (Join-Path $packageRoot "install-from-package.ps1") -Force
Copy-Item -LiteralPath (Join-Path $WebRoot "deploy\windows\Install-From-Package.cmd") -Destination (Join-Path $packageRoot "Install-From-Package.cmd") -Force
Copy-Item -LiteralPath (Join-Path $WebRoot "docs\windows-server-install.md") -Destination (Join-Path $packageRoot "windows-server-install.md") -Force
Copy-Item -LiteralPath (Join-Path $WebRoot "docs\ubuntu-server-install.md") -Destination (Join-Path $packageRoot "ubuntu-server-install.md") -Force
Copy-Item -LiteralPath (Join-Path $WebRoot "docs\role-training.md") -Destination (Join-Path $packageRoot "role-training.md") -Force

$readme = @"
SuperOptic MGS Installer Package

1. Run Install-From-Package.cmd as Administrator
2. The installer copies payload to D:\xampp\htdocs\superopticnet and D:\xampp\htdocs\oltidareetme
3. Fill real MySQL/RADIUS secrets on the server only
4. Windows install creates billing, queue, CoA, bot, backup, security anomaly and daily optimizer scheduled tasks
5. Ubuntu install creates matching systemd timers, including security anomaly and daily retention/index optimizer
6. Read windows-server-install.md, ubuntu-server-install.md and role-training.md
"@
Set-Content -LiteralPath (Join-Path $packageRoot "README.txt") -Value $readme -Encoding ASCII

$payloadZip = Join-Path $packageRoot "SuperOptic-MGS-Payload.zip"
Compress-Archive -Path (Join-Path $payloadRoot "*") -DestinationPath $payloadZip -Force
Remove-Item -LiteralPath $payloadRoot -Recurse -Force

$zipPath = Join-Path $OutputRoot "SuperOptic-MGS-Installer-$stamp.zip"
if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}
Compress-Archive -Path (Join-Path $packageRoot "*") -DestinationPath $zipPath -Force

$exePath = Join-Path $OutputRoot "SuperOptic-MGS-Installer-$stamp.exe"
if (-not $SkipExe) {
    $sevenZip = Get-Command 7z.exe -ErrorAction SilentlyContinue
    if (-not $sevenZip -and (Test-Path -LiteralPath "C:\Program Files\7-Zip\7z.exe")) {
        $sevenZip = [pscustomobject]@{ Source = "C:\Program Files\7-Zip\7z.exe" }
    }

    $sevenSfx = $null
    if ($sevenZip) {
        $sevenDir = Split-Path -Parent $sevenZip.Source
        $candidateSfx = Join-Path $sevenDir "7z.sfx"
        if (Test-Path -LiteralPath $candidateSfx) {
            $sevenSfx = $candidateSfx
        }
    }

    if ($sevenZip -and $sevenSfx) {
        $sfxArchive = Join-Path $packageRoot "superoptic-installer.7z"
        $sfxConfig = Join-Path $packageRoot "superoptic-sfx-config.txt"
        $sfxFiles = @(
            "README.txt",
            "windows-server-install.md",
            "ubuntu-server-install.md",
            "role-training.md",
            "SuperOptic-MGS-Install.cmd",
            "install-superoptic-mgs.ps1",
            "Install-From-Package.cmd",
            "install-from-package.ps1",
            "SuperOptic-MGS-Payload.zip"
        )
        $config = @"
;!@Install@!UTF-8!
Title="SuperOptic MGS Installer"
RunProgram="Install-From-Package.cmd"
;!@InstallEnd@!
"@
        Set-Content -LiteralPath $sfxConfig -Value $config -Encoding UTF8
        Push-Location $packageRoot
        try {
            & $sevenZip.Source a -t7z $sfxArchive @sfxFiles | Out-Null
            if ($LASTEXITCODE -ne 0) {
                throw "7z archive failed with exit $LASTEXITCODE"
            }
        } finally {
            Pop-Location
        }

        $out = [IO.File]::Open($exePath, [IO.FileMode]::Create, [IO.FileAccess]::Write)
        try {
            foreach ($part in @($sevenSfx, $sfxConfig, $sfxArchive)) {
                $bytes = [IO.File]::ReadAllBytes($part)
                $out.Write($bytes, 0, $bytes.Length)
            }
        } finally {
            $out.Close()
        }
    } else {
        $iexpress = Get-Command iexpress.exe -ErrorAction SilentlyContinue
        if ($iexpress) {
            $sedPath = Join-Path $packageRoot "superoptic-installer.sed"
            $sed = @"
[Version]
Class=IEXPRESS
SEDVersion=3
[Options]
PackagePurpose=InstallApp
ShowInstallProgramWindow=1
HideExtractAnimation=0
UseLongFileName=1
InsideCompressed=0
CAB_FixedSize=0
CAB_ResvCodeSigning=0
RebootMode=N
InstallPrompt=
DisplayLicense=
FinishMessage=SuperOptic MGS installer extracted. Run steps are in README.txt.
TargetName=$exePath
FriendlyName=SuperOptic MGS Installer
AppLaunched=cmd /c README.txt
PostInstallCmd=<None>
AdminQuietInstCmd=
UserQuietInstCmd=
SourceFiles=SourceFiles
[SourceFiles]
SourceFiles0=$packageRoot
[SourceFiles0]
README.txt=
windows-server-install.md=
ubuntu-server-install.md=
role-training.md=
SuperOptic-MGS-Install.cmd=
install-superoptic-mgs.ps1=
Install-From-Package.cmd=
install-from-package.ps1=
SuperOptic-MGS-Payload.zip=
"@
            Set-Content -LiteralPath $sedPath -Value $sed -Encoding ASCII
            & $iexpress.Source /N /Q $sedPath
        } else {
            Write-Warning "7-Zip SFX ve iexpress.exe tapilmadi; EXE yaradilmayacaq."
        }
    }
}

[pscustomobject]@{
    status = "ok"
    package_root = $packageRoot
    zip = $zipPath
    exe = if (Test-Path -LiteralPath $exePath) { $exePath } else { $null }
} | ConvertTo-Json -Depth 3
