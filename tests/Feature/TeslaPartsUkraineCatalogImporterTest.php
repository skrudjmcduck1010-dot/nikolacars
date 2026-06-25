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

        $name = $this->u('\u041a\u0440\u043e\u043d\u0448\u0442\u0435\u0439\u043d Tesla Model 3');

        $this->assertSame(
            ['name' => $name, 'part_origin' => 'analog'],
            $method->invoke($importer, $name.' '.$this->u('\u0430\u043d\u0430\u043b\u043e\u0433'))
        );

        $this->assertSame(
            ['name' => $name, 'part_origin' => 'analog'],
            $method->invoke($importer, $name.' '.$this->u('\u041e\u0440\u0438\u0433\u0438\u043d\u0430\u043b \u0430\u043d\u0430\u043b\u043e\u0433'))
        );

        $this->assertSame(
            ['name' => $name, 'part_origin' => 'original'],
            $method->invoke($importer, $name.' '.$this->u('\u041e\u0440\u0438\u0433\u0438\u043d\u0430\u043b'))
        );

        $this->assertSame(
            ['name' => $name, 'part_origin' => 'original'],
            $method->invoke($importer, $name.' '.$this->u('\u041e\u0440\u0438\u0433\u0456\u043d\u0430\u043b'))
        );
    }

    public function test_removes_model_3_only_for_model_3_catalog_names(): void
    {
        $importer = app(TeslaPartsUkraineCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'withoutListingModelName');
        $method->setAccessible(true);

        $name = $this->u('\u041a\u0440\u043e\u043d\u0448\u0442\u0435\u0439\u043d');
        $modelName = $name.' Tesla Model 3';

        $this->assertSame(
            $name,
            $method->invoke($importer, $modelName, 'Model 3')
        );

        $this->assertSame(
            $name,
            $method->invoke($importer, $modelName, 'Tesla Model 3')
        );

        $this->assertSame(
            $modelName,
            $method->invoke($importer, $modelName, 'Model Y')
        );
    }

    public function test_extracts_used_condition_from_uppercase_bv_only(): void
    {
        $importer = app(TeslaPartsUkraineCatalogImporter::class);
        $method = new \ReflectionMethod($importer, 'partConditionFromName');
        $method->setAccessible(true);

        $name = $this->u('\u041a\u0440\u043e\u043d\u0448\u0442\u0435\u0439\u043d Tesla Model 3');

        $this->assertSame(
            ['name' => $name, 'condition' => $this->u('\u0411/\u0423')],
            $method->invoke($importer, $name.' '.$this->u('\u0411\u0412'))
        );

        $this->assertSame(
            ['name' => $name.' '.$this->u('\u0431\u0432'), 'condition' => null],
            $method->invoke($importer, $name.' '.$this->u('\u0431\u0432'))
        );
    }

    public function test_does_not_assign_mixed_language_name_to_localized_columns(): void
    {
        $importer = app(TeslaPartsUkraineCatalogImporter::class);

        $mixedName = "\u{042D}\u{043B}\u{0435}\u{043A}\u{0442}\u{0440}\u{043E}\u{043F}\u{0440}\u{043E}\u{0432}\u{043E}\u{0434}\u{043A}\u{0430} \u{0446}\u{0435}\u{043D}\u{0442}\u{0440}\u{0430}\u{043B}\u{044C}\u{043D}\u{043E}\u{0457} \u{043A}\u{043E}\u{043D}\u{0441}\u{043E}\u{043B}\u{0456}";

        $this->assertSame([], $importer->localizedNamePayload($mixedName));
    }

    private function u(string $value): string
    {
        return json_decode('"'.$value.'"', true, 512, JSON_THROW_ON_ERROR);
    }
}
