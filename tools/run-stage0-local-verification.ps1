[CmdletBinding()]
param(
    [string]$ProjectRoot = 'D:\xampp\htdocs\superopticnet',
    [string]$PhpPath = 'D:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $ProjectRoot -PathType Container)) {
    throw "Project root not found: $ProjectRoot"
}
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP executable not found: $PhpPath"
}

$localConfig = Join-Path $ProjectRoot 'config.local.php'
if (-not (Test-Path -LiteralPath $localConfig -PathType Leaf)) {
    throw 'Local configuration is unavailable.'
}

$phpConfigPath = $localConfig.Replace('\', '/')
$extractCode = @"
`$config = require '$phpConfigPath';
`$keys = array(
    'db_pass', 'radius_shared_secret', 'provider_api_token', 'provider_api_hmac_secret',
    'copilot_api_token', 'recaptcha_secret', 'telegram_token', 'telegram_network_bot_token',
    'telegram_customer_bot_token', 'telegram_finance_bot_token', 'telegram_warehouse_bot_token',
    'telegram_backup_bot_token', 'telegram_diagnostics_bot_token', 'telegram_modem_search_bot_token',
    'sms_provider_user', 'sms_provider_password', 'whatsapp_provider_token',
    'mgs_service_shared_secret', 'olt_sso_shared_secret', 'db_host', 'db_port', 'db_name', 'db_user'
);
`$result = array();
foreach (`$keys as `$key) {
    `$value = `$config[`$key] ?? '';
    if (`$value !== '' && `$value !== null) { `$result[`$key] = (string) `$value; }
}
fwrite(STDOUT, json_encode(`$result, JSON_UNESCAPED_SLASHES));
"@

$secretJson = & $PhpPath -r $extractCode
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace([string]$secretJson)) {
    throw 'Local migration secret source could not be loaded.'
}
$legacy = $secretJson | ConvertFrom-Json
function Get-LegacyString {
    param(
        [object]$Source,
        [string]$Name,
        [string]$DefaultValue = ''
    )
    $property = $Source.PSObject.Properties[$Name]
    if ($null -eq $property -or $null -eq $property.Value -or [string]::IsNullOrWhiteSpace([string]$property.Value)) {
        return $DefaultValue
    }
    return [string]$property.Value
}

$dbPassword = Get-LegacyString -Source $legacy -Name 'db_pass'
if ([string]::IsNullOrWhiteSpace($dbPassword)) {
    throw 'DB_PASS migration source is unavailable.'
}

$environment = [ordered]@{}
function Add-EnvironmentValue {
    param([string]$Name, [string]$Value)
    if (-not [string]::IsNullOrWhiteSpace($Value)) {
        $environment[$Name] = $Value
    }
}

Add-EnvironmentValue 'DB_PASS' $dbPassword
$radiusSharedSecret = Get-LegacyString -Source $legacy -Name 'radius_shared_secret'
$telegramToken = Get-LegacyString -Source $legacy -Name 'telegram_token'
$smsPublicKey = Get-LegacyString -Source $legacy -Name 'sms_provider_user'
$smsPrivateKey = Get-LegacyString -Source $legacy -Name 'sms_provider_password'
$whatsAppToken = Get-LegacyString -Source $legacy -Name 'whatsapp_provider_token'
$mgsServiceSecret = Get-LegacyString -Source $legacy -Name 'mgs_service_shared_secret'

