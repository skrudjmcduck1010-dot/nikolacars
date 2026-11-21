<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['uk','ru'] as $locale) {
    $html = view('home',['locale'=>$locale,'services'=>[],'featuredParts'=>[]])->render();
    $prefix = $locale === 'ru' ? '/ru' : '';
    preg_match('~<h1[^>]*>(.*?)</h1>~s',$html,$heading);
    preg_match_all('~data-service="([^"]+)"~',$html,$slides);
    foreach ([
        substr_count($html,'<h1 ') === 1,
        str_contains($heading[1],$locale === 'ru' ? 'Сервис Tesla в Киеве' : 'Сервіс Tesla у Києві'),
        $slides[1] === ['service','import','fw','cert'],
        str_contains($html,'href="'.$prefix.'/services/tesla-service/"'),
        str_contains($html,'href="'.$prefix.'/services/prigon-tesla-usa/"'),
        substr_count($html,'action="'.route('lead.callback').'"') === 4,
        str_contains($html,'images/hero/service.webp'),
        !str_contains($html,'images/hero/import-mobile.webp'),
    ] as $assertion) {
        if (!$assertion) throw new RuntimeException('Home regression: '.$locale);
    }
    echo "PASS $locale: service H1, four slides, localized links, callback forms and matching first image\n";
}
