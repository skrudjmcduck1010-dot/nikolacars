<?php

namespace App\Services\Concerns;

use App\Services\TeslaPartsUkraineCatalogImporter;
use App\Support\PartCatalogLanguageMarkerConflict;
use App\Support\PartCatalogLanguageMarkers;
use Illuminate\Support\Str;

trait DetectsPartCatalogLocalizedNames
{
    public function localizedNamePayload(string $name, ?string $sourceName = null): array
    {
        return $this->localizedNamePayloadFromResolution($this->localizedNameResolution($name, $sourceName));
    }

    public function localizedNameDetection(string $name, ?string $sourceName = null): array
    {
        return $this->localizedNameResolution($name, $sourceName);
    }

    protected function localizedNamePayloadFromResolution(array $resolution): array
    {
        return match ($resolution['locale'] ?? null) {
            'ru' => ['name_ru' => $resolution['name']],
            'ua' => ['name_ua' => $resolution['name']],
            'both' => ['name_ru' => $resolution['name'], 'name_ua' => $resolution['name']],
            default => [],
        };
    }

    protected function withLocalizedNameMarkerConflict(array $rawAttributes, string $locale, array $detection): array
    {
        return PartCatalogLanguageMarkerConflict::apply($rawAttributes, $locale, $detection);
    }

    protected function localizedNameSourceLocales(array $resolution): array
    {
        return match ($resolution['locale'] ?? null) {
            'ru' => ['ru'],
            'ua' => ['ua'],
            'both' => ['ru', 'ua'],
            default => [],
        };
    }

    protected function localizedNameResolution(string $name, ?string $sourceName = null): array
    {
        $name = Str::limit($this->clean($name), 255, '');
        $detection = $sourceName !== null && $this->clean($sourceName) !== ''
            ? $this->detectLocalizedNameLanguage($sourceName)
            : null;

        $detection ??= $this->detectLocalizedNameLanguage($name);

        return [
            'name' => $name,
            'locale' => $detection['locale'] ?? null,
            'source' => $detection['source'] ?? null,
            'marker' => $detection['marker'] ?? null,
            'conflict' => $detection['conflict'] ?? null,
        ];
    }

    protected function detectLocalizedNameLanguage(string $name): ?array
    {
        $name = Str::lower($this->clean($name));
        $name = $this->withoutLocalizedLanguageStopWords($name);

        if ($name === '') {
            return null;
        }

        if (! $this->containsLanguageSpecificLocalizedNameMarker($name)
            && $this->allCyrillicWordsAreSharedLanguageMarkers($name)) {
            return ['locale' => 'both', 'source' => 'shared_language_marker', 'marker' => null];
        }

        $sharedMarkerConflict = $this->detectSharedLocalizedNameMarkerConflict($name);
        if ($sharedMarkerConflict !== null) {
            return $sharedMarkerConflict;
        }

        $uaScore = preg_match_all('/[\x{0456}\x{0457}\x{0454}\x{0491}]/u', $name) ?: 0;
        $ruScore = preg_match_all('/[\x{044B}\x{044D}\x{0451}\x{044A}]/u', $name) ?: 0;

        if ($uaScore > 0 && $ruScore > 0) {
            return null;
        }

        if ($uaScore > 0 || $ruScore > 0) {
            return ['locale' => $uaScore >= $ruScore ? 'ua' : 'ru', 'source' => 'alphabet', 'marker' => null];
        }

        $uaMarkers = $this->localizedNameMarkers('ua');
        $ruMarkers = $this->localizedNameMarkers('ru');

        $matchedUaMarkers = [];
        $matchedRuMarkers = [];

        foreach ($uaMarkers as $marker) {
            if ($this->containsLocalizedNameMarker($name, $marker)) {
                $uaScore++;
                $matchedUaMarker = $marker;
                $matchedUaMarkers[] = $marker;
            }
        }

        foreach ($ruMarkers as $marker) {
            if ($this->containsLocalizedNameMarker($name, $marker)) {
                $ruScore++;
                $matchedRuMarker = $marker;
                $matchedRuMarkers[] = $marker;
            }
        }

        if ($uaScore === 0 && $ruScore === 0) {
            return null;
        }

        if ($uaScore > 0 && $ruScore > 0 && $uaScore === $ruScore) {
            return [
                'locale' => null,
                'source' => 'language_marker_conflict',
                'conflict' => [
                    'ua_count' => $uaScore,
                    'ru_count' => $ruScore,
                    'ua_markers' => array_values(array_unique($matchedUaMarkers)),
                    'ru_markers' => array_values(array_unique($matchedRuMarkers)),
                ],
            ];
        }

        $locale = $uaScore >= $ruScore ? 'ua' : 'ru';
        $oppositeLocale = $locale === 'ua' ? 'ru' : 'ua';
        $oppositeCount = $locale === 'ua' ? $ruScore : $uaScore;
        $oppositeMarkers = $locale === 'ua' ? $matchedRuMarkers : $matchedUaMarkers;

        return [
            'locale' => $locale,
            'source' => 'language_marker',
            'marker' => $locale === 'ua' ? ($matchedUaMarker ?? null) : ($matchedRuMarker ?? null),
            'conflict' => $oppositeCount > 0 ? [
                'locale' => $oppositeLocale,
                'count' => $oppositeCount,
                'markers' => array_values(array_unique($oppositeMarkers)),
            ] : null,
        ];
    }

