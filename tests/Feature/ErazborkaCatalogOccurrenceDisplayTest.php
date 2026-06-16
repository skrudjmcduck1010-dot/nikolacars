<?php

namespace Tests\Feature;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use App\Models\User;
use App\Services\ErazborkaCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use ReflectionClass;
use Tests\TestCase;

class ErazborkaCatalogOccurrenceDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_erazborka_category_shows_items_from_listing_occurrences(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-erazborka-occurrences@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $selectedCategory = PartCatalogCategory::query()->create([
            'source' => 'erazborka',
            'source_url' => 'https://erazborka.com.ua/catalog/aksessuary-tesla-model-s/',
            'depth' => 1,
            'name' => 'Accessories',
            'model_label' => 'Model S',
            'model_name' => 'Model S',
        ]);

        $canonicalCategory = PartCatalogCategory::query()->create([
            'source' => 'erazborka',
            'source_url' => 'https://erazborka.com.ua/catalog/detali-kuzova-tesla-model-s/',
            'depth' => 1,
            'name' => 'Body',
            'model_label' => 'Model S',
            'model_name' => 'Model S',
        ]);

        $item = PartCatalogItem::query()->create([
            'part_catalog_category_id' => $canonicalCategory->id,
            'source' => 'erazborka',
            'source_url' => 'https://erazborka.com.ua/catalog/zapchasti-tesla-model-s/test-mirror/',
            'part_number' => '1041317-00-G',
            'name' => 'Left mirror Tesla Model S',
            'price_amount' => 2500,
            'currency' => 'UAH',
        ]);

        PartCatalogItemOccurrence::query()->create([
            'part_catalog_item_id' => $item->id,
            'part_catalog_category_id' => $selectedCategory->id,
            'source' => 'erazborka',
            'occurrence_key' => 'erazborka-test-model-s-accessories',
            'page_url' => $selectedCategory->source_url,
            'product_url' => $item->source_url,
            'part_number' => $item->part_number,
            'name' => $item->name,
        ]);

        $this->actingAs($user)
            ->get(route('admin.erazborka-catalog.index', ['category_id' => $selectedCategory->id]))
            ->assertOk()
            ->assertSee('Left mirror Tesla Model S')
            ->assertSee('1041317-00-G');
    }

    public function test_erazborka_part_number_normalization_strips_trailing_price_digits(): void
    {
        $importer = app(ErazborkaCatalogImporter::class);
        $method = (new ReflectionClass($importer))->getMethod('canonicalPartNumber');
        $method->setAccessible(true);

        $this->assertSame('1049612-00-F', $method->invoke($importer, '1049612-00-F898'));
        $this->assertSame('1041317-00-G', $method->invoke($importer, '1041317-00-G7685'));
        $this->assertSame('1714047-E0-G', $method->invoke($importer, '1714047-E0-G-ASR'));
        $this->assertSame('1081390-E0-C', $method->invoke($importer, '1081390-E0-C-ASR8385'));
        $this->assertSame('1081390-E0-C', $method->invoke($importer, '1081390-E0-C-TC'));
        $this->assertSame('1091879-S4-A', $method->invoke($importer, '1091879-S4-A'));
    }
}
