[CmdletBinding()]
param(
    [ValidateSet("Machine", "Process")]
    [string]$Target = "Machine",
    [string]$DbHost = "",
    [int]$DbPort = 0,
    [string]$DbName = "",
    [string]$DbUser = "",
    [string]$RadiusDbHost = "",
    [int]$RadiusDbPort = 0,
    [string]$RadiusDbName = "",
    [string]$RadiusDbUser = "",
    [switch]$DryRun,
    [switch]$NonInteractive,
    [switch]$Rotate,
    [switch]$ValidateOnly
)

$ErrorActionPreference = "Stop"

$secretDefinitions = @(
    [pscustomobject]@{ Name = "DB_PASS"; Label = "MySQL application password"; MinLength = 8; Generated = $false },
    [pscustomobject]@{ Name = "RADIUS_DB_PASS"; Label = "RADIUS SQL password"; MinLength = 8; Generated = $false },
    [pscustomobject]@{ Name = "RADIUS_ROUTER_SECRET"; Label = "RADIUS router shared secret"; MinLength = 8; Generated = $false },
    [pscustomobject]@{ Name = "DEEPSEEK_API_KEY"; Label = "DeepSeek API key"; MinLength = 8; Generated = $false },
    [pscustomobject]@{ Name = "SMS_PROVIDER_PUBLIC_KEY"; Label = "SMS provider public key"; MinLength = 8; Generated = $false },
    [pscustomobject]@{ Name = "SMS_PROVIDER_PRIVATE_KEY"; Label = "SMS provider private key"; MinLength = 8; Generated = $false },
    [pscustomobject]@{ Name = "TELEGRAM_TOKEN"; Label = "Telegram bot token"; MinLength = 24; Generated = $false },
    [pscustomobject]@{ Name = "TELEGRAM_NETWORK_BOT_TOKEN"; Label = "Telegram network bot token"; MinLength = 24; Generated = $false },
    [pscustomobject]@{ Name = "TELEGRAM_CUSTOMER_BOT_TOKEN"; Label = "Telegram customer bot token"; MinLength = 24; Generated = $false },
    [pscustomobject]@{ Name = "TELEGRAM_FINANCE_BOT_TOKEN"; Label = "Telegram finance bot token"; MinLength = 24; Generated = $false },
    [pscustomobject]@{ Name = "TELEGRAM_WAREHOUSE_BOT_TOKEN"; Label = "Telegram warehouse bot token"; MinLength = 24; Generated = $false },
    [pscustomobject]@{ Name = "TELEGRAM_BACKUP_BOT_TOKEN"; Label = "Telegram backup bot token"; MinLength = 24; Generated = $false },
    [pscustomobject]@{ Name = "TELEGRAM_DIAGNOSTICS_BOT_TOKEN"; Label = "Telegram diagnostics bot token"; MinLength = 24; Generated = $false },
    [pscustomobject]@{ Name = "TELEGRAM_MODEM_SEARCH_BOT_TOKEN"; Label = "Telegram modem-search bot token"; MinLength = 24; Generated = $false },
    [pscustomobject]@{ Name = "WHATSAPP_PROVIDER_TOKEN"; Label = "WhatsApp provider token"; MinLength = 8; Generated = $false },
    [pscustomobject]@{ Name = "RADIUS_SSH_PASSWORD"; Label = "Router SSH password"; MinLength = 8; Generated = $false },
    [pscustomobject]@{ Name = "RADIUS_TEST_PASSWORD"; Label = "RADIUS test-user password"; MinLength = 24; Generated = $true },
    [pscustomobject]@{ Name = "SNMP_COMMUNITY"; Label = "SNMP read-only community"; MinLength = 8; Generated = $false },
    [pscustomobject]@{ Name = "IMPORT_API_TOKEN"; Label = "Import API token"; MinLength = 24; Generated = $true },
    [pscustomobject]@{ Name = "COPILOT_API_TOKEN"; Label = "Copilot API token"; MinLength = 24; Generated = $true },
    [pscustomobject]@{ Name = "RECAPTCHA_SECRET"; Label = "reCAPTCHA provider secret"; MinLength = 24; Generated = $false },
    [pscustomobject]@{ Name = "BILLING_SSO_SHARED_SECRET"; Label = "Billing SSO shared secret"; MinLength = 32; Generated = $true },
    [pscustomobject]@{ Name = "OLT_SSO_SHARED_SECRET"; Label = "OLT SSO shared secret"; MinLength = 32; Generated = $true },
    [pscustomobject]@{ Name = "OLT_USER_SYNC_SECRET"; Label = "OLT user-sync shared secret"; MinLength = 32; Generated = $true },
    [pscustomobject]@{ Name = "MYGOV_CLIENT_SECRET"; Label = "MyGov client secret"; MinLength = 24; Generated = $false },
    [pscustomobject]@{ Name = "API_SECRET_KEY"; Label = "WhatsApp bridge API secret key"; MinLength = 32; Generated = $true },
    [pscustomobject]@{ Name = "PAIRING_WORKER_TOKEN"; Label = "WhatsApp pairing-worker token"; MinLength = 32; Generated = $true },
    [pscustomobject]@{ Name = "CLICKHOUSE_PASSWORD"; Label = "ClickHouse service password"; MinLength = 24; Generated = $true },
    [pscustomobject]@{ Name = "PORTAL_TICKET_CLOSE_SECRET"; Label = "Portal ticket-close signing secret"; MinLength = 32; Generated = $true },
    [pscustomobject]@{ Name = "PROVIDER_API_TOKEN"; Label = "Provider API token"; MinLength = 24; Generated = $true },
    [pscustomobject]@{ Name = "PROVIDER_API_HMAC_SECRET"; Label = "Provider API HMAC secret"; MinLength = 32; Generated = $true },
    [pscustomobject]@{ Name = "EMANAT_PROVIDER_API_TOKEN"; Label = "Emanat provider API token"; MinLength = 24; Generated = $true },
    [pscustomobject]@{ Name = "EMANAT_PROVIDER_HMAC_SECRET"; Label = "Emanat provider HMAC secret"; MinLength = 32; Generated = $true },
    [pscustomobject]@{ Name = "UNITEDPAYMENT_PROVIDER_API_TOKEN"; Label = "United Payment provider API token"; MinLength = 24; Generated = $true },
    [pscustomobject]@{ Name = "UNITEDPAYMENT_PROVIDER_HMAC_SECRET"; Label = "United Payment provider HMAC secret"; MinLength = 32; Generated = $true },
    [pscustomobject]@{ Name = "MGS_SERVICE_SECRET"; Label = "MGS service shared secret"; MinLength = 24; Generated = $true },
    [pscustomobject]@{ Name = "MGS_JWT_SECRET"; Label = "MGS JWT signing secret"; MinLength = 32; Generated = $true },
    [pscustomobject]@{ Name = "MGS_SECRETS_MASTER_KEY"; Label = "MGS encrypted-device-secret master key"; MinLength = 32; Generated = $true },
    [pscustomobject]@{ Name = "MGS_PORTAL_SHARED_SECRET"; Label = "MGS portal shared secret"; MinLength = 24; Generated = $true },
    [pscustomobject]@{ Name = "MGS_TELEGRAM_WEBHOOK_SECRET"; Label = "MGS Telegram webhook secret"; MinLength = 24; Generated = $true }
)

