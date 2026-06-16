param(
    [int] $BatchSize = 8,
    [int] $DelayMs = 1500,
    [int] $PageWaitMs = 6000,
    [int] $BatchTimeoutSec = 420,
    [int] $SleepOnFailureSec = 120,
    [int] $SleepBetweenBatchesSec = 5,
    [int] $MaxBatches = 0,
    [string] $Browser = 'firefox',
    [string] $ProfileDir = 'storage\app\tesla-official-firefox-playwright-profile',
    [switch] $Headed,
    [string] $Php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe',
    [string] $LogPath = 'storage\logs\tesla-official-find-part-loop.log'
)

$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$loopScript = Join-Path $root 'scripts\run-tesla-official-find-part-loop.ps1'
$resolvedLogPath = Join-Path $root $LogPath
$logDirectory = Split-Path -Parent $resolvedLogPath
$outLog = Join-Path $logDirectory 'tesla-official-find-part-loop.process.out.log'
$errLog = Join-Path $logDirectory 'tesla-official-find-part-loop.process.err.log'

New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null

function Add-LauncherLog {
    param([string] $Message)

    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -LiteralPath $resolvedLogPath -Value "[$timestamp] $Message" -Encoding UTF8
}

$headedArg = if ($Headed) { '-Headed' } else { '' }

$workerCommand = @"
`$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath '$($root.Path.Replace("'", "''"))'
try {
    & '$($loopScript.Replace("'", "''"))' -BatchSize $BatchSize -DelayMs $DelayMs -PageWaitMs $PageWaitMs -BatchTimeoutSec $BatchTimeoutSec -SleepOnFailureSec $SleepOnFailureSec -SleepBetweenBatchesSec $SleepBetweenBatchesSec -MaxBatches $MaxBatches -Browser '$($Browser.Replace("'", "''"))' -ProfileDir '$($ProfileDir.Replace("'", "''"))' $headedArg -Php '$($Php.Replace("'", "''"))' -LogPath '$($LogPath.Replace("'", "''"))'
    `$exitCode = `$LASTEXITCODE
    if (`$null -eq `$exitCode) { `$exitCode = 0 }
    if (`$exitCode -ne 0) {
        `$timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
        Add-Content -LiteralPath '$($resolvedLogPath.Replace("'", "''"))' -Value "[`$timestamp] BACKGROUND STOPPED WITH ERROR exit_code=`$exitCode" -Encoding UTF8
        exit `$exitCode
    }
    `$timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -LiteralPath '$($resolvedLogPath.Replace("'", "''"))' -Value "[`$timestamp] Background worker stopped normally." -Encoding UTF8
    exit 0
} catch {
    `$timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -LiteralPath '$($resolvedLogPath.Replace("'", "''"))' -Value "[`$timestamp] BACKGROUND STOPPED WITH ERROR `$(`$_.Exception.Message)" -Encoding UTF8
    exit 1
}
"@

Add-LauncherLog "Starting Tesla official find-part background worker. browser=$Browser batch_size=$BatchSize profile_dir=$ProfileDir headed=$Headed"

$process = Start-Process `
    -FilePath 'powershell.exe' `
    -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', $workerCommand) `
    -WorkingDirectory $root `
    -WindowStyle Hidden `
    -PassThru `
    -RedirectStandardOutput $outLog `
    -RedirectStandardError $errLog

Add-LauncherLog "Background worker process started. pid=$($process.Id)"
Write-Host "Tesla official background worker started. PID=$($process.Id)"
