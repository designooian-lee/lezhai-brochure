param(
    [string]$ToolchainRoot = 'C:\Users\Lee\Documents\Codex\2026-07-10\php-8-3-postgresql',
    [switch]$NoBrowser,
    [switch]$SkipSeed
)
$ErrorActionPreference = 'Stop'
$OutputEncoding = [Console]::OutputEncoding = [Text.UTF8Encoding]::new()
# Some managed shells expose both Path and PATH. Normalize them before Start-Process.
$processPath = [Environment]::GetEnvironmentVariable('Path','Process')
[Environment]::SetEnvironmentVariable('PATH',$null,'Process')
[Environment]::SetEnvironmentVariable('Path',$processPath,'Process')
$project = Split-Path -Parent $PSScriptRoot
$runtime = Join-Path $project 'storage\runtime'
$logs = Join-Path $project 'storage\logs'
New-Item -ItemType Directory -Force -Path $runtime,$logs | Out-Null

try {
    $phpRoot = Join-Path $ToolchainRoot 'tools\php'
    $pgBin = Join-Path $ToolchainRoot 'tools\postgresql\pgsql\bin'
    $php = Join-Path $phpRoot 'php.exe'
    if (-not (Test-Path $php)) { throw "找不到 PHP 工具链：$php" }
    if (-not (Test-Path (Join-Path $pgBin 'psql.exe'))) { throw "找不到 PostgreSQL 工具链：$pgBin" }

    $template = Get-Content -Raw -Encoding UTF8 (Join-Path $project 'config\php.ini.template')
    $ini = Join-Path $runtime 'php.ini'
    $ext = (Join-Path $phpRoot 'ext').Replace([char]92,[char]47)
    $errorLog = (Join-Path $logs 'php-error.log').Replace([char]92,[char]47)
    $caFile = (Join-Path $project 'config\cacert.pem').Replace([char]92,[char]47)
    $template.Replace('{{EXT_DIR}}',$ext).Replace('{{ERROR_LOG}}',$errorLog).Replace('{{CA_FILE}}',$caFile) | Set-Content -Encoding UTF8 $ini

    $nodeRoot = 'C:\Users\Lee\.cache\codex-runtimes\codex-primary-runtime\dependencies\node'
    $node = Join-Path $nodeRoot 'bin\node.exe'
    $nodeModules = Join-Path $nodeRoot 'node_modules'
    if (Test-Path $node) {
        $env:NODE_PATH = "$nodeModules;$nodeModules\.pnpm\node_modules"
    } else {
        $node = 'node'
    }
    $edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
    if (-not (Test-Path $edge)) { $edge = '' }

    $ready = & (Join-Path $pgBin 'pg_isready.exe') -h 127.0.0.1 -p 5432 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Host '正在初始化并启动 PostgreSQL...'
        & (Join-Path $ToolchainRoot 'start-postgres.ps1')
    }
    $passwordFile = Join-Path $ToolchainRoot 'data\postgres-password.txt'
    if (-not (Test-Path $passwordFile)) { throw 'PostgreSQL 密码文件不存在。' }
    $dbPassword = Get-Content -Raw $passwordFile
    $env:PGPASSWORD = $dbPassword

    $envFile = Join-Path $project '.env'
    $credentialFile = Join-Path $runtime 'local-login.txt'
    if (-not (Test-Path $envFile)) {
        $adminPassword = -join ((48..57)+(65..90)+(97..122) | Get-Random -Count 16 | ForEach-Object {[char]$_})
        $hash = & $php -c $ini -r "echo password_hash('$adminPassword', PASSWORD_DEFAULT);"
        $secret = -join ((48..57)+(97..102) | Get-Random -Count 48 | ForEach-Object {[char]$_})
        @(
            'APP_ENV=local'
            'APP_BASE_PATH=/brochure'
            "APP_SECRET=$secret"
            'DB_DSN=pgsql:host=127.0.0.1;port=5432;dbname=lezhai_brochure'
            'DB_USER=postgres'
            "DB_PASSWORD=$dbPassword"
            'ADMIN_USERNAME=admin'
            "ADMIN_PASSWORD_HASH=$hash"
            "NODE_BINARY=$($node.Replace([char]92,[char]47))"
            "BROWSER_EXECUTABLE=$($edge.Replace([char]92,[char]47))"
        ) | Set-Content -Encoding UTF8 $envFile
        @("后台地址：http://127.0.0.1:8080/brochure/admin/login",'管理员：admin',"密码：$adminPassword") | Set-Content -Encoding UTF8 $credentialFile
    }

    $exists = & (Join-Path $pgBin 'psql.exe') -h 127.0.0.1 -U postgres -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='lezhai_brochure'"
    if ($exists -ne '1') {
        & (Join-Path $pgBin 'createdb.exe') -h 127.0.0.1 -U postgres -E UTF8 lezhai_brochure
        if ($LASTEXITCODE -ne 0) { throw '创建本地数据库失败。' }
    }
    & $php -c $ini (Join-Path $project 'scripts\migrate.php')
    if ($LASTEXITCODE -ne 0) { throw '数据库迁移失败。' }
    if (-not $SkipSeed) {
        Write-Host '正在核对初始图册，单次最多等待 4 分钟...'
        $seedOut = Join-Path $logs 'seed-output.log'
        $seedErr = Join-Path $logs 'seed-error.log'
        $seedProcess = Start-Process -FilePath $php -ArgumentList @('-c',$ini,(Join-Path $project 'scripts\seed.php')) -WorkingDirectory $project -RedirectStandardOutput $seedOut -RedirectStandardError $seedErr -WindowStyle Hidden -PassThru
        if (-not $seedProcess.WaitForExit(240000)) {
            Stop-Process -Id $seedProcess.Id -Force -ErrorAction SilentlyContinue
            Write-Warning '初始图册解析超过 4 分钟，已停止本轮导入。平台将使用已成功导入的数据，缺失图册可在后台重新添加。'
        } else {
            $seedProcess.WaitForExit()
            $seedProcess.Refresh()
            $seedCompleted = (Test-Path $seedOut) -and ((Get-Content -Raw -Encoding UTF8 $seedOut) -match '初始图册导入完成。')
            $seedHasErrors = (Test-Path $seedErr) -and ((Get-Item $seedErr).Length -gt 0)
            if ($seedHasErrors -or -not $seedCompleted) {
                Write-Warning "部分初始图册未导入，请查看：$seedErr"
            }
        }
    }

    $pidFile = Join-Path $runtime 'server.pid'
    if (Test-Path $pidFile) {
        $oldPid = [int](Get-Content -Raw $pidFile)
        $oldProcess = Get-Process -Id $oldPid -ErrorAction SilentlyContinue
        if ($oldProcess -and $oldProcess.ProcessName -like 'php*') {
            Write-Host "平台已经运行，进程号：$oldPid"
        } else { Remove-Item $pidFile -Force }
    }
    if (-not (Test-Path $pidFile)) {
        $outLog = Join-Path $logs 'server-output.log'
        $errLog = Join-Path $logs 'server-error.log'
        $process = Start-Process -FilePath $php -ArgumentList @('-c',$ini,'-S','127.0.0.1:8080',(Join-Path $project 'public\router.php')) -WorkingDirectory $project -RedirectStandardOutput $outLog -RedirectStandardError $errLog -WindowStyle Hidden -PassThru
        $process.Id | Set-Content -Encoding ASCII $pidFile
    }

    $health = 'http://127.0.0.1:8080/brochure/health'
    $healthy = $false
    for ($i=0; $i -lt 30; $i++) {
        try { $response = Invoke-RestMethod -Uri $health -TimeoutSec 2; if ($response.status -eq 'ok') { $healthy=$true; break } } catch {}
        Start-Sleep -Milliseconds 500
    }
    if (-not $healthy) { throw "平台未通过健康检查，请查看：$logs" }
    Write-Host ''
    Write-Host '乐宅.Life 图册选款平台已启动。' -ForegroundColor Green
    Write-Host '前台：http://127.0.0.1:8080/brochure/'
    Write-Host '后台：http://127.0.0.1:8080/brochure/admin/login'
    Write-Host "登录凭据：$credentialFile"
    if (-not $NoBrowser) { Start-Process 'http://127.0.0.1:8080/brochure/' }
} catch {
    Write-Host ''
    Write-Host "启动失败：$($_.Exception.Message)" -ForegroundColor Red
    Write-Host $_.ScriptStackTrace
    Write-Host "日志目录：$logs"
    exit 1
}
