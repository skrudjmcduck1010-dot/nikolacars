$ErrorActionPreference = "Continue"

$Php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$Log = Join-Path $ProjectRoot "storage\logs\retry-failed-official-vin-donors.log"

Set-Location $ProjectRoot

$Donors = @(
    @{ Id = 23; Vin = "5YJYGDEE8LF031402" },
    @{ Id = 27; Vin = "5YJ3E1EA6MF048163" },
    @{ Id = 28; Vin = "5YJ3E1EB8JF091651" },
    @{ Id = 29; Vin = "5YJYGDEE5MF081658" },
    @{ Id = 30; Vin = "5YJYGAEE4MF266931" }
)

function Write-Log($Message) {
    $Line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Add-Content -Path $Log -Value $Line
}

Write-Log "Starting failed official VIN donor retry."

foreach ($Donor in $Donors) {
    Write-Log ("Donor #{0} VIN {1}: start" -f $Donor.Id, $Donor.Vin)

    & $Php artisan donor-cars:download-official-vin-cdp $Donor.Id --vin=$($Donor.Vin) --sleep-ms=2500 --show-progress *> $null
    $ExitCode = $LASTEXITCODE

    Write-Log ("Donor #{0} VIN {1}: finish exit={2}" -f $Donor.Id, $Donor.Vin, $ExitCode)
    Start-Sleep -Seconds 20
}

Write-Log "Cleaning non-recommended VIN-generated products."
& $Php artisan donor-cars:cleanup-vin-nonrecommended-products *> $null

Write-Log "Downloading part images for retry batch."
for ($i = 1; $i -le 8; $i++) {
    Write-Log ("Part image retry batch {0} start" -f $i)
    & $Php artisan parts:download-tesla-official-part-images --limit=300 *> $null
    Write-Log ("Part image retry batch {0} finish exit={1}" -f $i, $LASTEXITCODE)
}

Write-Log "Finished failed official VIN donor retry."