$secretAliases = [ordered]@{
    RADIUS_SQL_PASSWORD = "RADIUS_DB_PASS"
    RADIUS_SHARED_SECRET = "RADIUS_ROUTER_SECRET"
    SMS_PROVIDER_USER = "SMS_PROVIDER_PUBLIC_KEY"
    SMS_PROVIDER_PASSWORD = "SMS_PROVIDER_PRIVATE_KEY"
    MGS_SERVICE_SHARED_SECRET = "MGS_SERVICE_SECRET"
    SERVICE_SHARED_SECRET = "MGS_SERVICE_SECRET"
    JWT_SECRET = "MGS_JWT_SECRET"
    SECRETS_MASTER_KEY = "MGS_SECRETS_MASTER_KEY"
    PORTAL_SHARED_SECRET = "MGS_PORTAL_SHARED_SECRET"
    TELEGRAM_WEBHOOK_SECRET = "MGS_TELEGRAM_WEBHOOK_SECRET"
    TELEGRAM_BOT_TOKEN = "TELEGRAM_TOKEN"
    WHATSAPP_PROVIDER_API_KEY = "WHATSAPP_PROVIDER_TOKEN"
    SMS_PROVIDER_API_KEY = "SMS_PROVIDER_PRIVATE_KEY"
}

