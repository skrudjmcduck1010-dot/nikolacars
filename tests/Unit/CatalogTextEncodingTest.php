<?php

namespace Tests\Unit;

use App\Support\CatalogTextEncoding;
use PHPUnit\Framework\TestCase;

class CatalogTextEncodingTest extends TestCase
{
    public function test_repairs_windows_1251_mojibake_with_regular_and_nbsp_bytes(): void
    {
        $this->assertSame("\u{041B}\u{0435}\u{0445}\u{0430}", CatalogTextEncoding::repair("\u{0420}\u{203A}\u{0420}\u{00B5}\u{0421}\u{2026}\u{0420}\u{00B0}"));
        $this->assertSame("\u{0420}\u{0430}\u{0437}\u{0434}\u{043E}\u{0440}\u{0438}\u{043D} \u{0412}\u{043B}\u{0430}\u{0434}", CatalogTextEncoding::repair("\u{0420} \u{0420}\u{00B0}\u{0420}\u{00B7}\u{0420}\u{0491}\u{0420}\u{0455}\u{0421}\u{0402}\u{0420}\u{0451}\u{0420}\u{0405} \u{0420}\u{2019}\u{0420}\u{00BB}\u{0420}\u{00B0}\u{0420}\u{0491}"));
    }

    public function test_repairs_short_ukrainian_letter_mojibake(): void
    {
        $this->assertSame("\u{0425}\u{0417}", CatalogTextEncoding::repair("\u{0420}\u{0490}\u{0420}\u{2014}"));
    }

    public function test_detects_standalone_punctuation_mojibake(): void
    {
        $this->assertTrue(CatalogTextEncoding::looksLikeMojibake("\u{0432}\u{0402}\u{201D}"));
        $this->assertTrue(CatalogTextEncoding::looksLikeMojibake("\u{0412}\u{00B7}"));
        $this->assertSame("\u{2014}", CatalogTextEncoding::repair("\u{0432}\u{0402}\u{201D}"));
        $this->assertSame("\u{00B7}", CatalogTextEncoding::repair("\u{0412}\u{00B7}"));
    }

    public function test_repairs_short_cyrillic_mojibake_pairs(): void
    {
        $this->assertTrue(CatalogTextEncoding::looksLikeMojibake("\u{0421}\u{20AC}\u{0421}\u{201A}"));
        $this->assertSame("\u{0448}\u{0442}", CatalogTextEncoding::repair("\u{0421}\u{20AC}\u{0421}\u{201A}"));
    }

    public function test_detects_common_blade_button_mojibake(): void
    {
        $this->assertTrue(CatalogTextEncoding::looksLikeMojibake('РЎРѕС…СЂ.'));
        $this->assertTrue(CatalogTextEncoding::looksLikeMojibake('Р’С‹РіСЂСѓР·РєР° Prom'));
        $this->assertTrue(CatalogTextEncoding::looksLikeMojibake('РЈРґР°Р»РµРЅРЅС‹Рµ Р·Р°РїС‡Р°СЃС‚Рё'));
        $this->assertSame('Сохр.', CatalogTextEncoding::repair('РЎРѕС…СЂ.'));
        $this->assertSame('Выгрузка Prom', CatalogTextEncoding::repair('Р’С‹РіСЂСѓР·РєР° Prom'));
        $this->assertSame('Удаленные запчасти', CatalogTextEncoding::repair('РЈРґР°Р»РµРЅРЅС‹Рµ Р·Р°РїС‡Р°СЃС‚Рё'));
    }

    public function test_keeps_normal_cyrillic_text_unchanged(): void
    {
        $this->assertSame("\u{0415}\u{043B}\u{0435}\u{043A}\u{0442}\u{0440}\u{0438}\u{043A}", CatalogTextEncoding::repair("\u{0415}\u{043B}\u{0435}\u{043A}\u{0442}\u{0440}\u{0438}\u{043A}"));
    }
}
