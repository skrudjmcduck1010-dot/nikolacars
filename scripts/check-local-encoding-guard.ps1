param()

$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$requiredFiles = @(
    '.githooks/pre-commit',
    'scripts/encoding-guard.php',
    'scripts/install-git-hooks.php',
    'app/Support/SourceEncodingScanner.php',
    'app/Console/Commands/AuditTextEncoding.php'
)

$missing = @()
foreach ($relative in $requiredFiles) {
    $path = Join-Path $root $relative
    if (!(Test-Path $path -PathType Leaf)) {
        $missing += $relative
    }
}

if ($missing.Count -gt 0) {
    throw "Local encoding guard files are missing after live pull: $($missing -join ', ')"
}

$php = if (![string]::IsNullOrWhiteSpace($env:LOCAL_PHP) -and (Test-Path $env:LOCAL_PHP)) {
    (Resolve-Path $env:LOCAL_PHP).Path
} else {
    $laragon = 'C:\laragon\bin\php'
    if (Test-Path $laragon) {
        $candidate = Get-ChildItem $laragon -Recurse -Filter 'php.exe' -ErrorAction SilentlyContinue |
            Sort-Object FullName -Descending |
            Select-Object -First 1

        if ($candidate) {
            $candidate.FullName
        }
    }
}

if ([string]::IsNullOrWhiteSpace($php)) {
    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($command) {
        $php = $command.Source
    }
}

if ([string]::IsNullOrWhiteSpace($php)) {
    throw 'Unable to find PHP. Set LOCAL_PHP to php.exe or add PHP to PATH.'
}

& $php (Join-Path $root 'scripts/install-git-hooks.php')
if ($LASTEXITCODE -ne 0) {
    throw 'Git hook reinstall failed.'
}

& $php (Join-Path $root 'scripts/encoding-guard.php')
if ($LASTEXITCODE -ne 0) {
    throw 'Encoding guard audit failed.'
}

Write-Output 'Local encoding guard is present and active.'
