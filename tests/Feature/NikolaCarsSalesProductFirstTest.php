<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NikolaCarsSalesProductFirstTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_index_uses_product_link_when_catalog_item_id_is_stale(): void
    {
        $user = $this->adminUser();
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);
        $staleCatalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/stale-sale-link',
            'part_number' => '1081439-S0-A',
            'name' => 'Wrong stale catalog name',
            'name_ua' => 'Wrong stale catalog name',
        ]);
        $currentCatalogItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/current-sale-link',
            'part_number' => '1081421-E0-C',
            'name' => 'Current catalog name',
            'name_ua' => 'Current catalog name',
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-CURRENT-SALE',
            'external_sku' => '1081421-E0-C',
            'name' => 'Current product name',
            'slug' => 'current-sale-product',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $currentCatalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_SOLD,
            'selling_price' => 235,
            'currency' => 'USD',
            'is_active' => false,
        ]);
        $currentCatalogItem->forceFill([
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'raw_attributes' => ['product_id' => $product->id],
        ])->save();
        $sale = PartSale::query()->create([
            'part_catalog_item_id' => $staleCatalogItem->id,
            'product_id' => $product->id,
            'donor_car_id' => $donorCar->id,
            'source' => 'nikolacars',
            'code' => 'NC-CURRENT-SALE',
            'part_number' => '1081421-E0-C',
            'name' => 'Historical sale name',
            'quantity' => 1,
            'unit_price' => 235,
            'currency' => 'USD',
            'sold_at' => '2026-05-31',
            'document_number' => 'LEGACY-SALE',
            'source_row_hash' => 'stale-sale-link-product-first',
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.nikolacars-sales.index', ['q' => $sale->document_number]))
            ->assertOk()
            ->assertSee('Historical sale name')
            ->assertSee('href="'.route('admin.products.show', $product).'"', false);

        $row = $this->tableRowContaining($response->getContent(), 'LEGACY-SALE');
        $this->assertStringNotContainsString('Wrong stale catalog name', $row);
    }

    public function test_sales_matched_filter_uses_product_id_not_catalog_item_id(): void
    {
        $user = $this->adminUser('admin-sales-product-filter@example.com');
        $product = Product::query()->create([
            'sku' => 'NC-PRODUCT-LINKED-SALE',
            'external_sku' => '1084168-S0-E',
            'name' => 'Linked product sale',
            'slug' => 'linked-product-sale',
            'storage_status' => Product::STORAGE_STATUS_SOLD,
            'selling_price' => 235,
            'currency' => 'USD',
            'is_active' => false,
        ]);
        PartSale::query()->create([
            'part_catalog_item_id' => null,
            'product_id' => $product->id,
            'source' => 'nikolacars',
            'code' => $product->sku,
            'part_number' => $product->external_sku,
            'name' => 'Linked sale without legacy catalog id',
            'quantity' => 1,
            'unit_price' => 235,
            'currency' => 'USD',
            'document_number' => 'PRODUCT-LINKED',
            'source_row_hash' => 'product-linked-sale-without-catalog-id',
        ]);
        PartSale::query()->create([
            'part_catalog_item_id' => null,
            'product_id' => null,
            'source' => 'nikolacars',
            'code' => 'UNLINKED',
            'part_number' => '0000000-00-A',
            'name' => 'Unlinked sale',
            'quantity' => 1,
            'unit_price' => 1,
            'currency' => 'USD',
            'document_number' => 'UNLINKED-SALE',
            'source_row_hash' => 'unlinked-sale',
        ]);

        $this->actingAs($user)
            ->get(route('admin.nikolacars-sales.index', ['matched' => 'yes']))
            ->assertOk()
            ->assertSee('PRODUCT-LINKED')
            ->assertDontSee('UNLINKED-SALE');

        $this->actingAs($user)
            ->get(route('admin.nikolacars-sales.index', ['matched' => 'no']))
            ->assertOk()
            ->assertSee('UNLINKED-SALE')
            ->assertDontSee('PRODUCT-LINKED');
    }

    protected function adminUser(string $email = 'admin-sales-product-first@example.com'): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    protected function tableRowContaining(string $html, string $needle): string
    {
        if (preg_match('/<tr\b[^>]*>(?:(?!<\/tr>).)*'.preg_quote($needle, '/').'(?:(?!<\/tr>).)*<\/tr>/su', $html, $matches) !== 1) {
            return '';
        }

        return $matches[0];
    }
}
