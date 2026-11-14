<?php

// Run through run-regressions.php: isolated environment/cache/storage and fake HTTP only.
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/e2e/catalog-fixture.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$checks = 0;
$assert = function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
Illuminate\Support\Facades\Http::preventStrayRequests();
$middleware = new App\Http\Middleware\ForceTrailingSlash();
$aliases = [
    '/parts/vysokovoltnaia-batareia' => '/parts/visokovoltna-batareia/',
    '/parts/model-3-06-2017-12-2023/vysokovoltnaia-batareia' => '/parts/model-3-06-2017-12-2023/visokovoltna-batareia/',
];
$base = 'https://nikolacars.kiev.ua';
foreach ($aliases as $old => $target) {
    foreach (['', '/'] as $slash) foreach (['GET', 'HEAD'] as $method) {
        foreach (['', '?page=2&sort=price_asc&q=OEM%2B12&x=1&x=2'] as $query) {
            $request = Illuminate\Http\Request::create($base.$old.$slash.$query, $method);
            $response = $kernel->handle($request);
            $assert($response->getStatusCode() === 301, 'UA alias is permanent for both slash variants');
            $assert($response->headers->get('Location') === $base.$target.$query, 'Direct final UA target preserves raw query');
            $kernel->terminate($request, $response);
        }
    }
}
Illuminate\Support\Facades\Http::assertNothingSent();
$assert(true, 'Legacy aliases do not call warehouse');

// The new rule must not broaden to models/children/RU paths, or change write methods.
foreach ([
    '/ru/parts/vysokovoltnaia-batareia/',
    '/ru/parts/model-3-06-2017-12-2023/vysokovoltnaia-batareia/',
    '/parts/model-y-01-2020-01-2025/vysokovoltnaia-batareia/',
    '/parts/vysokovoltnaia-batareia/vysokovoltnaia-batareia/',
    '/parts/vysokovoltnaia-batareia-extra/',
    '/parts/vysokovoltnaia-batareia//',
    ...array_values($aliases),
] as $path) {
    $response = $middleware->handle(Illuminate\Http\Request::create($base.$path), fn () => response('next', 209));
    $assert($response->getStatusCode() === 209, 'Only exact reviewed UA aliases change: '.$path);
}
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) foreach (array_keys($aliases) as $path) {
    $response = $middleware->handle(Illuminate\Http\Request::create($base.$path.'/', $method), fn () => response('next', 209));
    $assert($response->getStatusCode() === 209, 'Non-read methods retained');
}
$slash = $middleware->handle(Illuminate\Http\Request::create($base.'/parts/elektrika?x=1'), fn () => response('next', 209));
$assert($slash->getStatusCode() === 301 && $slash->headers->get('Location') === $base.'/parts/elektrika/?x=1', 'Ordinary trailing slash unchanged');

// Follow both real routed destinations using synthetic catalog responses: no redirect loop.
foreach (['', 'model-3-06-2017-12-2023/'] as $model) foreach (['uk', 'ru'] as $locale) {
    Illuminate\Support\Facades\Cache::flush();
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory());
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $category = $locale === 'ru' ? 'vysokovoltnaia-batareia' : 'visokovoltna-batareia';
    $path = ($locale === 'ru' ? '/ru/parts/' : '/parts/').$model.$category.'/';
    $payload = cartCatalogFixture($locale);
    $payload['selection'] = ['model_slug' => rtrim($model, '/'), 'category_slug' => $category, 'category' => $locale === 'ru' ? 'Высоковольтная батарея' : 'Високовольтна батарея'];
    Illuminate\Support\Facades\Http::fake(function ($request) use ($payload) {
        if ($request->method() !== 'GET' || !str_contains($request->url(), '/catalog')) throw new RuntimeException('Unexpected fake upstream');
        return Illuminate\Support\Facades\Http::response($payload);
    });
    $request = Illuminate\Http\Request::create($base.$path);
    $response = $kernel->handle($request);
    $assert($response->getStatusCode() === 200, 'UA targets and working RU pages render without redirect: '.$path);
    $assert(str_contains($response->getContent(), 'rel="canonical" href="'.$base.$path.'"'), 'Target self-canonical');
    $kernel->terminate($request, $response);
}
echo "PASS UA battery aliases: $checks assertions\n";