function Write-SecretStep {
    param([string]$Message)
    Write-Host "[SuperOptic secrets] $Message" -ForegroundColor Cyan
}

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function New-CryptographicSecret {
    $bytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    } finally {
        $generator.Dispose()
    }
    return [Convert]::ToBase64String($bytes)
}

function ConvertFrom-SecurePrompt {
    param([Security.SecureString]$SecureValue)
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($SecureValue)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

function Read-SecretValue {
    param([string]$Label)
    $secureValue = Read-Host -Prompt $Label -AsSecureString
    return ConvertFrom-SecurePrompt -SecureValue $secureValue
}

function Test-PlaceholderSecret {
    param([string]$Value)
    $normalized = $Value.Trim().ToUpperInvariant()
    return $normalized.Contains("CHANGE_ME") -or
        $normalized.Contains("CHANGEME") -or
        $normalized.Contains("EXAMPLE") -or
        $normalized.Contains("TEST_SECRET") -or
        $normalized.Contains("DRY_RUN_SECRET") -or
        $normalized -in @("SECRET", "TESTING123", "PUBLIC", "PRIVATE")
}

function Assert-SecretValue {
    param(
        [string]$Name,
        [string]$Value,
        [int]$MinLength
    )
    if ([string]::IsNullOrEmpty($Value)) {
        throw "$Name is missing"
    }
    if ($Value.Contains("`r") -or $Value.Contains("`n")) {
        throw "$Name contains a line break"
    }
    if ($Value.Length -lt $MinLength) {
        throw "$Name does not meet the minimum length policy"
    }
    if (Test-PlaceholderSecret -Value $Value) {
        throw "$Name contains a placeholder/default value"
    }
}

function Get-TargetEnvironmentValue {
    param([string]$Name)
    return [Environment]::GetEnvironmentVariable($Name, $Target)
}

function Resolve-MetadataString {
    param([string]$ExplicitValue, [string]$EnvironmentName, [string]$DefaultValue)
    if ($ExplicitValue -ne "") {
        return $ExplicitValue
    }
    $stored = [string](Get-TargetEnvironmentValue -Name $EnvironmentName)
    return $(if ($stored -ne "") { $stored } else { $DefaultValue })
}

function Resolve-MetadataPort {
    param([int]$ExplicitValue, [string]$EnvironmentName, [int]$DefaultValue)
    if ($ExplicitValue -gt 0) {
        return $ExplicitValue
    }
    $stored = [string](Get-TargetEnvironmentValue -Name $EnvironmentName)
    $parsed = 0
    if ($stored -ne "" -and [int]::TryParse($stored, [ref]$parsed)) {
        return $parsed
    }
    return $DefaultValue
}

function New-MySqlUrl {
    param(
        [string]$HostName,
        [int]$Port,
        [string]$DatabaseName,
        [string]$UserName,
        [string]$Password
    )
    if ($HostName -eq "" -or $HostName -match '[\s/@?#]') {
        throw "Invalid database host metadata"
    }
    if ($Port -lt 1 -or $Port -gt 65535) {
        throw "Invalid database port metadata"
    }
    if ($DatabaseName -notmatch '^[A-Za-z0-9_]+$') {
        throw "Invalid database name metadata"
    }
    if ($UserName -eq "") {
        throw "Invalid database user metadata"
    }
    $formattedHost = if ($HostName.Contains(":") -and -not $HostName.StartsWith("[")) { "[$HostName]" } else { $HostName }
    $encodedUser = [Uri]::EscapeDataString($UserName)
    $encodedPassword = [Uri]::EscapeDataString($Password)
    $encodedDatabase = [Uri]::EscapeDataString($DatabaseName)
    return "mysql://${encodedUser}:${encodedPassword}@${formattedHost}:${Port}/${encodedDatabase}"
}

if ($ValidateOnly -and $Rotate) {
    throw "-ValidateOnly and -Rotate cannot be combined"
}
if ($ValidateOnly -and $DryRun) {
    throw "-ValidateOnly and -DryRun cannot be combined"
}
if (-not $DryRun -and -not $ValidateOnly -and $Target -eq "Machine" -and -not (Test-IsAdministrator)) {
    throw "Run PowerShell as Administrator to update the machine service environment"
}

$metadata = [ordered]@{
    SUPEROPTIC_DB_HOST = Resolve-MetadataString -ExplicitValue $DbHost -EnvironmentName "SUPEROPTIC_DB_HOST" -DefaultValue "127.0.0.1"
    SUPEROPTIC_DB_PORT = [string](Resolve-MetadataPort -ExplicitValue $DbPort -EnvironmentName "SUPEROPTIC_DB_PORT" -DefaultValue 3306)
    SUPEROPTIC_DB_NAME = Resolve-MetadataString -ExplicitValue $DbName -EnvironmentName "SUPEROPTIC_DB_NAME" -DefaultValue "superoptic_portal"
    SUPEROPTIC_DB_USER = Resolve-MetadataString -ExplicitValue $DbUser -EnvironmentName "SUPEROPTIC_DB_USER" -DefaultValue "superoptic_app"
    SUPEROPTIC_RADIUS_DB_HOST = Resolve-MetadataString -ExplicitValue $RadiusDbHost -EnvironmentName "SUPEROPTIC_RADIUS_DB_HOST" -DefaultValue "127.0.0.1"
    SUPEROPTIC_RADIUS_DB_PORT = [string](Resolve-MetadataPort -ExplicitValue $RadiusDbPort -EnvironmentName "SUPEROPTIC_RADIUS_DB_PORT" -DefaultValue 3306)
    SUPEROPTIC_RADIUS_DB_NAME = Resolve-MetadataString -ExplicitValue $RadiusDbName -EnvironmentName "SUPEROPTIC_RADIUS_DB_NAME" -DefaultValue "superoptic_portal"
    SUPEROPTIC_RADIUS_DB_USER = Resolve-MetadataString -ExplicitValue $RadiusDbUser -EnvironmentName "SUPEROPTIC_RADIUS_DB_USER" -DefaultValue "radius"
}

$resolved = [ordered]@{}
$presentInDryRun = 0
$generatedInDryRun = 0
$missingInDryRun = 0
$existingMasterKey = [string](Get-TargetEnvironmentValue -Name "MGS_SECRETS_MASTER_KEY")

foreach ($definition in $secretDefinitions) {
    $name = [string]$definition.Name
    $value = ""

    if ($Rotate -and $name -eq "MGS_SECRETS_MASTER_KEY" -and $existingMasterKey -ne "") {
        $suppliedMasterKey = [string][Environment]::GetEnvironmentVariable($name, "Process")
        if ($suppliedMasterKey -ne "" -and $suppliedMasterKey -cne $existingMasterKey) {
            throw "MGS_SECRETS_MASTER_KEY cannot be changed by general rotation; re-encrypt stored device credentials with a versioned key migration first"
        }
        $value = $existingMasterKey
    } elseif ($ValidateOnly) {
        $value = [string](Get-TargetEnvironmentValue -Name $name)
    } elseif ($DryRun) {
        $value = [string][Environment]::GetEnvironmentVariable($name, "Process")
        if ($value -eq "") {
            $value = [string](Get-TargetEnvironmentValue -Name $name)
        }
        if ($value -eq "") {
            if ($definition.Generated) {
                $generatedInDryRun++
            } else {
                $missingInDryRun++
            }
            continue
        }
        $presentInDryRun++
    } elseif ($Rotate -and -not $NonInteractive) {
        if ($definition.Generated) {
            $value = New-CryptographicSecret
        } else {
            $value = Read-SecretValue -Label ([string]$definition.Label)
        }
    } else {
        if (-not $Rotate) {
            $value = [string](Get-TargetEnvironmentValue -Name $name)
        }
        if ($value -eq "") {
            $value = [string][Environment]::GetEnvironmentVariable($name, "Process")
        }
        if ($value -eq "" -and $definition.Generated) {
            $value = New-CryptographicSecret
        } elseif ($value -eq "" -and $NonInteractive) {
            throw "$name is required in non-interactive mode"
        } elseif ($value -eq "") {
            $value = Read-SecretValue -Label ([string]$definition.Label)
        }
    }

    Assert-SecretValue -Name $name -Value $value -MinLength ([int]$definition.MinLength)
    $resolved[$name] = $value
}

if ($DryRun) {
    Write-SecretStep "[DRY-RUN] $presentInDryRun supplied secret(s) passed validation; $generatedInDryRun would be generated and $missingInDryRun collected securely"
    Write-SecretStep "[DRY-RUN] would update the $Target environment; no values were displayed"
    return
}

foreach ($alias in $secretAliases.GetEnumerator()) {
    $resolved[[string]$alias.Key] = [string]$resolved[[string]$alias.Value]
}
foreach ($entry in $metadata.GetEnumerator()) {
    $resolved[[string]$entry.Key] = [string]$entry.Value
}
$resolved["DATABASE_URL"] = New-MySqlUrl `
    -HostName ([string]$metadata.SUPEROPTIC_DB_HOST) `
    -Port ([int]$metadata.SUPEROPTIC_DB_PORT) `
    -DatabaseName ([string]$metadata.SUPEROPTIC_DB_NAME) `
    -UserName ([string]$metadata.SUPEROPTIC_DB_USER) `
    -Password ([string]$resolved.DB_PASS)
$resolved["RADIUS_DATABASE_URL"] = New-MySqlUrl `
    -HostName ([string]$metadata.SUPEROPTIC_RADIUS_DB_HOST) `
    -Port ([int]$metadata.SUPEROPTIC_RADIUS_DB_PORT) `
    -DatabaseName ([string]$metadata.SUPEROPTIC_RADIUS_DB_NAME) `
    -UserName ([string]$metadata.SUPEROPTIC_RADIUS_DB_USER) `
    -Password ([string]$resolved.RADIUS_DB_PASS)

if ($ValidateOnly) {
    foreach ($alias in $secretAliases.GetEnumerator()) {
        $aliasValue = [string](Get-TargetEnvironmentValue -Name ([string]$alias.Key))
        if ($aliasValue -eq "" -or $aliasValue -cne [string]$resolved[[string]$alias.Value]) {
            throw "$($alias.Key) alias is missing or inconsistent"
        }
    }
    foreach ($name in @($metadata.Keys) + @("DATABASE_URL", "RADIUS_DATABASE_URL")) {
        $storedValue = [string](Get-TargetEnvironmentValue -Name ([string]$name))
        if ($storedValue -eq "" -or $storedValue -cne [string]$resolved[[string]$name]) {
            throw "$name is missing or inconsistent"
        }
    }
    Write-SecretStep "Secret environment validation passed; values were not displayed"
    return
}

$previousTarget = [ordered]@{}
$previousProcess = [ordered]@{}
try {
    foreach ($entry in $resolved.GetEnumerator()) {
        $name = [string]$entry.Key
        $previousTarget[$name] = [Environment]::GetEnvironmentVariable($name, $Target)
        $previousProcess[$name] = [Environment]::GetEnvironmentVariable($name, "Process")
        [Environment]::SetEnvironmentVariable($name, [string]$entry.Value, $Target)
        [Environment]::SetEnvironmentVariable($name, [string]$entry.Value, "Process")
    }
} catch {
    foreach ($entry in $previousTarget.GetEnumerator()) {
        [Environment]::SetEnvironmentVariable([string]$entry.Key, $entry.Value, $Target)
        [Environment]::SetEnvironmentVariable([string]$entry.Key, $previousProcess[[string]$entry.Key], "Process")
    }
    throw
}

Write-SecretStep "$Target environment updated atomically as far as the OS API permits; values were not displayed"
if ($Target -eq "Machine") {
    Write-SecretStep "Restart Apache/MGS services so long-running processes inherit the rotated environment"
}
