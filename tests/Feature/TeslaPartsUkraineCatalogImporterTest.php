<?php

namespace Tests\Feature;

use App\Services\TeslaPartsUkraineCatalogImporter;
use Tests\TestCase;

class TeslaPartsUkraineCatalogImporterTest extends TestCase
{
    public function test_extracts_part_origin_from_product_name(): void
    {
        $importer = app(TeslaPartsUkraineCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'partOriginFromName');
        $method->setAccessible(true);

        $this->assertSame(
            ['name' => 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3', 'part_origin' => 'analog'],
            $method->invoke($importer, 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3 Р°РЅР°Р»РѕРі')
        );

        $this->assertSame(
            ['name' => 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3', 'part_origin' => 'analog'],
            $method->invoke($importer, 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3 РћСЂРёРіРёРЅР°Р» Р°РЅР°Р»РѕРі')
        );

        $this->assertSame(
            ['name' => 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3', 'part_origin' => 'original'],
            $method->invoke($importer, 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3 РћСЂРёРіРёРЅР°Р»')
        );

        $this->assertSame(
            ['name' => 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3', 'part_origin' => 'original'],
            $method->invoke($importer, 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3 РћСЂРёРіС–РЅР°Р»')
        );
    }

    public function test_removes_model_3_only_for_model_3_catalog_names(): void
    {
        $importer = app(TeslaPartsUkraineCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'withoutListingModelName');
        $method->setAccessible(true);

        $this->assertSame(
            'РљСЂРѕРЅС€С‚РµР№РЅ',
            $method->invoke($importer, 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3', 'Model 3')
        );

        $this->assertSame(
            'РљСЂРѕРЅС€С‚РµР№РЅ',
            $method->invoke($importer, 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3', 'Tesla Model 3')
        );

        $this->assertSame(
            'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3',
            $method->invoke($importer, 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3', 'Model Y')
        );
    }

    public function test_extracts_used_condition_from_uppercase_bv_only(): void
    {
        $importer = app(TeslaPartsUkraineCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'partConditionFromName');
        $method->setAccessible(true);

        $this->assertSame(
            ['name' => 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3', 'condition' => 'Р‘/РЈ'],
            $method->invoke($importer, 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3 Р‘Р’')
        );

        $this->assertSame(
            ['name' => 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3 Р±РІ', 'condition' => null],
            $method->invoke($importer, 'РљСЂРѕРЅС€С‚РµР№РЅ Tesla Model 3 Р±РІ')
        );
    }

    public function test_does_not_assign_mixed_language_name_to_localized_columns(): void
    {
        $importer = app(TeslaPartsUkraineCatalogImporter::class);

        $mixedName = "\u{042D}\u{043B}\u{0435}\u{043A}\u{0442}\u{0440}\u{043E}\u{043F}\u{0440}\u{043E}\u{0432}\u{043E}\u{0434}\u{043A}\u{0430} \u{0446}\u{0435}\u{043D}\u{0442}\u{0440}\u{0430}\u{043B}\u{044C}\u{043D}\u{043E}\u{0457} \u{043A}\u{043E}\u{043D}\u{0441}\u{043E}\u{043B}\u{0456}";

        $this->assertSame([], $importer->localizedNamePayload($mixedName));
    }
}
