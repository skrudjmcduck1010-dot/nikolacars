param(
    [Parameter(Mandatory = $true)]
    [string[]] $Path,

    [switch] $DryRun,

    [switch] $SkipRelease
)

$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$configPath = Join-Path $root '.env.live-sync'
$deployDir = Join-Path $root '.codex-tmp\deploy-live-selected'

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

function Write-Utf8NoBom([string] $path, [string] $contents) {
    [System.IO.File]::WriteAllText($path, $contents, [System.Text.UTF8Encoding]::new($false))
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

function Receive-FtpFile([string] $remotePath, [string] $localPath, [hashtable] $sync) {
    New-Item -ItemType Directory -Path (Split-Path $localPath -Parent) -Force | Out-Null
    $remoteUrl = "ftp://$($sync.FTP_HOST)/$(Convert-ToFtpPath $remotePath)"
    & curl.exe --silent --show-error --fail --disable-epsv --ftp-method nocwd `
        --user "$($sync.FTP_USERNAME):$($sync.FTP_PASSWORD)" --output $localPath $remoteUrl

    if ($LASTEXITCODE -ne 0) {
        throw "FTP download failed: $remotePath"
    }
}

function Remove-FtpFile([string] $remotePath, [hashtable] $sync) {
    & curl.exe --silent --disable-epsv --ftp-method nocwd `
        --user "$($sync.FTP_USERNAME):$($sync.FTP_PASSWORD)" "ftp://$($sync.FTP_HOST)/" `
        --quote "DELE $remotePath" --output NUL | Out-Null
}

function Resolve-DeployPath([string] $path) {
    $relative = $path.Replace('\', '/').Trim('/')

    if ([System.IO.Path]::IsPathRooted($path)) {
        $full = (Resolve-Path $path).Path
        $rootText = $root.Path.TrimEnd('\') + '\'
        if (!$full.StartsWith($rootText, [System.StringComparison]::OrdinalIgnoreCase)) {
            throw "Selected file is outside project root: $path"
        }

        $relative = $full.Substring($rootText.Length).Replace('\', '/')
    }

    if ($relative -eq '' -or $relative.Contains('../') -or $relative.Contains('/..') -or $relative -eq '..') {
        throw "Unsafe relative path: $path"
    }

    foreach ($blocked in @(
        '.env',
        '.env.live-sync',
        '.codex-tmp/',
        'database/backups/',
        'database/dumps/',
        'node_modules/',
        'outputs/',
        'storage/app/public/',
        'vendor/'
    )) {
        if ($relative -eq $blocked.TrimEnd('/') -or $relative.StartsWith($blocked, [System.StringComparison]::OrdinalIgnoreCase)) {
            throw "Refusing to deploy local-only or protected path: $relative"
        }
    }

    $local = Join-Path $root $relative
    if (!(Test-Path $local -PathType Leaf)) {
        throw "Selected deploy path is not a file: $relative"
    }

    return [pscustomobject]@{
        Relative = $relative
        Local = (Resolve-Path $local).Path
    }
}

function New-EncodingGuardScript([string] $path) {
    $autoloadPath = (Join-Path $root 'vendor/autoload.php').Replace('\', '/').Replace("'", "\\'")
    $script = @'
<?php

declare(strict_types=1);

require '__AUTOLOAD_PATH__';

use App\Support\CatalogTextEncoding;

$failed = false;

foreach (array_slice($argv, 1) as $path) {
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, "Unable to read {$path}\n");
        $failed = true;
        continue;
    }

    if (str_contains(substr($contents, 0, 4096), "\0")) {
        continue;
    }

    if (str_starts_with($contents, "\xEF\xBB\xBF")) {
        fwrite(STDERR, "{$path}:1 UTF-8 BOM\n");
        $failed = true;
    }

    if (! mb_check_encoding($contents, 'UTF-8')) {
        fwrite(STDERR, "{$path}:1 invalid UTF-8 bytes\n");
        $failed = true;
        continue;
    }

    $lines = preg_split('/\R/u', $contents) ?: [];
    foreach ($lines as $index => $line) {
        if (str_contains($line, "\u{FFFD}") || CatalogTextEncoding::looksLikeMojibake($line)) {
            $sample = trim((string) preg_replace('/\s+/u', ' ', $line));
            if (mb_strlen($sample) > 160) {
                $sample = mb_substr($sample, 0, 157).'...';
            }

            fwrite(STDERR, "{$path}:".($index + 1)." possible mojibake: {$sample}\n");
            $failed = true;
        }
    }
}

exit($failed ? 1 : 0);
'@

    $script = $script.Replace('__AUTOLOAD_PATH__', $autoloadPath)
    Write-Utf8NoBom $path $script
}

function Invoke-EncodingGuard([string[]] $paths, [string] $label, [string] $scannerPath) {
    Write-Output "Checking encoding: $label"
    $php = Resolve-LocalPhp
    & $php $scannerPath @paths

    if ($LASTEXITCODE -ne 0) {
        throw "Encoding guard failed: $label"
    }
}

function Invoke-Runner([string] $action, [hashtable] $state) {
    $url = "$($state.BaseUrl.TrimEnd('/'))/$($state.RunnerName)?token=$([uri]::EscapeDataString($state.Token))&action=$([uri]::EscapeDataString($action))"
    $responsePath = Join-Path $state.DeployDir "runner-$action-$((Get-Date).ToString('yyyyMMdd-HHmmss')).txt"
    $status = & curl.exe --silent --show-error --max-time 1800 --write-out '%{http_code}' --output $responsePath $url
    $body = Get-Content $responsePath -Raw
    Write-Output $body.Trim()

    if ($LASTEXITCODE -ne 0 -or $status -ne '200') {
        throw "Runner action $action failed with HTTP $status"
    }
}

function New-ReleaseRunner([string] $path, [string] $token) {
    $runner = @"
<?php
declare(strict_types=1);
`$token = '$token';
if (!hash_equals(`$token, (string) (`$_GET['token'] ?? ''))) { http_response_code(404); exit; }
set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: text/plain; charset=UTF-8');
`$publicRoot = __DIR__;
`$homeRoot = dirname(__DIR__);
`$appRoot = `$homeRoot.'/sklad_app';
`$action = (string) (`$_GET['action'] ?? '');
function run_shell(string `$label, string `$command): void {
    echo ">>> `$label\n";
    `$cmd = '/bin/bash -lc '.escapeshellarg('set -euo pipefail; '.`$command);
    `$lines = [];
    `$status = 0;
    exec(`$cmd.' 2>&1', `$lines, `$status);
    if (`$lines !== []) { echo implode("\n", `$lines)."\n"; }
    if (`$status !== 0) { http_response_code(500); echo "FAILED: `$label status=`$status\n"; exit; }
}
function cli_php(): string {
    foreach (['/opt/alt/php83/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php'] as `$candidate) {
        if (is_file(`$candidate) && is_executable(`$candidate)) { return escapeshellarg(`$candidate); }
    }
    return 'php';
}
if (`$action === 'release') {
    chdir(`$appRoot);
    `$php = cli_php();
    run_shell('clear stale bootstrap cache files', 'find bootstrap/cache -type f ! -name ".gitignore" -delete');
    run_shell('artisan optimize clear', `$php.' artisan optimize:clear');
    run_shell('artisan migrate', `$php.' artisan migrate --force');
    run_shell('artisan config cache', `$php.' artisan config:cache');
    run_shell('artisan route cache', `$php.' artisan route:cache');
    run_shell('artisan view cache', `$php.' artisan view:cache');
    echo "OK release\n";
    exit;
}
http_response_code(400);
echo "Unknown action\n";
"@

    Write-Utf8NoBom $path $runner
}

