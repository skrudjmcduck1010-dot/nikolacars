<?php

namespace App\Services;

final class CatalogPath
{
    // Only reviewed equivalent landing pages. Taxonomy levels and IDs stay intact.
    // Full non-empty product sets were checked in both locales on 2026-10-27.
    // Prefer the existing leaf (Google already selected it for Model 3 pedals).
    // Battery paths are excluded: their UA/RU inventories do not match.
    private const PREFERRED_PATHS = [
        'model-3-06-2017-12-2023' => [
            'galma/pedal-galma' => 'galma/pedal-galma/pedal-galma',
            'tormoza/pedal-tormoza' => 'tormoza/pedal-tormoza/pedal-tormoza',
        ],
        'model-s-02-2012-03-2016' => [
            'visokovoltna-sistema/bortovii-zariadnii-modul-cardzer' => 'visokovoltna-sistema/bortovii-zariadnii-modul-cardzer/bortovii-zariadnii-modul-cardzer',
            'vysokovoltnaia-sistema/bortovoi-zariadnyi-modul-cardzer' => 'vysokovoltnaia-sistema/bortovoi-zariadnyi-modul-cardzer/bortovoi-zariadnyi-modul-cardzer',
        ],
        'model-3-highland-01-2024' => [
            'diski-i-sini/diski' => 'diski-i-sini/diski/diski',
            'diski-i-siny/diski' => 'diski-i-siny/diski/diski',
        ],
        '' => [
            'galma/pedal-galma' => 'galma/pedal-galma/pedal-galma',
            'tormoza/pedal-tormoza' => 'tormoza/pedal-tormoza/pedal-tormoza',
            'zadnii-motor/zadni-pivosi' => 'zadnii-motor/zadni-pivosi/zadni-pivosi',
            'zadnii-motor/zadnie-poluosi' => 'zadnii-motor/zadnie-poluosi/zadnie-poluosi',
            'zovnisnia-furnitura/podium-nomernogo-znaka' => 'zovnisnia-furnitura/podium-nomernogo-znaka/podium-nomernogo-znaka',
            'naruznaia-furnitura/podium-nomernogo-znaka' => 'naruznaia-furnitura/podium-nomernogo-znaka/podium-nomernogo-znaka',
        ],
    ];

    public static function preferred(string $path, string $modelSlug = ''): string
    {
        $path = self::normalize($path);

        return self::PREFERRED_PATHS[$modelSlug][$path] ?? $path;
    }

    public static function preferredPaths(): array
    {
        return self::PREFERRED_PATHS;
    }

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
