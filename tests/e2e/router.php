<?php

// Test-only loopback router: no normal .env cache/storage, real API, DB or POSTs.
$root = dirname(__DIR__, 2);
$runtime = realpath((string) getenv('NC_CART_E2E_RUNTIME'));
$allowed = realpath($root.'/.codex-tmp/cart-e2e');
if (getenv('NC_CART_E2E') !== '1' || getenv('APP_ENV') !== 'testing'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:4327'
    || !$runtime || !$allowed || !str_starts_with(strtolower($runtime), strtolower($allowed).DIRECTORY_SEPARATOR)) {
    http_response_code(403);
    exit('Isolated loopback test profile required.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Business writes are disabled in the cart browser fixture.');
}
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($path === '/__cart_e2e/health') exit('cart-e2e-ready');
if ($path === '/__cart_e2e/part.svg') {
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
if ($path !== '/__cart_e2e/no-modal/' && !in_array($path, $readPages, true) && !preg_match('~^/(?:ru/)?parts/~', $path)) {
    http_response_code(404);
    exit('Only storefront fixture routes are available.');
}

require $root.'/vendor/autoload.php';
require __DIR__.'/catalog-fixture.php';
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
$recommendationProfile = (string) $request->header('X-Recommendation-E2E', '');
if (!in_array($recommendationProfile, ['', 'available', 'sold'], true)) throw new RuntimeException('Unknown recommendation fixture.');
Illuminate\Support\Facades\Http::fake(function ($request) use ($recommendationProfile) {
    if ($request->method() !== 'GET' || !str_starts_with($request->url(), 'http://127.0.0.1:9/api/storefront/v1/')) {
        throw new RuntimeException('Unexpected outbound request in isolated browser fixture.');
    }
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
    if (parse_url($request->url(), PHP_URL_PATH) === '/api/storefront/v1/catalog') {
        return Illuminate\Support\Facades\Http::response(cartCatalogFixture($query['locale'] ?? 'uk'));
    }
    if (parse_url($request->url(), PHP_URL_PATH) === '/api/storefront/v1/products/100001') {
        return Illuminate\Support\Facades\Http::response(compatibilityProductFixture($query['locale'] ?? 'uk', $recommendationProfile));
    }
    if (parse_url($request->url(), PHP_URL_PATH) === '/api/storefront/v1/products/100002') {
        return Illuminate\Support\Facades\Http::response(array_replace(compatibilityProductFixture($query['locale'] ?? 'uk'), [
            'id' => 100002, 'url_slug' => 'recommendation-100002',
        ]));
    }
    return Illuminate\Support\Facades\Http::response(['message' => 'Unknown synthetic endpoint'], 404);
});
Illuminate\Support\Facades\Route::get('/__cart_e2e/no-modal/', function () {
    $locale = request()->query('locale') === 'ru' ? 'ru' : 'uk';
    return '<!doctype html><html><head><title>Header without modal fixture</title></head><body>'
        .view('partials.header-cart', ['locale' => $locale])->render().'</body></html>';
});
$response = $kernel->handle($request);
if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
    $markup = $request->header('X-Cart-E2E-Markup', 'header-only');
    if ($markup === 'header-only') {
        // Current production profile: the retired floating control is absent.
        $content = preg_replace('~<button\b[^>]*class="parts-floating-cart"[^>]*>.*?</button>~s', '', $response->getContent(), 1);
        $response->setContent($content);
    } elseif ($markup !== 'legacy') {
        throw new RuntimeException('Unknown browser fixture markup profile.');
    } elseif (str_contains($response->getContent(), 'id="partsCatalog"')) {
        // Backwards-compatibility fixture only; never add this control to production Blade.
        $control = '<button type="button" class="parts-floating-cart" data-open-cart>Legacy cart <span data-cart-count>0</span></button>';
        $response->setContent(preg_replace('~</header>~', '</header>'.$control, $response->getContent(), 1));
    }
}
$response->send();
$kernel->terminate($request, $response);
