<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleTranslateMissingLocalizedNamesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_translate_backfill_command_is_retired_without_changing_names(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'nikolacars',
            'source_url' => 'nikolacars://inventory-product/74880',
            'part_number' => '1034344-20-B',
            'name' => 'Front bumper bracket',
            'name_ru' => 'Existing RU name',
            'name_ua' => null,
        ]);

        Http::fake([
            'translation.googleapis.com/*' => Http::response(['unexpected' => true]),
        ]);

        $this->artisan('parts:google-translate-missing-localized-names', [
            '--only-id' => $item->id,
        ])
            ->expectsOutput('Localized catalog names are frozen; Google Translate backfill is retired.')
            ->assertExitCode(0);

        $item->refresh();

        $this->assertSame('Existing RU name', $item->name_ru);
        $this->assertNull($item->name_ua);
        Http::assertSentCount(0);
    }
}
