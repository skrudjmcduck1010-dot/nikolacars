<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\CatalogPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } echo "PASS $message\n"; }
function queryOf($request) { parse_str(parse_url($request->url(),PHP_URL_QUERY) ?: '',$query); return $query; }
function fakeApi($callback) {
    Cache::flush();
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    Http::fake($callback);
}
function categoryRequest($path, $query='') {
    $request = Illuminate\Http\Request::create('/parts/'.$path.'/'.$query);
    $route = new Illuminate\Routing\Route('GET', 'parts/{catalogPath}', fn()=>null);
    $route->where('catalogPath','.*');
    $route->bind($request);
    $request->setRouteResolver(fn()=>$route);
    return $request;
}
config(['cache.default'=>'array','services.sklad_storefront.token'=>'test','services.sklad_storefront.base_url'=>'https://warehouse.test']);
$client = app(App\Services\SkladStorefrontClient::class);
$controller = app(App\Http\Controllers\PartsController::class);
$full = 'panel-priladiv--panel-priladiv--rama-pribornoyi-paneli';
$short = 'panel-priladiv--rama-pribornoyi-paneli';
$model = 'model-y-01-2020-01-2025';
$fixture = ['products'=>[], 'selection'=>['model_slug'=>$model,'category_path_locale_slugs'=>['uk'=>$full,'ru'=>'pribornaia-panel--panel-pribornaia--rama-pribornoi-paneli']], 'pagination'=>['page'=>2,'last_page'=>2]];
$index = ['locales'=>['uk'=>['category_path_sections'=>[['model_slug'=>$model,'category_path_slug'=>$full]]], 'ru'=>['category_path_sections'=>[]]]];
check(CatalogPath::normalize($full)==='panel-priladiv/panel-priladiv/rama-pribornoyi-paneli','distinct equal-named levels retained');
check(CatalogPath::legacyCollapsed($full)===CatalogPath::legacyCollapsed($short),'legacy alias comparison only');
fakeApi(fn($r)=>Http::response($fixture));
$view = $controller->index(categoryRequest($model.'/'.CatalogPath::normalize($full),'?page=2'),$client);
check($view instanceof Illuminate\View\View,'full category route renders, not redirect');
Http::assertSent(fn($r)=>(queryOf($r)['category_path_slug'] ?? null)===$full && (int)(queryOf($r)['page'] ?? 0)===2);
Http::assertSentCount(1);
fakeApi(function($r) use($index,$full,$fixture) {
    if(str_contains($r->url(),'/seo-index')) return Http::response($index);
    return (queryOf($r)['category_path_slug'] ?? null)===$full ? Http::response($fixture) : Http::response(['message'=>'Подкатегория не найдена.'],422);
});
$redirect = $controller->index(categoryRequest($model.'/'.CatalogPath::normalize($short),'?page=2'),$client);
check($redirect instanceof Illuminate\Http\RedirectResponse && $redirect->getStatusCode()===301 && str_ends_with($redirect->getTargetUrl(),'/'.$model.'/'.CatalogPath::normalize($full).'/?page=2'),'short old URL redirects to full category retaining page');
Http::assertSentCount(3);
$client->catalog(['locale'=>'uk','model_slug'=>$model,'category_path_slug'=>$short]);
Http::assertSentCount(5);
check(true,'category aliases cached, no repeated SEO index download');
$client->catalog(['locale'=>'uk','model_slug'=>$model,'category_path_slug'=>$full.'--rama-pribornoyi-paneli']);
Http::assertSentCount(7);
check(true,'invalid repeated tail resolves to the real full path');
fakeApi(fn($r)=>Http::response($fixture));
$ruView=$controller->index(categoryRequest($model.'/pribornaia-panel/panel-pribornaia/rama-pribornoi-paneli'),$client);
check($ruView instanceof Illuminate\Http\RedirectResponse || $ruView instanceof Illuminate\View\View,'different Russian level labels remain valid');
fakeApi(fn($r)=>Http::response(['message'=>'unavailable'],503));
check($client->catalog(['locale'=>'uk','category_path_slug'=>$short])->status()===503,'503 not treated as category alias');
Http::assertSentCount(1);
$ambiguous = $index;
$ambiguous['locales']['uk']['category_path_sections'][]=['model_slug'=>$model,'category_path_slug'=>'panel-priladiv--rama-pribornoyi-paneli--rama-pribornoyi-paneli'];
fakeApi(fn($r)=>str_contains($r->url(),'/seo-index') ? Http::response($ambiguous) : Http::response(['message'=>'unknown'],422));
check($client->catalog(['locale'=>'uk','model_slug'=>$model,'category_path_slug'=>$short])->status()===422,'ambiguous alias not guessed');
Http::assertSentCount(2);
fakeApi(fn($r)=>Http::response(['message'=>'unknown'],422));
check($client->catalog(['locale'=>'uk','q'=>'search'])->status()===422,'non-category validation not retried');
Http::assertSentCount(1);
fakeApi(fn($r)=>Http::response(['id'=>82780,'name'=>'Накладка','part_number'=>'1507076-00-A','model'=>'Model Y 01.2020 - 01.2025','model_slug'=>$model,'quantity'=>1,'price_uah'=>680,'category_breadcrumbs'=>[['label'=>'Панель приладів','slug'=>'panel-priladiv'],['label'=>'Панель приладів','slug'=>'panel-priladiv--panel-priladiv'],['label'=>'Рама приборної панелі','slug'=>$full]]]));
$productView=$controller->show($client,'1507076-00-a-82780','uk');
$breadcrumbs=collect($productView->getData()['seoStructuredData'])->firstWhere('@type','BreadcrumbList')['itemListElement'];
check(count(array_filter($breadcrumbs,fn($row)=>$row['name']==='Панель приладів'))===2,'both official levels retained in structured breadcrumbs');
check(str_contains($productView->render(),'/'.$model.'/'.CatalogPath::normalize($full).'/'),'product HTML links to full category');
echo "All category-level regression checks passed.\n";
