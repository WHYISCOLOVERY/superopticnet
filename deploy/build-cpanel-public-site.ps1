param(
    [string]$OutputDirectory = "",
    [switch]$NoArchive
)

$ErrorActionPreference = "Stop"
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $repoRoot "output\cpanel-superoptic-site"
}
$outputRoot = [IO.Path]::GetFullPath($OutputDirectory)
$repoPrefix = [IO.Path]::GetFullPath($repoRoot).TrimEnd('\') + '\'
if (-not $outputRoot.StartsWith($repoPrefix, [StringComparison]::OrdinalIgnoreCase)) {
    throw "Output directory must remain inside the repository."
}

if (Test-Path -LiteralPath $outputRoot) {
    Remove-Item -LiteralPath $outputRoot -Recurse -Force
}
$publicRoot = Join-Path $outputRoot "public_html"
$privateRoot = Join-Path $outputRoot "superoptic-private"
New-Item -ItemType Directory -Path $publicRoot, $privateRoot, (Join-Path $privateRoot "data") -Force | Out-Null

$publicFiles = @(
    "index.php",
    "page-helpers.php",
    "area-interest.php",
    "haqqimizda.php",
    "gizlilik-siyaseti.php",
    "qaydalar-ve-sertler.php",
    "keyfiyyet-gostericileri.php",
    "cihazlar.php"
)
foreach ($file in $publicFiles) {
    Copy-Item -LiteralPath (Join-Path $repoRoot $file) -Destination (Join-Path $publicRoot $file)
}

Copy-Item -LiteralPath (Join-Path $repoRoot "images") -Destination (Join-Path $publicRoot "images") -Recurse
Copy-Item -LiteralPath (Join-Path $repoRoot "fonts") -Destination (Join-Path $publicRoot "fonts") -Recurse
New-Item -ItemType Directory -Path (Join-Path $publicRoot "css"), (Join-Path $publicRoot "script"), (Join-Path $publicRoot "data") -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $repoRoot "css\fiber-site.css") -Destination (Join-Path $publicRoot "css\fiber-site.css")
Copy-Item -LiteralPath (Join-Path $repoRoot "css\fontawesome-lite.css") -Destination (Join-Path $publicRoot "css\fontawesome-lite.css")
Copy-Item -LiteralPath (Join-Path $repoRoot "script\main.js") -Destination (Join-Path $publicRoot "script\main.js")
Copy-Item -LiteralPath (Join-Path $repoRoot "script\fiber-bg.js") -Destination (Join-Path $publicRoot "script\fiber-bg.js")
Copy-Item -LiteralPath (Join-Path $repoRoot "data\tv-channels.json") -Destination (Join-Path $publicRoot "data\tv-channels.json")

$cpanelSource = Join-Path $repoRoot "deploy\cpanel"
Copy-Item -LiteralPath (Join-Path $cpanelSource "public-config.php") -Destination (Join-Path $publicRoot "config.php")
Copy-Item -LiteralPath (Join-Path $cpanelSource "muraciet-gonder.php") -Destination (Join-Path $publicRoot "muraciet-gonder.php")
Copy-Item -LiteralPath (Join-Path $cpanelSource "portal-login.php") -Destination (Join-Path $publicRoot "portal-login.php")
Copy-Item -LiteralPath (Join-Path $cpanelSource ".htaccess") -Destination (Join-Path $publicRoot ".htaccess")
Copy-Item -LiteralPath (Join-Path $cpanelSource "public-site.local.example.php") -Destination (Join-Path $privateRoot "public-site.local.example.php")
Copy-Item -LiteralPath (Join-Path $cpanelSource "README-CPanel.txt") -Destination (Join-Path $outputRoot "README-CPanel.txt")

$forbidden = @("config.local.php", ".env", "db.php", "mgs-integration-lib.php", "portal-actions.php")
$foundForbidden = Get-ChildItem -LiteralPath $outputRoot -Recurse -Force |
    Where-Object { $forbidden -contains $_.Name }
if ($foundForbidden) {
    throw "Forbidden internal file entered the public package: $($foundForbidden.FullName -join ', ')"
}

if (-not $NoArchive) {
    $archive = "$outputRoot.zip"
    if (Test-Path -LiteralPath $archive) {
        Remove-Item -LiteralPath $archive -Force
    }
    Compress-Archive -Path (Join-Path $outputRoot "*") -DestinationPath $archive -CompressionLevel Optimal
    Write-Output "archive=$archive"
}
Write-Output "public_root=$publicRoot"
