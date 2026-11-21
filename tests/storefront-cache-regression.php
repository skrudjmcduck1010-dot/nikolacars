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

    // Exercise real 404 HTTP termination: a restored product must not remain
    // hidden until the old negative cache entry reaches its hard expiry.
    foreach (['uk', 'ru'] as $locale) {
        foreach ([422, 404, 410] as $missingStatus) {
            Illuminate\Support\Facades\Cache::flush();
            Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory());
            Illuminate\Support\Facades\Http::preventStrayRequests();
            $productPhase = 'missing';
            $productCalls = 0;
            $isSold = $missingStatus === 422;
            Illuminate\Support\Facades\Http::fake(function ($request) use (&$productPhase, &$productCalls, $locale, $missingStatus, $isSold) {
                if ($request->method() !== 'GET'
                    || parse_url($request->url(), PHP_URL_PATH) !== '/api/storefront/v1/products/100001'
                    || !str_starts_with($request->url(), 'http://127.0.0.1:9/')
                    || $request['locale'] !== $locale) {
                    throw new RuntimeException('Unexpected outbound product request in cache characterization.');
                }
                $productCalls++;
                if ($productPhase === 'missing') {
                    return Illuminate\Support\Facades\Http::response(['message' => 'Synthetic unavailable product'], $missingStatus);
                }
                if ($productPhase === 'failure') {
                    return Illuminate\Support\Facades\Http::response(['message' => 'Synthetic upstream failure'], 503);
                }

                return Illuminate\Support\Facades\Http::response(compatibilityProductFixture($locale, $isSold ? 'sold' : ''));
            });
            $case = $locale.' upstream '.$missingStatus;
            $route = ($locale === 'ru' ? '/ru/parts/' : '/parts/').'test-001-100001/';
            $readProduct = function () use ($kernel, $route): array {
                $request = Illuminate\Http\Request::create('http://127.0.0.1:4327'.$route);

                return [$request, $kernel->handle($request)];
            };
            Illuminate\Support\Carbon::setTestNow($anchor);
            [$request, $missing] = $readProduct();
            cacheContract($missing->getStatusCode() === 404, $case.': unavailable product returns a real 404');
            $kernel->terminate($request, $missing);
            [$request, $freshMissing] = $readProduct();
            $kernel->terminate($request, $freshMissing);
            cacheContract($freshMissing->getStatusCode() === 404 && $productCalls === 1, $case.': fresh negative cache prevents repeated upstream reads');

            Illuminate\Support\Carbon::setTestNow($anchor->copy()->addSeconds(301));
            $productPhase = 'failure';
            [$request, $staleMissing] = $readProduct();
            cacheContract($staleMissing->getStatusCode() === 404 && $productCalls === 1, $case.': stale negative response keeps refresh deferred');
            $kernel->terminate($request, $staleMissing);
            cacheContract($productCalls === 2, $case.': negative response still executes its deferred refresh');

            $productPhase = 'restored';
            [$request, $recovering] = $readProduct();
            cacheContract($recovering->getStatusCode() === 404, $case.': failed refresh did not overwrite the previous negative cache');
            $kernel->terminate($request, $recovering);
            cacheContract($productCalls === 3, $case.': subsequent negative response retries the recovered upstream');

            [$request, $restored] = $readProduct();
            cacheContract($restored->getStatusCode() === 200
                && str_contains($restored->getContent(), 'test-001-100001')
                && str_contains($restored->getContent(), $isSold ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock'),
                $case.': restored '.($isSold ? 'sold' : 'available').' product renders before hard expiry');
            $kernel->terminate($request, $restored);
            cacheContract($productCalls === 3, $case.': recovered positive cache is reused');

            Illuminate\Support\Carbon::setTestNow($anchor->copy()->addSeconds(602));
            $productPhase = 'missing';
            [$request, $lastGood] = $readProduct();
            cacheContract($lastGood->getStatusCode() === 200, $case.': positive stale response keeps existing SWR behavior');
            $kernel->terminate($request, $lastGood);
            [$request, $withdrawn] = $readProduct();
            cacheContract($withdrawn->getStatusCode() === 404 && $productCalls === 4, $case.': newly unavailable product remains a real 404');
            $kernel->terminate($request, $withdrawn);
        }
    }
} finally {
    Illuminate\Support\Carbon::setTestNow();
}