    protected function localizedNameMarkers(string $locale): array
    {
        if (array_key_exists($locale, $this->localizedNameMarkersCache)) {
            return $this->localizedNameMarkersCache[$locale];
        }

        $defaultMarkers = array_filter(
            array_column(TeslaPartsUkraineCatalogImporter::DEFAULT_LOCALIZED_NAME_MARKER_PAIRS, $locale),
            fn (string $marker): bool => $this->isUsableLocalizedLanguageMarker($marker)
                && ! $this->isLocalizedLanguageStopWord($marker)
        );

        if (! PartCatalogLanguageMarkers::tableExists()) {
            return $this->localizedNameMarkersCache[$locale] = array_values(array_unique($defaultMarkers));
        }

        $activeMarkers = PartCatalogLanguageMarkers::activeRawForLocale($locale)
            ->map(fn (mixed $marker): string => Str::lower($this->clean((string) $marker)))
            ->filter()
            ->filter(fn (string $marker): bool => $this->isUsableLocalizedLanguageMarker($marker))
            ->reject(fn (string $marker): bool => $this->isLocalizedLanguageStopWord($marker))
            ->reject(fn (string $marker): bool => in_array($marker, $this->sharedLocalizedNameMarkers(), true))
            ->all();

        return $this->localizedNameMarkersCache[$locale] = array_values(array_unique($activeMarkers));
    }

    protected function allCyrillicWordsAreSharedLanguageMarkers(string $name): bool
    {
        $words = $this->localizedNameWords($name);

        if ($words === []) {
            return false;
        }

        $markers = $this->sharedLocalizedNameMarkers();

        if ($markers === []) {
            return false;
        }

        foreach ($words as $word) {
            if (! in_array($word, $markers, true)) {
                return false;
            }
        }

        return true;
    }