$sync = Read-DotEnv $configPath
foreach ($key in @('FTP_HOST', 'FTP_USERNAME', 'FTP_PASSWORD', 'LIVE_BASE_URL', 'LIVE_APP_REMOTE', 'LIVE_PUBLIC_REMOTE')) {
    $sync[$key] = Require-Value $sync $key
}

New-Item -ItemType Directory -Path $deployDir -Force | Out-Null
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$workDir = Join-Path $deployDir $timestamp
$verifyDir = Join-Path $workDir 'verify'
New-Item -ItemType Directory -Path $verifyDir -Force | Out-Null

$normalizedPaths = $Path |
    ForEach-Object { $_ -split ',' } |
    ForEach-Object { $_.Trim() } |
    Where-Object { $_ -ne '' }

$selectedFiles = $normalizedPaths |
    ForEach-Object { Resolve-DeployPath $_ } |
    Sort-Object Relative -Unique

$scannerPath = Join-Path $workDir 'encoding-guard.php'
New-EncodingGuardScript $scannerPath

Invoke-EncodingGuard ($selectedFiles | ForEach-Object { $_.Local }) 'local selected files before upload' $scannerPath

if ($DryRun) {
    Write-Output 'Dry run completed. No files uploaded.'
    exit
}

foreach ($file in $selectedFiles) {
    $remotePath = "$($sync.LIVE_APP_REMOTE)/$($file.Relative)"
    Send-FtpFile $file.Local $remotePath $sync
    Write-Output "Uploaded $($file.Relative)"
}

$downloaded = @()
foreach ($file in $selectedFiles) {
    $target = Join-Path $verifyDir ($file.Relative.Replace('/', '\'))
    Receive-FtpFile "$($sync.LIVE_APP_REMOTE)/$($file.Relative)" $target $sync
    $downloaded += $target
}

Invoke-EncodingGuard $downloaded 'downloaded live files after upload' $scannerPath

if (!$SkipRelease) {
    $tokenBytes = New-Object byte[] 24
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($tokenBytes)
    $token = ($tokenBytes | ForEach-Object { $_.ToString('x2') }) -join ''

    $runnerName = "codex-live-selected-release-$timestamp.php"
    $runnerLocal = Join-Path $workDir $runnerName
    $runnerRemote = "$($sync.LIVE_PUBLIC_REMOTE)/$runnerName"
    New-ReleaseRunner $runnerLocal $token

    try {
        Send-FtpFile $runnerLocal $runnerRemote $sync
        Invoke-Runner 'release' @{
            Token = $token
            BaseUrl = $sync.LIVE_BASE_URL
            RunnerName = $runnerName
            DeployDir = $workDir
        }
    } finally {
        Remove-FtpFile $runnerRemote $sync
    }
}

Write-Output 'Selected live file deploy completed safely.'
