<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use App\Models\TranslationLanguageMarker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartCatalogShowLanguageMarkerConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_page_displays_only_active_language_marker_conflicts(): void
    {
        TranslationLanguageMarker::query()->create([
            'ua_marker' => 'active-marker',
            'ru_marker' => 'active-marker-ru',
        ]);

        $item = PartCatalogItem::query()->create([
            'source' => 'teslacompany',
            'source_url' => 'https://example.test/part',
            'part_number' => '1000001-00-A',
            'name' => 'Catalog item',
            'name_ru' => 'RU name',
            'name_ua' => 'UA name',
            'raw_attributes' => [
                'name_language_marker_conflict_ru' => [
                    'locale' => 'ua',
                    'count' => 2,
                    'markers' => ['active-marker', 'inactive-marker'],
                ],
            ],
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.teslacompany-catalog.show', $item))
            ->assertOk()
            ->assertSee('active-marker')
            ->assertDontSee('inactive-marker');
    }
}
