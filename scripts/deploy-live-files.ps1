param(
    [switch] $SkipArchives
)

$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$configPath = Join-Path $root '.env.live-sync'
$deployDir = Join-Path $root '.codex-tmp\deploy-live'

function Read-DotEnv([string] $path) {
    $values = @{}
    if (!(Test-Path $path)) {
        return $values
    }

    foreach ($line in Get-Content $path) {
        $trimmed = $line.Trim()
        if ($trimmed -eq '' -or $trimmed.StartsWith('#') -or !$trimmed.Contains('=')) {
            continue
        }

        $key, $value = $trimmed.Split('=', 2)
        $values[$key.Trim()] = $value.Trim().Trim('"').Trim("'")
    }

    return $values
}

function Require-Value([hashtable] $values, [string] $key) {
    if (!$values.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($values[$key])) {
        throw "Missing $key in .env.live-sync"
    }

    return $values[$key]
}

function Resolve-LocalPhp {
    if (![string]::IsNullOrWhiteSpace($env:LOCAL_PHP) -and (Test-Path $env:LOCAL_PHP)) {
        return (Resolve-Path $env:LOCAL_PHP).Path
    }

    $laragon = 'C:\laragon\bin\php'
    if (Test-Path $laragon) {
        $candidate = Get-ChildItem $laragon -Recurse -Filter 'php.exe' -ErrorAction SilentlyContinue |
            Sort-Object FullName -Descending |
            Select-Object -First 1

        if ($candidate) {
            return $candidate.FullName
        }
    }

    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($command) {
        return $command.Source
    }

    throw 'Unable to find local PHP. Set LOCAL_PHP to a php.exe path.'
}

function Invoke-LocalEncodingAudit {
    $php = Resolve-LocalPhp
    & $php artisan encoding:audit --fail-on-issues

    if ($LASTEXITCODE -ne 0) {
        throw 'Local encoding audit failed. Deploy aborted before uploading files.'
    }
}

function Convert-ToFtpPath([string] $path) {
    return (($path -split '[\\/]+') | ForEach-Object { [uri]::EscapeDataString($_) }) -join '/'
}

