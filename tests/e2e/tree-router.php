<?php

// Test-only loopback router: no normal .env cache/storage, real API, DB or POSTs.
$root = dirname(__DIR__, 2);
$runtime = realpath((string) getenv('NC_TREE_E2E_RUNTIME'));
$allowed = realpath($root.'/.codex-tmp/tree-e2e');
if (getenv('NC_TREE_E2E') !== '1' || getenv('APP_ENV') !== 'testing'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:4336'
    || !$runtime || !$allowed || !str_starts_with(strtolower($runtime), strtolower($allowed).DIRECTORY_SEPARATOR)) {
    http_response_code(403);
    exit('Isolated loopback test profile required.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Business writes are disabled in the cart browser fixture.');
}
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($path === '/__tree_e2e/health') exit('cart-e2e-ready');
if ($path === '/__tree_e2e/part.svg') {
    header('Content-Type: image/svg+xml');
    exit('<svg xmlns="http://www.w3.org/2000/svg" width="360" height="300"><rect width="360" height="300" fill="#e5ebe6"/><text x="40" y="150" fill="#256b36">Synthetic part</text></svg>');
}
if (preg_match('~^/(?:assets|images)/~', $path) || $path === '/favicon.ico') {
    $file = realpath($root.'/public'.$path);
    $public = realpath($root.'/public');
    if ($file && str_starts_with(strtolower($file), strtolower($public).DIRECTORY_SEPARATOR)
        && preg_match('/\.(?:js|css|png|jpe?g|webp|svg|ico|woff2?|ttf)$/i', $file)) return false;
    http_response_code(404);
    exit;
}
$readPages = ['/', '/ru/', '/services/tesla-service/', '/ru/services/tesla-service/'];
if ($path !== '/__tree_e2e/no-modal/' && !in_array($path, $readPages, true) && !preg_match('~^/(?:ru/)?parts/~', $path)) {
    http_response_code(404);
    exit('Only storefront fixture routes are available.');
}

require $root.'/vendor/autoload.php';
require __DIR__.'/catalog-tree-fixture.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
$request = Illuminate\Http\Request::capture();
$app->instance('request', $request);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config([
    'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
    'services.sklad_storefront.base_url' => 'http://127.0.0.1:9/api/storefront/v1',
    'services.sklad_storefront.token' => 'synthetic-cart-fixture',
]);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function ($request) {
    if ($request->method() !== 'GET' || !str_starts_with($request->url(), 'http://127.0.0.1:9/api/storefront/v1/')) {
        throw new RuntimeException('Unexpected outbound request in isolated browser fixture.');
    }
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
    if (parse_url($request->url(), PHP_URL_PATH) === '/api/storefront/v1/catalog') {
        return Illuminate\Support\Facades\Http::response(treeCatalogFixture($query));
    }
    return Illuminate\Support\Facades\Http::response(['message' => 'Unknown synthetic endpoint'], 404);
});
$response = $kernel->handle($request);
// Preview-only disclosure; never emitted by a production controller/template.
if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
    $response->setContent(preg_replace('~(<body\b[^>]*>)~i', '$1<div data-preview-fixture role="note" style="padding:10px 16px;background:#fff2cf;color:#503b0c;text-align:center;font:14px/1.5 Arial,sans-serif;overflow-wrap:anywhere">Предпросмотр: тестовые товары и остатки</div>', $response->getContent(), 1));
}
$response->send();
$kernel->terminate($request, $response);
