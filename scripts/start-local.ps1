param(
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
    $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
        Where-Object FullName -match 'PHP\.PHP\.8\.3' |
        Select-Object -First 1 -ExpandProperty FullName
    if (-not $php) {
        $php = Get-ChildItem "$env:LOCALAPPDATA\Programs\PHP\8.3*" -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
            Select-Object -First 1 -ExpandProperty FullName
    }
    if (-not $php -or -not (Test-Path $php)) { throw '找不到 PHP 8.3，请先安装 PHP 并重新运行。' }
    $phpRoot = Split-Path -Parent $php
    $pgBin = 'D:\Program Files\PostgreSQL\18\bin'
    if (-not (Test-Path (Join-Path $pgBin 'psql.exe'))) { throw "找不到 PostgreSQL：$pgBin" }

    $postgres = Join-Path $pgBin 'postgres.exe'
    $databaseFirewallRule = Get-NetFirewallRule -DisplayName 'Lezhai Brochure PostgreSQL Localhost' -ErrorAction SilentlyContinue
    $webFirewallRule = Get-NetFirewallRule -DisplayName 'Lezhai Brochure PHP Localhost' -ErrorAction SilentlyContinue
    if (-not $databaseFirewallRule -or -not $webFirewallRule) {
        Write-Host '首次启动需要允许数据库和网页服务的本机通信，请在弹出的窗口中选择“是”...'
        $firewallHelper = Join-Path $PSScriptRoot 'allow-postgres-localhost.ps1'
        $elevated = Start-Process powershell.exe -Verb RunAs -Wait -PassThru -ArgumentList @(
            '-NoProfile',
            '-ExecutionPolicy', 'Bypass',
            '-File', ('"' + $firewallHelper + '"'),
            '-PostgresExecutable', ('"' + $postgres + '"'),
            '-PhpExecutable', ('"' + $php + '"')
        )
        if ($elevated.ExitCode -ne 0) { throw '未能添加 PostgreSQL 本机通信规则，请重新启动并在权限提示中选择“是”。' }
    }

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

    $pgData = Join-Path $runtime 'postgres-data'
    $pgPort = 55432
    $passwordFile = Join-Path $runtime 'postgres-password.txt'
    $pgLog = Join-Path $logs 'postgres.log'
    $ready = & (Join-Path $pgBin 'pg_isready.exe') -h 127.0.0.1 -p $pgPort -t 1 2>$null
    $databaseReady = $LASTEXITCODE -eq 0
    $databaseProcessRunning = $false
    if (Test-Path $pgData) {
        & (Join-Path $pgBin 'pg_ctl.exe') -D $pgData status *> $null
        $databaseProcessRunning = $LASTEXITCODE -eq 0
    }
    $postmasterPid = Join-Path $pgData 'postmaster.pid'
    if (-not $databaseProcessRunning -and (Test-Path $postmasterPid)) {
        $recordedPid = [int]((Get-Content -LiteralPath $postmasterPid -TotalCount 1).Trim())
        $recordedProcess = Get-Process -Id $recordedPid -ErrorAction SilentlyContinue
        if (-not $recordedProcess -or $recordedProcess.ProcessName -notlike 'postgres*') {
            Write-Host '正在清理上次异常退出留下的数据库锁文件...'
            Remove-Item -LiteralPath $postmasterPid -Force
        }
    }
    if ($databaseProcessRunning -and -not $databaseReady) {
        Write-Host '检测到旧端口或异常的 PostgreSQL 进程，正在安全重启...'
        & (Join-Path $pgBin 'pg_ctl.exe') -D $pgData -m fast -w -t 20 stop
        if ($LASTEXITCODE -ne 0) { throw '无法停止旧的 PostgreSQL 进程，请重新启动电脑后再试。' }
        $databaseProcessRunning = $false
    }
    if (-not $databaseProcessRunning) {
        Write-Host '正在初始化并启动 PostgreSQL...'
        if (-not (Test-Path $pgData)) {
            New-Item -ItemType Directory -Force -Path (Split-Path -Parent $pgData) | Out-Null
            if (-not (Test-Path $passwordFile)) {
                Set-Content -LiteralPath $passwordFile -Value 'admin' -NoNewline -Encoding ASCII
            }
            & (Join-Path $pgBin 'initdb.exe') -D $pgData -U postgres -A scram-sha-256 --pwfile=$passwordFile -E UTF8 --locale=C
            if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL 初始化失败。' }
        }
        & (Join-Path $pgBin 'pg_ctl.exe') -D $pgData -l $pgLog -o "-p $pgPort" -w -t 30 start
        if ($LASTEXITCODE -ne 0) {
            $detail = if (Test-Path $pgLog) { (Get-Content -Tail 12 -Encoding UTF8 $pgLog) -join "`n" } else { '没有生成数据库日志。' }
            throw "PostgreSQL 启动失败。`n$detail"
        }
        $databaseProcessRunning = $true
    } elseif (-not $databaseReady) {
        Write-Host 'PostgreSQL 进程已存在，将直接执行数据库连接检查...'
    }
    if (-not (Test-Path $passwordFile)) { throw 'PostgreSQL 密码文件不存在。' }
    $dbPassword = Get-Content -Raw $passwordFile
    $env:PGPASSWORD = $dbPassword
    $env:PGCONNECT_TIMEOUT = '5'
    $env:PGHOST = '127.0.0.1'
    $env:PGPORT = [string]$pgPort
    $env:DB_DSN = "pgsql:host=127.0.0.1;port=$pgPort;dbname=lezhai_brochure"

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
            "DB_DSN=pgsql:host=127.0.0.1;port=$pgPort;dbname=lezhai_brochure"
            'DB_USER=postgres'
            "DB_PASSWORD=$dbPassword"
            'ADMIN_USERNAME=admin'
            "ADMIN_PASSWORD_HASH=$hash"
            "NODE_BINARY=$($node.Replace([char]92,[char]47))"
            "BROWSER_EXECUTABLE=$($edge.Replace([char]92,[char]47))"
        ) | Set-Content -Encoding UTF8 $envFile
        @("后台地址：http://127.0.0.1:8080/admin/login",'管理员：admin',"密码：$adminPassword") | Set-Content -Encoding UTF8 $credentialFile
    }

    $websiteIndex = Join-Path $project 'storage\website-dist\index.html'
    if (-not (Test-Path $websiteIndex)) {
        $pnpm = Get-Command pnpm.cmd -ErrorAction SilentlyContinue
        if (-not $pnpm) {
            $bundledPnpm = 'C:\Users\Lee\.cache\codex-runtimes\codex-primary-runtime\dependencies\bin\fallback\pnpm.cmd'
            if (Test-Path $bundledPnpm) { $pnpm = Get-Item $bundledPnpm }
        }
        if (-not $pnpm) { throw '找不到 pnpm，请安装 Node.js 22 并启用 Corepack 后重新运行。' }
        Write-Host '正在安装官网构建依赖并生成静态页面...'
        & $pnpm.FullName install --frozen-lockfile
        if ($LASTEXITCODE -ne 0) { throw 'pnpm 依赖安装失败。' }
        & $pnpm.FullName run build:website
        if ($LASTEXITCODE -ne 0) { throw '官网静态页面构建失败。' }
    }

    Write-Host '正在检查项目数据库...'
    $dbCheckOut = Join-Path $logs 'database-check-output.log'
    $dbCheckErr = Join-Path $logs 'database-check-error.log'
    $dbHostFile = Join-Path $runtime 'database-host.txt'
    Remove-Item -LiteralPath $dbHostFile -Force -ErrorAction SilentlyContinue
    $dbCheck = Start-Process -FilePath $php -ArgumentList @('-c',$ini,(Join-Path $project 'scripts\ensure-database.php'),$dbHostFile) -WorkingDirectory $project -RedirectStandardOutput $dbCheckOut -RedirectStandardError $dbCheckErr -WindowStyle Hidden -PassThru
    if (-not $dbCheck.WaitForExit(15000)) {
        Stop-Process -Id $dbCheck.Id -Force -ErrorAction SilentlyContinue
        throw "数据库检查超过 15 秒，已停止。请查看：$dbCheckErr"
    }
    $dbCheck.WaitForExit()
    if (-not (Test-Path $dbHostFile)) {
        $detail = if ((Test-Path $dbCheckErr) -and (Get-Item $dbCheckErr).Length -gt 0) { (Get-Content -Raw -Encoding UTF8 $dbCheckErr).Trim() } else { '数据库检查未返回连接地址。' }
        throw "数据库检查失败：$detail"
    }
    $dbHost = (Get-Content -Raw -Encoding UTF8 $dbHostFile).Trim()
    if ($dbHost -notin @('127.0.0.1','::1','localhost')) { throw '数据库检查未返回有效地址。' }
    $env:PGHOST = $dbHost
    $env:DB_DSN = "pgsql:host=$dbHost;port=$pgPort;dbname=lezhai_brochure"
    Write-Host "数据库连接成功：$dbHost`:$pgPort"

    Write-Host '正在执行数据库迁移...'
    $migrateOut = Join-Path $logs 'migrate-output.log'
    $migrateErr = Join-Path $logs 'migrate-error.log'
    $migrate = Start-Process -FilePath $php -ArgumentList @('-c',$ini,(Join-Path $project 'scripts\migrate.php')) -WorkingDirectory $project -RedirectStandardOutput $migrateOut -RedirectStandardError $migrateErr -WindowStyle Hidden -PassThru
    if (-not $migrate.WaitForExit(30000)) {
        Stop-Process -Id $migrate.Id -Force -ErrorAction SilentlyContinue
        throw "数据库迁移超过 30 秒，已停止。请查看：$migrateErr"
    }
    $migrate.WaitForExit()
    $migrationCompleted = (Test-Path $migrateOut) -and ((Get-Content -Raw -Encoding UTF8 $migrateOut) -match '数据库迁移完成。')
    if (-not $migrationCompleted) {
        $detail = if ((Test-Path $migrateErr) -and (Get-Item $migrateErr).Length -gt 0) { (Get-Content -Raw -Encoding UTF8 $migrateErr).Trim() } else { '迁移脚本没有返回完成标志。' }
        throw "数据库迁移失败：$detail"
    }
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

    $health = 'http://127.0.0.1:8080/health'
    $healthy = $false
    for ($i=0; $i -lt 30; $i++) {
        try { $response = Invoke-RestMethod -Uri $health -TimeoutSec 2; if ($response.status -eq 'ok') { $healthy=$true; break } } catch {}
        Start-Sleep -Milliseconds 500
    }
    if (-not $healthy) { throw "平台未通过健康检查，请查看：$logs" }
    Write-Host ''
    Write-Host '乐宅.Life 图册选款平台已启动。' -ForegroundColor Green
    Write-Host '官网：http://127.0.0.1:8080/'
    Write-Host '官网文章：http://127.0.0.1:8080/articles'
    Write-Host '后台：http://127.0.0.1:8080/admin/login'
    Write-Host '图册：http://127.0.0.1:8080/brochure'
    Write-Host '指纹锁教程：http://127.0.0.1:8080/brochure/tutorials'
    Write-Host '图册文章：http://127.0.0.1:8080/brochure/articles'
    Write-Host '健康检查：http://127.0.0.1:8080/health'
    Write-Host "登录凭据：$credentialFile"
    if (-not $NoBrowser) { Start-Process 'http://127.0.0.1:8080/' }
} catch {
    Write-Host ''
    Write-Host "启动失败：$($_.Exception.Message)" -ForegroundColor Red
    Write-Host $_.ScriptStackTrace
    Write-Host "日志目录：$logs"
    exit 1
}
