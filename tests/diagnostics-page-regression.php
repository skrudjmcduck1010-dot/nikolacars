<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function diagnosticsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['uk', 'ru'] as $locale) {
    $html = view('services.targeted.tesla-computer-diagnostics', ['locale' => $locale])->render();
    $isRu = $locale === 'ru';
    $prefix = $isRu ? '/ru' : '';
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);
    $h1 = $xpath->query('//h1');
    diagnosticsAssert($h1->length === 1, "$locale: exactly one H1");
    diagnosticsAssert(trim($h1->item(0)->textContent) === ($isRu
        ? 'Компьютерная диагностика Tesla в Киеве'
        : 'Комп’ютерна діагностика Tesla у Києві'), "$locale: localized H1");
    diagnosticsAssert(trim($xpath->query('//title')->item(0)->textContent) === ($isRu
        ? 'Диагностика Tesla в Киеве — проверка ошибок | NikolaCars'
        : 'Діагностика Tesla у Києві — перевірка помилок | NikolaCars'), "$locale: title");
    $description = $xpath->query('//meta[@name="description"]');
    diagnosticsAssert($description->length === 1, "$locale: unique description");
    diagnosticsAssert(str_contains($description->item(0)->getAttribute('content'), $isRu
        ? 'Стоимость согласуем до работ.' : 'Вартість погодимо до робіт.'), "$locale: description pricing");
    diagnosticsAssert($xpath->query('//form[@id="callForm"]')->length === 1, "$locale: callback retained");
    diagnosticsAssert(str_contains($html, 'action="'.route('lead.callback').'"'), "$locale: callback route");
    $article = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " service-article-text ")]')->item(0);
    foreach (['/contacts/', '/services/tesla-service/', '/services/tesla-battery-repair/',
        '/services/tesla-charge-port-repair/', '/services/tesla-pre-purchase-inspection/'] as $path) {
        diagnosticsAssert($xpath->query('.//a[@href="'.$prefix.$path.'"]', $article)->length === 1, "$locale: localized $path");
    }
    foreach (['Model S', 'Model 3', 'Model X', 'Model Y', $isRu ? 'Стоимость и время диагностики' : 'Вартість і час діагностики',
        $isRu ? 'Запись на диагностику в Киеве' : 'Запис на діагностику у Києві'] as $text) {
        diagnosticsAssert(str_contains($article->textContent, $text), "$locale: useful content $text");
    }
    diagnosticsAssert(!str_contains(mb_strtolower($html), 'loki'), "$locale: no unconfirmed equipment");
    diagnosticsAssert(!str_contains($html, 'по кейсу «') && !str_contains($html, 'за кейсом «'), "$locale: specific hero subtitle");
    diagnosticsAssert(str_contains($html, 'https://service.tesla.com/docs/Public/ServiceMode/release-notes.html'), "$locale: official source");
    diagnosticsAssert(!str_contains($html, 'SOH'), "$locale: no blanket battery-test claim");

    foreach (config('targeted_services', []) as $service) {
        if ($service['slug'] === 'tesla-computer-diagnostics') {
            continue;
        }
        $view = 'services.targeted.'.$service['slug'];
        if (!view()->exists($view)) {
            continue;
        }
        $other = view($view, ['locale' => $locale])->render();
        $otherDom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $otherDom->loadHTML('<?xml encoding="UTF-8">'.$other);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $otherXPath = new DOMXPath($otherDom);
        diagnosticsAssert($otherXPath->query('//h1')->length === 1, "$locale: other service has one H1: ".$service['slug']);
        $subtitles = $otherXPath->query('//*[contains(concat(" ", normalize-space(@class), " "), " service-hero-subtitle ")]');
        diagnosticsAssert($subtitles->length === 1, "$locale: other service has one subtitle: ".$service['slug']);
        $subtitle = trim(preg_replace('/\s+/u', ' ', $subtitles->item(0)->textContent));
        $custom = [
            'tesla-navigation-firmware' => [
                'ru' => 'Карты, GPS и маршруты: диагностика, настройка и проверка доступных функций для вашей Tesla.',
                'uk' => 'Карти, GPS і маршрути: діагностика, налаштування та перевірка доступних функцій для вашої Tesla.',
            ],
            'tesla-key-flash' => [
                'ru' => 'Добавление совместимой карты или брелока, проверка активных ключей и доступа владельца.',
                'uk' => 'Додавання сумісної картки або брелока, перевірка активних ключів і доступу власника.',
            ],
        ];
        if (isset($custom[$service['slug']])) {
            diagnosticsAssert($subtitle === $custom[$service['slug']][$locale], "$locale: own current live subtitle retained: ".$service['slug']);
        } else {
            diagnosticsAssert(str_contains($subtitle, $isRu ? 'Узкоспециализированный сервис Tesla по кейсу' : 'Вузькоспеціалізований сервіс Tesla за кейсом'), "$locale: other service subtitle retained: ".$service['slug']);
        }
        diagnosticsAssert(!str_contains($other, $isRu ? 'Стоимость и время диагностики' : 'Вартість і час діагностики'), "$locale: diagnostics content isolated: ".$service['slug']);
    }
    echo "PASS $locale: metadata, single H1, content, links, callback and other service isolation\n";
}
