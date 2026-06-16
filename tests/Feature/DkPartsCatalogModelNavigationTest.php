<?php

namespace Tests\Feature;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use App\Models\User;
use App\Services\DkPartsCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DkPartsCatalogModelNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dkparts_root_navigation_includes_legacy_plaid_and_after_2016_model_labels(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        foreach ([
            ['https://dk-parts.com.ua/ru/model-s-after-2016/', 'Model S after 2016'],
            ['https://dk-parts.com.ua/ru/model-s-plaid/', 'Model S Plaid'],
            ['https://dk-parts.com.ua/ru/model-x-plaid/', 'Model X Plaid'],
        ] as [$sourceUrl, $modelLabel]) {
            PartCatalogCategory::query()->create([
                'source' => 'dkparts',
                'source_url' => $sourceUrl,
                'depth' => 0,
                'name' => $modelLabel,
                'model_label' => $modelLabel,
                'model_name' => $modelLabel,
                'parent_id' => null,
            ]);
        }

        $this->actingAs($user)
            ->get(route('admin.dkparts-catalog.index'))
            ->assertOk()
            ->assertSee('Model S after 2016')
            ->assertSee('Model S Plaid')
            ->assertSee('Model X Plaid');
    }

    public function test_dkparts_model_root_categories_keep_competitor_model_labels(): void
    {
        $importer = app(DkPartsCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'modelRootCategories');
        $method->setAccessible(true);

        $categories = $method->invoke($importer, 'https://dk-parts.com.ua', true)->keyBy('source_url');

        $this->assertSame(
            'Model S после 2016',
            $categories->get('https://dk-parts.com.ua/ru/model-s-after-2016/')->model_label
        );
        $this->assertSame(
            'Model S Plaid',
            $categories->get('https://dk-parts.com.ua/ru/model-s-plaid/')->model_label
        );
        $this->assertSame(
            'Model X Plaid',
            $categories->get('https://dk-parts.com.ua/ru/model-x-plaid/')->model_label
        );
    }

    public function test_dkparts_tcars_model_paths_open_competitor_model_categories(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-routes@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-s-321',
            'depth' => 0,
            'name' => 'TCARS Model S',
            'model_label' => 'TCARS Model S',
            'model_name' => 'Model S',
            'parent_id' => null,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.com/zapchasty/model-s2-322',
            'depth' => 0,
            'name' => 'TCARS Model S2',
            'model_label' => 'TCARS Model S2',
            'model_name' => 'Model S2',
            'parent_id' => null,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.com.ua/ru/model-s-before-2016/',
            'depth' => 0,
            'name' => 'Model S до 2016',
            'model_label' => 'Model S до 2016',
            'model_name' => 'Model S',
            'parent_id' => null,
        ]);

        PartCatalogCategory::query()->create([
            'source' => 'dkparts',
            'source_url' => 'https://dk-parts.com.ua/ru/model-s-after-2016/',
            'depth' => 0,
            'name' => 'Model S после 2016',
            'model_label' => 'Model S после 2016',
            'model_name' => 'Model S',
            'parent_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dkparts-catalog.category', ['catalogPath' => 'model-s-321']))
            ->assertOk()
            ->assertSee('Model S до 2016');

        $this->actingAs($user)
            ->get(route('admin.dkparts-catalog.category', ['catalogPath' => 'model-s2-322']))
            ->assertOk()
            ->assertSee('Model S после 2016');
    }

    public function test_dkparts_import_keeps_root_occurrences_for_shared_products_across_model_listings(): void
    {
        $sharedUrl = 'https://dk-parts.com.ua/ru/model-s-before-2016/31-suspension-before-2016/104396600a-2';

        PartCatalogItem::query()->create([
            'source' => 'dkparts',
            'source_url' => $sharedUrl,
            'part_number' => '1043966-00-A',
            'name' => 'Shared suspension arm',
        ]);

        Http::fake([
            'https://dk-parts.com.ua/ru/model-s-before-2016/?limit=10000' => Http::response($this->dkPartsListingHtml($sharedUrl), 200),
            'https://dk-parts.com.ua/ru/model-s-after-2016/?limit=10000' => Http::response($this->dkPartsListingHtml($sharedUrl), 200),
        ]);

        $stats = app(DkPartsCatalogImporter::class)->importProducts([
            'max_categories' => 2,
            'rescan' => true,
            'sleep_ms' => 0,
        ]);

        $beforeCategory = PartCatalogCategory::query()
            ->where('source_url', 'https://dk-parts.com.ua/ru/model-s-before-2016/')
            ->firstOrFail();
        $afterCategory = PartCatalogCategory::query()
            ->where('source_url', 'https://dk-parts.com.ua/ru/model-s-after-2016/')
            ->firstOrFail();

        $this->assertSame(2, $stats['products_found']);
        $this->assertSame(2, PartCatalogItemOccurrence::query()->count());
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'source' => 'dkparts',
            'part_catalog_category_id' => $beforeCategory->id,
            'product_url' => $sharedUrl,
        ]);
        $this->assertDatabaseHas('part_catalog_item_occurrences', [
            'source' => 'dkparts',
            'part_catalog_category_id' => $afterCategory->id,
            'product_url' => $sharedUrl,
        ]);
    }

    private function dkPartsListingHtml(string $productUrl): string
    {
        return <<<HTML
        <html>
            <body>
                <div class="product-layout">
                    <div class="product-thumb">
                        <div class="h4"><a href="{$productUrl}">1043966-00-A Shared suspension arm</a></div>
                        <span class="model_">1043966-00-A</span>
                    </div>
                </div>
            </body>
        </html>
        HTML;
    }
}
