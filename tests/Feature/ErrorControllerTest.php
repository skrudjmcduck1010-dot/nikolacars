<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Models\TranslationLanguageMarker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_errors_page_lists_only_active_unlocked_language_marker_conflicts(): void
    {
        TranslationLanguageMarker::query()->create([
            'ua_marker' => 'active-marker',
            'ru_marker' => 'active-marker-ru',
        ]);

        $activeItem = PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://example.test/active-conflict',
            'part_number' => '1000001-00-A',
            'name' => 'Active conflict item',
            'name_ru' => 'Active RU',
            'name_ua' => 'Active UA',
            'raw_attributes' => [
                'name_language_marker_conflict_ru' => [
                    'locale' => 'ua',
                    'count' => 1,
                    'markers' => ['active-marker'],
                ],
            ],
        ]);

        PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://example.test/inactive-conflict',
            'part_number' => '1000002-00-A',
            'name' => 'Inactive conflict item',
            'name_ru' => 'Inactive RU',
            'name_ua' => 'Inactive UA',
            'raw_attributes' => [
                'name_language_marker_conflict_ru' => [
                    'locale' => 'ua',
                    'count' => 1,
                    'markers' => ['inactive-marker'],
                ],
            ],
        ]);

        PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://example.test/manual-lock',
            'part_number' => '1000003-00-A',
            'name' => 'Manual lock item',
            'name_ru' => 'Manual RU',
            'name_ua' => 'Manual UA',
            'name_ru_manually_locked_at' => now(),
            'raw_attributes' => [
                'name_language_marker_conflict_ru' => [
                    'locale' => 'ua',
                    'count' => 1,
                    'markers' => ['active-marker'],
                ],
            ],
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('admin.errors.index'));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('totalConflictItems'));
        $this->assertSame([$activeItem->id], $response->viewData('items')->pluck('id')->all());
    }
}
