[CmdletBinding(DefaultParameterSetName = 'Plan')]
param(
    [Parameter(ParameterSetName = 'Apply', Mandatory = $true)][switch]$Apply,
    [Parameter(ParameterSetName = 'Rollback', Mandatory = $true)][switch]$Rollback,
    [int]$ExpectedCustomers = 5500,
    [int]$ExpectedPayments = 0,
    [int]$PaymentWindow = 10000,
    [string]$PveHost = '85.132.57.219',
    [string]$RouterHost = '85.132.57.218',
    [int]$RouterPort = 8022,
    [string]$RouterUser = 'semender',
    [string]$PveHostKey = 'SHA256:BhJtGVI1S3JBiJe0NU1E+4T+eleEr1qgentCcfOTTN4',
    [string]$RouterHostKey = 'SHA256:e42QkwT1BiM9+Nk4FZdq2SZ58maZhYBhwzMdZ3SHeX8'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Require-SecretEnvironment([string]$Name) {
    $value = [Environment]::GetEnvironmentVariable($Name)
    if ([string]::IsNullOrWhiteSpace($value)) {
        throw "Required secret environment variable is missing: $Name"
    }
    return $value
}

function Invoke-Pve([string]$Command) {
    $password = Require-SecretEnvironment 'SUPEROPTIC_PVE_PASSWORD'
    $arguments = @('-batch', '-ssh', '-hostkey', $PveHostKey, '-pw', $password, "root@$PveHost", $Command)
    $output = & $script:Plink @arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "PVE command failed with exit code $LASTEXITCODE"
    }
    return ($output -join "`n")
}

function Invoke-MgtHelper([string]$Mode, [int]$Payments = $ExpectedPayments) {
    $command = "ssh -i /root/.ssh/sot_vm_ed25519 sardar@192.168.100.201 sudo -n env EXPECTED_CUSTOMERS=$ExpectedCustomers EXPECTED_PAYMENTS=$Payments PAYMENT_WINDOW=$PaymentWindow /usr/local/sbin/superoptic-legacy-cutover-helper $Mode"
    return Invoke-Pve $command
}

function Invoke-Router([string[]]$Commands) {
    $password = Require-SecretEnvironment 'SUPEROPTIC_ROUTER_PASSWORD'
    $start = [System.Diagnostics.ProcessStartInfo]::new()
    $start.FileName = $script:Plink
    $start.Arguments = "-batch -ssh -t -P $RouterPort -hostkey $RouterHostKey -pw $password $RouterUser@$RouterHost"
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $process = [System.Diagnostics.Process]::new()
    $process.StartInfo = $start
    if (-not $process.Start()) { throw 'Router SSH process could not start.' }
    Start-Sleep -Milliseconds 1200
    $process.StandardInput.WriteLine('')
    $process.StandardInput.Flush()
    Start-Sleep -Milliseconds 1200
    foreach ($command in @('terminal length 0') + $Commands + @('exit')) {
        $process.StandardInput.WriteLine($command)
        $process.StandardInput.Flush()
        Start-Sleep -Milliseconds 350
    }
    $process.StandardInput.Close()
    $stdout = $process.StandardOutput.ReadToEnd()
    $stderr = $process.StandardError.ReadToEnd()
    if (-not $process.WaitForExit(45000)) {
        $process.Kill()
        throw 'Router SSH command timed out.'
    }
    if ($process.ExitCode -ne 0) {
        throw "Router SSH failed with exit code $($process.ExitCode): $stderr"
    }
    if ($stdout -match '% Invalid|% Incomplete|% Ambiguous|Error') {
        throw 'Router rejected at least one cutover command.'
    }
    return $stdout
}

function Set-RouterNat([bool]$UseNewServer) {
    if ($UseNewServer) {
        $commands = @(
            'configure terminal',
            'no ip nat inside source static tcp 192.168.100.11 80 85.132.57.220 80 extendable',
            'no ip nat inside source static tcp 192.168.100.11 443 85.132.57.220 443 extendable',
            'ip nat inside source static tcp 192.168.100.201 80 85.132.57.220 80 extendable',
            'ip nat inside source static tcp 192.168.100.201 443 85.132.57.220 443 extendable',
            'end', 'write memory',
            'show running-config | include 192.168.100.201.*85.132.57.220'
        )
        $output = Invoke-Router $commands
        if (($output | Select-String -Pattern '192\.168\.100\.201 80 85\.132\.57\.220 80').Count -eq 0 -or
            ($output | Select-String -Pattern '192\.168\.100\.201 443 85\.132\.57\.220 443').Count -eq 0) {
            throw 'New billing NAT rules were not confirmed.'
        }
    } else {
        $commands = @(
            'configure terminal',
            'no ip nat inside source static tcp 192.168.100.201 80 85.132.57.220 80 extendable',
            'no ip nat inside source static tcp 192.168.100.201 443 85.132.57.220 443 extendable',
            'ip nat inside source static tcp 192.168.100.11 80 85.132.57.220 80 extendable',
            'ip nat inside source static tcp 192.168.100.11 443 85.132.57.220 443 extendable',
            'end', 'write memory'
        )
        [void](Invoke-Router $commands)
    }
}

function Set-RouterRadius([bool]$UseNewServer) {
    $target = if ($UseNewServer) { '10.99.99.12' } else { '10.99.99.10' }
    $output = Invoke-Router @(
        'configure terminal',
        'radius server RADIUS-INTERNAL',
        "address ipv4 $target auth-port 1812 acct-port 1813",
        'end', 'write memory',
        "show running-config | include address ipv4 $target",
        'show aaa servers | include State'
    )
    if (($output | Select-String -SimpleMatch "address ipv4 $target auth-port 1812 acct-port 1813").Count -eq 0) {
        throw "RADIUS target $target was not confirmed."
    }
}

