$ErrorActionPreference = 'Stop'
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$relative = '.codex-tmp/tree-e2e/preview-' + [guid]::NewGuid().ToString('N')
$runtime = Join-Path $root $relative
foreach ($directory in @('bootstrap','storage/logs','storage/framework/views','storage/framework/cache/data','storage/framework/sessions')) {
    $created = New-Item -ItemType Directory -Path (Join-Path $runtime $directory) -Force
    $created.Attributes = $created.Attributes -band (-bnot [IO.FileAttributes]::ReadOnly)
}
$settings = @{
    NC_TREE_E2E='1'; NC_TREE_E2E_RUNTIME=$runtime; NC_TREE_BACKEND='C:/Projects/sklad-zapchastey';
    APP_ENV='testing'; APP_DEBUG='false'; APP_URL='http://127.0.0.1:4336'; ASSET_URL='http://127.0.0.1:4336';
    APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    CACHE_STORE='array'; SESSION_DRIVER='array'; QUEUE_CONNECTION='sync'; MAIL_MAILER='array';
    DB_CONNECTION='sqlite'; DB_DATABASE=':memory:'; DB_URL=''; LOG_CHANNEL='stderr';
    SKLAD_STOREFRONT_URL='http://127.0.0.1:9/api/storefront/v1'; SKLAD_STOREFRONT_TOKEN='synthetic-tree-fixture';
    TELEGRAM_BOT_TOKEN=''; TELEGRAM_CHAT_ID=''; LARAVEL_STORAGE_PATH=(Join-Path $runtime 'storage');
    VIEW_COMPILED_PATH=(Join-Path $runtime 'storage/framework/views')
}
foreach ($name in @('CONFIG','SERVICES','PACKAGES','ROUTES','EVENTS')) {
    $settings['APP_'+$name+'_CACHE'] = $relative+'/bootstrap/'+$name.ToLower()+'.php'
}
foreach ($entry in $settings.GetEnumerator()) { [Environment]::SetEnvironmentVariable($entry.Key,$entry.Value,'Process') }
Set-Location -LiteralPath $root
Write-Output ('Isolated tree preview: http://127.0.0.1:4336/parts/elektrika/ ; evidence '+$runtime)
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' -S 127.0.0.1:4336 -t public tests/e2e/tree-router.php
exit $LASTEXITCODE
