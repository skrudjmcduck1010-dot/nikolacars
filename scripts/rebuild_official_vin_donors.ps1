$ErrorActionPreference = 'Continue'

$ProjectRoot = 'C:\Users\skrud\OneDrive\Projects\sklad-zapchastey'
$Php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$Log = Join-Path $ProjectRoot 'storage\logs\rebuild-official-vin-donors.log'

Set-Location $ProjectRoot

$donors = @(
    @{ id = 2; vin = '5YJ3E1EB9MF855978' },
    @{ id = 4; vin = '5YJ3E1EA0LF611657' },
    @{ id = 6; vin = '5YJYGDED4MF109750' },
    @{ id = 13; vin = '5YJSA1E19HF202779' },
    @{ id = 15; vin = '5YJ3E1EA7PF407816' },
    @{ id = 16; vin = '5YJ3E1EBXJF117800' },
    @{ id = 17; vin = '5YJ3E1EB3JF151075' },
    @{ id = 18; vin = '5YJ3E1EB4KF517627' },
    @{ id = 20; vin = '5YJSA1E20GF129213' },
    @{ id = 21; vin = '5YJ3E1EA3JF030277' },
    @{ id = 22; vin = '5YJSA1H1XEFP59563' },
    @{ id = 23; vin = '5YJYGDEE8LF031402' },
    @{ id = 24; vin = '7SAYGDEE4NF447985' },
    @{ id = 25; vin = '7SAYGDEE0PA189237' },
    @{ id = 26; vin = '5YJYGDEE3MF214952' },
    @{ id = 27; vin = '5YJ3E1EA6MF048163' },
    @{ id = 28; vin = '5YJ3E1EB8JF091651' },
    @{ id = 29; vin = '5YJYGDEE5MF081658' },
    @{ id = 30; vin = '5YJYGAEE4MF266931' }
)

function Write-Step($message) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $message
    Add-Content -Path $Log -Value $line
}

Write-Step 'Started official VIN donor rebuild.'

foreach ($donor in $donors) {
    Write-Step "Donor #$($donor.id) VIN $($donor.vin): start"
    & $Php artisan donor-cars:download-official-vin-cdp $donor.id --vin=$($donor.vin) --sleep-ms=1200 2>&1 |
        ForEach-Object { Add-Content -Path $Log -Value $_ }
    Write-Step "Donor #$($donor.id) VIN $($donor.vin): finish exit=$LASTEXITCODE"
}

Write-Step 'Cleaning non-recommended VIN-generated products.'
& $Php artisan donor-cars:cleanup-vin-nonrecommended-products 2>&1 |
    ForEach-Object { Add-Content -Path $Log -Value $_ }

Write-Step 'Starting Tesla official part image batches.'
for ($i = 1; $i -le 20; $i++) {
    Write-Step "Part image batch $i start"
    & $Php artisan parts:download-tesla-official-part-images --limit=300 2>&1 |
        ForEach-Object { Add-Content -Path $Log -Value $_ }
    Write-Step "Part image batch $i finish exit=$LASTEXITCODE"
}

Write-Step 'Finished official VIN donor rebuild.'
