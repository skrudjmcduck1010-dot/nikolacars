<?php

namespace App\Support;

class CatalogTextEncoding
{
    public static function repair(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $value = preg_replace('/Р (?=Р[\x{0080}-\x{00BF}\x{0400}-\x{045F}\x{2010}-\x{203A}])/u', 'Р'.chr(0xC2).chr(0xA0), $value) ?? $value;

        for ($i = 0; $i < 3 && self::looksLikeMojibake($value); $i++) {
            $bytes = self::looksLikeLatinMojibake($value)
                ? iconv('UTF-8', 'Windows-1252//IGNORE', $value)
                : self::mojibakeBytes($value);

            if ($bytes !== false && $bytes !== null && $bytes !== '' && ! mb_check_encoding($bytes, 'UTF-8')) {
                while ($bytes !== '' && ! mb_check_encoding($bytes, 'UTF-8')) {
                    $bytes = substr($bytes, 0, -1);
                }
            }

            if ($bytes === false || $bytes === null || $bytes === '' || ! mb_check_encoding($bytes, 'UTF-8')) {
                break;
            }

            if ($bytes === $value) {
                break;
            }

            $value = $bytes;
        }

        return $value;
    }

    public static function looksLikeMojibake(string $value): bool
    {
        preg_match_all('/[\x{0420}\x{0421}][\x{00A0}-\x{00BF}\x{0400}-\x{040F}\x{0450}-\x{045F}\x{0490}-\x{0491}\x{2010}-\x{203A}\x{20AC}]/u', $value, $singlePassMatches);

        if (count($singlePassMatches[0]) >= 2) {
            return true;
        }

        if (preg_match('/(?:\x{0420}[\x{0490}-\x{0491}\x{2010}-\x{203A}]){2,}/u', $value) === 1) {
            return true;
        }

        if (preg_match('/(?:\x{0421}[\x{0080}-\x{00BF}\x{0400}-\x{040F}\x{0450}-\x{045F}\x{2010}-\x{203A}\x{20AC}]){2,}/u', $value) === 1) {
            return true;
        }

        if (preg_match('/\x{0432}\x{0402}[\x{0080}-\x{00BF}\x{2010}-\x{203A}]|\x{0432}\x{2030}[\x{0080}-\x{00BF}]|\x{0412}[\x{00B7}\x{00AB}\x{00BB}]/u', $value) === 1) {
            return true;
        }

        preg_match_all('/[РС][\x{0080}-\x{00BF}\x{0400}-\x{040F}\x{0450}-\x{045F}\x{2010}-\x{203A}]|в[\x{0080}-\x{00BF}\x{2010}-\x{203A}]|В[·№]/u', $value, $matches);

        return count($matches[0]) >= 2
            || preg_match('/[\x{0080}-\x{009F}]|в[\x{0080}-\x{00BF}\x{2010}-\x{203A}]|В[·№]|[ÐÑ][\x{0080}-\x{00BF}\x{2010}-\x{203A}]/u', $value) === 1;
    }

    protected static function looksLikeLatinMojibake(string $value): bool
    {
        return preg_match('/[ÐÑ][\x{0080}-\x{00BF}\x{2010}-\x{203A}]/u', $value) === 1;
    }

    protected static function mojibakeBytes(string $value): ?string
    {
        $bytes = '';

        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $codepoint = mb_ord($char, 'UTF-8');

            if ($codepoint >= 0x80 && $codepoint <= 0x9F) {
                $bytes .= chr($codepoint);

                continue;
            }

            $encoded = iconv('UTF-8', 'Windows-1251//IGNORE', $char);
            if ($encoded === false) {
                return null;
            }

            $bytes .= $encoded;
        }

        return $bytes;
    }
}
