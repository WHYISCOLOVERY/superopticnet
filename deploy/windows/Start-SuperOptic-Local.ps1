[CmdletBinding()]
param(
    [string]$ProjectRoot = 'D:\xampp\htdocs\superopticnet',
    [string]$XamppRoot = 'D:\xampp'
)

$ErrorActionPreference = 'Stop'

$php = Join-Path $XamppRoot 'php\php.exe'
$apache = Join-Path $XamppRoot 'apache\bin\httpd.exe'
$mysql = Join-Path $XamppRoot 'mysql\bin\mysqld.exe'
$mysqlConfig = Join-Path $XamppRoot 'mysql\bin\my.ini'
$localConfig = Join-Path $ProjectRoot 'config.local.php'

foreach ($requiredPath in @($php, $apache, $mysql, $mysqlConfig, $localConfig)) {
    if (-not (Test-Path -LiteralPath $requiredPath)) {
        throw "Required local runtime path is missing: $requiredPath"
    }
}

& $apache -t
if ($LASTEXITCODE -ne 0) {
    throw 'Apache configuration test failed.'
}

if (-not (Get-Process -Name mysqld -ErrorAction SilentlyContinue)) {
    Start-Process `
        -FilePath $mysql `
        -ArgumentList "--defaults-file=$mysqlConfig", '--standalone' `
        -WorkingDirectory (Join-Path $XamppRoot 'mysql\bin') `
        -WindowStyle Hidden

    $mysqlDeadline = (Get-Date).AddSeconds(20)
    do {
        Start-Sleep -Milliseconds 400
        $mysqlListener = Get-NetTCPConnection -State Listen -LocalPort 3306 -ErrorAction SilentlyContinue
    } while (-not $mysqlListener -and (Get-Date) -lt $mysqlDeadline)
    if (-not $mysqlListener) {
        throw 'MySQL did not bind to port 3306.'
    }
}

$secretJson = & $php -r "`$c=require '$($localConfig.Replace('\', '/'))';fwrite(STDOUT,json_encode(array('DB_PASS'=>(string)(`$c['db_pass']??''),'RECAPTCHA_SECRET'=>(string)(`$c['recaptcha_secret']??''),'WHATSAPP_PROVIDER_TOKEN'=>(string)(`$c['whatsapp_provider_token']??''))));"
$localSecrets = $secretJson | ConvertFrom-Json
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace([string]$localSecrets.DB_PASS)) {
    throw 'Local DB password is unavailable.'
}

$existingApache = Get-Process -Name httpd -ErrorAction SilentlyContinue
if ($existingApache) {
    $existingApache | Sort-Object Id -Descending | Stop-Process
    $apacheDeadline = (Get-Date).AddSeconds(10)
    do {
        Start-Sleep -Milliseconds 250
        $existingApache = Get-Process -Name httpd -ErrorAction SilentlyContinue
    } while ($existingApache -and (Get-Date) -lt $apacheDeadline)
    if ($existingApache) {
        $existingApache | Stop-Process -Force
        Start-Sleep -Seconds 1
    }
}

$env:DB_PASS = [string]$localSecrets.DB_PASS
if (-not [string]::IsNullOrWhiteSpace([string]$localSecrets.RECAPTCHA_SECRET)) {
    $env:RECAPTCHA_SECRET = [string]$localSecrets.RECAPTCHA_SECRET
}
if (-not [string]::IsNullOrWhiteSpace([string]$localSecrets.WHATSAPP_PROVIDER_TOKEN)) {
    $env:WHATSAPP_PROVIDER_TOKEN = [string]$localSecrets.WHATSAPP_PROVIDER_TOKEN
}
try {
    Start-Process `
        -FilePath $apache `
        -ArgumentList '-d', ($XamppRoot.Replace('\', '/') + '/apache') `
        -WorkingDirectory (Join-Path $XamppRoot 'apache\bin') `
        -WindowStyle Hidden
} finally {
    foreach ($secretName in @('DB_PASS', 'RECAPTCHA_SECRET', 'WHATSAPP_PROVIDER_TOKEN')) {
        Remove-Item "Env:$secretName" -ErrorAction SilentlyContinue
    }
    $localSecrets = $null
    $secretJson = $null
}

$httpDeadline = (Get-Date).AddSeconds(20)
do {
    Start-Sleep -Milliseconds 400
    $httpListener = Get-NetTCPConnection -State Listen -LocalPort 80 -ErrorAction SilentlyContinue
} while (-not $httpListener -and (Get-Date) -lt $httpDeadline)
if (-not $httpListener) {
    throw 'Apache did not bind to port 80.'
}

$loginUrl = 'http://localhost/superopticnet/staff-login.php'
$response = Invoke-WebRequest -Uri $loginUrl -UseBasicParsing -TimeoutSec 15
if ($response.StatusCode -ne 200) {
    throw "Staff login health check failed with HTTP $($response.StatusCode)."
}

[pscustomobject]@{
    Status = 'ok'
    Apache = 'running'
    MySQL = 'running'
    LoginUrl = $loginUrl
    SecretPersistence = 'process-only'
}
