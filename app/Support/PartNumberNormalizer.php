<?php

namespace App\Support;

use Illuminate\Support\Str;

class PartNumberNormalizer
{
    /**
     * Cyrillic letters that are commonly pasted instead of Latin article letters.
     *
     * Includes both real Unicode Cyrillic and the mojibake strings already present
     * in legacy imports.
     */
    protected const CYRILLIC_CONFUSABLES = [
        "\u{0410}" => 'A',
        "\u{0412}" => 'B',
        "\u{0415}" => 'E',
        "\u{041A}" => 'K',
        "\u{041C}" => 'M',
        "\u{041D}" => 'H',
        "\u{041E}" => 'O',
        "\u{0420}" => 'P',
        "\u{0421}" => 'C',
        "\u{0422}" => 'T',
        "\u{0423}" => 'Y',
        "\u{0425}" => 'X',
        "\u{0406}" => 'I',
        "\u{0430}" => 'A',
        "\u{0432}" => 'B',
        "\u{0435}" => 'E',
        "\u{043A}" => 'K',
        "\u{043C}" => 'M',
        "\u{043D}" => 'H',
        "\u{043E}" => 'O',
        "\u{0440}" => 'P',
        "\u{0441}" => 'C',
        "\u{0442}" => 'T',
        "\u{0443}" => 'Y',
        "\u{0445}" => 'X',
        "\u{0456}" => 'I',
        'Рђ' => 'A',
        'Р’' => 'B',
        'Р•' => 'E',
        'Рљ' => 'K',
        'Рњ' => 'M',
        'Рќ' => 'H',
        'Рћ' => 'O',
        'Р ' => 'P',
        'РЎ' => 'C',
        'Рў' => 'T',
        'РЈ' => 'Y',
        'РҐ' => 'X',
        'Р†' => 'I',
        'Р°' => 'A',
        'РІ' => 'B',
        'Рµ' => 'E',
        'Рє' => 'K',
        'Рј' => 'M',
        'РЅ' => 'H',
        'Рѕ' => 'O',
        'СЂ' => 'P',
        'СЃ' => 'C',
        'С‚' => 'T',
        'Сѓ' => 'Y',
        'С…' => 'X',
        'С–' => 'I',
    ];

    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $original = $value;

        $value = preg_replace('/[\x{2010}\x{2011}\x{2012}\x{2013}\x{2014}\x{2212}]/u', '-', $value) ?? $value;
        $value = strtr($value, self::CYRILLIC_CONFUSABLES);
        $value = Str::upper($value);
        $value = preg_replace('/\s+AG$/i', '', $value) ?? $value;
        $value = preg_replace('/[А-Яа-яЁёІіЇїЄєҐґ]+/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = preg_replace('/-{2,}/', '-', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B-_/.,;");

        if (preg_match('/^([A-Z0-9]{7}-[A-Z0-9]{2}-[A-Z0-9])(?:$|[^A-Z0-9].*)/', $value, $matches)) {
            $value = $matches[1];
        }

        if (preg_match('/^([A-Z0-9]{7}-[A-Z0-9]{2}-[A-Z])[A-Z0-9]$/', $value, $matches)) {
            $value = $matches[1];
        }

        if (! preg_match('/\d/', $original) && preg_match('/[А-Яа-яЁёІіЇїЄєҐґ]/u', $original)) {
            return null;
        }

        return $value !== '' ? $value : null;
    }

    public static function compact(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/i', '', self::normalize($value) ?? '') ?: '';
    }
}
