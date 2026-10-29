#requires -Version 5.1
<#
  MOFU 本地预览启动器
  --------------------
  1. 使用项目内 .runtime/php-8.2.35（PHP2Shell 修复版），缺失时自动下载官方 Windows 包。
  2. 如 127.0.0.1 的数据库端口没有服务，使用 Docker MariaDB 启动本地数据库。
  3. 执行数据库迁移，可选写入演示数据。
  4. 启动 PHP 内置服务器，默认 http://127.0.0.1:8000 。

  用法：
    powershell -ExecutionPolicy Bypass -File .\bin\preview.ps1
    powershell -ExecutionPolicy Bypass -File .\bin\preview.ps1 -Seed
    powershell -ExecutionPolicy Bypass -File .\bin\preview.ps1 -Port 8080
    powershell -ExecutionPolicy Bypass -File .\bin\preview.ps1 -DownloadOnly
#>
[CmdletBinding()]
param(
    [int]$Port = 8000,
    [switch]$Seed,
    [switch]$DownloadOnly,
    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch {}
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root

$phpVersion = '8.2.35'
$runtimeDir = Join-Path $root ".runtime\php-$phpVersion"
$phpExe = Join-Path $runtimeDir 'php.exe'
$iniPath = Join-Path $runtimeDir 'php-preview.ini'
$zipPath = Join-Path $root ".runtime\php-$phpVersion.zip"

function Write-Step([string]$text) {
    Write-Host ""
    Write-Host "==> $text" -ForegroundColor Cyan
}

function Test-TcpPort([string]$hostName, [int]$port) {
    $client = [System.Net.Sockets.TcpClient]::new()

    try {
        $task = $client.ConnectAsync($hostName, $port)
        return $task.Wait(1200) -and $client.Connected
    } catch {
        return $false
    } finally {
        $client.Dispose()
    }
}

function Read-DotEnv([string]$path) {
    $map = @{}

    if (-not (Test-Path $path)) {
        return $map
    }

    foreach ($line in Get-Content $path) {
        if ($line -match '^\s*#') { continue }
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$') {
            $value = $Matches[2].Trim()
            if ($value.Length -ge 2 -and (
                ($value.StartsWith('"') -and $value.EndsWith('"')) -or
                ($value.StartsWith("'") -and $value.EndsWith("'"))
            )) {
                $value = $value.Substring(1, $value.Length - 2)
            }
            $map[$Matches[1]] = $value
        }
    }

    return $map
}

function Set-DotEnvValue([string]$path, [string]$key, [string]$value) {
    $text = if (Test-Path $path) { Get-Content $path -Raw } else { '' }

    if ($text -match "(?m)^$([regex]::Escape($key))=.*$") {
        $text = [regex]::Replace($text, "(?m)^$([regex]::Escape($key))=.*$", "$key=$value")
    } else {
        $text = $text.TrimEnd() + "`r`n$key=$value`r`n"
    }

    Set-Content -Path $path -Value $text -Encoding utf8
}

