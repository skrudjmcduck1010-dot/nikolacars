<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PartCatalogCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CategoryTcarsSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_sync_categories_from_tcars_catalog(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $model3 = PartCatalogCategory::query()->create([
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326',
            'depth' => 0,
            'name' => 'Model 3',
            'model_label' => 'Model 3',
            'sort_order' => 1,
        ]);

        $body = PartCatalogCategory::query()->create([
            'parent_id' => $model3->id,
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/body',
            'depth' => 1,
            'code' => '10',
            'name' => 'Body',
            'model_label' => 'Model 3',
            'sort_order' => 1,
        ]);

        $legacyDuplicate = Category::query()->create([
            'name' => 'Model 3 / 10 - Body',
            'slug' => 'tcars-'.$body->id.'-model-3-10-body',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'sku' => 'ZAK-000001',
            'name' => 'Legacy product',
            'slug' => 'legacy-product',
            'category_id' => $legacyDuplicate->id,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'UAH',
            'is_active' => true,
        ]);

        PartCatalogCategory::query()->create([
            'parent_id' => $body->id,
            'source_url' => 'https://tcarservice.com/zapchasty/model-3-326/body/front-bumper',
            'depth' => 2,
            'code' => '1001',
            'name' => 'Front bumper',
            'model_label' => 'Model 3',
            'sort_order' => 1,
        ]);

        $modelY = PartCatalogCategory::query()->create([
            'source_url' => 'https://tcarservice.com/zapchasty/model-y-324',
            'depth' => 0,
            'name' => 'Model Y',
            'model_label' => 'Model Y',
            'sort_order' => 2,
        ]);

        $bodyY = PartCatalogCategory::query()->create([
            'parent_id' => $modelY->id,
            'source_url' => 'https://tcarservice.com/zapchasty/model-y-324/body',
            'depth' => 1,
            'code' => '10',
            'name' => 'Body',
            'model_label' => 'Model Y',
            'sort_order' => 1,
        ]);

        PartCatalogCategory::query()->create([
            'parent_id' => $bodyY->id,
            'source_url' => 'https://tcarservice.com/zapchasty/model-y-324/body/front-bumper',
            'depth' => 2,
            'code' => '1001',
            'name' => 'Front bumper',
            'model_label' => 'Model Y',
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('admin.categories.sync-tcars'))
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('categories', [
            'name' => '10 - Body',
            'slug' => 'tcars-10-body',
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('categories', [
            'slug' => 'tcars-'.$body->id.'-model-3-10-body',
        ]);

        $product->refresh();
        $this->assertSame('tcars-10-body', $product->category->slug);

        $this->actingAs($user)->post(route('admin.categories.sync-tcars'));

        $this->assertSame(2, Category::query()->count());
    }
}
