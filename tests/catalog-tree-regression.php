<?php

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/e2e/catalog-tree-fixture.php';
$app=require __DIR__.'/../bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$kernel->bootstrap();
config(['cache.default'=>'array','session.driver'=>'array','services.sklad_storefront.base_url'=>'http://127.0.0.1:9/api/storefront/v1','services.sklad_storefront.token'=>'synthetic-tree-fixture']);
Illuminate\Support\Facades\Http::preventStrayRequests();
$apiCalls=0;
Illuminate\Support\Facades\Http::fake(function($request)use(&$apiCalls){
    if($request->method()!=='GET'||!str_starts_with($request->url(),'http://127.0.0.1:9/api/storefront/v1/catalog'))throw new RuntimeException('Unexpected fixture request');
    $apiCalls++;parse_str(parse_url($request->url(),PHP_URL_QUERY)?:'',$query);
    return Illuminate\Support\Facades\Http::response(treeCatalogFixture($query));
});
$checks=0;
$check=function($value,$message)use(&$checks){if(!$value)throw new RuntimeException($message);$checks++;};
foreach(['uk','ru'] as $locale){
    $base=$locale==='ru'?'/ru/parts/':'/parts/';
    $wire=$locale==='ru'?'elektroprovodka':'elektroprovodka';
    foreach(['','elektrika/','elektrika/'.$wire.'/','model-s-04-2016-01-2021/elektrika/'] as $path){
        $request=Illuminate\Http\Request::create('http://127.0.0.1:4327'.$base.$path.'?sort=name');
        $response=$kernel->handle($request);$html=$response->getContent();
        $check($response->getStatusCode()===200,$locale.' route '.$path);
        $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
        $check($xp->query('//aside[contains(@class,"parts-sidebar")]//nav/ul[@data-categories]')->length===1,'semantic nav/list');
        $check($xp->query('//aside[contains(@class,"parts-sidebar")]//a[@aria-current="page"]')->length===1,'one current item');
        $check($xp->query('//*[@id="partsCatalog"]/*/nav[contains(@class,"parts-model-links")]')->length===0,'no top SEO links');
        foreach($xp->query('//*[@data-categories]//a') as $a){$check(!str_contains($a->getAttribute('href'),'?'),'category clears filter/page');$check(str_starts_with($a->getAttribute('href'),$base),'locale preserved');}
        if($path!=='')$check($xp->query('//*[@data-categories]//a[contains(@style,"--category-depth:1")]')->length>0,'children included in SSR');
        if(str_contains($path,'model-s-'))foreach($xp->query('//*[@data-categories]//a') as $a)$check(str_contains($a->getAttribute('href'),'model-s-04-2016-01-2021/'),'model preserved');
        if(str_contains($path,$wire.'/'))$check($xp->query('//*[@data-categories]//a[contains(@class,"is-ancestor")]')->length===1,'root ancestor distinct from current child');
        $kernel->terminate($request,$response);
    }
}
$check($apiCalls===8,'exactly one upstream read per page');
$old=['categories'=>[['slug'=>'elektrika','label'=>'Електрика','count'=>2]],'selection'=>['category_slug'=>'elektrika','category_path_slug'=>'elektrika--elektroprovodka']];
$fallback=App\Services\CatalogNavigation::items($old,'uk');
$check(count($fallback)===1&&$fallback[0]['active']&&$fallback[0]['depth']===0,'absent field keeps usable root fallback');
$check(App\Services\CatalogNavigation::items($old+['category_navigation'=>null],'uk')===$fallback,'null field keeps same root fallback');
$alias=['selection'=>['model_slug'=>'model-3-highland-01-2024','category_slug'=>'diski-i-sini','category_path_slug'=>'diski-i-sini--diski--diski'],'category_navigation'=>[
    ['label'=>'Диски і шини','category_path_slug'=>'diski-i-sini','parent_path_slug'=>'','count'=>4,'has_children'=>true],
    ['label'=>'Диски','category_path_slug'=>'diski-i-sini--diski','parent_path_slug'=>'diski-i-sini','count'=>4,'has_children'=>true],
    ['label'=>'Диски','category_path_slug'=>'diski-i-sini--diski--diski','parent_path_slug'=>'diski-i-sini--diski','count'=>4,'has_children'=>false],
]];
$items=App\Services\CatalogNavigation::items($alias,'uk');
$check(count($items)===2&&$items[1]['active']&&!$items[1]['has_children'],'reviewed equal final URL shown once without leaf arrow');
$alias['selection']['model_slug']='model-y-01-2020-01-2025';
$check(count(App\Services\CatalogNavigation::items($alias,'uk'))===3,'same labels with distinct URLs are retained');
$battery=['selection'=>['category_slug'=>'visokovoltna-batareia','category_path_slug'=>'visokovoltna-batareia--vysokovoltnaia-batareia'],'category_navigation'=>[
    ['label'=>'Высоковольтная батарея','category_path_slug'=>'visokovoltna-batareia','parent_path_slug'=>'','count'=>7,'has_children'=>true],
    ['label'=>'Высоковольтная батарея','category_path_slug'=>'visokovoltna-batareia--vysokovoltnaia-batareia','parent_path_slug'=>'visokovoltna-batareia','count'=>7,'has_children'=>false],
]];
$batteryItems=App\Services\CatalogNavigation::items($battery,'ru');
$check($batteryItems[0]['url']==='/ru/parts/vysokovoltnaia-batareia/'&&$batteryItems[1]['url']==='/ru/parts/vysokovoltnaia-batareia/vysokovoltnaia-batareia/','old battery root alias keeps normalized RU URLs');
$check($batteryItems[0]['ancestor']&&$batteryItems[1]['active']&&count(array_filter($batteryItems,fn($item)=>$item['active']))===1,'old battery alias has one current item and its ancestor');
// Reviewed Model Y aliases must retain locale, model and pagination in real routes.
$modelY='model-y-01-2020-01-2025';
foreach([
    ['uk','visokovoltna-sistema/visokovoltni-provoda'],
    ['ru','vysokovoltnaia-sistema/vysokovoltnye-provoda'],
    ['uk','perednii-motor/peredni-pivosi'], ['ru','perednii-motor/perednie-poluosi'],
    ['uk','zadnii-motor/zadni-pivosi'], ['ru','zadnii-motor/zadnie-poluosi'],
] as [$locale,$parent]){
    $base=$locale==='ru'?'/ru/parts/':'/parts/';
    $tail=substr($parent,strrpos($parent,'/')+1);$leaf=$parent.'/'.$tail;
    $root=explode('/',$parent)[0];$parentSlug=str_replace('/','--',$parent);$leafSlug=str_replace('/','--',$leaf);
    $check(App\Services\CatalogPath::preferred($parent,$modelY)===$leaf,'reviewed Model Y path uses leaf');
    $check(App\Services\CatalogPath::preferred($parent,'model-s-04-2016-01-2021')===$parent,'other model is unchanged');
    Illuminate\Support\Facades\Cache::flush();$before=$apiCalls;
    $request=Illuminate\Http\Request::create('http://127.0.0.1:4327'.$base.$modelY.'/'.$parent.'/?page=2');
    $response=$kernel->handle($request);
    $check($response->getStatusCode()===301,'parent permanent redirect');
    $check($response->headers->get('Location')==='http://127.0.0.1:4327'.$base.$modelY.'/'.$leaf.'/?page=2','redirect preserves locale model page two');
    $check($apiCalls===$before,'redirect needs no upstream');$kernel->terminate($request,$response);
    $catalog=['models'=>[],'categories'=>[],'products'=>[],'pagination'=>['page'=>2,'last_page'=>2,'total'=>25],
        'selection'=>['model_slug'=>$modelY,'model'=>'Model Y 01.2020 - 01.2025','category_slug'=>$root,'category_path_slug'=>$leafSlug,'category'=>'Test category','category_path_locale_slugs'=>[$locale=>$leafSlug]],
        'category_navigation'=>[
            ['label'=>'Root','category_path_slug'=>$root,'parent_path_slug'=>'','count'=>2,'has_children'=>true],
            ['label'=>'Leaf','category_path_slug'=>$parentSlug,'parent_path_slug'=>$root,'count'=>2,'has_children'=>true],
            ['label'=>'Leaf','category_path_slug'=>$leafSlug,'parent_path_slug'=>$parentSlug,'count'=>2,'has_children'=>false],
        ]];
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory());
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(fn()=>Illuminate\Support\Facades\Http::response($catalog));
    $request=Illuminate\Http\Request::create('http://127.0.0.1:4327'.$base.$modelY.'/'.$leaf.'/?page=2');
    $response=$kernel->handle($request);
    $check($response->getStatusCode()===200,'leaf page two without loop');
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());$xp=new DOMXPath($dom);
    $canonical=$xp->evaluate('string(//link[@rel="canonical"]/@href)');
    $check(parse_url($canonical,PHP_URL_PATH)===$base.$modelY.'/'.$leaf.'/'&&parse_url($canonical,PHP_URL_QUERY)==='page=2','canonical retains page two: '.$canonical);
    $items=App\Services\CatalogNavigation::items($catalog,$locale);
    $check(count($items)===2&&$items[1]['url']===$base.$modelY.'/'.$leaf.'/','navigation preferred URL unique');
    $check($xp->query('//aside//a[@href="'.$base.$modelY.'/'.$parent.'/"]')->length===0,'SSR no duplicate link');
    $check($xp->query('//aside//a[@href="'.$base.$modelY.'/'.$leaf.'/"]')->length===1,'SSR direct canonical link');
    $kernel->terminate($request,$response);
}
echo "PASS category tree: $checks assertions; reviewed Model Y redirects and SSR renders\n";
