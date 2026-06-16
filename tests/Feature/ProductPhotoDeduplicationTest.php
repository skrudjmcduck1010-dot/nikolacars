<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductPhotoDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_show_deduplicates_official_part_images_by_rendered_url(): void
    {
        config([
            'app.url' => 'https://sklad.nikolacars.kiev.ua',
            'filesystems.disks.public.url' => 'https://sklad.nikolacars.kiev.ua/storage',
            'filesystems.public_fallback_url' => null,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'tesla_official',
            'source_url' => 'tesla-official://1127503-11-D',
            'part_number' => '1127503-11-D',
            'name' => 'ULTRASONIC SENSOR',
            'raw_attributes' => [
                'part_image_urls' => [
                    'tesla-official/part-images/112750311D/1127503-11-D_1.jpeg',
                    'tesla-official/part-images/112750311D/1127503-11-D_2.jpeg',
                ],
                'system_group_image_urls' => [
                    'tesla-official/resources-images/parking-sensors.png',
                ],
            ],
        ]);

        $product = Product::query()->create([
            'sku' => 'DON6-1976',
            'external_sku' => '1127503-11-D',
            'name' => 'ULTRASONIC SENSOR',
            'slug' => 'ultrasonic-sensor-dedup',
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
            'condition_type' => 'used',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'selling_price' => 25,
            'currency' => 'USD',
            'main_image' => 'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_1.jpeg',
            'images_json' => [
                'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_2.jpeg',
                'https://sklad.nikolacars.kiev.ua/storage/tesla-official/resources-images/parking-sensors.png',
            ],
            'is_active' => true,
        ]);

        $html = $this->actingAs($this->adminUser())
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($html, 'data-photo="'));
        $this->assertStringContainsString('data-photo="https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_1.jpeg"', $html);
        $this->assertStringContainsString('data-photo="https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_2.jpeg"', $html);
        $this->assertStringNotContainsString('data-photo="tesla-official/part-images/112750311D/1127503-11-D_1.jpeg"', $html);
        $this->assertStringNotContainsString('data-photo="https://sklad.nikolacars.kiev.ua/storage/tesla-official/resources-images/parking-sensors.png"', $html);
        $this->assertStringNotContainsString('alt="Tesla.com 1127503-11-D"', $html);
    }

    public function test_clean_product_photo_schemes_command_removes_saved_scheme_images(): void
    {
        config([
            'app.url' => 'https://sklad.nikolacars.kiev.ua',
            'filesystems.disks.public.url' => 'https://sklad.nikolacars.kiev.ua/storage',
            'filesystems.public_fallback_url' => null,
        ]);

        $product = Product::query()->create([
            'sku' => 'DON6-1976',
            'external_sku' => '1127503-11-D',
            'name' => 'ULTRASONIC SENSOR',
            'slug' => 'ultrasonic-sensor-clean-command',
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'currency' => 'USD',
            'main_image' => 'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_1.jpeg',
            'images_json' => [
                'tesla-official/part-images/112750311D/1127503-11-D_1.jpeg',
                'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_2.jpeg',
                'https://sklad.nikolacars.kiev.ua/storage/tesla-official/resources-images/parking-sensors.png',
                'https://epc.tesla.com/resources/images/ModelY/Classic/US/Parking Sensors.svg',
                'https://epc.tesla.com/resources/images/ModelY/Classic/US/Parking Sensors.png',
            ],
            'is_active' => true,
        ]);

        $this->artisan('products:clean-photo-schemes', [
            '--write' => true,
            '--product-id' => [$product->id],
        ])
            ->expectsOutputToContain('scheme_images_removed')
            ->assertExitCode(0);

        $product->refresh();

        $this->assertSame(
            'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_1.jpeg',
            $product->main_image
        );
        $this->assertSame([
            'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_1.jpeg',
            'https://sklad.nikolacars.kiev.ua/storage/tesla-official/part-images/112750311D/1127503-11-D_2.jpeg',
        ], (array) $product->images_json);
    }

    protected function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-product-photo-dedup@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }
}
