<?php
// Local read-only preview. No business forms, production API, persistent DB or normal cache/storage.
$root='C:/Projects/nikolacars';
if (($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:4338') {http_response_code(403);exit;}
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') {http_response_code(405);exit('Preview is read-only.');}
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if ($path==='/__health') exit('services-preview-ready');
if (preg_match('~^/(?:assets|images)/~',$path)||$path==='/favicon.ico') {
 $file=realpath($root.'/public'.$path);$public=realpath($root.'/public');
 if($file&&str_starts_with(strtolower($file),strtolower($public).DIRECTORY_SEPARATOR)&&preg_match('/\.(?:js|css|png|jpe?g|webp|svg|ico|woff2?|ttf)$/i',$file))return false;
 http_response_code(404);exit;
}
if(!preg_match('~^/(?:ru/)?services/(?:[a-z0-9-]+/)?$~',$path)){http_response_code(404);exit('Only service preview pages are enabled.');}
$runtime=$root.'/.codex-tmp/services-preview-20261030';
foreach(['bootstrap','storage/logs','storage/framework/views','storage/framework/sessions','storage/framework/cache/data']as$d)if(!is_dir($runtime.'/'.$d))mkdir($runtime.'/'.$d,0777,true);
$env=['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:4338','APP_KEY'=>'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','MAIL_MAILER'=>'array','LOG_CHANNEL'=>'stderr','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:','LARAVEL_STORAGE_PATH'=>$runtime.'/storage','VIEW_COMPILED_PATH'=>$runtime.'/storage/framework/views'];
foreach(['CONFIG','SERVICES','PACKAGES','ROUTES','EVENTS']as$key)$env['APP_'.$key.'_CACHE']='.codex-tmp/services-preview-20261030/bootstrap/'.strtolower($key).'.php';
foreach($env as$k=>$v){putenv($k.'='.$v);$_ENV[$k]=$v;$_SERVER[$k]=$v;}
require$root.'/vendor/autoload.php';$app=require$root.'/bootstrap/app.php';
$request=Illuminate\Http\Request::capture();$app->instance('request',$request);$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$kernel->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
$response=$kernel->handle($request);$response->send();$kernel->terminate($request,$response);