function Ensure-PhpRuntime {
    if (-not (Test-Path $phpExe)) {
        Write-Step "下载官方 PHP $phpVersion（PHP2Shell 修复版）"
        New-Item -ItemType Directory -Force (Split-Path $zipPath) | Out-Null

        if (-not (Test-Path $zipPath)) {
            Invoke-WebRequest `
                -Uri "https://windows.php.net/downloads/releases/php-$phpVersion-nts-Win32-vs16-x64.zip" `
                -OutFile $zipPath `
                -UseBasicParsing
        }

        $extractDir = Join-Path $root '.runtime\php-download'
        if (Test-Path $extractDir) { Remove-Item $extractDir -Recurse -Force }
        Expand-Archive -Path $zipPath -DestinationPath $extractDir -Force
        New-Item -ItemType Directory -Force $runtimeDir | Out-Null
        Copy-Item (Join-Path $extractDir '*') $runtimeDir -Recurse -Force
        Remove-Item $extractDir -Recurse -Force
    }

    if (-not (Test-Path $iniPath)) {
        Copy-Item (Join-Path $runtimeDir 'php.ini-development') $iniPath -Force
        $ini = Get-Content $iniPath -Raw

        $ini = $ini.Replace(';extension_dir = "ext"', 'extension_dir = "ext"')
        foreach ($extension in @('curl', 'fileinfo', 'gd', 'mbstring', 'openssl', 'pdo_mysql', 'sodium', 'zip')) {
            $ini = $ini.Replace(";extension=$extension", "extension=$extension")
        }

        $ini = [regex]::Replace($ini, '(?m)^;?date\.timezone\s*=.*$', 'date.timezone = UTC')
        $ini = [regex]::Replace($ini, '(?m)^;?display_errors\s*=.*$', 'display_errors = On')
        $ini = [regex]::Replace($ini, '(?m)^;?error_reporting\s*=.*$', 'error_reporting = E_ALL')
        $ini = [regex]::Replace($ini, '(?m)^;?expose_php\s*=.*$', 'expose_php = Off')
        $ini = [regex]::Replace($ini, '(?m)^;?session\.upload_progress\.enabled\s*=.*$', 'session.upload_progress.enabled = 0')
        $ini = [regex]::Replace($ini, '(?m)^;?upload_max_filesize\s*=.*$', 'upload_max_filesize = 64M')
        $ini = [regex]::Replace($ini, '(?m)^;?post_max_size\s*=.*$', 'post_max_size = 68M')

        if ($ini -notmatch '(?m)^memory_limit\s*=') {
            $ini += "`r`nmemory_limit = 512M`r`n"
        }

        Set-Content -Path $iniPath -Value $ini -Encoding utf8
    }

    # Built-in server is a CLI SAPI. Enable OPcache for every preview so PHP
    # files are compiled once instead of on every request.
    $ini = Get-Content $iniPath -Raw
    $ini = [regex]::Replace($ini, '(?m)^;?\s*zend_extension\s*=\s*opcache\s*$', 'zend_extension=opcache')
    $ini = [regex]::Replace($ini, '(?m)^;?\s*opcache\.enable\s*=.*$', 'opcache.enable=1')
    $ini = [regex]::Replace($ini, '(?m)^;?\s*opcache\.enable_cli\s*=.*$', 'opcache.enable_cli=1')
    $ini = [regex]::Replace($ini, '(?m)^;?\s*opcache\.validate_timestamps\s*=.*$', 'opcache.validate_timestamps=1')
    Set-Content -Path $iniPath -Value $ini -Encoding utf8
}

Ensure-PhpRuntime

$phpVersionId = [int](& $phpExe -c $iniPath -r 'echo PHP_VERSION_ID;')
if ($phpVersionId -lt 80235) {
    throw "PHP runtime is $phpVersionId, expected 80235 or patched branch. Delete .runtime and rerun."
}

$modules = & $phpExe -c $iniPath -m
foreach ($required in @('pdo_mysql', 'gd', 'fileinfo', 'mbstring', 'openssl')) {
    if ($modules -notcontains $required) {
        throw "PHP extension not loaded: $required. Delete .runtime/php-$phpVersion and rerun."
    }
}

Write-Step "PHP $phpVersion 运行时已就绪"

if ($DownloadOnly) {
    Write-Host "PHP runtime: $phpExe"
    exit 0
}

$envFile = Join-Path $root '.env'
if (-not (Test-Path $envFile)) {
    Copy-Item (Join-Path $root '.env.example') $envFile
    Write-Host "已从 .env.example 创建 .env"
}

$envMap = Read-DotEnv $envFile

if ([string]::IsNullOrWhiteSpace($envMap['APP_KEY'])) {
    $generatedKey = (& $phpExe -c $iniPath -r 'echo bin2hex(random_bytes(32));').Trim()
    Set-DotEnvValue $envFile 'APP_KEY' $generatedKey
    $envMap['APP_KEY'] = $generatedKey
    Write-Host "已生成随机 APP_KEY"
}

$dbHost = if ($envMap['DB_HOST']) { $envMap['DB_HOST'] } else { '127.0.0.1' }
$dbPort = if ($envMap['DB_PORT']) { [int]$envMap['DB_PORT'] } else { 3306 }
$dbName = if ($envMap['DB_DATABASE']) { $envMap['DB_DATABASE'] } else { 'mofu' }
$dbUser = if ($envMap['DB_USERNAME']) { $envMap['DB_USERNAME'] } else { 'mofu' }
$dbPass = $envMap['DB_PASSWORD']

if ([string]::IsNullOrEmpty($dbPass)) {
    $dbPass = 'mofu_preview_password'
    Set-DotEnvValue $envFile 'DB_PASSWORD' $dbPass
}

$isLoopback = $dbHost -in @('127.0.0.1', 'localhost', '::1')
$dbReachable = Test-TcpPort '127.0.0.1' $dbPort

if (-not $dbReachable -and $isLoopback) {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw "数据库端口 $dbPort 没有服务，且未找到 Docker。请先启动本地 MySQL/MariaDB，或安装 Docker Desktop。"
    }

    Write-Step "启动本地 MariaDB 预览数据库"

    $container = 'mofu-preview-db'
    $existing = (& docker ps -a --filter "name=^/${container}$" --format '{{.Names}}' | Out-String).Trim()

    if ($existing -eq $container) {
        & docker start $container | Out-Null
    } else {
        $rootPass = 'mofu_preview_root_' + [Guid]::NewGuid().ToString('N').Substring(0, 16)

        & docker run -d `
            --name $container `
            -p "${dbPort}:3306" `
            -v 'mofu-preview-db-data:/var/lib/mysql' `
            -e "MARIADB_DATABASE=$dbName" `
            -e "MARIADB_USER=$dbUser" `
            -e "MARIADB_PASSWORD=$dbPass" `
            -e "MARIADB_ROOT_PASSWORD=$rootPass" `
            'public.ecr.aws/docker/library/mariadb:11' | Out-Null
    }

    for ($i = 0; $i -lt 45; $i++) {
        if (Test-TcpPort '127.0.0.1' $dbPort) { break }
        Start-Sleep -Seconds 1
    }

    if (-not (Test-TcpPort '127.0.0.1' $dbPort)) {
        throw "MariaDB 未在 45 秒内启动。运行 docker logs mofu-preview-db 查看原因。"
    }

    # TCP 端口打开不代表数据库已完成初始化，继续等待接受查询。
    $dbReady = $false
    for ($i = 0; $i -lt 45; $i++) {
        & docker exec $container mariadb-admin --host=127.0.0.1 -u$dbUser -p$dbPass ping --silent 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $dbReady = $true; break }
        Start-Sleep -Seconds 1
    }

    if (-not $dbReady) {
        throw "MariaDB 已启动但尚未接受查询。运行 docker logs mofu-preview-db 查看原因。"
    }
} elseif (-not $dbReachable) {
    throw "数据库 ${dbHost}:${dbPort} 不可达，请检查 .env 与网络。"
}

# 如果端口已经打开但容器仍在初始化，继续等到真正能执行查询。
if ($isLoopback) {
    $previewContainer = 'mofu-preview-db'
    $exists = (& docker ps -a --filter "name=^/${previewContainer}$" --format '{{.Names}}' | Out-String).Trim()

    if ($exists -eq $previewContainer) {
        $dbReady = $false
        for ($i = 0; $i -lt 45; $i++) {
            & docker exec $previewContainer mariadb-admin --host=127.0.0.1 -u$dbUser -p$dbPass ping --silent 2>$null | Out-Null
            if ($LASTEXITCODE -eq 0) { $dbReady = $true; break }
            Start-Sleep -Seconds 1
        }

        if (-not $dbReady) {
            throw "MariaDB 已启动但尚未接受查询。运行 docker logs mofu-preview-db 查看原因。"
        }
    }
}

Write-Step "执行数据库迁移"
& $phpExe -c $iniPath 'bin/migrate.php' 'migrate'
if ($LASTEXITCODE -ne 0) {
    throw "数据库迁移失败。如果本地已有不兼容的旧库，请检查 .env 中的数据库配置。"
}

if ($Seed) {
    Write-Step "写入本地演示数据（仅本地环境）"
    & $phpExe -c $iniPath 'bin/seed-preview.php'
    if ($LASTEXITCODE -ne 0) {
        throw "演示数据写入失败。"
    }
}

if (Test-TcpPort '127.0.0.1' $Port) {
    throw "端口 $Port 已被占用。请换一个端口，例如：-Port 8080"
}

$url = "http://127.0.0.1:$Port"

Write-Host ""
Write-Host "MOFU 本地预览已启动" -ForegroundColor Green
Write-Host "地址: $url"
Write-Host "PHP:  $phpVersion (PHP2Shell patched)"
Write-Host "数据库: $dbHost`:$dbPort/$dbName"
Write-Host "按 Ctrl+C 停止 PHP 服务器；数据库容器会在后台保留。"
Write-Host ""

if (-not $NoBrowser) {
    Start-Job -ScriptBlock {
        param($target)
        Start-Sleep -Seconds 2
        Start-Process $target
    } -ArgumentList $url | Out-Null
}

$nodeCommand = Get-Command node -ErrorAction SilentlyContinue
$nodeExe = if ($null -ne $nodeCommand) { $nodeCommand.Source } else { $null }

if ($nodeExe) {
    & $nodeExe (Join-Path $root 'scripts\dev-server.mjs') `
        --php $phpExe `
        --ini $iniPath `
        --root $root `
        --port $Port `
        --workers 8
} else {
    Write-Warning "未找到 Node.js，退回单进程 PHP 服务器；SSE 可能阻塞其他页面请求。"
    & $phpExe -c $iniPath -S "127.0.0.1:$Port" -t public public/router.php
}

