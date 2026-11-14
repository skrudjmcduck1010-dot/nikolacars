<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function policyAssert(bool $value, string $message): void
{
    if (! $value) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

config([
    'cache.default' => 'array',
    'services.sklad_storefront.token' => 'test',
    'services.sklad_storefront.base_url' => 'https://warehouse.test',
]);
$client = app(App\Services\SkladStorefrontClient::class);
$controller = app(App\Http\Controllers\PartsController::class);
$fixture = [
    'id' => 103754,
    'name' => 'Козирок малого монітора нижній',
    'part_number' => '1002274-00-D',
    'price_uah' => 190,
    'quantity' => 3,
    'condition' => 'used',
    'images' => ['https://warehouse.test/images/part.jpg'],
    'length_cm' => 20,
    'width_cm' => 15,
    'height_cm' => 5,
];

foreach (['uk', 'ru'] as $locale) {
    Cache::flush();
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response($fixture));
    $view = $controller->show($client, '1002274-00-d-103754', $locale);
    $schemas = collect($view->getData()['seoStructuredData']);
    $product = $schemas->firstWhere('@type', 'Product');
    $merchant = $schemas->firstWhere('@type', 'Organization');
    $offer = $product['offers'];
    $shipping = $merchant['hasShippingService'];
    $return = $offer['hasMerchantReturnPolicy'];
    $html = $view->render();

    policyAssert($offer['shippingDetails']['@type'] === 'OfferShippingDetails', "$locale: offer has shipping details");
    policyAssert(array_keys($offer['shippingDetails']) === ['@type', 'hasShippingService'], "$locale: offer uses policy-reference format, not incomplete rate markup");
    policyAssert($offer['shippingDetails']['hasShippingService']['@id'] === $shipping['@id'], "$locale: shipping reference resolves to inline policy");
    policyAssert($offer['seller']['@id'] === $merchant['@id'], "$locale: seller links to merchant organization");
    policyAssert($shipping['@id'] === $offer['url'].'#delivery-and-returns' && str_contains($html, 'id="delivery-and-returns"'), "$locale: policy anchor exists on product page");
    policyAssert($shipping['shippingConditions']['shippingDestination']['addressCountry'] === 'UA', "$locale: shipping is Ukraine only");
    policyAssert(! str_contains(json_encode($shipping), 'shippingRate'), "$locale: no invented carrier cost or free shipping");
    policyAssert(! str_contains(json_encode($shipping), 'weight') && ! str_contains(json_encode($shipping), 'width'), "$locale: product dimensions not used as packed dimensions");
    policyAssert($shipping['handlingTime']['businessDays'] === array_map(fn ($day) => 'https://schema.org/'.$day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']), "$locale: handling excludes weekends");
    policyAssert($shipping['handlingTime']['duration']['minValue'] === 0 && $shipping['handlingTime']['duration']['maxValue'] === 1 && $shipping['handlingTime']['duration']['unitCode'] === 'DAY', "$locale: handling uses conservative whole-day values");
    $offset = (new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv')))->format('P');
    policyAssert($shipping['handlingTime']['cutoffTime'] === '18:00:00'.$offset, "$locale: cutoff uses current Kyiv timezone offset");
    policyAssert($shipping['shippingConditions']['transitTime']['duration'] === ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 3, 'unitCode' => 'DAY'], "$locale: carrier transit is separate from handling");
    policyAssert($return['merchantReturnDays'] === 14 && $return['applicableCountry'] === 'UA', "$locale: return window unchanged");
    policyAssert($return['returnMethod'] === ['https://schema.org/ReturnByMail', 'https://schema.org/ReturnInStore'], "$locale: both owner-confirmed return methods");
    policyAssert($return['returnFees'] === 'https://schema.org/ReturnFeesCustomerResponsibility' && ! isset($return['returnShippingFeesAmount']), "$locale: customer-paid return shipping unchanged");
    policyAssert($offer['price'] === '190.00' && $offer['priceCurrency'] === 'UAH' && $offer['availability'] === 'https://schema.org/InStock', "$locale: price and stock unchanged");
    policyAssert($product['category']['codeValue'] === '5613', "$locale: corrected taxonomy retained");
    policyAssert(str_contains($html, $locale === 'ru' ? 'от 1 до 16 часов' : 'від 1 до 16 годин'), "$locale: exact visible preparation time retained");
    policyAssert(str_contains($html, $locale === 'ru' ? 'отправить Новой почтой или передать лично' : 'надіслати Новою поштою або передати особисто'), "$locale: return methods visible, not just schema");
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $renderedSchemas = [];
    $appendSchemas = function (array $document) use (&$appendSchemas, &$renderedSchemas): void {
        if (array_is_list($document)) {
            foreach ($document as $entry) {
                $appendSchemas($entry);
            }
        } elseif (isset($document['@graph'])) {
            $appendSchemas($document['@graph']);
        } elseif (isset($document['@type'])) {
            $renderedSchemas[] = $document;
        }
    };
    foreach ((new DOMXPath($dom))->query('//script[@type="application/ld+json"]') as $script) {
        $appendSchemas(json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR));
    }
    foreach (['Product', 'Organization', 'BreadcrumbList'] as $type) {
        $matches = array_values(array_filter($renderedSchemas, fn (array $schema): bool => in_array($type, (array) $schema['@type'], true)));
        policyAssert(count($matches) === 1, "$locale: exactly one rendered $type JSON-LD entity");
        if ($type === 'Product') {
            policyAssert($matches[0]['offers']['hasMerchantReturnPolicy'] === $return, "$locale: rendered product preserves verified return policy");
        } elseif ($type === 'Organization') {
            policyAssert($matches[0]['hasShippingService'] === $shipping, "$locale: rendered merchant preserves verified delivery policy");
        }
    }
    Http::assertSentCount(1);
}

foreach (['no-price', 'no-image'] as $case) {
    Cache::flush();
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    $incomplete = $fixture;
    if ($case === 'no-price') {
        $incomplete['price_uah'] = 0;
    } else {
        $incomplete['images'] = [];
    }
    Http::fake(fn () => Http::response($incomplete));
    $schemas = collect($controller->show($client, '1002274-00-d-103754', 'uk')->getData()['seoStructuredData']);
    policyAssert($schemas->firstWhere('@type', 'Product') === null && $schemas->firstWhere('@type', 'Organization') === null, "$case: no incomplete product/policy markup");
    policyAssert($schemas->firstWhere('@type', 'BreadcrumbList') !== null, "$case: breadcrumbs retained");
}

echo "All merchant policy regression checks passed.\n";
