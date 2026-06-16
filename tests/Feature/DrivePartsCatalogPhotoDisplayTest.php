<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Models\User;
use App\Services\DrivePartsCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DrivePartsCatalogPhotoDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_show_renders_remote_image_url_when_not_localized(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $imageUrl = 'https://drive-parts.com.ua/content/images/21/233x310l85nn0/24922430617272.jpg';
        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/36750-olyva-transmisiina-sp-matic-4036-5l/',
            'part_number' => '36750',
            'name' => 'DriveParts oil',
            'raw_attributes' => [
                'image_url' => $imageUrl,
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.driveparts-catalog.show', $item))
            ->assertOk()
            ->assertSee($imageUrl);
    }

    public function test_item_show_deduplicates_driveparts_local_image_variants(): void
    {
        Storage::fake('public');

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        foreach ([
            'driveparts/part-images/150006600C/70410249014960-fd2d7aafe5.jpg',
            'driveparts/part-images/150006600C/39480925138728-fe545f3e13.jpg',
            'driveparts/part-images/150006600C/63080670276186-fa3b757b41.jpg',
            'driveparts/part-images/150006600C/83807260886687-398ccb4ff6.jpg',
            'driveparts/part-images/150006600C/70410249014960-601a75a2e8.jpg',
            'driveparts/part-images/150006600C/39480925138728-e0474c915b.jpg',
            'driveparts/part-images/150006600C/63080670276186-4e02ef1200.jpg',
            'driveparts/part-images/150006600C/83807260886687-dcce2e744a.jpg',
            'driveparts/part-images/150006600C/70410249014960-e0f294ec8d.jpg',
        ] as $path) {
            Storage::disk('public')->put($path, 'image');
        }

        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/1500066-00-c-ushchilniuvach-skla-dverei-perednii-livyi-tesla-model-y-yr/',
            'part_number' => '1500066-00-C',
            'name' => 'DriveParts seal',
            'raw_attributes' => [
                'image_url' => 'driveparts/part-images/150006600C/70410249014960-fd2d7aafe5.jpg',
                'image_urls' => [
                    'driveparts/part-images/150006600C/70410249014960-fd2d7aafe5.jpg',
                    'driveparts/part-images/150006600C/39480925138728-fe545f3e13.jpg',
                    'driveparts/part-images/150006600C/63080670276186-fa3b757b41.jpg',
                    'driveparts/part-images/150006600C/83807260886687-398ccb4ff6.jpg',
                    'driveparts/part-images/150006600C/70410249014960-601a75a2e8.jpg',
                    'driveparts/part-images/150006600C/39480925138728-e0474c915b.jpg',
                    'driveparts/part-images/150006600C/63080670276186-4e02ef1200.jpg',
                    'driveparts/part-images/150006600C/83807260886687-dcce2e744a.jpg',
                    'driveparts/part-images/150006600C/70410249014960-e0f294ec8d.jpg',
                    'competitor-catalog/driveparts/1500066-00-c/70410249014960-fd2d7aafe50a.jpg',
                    'competitor-catalog/driveparts/1500066-00-c/39480925138728-fe545f3e13c2.jpg',
                    'competitor-catalog/driveparts/1500066-00-c/63080670276186-fa3b757b415b.jpg',
                    'competitor-catalog/driveparts/1500066-00-c/83807260886687-398ccb4ff63d.jpg',
                ],
            ],
        ]);

        $content = $this->actingAs($user)
            ->get(route('admin.driveparts-catalog.show', $item))
            ->assertOk()
            ->content();

        $this->assertSame(4, substr_count($content, 'class="part-catalog-photo-manager__item"'));
        $this->assertStringContainsString('70410249014960-fd2d7aafe5.jpg', $content);
        $this->assertStringContainsString('39480925138728-fe545f3e13.jpg', $content);
        $this->assertStringContainsString('63080670276186-fa3b757b41.jpg', $content);
        $this->assertStringContainsString('83807260886687-398ccb4ff6.jpg', $content);
        $this->assertStringNotContainsString('70410249014960-601a75a2e8.jpg', $content);
        $this->assertStringNotContainsString('70410249014960-fd2d7aafe50a.jpg', $content);
    }

    public function test_item_show_maps_driveparts_placeholder_reference_to_shared_placeholder(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/1079904-15-h-invertor-motora/',
            'part_number' => '1079904-15-H',
            'name' => 'Інвектор мотора',
            'raw_attributes' => [
                'image_url' => 'driveparts/part-images/107490700F/35938788866351-c446aac198.png',
                'image_urls' => [
                    'driveparts/part-images/107490700F/35938788866351-c446aac198.png',
                ],
            ],
        ]);

        $content = $this->actingAs($user)
            ->get(route('admin.driveparts-catalog.show', $item))
            ->assertOk()
            ->content();

        $this->assertStringContainsString(Storage::url(DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH), $content);
        $this->assertStringNotContainsString('35938788866351-c446aac198.png', $content);
    }

    public function test_item_show_maps_missing_driveparts_local_image_to_shared_placeholder(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/missing-photo-product/',
            'part_number' => '1074907-00-F',
            'name' => 'Missing photo product',
            'raw_attributes' => [
                'image_url' => 'driveparts/part-images/107490700F/missing-local-image.png',
            ],
        ]);

        $content = $this->actingAs($user)
            ->get(route('admin.driveparts-catalog.show', $item))
            ->assertOk()
            ->content();

        $this->assertStringContainsString(Storage::url(DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH), $content);
        $this->assertStringContainsString('&#1073;&#1077;&#1079; &#1092;&#1086;&#1090;&#1086;', $content);
        $this->assertStringNotContainsString('missing-local-image.png', $content);
    }

    public function test_item_show_renders_only_one_shared_placeholder_when_old_placeholder_copy_remains(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/6654323-t1-a-sydinnia-vodiiske-tesla-model-y/',
            'part_number' => '6654323-T1-A',
            'name' => 'Seat',
            'raw_attributes' => [
                'image_url' => 'driveparts/part-images/6654323T1A/76951182311577-1098b50f12.png',
                'image_urls' => [
                    DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH,
                    'driveparts/part-images/6654323T1A/76951182311577-1098b50f12.png',
                ],
            ],
        ]);

        $content = $this->actingAs($user)
            ->get(route('admin.driveparts-catalog.show', $item))
            ->assertOk()
            ->content();

        $this->assertSame(1, substr_count($content, 'class="part-catalog-photo-manager__item"'));
        $this->assertStringContainsString(Storage::url(DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH), $content);
        $this->assertStringNotContainsString('76951182311577-1098b50f12.png', $content);
    }

    public function test_index_renders_only_one_shared_placeholder_when_old_placeholder_copy_remains(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/6654323-t1-a-sydinnia-vodiiske-tesla-model-y/',
            'part_number' => '6654323-T1-A',
            'name' => 'Seat',
            'raw_attributes' => [
                'image_url' => 'driveparts/part-images/6654323T1A/76951182311577-1098b50f12.png',
                'image_urls' => [
                    DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH,
                    'driveparts/part-images/6654323T1A/76951182311577-1098b50f12.png',
                ],
            ],
        ]);

        $content = $this->actingAs($user)
            ->get(route('admin.driveparts-catalog.index'))
            ->assertOk()
            ->content();

        $this->assertSame(1, substr_count($content, Storage::url(DrivePartsCatalogImporter::PLACEHOLDER_IMAGE_PATH)));
        $this->assertStringNotContainsString('76951182311577-1098b50f12.png', $content);
    }

    public function test_importer_deduplicates_driveparts_local_image_variants_when_saving(): void
    {
        Storage::fake('public');

        $item = PartCatalogItem::query()->create([
            'source' => 'driveparts',
            'source_url' => 'https://drive-parts.com.ua/1500066-00-c-ushchilniuvach-skla-dverei-perednii-livyi-tesla-model-y-yr/',
            'part_number' => '1500066-00-C',
            'name' => 'DriveParts seal',
            'raw_attributes' => [
                'image_urls' => [
                    'driveparts/part-images/150006600C/70410249014960-fd2d7aafe5.jpg',
                    'competitor-catalog/driveparts/1500066-00-c/70410249014960-fd2d7aafe50a.jpg',
                ],
            ],
        ]);

        $method = new \ReflectionMethod(app(DrivePartsCatalogImporter::class), 'saveProductImages');
        $method->setAccessible(true);
        $method->invoke(app(DrivePartsCatalogImporter::class), $item, [
            'image_urls' => [
                'driveparts/part-images/150006600C/70410249014960-601a75a2e8.jpg',
                'driveparts/part-images/150006600C/39480925138728-fe545f3e13.jpg',
            ],
        ]);

        $item->refresh();

        $this->assertSame([
            'driveparts/part-images/150006600C/70410249014960-fd2d7aafe5.jpg',
            'driveparts/part-images/150006600C/39480925138728-fe545f3e13.jpg',
        ], data_get($item->raw_attributes, 'image_urls'));
    }
}
