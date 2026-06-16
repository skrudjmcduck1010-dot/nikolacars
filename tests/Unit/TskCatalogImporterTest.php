<?php

namespace Tests\Unit;

use App\Services\TskCatalogImporter;
use Illuminate\Http\Client\Factory as HttpFactory;
use ReflectionMethod;
use Tests\TestCase;

class TskCatalogImporterTest extends TestCase
{
    public function test_localized_name_payload_detects_language_from_tsk_name(): void
    {
        $importer = new TskCatalogImporter(new HttpFactory);

        $this->assertSame(
            ['name_ru' => 'Крыло переднее левое'],
            $importer->localizedNamePayload('Крыло переднее левое')
        );
        $this->assertSame(
            ['name_ru' => 'Кронштейн сидения 2-го ряда правая сторона'],
            $importer->localizedNamePayload('Кронштейн сидения 2-го ряда правая сторона')
        );
        $this->assertSame(
            ['name_ua' => 'Крило переднє ліве'],
            $importer->localizedNamePayload('Крило переднє ліве')
        );
        $this->assertSame([], $importer->localizedNamePayload('FRONT END CARRIER, MS2'));
    }

    public function test_product_details_extracts_name_price_condition_and_availability(): void
    {
        $importer = new TskCatalogImporter(new HttpFactory);
        $html = <<<'HTML'
        <div class="one-tovar">
            <div class="one-tovar__gallery">
                <a href="/data/tovars/17/10/1710_a.jpg" class="gallery-image one-tovar__gallery__bigimage">
                    <img src="/data/tovars/17/10/1710_a.jpg">
                </a>
                <a href="/data/tovars/17/10/1710_747.jpg" class="gallery-image one-tovar__gallery__bigimage">
                    <img src="/data/tovars/17/10/1710_747.jpg">
                </a>
            </div>
            <div class="one-tovar__nal">В НАЯВНОСТІ</div>
            <h1 class="one-tovar__name" itemprop="name">Кронштейн фары противотуманной левой</h1>
            <div class="one-tovar__specif">Модель авто:<b>Model SR</b></div>
            <div class="one-tovar__specif">Стан:<button type="button" data-status="sh">б/у</button></div>
            <div data-status="sh" class="one-tovar__price hidden">
                Ціна:<span itemprop="price" content="31">31</span><span itemprop="priceCurrency" content="USD">USD</span>
            </div>
            <meta property="og:image" content="https://tsk.ua/image.jpeg">
        </div>
        HTML;

        $page = $this->invoke($importer, 'page', [$html]);
        $details = $this->invoke($importer, 'productDetailsFromPage', [$page, 'https://tsk.ua/1003124-00-f/']);

        $this->assertSame('Кронштейн фары противотуманной левой', $details['name']);
        $this->assertSame(31.0, $details['price_amount']);
        $this->assertSame('USD', $details['currency']);
        $this->assertSame('Б/У', $details['condition']);
        $this->assertSame('В НАЯВНОСТІ', $details['availability']);
        $this->assertSame('Model SR', $details['compatibility_text']);
        $this->assertSame('https://tsk.ua/data/tovars/17/10/1710_a.jpg', $details['image_url']);
        $this->assertSame([
            'https://tsk.ua/data/tovars/17/10/1710_a.jpg',
            'https://tsk.ua/data/tovars/17/10/1710_747.jpg',
        ], $details['image_urls']);
    }

    public function test_product_details_ignores_tsk_placeholder_image(): void
    {
        $importer = new TskCatalogImporter(new HttpFactory);
        $html = <<<'HTML'
        <div class="one-tovar">
            <h1 class="one-tovar__name" itemprop="name">INSULATOR,ACCESS,SHUNT</h1>
            <div class="one-tovar__gallery">
                <a href="/datacache/7/5/b/d/7/75bd7c3f97912998faad55cf0790b015b3feae79.png" class="gallery-image">
                    <img src="/datacache/7/5/b/d/7/75bd7c3f97912998faad55cf0790b015b3feae79.png">
                </a>
            </div>
            <meta property="og:image" content="https://tsk.ua/datacache/7/5/b/d/7/75bd7c3f97912998faad55cf0790b015b3feae79.png">
        </div>
        HTML;

        $page = $this->invoke($importer, 'page', [$html]);
        $details = $this->invoke($importer, 'productDetailsFromPage', [$page, 'https://tsk.ua/1088608-00-e/']);

        $this->assertArrayNotHasKey('image_url', $details);
        $this->assertArrayNotHasKey('image_urls', $details);
    }

    protected function invoke(object $object, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
