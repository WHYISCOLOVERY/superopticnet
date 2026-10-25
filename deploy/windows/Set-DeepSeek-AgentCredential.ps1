[CmdletBinding()]
param(
    [string]$CredentialPath = (Join-Path $env:APPDATA "SuperOptic\deepseek-agent.credential"),
    [switch]$Remove
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version 2.0

if ($Remove) {
    if (Test-Path -LiteralPath $CredentialPath) {
        Remove-Item -LiteralPath $CredentialPath -Force
    }
    Write-Output "DeepSeek agent credential removed."
    exit 0
}

$directory = Split-Path -Parent $CredentialPath
New-Item -ItemType Directory -Path $directory -Force | Out-Null

$identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$directoryAcl = New-Object Security.AccessControl.DirectorySecurity
$directoryAcl.SetAccessRuleProtection($true, $false)
$directoryRule = New-Object Security.AccessControl.FileSystemAccessRule(
    $identity,
    [Security.AccessControl.FileSystemRights]::FullControl,
    [Security.AccessControl.InheritanceFlags]"ContainerInherit, ObjectInherit",
    [Security.AccessControl.PropagationFlags]::None,
    [Security.AccessControl.AccessControlType]::Allow
)
$directoryAcl.AddAccessRule($directoryRule)
Set-Acl -LiteralPath $directory -AclObject $directoryAcl

Write-Output "DeepSeek panelinde evvel paylasilmis acari legv edin ve yeni acar yaradib burada daxil edin."
$secureKey = Read-Host "Yeni DeepSeek API acari" -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureKey)
try {
    $plainLength = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer).Length
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
}
if ($plainLength -lt 20) {
    throw "API key is missing or too short."
}

$encrypted = ConvertFrom-SecureString -SecureString $secureKey
[IO.File]::WriteAllText($CredentialPath, $encrypted, (New-Object Text.UTF8Encoding($false)))
$fileAcl = Get-Acl -LiteralPath $CredentialPath
$fileAcl.SetAccessRuleProtection($true, $false)
foreach ($rule in @($fileAcl.Access)) {
    [void] $fileAcl.RemoveAccessRule($rule)
}
$fileRule = New-Object Security.AccessControl.FileSystemAccessRule(
    $identity,
    [Security.AccessControl.FileSystemRights]::FullControl,
    [Security.AccessControl.AccessControlType]::Allow
)
$fileAcl.AddAccessRule($fileRule)
Set-Acl -LiteralPath $CredentialPath -AclObject $fileAcl

Write-Output "DeepSeek agent credential DPAPI ile cari Windows istifadecisine bagli saxlanildi."
