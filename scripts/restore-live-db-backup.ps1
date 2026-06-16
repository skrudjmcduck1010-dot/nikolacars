param(
    [Parameter(Mandatory = $true)]
    [string] $BackupFile
)

$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$configPath = Join-Path $root '.env.live-sync'
$workDir = Join-Path $root '.codex-tmp\deploy-live'

function Read-DotEnv([string] $path) {
    $values = @{}
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

$sync = Read-DotEnv $configPath
foreach ($key in @('FTP_HOST', 'FTP_USERNAME', 'FTP_PASSWORD', 'LIVE_BASE_URL', 'LIVE_PUBLIC_REMOTE')) {
    $sync[$key] = Require-Value $sync $key
}

New-Item -ItemType Directory -Path $workDir -Force | Out-Null
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$tokenBytes = New-Object byte[] 24
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($tokenBytes)
$token = ($tokenBytes | ForEach-Object { $_.ToString('x2') }) -join ''
$runnerName = "codex-restore-live-db-$timestamp.php"
$runnerLocal = Join-Path $workDir $runnerName
$runnerRemote = "$($sync.LIVE_PUBLIC_REMOTE)/$runnerName"

$runner = @"
<?php
declare(strict_types=1);

`$token = '$token';
`$backupFile = '$BackupFile';
if (! hash_equals(`$token, (string) (`$_GET['token'] ?? ''))) {
    http_response_code(404);
    exit;
}
set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: text/plain; charset=UTF-8');

`$appRoot = dirname(__DIR__).'/sklad_app';
chdir(`$appRoot);

require `$appRoot.'/vendor/autoload.php';
`$app = require `$appRoot.'/bootstrap/app.php';
`$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

`$connection = config('database.default');
`$config = config("database.connections.`$connection");
`$db = (string) `$config['database'];
`$host = (string) `$config['host'];
`$port = (string) (`$config['port'] ?? 3306);
`$user = (string) `$config['username'];
`$pass = (string) `$config['password'];
`$backupPath = `$appRoot.'/storage/app/private/db-backups/'.basename(`$backupFile);

if (! is_file(`$backupPath)) {
    http_response_code(500);
    exit("Backup not found: `$backupPath\n");
}

`$mysqlArgs = implode(' ', [
    '--host='.escapeshellarg(`$host),
    '--port='.escapeshellarg(`$port),
    '--user='.escapeshellarg(`$user),
    escapeshellarg('--password='.`$pass),
    '--default-character-set=utf8mb4',
    escapeshellarg(`$db),
]);

function run_shell(string `$label, string `$pipeline): void
{
    echo ">>> `$label\n";
    `$cmd = '/bin/bash -lc '.escapeshellarg('set -o pipefail; '.`$pipeline);
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

run_shell('remove stale bootstrap cache', 'rm -f bootstrap/cache/*.php bootstrap/cache/*.tmp');
run_shell('restore db backup', '/bin/gzip -dc '.escapeshellarg(`$backupPath).' | /bin/mysql '.`$mysqlArgs);
run_shell('artisan optimize clear', 'php artisan optimize:clear || true');
run_shell('artisan config cache', 'php artisan config:cache');
run_shell('artisan route cache', 'php artisan route:cache');
run_shell('artisan view cache', 'php artisan view:cache');

echo json_encode([
    'products' => class_exists(App\Models\Product::class) ? App\Models\Product::count() : null,
    'donors' => class_exists(App\Models\DonorCar::class) ? App\Models\DonorCar::count() : null,
    'part_catalog_items' => class_exists(App\Models\PartCatalogItem::class) ? App\Models\PartCatalogItem::count() : null,
    'tesla_official' => class_exists(App\Models\PartCatalogItem::class) ? App\Models\PartCatalogItem::where('source', 'tesla_official')->count() : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
echo "OK restored\n";
"@

Set-Content -Encoding ASCII -Path $runnerLocal -Value $runner

try {
    Send-FtpFile $runnerLocal $runnerRemote $sync
    $url = "$($sync.LIVE_BASE_URL.TrimEnd('/'))/$runnerName`?token=$token"
    $responsePath = Join-Path $workDir "restore-live-db-$timestamp.txt"
    $status = & curl.exe --silent --show-error --max-time 900 --write-out '%{http_code}' --output $responsePath $url
    Get-Content $responsePath
    if ($LASTEXITCODE -ne 0 -or $status -ne '200') {
        throw "Restore failed with HTTP $status"
    }
} finally {
    Remove-FtpFile $runnerRemote $sync
}
