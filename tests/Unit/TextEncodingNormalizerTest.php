<?php

namespace Tests\Unit;

use App\Support\TextEncodingNormalizer;
use PHPUnit\Framework\TestCase;

class TextEncodingNormalizerTest extends TestCase
{
    public function test_normalizes_windows_1251_bytes_to_utf8(): void
    {
        $bytes = iconv('UTF-8', 'Windows-1251', 'Раздорин Влад');

        $this->assertSame('Раздорин Влад', TextEncodingNormalizer::normalize($bytes));
    }

    public function test_repairs_mojibake_after_utf8_conversion(): void
    {
        $this->assertSame('Леха', TextEncodingNormalizer::normalize('Р›РµС…Р°'));
    }

    public function test_normalizes_nested_arrays(): void
    {
        $this->assertSame(
            ['name' => 'Леха', 'nested' => ['employee' => 'Раздорин Влад']],
            TextEncodingNormalizer::normalizeArray([
                'name' => 'Р›РµС…Р°',
                'nested' => ['employee' => 'Р Р°Р·РґРѕСЂРёРЅ Р’Р»Р°Рґ'],
            ])
        );
    }
}
