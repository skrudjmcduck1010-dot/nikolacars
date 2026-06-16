<?php

namespace Tests\Feature;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Services\NikolaCarsCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NikolaCarsCatalogImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_keeps_only_checked_donor_products_in_nikolacars_catalog(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nikolacars-catalog-');
        $handle = fopen($path, 'wb');

        fputcsv($handle, ['code', 'part_number', 'name', 'barcode', 'category', 'price', 'stock', 'images']);
        fputcsv($handle, ['SHELF-1', '123456700A', 'Shelf part', '', 'NikolaCars;Склад;Полка A', '10', '1', '']);
        fputcsv($handle, ['DONOR-1', '123456701A', 'Checked donor part', '', 'Tesla;Model 3;5YJ3E1EA7KF000001;Без повреждений;Кузов', '20', '1', '']);
        fputcsv($handle, ['DONOR-2', '123456702A', 'Light donor part', '', 'Tesla;Model 3;5YJ3E1EA7KF000002;Легкие повреждения;Кузов', '30', '1', '']);
        fputcsv($handle, ['DONOR-3', '123456703A', 'Unknown donor part', '', 'Tesla;Model 3;5YJ3E1EA7KF000003;Неизвестно;Кузов', '40', '1', '']);
        fputcsv($handle, ['DONOR-4', '123456704A', 'Broken donor part', '', 'Tesla;Model 3;5YJ3E1EA7KF000004;Разбит;Кузов', '50', '1', '']);
        fclose($handle);

        try {
            $stats = app(NikolaCarsCatalogImporter::class)->import($path, [
                'images_dir' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-nikolacars-images',
                'public_images_dir' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-nikolacars-public',
            ]);
        } finally {
            @unlink($path);
        }

        $this->assertSame(5, $stats['rows_read']);
        $this->assertSame(2, $stats['donor_products_skipped_unchecked']);
        $this->assertSame(3, $stats['products_saved']);
        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/SHELF-1',
        ]);
        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/DONOR-1',
            'quality' => 'Без повреждений',
        ]);
        $this->assertDatabaseHas('part_catalog_items', [
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/DONOR-2',
            'quality' => 'Легкие повреждения',
        ]);
        $this->assertDatabaseMissing('part_catalog_items', [
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/DONOR-3',
        ]);
        $this->assertDatabaseMissing('part_catalog_items', [
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://product/DONOR-4',
        ]);

        $checkedDonor = PartCatalogItem::query()->where('source_url', 'nikolacars://product/DONOR-1')->firstOrFail();
        $this->assertSame('Без повреждений', data_get($checkedDonor->raw_attributes, 'donor_damage_status'));
    }

    public function test_import_links_known_leftovers_category_to_pseudo_vin_donor(): void
    {
        $donorCar = DonorCar::query()->create([
            'vin' => 'TESLA МS 2015 - 2021 залишки',
            'brand' => 'Tesla',
            'model' => 'Model S',
            'year' => 2015,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'nikolacars-catalog-');
        $handle = fopen($path, 'wb');

        fputcsv($handle, ['code', 'part_number', 'name', 'barcode', 'category', 'price', 'stock', 'images']);
        fputcsv($handle, ['28', '1004808-00-D', 'FM antenna Tesla MS', '', 'Tesla;Tesla МS 2015 - 2021 залишки;Екстер\'єр / Кузов Tesla MS 2015 - 2021', '30', '1', '']);
        fclose($handle);

        try {
            $stats = app(NikolaCarsCatalogImporter::class)->import($path, [
                'images_dir' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-nikolacars-images',
                'public_images_dir' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-nikolacars-public',
            ]);
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, $stats['donor_vins_found']);
        $this->assertSame(1, $stats['products_saved']);
        $this->assertDatabaseHas('products', [
            'donor_car_id' => $donorCar->id,
            'sku' => 'NC-28',
            'external_sku' => '1004808-00-D',
            'storage_status' => Product::STORAGE_STATUS_ON_DONOR,
        ]);

        $item = PartCatalogItem::query()->where('source_url', 'nikolacars://product/28')->firstOrFail();
        $this->assertSame($donorCar->vin, data_get($item->raw_attributes, 'donor_vin'));
    }
}
