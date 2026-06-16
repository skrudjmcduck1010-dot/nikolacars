param(
    [switch] $KeepLocalDump,
    [switch] $KeepRemoteDump
)

$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$configPath = Join-Path $root '.env.live-sync'
$localEnvPath = Join-Path $root '.env'
$syncDir = Join-Path $root '.codex-tmp\live-pull'

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

function Require-Value([hashtable] $values, [string] $key, [string] $source) {
    if (!$values.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($values[$key])) {
        throw "Missing $key in $source"
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

function Receive-FtpFile([string] $remotePath, [string] $localPath, [hashtable] $sync) {
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

function Find-MysqlTool([string] $name, [hashtable] $sync) {
    if ($name -eq 'mysqldump' -and $sync.MYSQLDUMP_PATH -and (Test-Path $sync.MYSQLDUMP_PATH)) {
        return $sync.MYSQLDUMP_PATH
    }

    $configuredDump = $sync.MYSQLDUMP_PATH
    if ($configuredDump) {
        $candidate = Join-Path (Split-Path $configuredDump -Parent) "$name.exe"
        if (Test-Path $candidate) {
            return $candidate
        }
    }

    $laragon = "C:\laragon\bin\mysql"
    if (Test-Path $laragon) {
        $candidate = Get-ChildItem $laragon -Recurse -Filter "$name.exe" -ErrorAction SilentlyContinue |
            Sort-Object FullName -Descending |
            Select-Object -First 1
        if ($candidate) {
            return $candidate.FullName
        }
    }

    return $name
}

function Find-PhpTool() {
    $laragon = "C:\laragon\bin\php"
    if (Test-Path $laragon) {
        $candidate = Get-ChildItem $laragon -Recurse -Filter "php.exe" -ErrorAction SilentlyContinue |
            Sort-Object FullName -Descending |
            Select-Object -First 1
        if ($candidate) {
            return $candidate.FullName
        }
    }

    return 'php'
}

function New-GzipFile([string] $sourcePath, [string] $targetPath) {
    Remove-Item $targetPath -ErrorAction SilentlyContinue
    $in = [System.IO.File]::OpenRead((Resolve-Path $sourcePath))
    $out = [System.IO.File]::Create($targetPath)
    $gz = New-Object System.IO.Compression.GzipStream($out, [System.IO.Compression.CompressionMode]::Compress)
    try {
        $in.CopyTo($gz)
    } finally {
        $gz.Dispose()
        $out.Dispose()
        $in.Dispose()
    }
}

function Expand-GzipFile([string] $sourcePath, [string] $targetPath) {
    Remove-Item $targetPath -ErrorAction SilentlyContinue
    $in = [System.IO.File]::OpenRead((Resolve-Path $sourcePath))
    $gz = New-Object System.IO.Compression.GzipStream($in, [System.IO.Compression.CompressionMode]::Decompress)
    $out = [System.IO.File]::Create($targetPath)
    try {
        $gz.CopyTo($out)
    } finally {
        $out.Dispose()
        $gz.Dispose()
        $in.Dispose()
    }
}

$sync = Read-DotEnv $configPath
$localEnv = Read-DotEnv $localEnvPath

foreach ($key in @('FTP_HOST', 'FTP_USERNAME', 'FTP_PASSWORD', 'LIVE_BASE_URL', 'LIVE_APP_REMOTE', 'LIVE_PUBLIC_REMOTE', 'REMOTE_PRIVATE_DIR')) {
    $sync[$key] = Require-Value $sync $key '.env.live-sync'
}

$dbName = if ($sync.LOCAL_DB_DATABASE) { $sync.LOCAL_DB_DATABASE } else { Require-Value $localEnv 'DB_DATABASE' '.env' }
$dbUser = if ($sync.LOCAL_DB_USERNAME) { $sync.LOCAL_DB_USERNAME } else { Require-Value $localEnv 'DB_USERNAME' '.env' }
$dbPass = if ($sync.ContainsKey('LOCAL_DB_PASSWORD')) { $sync.LOCAL_DB_PASSWORD } else { $localEnv.DB_PASSWORD }
$dbHost = if ($sync.LOCAL_DB_HOST) { $sync.LOCAL_DB_HOST } else { $localEnv.DB_HOST }
$dbPort = if ($sync.LOCAL_DB_PORT) { $sync.LOCAL_DB_PORT } else { $localEnv.DB_PORT }

if (!$dbHost) { $dbHost = '127.0.0.1' }
if (!$dbPort) { $dbPort = '3306' }

$mysql = Find-MysqlTool 'mysql' $sync
$mysqldump = Find-MysqlTool 'mysqldump' $sync
$php = Find-PhpTool

New-Item -ItemType Directory -Force $syncDir | Out-Null

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$tokenBytes = New-Object byte[] 24
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($tokenBytes)
$token = ($tokenBytes | ForEach-Object { $_.ToString('x2') }) -join ''

$dumpName = "live-to-local-$timestamp.sql"
$gzipName = "$dumpName.gz"
$remoteDump = "$($sync.LIVE_APP_REMOTE)/$($sync.REMOTE_PRIVATE_DIR)/$gzipName"
$remotePhpName = "codex-live-db-export-$timestamp.php"
$remotePhp = "$($sync.LIVE_PUBLIC_REMOTE)/$remotePhpName"
$localPhp = Join-Path $syncDir $remotePhpName
$gzipPath = Join-Path $syncDir $gzipName
$dumpPath = Join-Path $syncDir $dumpName
$normalizedDumpPath = Join-Path $syncDir "normalized-$dumpName"
$normalizerPath = Join-Path $syncDir "normalize-live-dump-$timestamp.php"
$localBackup = Join-Path $syncDir "local-before-live-import-$timestamp.sql"
$localBackupGz = "$localBackup.gz"
$mysqlDefaults = Join-Path $syncDir "local-mysql-$timestamp.cnf"

$remotePhpContent = @"
<?php

declare(strict_types=1);

`$token = '$token';
`$dumpFile = '$gzipName';

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
`$dumpPath = `$appRoot.'/storage/app/private/'.`$dumpFile;

`$mysqlArgs = implode(' ', [
    '--host='.escapeshellarg(`$host),
    '--port='.escapeshellarg(`$port),
    '--user='.escapeshellarg(`$user),
    escapeshellarg('--password='.`$pass),
    '--default-character-set=utf8mb4',
    '--single-transaction',
    '--skip-lock-tables',
    '--routines',
    '--triggers',
    '--add-drop-table',
    escapeshellarg(`$db),
]);

`$cmd = '/bin/bash -lc '.escapeshellarg('set -o pipefail; /bin/mysqldump '.`$mysqlArgs.' | /bin/gzip > '.escapeshellarg(`$dumpPath));
`$lines = [];
`$status = 0;
exec(`$cmd.' 2>&1', `$lines, `$status);

if (`$status !== 0) {
    http_response_code(500);
    echo implode("\n", `$lines)."\n";
    echo "FAILED status=`$status\n";
    exit;
}

echo json_encode([
    'database' => `$db,
    'dump' => `$dumpPath,
    'bytes' => filesize(`$dumpPath),
    'products' => class_exists(App\Models\Product::class) ? App\Models\Product::count() : null,
    'donors' => class_exists(App\Models\DonorCar::class) ? App\Models\DonorCar::count() : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
echo "OK\n";
"@

Set-Content -Encoding ASCII -Path $localPhp -Value $remotePhpContent

try {
    Write-Output 'Uploading remote export runner...'
    Send-FtpFile $localPhp $remotePhp $sync

    Write-Output 'Exporting live database...'
    $url = "$($sync.LIVE_BASE_URL.TrimEnd('/'))/$remotePhpName`?token=$token"
    $responsePath = Join-Path $syncDir "live-export-response-$timestamp.txt"
    $status = & curl.exe --silent --show-error --max-time 900 --write-out '%{http_code}' --output $responsePath $url
    Get-Content $responsePath

    if ($LASTEXITCODE -ne 0 -or $status -ne '200') {
        throw "Live export failed with HTTP $status"
    }

    Write-Output 'Downloading live dump...'
    Receive-FtpFile $remoteDump $gzipPath $sync
    Expand-GzipFile $gzipPath $dumpPath

    Write-Output 'Normalizing dump for local MySQL...'
    $normalizer = @'
<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php normalize-live-dump.php input.sql output.sql\n");
    exit(2);
}

$input = fopen($argv[1], 'rb');
$output = fopen($argv[2], 'wb');

if (! $input || ! $output) {
    fwrite(STDERR, "Unable to open input or output\n");
    exit(2);
}

$partCatalogColumns = [
    'id',
    'part_catalog_category_id',
    'source',
    'source_url',
    'part_number',
    'name',
    'name_en',
    'name_ru',
    'name_ua',
    'name_ru_manually_locked_at',
    'scheme_number',
    'price_amount',
    'currency',
    'model_label',
    'model_name',
    'year_from',
    'year_to',
    'main_category_code',
    'main_category_name',
    'subcategory_code',
    'subcategory_name',
    'node_name',
    'compatibility_text',
    'notes_en',
    'notes_ru',
    'notes_ua',
    'condition',
    'quality',
    'availability',
    'raw_attributes',
    'source_updated_at',
    'created_at',
    'updated_at',
];
$partCatalogPrefix = 'INSERT INTO `part_catalog_items` VALUES';
$partCatalogInsert = 'INSERT INTO `part_catalog_items` (`'.implode('`,`', $partCatalogColumns).'`) VALUES ';

function splitTuples(string $values): array
{
    $tuples = [];
    $length = strlen($values);
    $start = null;
    $depth = 0;
    $quoted = false;
    $escaped = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $values[$i];

        if ($quoted) {
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === "'") {
                $quoted = false;
            }
            continue;
        }

        if ($char === "'") {
            $quoted = true;
            continue;
        }

        if ($char === '(') {
            if ($depth === 0) {
                $start = $i + 1;
            }
            $depth++;
            continue;
        }

        if ($char === ')') {
            $depth--;
            if ($depth === 0 && $start !== null) {
                $tuples[] = substr($values, $start, $i - $start);
                $start = null;
            }
        }
    }

    return $tuples;
}

function splitValues(string $tuple): array
{
    $values = [];
    $length = strlen($tuple);
    $start = 0;
    $quoted = false;
    $escaped = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $tuple[$i];

        if ($quoted) {
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === "'") {
                $quoted = false;
            }
            continue;
        }

        if ($char === "'") {
            $quoted = true;
            continue;
        }

        if ($char === ',') {
            $values[] = substr($tuple, $start, $i - $start);
            $start = $i + 1;
        }
    }

    $values[] = substr($tuple, $start);

    return $values;
}

function normalizePartCatalogInsert(string $statement, string $prefix, string $insert): string
{
    $valuesSql = trim(substr($statement, strlen($prefix)));
    $valuesSql = rtrim($valuesSql);
    $valuesSql = rtrim($valuesSql, ';');
    $tuples = splitTuples($valuesSql);
    $normalized = [];

    foreach ($tuples as $tuple) {
        $values = splitValues($tuple);
        if (count($values) !== 36) {
            fwrite(STDERR, "Unexpected part_catalog_items value count: ".count($values)."\n");
            exit(1);
        }

        unset($values[33], $values[34], $values[35]);
        $normalized[] = '('.implode(',', array_values($values)).')';
    }

    return $insert.implode(",\n", $normalized).";\n";
}

$capturingPartCatalog = false;
$statement = '';

while (($line = fgets($input)) !== false) {
    if ($capturingPartCatalog) {
        $statement .= $line;
        if (str_ends_with(rtrim($line), ';')) {
            fwrite($output, normalizePartCatalogInsert($statement, $partCatalogPrefix, $partCatalogInsert));
            $capturingPartCatalog = false;
            $statement = '';
        }
        continue;
    }

    if (str_starts_with($line, $partCatalogPrefix)) {
        $capturingPartCatalog = true;
        $statement = $line;
        if (str_ends_with(rtrim($line), ';')) {
            fwrite($output, normalizePartCatalogInsert($statement, $partCatalogPrefix, $partCatalogInsert));
            $capturingPartCatalog = false;
            $statement = '';
        }
        continue;
    }

    fwrite($output, $line);
}

fclose($input);
fclose($output);
'@
    Set-Content -Encoding ASCII -Path $normalizerPath -Value $normalizer
    & $php $normalizerPath $dumpPath $normalizedDumpPath
    if ($LASTEXITCODE -ne 0) {
        throw 'Dump normalization failed'
    }

    Write-Output "Ensuring local database $dbName exists..."
    $createArgs = @(
        "--host=$dbHost",
        "--port=$dbPort",
        "--user=$dbUser",
        '--default-character-set=utf8mb4',
        '--execute',
        "CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    )
    if (![string]::IsNullOrEmpty($dbPass)) {
        $createArgs = @("--password=$dbPass") + $createArgs
    }
    & $mysql @createArgs
    if ($LASTEXITCODE -ne 0) {
        throw 'Local database create failed'
    }

    Write-Output 'Backing up current local database...'
    $backupArgs = @(
        "--host=$dbHost",
        "--port=$dbPort",
        "--user=$dbUser",
        "--default-character-set=utf8mb4",
        '--single-transaction',
        '--skip-lock-tables',
        '--routines',
        '--triggers',
        '--add-drop-table',
        "--result-file=$localBackup",
        $dbName
    )
    if (![string]::IsNullOrEmpty($dbPass)) {
        $backupArgs = @("--password=$dbPass") + $backupArgs
    }
    & $mysqldump @backupArgs
    if ($LASTEXITCODE -eq 0 -and (Test-Path $localBackup)) {
        New-GzipFile $localBackup $localBackupGz
        Remove-Item $localBackup -ErrorAction SilentlyContinue
        Write-Output "Local backup: $localBackupGz"
    } else {
        Write-Output 'Local backup skipped or empty.'
    }

    Write-Output "Recreating local database $dbName..."
    $recreateArgs = @(
        "--host=$dbHost",
        "--port=$dbPort",
        "--user=$dbUser",
        '--default-character-set=utf8mb4',
        '--execute',
        "DROP DATABASE IF EXISTS ``$dbName``; CREATE DATABASE ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    )
    if (![string]::IsNullOrEmpty($dbPass)) {
        $recreateArgs = @("--password=$dbPass") + $recreateArgs
    }
    & $mysql @recreateArgs
    if ($LASTEXITCODE -ne 0) {
        throw 'Local database recreate failed'
    }

    Write-Output 'Importing live dump into local database...'
    $defaults = @(
        '[client]',
        "host=$dbHost",
        "port=$dbPort",
        "user=$dbUser",
        'default-character-set=utf8mb4'
    )
    if (![string]::IsNullOrEmpty($dbPass)) {
        $defaults += "password=$dbPass"
    }
    Set-Content -Encoding ASCII -Path $mysqlDefaults -Value ($defaults -join [Environment]::NewLine)

    $importCmd = '"' + $mysql + '" --defaults-extra-file="' + $mysqlDefaults + '" --binary-mode=1 "' + $dbName + '" < "' + $normalizedDumpPath + '"'
    & cmd.exe /d /c $importCmd
    if ($LASTEXITCODE -ne 0) {
        throw 'Local import failed'
    }

    Push-Location $root
    try {
        & $php artisan optimize:clear
    } finally {
        Pop-Location
    }

    Write-Output 'Live database imported locally.'
} finally {
    Remove-FtpFile $remotePhp $sync
    if (!$KeepRemoteDump) {
        Remove-FtpFile $remoteDump $sync
    }

    if (!$KeepLocalDump) {
        Remove-Item $gzipPath, $dumpPath, $normalizedDumpPath, $localPhp, $mysqlDefaults, $normalizerPath -ErrorAction SilentlyContinue
    }
}