function Send-FtpFile([string] $localPath, [string] $remotePath, [hashtable] $sync) {
    $remoteUrl = "ftp://$($sync.FTP_HOST)/$(Convert-ToFtpPath $remotePath)"
    & curl.exe --silent --show-error --fail --disable-epsv --ftp-method nocwd --ftp-create-dirs `
        --user "$($sync.FTP_USERNAME):$($sync.FTP_PASSWORD)" -T $localPath $remoteUrl

    if ($LASTEXITCODE -ne 0) {
        throw "FTP upload failed: $remotePath"
    }
}

function Remove-FtpFile([string] $remotePath, [hashtable] $sync) {
    & curl.exe --silent --disable-epsv --ftp-method nocwd `
        --user "$($sync.FTP_USERNAME):$($sync.FTP_PASSWORD)" "ftp://$($sync.FTP_HOST)/" `
        --quote "DELE $remotePath" --output NUL | Out-Null
}

function Invoke-Runner([string] $action, [hashtable] $query, [hashtable] $state) {
    $pairs = @("token=$([uri]::EscapeDataString($state.Token))", "action=$([uri]::EscapeDataString($action))")
    foreach ($key in $query.Keys) {
        $pairs += "$([uri]::EscapeDataString($key))=$([uri]::EscapeDataString([string] $query[$key]))"
    }

    $url = "$($state.BaseUrl.TrimEnd('/'))/$($state.RunnerName)?$($pairs -join '&')"
    $responsePath = Join-Path $state.DeployDir "runner-$action-$((Get-Date).ToString('yyyyMMdd-HHmmss')).txt"
    $status = & curl.exe --silent --show-error --max-time 1800 --write-out '%{http_code}' --output $responsePath $url
    $body = Get-Content $responsePath -Raw
    Write-Output $body.Trim()

    if ($LASTEXITCODE -ne 0 -or $status -ne '200') {
        throw "Runner action $action failed with HTTP $status"
    }
}

function Add-ToArchive([string] $archivePath, [string] $basePath, [string[]] $entries, [switch] $Gzip) {
    Remove-Item $archivePath -ErrorAction SilentlyContinue
    $args = @('-cf', $archivePath, '-C', $basePath) + $entries
    if ($Gzip) {
        $args = @('-czf', $archivePath, '-C', $basePath) + $entries
    }

    & tar.exe @args
    if ($LASTEXITCODE -ne 0) {
        throw "Archive failed: $archivePath"
    }
}

function Copy-PublicStaging([string] $source, [string] $target) {
    Remove-Item $target -Recurse -Force -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Path $target -Force | Out-Null
    & robocopy.exe $source $target /E /XD storage | Out-Null
    if ($LASTEXITCODE -gt 7) {
        throw 'robocopy public staging failed'
    }
    & attrib.exe -R (Join-Path $target '*') /S /D
}

function Copy-CodeStaging([string] $source, [string] $target, [string[]] $entries) {
    Remove-Item $target -Recurse -Force -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Path $target -Force | Out-Null

    foreach ($entry in $entries) {
        $from = Join-Path $source $entry
        $to = Join-Path $target $entry
        if (Test-Path $from -PathType Container) {
            New-Item -ItemType Directory -Path $to -Force | Out-Null
            & robocopy.exe $from $to /E | Out-Null
            if ($LASTEXITCODE -gt 7) {
                throw "robocopy code staging failed: $entry"
            }
        } elseif (Test-Path $from -PathType Leaf) {
            New-Item -ItemType Directory -Path (Split-Path $to -Parent) -Force | Out-Null
            Copy-Item $from $to -Force
        }
    }

    Remove-CodeStagingLocalOnlyFiles $target

    $bootstrapCache = Join-Path $target 'bootstrap\cache'
    if (Test-Path $bootstrapCache) {
        Get-ChildItem -Path $bootstrapCache -Force |
            Where-Object { $_.Name -ne '.gitignore' } |
            Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    }

    & attrib.exe -R (Join-Path $target '*') /S /D
}

function Remove-CodeStagingLocalOnlyFiles([string] $target) {
    foreach ($relativePath in @(
        'database\backups',
        'database\dumps',
        'database\database.sqlite'
    )) {
        Remove-Item (Join-Path $target $relativePath) -Recurse -Force -ErrorAction SilentlyContinue
    }

    Get-ChildItem -Path $target -Recurse -Force -File -Filter '*-BOSS.*' -ErrorAction SilentlyContinue |
        Remove-Item -Force -ErrorAction SilentlyContinue

    $scriptsPath = Join-Path $target 'scripts'
    if (Test-Path $scriptsPath) {
        Get-ChildItem -Path $scriptsPath -Force |
            Where-Object { $_.Name -ne 'tesla_official_browser_search.mjs' } |
            Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    }
}

$sync = Read-DotEnv $configPath
foreach ($key in @('FTP_HOST', 'FTP_USERNAME', 'FTP_PASSWORD', 'LIVE_BASE_URL', 'LIVE_APP_REMOTE', 'LIVE_PUBLIC_REMOTE', 'REMOTE_PRIVATE_DIR')) {
    $sync[$key] = Require-Value $sync $key
}

New-Item -ItemType Directory -Path $deployDir -Force | Out-Null
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$tokenBytes = New-Object byte[] 24
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($tokenBytes)
$token = ($tokenBytes | ForEach-Object { $_.ToString('x2') }) -join ''

$runnerName = "codex-live-file-deploy-$timestamp.php"
$runnerLocal = Join-Path $deployDir $runnerName
$runnerRemote = "$($sync.LIVE_PUBLIC_REMOTE)/$runnerName"
$remotePrivate = "$($sync.LIVE_APP_REMOTE)/$($sync.REMOTE_PRIVATE_DIR)"

$runner = @"
<?php

declare(strict_types=1);

`$token = '$token';
if (! hash_equals(`$token, (string) (`$_GET['token'] ?? ''))) {
    http_response_code(404);
    exit;
}

set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: text/plain; charset=UTF-8');

`$publicRoot = __DIR__;
`$homeRoot = dirname(__DIR__);
`$appRoot = `$homeRoot.'/sklad_app';
`$privateRoot = `$appRoot.'/storage/app/private';
`$action = (string) (`$_GET['action'] ?? '');

function run_shell(string `$label, string `$command): void
{
    echo ">>> `$label\n";
    `$cmd = '/bin/bash -lc '.escapeshellarg('set -euo pipefail; '.`$command);
    `$lines = [];
    `$status = 0;
    exec(`$cmd.' 2>&1', `$lines, `$status);
    if (`$lines !== []) {
        echo implode("\n", `$lines)."\n";
    }

    if (`$status !== 0) {
        http_response_code(500);
        echo "FAILED: `$label status=`$status\n";
        exit;
    }
}

