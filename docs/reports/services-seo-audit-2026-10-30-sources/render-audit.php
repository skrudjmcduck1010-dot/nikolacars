<?php
try {
$root='C:/Projects/nikolacars';
$runtime=$root.'/.codex-tmp/services-audit-20261030';
foreach(['bootstrap','storage/logs','storage/framework/views','storage/framework/sessions','storage/framework/cache/data'] as $d) { if(!is_dir($runtime.'/'.$d)) mkdir($runtime.'/'.$d,0777,true); }
$env=['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_URL'=>'https://nikolacars.kiev.ua','APP_KEY'=>'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','MAIL_MAILER'=>'array','LOG_CHANNEL'=>'stderr','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:','LARAVEL_STORAGE_PATH'=>$runtime.'/storage','VIEW_COMPILED_PATH'=>$runtime.'/storage/framework/views'];
foreach(['CONFIG','SERVICES','PACKAGES','ROUTES','EVENTS'] as $key) $env['APP_'.$key.'_CACHE']='.codex-tmp/services-audit-20261030/bootstrap/'.strtolower($key).'.php';
foreach($env as $k=>$v) {putenv($k.'='.$v); $_ENV[$k]=$v; $_SERVER[$k]=$v;}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (in_array('--before', $argv, true)) {
 $app['view']->getFinder()->setPaths([$root.'/.local-backup-20261030-services-content/before/resources/views',$root.'/resources/views']);
 config(['targeted_services'=>require $root.'/.local-backup-20261030-services-content/before/config/targeted_services.php']);
}
$outputDir=in_array('--candidate',$argv,true)?$root.'/docs/reports/services-content-implementation-2026-10-30-sources':__DIR__;
Illuminate\Support\Facades\Http::preventStrayRequests();
$services=['tesla-service'=>'tesla-service','tesla-electricmotor-repair'=>'tesla-electricmotor-repair','tesla-battery-repair'=>'tesla-battery-repair','repair-tesla-door-handle'=>'repair-tesla-door-handle','tesla-subframe-repair'=>'tesla-subframe-repair','vidnovlennya-sertyfikativ-tesla'=>'vidnovlennya-sertyfikativ-tesla','firmware-auto'=>'firmware-auto','prigon-tesla-usa'=>'prigon-usa'];
foreach(config('targeted_services') as $s) $services[$s['slug']]='targeted.'.$s['slug'];
$services['']='INDEX';
if (in_array('--confirmed', $argv, true)) {
 $services=array_intersect_key($services,array_flip(['tesla-srs-crash','tesla-body-crash','tesla-battery-crash','tesla-air-suspension-crash','tesla-processor-repair']));
 $outputDir=$root.'/docs/reports/services-content-implementation-2026-10-30-sources/confirmed-services';
 if(!is_dir($outputDir))mkdir($outputDir,0777,true);
}
$out=[];
foreach($services as $slug=>$view) foreach(['uk','ru'] as $loc) {
 $path=($loc==='ru'?'/ru':'').'/services/'.($slug?$slug.'/':'');
 $request=Illuminate\Http\Request::create('https://nikolacars.kiev.ua'.$path);
 $app->instance('request',$request);
 $route=$app['router']->getRoutes()->match($request);
 $route->bind($request); $request->setRouteResolver(fn()=>$route);
 $result=$route->run(); $html=$result instanceof Illuminate\Contracts\View\View?$result->render():$result->getContent();
 $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);libxml_clear_errors();$xp=new DOMXPath($dom);
 $texts=fn($q)=>array_map(fn($n)=>trim(preg_replace('/\s+/u',' ',$n->textContent)),iterator_to_array($xp->query($q)));
 $attrs=fn($q,$a)=>array_map(fn($n)=>$n->getAttribute($a),iterator_to_array($xp->query($q)));
 $article=$xp->query('//*[contains(@class,"service-article-text")]')->item(0);
 $articleText=$article?trim(preg_replace('/\s+/u',' ',$article->textContent)):'';
 $out[]=['path'=>$path,'view'=>$view,'title'=>$texts('//title'),'description'=>$attrs('//meta[@name="description"]','content'),'h1'=>$texts('//h1'),'canonical'=>$attrs('//link[@rel="canonical"]','href'),'alternates'=>$attrs('//link[@rel="alternate"]','href'),'h2'=>$texts('//h2'),'article_chars'=>mb_strlen($articleText),'article'=>$articleText,'paragraphs'=>$texts('//section//p'),'article_links'=>$article?$attrs('//*[contains(@class,"service-article-text")]//a','href'):[],'links'=>$attrs('//a[starts-with(@href,"/services/") or starts-with(@href,"/ru/services/")]','href')];
}
$encoded=json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$encoded=preg_replace('/\\+380[0-9]{9}/','[public contact redacted]',$encoded);
file_put_contents($outputDir.'/rendered-inventory.json',$encoded);
$checks=['routes'=>count($out),'bad_h1'=>[],'bad_metadata_count'=>[],'bad_canonical'=>[],'bad_alternates'=>[],'broken_service_links'=>[],'missing_in_sitemap'=>[]];
$app->instance('request',Illuminate\Http\Request::create('https://nikolacars.kiev.ua/sitemaps/pages.xml'));
$sitemap=app(App\Http\Controllers\SiteController::class)->sitemapPages($app['request'])->getContent();
foreach($out as $r){
 if(count($r['h1'])!==1)$checks['bad_h1'][]=$r['path'];
 if(count($r['title'])!==1||count($r['description'])!==1)$checks['bad_metadata_count'][]=$r['path'];
 if($r['canonical']!==['https://nikolacars.kiev.ua'.$r['path']])$checks['bad_canonical'][]=$r['path'];
 $ua=preg_replace('#^/ru/#','/',$r['path']);
 if(!in_array('https://nikolacars.kiev.ua'.$ua,$r['alternates'])||!in_array('https://nikolacars.kiev.ua/ru'.$ua,$r['alternates']))$checks['bad_alternates'][]=$r['path'];
 if(!str_contains($sitemap,'https://nikolacars.kiev.ua'.$r['path']))$checks['missing_in_sitemap'][]=$r['path'];
 foreach(array_unique($r['links']) as $link){try{$app['router']->getRoutes()->match(Illuminate\Http\Request::create('https://nikolacars.kiev.ua'.$link));}catch(Throwable){$checks['broken_service_links'][]=[$r['path'],$link];}}
}
file_put_contents($outputDir.'/render-checks.json',json_encode($checks,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo 'COMPLETED '.count($out)." routes\n";
} catch(Throwable $e) {fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString());exit(1);}
