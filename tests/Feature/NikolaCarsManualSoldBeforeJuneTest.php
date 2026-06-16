<?php

namespace Tests\Feature;

use App\Models\CustomerOrder;
use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Models\User;
use App\Services\NikolaCarsInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NikolaCarsManualSoldBeforeJuneTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_sold_before_june_sale_is_cancelled_from_sales_page(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-manual-sold-before-june@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA7KF000777',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2020,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/manual-sold-before-june',
            'part_number' => '1002295-00-D',
            'name' => 'Manual cleanup sold part',
            'name_ua' => 'Manual cleanup sold part',
            'price_amount' => 50,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'CLEANUP-SOLD',
                'stock_quantity' => 1,
                'donor_vin' => $donorCar->vin,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-CLEANUP-SOLD',
            'external_sku' => '1002295-00-D',
            'name' => 'Manual cleanup sold part',
            'slug' => 'nc-cleanup-sold',
            'source_part_catalog_item_id' => $item->id,
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'unit' => 'pcs',
            'selling_price' => 50,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $rawAttributes = $item->raw_attributes->getArrayCopy();
        $rawAttributes['product_id'] = $product->id;
        $item->forceFill(['raw_attributes' => $rawAttributes])->save();

        $this->actingAs($user)
            ->patchJson(route('admin.zapchasti.sold', $item))
            ->assertOk();

        $sale = PartSale::query()->where('part_catalog_item_id', $item->id)->firstOrFail();
        $this->assertSame('2026-05-31', $sale->sold_at->toDateString());
        $this->assertSame('manual-sold-before-june-2026', $sale->document_number);
        $this->assertSame('manual-zapchasti-cleanup', $sale->source_file);
        $this->assertSame('manual-sold-before-june-2026-'.$item->id, $sale->source_row_hash);
        $this->assertFalse(app(NikolaCarsInventoryService::class)->activeItemsQuery()->whereKey($item->id)->exists());
        $this->assertSame(Product::STORAGE_STATUS_SOLD, $product->refresh()->storage_status);
        $this->assertFalse((bool) $product->is_active);

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index', ['q' => 'Manual cleanup sold part']))
            ->assertOk()
            ->assertDontSeeText('Manual cleanup sold part');

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index', [
                'q' => 'Manual cleanup sold part',
                'hide_sold' => '0',
            ]))
            ->assertOk()
            ->assertSeeText('Manual cleanup sold part')
            ->assertSeeText("0 \u{0448}\u{0442}")
            ->assertDontSee('data-cart-id="'.$item->id.'"', false)
            ->assertDontSee('nikolacars-update-'.$item->id, false);

        $this->actingAs($user)
            ->get(route('admin.nikolacars-sales.index', ['q' => 'Manual cleanup sold part']))
            ->assertOk()
            ->assertSeeText('Manual cleanup sold part')
            ->assertSeeText('2026-05-31')
            ->assertSeeText('manual-sold-before-june-2026')
            ->assertSee(route('admin.nikolacars-sales.cancel-manual', $sale), false)
            ->assertSee('href="'.route('admin.products.show', $product).'"', false);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertSeeText('Manual cleanup sold part')
            ->assertSeeText('manual-sold-before-june-2026')
            ->assertSee('href="'.route('admin.products.show', $product).'"', false);

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertDontSee(route('admin.products.edit', $product), false)
            ->assertDontSee(route('admin.products.catalog-name.update', $product), false)
            ->assertDontSee(route('admin.products.photos.store', $product), false);

        $this->actingAs($user)
            ->get(route('admin.products.edit', $product))
            ->assertStatus(422);

        $this->actingAs($user)
            ->patch(route('admin.products.catalog-name.update', $product), [
                'name_type' => 'name_ua',
                'name' => 'Changed sold part name',
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->patchJson(route('admin.zapchasti.update', $item), [
                'notes_ua' => 'Changed sold part description',
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->patch(route('admin.nikolacars-sales.cancel-manual', $sale))
            ->assertRedirect(route('admin.nikolacars-sales.index'));

        $item->refresh();
        $this->assertNull(data_get($item->raw_attributes, 'manual_sold_at'));
        $this->assertEquals(1.0, data_get($item->raw_attributes, 'stock_quantity'));
        $this->assertSame(Product::STORAGE_STATUS_ON_DONOR, $product->refresh()->storage_status);
        $this->assertTrue((bool) $product->is_active);
        $this->assertDatabaseMissing('part_sales', [
            'id' => $sale->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index', ['q' => 'Manual cleanup sold part']))
            ->assertOk()
            ->assertSeeText('Manual cleanup sold part');
    }

    public function test_manual_sold_before_june_allows_long_raw_donor_identity(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-manual-sold-long-donor@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $longDonorIdentity = 'TESLA MS 2015 - 2021 leftovers imported donor group';
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/manual-sold-long-donor',
            'part_number' => '1002295-00-L',
            'name' => 'Manual cleanup long donor identity part',
            'name_ua' => 'Manual cleanup long donor identity part',
            'price_amount' => 75,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'CLEANUP-LONG-DONOR',
                'stock_quantity' => 1,
                'donor_vin' => $longDonorIdentity,
            ],
        ]);

        $this->actingAs($user)
            ->patchJson(route('admin.zapchasti.sold', $item))
            ->assertOk();

        $sale = PartSale::query()->where('part_catalog_item_id', $item->id)->firstOrFail();

        $this->assertNull($sale->donor_vin);
        $this->assertSame($longDonorIdentity, data_get($sale->raw_attributes, 'original_donor_vin'));
        $this->assertSame('2026-05-31', $sale->sold_at->toDateString());
    }

    public function test_zapchasti_index_keeps_manual_sold_items_as_zero_stock(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-manual-sold-filter@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $soldItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/manual-sold-filter',
            'part_number' => '1002295-00-F',
            'name' => 'Manual sold filter part',
            'name_ua' => 'Manual sold filter part',
            'price_amount' => 40,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'CLEANUP-SOLD-FILTER',
                'stock_quantity' => 1,
            ],
        ]);

        $this->actingAs($user)
            ->patchJson(route('admin.zapchasti.sold', $soldItem))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index', ['q' => 'CLEANUP-SOLD-FILTER']))
            ->assertOk()
            ->assertSeeText("\u{041d}\u{0435} \u{043e}\u{0442}\u{043e}\u{0431}\u{0440}\u{0430}\u{0436}\u{0430}\u{0442}\u{044c} \u{043f}\u{0440}\u{043e}\u{0434}\u{0430}\u{043d}\u{043d}\u{044b}\u{0435}")
            ->assertDontSeeText('Manual sold filter part');

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index', [
                'q' => 'CLEANUP-SOLD-FILTER',
                'hide_sold' => '0',
            ]))
            ->assertOk()
            ->assertSeeText('Manual sold filter part')
            ->assertSeeText("\u{041f}\u{0440}\u{043e}\u{0434}\u{0430}\u{043d}\u{043e} \u{0434}\u{043e} 01.06.2026");
    }

    public function test_manual_sold_before_june_allows_long_donor_car_identity(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-manual-sold-long-donor-car@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $longDonorIdentity = 'TESLA MY 2020 - 2023 leftovers';
        $donorCar = DonorCar::query()->create([
            'vin' => $longDonorIdentity,
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2020,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/manual-sold-long-donor-car',
            'part_number' => '1100055-01-C',
            'name' => 'Manual cleanup long donor car identity part',
            'name_ua' => 'Manual cleanup long donor car identity part',
            'price_amount' => 40,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'CLEANUP-LONG-DONOR-CAR',
                'stock_quantity' => 1,
                'donor_vin' => $longDonorIdentity,
            ],
        ]);
        Product::query()->create([
            'sku' => 'NC-CLEANUP-LONG-DONOR-CAR',
            'external_sku' => '1100055-01-C',
            'name' => 'Manual cleanup long donor car identity part',
            'slug' => 'nc-cleanup-long-donor-car',
            'source_part_catalog_item_id' => $item->id,
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'unit' => 'pcs',
            'selling_price' => 40,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patchJson(route('admin.zapchasti.sold', $item))
            ->assertOk();

        $sale = PartSale::query()->where('part_catalog_item_id', $item->id)->firstOrFail();

        $this->assertSame($donorCar->id, $sale->donor_car_id);
        $this->assertNull($sale->donor_vin);
        $this->assertSame($donorCar->vin, data_get($sale->raw_attributes, 'original_donor_vin'));
    }

    public function test_backfilled_manual_sold_orphan_product_is_visible_and_cancelled(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-manual-sold-orphan-product@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EA0LF611657',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2019,
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-582',
            'external_sku' => '5YJ3E1EA0LF611657',
            'name' => 'РђРєСѓРјСѓР»СЏС‚РѕСЂРЅР° Р±Р°С‚Р°СЂРµСЏ Р’Р’Р‘ РІ Р·Р±РѕСЂС– 52 РєР’С‚ 5YJ3E1EA0LF611657',
            'slug' => 'nc-582-orphan-battery',
            'source_part_catalog_item_id' => 155102,
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_SOLD,
            'unit' => 'pcs',
            'selling_price' => 3400,
            'currency' => 'USD',
            'is_active' => false,
        ]);
        $sale = PartSale::query()->create([
            'part_catalog_item_id' => null,
            'donor_car_id' => $donorCar->id,
            'source' => 'nikolacars',
            'code' => $product->sku,
            'part_number' => $product->external_sku,
            'name' => $product->name,
            'quantity' => 1,
            'unit_price' => 3400,
            'currency' => 'USD',
            'sold_at' => '2026-05-31',
            'document_number' => 'manual-sold-before-june-2026',
            'counterparty' => 'Cleanup before 01.06.2026',
            'donor_vin' => $donorCar->vin,
            'raw_attributes' => [
                'manual_cleanup' => true,
                'manual_sold_at' => '2026-05-31',
                'restorable_from_zapchasti_sold' => true,
                'backfilled_by_migration' => true,
                'product_id' => $product->id,
                'missing_part_catalog_item_id' => 155102,
            ],
            'source_file' => 'manual-zapchasti-cleanup',
            'source_row_number' => $product->id,
            'source_row_hash' => 'manual-sold-before-june-2026-product-'.$product->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.nikolacars-sales.index', ['q' => 'NC-582']))
            ->assertOk()
            ->assertSeeText($product->name)
            ->assertSeeText('2026-05-31')
            ->assertSee(route('admin.nikolacars-sales.cancel-manual', $sale), false);

        $this->actingAs($user)
            ->get(route('admin.donor-cars.show', $donorCar))
            ->assertOk()
            ->assertSeeText($product->name)
            ->assertSeeText('manual-sold-before-june-2026')
            ->assertSee('href="'.route('admin.products.show', $product).'"', false);

        $this->actingAs($user)
            ->patch(route('admin.nikolacars-sales.cancel-manual', $sale))
            ->assertRedirect(route('admin.nikolacars-sales.index'));

        $this->assertSame(Product::STORAGE_STATUS_ON_DONOR, $product->refresh()->storage_status);
        $this->assertTrue((bool) $product->is_active);
        $this->assertDatabaseMissing('part_sales', [
            'id' => $sale->id,
        ]);
    }

    public function test_duplicate_manual_sold_orphan_product_sale_is_hidden_when_linked_sale_exists(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-manual-sold-duplicate-product@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/duplicate-manual-sold',
            'part_number' => '1081460-11-D',
            'name' => 'Duplicate manual sold part',
            'name_ua' => 'Duplicate manual sold part',
            'price_amount' => 300,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'NC-DUP',
                'stock_quantity' => 0,
                'manual_sold_at' => '2026-05-31',
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-DUP',
            'external_sku' => '1081460-11-D',
            'name' => 'Duplicate manual sold part',
            'slug' => 'nc-dup',
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => Product::STORAGE_STATUS_SOLD,
            'unit' => 'pcs',
            'selling_price' => 300,
            'currency' => 'USD',
            'is_active' => false,
        ]);
        $rawAttributes = $item->raw_attributes->getArrayCopy();
        $rawAttributes['product_id'] = $product->id;
        $item->forceFill(['raw_attributes' => $rawAttributes])->save();

        $linkedSale = PartSale::query()->create([
            'part_catalog_item_id' => $item->id,
            'source' => 'nikolacars',
            'code' => 'NC-DUP',
            'part_number' => '1081460-11-D',
            'name' => 'Duplicate manual sold part',
            'quantity' => 1,
            'unit_price' => 300,
            'currency' => 'USD',
            'sold_at' => '2026-05-31',
            'document_number' => 'manual-sold-before-june-2026',
            'raw_attributes' => [
                'product_id' => $product->id,
                'manual_sold_at' => '2026-05-31',
            ],
            'source_file' => 'manual-zapchasti-cleanup',
            'source_row_number' => $item->id,
            'source_row_hash' => 'manual-sold-before-june-2026-'.$item->id,
        ]);
        $orphanProductSale = PartSale::query()->create([
            'part_catalog_item_id' => null,
            'source' => 'nikolacars',
            'code' => 'NC-DUP',
            'part_number' => '1081460-11-D',
            'name' => 'Duplicate manual sold part',
            'quantity' => 1,
            'unit_price' => 300,
            'currency' => 'USD',
            'sold_at' => '2026-05-31',
            'document_number' => 'manual-sold-before-june-2026',
            'raw_attributes' => [
                'product_id' => $product->id,
                'manual_sold_at' => '2026-05-31',
            ],
            'source_file' => 'manual-zapchasti-cleanup',
            'source_row_number' => $product->id,
            'source_row_hash' => 'manual-sold-before-june-2026-product-'.$product->id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.nikolacars-sales.index', ['q' => 'NC-DUP']))
            ->assertOk()
            ->assertSeeText('Duplicate manual sold part')
            ->assertDontSeeText('РќРµ РЅР°Р№РґРµРЅР° СЃС‚СЂРѕРєР° РєР°С‚Р°Р»РѕРіР° РїРѕ РєРѕРґСѓ');

        $this->assertSame(1, substr_count($response->getContent(), route('admin.products.show', $product)));

        $this->actingAs($user)
            ->patch(route('admin.nikolacars-sales.cancel-manual', $linkedSale))
            ->assertRedirect(route('admin.nikolacars-sales.index'));

        $this->assertDatabaseMissing('part_sales', ['id' => $linkedSale->id]);
        $this->assertDatabaseMissing('part_sales', ['id' => $orphanProductSale->id]);

        $this->actingAs($user)
            ->get(route('admin.nikolacars-sales.index', ['q' => 'NC-DUP']))
            ->assertOk()
            ->assertDontSeeText('Duplicate manual sold part')
            ->assertDontSeeText('manual-sold-before-june-2026');
    }

    public function test_manual_sold_before_june_button_is_disabled_for_reserved_items(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-manual-sold-reserved@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/manual-sold-reserved',
            'part_number' => '1002295-00-R',
            'name' => 'Reserved manual cleanup part',
            'name_ua' => 'Reserved manual cleanup part',
            'price_amount' => 60,
            'currency' => 'USD',
            'raw_attributes' => [
                'code' => 'CLEANUP-RESERVED',
                'stock_quantity' => 1,
            ],
        ]);
        $order = CustomerOrder::query()->create([
            'number' => 'ORD-20260602-0001',
            'status' => CustomerOrder::STATUS_PROCESSING,
            'total_amount' => 60,
            'currency' => 'USD',
        ]);
        $order->items()->create([
            'part_catalog_item_id' => $item->id,
            'name' => 'Reserved manual cleanup part',
            'quantity' => 1,
            'unit_price' => 60,
            'total_price' => 60,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->get(route('admin.zapchasti.index', ['q' => 'Reserved manual cleanup part']))
            ->assertOk()
            ->assertSeeText('Reserved manual cleanup part')
            ->assertSee('title="&#1053;&#1077;&#1083;&#1100;&#1079;&#1103; &#1087;&#1088;&#1086;&#1076;&#1072;&#1090;&#1100;: &#1087;&#1086;&#1079;&#1080;&#1094;&#1080;&#1103; &#1074; &#1088;&#1077;&#1079;&#1077;&#1088;&#1074;&#1077;"', false)
            ->assertSee('disabled', false)
            ->assertDontSee('action="'.route('admin.zapchasti.sold', $item).'"', false);
    }
}
