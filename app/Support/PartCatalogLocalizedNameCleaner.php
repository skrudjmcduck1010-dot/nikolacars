<?php

namespace App\Support;

use Illuminate\Support\Str;

class PartCatalogLocalizedNameCleaner
{
    public static function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s*(?:Tesla|\x{0422}\x{0435}\x{0441}\x{043B}\x{0430})\s*(?:Model|\x{041C}\x{043E}\x{0434}\x{0435}\x{043B}\x{044C})\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/(?:Tesla|\x{0422}\x{0435}\x{0441}\x{043B}\x{0430})(?![\pL\pN])/iu', ' ', $value) ?? $value;
        $value = preg_replace(self::nonOriginalParenthesesPattern(), ' ', $value) ?? $value;
        $value = preg_replace(self::nonOriginalWordPattern(), ' ', $value) ?? $value;
        $value = preg_replace(self::analogParenthesesPattern(), ' ', $value) ?? $value;
        $value = preg_replace(self::analogWordPattern(), ' ', $value) ?? $value;
        $value = preg_replace(self::originalParenthesesPattern(), ' ', $value) ?? $value;
        $value = preg_replace(self::originalWordPattern(), ' ', $value) ?? $value;
        $value = preg_replace(self::usedParenthesesPattern(), ' ', $value) ?? $value;
        $value = preg_replace(self::usedWordPattern(), ' ', $value) ?? $value;
        $value = preg_replace('/(?<!\d)\d{6,8}(?:-[0-9A-Z\x{0410}-\x{042F}]{0,3}){1,3}(?:\([^)]+\))?/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\(\s*\)/u', '', $value) ?? $value;
        $value = preg_replace('/\s+([,.;:])/u', '$1', $value) ?? $value;
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;
        $value = preg_replace('/[\s,.;:\-\x{2013}]+$/u', '', $value) ?? $value;
        $value = trim($value);

        return $value !== '' ? Str::limit($value, 255, '') : null;
    }

    public static function hasAnalogMarker(mixed $value): bool
    {
        $value = (string) $value;

        return preg_match(self::nonOriginalParenthesesPattern(), $value) === 1
            || preg_match(self::nonOriginalWordPattern(), $value) === 1
            || preg_match(self::analogParenthesesPattern(), $value) === 1
            || preg_match(self::analogWordPattern(), $value) === 1;
    }

    public static function hasOriginalMarker(mixed $value): bool
    {
        $value = (string) $value;

        return preg_match(self::originalParenthesesPattern(), $value) === 1
            || preg_match(self::originalWordPattern(), $value) === 1;
    }

    public static function hasUsedMarker(mixed $value): bool
    {
        $value = (string) $value;

        return preg_match(self::usedParenthesesPattern(), $value) === 1
            || preg_match(self::usedWordPattern(), $value) === 1;
    }

    public static function analogLabel(): string
    {
        return html_entity_decode('&#1040;&#1085;&#1072;&#1083;&#1086;&#1075;', ENT_QUOTES, 'UTF-8');
    }

    public static function originalLabel(): string
    {
        return html_entity_decode('&#1054;&#1088;&#1080;&#1075;&#1080;&#1085;&#1072;&#1083;', ENT_QUOTES, 'UTF-8');
    }

    protected static function analogParenthesesPattern(): string
    {
        return '/(?<![\pL\pN])\(\s*\x{0430}\x{043D}\x{0430}\x{043B}\x{043E}\x{0433}\s*\)(?![\pL\pN])/iu';
    }

    protected static function analogWordPattern(): string
    {
        return '/(?<![\pL\pN])\x{0430}\x{043D}\x{0430}\x{043B}\x{043E}\x{0433}(?![\pL\pN])/iu';
    }

    protected static function nonOriginalParenthesesPattern(): string
    {
        return '/(?<![\pL\pN])\(\s*(?:\x{043D}\x{0435}\s*[-\s]?\s*\x{043E}\x{0440}\x{0438}\x{0433}\x{0438}\x{043D}\x{0430}\x{043B}|\x{043D}\x{0435}\s*[-\s]?\s*\x{043E}\x{0440}\x{0438}\x{0433}\x{0456}\x{043D}\x{0430}\x{043B})\s*\)(?![\pL\pN])/iu';
    }

    protected static function nonOriginalWordPattern(): string
    {
        return '/(?<![\pL\pN])(?:\x{043D}\x{0435}\s*[-\s]?\s*\x{043E}\x{0440}\x{0438}\x{0433}\x{0438}\x{043D}\x{0430}\x{043B}|\x{043D}\x{0435}\s*[-\s]?\s*\x{043E}\x{0440}\x{0438}\x{0433}\x{0456}\x{043D}\x{0430}\x{043B})(?![\pL\pN])/iu';
    }

    protected static function originalParenthesesPattern(): string
    {
        return '/(?<![\pL\pN])\(\s*(?:\x{043E}\x{0440}\x{0438}\x{0433}\x{0438}\x{043D}\x{0430}\x{043B}|\x{043E}\x{0440}\x{0438}\x{0433}\x{0456}\x{043D}\x{0430}\x{043B})\s*\)(?![\pL\pN])/iu';
    }

    protected static function originalWordPattern(): string
    {
        return '/(?<![\pL\pN])(?:\x{043E}\x{0440}\x{0438}\x{0433}\x{0438}\x{043D}\x{0430}\x{043B}|\x{043E}\x{0440}\x{0438}\x{0433}\x{0456}\x{043D}\x{0430}\x{043B})(?![\pL\pN])/iu';
    }

    protected static function usedParenthesesPattern(): string
    {
        return '/(?<![\pL\pN])\(\s*\x{0431}\s*\/?\s*\x{0443}\s*\)(?![\pL\pN])/iu';
    }

    protected static function usedWordPattern(): string
    {
        return '/(?<![\pL\pN])\x{0431}\s*\/?\s*\x{0443}(?![\pL\pN])/iu';
    }
}
