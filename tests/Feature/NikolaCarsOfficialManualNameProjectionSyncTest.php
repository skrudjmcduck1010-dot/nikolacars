<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\User;
use App\Services\NikolaCarsProductInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NikolaCarsOfficialManualNameProjectionSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_tesla_official_name_update_resyncs_linked_nikolacars_projection(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'linked-manual-name-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $donorCar = DonorCar::query()->create([
            'vin' => 'TESLA MX 2015 - 2021 leftovers',
            'brand' => 'Tesla',
            'model' => 'Model X',
            'year' => 2015,
        ]);
        $official = PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'https://parts.tesla.com/en-US/find-part?searchTerm=1027411-00-D',
            'part_number' => '1027411-00-D',
            'name' => 'REAR KNUCKLE - LEFT HAND',
            'name_ua' => "\u{041A}\u{0443}\u{043B}\u{0430}\u{043A} \u{0437}\u{0430}\u{0434}\u{043D}\u{0438}\u{0439} \u{043B}\u{0435}\u{0432}\u{044B}\u{0439}",
            'raw_attributes' => [
                'name_source_site_ua' => 'erazborka.com',
                'name_source_url_ua' => 'https://erazborka.example/ua/rear-knuckle-left',
            ],
        ]);
        $product = Product::query()->create([
            'sku' => 'NC-615',
            'external_sku' => '1027411-00-A',
            'name' => "\u{0426}\u{0430}\u{043F}\u{0444}\u{0430} \u{0437}\u{0430}\u{0434}\u{043D}\u{044F} \u{043B}\u{0456}\u{0432}\u{0430} Tesla MX 2015 - 2021 1027411-00-A",
            'slug' => 'nc-615',
            'donor_car_id' => $donorCar->id,
            'storage_status' => Product::STORAGE_STATUS_IN_STOCK,
            'condition_type' => 'used',
            'testing_status' => 'tested',
            'unit' => 'pcs',
            'selling_price' => 100,
            'currency' => 'USD',
            'notes' => NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES[0],
            'is_active' => true,
        ]);
        $mirror = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://donor-product/'.$product->id,
            'part_number' => '1027411-00-A',
            'name' => $product->name,
            'name_ua' => "\u{041A}\u{0443}\u{043B}\u{0430}\u{043A} \u{0437}\u{0430}\u{0434}\u{043D}\u{0438}\u{0439} \u{043B}\u{0435}\u{0432}\u{044B}\u{0439}",
            'raw_attributes' => [
                'code' => '615',
                'product_id' => $product->id,
                'source_catalog_item_id' => $official->id,
                'source_catalog_source' => 'tesla_official',
                'name_source_site_ua' => 'erazborka.com',
                'name_source_url_ua' => 'https://erazborka.example/ua/rear-knuckle-left',
            ],
        ]);
        $product->forceFill(['source_part_catalog_item_id' => $mirror->id])->save();

        $this->actingAs($user)
            ->patch(route('admin.tesla-official-catalog.update', $official), [
                'name_ua' => "\u{041A}\u{0443}\u{043B}\u{0430}\u{043A} \u{0437}\u{0430}\u{0434}\u{043D}\u{0456}\u{0439} \u{043B}\u{0456}\u{0432}\u{0438}\u{0439}",
            ])
            ->assertRedirect();

        $mirror->refresh();

        $this->assertSame("\u{041A}\u{0443}\u{043B}\u{0430}\u{043A} \u{0437}\u{0430}\u{0434}\u{043D}\u{0456}\u{0439} \u{043B}\u{0456}\u{0432}\u{0438}\u{0439}", $mirror->name_ua);
        $this->assertNull(data_get($mirror->raw_attributes, 'manual_name_locks.ua'));
        $this->assertNull(data_get($mirror->raw_attributes, 'name_source_site_ua'));
        $this->assertNull(data_get($mirror->raw_attributes, 'name_source_url_ua'));
        $this->assertSame($mirror->id, $product->refresh()->source_part_catalog_item_id);
    }
}
