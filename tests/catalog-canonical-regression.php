<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'services.sklad_storefront.base_url' => 'http://127.0.0.1:9/api/storefront/v1', 'services.sklad_storefront.token' => 'canonical-fixture']);
$reviewed = json_decode(file_get_contents(__DIR__.'/fixtures/canonical-reviewed-20261121.json'), true, flags: JSON_THROW_ON_ERROR);
$checks = 0;
$check = function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) throw new RuntimeException($message);
    $checks++;
};
$check(count($reviewed) >= 12, 'reviewed scope fixture is populated');
foreach ($reviewed as $rule) {
    ['model' => $model, 'locale' => $locale, 'parent' => $parent, 'child' => $child] = $rule;
    $scope = ($locale === 'ru' ? '/ru/parts/' : '/parts/').($model !== '' ? $model.'/' : '');
    $otherLocale = $locale === 'ru' ? 'uk' : 'ru';
    $other = collect($reviewed)->first(fn (array $item): bool => $item['model'] === $model && $item['locale'] === $otherLocale && $item['total'] === $rule['total'] &&
        // Pair order in the fixture is UA followed by RU, below selects exact pair.
        array_search($item, $reviewed, true) === (array_search($rule, $reviewed, true) ^ 1));
    $check(is_array($other), 'both locales have a reviewed counterpart');
    $parents = [$locale => $parent, $otherLocale => $other['parent']];
    $children = [$locale => $child, $otherLocale => $other['child']];
    $expandedChildren = [];
    foreach (['uk', 'ru'] as $l) {
        $expandedChildren[$l] = collect($reviewed)->filter(fn (array $item): bool => $item['model'] === $model && $item['locale'] === $l && $item['parent'] === $parents[$l])
            ->sortByDesc(fn (array $item): int => strlen($item['child']))->first()['child'];
    }
    $expandedChild = $expandedChildren[$locale];
    $calls = 0;
    Illuminate\Support\Facades\Cache::flush();
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory());
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(function ($request) use (&$calls, $model, $locale, $parent, $child, $parents, $children, $expandedChild, $expandedChildren) {
        $calls++;
        if ($request->method() !== 'GET' || !str_starts_with($request->url(), 'http://127.0.0.1:9/api/storefront/v1/catalog')) throw new RuntimeException('Unexpected request');
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $q);
        $selected = App\Services\CatalogPath::normalize($q['category_path_slug'] ?? $q['category_slug'] ?? '');
        $descendant = $selected === $child.'/retained-child';
        $expanded = $selected === $expandedChild || $descendant;
        $root = explode('/', $parent)[0];
        $nodes = [['label' => 'Root', 'category_path_slug' => $root, 'parent_path_slug' => '', 'count' => 3, 'has_children' => true]];
        if ($parent !== $root) $nodes[] = ['label' => 'Reviewed parent', 'category_path_slug' => $parent, 'parent_path_slug' => $root, 'count' => 3, 'has_children' => true];
        $nodes[] = ['label' => 'Reviewed alias', 'category_path_slug' => $child, 'parent_path_slug' => $parent, 'count' => 3, 'has_children' => true];
        // This mirrors the real panel API: a parent exposes only its child;
        // grandchildren appear only when the equivalent child is selected.
        if ($expanded) $nodes[] = ['label' => 'Distinct descendant', 'category_path_slug' => $child.'/retained-child', 'parent_path_slug' => $child, 'count' => 2, 'has_children' => false];
        $nodes[] = ['label' => 'Unexpanded branch', 'category_path_slug' => 'sidinnia', 'parent_path_slug' => '', 'count' => 1, 'has_children' => true];
        $localePaths = [];
        foreach (['uk', 'ru'] as $l) $localePaths[$l] = str_replace('/', '--', $descendant ? $children[$l].'/retained-child' : ($expanded ? $expandedChildren[$l] : $parents[$l]));
        return Illuminate\Support\Facades\Http::response([
            'models' => [], 'categories' => [], 'products' => [], 'category_navigation' => $nodes,
            'pagination' => ['page' => 2, 'last_page' => 3, 'total' => 50],
            'selection' => ['model_slug' => $model, 'model' => $model, 'category_slug' => $root,
                'category_path_slug' => str_replace('/', '--', $selected), 'category' => 'Fixture',
                'category_path_locale_slugs' => $localePaths],
        ]);
    });
    $query = '?page=2&q=fixture&sort=name&gclid=synthetic-click&gbraid=synthetic-braid&wbraid=synthetic-web';
    $request = Illuminate\Http\Request::create('http://127.0.0.1:4327'.$scope.$child.'/'.$query);
    $response = $kernel->handle($request);
    $check($response->getStatusCode() === 301, 'reviewed duplicate permanently redirects');
    $check($response->headers->get('Location') === 'http://127.0.0.1:4327'.$scope.$parent.'/'.$query, 'redirect retains locale/model/all query values');
    $check($calls === 0, 'static alias requires no upstream');
    $kernel->terminate($request, $response);
    $request = Illuminate\Http\Request::create('http://127.0.0.1:4327'.$scope.$parent.'/?page=2');
    $response = $kernel->handle($request);
    $check($response->getStatusCode() === 200, 'canonical parent renders without a redirect loop');
    $check($calls === 1, 'canonical parent uses a single expanded upstream read');
    Illuminate\Support\Facades\Http::assertSent(function ($sent) use ($expandedChild) { parse_str(parse_url($sent->url(), PHP_URL_QUERY) ?: '', $query); return ($query['category_path_slug'] ?? '') === str_replace('/', '--', $expandedChild); });
    $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent()); $xp = new DOMXPath($dom);
    $check($xp->evaluate('string(//link[@rel="canonical"]/@href)') === 'https://nikolacars.kiev.ua'.$scope.$parent.'/?page=2', 'page two self canonical');
    foreach (['uk' => 'uk-UA', 'ru' => 'ru-UA'] as $l => $lang) {
        $base = ($l === 'ru' ? '/ru/parts/' : '/parts/').($model !== '' ? $model.'/' : '');
        $check($xp->evaluate('string(//link[@hreflang="'.$lang.'"]/@href)') === 'https://nikolacars.kiev.ua'.$base.$parents[$l].'/?page=2', 'hreflang uses counterpart canonical and page');
    }
    $check($xp->query('//aside//a[@href="'.$scope.$parent.'/"]')->length === 1, 'SSR exposes one canonical navigation row');
    $check($xp->query('//aside//a[@href="'.$scope.$child.'/"]')->length === 0, 'SSR removes duplicate alias link');
    $check($xp->query('//aside//a[@href="'.$scope.$child.'/retained-child/"]')->length === 1, 'distinct third level remains reachable');
    $kernel->terminate($request, $response);
    $request = Illuminate\Http\Request::create('http://127.0.0.1:4327'.$scope.$child.'/retained-child/?page=2');
    $response = $kernel->handle($request);
    $check($response->getStatusCode() === 200, 'descendant URL does not inherit an exact alias redirect');
    $check(str_contains($response->getContent(), '<link rel="canonical" href="https://nikolacars.kiev.ua'.$scope.$child.'/retained-child/?page=2">'), 'descendant retains its own canonical');
    $kernel->terminate($request, $response);
    $calls = 0;
    $apiQuery = http_build_query(['locale' => $locale, 'model_slug' => $model, 'page' => 2,
        str_contains($parent, '/') ? 'category_path_slug' : 'category_slug' => str_replace('/', '--', $parent)]);
    $request = Illuminate\Http\Request::create('http://127.0.0.1:4327'.($locale === 'ru' ? '/ru' : '').'/parts/api/catalog/?'.$apiQuery);
    $response = $kernel->handle($request);
    $check($response->getStatusCode() === 200 && $calls === 1, 'AJAX canonical query uses exactly one expanded read');
    $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $navigationUrls = array_column($payload['navigation'], 'url');
    $check(count(array_filter($navigationUrls, fn (string $url): bool => $url === $scope.$parent.'/')) === 1
        && !in_array($scope.$child.'/', $navigationUrls, true)
        && in_array($scope.$child.'/retained-child/', $navigationUrls, true), 'AJAX keeps canonical parent and reachable grandchildren');
    $kernel->terminate($request, $response);
    $navigation = App\Services\CatalogNavigation::items([
        'selection' => ['model_slug' => $model, 'category_path_slug' => $child],
        'category_navigation' => [
            ['label' => 'Parent', 'category_path_slug' => $parent, 'parent_path_slug' => '', 'has_children' => true],
            ['label' => 'Alias', 'category_path_slug' => $child, 'parent_path_slug' => $parent, 'has_children' => false],
            ['label' => 'Distinct', 'category_path_slug' => $child.'/retained-child', 'parent_path_slug' => $child, 'has_children' => false],
            ['label' => 'Unexpanded', 'category_path_slug' => 'sidinnia', 'parent_path_slug' => '', 'has_children' => true],
        ],
    ], $locale);
    $check(count($navigation) === 3 && $navigation[0]['active'] && $navigation[0]['has_children'], 'merged parent retains actual descendants despite leaf flag');
    $check($navigation[1]['depth'] === 1 && $navigation[2]['has_children'], 'descendant depth and unexpanded branch preserved');
    $navigation = App\Services\CatalogNavigation::items(['selection' => ['model_slug' => $model, 'category_path_slug' => $child], 'category_navigation' => [
        ['label' => 'Parent', 'category_path_slug' => $parent, 'parent_path_slug' => '', 'has_children' => true],
        ['label' => 'Alias', 'category_path_slug' => $child, 'parent_path_slug' => $parent, 'has_children' => true],
    ]], $locale);
    $check(count($navigation) === 1 && !$navigation[0]['has_children'], 'merged leaf has no false expanding arrow');
    $check(App\Services\CatalogPath::preferred($child, 'unreviewed-fixture-model') === $child, 'unreviewed model is unchanged');
}
// The Wi-Fi RU slug combines two genuinely distinct UA leaves. Consolidate
// only its equivalent RU parent; never redirect either UA inventory.
$wifiUk = 'informaciino-rozvazalna-sistema/antena-wi-fi';
$wifiRu = 'informacionno-razvlekatelnaia-sistema/antenna-wi-fi';
$wifiLeaves = [$wifiUk.'/antena-wi-fi' => 7, $wifiUk.'/antenna-wi-fi' => 3];
Illuminate\Support\Facades\Cache::flush();
Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory());
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function ($request) use ($wifiUk, $wifiRu, $wifiLeaves) {
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $q);
    $locale = $q['locale'] ?? 'uk';
    $path = App\Services\CatalogPath::normalize($q['category_path_slug'] ?? '');
    $count = $locale === 'ru' ? 10 : ($wifiLeaves[$path] ?? 10);
    $paths = $locale === 'ru'
        ? ['uk' => $wifiUk.'/antena-wi-fi', 'ru' => $wifiRu.'/antenna-wi-fi']
        : ['uk' => $path, 'ru' => $path === $wifiUk ? $wifiRu : $wifiRu.'/antenna-wi-fi'];
    return Illuminate\Support\Facades\Http::response([
        'models' => [], 'categories' => [], 'products' => [],
        'pagination' => ['page' => 1, 'last_page' => 1, 'total' => $count],
        'selection' => ['model_slug' => '', 'category_slug' => explode('/', $path)[0], 'category' => 'Wi-Fi fixture',
            'category_path_slug' => str_replace('/', '--', $path), 'category_path_locale_slugs' => $paths],
    ]);
});
foreach ([$wifiUk => 10] + $wifiLeaves as $path => $count) {
    $request = Illuminate\Http\Request::create('http://127.0.0.1:4327/parts/'.$path.'/');
    $response = $kernel->handle($request);
    $check($response->getStatusCode() === 200, 'distinct UA Wi-Fi URL preserved');
    $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent()); $xp = new DOMXPath($dom);
    $check($xp->evaluate('string(//link[@rel="canonical"]/@href)') === 'https://nikolacars.kiev.ua/parts/'.$path.'/', 'UA Wi-Fi self canonical');
    $check($xp->query('//link[@hreflang="ru-UA"]')->length === ($path === $wifiUk ? 1 : 0), 'only equivalent UA parent declares RU alternate');
    $check($xp->query('//link[@hreflang="uk-UA"]')->length === 1 && $xp->query('//link[@hreflang="x-default"]')->length === 1, 'UA self and x-default retained');
    preg_match('/window\.initialPartsCatalog = (.*);/', $response->getContent(), $match);
    $check(json_decode($match[1], true, flags: JSON_THROW_ON_ERROR)['pagination']['total'] === $count, 'distinct UA inventory total preserved');
    $kernel->terminate($request, $response);
}
$request = Illuminate\Http\Request::create('http://127.0.0.1:4327/ru/parts/'.$wifiRu.'/antenna-wi-fi/');
$response = $kernel->handle($request);
$check($response->getStatusCode() === 301 && $response->headers->get('Location') === 'http://127.0.0.1:4327/ru/parts/'.$wifiRu.'/', 'only duplicate RU leaf redirects');
$kernel->terminate($request, $response);
$request = Illuminate\Http\Request::create('http://127.0.0.1:4327/ru/parts/'.$wifiRu.'/');
$response = $kernel->handle($request);
$check($response->getStatusCode() === 200, 'RU Wi-Fi parent has no redirect loop');
$dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent()); $xp = new DOMXPath($dom);
$check($xp->evaluate('string(//link[@rel="canonical"]/@href)') === 'https://nikolacars.kiev.ua/ru/parts/'.$wifiRu.'/', 'RU Wi-Fi parent self canonical');
$check($xp->evaluate('string(//link[@hreflang="uk-UA"]/@href)') === 'https://nikolacars.kiev.ua/parts/'.$wifiUk.'/', 'RU parent alternate targets equivalent UA parent, not its seven-item leaf');
$kernel->terminate($request, $response);
echo "PASS canonical consolidation: $checks assertions\n";
