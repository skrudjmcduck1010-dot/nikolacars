<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PartCatalogLanguageMarkerConflict
{
    public static function apply(array $rawAttributes, string $locale, array $detection): array
    {
        unset($rawAttributes['name_language_marker_conflict_'.$locale]);

        $conflict = $detection['conflict'] ?? null;

        if (($detection['source'] ?? null) !== 'language_marker'
            || ! is_array($conflict)
            || (int) ($conflict['count'] ?? 0) <= 0
        ) {
            return $rawAttributes;
        }

        $rawAttributes['name_language_marker_conflict_'.$locale] = [
            'locale' => $conflict['locale'] ?? null,
            'count' => (int) ($conflict['count'] ?? 0),
            'markers' => array_values(array_filter((array) ($conflict['markers'] ?? []))),
        ];

        return $rawAttributes;
    }

    public static function activeMarkers(mixed $conflict, iterable $activeMarkers): Collection
    {
        $activeMarkers = collect($activeMarkers)
            ->map(fn (mixed $marker): string => Str::lower(trim((string) $marker)))
            ->filter()
            ->unique()
            ->values();

        if ((int) data_get((array) $conflict, 'count', 0) <= 0 || $activeMarkers->isEmpty()) {
            return collect();
        }

        return collect((array) data_get((array) $conflict, 'markers', []))
            ->map(fn (mixed $marker): string => trim((string) $marker))
            ->filter()
            ->filter(fn (string $marker): bool => $activeMarkers->contains(Str::lower($marker)))
            ->unique()
            ->values();
    }

    public static function hasActiveMarker(mixed $conflict, iterable $activeMarkers): bool
    {
        return self::activeMarkers($conflict, $activeMarkers)->isNotEmpty();
    }
}
