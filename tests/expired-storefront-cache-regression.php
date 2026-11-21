<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

$root = dirname(__DIR__);
$runtime = realpath((string) getenv('NC_REGRESSION_RUNTIME'));
$allowed = realpath($root.'/.codex-tmp/compat-regressions');
if (getenv('APP_ENV') !== 'testing' || ! $runtime || ! $allowed
    || ! str_starts_with(strtolower($runtime), strtolower($allowed).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('A separate regression runtime is required.');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'file', 'cache.stores.file.path' => storage_path('framework/cache/data')]);
$check = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
$call = static function (bool $dry = false): array {
    $exit = Artisan::call('storefront:prune-expired-cache', $dry ? ['--dry-run' => true] : []);
    if ($exit !== 0) {
        throw new RuntimeException('Artisan failed: '.Artisan::output());
    }
    return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
};
$cache = realpath(storage_path('framework/cache/data'));
$state = storage_path('framework/storefront-cache-prune.json');
$now = time();
$old = $now - 6 * 86400;
$expired = $now - 5 * 86400;
$response = serialize(['status' => 200, 'data' => ['id' => 12345]]);
$sample = function (int $n, string $content, int $mtime, string $prefix = '0001') use ($cache): string {
    $key = $prefix.str_pad(dechex($n), 36, '0', STR_PAD_LEFT);
    $dir = $cache.'/'.substr($key, 0, 2).'/'.substr($key, 2, 2);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $path = $dir.'/'.$key;
    file_put_contents($path, $content);
    touch($path, $mtime);
    return $path;
};

$stale = $sample(1, $expired.$response, $old);
$metadata = $sample(2, $expired.serialize($old), $old);
$safe = [
    $sample(3, ($now + 1000).$response, $old),        // Live payload.
    $sample(4, ($now - 900).$response, $old),         // In grace after hard expiry.
    $sample(5, $expired.$response, $now),             // Concurrently rewritten.
    $sample(6, '9999999999'.$response, $old),         // Forever.
    $sample(7, $expired.serialize('lock-owner'), $old),
    $sample(8, $expired.serialize(['unrelated' => 'payload']), $old),
    $sample(9, $expired.serialize($old + 100), $old), // Ordinary integer.
    $sample(10, $expired.serialize($old), $old + 100),
    $sample(11, 'not-a-time'.$response, $old),
    $sample(12, $expired.serialize(['status' => 503, 'data' => null]), $old),
];
$later = $sample(13, $expired.$response, $old, '1001');
$session = storage_path('framework/sessions/example');
file_put_contents($session, $expired.$response);
$held = $sample(14, $expired.$response, $old);
$busy = fopen($held, 'rb');
flock($busy, LOCK_EX);
$first = $call(true);
$check($first['eligible'] === 2 && $first['removed'] === 0 && ! file_exists($state), 'Dry-run must retain all contents and cursor.');
$ran = $call();
$check($ran['removed'] === 2 && ! file_exists($stale) && ! file_exists($metadata), 'Remove only exact old payload and timestamp companion.');
$check(file_exists($later) && file_exists($session) && file_exists($held), 'Future bucket, session and locked file must survive.');
foreach ($safe as $path) {
    $check(file_exists($path), 'Unrelated, recent, fresh, malformed or lock value removed.');
}
flock($busy, LOCK_UN);
fclose($busy);
$lock = fopen($state, 'r+b');
flock($lock, LOCK_EX);
$check($call()['status'] === 'already_running' && file_exists($later), 'Second invocation must respect an active lock.');
fclose($lock);
$check($call()['removed'] === 1 && ! file_exists($later), 'Next run must advance to the next shard.');
$inside = '2000';
for ($n = 0; $n <= 2000; $n++) {
    $last = $sample($n, $expired.$response, $old, $inside);
}
$full = $call();
$check($full['removed'] === 2000 && $full['next_bucket'] === hexdec($inside) && $full['after'] !== ''
    && file_exists($last), 'Full batch must stop and persist its in-bucket cursor.');
$check($call()['removed'] === 1 && ! file_exists($last), 'Next call must resume at the remaining file.');
config(['cache.stores.file.path' => storage_path('framework/sessions')]);
Cache::forgetDriver('file');
$check(Artisan::call('storefront:prune-expired-cache') !== 0 && file_exists($session), 'Refuse alternate file store even if its contents look like cache.');
config(['cache.default' => 'array']);
$check(Artisan::call('storefront:prune-expired-cache') !== 0 && file_exists($held), 'A different cache store must fail closed.');

echo "FileStore cursor, real Artisan command, expiration grace, type, locks, sessions and preview passed.\n";
