<?php

namespace Tests\Feature;

use App\Models\PartCatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartCatalogLocalizedNameCleanerTest extends TestCase
{
    use RefreshDatabase;

    public function test_part_catalog_localized_names_are_cleaned_when_assigned(): void
    {
        $item = PartCatalogItem::query()->create([
            'source' => 'tcarservice',
            'source_url' => 'https://tcarservice.test/part',
            'part_number' => '1006539-00-A',
            'name' => 'Original',
            'name_ru' => "\u{0412}\u{0438}\u{043D}\u{0442} ST WASHER M6X20 ZNAL Tesla Model S 2012-2021, X 2015-2021 1006539-00-A",
            'name_ua' => "\u{0413}\u{0432}\u{0438}\u{043D}\u{0442} ST WASHER M6X20 ZNAL Tesla \u{041C}\u{043E}\u{0434}\u{0435}\u{043B}\u{044C} S 2012-2021, X 2015-2021 1006539-00-A",
        ]);

        $this->assertSame("\u{0412}\u{0438}\u{043D}\u{0442} ST WASHER M6X20 ZNAL", $item->refresh()->name_ru);
        $this->assertSame("\u{0413}\u{0432}\u{0438}\u{043D}\u{0442} ST WASHER M6X20 ZNAL", $item->name_ua);

        $item->forceFill([
            'name_ru' => "\u{0421}\u{0442}\u{0435}\u{043A}\u{043B}\u{043E} \u{0437}\u{0430}\u{0434}\u{043D}\u{0435}\u{0439} \u{0447}\u{0435}\u{0442}\u{0432}\u{0435}\u{0440}\u{0442}\u{0438} \u{043B}\u{0435}\u{0432}\u{043E}\u{0435}TESLA MODEL Y",
            'name_ua' => "\u{0421}\u{043A}\u{043B}\u{043E} \u{0437}\u{0430}\u{0434}\u{043D}\u{044C}\u{043E}\u{0457} \u{0447}\u{0432}\u{0435}\u{0440}\u{0442}\u{0456} \u{043B}\u{0456}\u{0432}\u{0435}TESLA MODEL Y",
        ])->save();

        $this->assertSame("\u{0421}\u{0442}\u{0435}\u{043A}\u{043B}\u{043E} \u{0437}\u{0430}\u{0434}\u{043D}\u{0435}\u{0439} \u{0447}\u{0435}\u{0442}\u{0432}\u{0435}\u{0440}\u{0442}\u{0438} \u{043B}\u{0435}\u{0432}\u{043E}\u{0435}", $item->refresh()->name_ru);
        $this->assertSame("\u{0421}\u{043A}\u{043B}\u{043E} \u{0437}\u{0430}\u{0434}\u{043D}\u{044C}\u{043E}\u{0457} \u{0447}\u{0432}\u{0435}\u{0440}\u{0442}\u{0456} \u{043B}\u{0456}\u{0432}\u{0435}", $item->name_ua);

        $item->forceFill([
            'name_ru' => "\u{041C}\u{043E}\u{0434}\u{0443}\u{043B}\u{044C} HOMELINK 1055371-00-B (\u{0430}\u{043D}\u{0430}\u{043B}\u{043E}\u{0433})",
            'name_ua' => "\u{041C}\u{043E}\u{0434}\u{0443}\u{043B}\u{044C} HOMELINK 1055371-00-B \u{0430}\u{043D}\u{0430}\u{043B}\u{043E}\u{0433}",
        ])->save();

        $this->assertSame("\u{041C}\u{043E}\u{0434}\u{0443}\u{043B}\u{044C} HOMELINK", $item->refresh()->name_ru);
        $this->assertSame("\u{041C}\u{043E}\u{0434}\u{0443}\u{043B}\u{044C} HOMELINK", $item->name_ua);
        $this->assertSame('analog', $item->raw_attributes['part_origin']);
        $this->assertSame("\u{0410}\u{043D}\u{0430}\u{043B}\u{043E}\u{0433}", $item->raw_attributes['part_origin_label']);

        $item->forceFill([
            'name_ru' => "\u{041F}\u{0430}\u{043D}\u{0435}\u{043B}\u{044C} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}\u{044F} \u{043E}\u{0440}\u{0438}\u{0433}\u{0438}\u{043D}\u{0430}\u{043B} 1711743-00-F",
            'name_ua' => "\u{041F}\u{0430}\u{043D}\u{0435}\u{043B}\u{044C} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F} (\u{043E}\u{0440}\u{0438}\u{0433}\u{0456}\u{043D}\u{0430}\u{043B}) 1711743-00-F",
        ])->save();

        $this->assertSame("\u{041F}\u{0430}\u{043D}\u{0435}\u{043B}\u{044C} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}\u{044F}", $item->refresh()->name_ru);
        $this->assertSame("\u{041F}\u{0430}\u{043D}\u{0435}\u{043B}\u{044C} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{044F}", $item->name_ua);
        $this->assertSame('original', $item->raw_attributes['part_origin']);
        $this->assertSame("\u{041E}\u{0440}\u{0438}\u{0433}\u{0438}\u{043D}\u{0430}\u{043B}", $item->raw_attributes['part_origin_label']);

        $item->forceFill([
            'name_ru' => "#2 \u{041A}\u{0440}\u{044B}\u{043B}\u{043E} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0435}\u{0435} \u{043F}\u{0440}\u{0430}\u{0432}\u{043E}\u{0435} \u{0411}\u{0423}",
            'name_ua' => "#2 \u{041A}\u{0440}\u{0438}\u{043B}\u{043E} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0454} \u{043F}\u{0440}\u{0430}\u{0432}\u{0435} \u{0411}/\u{0423}",
        ])->save();

        $this->assertSame("#2 \u{041A}\u{0440}\u{044B}\u{043B}\u{043E} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0435}\u{0435} \u{043F}\u{0440}\u{0430}\u{0432}\u{043E}\u{0435}", $item->refresh()->name_ru);
        $this->assertSame("#2 \u{041A}\u{0440}\u{0438}\u{043B}\u{043E} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0454} \u{043F}\u{0440}\u{0430}\u{0432}\u{0435}", $item->name_ua);
        $this->assertSame("\u{0411}/\u{0443}", $item->condition);

        $item->forceFill([
            'name_ru' => "\u{0424}\u{0430}\u{0440}\u{0430} \u{043D}\u{0435}\u{043E}\u{0440}\u{0438}\u{0433}\u{0438}\u{043D}\u{0430}\u{043B}",
            'name_ua' => "\u{0424}\u{0430}\u{0440}\u{0430} \u{043D}\u{0435} \u{043E}\u{0440}\u{0438}\u{0433}\u{0456}\u{043D}\u{0430}\u{043B}",
        ])->save();

        $this->assertSame("\u{0424}\u{0430}\u{0440}\u{0430}", $item->refresh()->name_ru);
        $this->assertSame("\u{0424}\u{0430}\u{0440}\u{0430}", $item->name_ua);
        $this->assertSame('analog', $item->raw_attributes['part_origin']);
        $this->assertSame("\u{0410}\u{043D}\u{0430}\u{043B}\u{043E}\u{0433}", $item->raw_attributes['part_origin_label']);

        $item->forceFill([
            'name_ru' => "\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{0445}\u{0440}\u{0430}\u{043D}\u{0438}\u{0442}\u{0435}\u{043B}\u{044C} \u{0412}\u{0412}\u{0411} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{0438} \u{0422}\u{0435}\u{0441}\u{043B}\u{0430} RS309-MF",
            'name_ua' => "\u{0417}\u{0430}\u{043F}\u{043E}\u{0431}\u{0456}\u{0436}\u{043D}\u{0438}\u{043A} \u{0412}\u{0412}\u{0411} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{0457} Tesla RS309-MF",
        ])->save();

        $this->assertSame("\u{041F}\u{0440}\u{0435}\u{0434}\u{043E}\u{0445}\u{0440}\u{0430}\u{043D}\u{0438}\u{0442}\u{0435}\u{043B}\u{044C} \u{0412}\u{0412}\u{0411} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{0438} RS309-MF", $item->refresh()->name_ru);
        $this->assertSame("\u{0417}\u{0430}\u{043F}\u{043E}\u{0431}\u{0456}\u{0436}\u{043D}\u{0438}\u{043A} \u{0412}\u{0412}\u{0411} \u{0431}\u{0430}\u{0442}\u{0430}\u{0440}\u{0435}\u{0457} RS309-MF", $item->name_ua);

        $item->forceFill([
            'name_ru' => "\u{041A}\u{0440}\u{044B}\u{043B}\u{043E} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0435}\u{0435} \u{0422}\u{0435}\u{0441}\u{043B}\u{0430} \u{041C}\u{043E}\u{0434}\u{0435}\u{043B}\u{044C} 3 1495508-00-A",
            'name_ua' => "\u{041A}\u{0440}\u{0438}\u{043B}\u{043E} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0454} Tesla Model Y 1495508-00-A",
        ])->save();

        $this->assertSame("\u{041A}\u{0440}\u{044B}\u{043B}\u{043E} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0435}\u{0435}", $item->refresh()->name_ru);
        $this->assertSame("\u{041A}\u{0440}\u{0438}\u{043B}\u{043E} \u{043F}\u{0435}\u{0440}\u{0435}\u{0434}\u{043D}\u{0454}", $item->name_ua);

        $item->forceFill([
            'name_ru' => "\u{0428}\u{0442}\u{0438}\u{0444}\u{0442} \u{043D}\u{0430}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{044F}\u{044E}\u{0449}\u{0438}\u{0439} TESLAModel S",
            'name_ua' => "\u{0421}\u{043A}\u{043E}\u{0431}\u{0430} \u{0432}\u{0442}\u{0443}\u{043B}\u{043A}\u{0438} \u{0441}\u{0442}\u{0430}\u{0431}\u{0456}\u{043B}\u{0456}\u{0437}\u{0430}\u{0442}\u{043E}\u{0440}\u{0430}TESLA",
        ])->save();

        $this->assertSame("\u{0428}\u{0442}\u{0438}\u{0444}\u{0442} \u{043D}\u{0430}\u{043F}\u{0440}\u{0430}\u{0432}\u{043B}\u{044F}\u{044E}\u{0449}\u{0438}\u{0439}", $item->refresh()->name_ru);
        $this->assertSame("\u{0421}\u{043A}\u{043E}\u{0431}\u{0430} \u{0432}\u{0442}\u{0443}\u{043B}\u{043A}\u{0438} \u{0441}\u{0442}\u{0430}\u{0431}\u{0456}\u{043B}\u{0456}\u{0437}\u{0430}\u{0442}\u{043E}\u{0440}\u{0430}", $item->name_ua);
    }

    public function test_part_catalog_localized_names_are_cleaned_before_save_when_raw_attributes_bypass_setters(): void
    {
        $item = new PartCatalogItem;
        $item->setRawAttributes([
            'source' => 'toprazborka',
            'source_url' => 'https://toprazborka.test/part',
            'part_number' => '1045664-04-E',
            'name' => 'Original',
            'name_ru' => "\u{0414}\u{0430}\u{0442}\u{0447}\u{0438}\u{043A} \u{0442}\u{0435}\u{043C}\u{043F}\u{0435}\u{0440}\u{0430}\u{0442}\u{0443}\u{0440}\u{044B} \u{0432} \u{0441}\u{0430}\u{043B}\u{043E}\u{043D}\u{0435} TESLA",
            'name_ua' => "\u{0414}\u{0430}\u{0442}\u{0447}\u{0438}\u{043A} \u{0442}\u{0435}\u{043C}\u{043F}\u{0435}\u{0440}\u{0430}\u{0442}\u{0443}\u{0440}\u{0438} \u{0432} \u{0441}\u{0430}\u{043B}\u{043E}\u{043D}\u{0456} Tesla",
        ]);
        $item->save();

        $this->assertSame("\u{0414}\u{0430}\u{0442}\u{0447}\u{0438}\u{043A} \u{0442}\u{0435}\u{043C}\u{043F}\u{0435}\u{0440}\u{0430}\u{0442}\u{0443}\u{0440}\u{044B} \u{0432} \u{0441}\u{0430}\u{043B}\u{043E}\u{043D}\u{0435}", $item->refresh()->name_ru);
        $this->assertSame("\u{0414}\u{0430}\u{0442}\u{0447}\u{0438}\u{043A} \u{0442}\u{0435}\u{043C}\u{043F}\u{0435}\u{0440}\u{0430}\u{0442}\u{0443}\u{0440}\u{0438} \u{0432} \u{0441}\u{0430}\u{043B}\u{043E}\u{043D}\u{0456}", $item->name_ua);
    }
}