function cli_php(): string
{
    foreach (['/opt/alt/php83/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php'] as `$candidate) {
        if (is_file(`$candidate) && is_executable(`$candidate)) {
            return escapeshellarg(`$candidate);
        }
    }

    return 'php';
}

function archive_path(string `$name): string
{
    global `$privateRoot;
    `$base = basename(`$name);
    if (`$base === '' || `$base !== `$name) {
        http_response_code(400);
        exit("Invalid archive name\n");
    }

    return `$privateRoot.'/'.`$base;
}

function extract_flag(string `$archive): string
{
    return str_ends_with(`$archive, '.gz') ? '-xzf' : '-xf';
}

if (`$action === 'ping') {
    echo json_encode([
        'ok' => true,
        'appRoot' => `$appRoot,
        'publicRoot' => `$publicRoot,
        'time' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    exit;
}

if (`$action === 'deploy_code') {
    `$archive = (string) (`$_GET['archive'] ?? '');
    `$path = archive_path(`$archive);
    if (! is_file(`$path)) {
        http_response_code(500);
        exit("Archive missing: `$path\n");
    }

    run_shell('fix code permissions', 'chmod -R u+rwX '.escapeshellarg(`$appRoot).' 2>/dev/null || true');
    run_shell('extract code', 'mkdir -p '.escapeshellarg(`$appRoot).' && tar --overwrite --no-same-owner --no-same-permissions '.extract_flag(`$archive).' '.escapeshellarg(`$path).' -C '.escapeshellarg(`$appRoot));
    run_shell('ensure writable dirs', 'mkdir -p '.escapeshellarg(`$appRoot.'/bootstrap/cache').' '.escapeshellarg(`$appRoot.'/storage/app/private').' '.escapeshellarg(`$appRoot.'/storage/app/public').' '.escapeshellarg(`$appRoot.'/storage/framework/cache').' '.escapeshellarg(`$appRoot.'/storage/framework/sessions').' '.escapeshellarg(`$appRoot.'/storage/framework/views').' '.escapeshellarg(`$appRoot.'/storage/logs'));
    @unlink(`$path);
    echo "OK deploy_code\n";
    exit;
}

if (`$action === 'deploy_public') {
    `$archive = (string) (`$_GET['archive'] ?? '');
    `$path = archive_path(`$archive);
    if (! is_file(`$path)) {
        http_response_code(500);
        exit("Archive missing: `$path\n");
    }

    run_shell('clear public except storage', 'find '.escapeshellarg(`$publicRoot).' -mindepth 1 -maxdepth 1 ! -name storage ! -name '.escapeshellarg(basename(__FILE__)).' -exec rm -rf {} +');
    run_shell('fix public permissions', 'chmod -R u+rwX '.escapeshellarg(`$publicRoot).' 2>/dev/null || true');
    run_shell('extract public', 'tar --overwrite --no-same-owner --no-same-permissions '.extract_flag(`$archive).' '.escapeshellarg(`$path).' -C '.escapeshellarg(`$publicRoot));
    if (! file_exists(`$publicRoot.'/storage')) {
        @symlink(`$appRoot.'/storage/app/public', `$publicRoot.'/storage');
    }
    @unlink(`$path);
    echo "OK deploy_public\n";
    exit;
}

if (`$action === 'cleanup_home_known') {
    `$known = ['deploy-live.tar', 'deploy-sklad.tar', 'app.js', 'cards.html', 'cash.html', 'index.html', 'parking.html', 'styles.css', 'tenants.html', 'tesla.html'];
    foreach (`$known as `$name) {
        `$path = `$homeRoot.'/'.`$name;
        if (file_exists(`$path) || is_link(`$path)) {
            run_shell('remove home '.`$name, 'rm -rf '.escapeshellarg(`$path));
        }
    }
    echo "OK cleanup_home_known\n";
    exit;
}

if (`$action === 'cleanup_retired_live_sync') {
    `$paths = [
        `$appRoot.'/sync-live-db.bat',
        `$appRoot.'/app/Http/Middleware/MarkLiveSyncPending.php',
        `$appRoot.'/scripts/sync-live-db.ps1',
        `$appRoot.'/scripts/sync-live-db-hidden.vbs',
        `$appRoot.'/storage/app/live-sync/pending.flag',
    ];

    foreach (`$paths as `$path) {
        if (file_exists(`$path) || is_link(`$path)) {
            run_shell('remove retired live sync file '.basename(`$path), 'rm -f '.escapeshellarg(`$path));
        }
    }

    run_shell('remove retired live sync directory if empty', 'rmdir '.escapeshellarg(`$appRoot.'/storage/app/live-sync').' 2>/dev/null || true');
    echo "OK cleanup_retired_live_sync\n";
    exit;
}

if (`$action === 'artisan_release') {
    chdir(`$appRoot);
    `$php = cli_php();
    run_shell('clear stale bootstrap cache files', 'find bootstrap/cache -type f ! -name ".gitignore" -delete');
    run_shell('artisan optimize clear', `$php.' artisan optimize:clear');
    run_shell('artisan migrate', `$php.' artisan migrate --force');
    run_shell('artisan config cache', `$php.' artisan config:cache');
    run_shell('artisan route cache', `$php.' artisan route:cache');
    run_shell('artisan view cache', `$php.' artisan view:cache');
    echo "OK artisan_release\n";
    exit;
}

