# Tesla Official Find-Part Parsing

This workflow enriches `tesla_official` catalog rows through the logged-in
Tesla Find Part page. The reliable path is:

1. Log in with the installed desktop Firefox using the project profile.
2. Close that Firefox window so SQLite cookies/storage are flushed.
3. Copy only session and storage files into the Playwright Firefox profile.
4. Verify one part number.
5. Start the background loop.

Do not use the Playwright `firefox-login` helper first. It can trigger Tesla
CAPTCHA. Do not copy the entire desktop Firefox profile into the Playwright
profile; bundled Playwright Firefox can reject profiles written by a newer
installed Firefox.

## Profiles

Desktop login profile:

```text
storage/app/tesla-official-firefox-profile
```

Playwright parsing profile:

```text
storage/app/tesla-official-firefox-playwright-profile
```

## Login

Open the project profile with installed Firefox:

```powershell
Start-Process -FilePath 'C:\Program Files\Mozilla Firefox\firefox.exe' -ArgumentList @('-no-remote','-profile','C:\Users\skrud\OneDrive\Projects\sklad-zapchastey\storage\app\tesla-official-firefox-profile','https://parts.tesla.com/en-US/find-part')
```

Log in to Tesla in that Firefox window, confirm that the Find Part page opens,
then close the Firefox window before copying session files.

Check whether that profile is still open:

```powershell
Get-CimInstance Win32_Process |
    Where-Object { $_.Name -eq 'firefox.exe' -and $_.CommandLine -match 'tesla-official-firefox-profile' } |
    Select-Object ProcessId,Name,CommandLine
```

Firefox may leave a stale `parent.lock` file after closing. Do not copy that
file; use the process check above to decide whether the profile is still open.

## Copy Session To Playwright

After installed Firefox is closed, copy only the login/session storage:

```powershell
$Source = 'C:\Users\skrud\OneDrive\Projects\sklad-zapchastey\storage\app\tesla-official-firefox-profile'
$Target = 'C:\Users\skrud\OneDrive\Projects\sklad-zapchastey\storage\app\tesla-official-firefox-playwright-profile'

New-Item -ItemType Directory -Force -Path $Target | Out-Null

$Files = @(
    'cookies.sqlite',
    'cookies.sqlite-shm',
    'cookies.sqlite-wal',
    'storage.sqlite',
    'storage.sqlite-shm',
    'storage.sqlite-wal',
    'webappsstore.sqlite',
    'webappsstore.sqlite-shm',
    'webappsstore.sqlite-wal',
    'sessionstore.jsonlz4',
    'sessionCheckpoints.json',
    'SiteSecurityServiceState.bin',
    'permissions.sqlite'
)

foreach ($File in $Files) {
    $Path = Join-Path $Source $File
    if (Test-Path -LiteralPath $Path) {
        Copy-Item -LiteralPath $Path -Destination (Join-Path $Target $File) -Force
    }
}

Copy-Item -LiteralPath (Join-Path $Source 'storage') -Destination $Target -Recurse -Force
Copy-Item -LiteralPath (Join-Path $Source 'sessionstore-backups') -Destination $Target -Recurse -Force
```

If `cookies.sqlite-wal`, `storage.sqlite-wal`, or `webappsstore.sqlite-wal`
still has fresh non-zero content after Firefox is closed, keep copying the WAL
file too; it may contain uncheckpointed session data.

## Verify One Part

Run one explicit part before starting the loop. Use a known unchecked or recently
failed part number:

```powershell
php artisan parts:enrich-tesla-official-cdp-find-part --browser=firefox --profile-dir=storage/app/tesla-official-firefox-playwright-profile --part-number=1089189-07-B --delay-ms=1500 --page-wait-ms=6000
```

On this Laragon machine, use
`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` if `php` is not on
`PATH`.

Successful output starts with:

```text
Enriched Tesla official items through logged-in find-part.
```

If the row is marked `auth_required`, `security_blocked`, or `api_error`, repeat
the desktop Firefox login and copy steps before starting the long loop.

## Start Background Parsing

Use the background launcher for long runs:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\start-tesla-official-find-part-background.ps1
```

The launcher defaults to:

```text
browser=firefox
profile_dir=storage\app\tesla-official-firefox-playwright-profile
batch_size=8
```

The loop writes:

```text
storage/logs/tesla-official-find-part-loop.log
storage/logs/tesla-official-find-part-loop.process.out.log
storage/logs/tesla-official-find-part-loop.process.err.log
```

Useful checks:

```powershell
Get-Content storage\logs\tesla-official-find-part-loop.log -Tail 120

Get-CimInstance Win32_Process |
    Where-Object { ($_.Name -match 'powershell|php|firefox') -and ($_.CommandLine -match 'tesla-official-find-part|enrich-tesla-official|tesla-official-firefox-playwright') } |
    Select-Object ProcessId,Name,CommandLine
```

The admin Tesla.com log page also reads this log and shows `Stopped with error`
when the worker writes `BACKGROUND STOPPED WITH ERROR`.

## Queue Snapshot

Count unchecked Tesla official rows:

```powershell
php artisan tinker --execute "echo \App\Models\PartCatalogItem::query()->where('source','tesla_official')->where('source_url','like','https://parts.tesla.com/%')->whereNotNull('part_number')->where(function(`$q){`$q->whereNull('raw_attributes')->orWhere('raw_attributes','not like','%tesla_part_search_checked_at%');})->count();"
```

Count checked rows:

```powershell
php artisan tinker --execute "echo \App\Models\PartCatalogItem::query()->where('source','tesla_official')->where('source_url','like','https://parts.tesla.com/%')->whereNotNull('part_number')->where('raw_attributes','like','%tesla_part_search_checked_at%')->count();"
```
