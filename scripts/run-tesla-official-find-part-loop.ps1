param(
    [int] $BatchSize = 6,
    [int] $DelayMs = 6000,
    [int] $PageWaitMs = 9000,
    [int] $BatchTimeoutSec = 420,
    [int] $SleepOnFailureSec = 120,
    [int] $SleepBetweenBatchesSec = 5,
    [int] $MaxBatches = 0,
    [int] $ClaimTtlMin = 30,
    [string] $Browser = 'cdp',
    [string[]] $BrowserFallbacks = @(),
    [string] $ProfileDir = '',
    [switch] $RetryApiErrors,
    [switch] $ContinueOnApiError,
    [switch] $Headed,
    [string] $Cdp = 'http://127.0.0.1:9222',
    [string] $Php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe',
    [string] $LogPath = 'storage\logs\tesla-official-find-part-loop.log'
)

$ErrorActionPreference = 'Stop'
$script:WorkerId = "tesla-loop-$PID-$([guid]::NewGuid().ToString('N'))"

$BrowserFallbacks = @(
    $BrowserFallbacks |
        ForEach-Object { $_ -split ',' } |
        ForEach-Object { $_.Trim() } |
        Where-Object { $_ -ne '' }
)
$script:BrowserCycle = @(
    @($Browser) + $BrowserFallbacks |
        ForEach-Object { $_ -split ',' } |
        ForEach-Object { $_.Trim().ToLowerInvariant() } |
        Where-Object { $_ -ne '' } |
        Select-Object -Unique
)
if ($script:BrowserCycle.Count -eq 0) {
    $script:BrowserCycle = @($Browser)
}
$script:CurrentBrowserIndex = 0

function Get-CurrentBrowser {
    return $script:BrowserCycle[$script:CurrentBrowserIndex]
}

function Move-ToNextBrowser {
    param([string] $Reason)

    $oldBrowser = Get-CurrentBrowser
    $script:CurrentBrowserIndex = ($script:CurrentBrowserIndex + 1) % $script:BrowserCycle.Count
    $newBrowser = Get-CurrentBrowser
    Write-LoopLog "Switching browser ${oldBrowser} -> ${newBrowser}: $Reason"
}

function Set-CurrentBrowser {
    param([string] $BrowserName)

    $index = [Array]::IndexOf($script:BrowserCycle, $BrowserName)
    if ($index -ge 0) {
        $script:CurrentBrowserIndex = $index
    }
}

function Write-LoopLog {
    param([string] $Message)

    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    $line = "[$timestamp] $Message"
    Write-Host $line
    Add-Content -LiteralPath $script:ResolvedLogPath -Value $line -Encoding UTF8
}

function Invoke-ArtisanJson {
    param([string] $Code)

    $output = & $Php artisan tinker --execute $Code
    if ($LASTEXITCODE -ne 0) {
        throw "artisan tinker failed with exit code $LASTEXITCODE"
    }

    return ($output -join "`n") | ConvertFrom-Json
}