Add-EnvironmentValue 'RADIUS_ROUTER_SECRET' $radiusSharedSecret
Add-EnvironmentValue 'RADIUS_SHARED_SECRET' $radiusSharedSecret
Add-EnvironmentValue 'PROVIDER_API_TOKEN' (Get-LegacyString -Source $legacy -Name 'provider_api_token')
Add-EnvironmentValue 'PROVIDER_API_HMAC_SECRET' (Get-LegacyString -Source $legacy -Name 'provider_api_hmac_secret')
Add-EnvironmentValue 'COPILOT_API_TOKEN' (Get-LegacyString -Source $legacy -Name 'copilot_api_token')
Add-EnvironmentValue 'RECAPTCHA_SECRET' (Get-LegacyString -Source $legacy -Name 'recaptcha_secret')
Add-EnvironmentValue 'TELEGRAM_TOKEN' $telegramToken
Add-EnvironmentValue 'TELEGRAM_BOT_TOKEN' $telegramToken
Add-EnvironmentValue 'TELEGRAM_NETWORK_BOT_TOKEN' (Get-LegacyString -Source $legacy -Name 'telegram_network_bot_token')
Add-EnvironmentValue 'TELEGRAM_CUSTOMER_BOT_TOKEN' (Get-LegacyString -Source $legacy -Name 'telegram_customer_bot_token')
Add-EnvironmentValue 'TELEGRAM_FINANCE_BOT_TOKEN' (Get-LegacyString -Source $legacy -Name 'telegram_finance_bot_token')
Add-EnvironmentValue 'TELEGRAM_WAREHOUSE_BOT_TOKEN' (Get-LegacyString -Source $legacy -Name 'telegram_warehouse_bot_token')
Add-EnvironmentValue 'TELEGRAM_BACKUP_BOT_TOKEN' (Get-LegacyString -Source $legacy -Name 'telegram_backup_bot_token')
Add-EnvironmentValue 'TELEGRAM_DIAGNOSTICS_BOT_TOKEN' (Get-LegacyString -Source $legacy -Name 'telegram_diagnostics_bot_token')
Add-EnvironmentValue 'TELEGRAM_MODEM_SEARCH_BOT_TOKEN' (Get-LegacyString -Source $legacy -Name 'telegram_modem_search_bot_token')
Add-EnvironmentValue 'SMS_PROVIDER_PUBLIC_KEY' $smsPublicKey
Add-EnvironmentValue 'SMS_PROVIDER_USER' $smsPublicKey
Add-EnvironmentValue 'SMS_PROVIDER_PRIVATE_KEY' $smsPrivateKey
Add-EnvironmentValue 'SMS_PROVIDER_PASSWORD' $smsPrivateKey
Add-EnvironmentValue 'SMS_PROVIDER_API_KEY' $smsPrivateKey
Add-EnvironmentValue 'WHATSAPP_PROVIDER_TOKEN' $whatsAppToken
Add-EnvironmentValue 'WHATSAPP_PROVIDER_API_KEY' $whatsAppToken
Add-EnvironmentValue 'MGS_SERVICE_SECRET' $mgsServiceSecret
Add-EnvironmentValue 'MGS_SERVICE_SHARED_SECRET' $mgsServiceSecret
Add-EnvironmentValue 'SERVICE_SHARED_SECRET' $mgsServiceSecret
Add-EnvironmentValue 'OLT_SSO_SHARED_SECRET' (Get-LegacyString -Source $legacy -Name 'olt_sso_shared_secret')

$dbHost = Get-LegacyString -Source $legacy -Name 'db_host' -DefaultValue '127.0.0.1'
$dbPort = Get-LegacyString -Source $legacy -Name 'db_port' -DefaultValue '3306'
$dbName = Get-LegacyString -Source $legacy -Name 'db_name' -DefaultValue 'superoptic_portal'
$dbUser = Get-LegacyString -Source $legacy -Name 'db_user' -DefaultValue 'superoptic_app'
Add-EnvironmentValue 'SUPEROPTIC_DB_HOST' $dbHost
Add-EnvironmentValue 'SUPEROPTIC_DB_PORT' $dbPort
Add-EnvironmentValue 'SUPEROPTIC_DB_NAME' $dbName
Add-EnvironmentValue 'SUPEROPTIC_DB_USER' $dbUser

$encodedUser = [Uri]::EscapeDataString($dbUser)
$encodedPassword = [Uri]::EscapeDataString($dbPassword)
$encodedDatabase = [Uri]::EscapeDataString($dbName)
Add-EnvironmentValue 'DATABASE_URL' "mysql://${encodedUser}:${encodedPassword}@${dbHost}:${dbPort}/${encodedDatabase}"

$previous = [ordered]@{}
try {
    foreach ($entry in $environment.GetEnumerator()) {
        $previous[$entry.Key] = [Environment]::GetEnvironmentVariable([string]$entry.Key, 'Process')
        [Environment]::SetEnvironmentVariable([string]$entry.Key, [string]$entry.Value, 'Process')
    }

    $commands = @(
        @('tools/run-portal-migrations.php', '--list'),
        @('tools/secret-source-inventory.php'),
        @('tools/secret-cutover-smoke.php'),
        @('tools/full-system-regression.php')
    )
    foreach ($arguments in $commands) {
        Write-Host "[Stage 0] Running $($arguments -join ' ')" -ForegroundColor Cyan
        & $PhpPath @arguments
        if ($LASTEXITCODE -ne 0) {
            throw "Stage 0 command failed: $($arguments -join ' ')"
        }
    }
} finally {
    foreach ($entry in $previous.GetEnumerator()) {
        [Environment]::SetEnvironmentVariable([string]$entry.Key, $entry.Value, 'Process')
    }
    $legacy = $null
    $secretJson = $null
    $environment.Clear()
}

[pscustomobject]@{
    status = 'ok'
    secret_persistence = 'process-only'
    values_exposed = $false
} | ConvertTo-Json
