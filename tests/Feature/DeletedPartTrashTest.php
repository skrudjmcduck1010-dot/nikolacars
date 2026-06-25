<?php

namespace Tests\Feature;

use App\Models\DeletedPart;
use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeletedPartTrashTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_nikolacars_item_archives_it_and_removes_linked_donor_product(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EB8JF091651',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2018,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/73634',
            'part_number' => '1044831-99-E',
            'name' => 'Steering gear',
            'name_ru' => 'Рулевая рейка',
            'raw_attributes' => [
                'code' => '73634',
                'donor_vin' => $donorCar->vin,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-73634',
            'external_sku' => '1044831-99-E',
            'name' => 'Steering gear',
            'slug' => 'nc-73634',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'tested',
            'unit' => 'pcs',
            'selling_price' => 250,
            'currency' => 'USD',
            'notes' => 'Без повреждений',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.zapchasti.destroy', $item))
            ->assertRedirect();

        $this->assertDatabaseMissing('part_catalog_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseHas('deleted_parts', [
            'source' => 'nikolacars',
            'part_catalog_item_id' => $item->id,
            'product_id' => $product->id,
            'donor_car_id' => $donorCar->id,
            'part_number' => '1044831-99-E',
        ]);

        $deletedPart = DeletedPart::query()->firstOrFail();
        $this->assertSame('NC-73634', data_get($deletedPart->related_product_snapshots, '0.sku'));
    }

    public function test_deleting_nikolacars_projection_keeps_tesla_catalog_reference(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EB8JF091651',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2018,
        ]);
        $teslaCatalogItem = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/1044831-99-e',
            'part_number' => '1044831-99-E',
            'name' => 'STEERING GEAR ASSEMBLY - LEFT HAND DRIVE',
            'name_ru' => 'Рулевая рейка',
        ]);
        $product = Product::query()->create([
            'sku' => 'DON28-1260',
            'external_sku' => '1044831-99-E',
            'name' => 'STEERING GEAR ASSEMBLY - LEFT HAND DRIVE',
            'slug' => 'DON28-1260',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $teslaCatalogItem->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'tested',
            'unit' => 'pcs',
            'selling_price' => 250,
            'currency' => 'USD',
            'notes' => 'Без повреждений',
            'is_active' => true,
        ]);
        $nikolaCarsItem = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1044831-99-E',
            'name' => 'STEERING GEAR ASSEMBLY - LEFT HAND DRIVE',
            'raw_attributes' => [
                'product_id' => $product->id,
                'source_catalog_item_id' => $teslaCatalogItem->id,
                'source_catalog_source' => 'tesla_official',
                'donor_vin' => $donorCar->vin,
            ],
        ]);

        $this->actingAs($user)
            ->delete(route('admin.zapchasti.destroy', $nikolaCarsItem))
            ->assertRedirect();

        $this->assertDatabaseMissing('part_catalog_items', ['id' => $nikolaCarsItem->id]);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseHas('part_catalog_items', [
            'id' => $teslaCatalogItem->id,
            'source' => 'tesla_official',
            'part_number' => '1044831-99-E',
        ]);
        $this->assertDatabaseHas('deleted_parts', [
            'source' => 'nikolacars',
            'part_catalog_item_id' => $nikolaCarsItem->id,
            'product_id' => $product->id,
            'donor_car_id' => $donorCar->id,
        ]);
    }

    public function test_deleting_product_archives_it_and_removes_nikolacars_projection(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EB8JF091651',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2018,
        ]);
        $product = Product::query()->create([
            'sku' => 'DON28-1260',
            'external_sku' => '1044831-99-E',
            'name' => 'Steering gear',
            'slug' => 'DON28-1260',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'tested',
            'unit' => 'pcs',
            'selling_price' => 250,
            'currency' => 'USD',
            'notes' => 'Без повреждений',
            'is_active' => true,
        ]);
        PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1044831-99-E',
            'name' => 'Steering gear',
        ]);

        $this->actingAs($user)
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('part_catalog_items', [
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
        ]);
        $this->assertDatabaseHas('deleted_parts', [
            'source' => 'products',
            'product_id' => $product->id,
            'donor_car_id' => $donorCar->id,
            'sku' => 'DON28-1260',
        ]);
    }

    public function test_deleted_nikolacars_item_can_be_restored_from_trash(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJ3E1EB8JF091651',
            'brand' => 'Tesla',
            'model' => 'Model 3',
            'year' => 2018,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/restore-73634',
            'part_number' => '1044831-99-E',
            'name' => 'Steering gear',
            'name_ru' => 'Рулевая рейка',
            'raw_attributes' => [
                'code' => '73634',
                'donor_vin' => $donorCar->vin,
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-RESTORE-73634',
            'external_sku' => '1044831-99-E',
            'name' => 'Steering gear',
            'slug' => 'nc-restore-73634',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'tested',
            'unit' => 'pcs',
            'selling_price' => 250,
            'currency' => 'USD',
            'notes' => 'Без повреждений',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.zapchasti.destroy', $item))
            ->assertRedirect();

        $deletedPart = DeletedPart::query()->firstOrFail();

        $this->actingAs($user)
            ->post(route('admin.deleted-parts.restore', $deletedPart))
            ->assertRedirect(route('admin.deleted-parts.index'));

        $this->assertDatabaseHas('part_catalog_items', [
            'id' => $item->id,
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/restore-73634',
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'sku' => 'NC-RESTORE-73634',
            'source_part_catalog_item_id' => $item->id,
        ]);
        $this->assertDatabaseMissing('deleted_parts', [
            'id' => $deletedPart->id,
        ]);
    }

    public function test_restore_reports_when_donor_catalog_link_is_already_used(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE5MF081658',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
        ]);
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/71952',
            'part_number' => '1127502-11-D',
            'name' => 'Ultrasonic sensor',
        ]);

        Product::query()->create([
            'sku' => 'DON29-1282',
            'external_sku' => '1127502-11-D',
            'name' => 'Ultrasonic sensor',
            'slug' => 'don29-1282',
            'donor_car_id' => $donorCar->id,
            'source_part_catalog_item_id' => $item->id,
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'tested',
            'unit' => 'pcs',
            'selling_price' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $deletedPart = DeletedPart::query()->create([
            'source' => 'products',
            'sku' => 'DON29-OLD',
            'part_number' => '1127502-11-D',
            'name' => 'Deleted ultrasonic sensor',
            'product_snapshot' => [
                'id' => 999001,
                'sku' => 'DON29-OLD',
                'external_sku' => '1127502-11-D',
                'name' => 'Deleted ultrasonic sensor',
                'slug' => 'don29-old',
                'donor_car_id' => $donorCar->id,
                'source_part_catalog_item_id' => $item->id,
                'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
                'condition_type' => 'used',
                'testing_status' => 'tested',
                'unit' => 'pcs',
                'selling_price' => 0,
                'currency' => 'USD',
                'is_active' => true,
            ],
            'deleted_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('admin.deleted-parts.index'))
            ->post(route('admin.deleted-parts.restore', $deletedPart))
            ->assertRedirect(route('admin.deleted-parts.index'))
            ->assertSessionHasErrors([
                'restore' => 'Нельзя восстановить запчасть: у этого донора уже есть запчасть, связанная с той же строкой каталога.',
            ]);
    }

    public function test_restore_ignores_generated_catalog_columns_from_snapshot(): void
    {
        DB::statement(
            "alter table part_catalog_items add column restore_generated_probe text generated always as (source || ':' || source_url) virtual"
        );

        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $deletedPart = DeletedPart::query()->create([
            'source' => 'nikolacars',
            'part_catalog_item_id' => 998001,
            'part_number' => '1127502-11-D',
            'name' => 'Ultrasonic sensor',
            'part_catalog_item_snapshot' => [
                'id' => 998001,
                'source' => 'nikolacars',
                'source_url' => 'nikolacars://donor-product/generated-column-probe',
                'part_number' => '1127502-11-D',
                'name' => 'Ultrasonic sensor',
                'restore_generated_probe' => 'must-not-be-inserted',
            ],
            'deleted_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.deleted-parts.restore', $deletedPart))
            ->assertRedirect(route('admin.deleted-parts.index'));

        $this->assertDatabaseHas('part_catalog_items', [
            'id' => 998001,
            'source_url' => 'nikolacars://donor-product/generated-column-probe',
        ]);
        $this->assertDatabaseMissing('deleted_parts', [
            'id' => $deletedPart->id,
        ]);
    }

    public function test_deleted_parts_index_shows_restore_icon(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $deletedPart = DeletedPart::query()->create([
            'source' => 'products',
            'sku' => 'DON28-1260',
            'part_number' => '1044831-99-E',
            'name' => 'Steering gear',
            'product_snapshot' => [
                'sku' => 'DON28-1260',
                'name' => 'Steering gear',
                'slug' => 'don28-1260',
                'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
                'testing_status' => 'tested',
                'unit' => 'pcs',
                'selling_price' => 250,
                'currency' => 'USD',
                'is_active' => true,
            ],
            'deleted_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.deleted-parts.index'))
            ->assertOk()
            ->assertSee(route('admin.deleted-parts.show', $deletedPart), false)
            ->assertSee(route('admin.deleted-parts.restore', $deletedPart), false)
            ->assertSee('aria-label="Восстановить Steering gear"', false);
    }

    public function test_deleted_part_show_displays_saved_snapshots(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => '5YJYGDEE5MF081658',
            'brand' => 'Tesla',
            'model' => 'Model Y',
            'year' => 2021,
        ]);
        $deletedPart = DeletedPart::query()->create([
            'source' => 'nikolacars',
            'product_id' => 71952,
            'part_catalog_item_id' => 279874,
            'donor_car_id' => $donorCar->id,
            'donor_vin' => $donorCar->vin,
            'sku' => 'DON29-1282',
            'part_number' => '1127502-11-D',
            'name' => 'Ultrasonic sensor',
            'part_catalog_item_snapshot' => [
                'id' => 279874,
                'source' => 'nikolacars',
                'source_url' => 'nikolacars://donor-product/71952',
                'part_number' => '1127502-11-D',
                'name_ru' => 'Датчик парктроника',
            ],
            'related_product_snapshots' => [[
                'id' => 71952,
                'sku' => 'DON29-1282',
                'external_sku' => '1127502-11-D',
                'name' => 'Ultrasonic sensor',
                'donor_car_id' => $donorCar->id,
                'source_part_catalog_item_id' => 279874,
                'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
                'notes' => 'Без повреждений',
            ]],
            'deleted_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.deleted-parts.show', $deletedPart))
            ->assertOk()
            ->assertSee('DON29-1282')
            ->assertSee('1127502-11-D')
            ->assertSee('Ultrasonic sensor')
            ->assertSee('nikolacars://donor-product/71952')
            ->assertSee('Датчик парктроника')
            ->assertSee(route('admin.deleted-parts.restore', $deletedPart), false);
    }
}
