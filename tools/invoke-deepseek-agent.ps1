[CmdletBinding()]
param(
    [string]$Prompt = "",
    [string]$WorkingDirectory = (Split-Path -Parent $PSScriptRoot),
    [string]$CredentialPath = (Join-Path $env:APPDATA "SuperOptic\deepseek-agent.credential"),
    [ValidateSet("deepseek-v4-pro", "deepseek-v4-flash")]
    [string]$Model = "deepseek-v4-pro",
    [switch]$AllowEdits
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version 2.0

$claude = Join-Path $env:USERPROFILE ".local\bin\claude.exe"
if (-not (Test-Path -LiteralPath $claude -PathType Leaf)) {
    $command = Get-Command claude -ErrorAction SilentlyContinue
    if ($null -eq $command) {
        throw "Claude Code is not installed."
    }
    $claude = $command.Source
}
if (-not (Test-Path -LiteralPath $WorkingDirectory -PathType Container)) {
    throw "Working directory does not exist: $WorkingDirectory"
}

$secureKey = $null
if (-not [string]::IsNullOrWhiteSpace($env:DEEPSEEK_API_KEY)) {
    $secureKey = ConvertTo-SecureString $env:DEEPSEEK_API_KEY -AsPlainText -Force
} elseif (Test-Path -LiteralPath $CredentialPath -PathType Leaf) {
    $secureKey = ConvertTo-SecureString (Get-Content -LiteralPath $CredentialPath -Raw)
} else {
    throw "DeepSeek credential is not configured. Run deploy/windows/Set-DeepSeek-AgentCredential.ps1 first."
}

$managedNames = @(
    "ANTHROPIC_BASE_URL", "ANTHROPIC_AUTH_TOKEN", "ANTHROPIC_MODEL",
    "ANTHROPIC_DEFAULT_OPUS_MODEL", "ANTHROPIC_DEFAULT_SONNET_MODEL",
    "ANTHROPIC_DEFAULT_HAIKU_MODEL", "CLAUDE_CODE_SUBAGENT_MODEL",
    "CLAUDE_CODE_EFFORT_LEVEL"
)
$previous = @{}
foreach ($name in $managedNames) {
    $previous[$name] = [Environment]::GetEnvironmentVariable($name, "Process")
}

$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureKey)
try {
    $authToken = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    $env:ANTHROPIC_BASE_URL = "https://api.deepseek.com/anthropic"
    $env:ANTHROPIC_AUTH_TOKEN = $authToken
    $env:ANTHROPIC_MODEL = $Model
    $env:ANTHROPIC_DEFAULT_OPUS_MODEL = $Model
    $env:ANTHROPIC_DEFAULT_SONNET_MODEL = $Model
    $env:ANTHROPIC_DEFAULT_HAIKU_MODEL = "deepseek-v4-flash"
    $env:CLAUDE_CODE_SUBAGENT_MODEL = "deepseek-v4-flash"
    $env:CLAUDE_CODE_EFFORT_LEVEL = "max"
    $authToken = $null

    $arguments = @("--permission-mode", $(if ($AllowEdits) { "acceptEdits" } else { "plan" }))
    $allowedTools = if ($AllowEdits) { "Read,Grep,Glob,Edit,Write" } else { "Read,Grep,Glob" }
    $arguments += @("--allowedTools", $allowedTools)
    if ($Prompt -ne "") {
        $arguments += @("-p", "--no-session-persistence", $Prompt)
    }

    Push-Location -LiteralPath $WorkingDirectory
    try {
        & $claude @arguments
        exit $LASTEXITCODE
    } finally {
        Pop-Location
    }
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    foreach ($name in $managedNames) {
        [Environment]::SetEnvironmentVariable($name, $previous[$name], "Process")
    }
}
