<?php

$root = dirname(__DIR__);
$runtime = realpath((string) getenv('NC_REGRESSION_RUNTIME'));
$allowed = realpath($root.'/.codex-tmp/compat-regressions');
if (getenv('APP_ENV') !== 'testing' || !$runtime || !$allowed
    || !str_starts_with(strtolower($runtime), strtolower($allowed).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Run this characterization through tests/run-regressions.php.');
}
require $root.'/vendor/autoload.php';
require __DIR__.'/e2e/catalog-fixture.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config([
    'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
    'services.sklad_storefront.base_url' => 'http://127.0.0.1:9/api/storefront/v1',
    'services.sklad_storefront.token' => 'synthetic-cache-fixture',
]);
Illuminate\Support\Facades\Http::preventStrayRequests();
$phase = 'old';
$calls = 0;
Illuminate\Support\Facades\Http::fake(function ($request) use (&$phase, &$calls) {
    if ($request->method() !== 'GET'
        || parse_url($request->url(), PHP_URL_PATH) !== '/api/storefront/v1/catalog'
        || !str_starts_with($request->url(), 'http://127.0.0.1:9/')) {
        throw new RuntimeException('Unexpected outbound request in cache characterization.');
    }
    $calls++;
    if ($phase === 'failure') {
        return Illuminate\Support\Facades\Http::response(['message' => 'Synthetic upstream failure'], 503);
    }
    $payload = cartCatalogFixture('uk');
    $payload['products'][0]['name'] = $phase === 'old' ? 'Cache old snapshot' : 'Cache new snapshot';

    return Illuminate\Support\Facades\Http::response($payload);
});
function cacheContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS cache: $message\n";
}
$anchor = Illuminate\Support\Carbon::now();
Illuminate\Support\Carbon::setTestNow($anchor);
try {
    $request = Illuminate\Http\Request::create('http://127.0.0.1:4327/parts/');
    $first = $kernel->handle($request);
    cacheContract($first->getStatusCode() === 200 && str_contains($first->getContent(), 'Cache old snapshot'), 'cold HTTP read renders upstream data');
    $kernel->terminate($request, $first);
    cacheContract($calls === 1, 'cold read fetches once');

    $phase = 'failure';
    Illuminate\Support\Carbon::setTestNow($anchor->copy()->addSeconds(301));
    $request = Illuminate\Http\Request::create('http://127.0.0.1:4327/parts/');
    $stale = $kernel->handle($request);
    cacheContract($stale->getStatusCode() === 200 && str_contains($stale->getContent(), 'Cache old snapshot'), 'stale response returns last good data');
    cacheContract($calls === 1, 'stale refresh is deferred until HTTP termination');
    $kernel->terminate($request, $stale);
    cacheContract($calls === 2, 'failed deferred refresh actually executes');

    $phase = 'new';
    $request = Illuminate\Http\Request::create('http://127.0.0.1:4327/parts/');
    $stillStale = $kernel->handle($request);
    cacheContract($stillStale->getStatusCode() === 200 && str_contains($stillStale->getContent(), 'Cache old snapshot'), 'failed refresh does not overwrite last good snapshot');
    $kernel->terminate($request, $stillStale);
    cacheContract($calls === 3, 'subsequent deferred refresh can succeed');

    $request = Illuminate\Http\Request::create('http://127.0.0.1:4327/parts/');
    $fresh = $kernel->handle($request);
    cacheContract($fresh->getStatusCode() === 200 && str_contains($fresh->getContent(), 'Cache new snapshot')
        && !str_contains($fresh->getContent(), 'Cache old snapshot'), 'successful refresh replaces stale response');
    $kernel->terminate($request, $fresh);
    cacheContract($calls === 3, 'fresh snapshot avoids another upstream request');

    $phase = 'failure';
    Illuminate\Support\Carbon::setTestNow($anchor->copy()->addSeconds(301 + 86401));
    $request = Illuminate\Http\Request::create('http://127.0.0.1:4327/parts/');
    $expired = $kernel->handle($request);
    cacheContract($expired->getStatusCode() === 503 && !str_contains($expired->getContent(), 'Cache new snapshot'), 'expired cache fails closed when upstream fails');
    $kernel->terminate($request, $expired);
    Illuminate\Support\Facades\Http::assertSentCount(4);
} finally {
    Illuminate\Support\Carbon::setTestNow();
}
