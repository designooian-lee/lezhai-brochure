param(
    [Parameter(Mandatory = $true)]
    [string]$PostgresExecutable,
    [Parameter(Mandatory = $true)]
    [string]$PhpExecutable
)

$ErrorActionPreference = 'Stop'
$ruleName = 'Lezhai Brochure PostgreSQL Localhost'

if (-not (Test-Path -LiteralPath $PostgresExecutable)) {
    throw "找不到 PostgreSQL：$PostgresExecutable"
}
if (-not (Test-Path -LiteralPath $PhpExecutable)) {
    throw "找不到 PHP：$PhpExecutable"
}

Remove-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
New-NetFirewallRule `
    -DisplayName $ruleName `
    -Direction Inbound `
    -Action Allow `
    -Program $PostgresExecutable `
    -Protocol TCP `
    -LocalAddress 127.0.0.1 `
    -LocalPort 55432 `
    -RemoteAddress 127.0.0.1 `
    -Profile Any | Out-Null

$phpRuleName = 'Lezhai Brochure PHP Localhost'
Remove-NetFirewallRule -DisplayName $phpRuleName -ErrorAction SilentlyContinue
New-NetFirewallRule `
    -DisplayName $phpRuleName `
    -Direction Inbound `
    -Action Allow `
    -Program $PhpExecutable `
    -Protocol TCP `
    -LocalAddress 127.0.0.1 `
    -LocalPort 8080 `
    -RemoteAddress 127.0.0.1 `
    -Profile Any | Out-Null