function Get-NextItems {
$whereMode = if ($RetryApiErrors) {
@"
    ->where(function (`$query) {
        `$query->where('raw_attributes', 'like', '%\"official_part_match_status\":\"api_error\"%')
            ->orWhere('raw_attributes', 'like', '%\"official_part_match_status\":\"auth_required\"%')
            ->orWhere('raw_attributes', 'like', '%\"official_part_match_status\":\"security_blocked\"%');
    })
"@
    } else {
@"
    ->where(function (`$query) {
        `$query->whereNull('raw_attributes')
            ->orWhere('raw_attributes', 'not like', '%tesla_part_search_checked_at%');
    })
"@
    }

    $code = @"
`$worker = '$($script:WorkerId)';
`$claimTtlMinutes = $ClaimTtlMin;
`$items = Illuminate\Support\Facades\DB::transaction(function () use (`$worker, `$claimTtlMinutes) {
    `$claimCutoff = now()->subMinutes(`$claimTtlMinutes)->toIso8601String();
    `$items = App\Models\PartCatalogItem::query()
        ->where('source', 'tesla_official')
        ->where('source_url', 'like', 'https://parts.tesla.com/%')
        ->whereNotNull('part_number')
$whereMode
        ->where(function (`$query) use (`$claimCutoff) {
            `$query->whereNull('raw_attributes')
                ->orWhere('raw_attributes', 'not like', '%tesla_part_search_claimed_at%')
                ->orWhereRaw('json_unquote(json_extract(raw_attributes, ?)) < ?', ['`$.tesla_part_search_claimed_at', `$claimCutoff]);
        })
        ->orderBy('id')
        ->limit($BatchSize)
        ->lockForUpdate()
        ->get(['id', 'part_number', 'raw_attributes']);

    foreach (`$items as `$item) {
        `$raw = `$item->raw_attributes instanceof ArrayObject
            ? `$item->raw_attributes->getArrayCopy()
            : (array) (`$item->raw_attributes ?? []);
        `$raw['tesla_part_search_claimed_at'] = now()->toIso8601String();
        `$raw['tesla_part_search_claimed_by'] = `$worker;
        `$item->forceFill(['raw_attributes' => `$raw])->save();
    }

    return `$items;
});
foreach (`$items as `$item) {
    echo `$item->id.'|'.`$item->part_number.PHP_EOL;
}
"@

    $output = & $Php artisan tinker --execute $code
    if ($LASTEXITCODE -ne 0) {
        throw "artisan tinker failed with exit code $LASTEXITCODE"
    }

    return @(
        $output |
            Where-Object { $_ -is [string] -and $_.Trim() -ne '' -and $_.Contains('|') } |
            ForEach-Object {
                $parts = $_.Split('|', 2)
                [pscustomobject] @{
                    id = [int] $parts[0]
                    part_number = $parts[1]
                }
            }
    )
}

function Get-ProgressSnapshot {
$uncheckedWhere = if ($RetryApiErrors) {
@"
    ->where(function (`$query) {
        `$query->where('raw_attributes', 'like', '%\"official_part_match_status\":\"api_error\"%')
            ->orWhere('raw_attributes', 'like', '%\"official_part_match_status\":\"auth_required\"%')
            ->orWhere('raw_attributes', 'like', '%\"official_part_match_status\":\"security_blocked\"%');
    })
"@
    } else {
@"
    ->where(function (`$query) {
        `$query->whereNull('raw_attributes')
            ->orWhere('raw_attributes', 'not like', '%tesla_part_search_checked_at%');
    })
"@
    }

    $code = @"
`$checked = App\Models\PartCatalogItem::query()
    ->where('source', 'tesla_official')
    ->where('source_url', 'like', 'https://parts.tesla.com/%')
    ->where('raw_attributes', 'like', '%tesla_part_search_checked_at%')
    ->where('raw_attributes', 'not like', '%\"official_part_match_status\":\"api_error\"%')
    ->where('raw_attributes', 'not like', '%\"official_part_match_status\":\"auth_required\"%')
    ->where('raw_attributes', 'not like', '%\"official_part_match_status\":\"security_blocked\"%')
    ->count();
`$unchecked = App\Models\PartCatalogItem::query()
    ->where('source', 'tesla_official')
    ->where('source_url', 'like', 'https://parts.tesla.com/%')
    ->whereNotNull('part_number')
$uncheckedWhere
    ->count();
echo json_encode(['checked' => `$checked, 'unchecked' => `$unchecked], JSON_UNESCAPED_UNICODE);
"@

    return Invoke-ArtisanJson -Code $code
}

function Get-BatchRetryErrors {
    param([array] $Items)

    if ($Items.Count -eq 0) {
        return @()
    }

    $ids = ($Items | ForEach-Object { [int] $_.id }) -join ','
    $code = @"
`$items = App\Models\PartCatalogItem::query()
    ->whereIn('id', [$ids])
    ->get(['id', 'part_number', 'raw_attributes']);
foreach (`$items as `$item) {
    `$status = data_get(`$item->raw_attributes, 'official_part_match_status');
    if (in_array(`$status, ['api_error', 'auth_required', 'security_blocked'], true)) {
        echo `$item->id.'|'.`$item->part_number.'|'.`$status.PHP_EOL;
    }
}
"@

    $output = & $Php artisan tinker --execute $code
    if ($LASTEXITCODE -ne 0) {
        throw "artisan tinker failed with exit code $LASTEXITCODE"
    }

    return @(
        $output |
            Where-Object { $_ -is [string] -and $_.Trim() -ne '' -and $_.Contains('|') } |
            ForEach-Object {
                $parts = $_.Split('|', 3)
                [pscustomobject] @{
                    id = [int] $parts[0]
                    part_number = $parts[1]
                    status = if ($parts.Count -gt 2) { $parts[2] } else { 'api_error' }
                }
            }
    )
}

function Mark-BatchAsApiError {
    param(
        [array] $Items,
        [string] $Reason
    )

    if ($Items.Count -eq 0) {
        return
    }

    $ids = ($Items | ForEach-Object { [int] $_.id }) -join ','
    $escapedReason = $Reason.Replace('\', '\\').Replace("'", "\'")
    $code = @"
`$items = App\Models\PartCatalogItem::query()
    ->whereIn('id', [$ids])
    ->get();
foreach (`$items as `$item) {
    `$raw = `$item->raw_attributes instanceof \ArrayObject
        ? `$item->raw_attributes->getArrayCopy()
        : (array) `$item->raw_attributes;
    `$raw['official_part_match_status'] = 'api_error';
    `$hasSavedOfficialCatalogData = ! empty(`$raw['official_catalog_occurrences'] ?? [])
        || filled(`$raw['catalog_external_reference'] ?? null)
        || filled(`$raw['category_external_reference'] ?? null)
        || filled(`$raw['subcategory_external_reference'] ?? null)
        || filled(`$raw['system_group_external_reference'] ?? null);
    `$raw['official_presence'] = `$hasSavedOfficialCatalogData ? 'official_catalog_exact' : 'part_search_api_error';
    `$raw['tesla_part_search_error'] = '$escapedReason';
    `$raw['tesla_part_search_checked_at'] = now()->toIso8601String();
    `$item->forceFill([
        'raw_attributes' => `$raw,
        'source_updated_at' => now(),
    ])->save();
    echo `$item->id.'|'.`$item->part_number.PHP_EOL;
}
"@

    $output = & $Php artisan tinker --execute $code
    if ($LASTEXITCODE -ne 0) {
        throw "artisan tinker failed with exit code $LASTEXITCODE"
    }

    $marked = @(
        $output |
            Where-Object { $_ -is [string] -and $_.Trim() -ne '' -and $_.Contains('|') }
    )
    Write-LoopLog "Marked batch as api_error ($Reason): $($marked -join ', ')"
}

function Release-BatchClaims {
    param([array] $Items)

    if ($Items.Count -eq 0) {
        return
    }

    $ids = ($Items | ForEach-Object { [int] $_.id }) -join ','
    $code = @"
`$worker = '$($script:WorkerId)';
`$items = App\Models\PartCatalogItem::query()
    ->whereIn('id', [$ids])
    ->get(['id', 'part_number', 'raw_attributes']);
foreach (`$items as `$item) {
    `$raw = `$item->raw_attributes instanceof ArrayObject
        ? `$item->raw_attributes->getArrayCopy()
        : (array) (`$item->raw_attributes ?? []);
    if ((`$raw['tesla_part_search_claimed_by'] ?? null) !== `$worker) {
        continue;
    }
    unset(`$raw['tesla_part_search_claimed_at'], `$raw['tesla_part_search_claimed_by']);
    `$item->forceFill(['raw_attributes' => `$raw ?: null])->save();
    echo `$item->id.'|'.`$item->part_number.PHP_EOL;
}
"@

    $output = & $Php artisan tinker --execute $code
    if ($LASTEXITCODE -ne 0) {
        throw "artisan tinker failed with exit code $LASTEXITCODE"
    }

    $released = @(
        $output |
            Where-Object { $_ -is [string] -and $_.Trim() -ne '' -and $_.Contains('|') }
    )
    if ($released.Count -gt 0) {
        Write-LoopLog "Released batch claims: $($released -join ', ')"
    }
}

function Invoke-Batch {
    param(
        [array] $Items,
        [string] $BrowserName = $Browser
    )

    $arguments = @(
        'artisan',
        'parts:enrich-tesla-official-cdp-find-part',
        "--delay-ms=$DelayMs",
        "--page-wait-ms=$PageWaitMs",
        "--browser=$BrowserName",
        "--cdp=$Cdp"
    )

    if ($RetryApiErrors) {
        $arguments += '--retry-checked'
    }
    if ($Headed) {
        $arguments += '--headed'
    }
    if ($ProfileDir.Trim() -ne '') {
        $arguments += "--profile-dir=$ProfileDir"
    }

    foreach ($item in $Items) {
        $arguments += "--item-id=$($item.id)"
    }

    $stdoutPath = [System.IO.Path]::GetTempFileName()
    $stderrPath = [System.IO.Path]::GetTempFileName()

    try {
        $process = Start-Process `
            -FilePath $Php `
            -ArgumentList $arguments `
            -NoNewWindow `
            -PassThru `
            -RedirectStandardOutput $stdoutPath `
            -RedirectStandardError $stderrPath

        $finished = Wait-Process -Id $process.Id -Timeout $BatchTimeoutSec -ErrorAction SilentlyContinue

        if ($null -eq $finished -and -not $process.HasExited) {
            Stop-Process -Id $process.Id -Force
            Write-LoopLog "Batch timeout after ${BatchTimeoutSec}s. Killed process $($process.Id)."
            return $false
        }

        $stdout = Get-Content -LiteralPath $stdoutPath -Raw -ErrorAction SilentlyContinue
        $stderr = Get-Content -LiteralPath $stderrPath -Raw -ErrorAction SilentlyContinue

        if ($stdout) {
            Add-Content -LiteralPath $script:ResolvedLogPath -Value $stdout.Trim() -Encoding UTF8
        }

        if ($stderr) {
            Add-Content -LiteralPath $script:ResolvedLogPath -Value $stderr.Trim() -Encoding UTF8
        }

        $process.WaitForExit()
        $process.Refresh()
        $exitCode = $process.ExitCode
        if ($null -eq $exitCode) {
            $exitCode = 0
        }

        $combinedOutput = "$stdout`n$stderr"
        if ($combinedOutput -and (
            $combinedOutput.Contains('triggerUncaughtException') `
                -or $combinedOutput.Contains('TimeoutError') `
                -or $combinedOutput.Contains('browserType.launchPersistentContext') `
                -or $combinedOutput.Contains('browserType.connectOverCDP') `
                -or $combinedOutput.Contains('Target page, context or browser has been closed')
        )) {
            Write-LoopLog "Batch failed because browser ${BrowserName} crashed or timed out."
            return $false
        }

        if ($exitCode -ne 0) {
            Write-LoopLog "Batch failed with exit code $exitCode."
            return $false
        }

        return $true
    } finally {
        Remove-Item -LiteralPath $stdoutPath, $stderrPath -Force -ErrorAction SilentlyContinue
    }
}

$script:ResolvedLogPath = Join-Path (Get-Location) $LogPath
$logDirectory = Split-Path -Parent $script:ResolvedLogPath
New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null

Write-LoopLog "Tesla official find-part loop started. batch_size=$BatchSize delay_ms=$DelayMs page_wait_ms=$PageWaitMs timeout_sec=$BatchTimeoutSec browser_cycle=$($script:BrowserCycle -join '->') profile_dir=$ProfileDir retry_api_errors=$RetryApiErrors continue_on_api_error=$ContinueOnApiError headed=$Headed"

$batchesDone = 0

while ($true) {
    if ($MaxBatches -gt 0 -and $batchesDone -ge $MaxBatches) {
        Write-LoopLog "MaxBatches reached: $MaxBatches. Stopping."
        break
    }

    try {
        $items = @(Get-NextItems)
    } catch {
        Write-LoopLog "Failed to select next items: $($_.Exception.Message). Sleeping ${SleepOnFailureSec}s."
        Start-Sleep -Seconds $SleepOnFailureSec
        continue
    }

    if ($items.Count -eq 0) {
        Write-LoopLog 'No unchecked Tesla official items left. Stopping.'
        break
    }

    $batchLabel = ($items | ForEach-Object { "$($_.id):$($_.part_number)" }) -join ', '
    $attemptItems = @($items)
    $attempts = 0
    $ok = $false
    $lastRetryErrors = @()

    while ($attempts -lt $script:BrowserCycle.Count -and $attemptItems.Count -gt 0) {
        $currentBrowser = Get-CurrentBrowser
        Write-LoopLog "Checking batch with ${currentBrowser}: $batchLabel"

        $ok = Invoke-Batch -Items $attemptItems -BrowserName $currentBrowser
        $attempts++

        if (-not $ok) {
            $lastRetryErrors = @($attemptItems | ForEach-Object {
                [pscustomobject] @{
                    id = $_.id
                    part_number = $_.part_number
                    status = 'browser_failed'
                }
            })
            Move-ToNextBrowser -Reason "browser ${currentBrowser} did not finish cleanly"
            continue
        }

        try {
            $lastRetryErrors = @(Get-BatchRetryErrors -Items $attemptItems)
        } catch {
            Write-LoopLog "Batch OK. Retryable error guard query failed: $($_.Exception.Message)"
            $lastRetryErrors = @()
        }

        if ($lastRetryErrors.Count -eq 0) {
            break
        }

        $retryErrorLabel = ($lastRetryErrors | ForEach-Object { "$($_.id):$($_.part_number)[$($_.status)]" }) -join ', '
        if (-not $ContinueOnApiError) {
            Write-LoopLog "Stopping because Tesla.com returned retryable error for current batch: $retryErrorLabel"
            $ok = $false
            break
        }

        Move-ToNextBrowser -Reason "retryable Tesla.com status from ${currentBrowser}: $retryErrorLabel"
        $attemptItems = @($lastRetryErrors)
        $ok = $false
    }

    if ($ok) {
        $batchesDone++

        try {
            $progress = Get-ProgressSnapshot
            Write-LoopLog "Batch OK. checked=$($progress.checked) unchecked=$($progress.unchecked)"
        } catch {
            Write-LoopLog "Batch OK. Progress query failed: $($_.Exception.Message)"
        }

        Start-Sleep -Seconds $SleepBetweenBatchesSec
        continue
    }

    if ($lastRetryErrors.Count -gt 0) {
        $retryErrorLabel = ($lastRetryErrors | ForEach-Object { "$($_.id):$($_.part_number)[$($_.status)]" }) -join ', '
        if ($ContinueOnApiError) {
            Write-LoopLog "Batch still has retryable errors after full browser cycle, continuing next selection: $retryErrorLabel"
        } else {
            Write-LoopLog "Batch still has retryable errors after full browser cycle: $retryErrorLabel"
        }
    }

    try {
        Release-BatchClaims -Items $items
    } catch {
        Write-LoopLog "Failed to release failed batch claims: $($_.Exception.Message)"
    }

    if ($lastRetryErrors.Count -gt 0 -and -not $ContinueOnApiError) {
        $retryErrorLabel = ($lastRetryErrors | ForEach-Object { "$($_.id):$($_.part_number)[$($_.status)]" }) -join ', '
        Write-LoopLog "BACKGROUND STOPPED WITH ERROR retryable Tesla.com status: $retryErrorLabel"
        exit 1
    }

    if ($items.Count -eq 1 -and $lastRetryErrors.Count -eq 0) {
        try {
            Mark-BatchAsApiError -Items $items -Reason 'loop_batch_failed'
        } catch {
            Write-LoopLog "Failed to mark failed single-item batch as api_error: $($_.Exception.Message)"
        }
    }

    Write-LoopLog "Batch did not finish cleanly. Sleeping ${SleepOnFailureSec}s before retrying next selection."
    Start-Sleep -Seconds $SleepOnFailureSec
}

Write-LoopLog 'Tesla official find-part loop stopped.'
