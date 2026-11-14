$ErrorActionPreference = 'Stop'

$php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$mysql = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe'
$mysqlConfig = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\my.ini'
$sklad = 'C:\Projects\sklad-zapchastey'

if (-not (Get-Process mysqld -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath $mysql -ArgumentList "--defaults-file=$mysqlConfig" -WorkingDirectory (Split-Path $mysql) -WindowStyle Hidden
}

$apiIsRunning = Get-NetTCPConnection -LocalPort 8011 -State Listen -ErrorAction SilentlyContinue
if (-not $apiIsRunning) {
    Start-Process -FilePath $php -ArgumentList 'artisan','serve','--host=127.0.0.1','--port=8011' -WorkingDirectory $sklad -WindowStyle Hidden
}

Start-Process 'http://nikolacars.test/parts/'