function Test-PaymentEndpoint([bool]$ExpectPaused = $false) {
    $temporary = New-TemporaryFile
    try {
        $code = & curl.exe -sS --max-time 15 -o $temporary.FullName -w '%{http_code}' 'https://billing.superoptic.net/payments/index.php'
        if ($LASTEXITCODE -ne 0) { throw 'External billing TLS/HTTP probe failed.' }
        if ($ExpectPaused) {
            if ($code -ne '200' -and $code -ne '503') { throw "Unexpected paused endpoint HTTP status: $code" }
        } elseif ($code -ne '200') {
            throw "Unexpected billing endpoint HTTP status: $code"
        }
        if (-not $ExpectPaused) {
            $json = Get-Content -Raw -LiteralPath $temporary.FullName | ConvertFrom-Json
            if ([string]$json.status -ne '408') { throw 'Legacy unauthenticated contract response changed.' }
        }
    } finally {
        Remove-Item -LiteralPath $temporary.FullName -Force -ErrorAction SilentlyContinue
    }
}

$script:Plink = (Get-Command plink.exe -ErrorAction Stop).Source

if (-not $Apply -and -not $Rollback) {
    [ordered]@{
        mode = 'plan'
        production_changes = $false
        steps = @(
            'strict readiness and provider configuration checks',
            'database backup and final payment delta reconciliation',
            'pause payment writes on the new server',
            'move billing.superoptic.net ports 80/443 to the new MGT server',
            'final direct sync from the old internal billing VM',
            'move router RADIUS to 10.99.99.12',
            'run expiry, RADIUS, payment outbox, SMS queue workers',
            'resume payment writes and verify the public legacy contract',
            'shut down old VM 100 only after all checks pass'
        )
        rollback = 'Before payment ingress resumes, NAT and RADIUS are automatically restored on failure.'
    } | ConvertTo-Json -Depth 5
    exit 0
}

if ($Rollback) {
    [void](Invoke-MgtHelper '--pause-ingress')
    [void](Invoke-MgtHelper '--disable-delivery')
    Set-RouterRadius $false
    Set-RouterNat $false
    [void](Invoke-Pve 'qm start 100 >/dev/null 2>&1 || true; qm status 100')
    [void](Invoke-MgtHelper '--resume-ingress')
    Write-Output 'ROLLBACK_COMPLETED'
    exit 0
}

$natMoved = $false
$radiusMoved = $false
$natMoveAttempted = $false
$radiusMoveAttempted = $false
$ingressResumed = $false
$deliveryEnabled = $false
try {
    Write-Output (Invoke-MgtHelper '--preflight')
    $preSync = Invoke-MgtHelper '--presync'
    Write-Output $preSync
    if ($preSync -notmatch 'PAYMENT_RECONCILED old=(\d+) new=(\d+)') { throw 'Payment pre-sync evidence missing.' }
    $ExpectedPayments = [int]$Matches[1]

    [void](Invoke-MgtHelper '--pause-ingress' $ExpectedPayments)
    $natMoveAttempted = $true
    Set-RouterNat $true
    $natMoved = $true
    Test-PaymentEndpoint $true

    $finalSync = Invoke-MgtHelper '--final-sync' $ExpectedPayments
    Write-Output $finalSync
    if ($finalSync -notmatch 'PAYMENT_RECONCILED old=(\d+) new=(\d+)') { throw 'Final payment sync evidence missing.' }
    $ExpectedPayments = [int]$Matches[1]

    $radiusMoveAttempted = $true
    Set-RouterRadius $true
    $radiusMoved = $true
    Start-Sleep -Seconds 3
    [void](Invoke-MgtHelper '--enable-delivery' $ExpectedPayments)
    $deliveryEnabled = $true
    Write-Output (Invoke-MgtHelper '--activate' $ExpectedPayments)
    Write-Output (Invoke-MgtHelper '--postcheck' $ExpectedPayments)

    [void](Invoke-MgtHelper '--resume-ingress' $ExpectedPayments)
    $ingressResumed = $true
    Test-PaymentEndpoint $false

    $vmState = Invoke-Pve 'qm shutdown 100 --timeout 60 >/dev/null 2>&1 || qm stop 100; qm status 100'
    if ($vmState -notmatch 'status: stopped') { throw 'Old billing VM did not stop.' }

    $stateDir = Join-Path (Split-Path $PSScriptRoot -Parent | Split-Path -Parent) 'data\private'
    New-Item -ItemType Directory -Path $stateDir -Force | Out-Null
    $statePath = Join-Path $stateDir ('legacy-cutover-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.json')
    [ordered]@{
        status = 'completed'
        completed_at = (Get-Date).ToString('o')
        expected_customers = $ExpectedCustomers
        reconciled_payments = $ExpectedPayments
        billing_public_ip = '85.132.57.220'
        radius_target = '10.99.99.12'
        old_vm = 'stopped'
        secrets_exposed = $false
    } | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8
    Write-Output "CUTOVER_COMPLETED state=$statePath"
} catch {
    if (-not $ingressResumed) {
        if ($deliveryEnabled) { try { [void](Invoke-MgtHelper '--disable-delivery' $ExpectedPayments) } catch {} }
        if ($radiusMoved -or $radiusMoveAttempted) { try { Set-RouterRadius $false } catch {} }
        if ($natMoved -or $natMoveAttempted) { try { Set-RouterNat $false } catch {} }
        try { [void](Invoke-MgtHelper '--resume-ingress' $ExpectedPayments) } catch {}
    }
    throw
}
