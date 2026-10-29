<?php

// Standalone, isolated route/Blade characterization. Never reads .env credentials or production APIs.
try {
    $root = dirname(__DIR__);
    $relative = '.codex-tmp/seo-package2-tests/run-'.bin2hex(random_bytes(5));
    $runtime = $root.'/'.$relative;
    foreach (['bootstrap', 'storage/logs', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions'] as $directory) {
        if (!mkdir($runtime.'/'.$directory, 0777, true) && !is_dir($runtime.'/'.$directory)) throw new RuntimeException('Runtime creation failed');
    }
    $environment = [
        'APP_ENV'=>'testing', 'APP_DEBUG'=>'false', 'APP_URL'=>'https://nikolacars.kiev.ua',
        'APP_KEY'=>'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'ASSET_URL'=>'https://nikolacars.kiev.ua',
        'CACHE_STORE'=>'array', 'SESSION_DRIVER'=>'array', 'QUEUE_CONNECTION'=>'sync', 'MAIL_MAILER'=>'array',
        'DB_CONNECTION'=>'sqlite', 'DB_DATABASE'=>':memory:', 'DB_URL'=>'', 'LOG_CHANNEL'=>'stderr',
        'SKLAD_STOREFRONT_URL'=>'http://127.0.0.1:9/api/storefront/v1', 'SKLAD_STOREFRONT_TOKEN'=>'synthetic-seo-fixture',
        'TELEGRAM_BOT_TOKEN'=>'', 'TELEGRAM_CHAT_ID'=>'', 'LARAVEL_STORAGE_PATH'=>$runtime.'/storage',
        'VIEW_COMPILED_PATH'=>$runtime.'/storage/framework/views',
    ];
    foreach (['CONFIG'=>'config','SERVICES'=>'services','PACKAGES'=>'packages','ROUTES'=>'routes','EVENTS'=>'events'] as $key=>$file) $environment['APP_'.$key.'_CACHE']=$relative.'/bootstrap/'.$file.'.php';
    foreach ($environment as $key=>$value) { putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value; }
    require $root.'/vendor/autoload.php';
    $overlay = $argv[1] ?? '';
    if ($overlay !== '') {
        foreach (['app/Services/CatalogPath.php','app/Http/Controllers/PartsController.php','app/Http/Controllers/SiteController.php'] as $file) {
            if (!is_file($overlay.'/'.$file)) throw new RuntimeException('Incomplete battery overlay');
            require $overlay.'/'.$file;
        }
    }
    require __DIR__.'/e2e/catalog-fixture.php';
    $app = require $root.'/bootstrap/app.php';
    $app->useStoragePath($runtime.'/storage');
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
    if ($overlay !== '') view()->getFinder()->prependLocation($overlay.'/resources/views');
    config(['cache.default'=>'array','session.driver'=>'array','services.sklad_storefront.base_url'=>'http://127.0.0.1:9/api/storefront/v1','services.sklad_storefront.token'=>'synthetic-seo-fixture']);
    $checks = 0;
    $assert = function ($condition, $message) use (&$checks) { if (!$condition) throw new RuntimeException($message); $checks++; };
    $sections = [
        ['model'=>'model-3-highland-01-2024','uk'=>'diski-i-sini/diski/diski','ru'=>'diski-i-siny/diski/diski','uk_h1'=>'Диски та ковпаки Tesla Model 3 Highland','ru_h1'=>'Диски и колпаки Tesla Model 3 Highland'],
        ['model'=>'','uk'=>'zovnisnia-furnitura/podium-nomernogo-znaka/podium-nomernogo-znaka','ru'=>'naruznaia-furnitura/podium-nomernogo-znaka/podium-nomernogo-znaka','uk_h1'=>'Подіуми та кронштейни номерного знака Tesla','ru_h1'=>'Подиумы и кронштейны номерного знака Tesla'],
        ['model'=>'','uk'=>'galma/galmivni-diski-i-suporta/zadni-tormozni-diski-suporta-i-kolodki','ru'=>'tormoza/tormoznye-diski-i-supporta/zadnie-tormoznye-diski-supporta-i-kolodki','uk_h1'=>'Задні гальмівні диски, супорти та колодки Tesla','ru_h1'=>'Задние тормозные диски, суппорты и колодки Tesla'],
        ['model'=>'model-s-04-2016-01-2021','uk'=>'','ru'=>'','uk_h1'=>'Запчастини Tesla Model S 2016–2021','ru_h1'=>'Запчасти Tesla Model S 2016–2021'],
    ];
    $fixtures=[];
    foreach ($sections as $index=>$section) foreach (['uk','ru'] as $locale) {
        $prefix=$locale==='ru'?'/ru/parts/':'/parts/';
        $path=$prefix.($section['model']!==''?$section['model'].'/':'').($section[$locale]!==''?$section[$locale].'/':'');
        $payload=cartCatalogFixture($locale);
        $payload['selection']=['model_slug'=>$section['model'],'model'=>$index===3?'Model S 04.2016 - 01.2021':'','category_path_locale_slugs'=>['uk'=>str_replace('/','--',$section['uk']),'ru'=>str_replace('/','--',$section['ru'])]];
        $payload['categories']=[['value'=>'Кузов','label'=>'Кузов','slug'=>'kuzov','count'=>3],['value'=>'Підвіска','label'=>'Підвіска','slug'=>'pidviska','count'=>0]];
        $payload['pagination']=['page'=>1,'last_page'=>2,'total'=>25,'per_page'=>24];
        foreach (['','?page=2','?q=test','?sort=price_asc'] as $query) {
            Illuminate\Support\Facades\Cache::flush();
            Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory());
            Illuminate\Support\Facades\Http::preventStrayRequests();
            $active=$payload; $active['pagination']['page']=$query==='?page=2'?2:1;
            Illuminate\Support\Facades\Http::fake(function ($request) use ($active) {
                if ($request->method()!=='GET' || !str_starts_with($request->url(),'http://127.0.0.1:9/api/storefront/v1/catalog')) throw new RuntimeException('Unexpected outbound request');
                return Illuminate\Support\Facades\Http::response($active);
            });
            $request=Illuminate\Http\Request::create('https://nikolacars.kiev.ua'.$path.$query);
            $response=$kernel->handle($request);
            $assert($response->getStatusCode()===200, "HTTP 200 $path$query");
            $html=$response->getContent();
            $document=new DOMDocument(); @$document->loadHTML('<?xml encoding="UTF-8">'.$html); $xpath=new DOMXPath($document);
            $assert($xpath->query('//h1')->length===1 && trim($xpath->query('//h1')->item(0)->textContent)===$section[$locale.'_h1'],"one exact H1 $path$query");
            $assert(str_contains($xpath->query('//title')->item(0)->textContent,$section[$locale.'_h1']),"specific title $path$query");
            $canonical='https://nikolacars.kiev.ua'.$path.($query==='?page=2'?'?page=2':'');
            $assert($xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href')===$canonical,"canonical $path$query");
            $assert($xpath->query('//link[@hreflang]')->length===3,"three hreflang $path$query");
            $assert(($xpath->query('//meta[@name="robots"]')->length>0)===in_array($query,['?q=test','?sort=price_asc'],true),"query noindex $path$query");
            $assert($xpath->query('//*[@data-model-intro]')->length===1,"useful intro $path$query");
            $assert($xpath->query('//*[@data-model-intro]')->item(0)->hasAttribute('hidden')===($query!==''),"intro visibility $path$query");
            Illuminate\Support\Facades\Http::assertSentCount(1);
            $assert(true,"one API fetch $path$query");
            $kernel->terminate($request,$response);
            if ($query==='') {
                file_put_contents($runtime.'/section-'.$index.'-'.$locale.'.html',$html);
                $fixtures[]=['id'=>$index.'-'.$locale,'path'=>$path,'h1'=>$section[$locale.'_h1'],'html'=>$runtime.'/section-'.$index.'-'.$locale.'.html','payload'=>$payload];
                if ($index===3) {
                    $assert(str_contains($html,$path.'kuzov/'),'model links use available categories');
                    $assert($xpath->query('//*[@data-model-intro]//a[@href="'.$path.'pidviska/"]')->length===0,'zero-count category not linked in helper');
                }
            }
        }
        if ($index<2) {
            $short=substr($section[$locale],0,strrpos($section[$locale],'/'));
            $alias=$prefix.($section['model']!==''?$section['model'].'/':'').$short.'/';
            $before=Illuminate\Support\Facades\Http::recorded()->count();
            foreach ([$alias,$prefix.($section['model']!==''?$section['model'].'/':'').'subcategory/'.str_replace('/','--',$short).'/'] as $old) {
                $request=Illuminate\Http\Request::create('https://nikolacars.kiev.ua'.$old.'?page=2&sort=price_asc');
                $response=$kernel->handle($request);
                $assert($response->getStatusCode()===301 && $response->headers->get('Location')==='https://nikolacars.kiev.ua'.$path.'?page=2&sort=price_asc','direct alias retains query');
                $kernel->terminate($request,$response);
            }
            $assert(Illuminate\Support\Facades\Http::recorded()->count()===$before,'aliases require no upstream fetch');
        }
    }
    $assert(App\Services\ModelCatalogSeo::forModel('model-s-02-2012-03-2016','uk')===null,'earlier Model S not rewritten');
    $assert(App\Services\CatalogPath::preferred('panel-priladiv/panel-priladiv/rama-pribornoyi-paneli','model-y-01-2020-01-2025')==='panel-priladiv/panel-priladiv/rama-pribornoyi-paneli','unrelated official repeated levels retained');
    foreach (['uk','ru'] as $locale) {
        $links=App\Services\CategoryCatalogSeo::links('','','',$locale);
        $assert(count($links)===3,'catalog hub exposes three existing section links');
        foreach ($links as $link) $assert(str_ends_with($link['url'],'/') && !str_contains($link['url'],'?'),'clean section link');
    }
    if ($overlay !== '') {
        $batteryPaths=['uk'=>'visokovoltna-batareia/visokovoltna-batareia/visokovoltna-batareia','ru'=>'vysokovoltnaia-batareia/vysokovoltnaia-batareia/vysokovoltnaia-batareia'];
        $legacyRu='visokovoltna-batareia/vysokovoltnaia-batareia/vysokovoltnaia-batareia';
        $payload=cartCatalogFixture('ru');
        $payload['selection']=['category'=>'Высоковольтная батарея','category_path_locale_slugs'=>['uk'=>str_replace('/','--',$batteryPaths['uk']),'ru'=>str_replace('/','--',$legacyRu)]];
        $payload['pagination']['last_page']=2;
        Illuminate\Support\Facades\Cache::flush();
        Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory());
        Illuminate\Support\Facades\Http::preventStrayRequests();
        $seoIndex=['locales'=>['ru'=>['categories'=>[['slug'=>'visokovoltna-batareia'],['slug'=>'vysokovoltnaia-batareia']],'sections'=>[['model_slug'=>'model-s-02-2012-03-2016','category_slug'=>'visokovoltna-batareia']]]]];
        $product=compatibilityProductFixture('ru');
        $product['category_breadcrumbs']=[['label'=>'Високовольтна батарея','slug'=>'visokovoltna-batareia'],['label'=>'Высоковольтная батарея','slug'=>str_replace('/','--',$legacyRu)]];
        Illuminate\Support\Facades\Http::fake(function ($request) use ($payload,$seoIndex,$product) {
            if (!str_starts_with($request->url(),'http://127.0.0.1:9/') || $request->method()!=='GET') throw new RuntimeException('Unexpected overlay API request');
            if (str_contains($request->url(),'/seo-index')) return Illuminate\Support\Facades\Http::response($seoIndex);
            if (str_contains($request->url(),'/products/')) return Illuminate\Support\Facades\Http::response($product);
            parse_str(parse_url($request->url(),PHP_URL_QUERY) ?: '',$query);
            $current=$payload; $current['pagination']['page']=(int)($query['page']??1);
            if (!isset($query['category_path_slug'])) $current['selection']=['category'=>'Высоковольтная батарея'];
            return Illuminate\Support\Facades\Http::response($current);
        });
        foreach (['','model-s-02-2012-03-2016/'] as $model) foreach (['visokovoltna-batareia',$legacyRu] as $legacy) {
            $old='/ru/parts/'.$model.$legacy.'/'; $new='/ru/parts/'.$model.str_replace('visokovoltna-batareia','vysokovoltnaia-batareia',$legacy).'/';
            $request=Illuminate\Http\Request::create('https://nikolacars.kiev.ua'.$old.'?page=2&q=OEM');
            $response=$kernel->handle($request);
            $assert($response->getStatusCode()===301 && $response->headers->get('Location')==='https://nikolacars.kiev.ua'.$new.'?page=2&q=OEM','battery root redirect preserves model and full query');
            $kernel->terminate($request,$response);
        }
        Illuminate\Support\Facades\Http::assertNothingSent(); $assert(true,'battery aliases no extra API fetch');
        foreach (['uk','ru'] as $locale) foreach (['','?page=2','?q=OEM'] as $query) {
            $path=($locale==='ru'?'/ru/parts/':'/parts/').$batteryPaths[$locale].'/';
            $request=Illuminate\Http\Request::create('https://nikolacars.kiev.ua'.$path.$query);
            $response=$kernel->handle($request);$html=$response->getContent();
            $assert($response->getStatusCode()===200,'canonical battery URL has no loop');
            $assert(str_contains($html,'rel="canonical" href="https://nikolacars.kiev.ua'.$path.($query==='?page=2'?$query:'').'"'),'battery canonical pagination/query');
            $assert(str_contains($html,'hreflang="ru-UA" href="https://nikolacars.kiev.ua/ru/parts/'.$batteryPaths['ru'].'/'.($query==='?page=2'?$query:'').'"'),'battery counterpart never legacy root');
            $kernel->terminate($request,$response);
        }
        $request=Illuminate\Http\Request::create('https://nikolacars.kiev.ua/ru/parts/test-001-100001/');
        $response=$kernel->handle($request);$html=$response->getContent();
        $assert($response->getStatusCode()===200 && str_contains($html,'/ru/parts/model-3-06-2017-12-2023/vysokovoltnaia-batareia/'),'product breadcrumbs use normalized RU root');
        $assert(!str_contains($html,'/ru/parts/model-3-06-2017-12-2023/visokovoltna-batareia/'),'old breadcrumb root absent from HTML/schema');
        $kernel->terminate($request,$response);
        $request=Illuminate\Http\Request::create('https://nikolacars.kiev.ua/sitemaps/parts-ru.xml');
        $response=$kernel->handle($request);$xml=$response->getContent();
        $assert($response->getStatusCode()===200 && !str_contains($xml,'/visokovoltna-batareia/'),'sitemap never emits old RU root even from stale source');
        $assert(substr_count($xml,'<loc>https://nikolacars.kiev.ua/ru/parts/vysokovoltnaia-batareia/</loc>')===1,'sitemap normalized roots deduplicated');
        $kernel->terminate($request,$response);
    }
    file_put_contents($runtime.'/fixtures.json',json_encode($fixtures,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    file_put_contents($runtime.'/result.json',json_encode(['status'=>'PASS','checks'=>$checks,'landing_routes'=>32,'battery_overlay'=>$overlay!==''? $overlay:null,'fixtures'=>count($fixtures)],JSON_PRETTY_PRINT));
    echo "PASS $checks assertions; 32 landing route/Blade variants; 8 browser fixtures".($overlay!==''?'; battery overlay verified':'')."\n$runtime\n";
} catch (Throwable $exception) {
    fwrite(STDERR,'SEO PACKAGE2 FAILED: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(1);
}
