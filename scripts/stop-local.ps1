$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$pidFile = Join-Path $project 'storage\runtime\server.pid'
if (-not (Test-Path $pidFile)) { Write-Host '平台当前没有运行。'; exit 0 }
$serverPid = [int](Get-Content -Raw $pidFile)
$process = Get-Process -Id $serverPid -ErrorAction SilentlyContinue
if ($process -and $process.ProcessName -like 'php*') { Stop-Process -Id $serverPid -Force; Write-Host '乐宅.Life 图册平台已停止。' }
Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
