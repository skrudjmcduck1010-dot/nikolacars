<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function deliveryLayoutAssert(bool $value, string $message): void
{
    if (! $value) {
        throw new RuntimeException($message);
    }
}

foreach (['uk', 'ru'] as $locale) {
    foreach ([true, false] as $hasDescription) {
        $product = [
            'id' => 103754,
            'name' => 'Козирок малого монітора нижній',
            'model' => 'Model S',
            'part_number' => '1002274-00-D',
            'price_uah' => 190,
            'quantity' => 3,
            'images' => ['https://warehouse.test/part.jpg'],
            'description' => $hasDescription ? 'Опис запчастини.' : '',
        ];
        $html = view('parts.show', compact('locale', 'product'))->render();
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $delivery = $xpath->query('//section[@id="delivery-and-returns"]');
        deliveryLayoutAssert($delivery->length === 1, "$locale: delivery section exists exactly once");
        $node = $delivery->item(0);
        deliveryLayoutAssert($node->parentNode->getAttribute('class') === 'parts-container', "$locale: delivery occupies full content container, outside product columns");
        deliveryLayoutAssert($xpath->query('//section[contains(@class,"product-info")]//section[@id="delivery-and-returns"]')->length === 0, "$locale: no delivery text in purchase column");
        $previous = $xpath->query('preceding-sibling::*[1]', $node)->item(0);
        deliveryLayoutAssert($previous !== null && $previous->getAttribute('class') === 'product-detail', "$locale: delivery follows product details and description");
        deliveryLayoutAssert($xpath->query('.//section[contains(@class,"product-description")]', $previous)->length === ($hasDescription ? 1 : 0), "$locale: original description placement preserved");
        deliveryLayoutAssert($xpath->query('.//p', $node)->length === 6, "$locale: all delivery and return paragraphs retained");
        deliveryLayoutAssert($xpath->query('.//h2', $node)->item(0)->textContent === ($locale === 'ru' ? 'Доставка и возврат' : 'Доставка та повернення'), "$locale: localized heading retained");
        deliveryLayoutAssert(str_contains($node->textContent, $locale === 'ru' ? 'от 1 до 16 часов' : 'від 1 до 16 годин'), "$locale: preparation terms retained");
        deliveryLayoutAssert(str_contains($node->textContent, $locale === 'ru' ? '14 календарных дней' : '14 календарних днів'), "$locale: return window retained");
        deliveryLayoutAssert(str_contains($node->textContent, $locale === 'ru' ? 'отправить Новой почтой или передать лично' : 'надіслати Новою поштою або передати особисто'), "$locale: both return methods retained");
        deliveryLayoutAssert($xpath->query('//*[@data-product-add]')->length === 1, "$locale: purchase button retained");
        echo 'PASS '.$locale.' '.($hasDescription ? 'with' : 'without')." description: full-width delivery block and unchanged terms\n";
    }
}

echo "All product delivery layout checks passed.\n";
