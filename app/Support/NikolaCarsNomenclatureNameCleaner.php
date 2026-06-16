<?php

namespace App\Support;

use Illuminate\Support\Str;

class NikolaCarsNomenclatureNameCleaner
{
    public static function cleanName(mixed $name, mixed $partNumber = null): ?string
    {
        $name = self::normalize((string) $name);
        $partNumber = self::normalize((string) $partNumber);

        if ($name === '') {
            return null;
        }

        if ($partNumber !== '') {
            $compactPartNumber = preg_replace('/[^0-9A-ZА-ЯІЇЄҐ]/iu', '', $partNumber) ?: $partNumber;
            $name = preg_replace('/(?<![\pL\pN])'.preg_quote($partNumber, '/').'(?:\s*\/\s*[0-9A-ZА-ЯІЇЄҐ]+)?(?![\pL\pN])/iu', ' ', $name) ?? $name;

            if ($compactPartNumber !== $partNumber) {
                $name = preg_replace('/(?<![\pL\pN])'.preg_quote($compactPartNumber, '/').'(?:\s*\/\s*[0-9A-ZА-ЯІЇЄҐ]+)?(?![\pL\pN])/iu', ' ', $name) ?? $name;
            }
        }

        $name = preg_replace('/(?<![\pL\pN])[A-HJ-NPR-Z0-9]{17}(?![\pL\pN])/iu', ' ', $name) ?? $name;
        $name = preg_replace('/(?<![\pL\pN])VIN(?:\s*код)?(?![\pL\pN])/iu', ' ', $name) ?? $name;
        $name = preg_replace('/(?<![\pL\pN])(?:model|модель)\s*(?:auto|авто)?\s*:?(?![\pL\pN])/iu', ' ', $name) ?? $name;
        $name = preg_replace('/(?:Tesla|Тесла)/iu', ' ', $name) ?? $name;
        $name = preg_replace('/(?<![\pL\pN])model\s*(?:s|3|x|y)(?![\pL\pN])/iu', ' ', $name) ?? $name;
        $name = preg_replace('/(?<![\pL\pN])(?:MSR|MS|MX|MY|M3|МS|МX|МY|М3|МС|МХ|МУ)(?![\pL\pN])/iu', ' ', $name) ?? $name;
        $name = preg_replace('/(?<![\pL\pN])\d{1,2}\s*[\/.]\s*(?:19|20)\d{2}(?![\pL\pN])/u', ' ', $name) ?? $name;
        $name = preg_replace('/(?<![\pL\pN])\d{1,2}\s*[\/.]\s*\d{2}(?![\pL\pN])/u', ' ', $name) ?? $name;
        $name = preg_replace('/(?<![\pL\pN])\d{1,2}\s*[\/.](?![\pL\pN])/u', ' ', $name) ?? $name;
        $name = preg_replace('/(?<![\pL\pN])(?:19|20)\d{2}\s*-\s*(?:19|20)\d{2}(?![\pL\pN])/u', ' ', $name) ?? $name;
        $name = preg_replace('/(?<![\pL\pN])(?:до|после|після|after|before)\s+(?:19|20)?\d{2}(?![\pL\pN])/iu', ' ', $name) ?? $name;
        $name = preg_replace('/(?<![\pL\pN])(?:19|20)\d{2}(?![\pL\pN])/u', ' ', $name) ?? $name;
        $name = preg_replace('/(?<!\d)\d{6,8}(?:-[0-9A-ZА-ЯІЇЄҐ]{0,3}){1,3}(?:\s*\/\s*[0-9A-ZА-ЯІЇЄҐ]+)?(?![\pL\pN])/iu', ' ', $name) ?? $name;

        $name = preg_replace('/\s*,\s*,+/u', ', ', $name) ?? $name;
        $name = preg_replace('/\s+([),.;:])/u', '$1', $name) ?? $name;
        $name = preg_replace('/([(])\s+/u', '$1', $name) ?? $name;
        $name = preg_replace('/\s{2,}/u', ' ', $name) ?? $name;
        $name = preg_replace('/^[\s,.;:\-]+|[\s,.;:\-]+$/u', '', $name) ?? $name;
        $name = trim($name);

        return $name !== '' ? Str::limit($name, 255, '') : null;
    }

    public static function cleanUaName(mixed $name, mixed $partNumber = null): ?string
    {
        return self::cleanName($name, $partNumber);
    }

    protected static function normalize(string $value): string
    {
        $value = str_replace(["\xc2\xa0", '–', '—'], [' ', '-', '-'], $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