http_response_code(400);
echo "Unknown action\n";
"@

Set-Content -Encoding ASCII -Path $runnerLocal -Value $runner

$state = @{
    Token = $token
    BaseUrl = $sync.LIVE_BASE_URL
    RunnerName = $runnerName
    DeployDir = $deployDir
}

try {
    if (!$SkipArchives) {
        Write-Output 'Running local encoding audit...'
        Invoke-LocalEncodingAudit

        Write-Output 'Creating code archive...'
        $codeEntries = @(
            '.editorconfig', '.env.example', '.env.production.example', '.gitattributes', '.gitignore', '.npmrc',
            'AGENTS.md', 'README.md', 'app', 'artisan', 'bootstrap', 'capacitor.config.json',
            'composer.json', 'composer.lock', 'config', 'database', 'docs', 'package-lock.json',
            'package.json', 'phpunit.xml', 'resources', 'routes', 'scripts',
            'tests', 'vite.config.js'
        )
        $codeStaging = Join-Path $deployDir 'code-staging'
        Copy-CodeStaging $root $codeStaging $codeEntries
        $codeArchive = Join-Path $deployDir "code-$timestamp.tar.gz"
        Add-ToArchive $codeArchive $codeStaging @('.') -Gzip

        Write-Output 'Creating public archive...'
        $publicStaging = Join-Path $deployDir 'public-staging'
        Copy-PublicStaging (Join-Path $root 'public') $publicStaging
        $publicArchive = Join-Path $deployDir "public-$timestamp.tar.gz"
        Add-ToArchive $publicArchive $publicStaging @('.') -Gzip
    }

    Write-Output 'Uploading runner...'
    Send-FtpFile $runnerLocal $runnerRemote $sync
    Invoke-Runner 'ping' @{} $state

    $codeArchive = Get-ChildItem $deployDir -Filter "code-$timestamp.tar.gz" | Select-Object -First 1
    $publicArchive = Get-ChildItem $deployDir -Filter "public-$timestamp.tar.gz" | Select-Object -First 1

    Write-Output 'Uploading and deploying code archive...'
    Send-FtpFile $codeArchive.FullName "$remotePrivate/$($codeArchive.Name)" $sync
    Invoke-Runner 'deploy_code' @{ archive = $codeArchive.Name } $state

    Write-Output 'Uploading and deploying public archive...'
    Send-FtpFile $publicArchive.FullName "$remotePrivate/$($publicArchive.Name)" $sync
    Invoke-Runner 'deploy_public' @{ archive = $publicArchive.Name } $state

    Write-Output 'Live public storage is protected. Code deploys never delete or replace media.'

    Write-Output 'Cleaning known old files in hosting home...'
    Invoke-Runner 'cleanup_home_known' @{} $state

    Write-Output 'Removing retired live DB sync files...'
    Invoke-Runner 'cleanup_retired_live_sync' @{} $state

    Write-Output 'Running live release commands...'
    Invoke-Runner 'artisan_release' @{} $state

    Write-Output 'Live file deploy completed.'
} finally {
    Remove-FtpFile $runnerRemote $sync
}
