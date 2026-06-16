<?php

namespace App\Support;

class TextEncodingNormalizer
{
    protected const SOURCE_ENCODINGS = [
        'UTF-8',
        'Windows-1251',
        'CP1251',
        'Windows-1252',
        'ISO-8859-1',
    ];

    public static function normalize(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $value = self::toUtf8($value);
        $value = self::stripUtf8Bom($value);
        $value = self::stripUnsafeControls($value);

        return CatalogTextEncoding::repair($value) ?? $value;
    }

    public static function normalizeArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value) || $value === null) {
                $values[$key] = self::normalize($value);

                continue;
            }

            if (is_array($value)) {
                $values[$key] = self::normalizeArray($value);
            }
        }

        return $values;
    }

    protected static function toUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $encoding = mb_detect_encoding($value, self::SOURCE_ENCODINGS, true) ?: 'Windows-1251';
        $converted = mb_convert_encoding($value, 'UTF-8', $encoding);

        return is_string($converted) && $converted !== '' ? $converted : $value;
    }

    protected static function stripUtf8Bom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    protected static function stripUnsafeControls(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    }
}
