<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Services\TeslaWestPartsCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeslaWestPartsCatalogImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_keeps_teslawestparts_same_part_number_pages_separate(): void
    {
        Http::fake([
            'https://teslawestparts.com.ua/wp-json/wc/store/v1/products*' => Http::sequence()
                ->push([
                    $this->product(
                        9536,
                        'https://teslawestparts.com.ua/goods/podushka-bezopasnosti-voditelya-tesla-model-3/',
                        'podushka-bezopasnosti-voditelya-tesla-model-3',
                        '1508347-00-C',
                        'Подушка безпеки водія Tesla model 3',
                        'Model 3',
                        '2017, 2018, 2019, 2020, 2021, 2022, 2023, 2024',
                    ),
                    $this->product(
                        9537,
                        'https://teslawestparts.com.ua/goods/podushka-bezopasnosti-voditelya-tesla-model-y/',
                        'podushka-bezopasnosti-voditelya-tesla-model-y',
                        '1508347-00-C (2)',
                        'Подушка безпеки водія Tesla model Y',
                        'Model Y',
                        '2020, 2021, 2022, 2023, 2024',
                    ),
                ]),
        ]);

        $stats = app(TeslaWestPartsCatalogImporter::class)->import(['sleep_ms' => 0]);

        $this->assertSame(2, $stats['products_saved']);
        $this->assertSame(2, $stats['products_created']);
        $this->assertSame(0, $stats['products_merged_by_part_number']);

        $items = PartCatalogItem::query()
            ->where('source', 'teslawestparts')
            ->orderBy('source_url')
            ->get();

        $this->assertCount(2, $items);
        $this->assertSame([
            'https://teslawestparts.com.ua/goods/podushka-bezopasnosti-voditelya-tesla-model-3/',
            'https://teslawestparts.com.ua/goods/podushka-bezopasnosti-voditelya-tesla-model-y/',
        ], $items->pluck('source_url')->all());
        $this->assertSame(['1508347-00-C', '1508347-00-C'], $items->pluck('part_number')->all());
        $this->assertSame(['Model 3', 'Model Y'], $items->pluck('model_label')->all());
    }

    protected function product(int $id, string $url, string $slug, string $sku, string $name, string $model, string $years): array
    {
        return [
            'id' => $id,
            'permalink' => $url,
            'slug' => $slug,
            'sku' => $sku,
            'name' => $name,
            'is_in_stock' => true,
            'prices' => [
                'price' => '120000',
                'currency_minor_unit' => 2,
                'currency_code' => 'UAH',
            ],
            'categories' => [
                [
                    'id' => 10,
                    'name' => 'Запчасти Tesla '.$model,
                    'slug' => 'zapchasti-tesla-'.strtolower(str_replace(' ', '-', $model)),
                    'link' => 'https://teslawestparts.com.ua/category/zapchasti-tesla/',
                ],
                [
                    'id' => 20,
                    'name' => 'Салон',
                    'slug' => 'salon',
                    'link' => 'https://teslawestparts.com.ua/category/salon/',
                ],
            ],
            'attributes' => [
                [
                    'taxonomy' => 'pa_model',
                    'name' => 'РњРѕРґРµР»СЊ',
                    'terms' => [['name' => $model]],
                ],
                [
                    'taxonomy' => 'pa_stan',
                    'name' => 'РЎС‚Р°РЅ',
                    'terms' => [['name' => 'РќРѕРІРµ']],
                ],
                [
                    'taxonomy' => 'pa_year-car',
                    'name' => 'Рі',
                    'terms' => [['name' => $years]],
                ],
            ],
            'images' => [],
        ];
    }
}
