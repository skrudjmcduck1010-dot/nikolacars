<?php

// No framework bootstrap in the parent. Every child gets its own cache/storage/environment.
$root = dirname(__DIR__);
$scripts = [
    'category-levels-regression.php', 'diagnostics-page-regression.php',
    'front-controller-regression.php', 'home-service-regression.php',
    'language-switch-regression.php', 'merchant-policy-regression.php',
    'product-delivery-layout-regression.php', 'service-page-regression.php',
    'storefront-cache-regression.php',
];
$sentinel = '__NC_REGRESSION_COMPLETED__';
if (($argv[1] ?? '') === '--child') {
    $script = $argv[2] ?? '';
    try {
        $childRuntime = realpath((string) getenv('NC_REGRESSION_RUNTIME'));
        $childParent = realpath($root.'/.codex-tmp/compat-regressions');
        if (getenv('APP_ENV') !== 'testing' || !$childRuntime || !$childParent
            || !str_starts_with(strtolower($childRuntime), strtolower($childParent).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Fresh isolated child runtime required.');
        }
        if ($script === '__failure_probe__') {
            require $root.'/vendor/autoload.php';
            $app = require $root.'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            throw new RuntimeException('Intentional failure after Laravel handler installation');
        }
        if (!in_array($script, $scripts, true)) {
            throw new RuntimeException('Unknown regression script.');
        }
        require __DIR__.'/'.$script;
        echo $sentinel."\n";
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'REGRESSION FAILED: '.$exception->getMessage()."\n");
        exit(1);
    }
}
$parent = $root.'/.codex-tmp/compat-regressions';
if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
    throw new RuntimeException('Cannot create isolated regression directory.');
}
$run = $parent.'/run-'.bin2hex(random_bytes(6));
if (!mkdir($run, 0777, true)) {
    throw new RuntimeException('Cannot create fresh isolated runtime.');
}
$results = [];
$failed = false;
foreach (array_merge(['__failure_probe__'], $scripts) as $index => $script) {
    $relative = '.codex-tmp/compat-regressions/'.basename($run).'/case-'.$index;
    $runtime = $root.'/'.$relative;
    foreach (['bootstrap', 'storage/logs', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions'] as $directory) {
        if (!mkdir($runtime.'/'.$directory, 0777, true) && !is_dir($runtime.'/'.$directory)) {
            throw new RuntimeException('Cannot create isolated child directory.');
        }
    }
    $env = array_replace(getenv(), [
        'NC_REGRESSION_RUNTIME' => $runtime,
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:4327',
        'APP_MAINTENANCE_DRIVER' => 'file',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'ASSET_URL' => 'http://127.0.0.1:4327', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'stderr',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
        'SKLAD_STOREFRONT_URL' => 'http://127.0.0.1:9/api/storefront/v1',
        'SKLAD_STOREFRONT_TOKEN' => 'synthetic-regression-fixture',
        'TELEGRAM_BOT_TOKEN' => '', 'TELEGRAM_CHAT_ID' => '',
        'LARAVEL_STORAGE_PATH' => $runtime.'/storage', 'VIEW_COMPILED_PATH' => $runtime.'/storage/framework/views',
        'APP_CONFIG_CACHE' => $relative.'/bootstrap/config.php',
        'APP_SERVICES_CACHE' => $relative.'/bootstrap/services.php',
        'APP_PACKAGES_CACHE' => $relative.'/bootstrap/packages.php',
        'APP_ROUTES_CACHE' => $relative.'/bootstrap/routes.php',
        'APP_EVENTS_CACHE' => $relative.'/bootstrap/events.php',
    ]);
    $stdout = $runtime.'/stdout.log';
    $stderr = $runtime.'/stderr.log';
    $process = proc_open(
        [PHP_BINARY, '-d', 'opcache.enable_cli=0', __FILE__, '--child', $script],
        [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
        $pipes, $root, $env, ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start isolated regression child.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $output = file_get_contents($stdout);
    $completed = str_contains($output, $sentinel);
    $probe = $script === '__failure_probe__';
    $pass = $probe ? ($exit === 1 && !$completed && str_contains(file_get_contents($stderr), 'Intentional failure'))
        : ($exit === 0 && $completed);
    $results[] = ['file' => $script, 'exit_code' => $exit, 'completed' => $completed, 'passed' => $pass, 'stdout' => $stdout, 'stderr' => $stderr];
    echo ($pass ? 'PASS ' : 'FAIL ').$script.($probe ? ' (outer catch detects Laravel-handled failures)' : '')."\n";
    if (!$pass) {
        $failed = true;
        fwrite(STDERR, file_get_contents($stderr));
    }
}
file_put_contents($run.'/results.json', json_encode(['passed' => !$failed, 'results' => $results], JSON_PRETTY_PRINT));
echo 'Regression evidence: '.$run."\n";
exit($failed ? 1 : 0);
