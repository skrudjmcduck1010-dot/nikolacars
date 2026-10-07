<?php

namespace App\Services;

final class CatalogPath
{
    public static function normalize(string $path): string
    {
        $segments = preg_split('~(?:/|--)+~', trim($path, '/'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Equal labels can represent distinct official levels (e.g. 14 and 1405).
        return implode('/', $segments);
    }

    public static function legacyCollapsed(string $path): string
    {
        $segments = explode('/', self::normalize($path));
        $normalized = [];
        foreach ($segments as $segment) {
            if ($normalized === [] || $segment !== $normalized[count($normalized) - 1]) {
                $normalized[] = $segment;
            }
        }

        return implode('/', $normalized);
    }
}
