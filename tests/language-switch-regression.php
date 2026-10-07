<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function languageAssert(bool $value, string $message): void
{
    if (! $value) {
        throw new RuntimeException($message);
    }
}

$cases = [
    ['layouts.app', '/'],
    ['layouts.app', '/services/'],
    ['layouts.app', '/services/tesla-service/'],
    ['layouts.app', '/services/tesla-computer-diagnostics/'],
    ['layouts.app', '/services/prigon-tesla-usa/'],
    ['layouts.app', '/contacts/'],
    ['layouts.app', '/news/'],
    ['layouts.app', '/testimonial/'],
    ['layouts.app', '/privacy-policy/'],
    ['layouts.parts', '/parts/'],
    ['layouts.parts', '/parts/1002274-00-d-103754/'],
    ['layouts.parts', '/parts/checkout/'],
];
$assetVersion = '20261008-1';

foreach ($cases as [$layout, $path]) {
    foreach (['uk', 'ru'] as $locale) {
        $requestPath = $locale === 'ru' ? '/ru'.$path : $path;
        $app->instance('request', Illuminate\Http\Request::create('https://nikolacars.test'.$requestPath));
        $html = view($layout, ['locale' => $locale])->render();
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $links = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," lang ")]/a[contains(concat(" ",normalize-space(@class)," ")," pill ")]');
        languageAssert($links->length === 2, "$requestPath: exactly two language choices");
        $activeCount = 0;
        foreach ($links as $link) {
            $label = trim($link->textContent);
            $active = in_array('active', explode(' ', $link->getAttribute('class')), true);
            $expectedActive = $label === ($locale === 'ru' ? 'RU' : 'UA');
            languageAssert($active === $expectedActive, "$requestPath: correct active choice");
            languageAssert($link->getAttribute('aria-current') === ($expectedActive ? 'page' : ''), "$requestPath: accessibility state matches choice");
            languageAssert($link->getAttribute('href') === ($label === 'RU' ? '/ru'.$path : $path), "$requestPath: language switch keeps current page");
            $activeCount += (int) $active;
        }
        languageAssert($activeCount === 1, "$requestPath: only one active language");
        languageAssert(str_contains($html, 'assets/css/language-switch.css?v='.$assetVersion), "$requestPath: shared active style loaded with cache version");
        echo "PASS $requestPath: active language, accessibility, links and shared stylesheet\n";
    }
}

$css = file_get_contents(public_path('assets/css/language-switch.css'));
languageAssert(str_contains($css, '.lang .pill.active'), 'active styles scoped to language controls');
languageAssert(str_contains($css, 'color: #fff') && str_contains($css, 'border-color: rgba(255, 255, 255, .3)') && str_contains($css, 'background: rgba(255, 255, 255, .08)'), 'highlight matches existing storefront appearance');
echo "All language-switch regression checks passed.\n";
