<?php

namespace App\Support;

use App\Models\TranslationLanguageMarker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PartCatalogLanguageMarkers
{
    public static function tableExists(): bool
    {
        return Schema::hasTable('translation_language_markers');
    }

    public static function activePairs(): Collection
    {
        if (! self::tableExists()) {
            return collect();
        }

        return TranslationLanguageMarker::query()
            ->get(['ua_marker', 'ru_marker'])
            ->map(fn (TranslationLanguageMarker $marker): array => [
                'ua' => $marker->ua_marker,
                'ru' => $marker->ru_marker,
            ])
            ->values();
    }

    public static function activeRawForLocale(string $locale): Collection
    {
        return self::activePairs()
            ->pluck($locale === 'ua' ? 'ua' : 'ru')
            ->values();
    }

    public static function activeNormalized(): Collection
    {
        return self::activePairs()
            ->flatMap(fn (array $marker): array => [$marker['ua'] ?? null, $marker['ru'] ?? null])
            ->map(fn (mixed $marker): string => self::normalize($marker))
            ->filter()
            ->unique()
            ->values();
    }

    private static function normalize(mixed $marker): string
    {
        return Str::lower(trim((string) $marker));
    }
}
