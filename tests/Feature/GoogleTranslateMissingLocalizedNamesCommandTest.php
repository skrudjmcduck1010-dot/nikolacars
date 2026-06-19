<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleTranslateMissingLocalizedNamesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_translates_missing_ukrainian_name_from_existing_russian_name(): void
    {
        Config::set('services.google_translate.key', 'fake-google-key');
        Config::set('services.google_translate.allow_in_testing', true);

        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/74880',
            'part_number' => '1034344-20-B',
            'name' => 'Front bumper bracket',
            'name_ru' => 'Кронштейн переднего бампера',
            'name_ua' => null,
        ]);

        Http::fake([
            'translation.googleapis.com/*' => Http::response([
                'data' => [
                    'translations' => [
                        ['translatedText' => 'Кронштейн переднього бампера'],
                    ],
                ],
            ]),
        ]);

        $this->artisan('parts:google-translate-missing-localized-names', [
            '--only-id' => $item->id,
        ])->assertExitCode(0);

        $item->refresh();

        $this->assertSame('Кронштейн переднего бампера', $item->name_ru);
        $this->assertSame('Кронштейн переднього бампера', $item->name_ua);
        $this->assertSame('Google Translate', data_get($item->raw_attributes, 'name_source_site_ua'));
        $this->assertSame('one_time_google_translate_backfill', data_get($item->raw_attributes, 'name_source_type_ua'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://translation.googleapis.com/language/translate/v2'
            && ($request->data()['q'] ?? null) === 'Кронштейн переднего бампера'
            && ($request->data()['source'] ?? null) === 'ru'
            && ($request->data()['target'] ?? null) === 'uk');
    }

    public function test_translates_both_missing_names_from_english_name(): void
    {
        Config::set('services.google_translate.key', 'fake-google-key');
        Config::set('services.google_translate.allow_in_testing', true);

        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/100',
            'part_number' => '1084174-00-G',
            'name' => 'Front Bumper',
            'name_en' => 'Front Bumper',
        ]);

        Http::fake([
            'translation.googleapis.com/*' => Http::sequence()
                ->push([
                    'data' => [
                        'translations' => [
                            ['translatedText' => 'Передний бампер'],
                        ],
                    ],
                ])
                ->push([
                    'data' => [
                        'translations' => [
                            ['translatedText' => 'Передній бампер'],
                        ],
                    ],
                ]),
        ]);

        $this->artisan('parts:google-translate-missing-localized-names', [
            '--only-id' => $item->id,
        ])->assertExitCode(0);

        $item->refresh();

        $this->assertSame('Передний бампер', $item->name_ru);
        $this->assertSame('Передній бампер', $item->name_ua);
        $this->assertSame('en', data_get($item->raw_attributes, 'name_source_language_ru'));
        $this->assertSame('en', data_get($item->raw_attributes, 'name_source_language_ua'));

        Http::assertSentCount(2);
    }

    public function test_dry_run_does_not_save_translation(): void
    {
        Config::set('services.google_translate.key', 'fake-google-key');
        Config::set('services.google_translate.allow_in_testing', true);

        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/101',
            'name' => 'Front Bumper',
            'name_ru' => 'Передний бампер',
        ]);

        Http::fake([
            'translation.googleapis.com/*' => Http::response([
                'data' => [
                    'translations' => [
                        ['translatedText' => 'Передній бампер'],
                    ],
                ],
            ]),
        ]);

        $this->artisan('parts:google-translate-missing-localized-names', [
            '--only-id' => $item->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $item->refresh();

        $this->assertSame('Передний бампер', $item->name_ru);
        $this->assertNull($item->name_ua);
        Http::assertSentCount(1);
    }

    public function test_can_target_catalog_item_by_product_id(): void
    {
        Config::set('services.google_translate.key', 'fake-google-key');
        Config::set('services.google_translate.allow_in_testing', true);

        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/102',
            'name' => 'Front Bumper',
            'name_ru' => 'Передний бампер',
            'raw_attributes' => [
                'product_id' => 102,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'GOOGLE-PRODUCT-ID',
            'external_sku' => 'GOOGLE-PRODUCT-ID',
            'name' => 'Front Bumper',
            'slug' => 'google-product-id',
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        Http::fake([
            'translation.googleapis.com/*' => Http::response([
                'data' => [
                    'translations' => [
                        ['translatedText' => 'Передній бампер'],
                    ],
                ],
            ]),
        ]);

        $this->artisan('parts:google-translate-missing-localized-names', [
            '--product-id' => $product->id,
        ])->assertExitCode(0);

        $this->assertSame('Передній бампер', $item->refresh()->name_ua);
        Http::assertSentCount(1);
    }
}
