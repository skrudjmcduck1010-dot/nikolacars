$ErrorActionPreference = "Continue"

$ProjectRoot = Split-Path -Parent $PSScriptRoot
$Php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
$Log = Join-Path $ProjectRoot "storage\logs\rebuild-donor-25-fixed.log"

Set-Location $ProjectRoot

function Write-Log($Message) {
    $Line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Add-Content -Path $Log -Value $Line
}

Write-Log "Donor #25 fixed rebuild start"

& $Php artisan donor-cars:download-official-vin-cdp 25 --vin=7SAYGDEE0PA189237 --sleep-ms=2500 --show-progress *>> $Log
$ExitCode = $LASTEXITCODE

Write-Log "Donor #25 fixed rebuild finish exit=$ExitCode"