    protected function detectSharedLocalizedNameMarkerConflict(string $name): ?array
    {
        $words = $this->localizedNameWords($name);
        $sharedMarkers = $this->sharedLocalizedNameMarkers();

        if ($words === [] || $sharedMarkers === []) {
            return null;
        }

        $uaMarkers = $this->localizedNameMarkers('ua');
        $ruMarkers = $this->localizedNameMarkers('ru');
        $matchedSharedMarkers = [];
        $matchedUaMarkers = [];
        $matchedRuMarkers = [];

        foreach ($words as $word) {
            if (in_array($word, $sharedMarkers, true)) {
                $matchedSharedMarkers[] = $word;

                continue;
            }

            if (in_array($word, $uaMarkers, true)) {
                $matchedUaMarkers[] = $word;

                continue;
            }

            if (in_array($word, $ruMarkers, true)) {
                $matchedRuMarkers[] = $word;

                continue;
            }

            return null;
        }

        if ($matchedSharedMarkers === [] || ($matchedUaMarkers === [] && $matchedRuMarkers === [])) {
            return null;
        }

        if ($matchedUaMarkers !== [] && $matchedRuMarkers !== []) {
            return null;
        }

        $conflictLocale = $matchedUaMarkers !== [] ? 'ua' : 'ru';
        $conflictMarkers = array_values(array_unique($matchedUaMarkers !== [] ? $matchedUaMarkers : $matchedRuMarkers));

        return [
            'locale' => 'both',
            'source' => 'language_marker',
            'marker' => null,
            'conflict' => [
                'locale' => $conflictLocale,
                'count' => count($conflictMarkers),
                'markers' => $conflictMarkers,
            ],
        ];
    }

    protected function localizedNameWords(string $name): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[-\/]?[\p{L}\p{N}]+)*/u', Str::lower($this->clean($name)), $matches);

        return array_values(array_unique(array_filter(
            array_map(fn (string $word): string => trim($word, "-_/ \t\n\r\0\x0B"), $matches[0] ?? []),
            fn (string $word): bool => $this->isUsableLocalizedLanguageMarker($word)
        )));
    }

    protected function containsLanguageSpecificLocalizedNameMarker(string $name): bool
    {
        foreach (['ua', 'ru'] as $locale) {
            foreach ($this->localizedNameMarkers($locale) as $marker) {
                if ($this->containsLocalizedNameMarker($name, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function sharedLocalizedNameMarkers(): array
    {
        $cacheKey = 'shared';

        if (array_key_exists($cacheKey, $this->localizedNameMarkersCache)) {
            return $this->localizedNameMarkersCache[$cacheKey];
        }

        if (! PartCatalogLanguageMarkers::tableExists()) {
            return $this->localizedNameMarkersCache[$cacheKey] = [];
        }

        $markers = PartCatalogLanguageMarkers::activePairs()
            ->map(function (array $marker): ?string {
                $uaMarker = Str::lower($this->clean((string) ($marker['ua'] ?? '')));
                $ruMarker = Str::lower($this->clean((string) ($marker['ru'] ?? '')));

                if ($uaMarker === ''
                    || $uaMarker !== $ruMarker
                    || ! $this->isUsableLocalizedLanguageMarker($uaMarker)
                    || $this->isLocalizedLanguageStopWord($uaMarker)
                ) {
                    return null;
                }

                return $uaMarker;
            })
            ->filter()
            ->values()
            ->all();

        return $this->localizedNameMarkersCache[$cacheKey] = array_values(array_unique($markers));
    }

    protected function withoutLocalizedLanguageStopWords(string $name): string
    {
        foreach (self::LOCALIZED_LANGUAGE_STOP_WORDS as $word) {
            $name = preg_replace(
                '/(?<![\pL\pN])'.preg_quote($word, '/').'(?![\pL\pN])/u',
                ' ',
                $name
            ) ?? $name;
        }

        return $this->clean($name);
    }

    protected function isLocalizedLanguageStopWord(string $marker): bool
    {
        return in_array(Str::lower($this->clean($marker)), self::LOCALIZED_LANGUAGE_STOP_WORDS, true);
    }

    protected function isUsableLocalizedLanguageMarker(string $marker): bool
    {
        $marker = trim(Str::lower($this->clean($marker)));

        if ($marker === '' || preg_match('/\d/u', $marker) || ! preg_match('/\p{Cyrillic}/u', $marker)) {
            return false;
        }

        preg_match_all('/\p{Cyrillic}/u', $marker, $letters);

        return count($letters[0] ?? []) >= 3;
    }

    protected function containsLocalizedNameMarker(string $name, string $marker): bool
    {
        $marker = trim($marker);

        if (! $this->isUsableLocalizedLanguageMarker($marker)) {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($marker, '/').'(?![\p{L}\p{N}])/u', $name) === 1;
    }
}
