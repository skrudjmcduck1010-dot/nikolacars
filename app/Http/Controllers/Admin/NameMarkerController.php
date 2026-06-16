<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartCatalogItem;
use App\Models\TranslationLanguageMarker;
use App\Services\PartCatalogLanguageMarkerNameRebuilder;
use App\Services\TeslaPartsUkraineCatalogImporter;
use App\Services\TskCatalogImporter;
use App\Support\CatalogTextEncoding;
use App\Support\PartCatalogLanguageMarkerConflict;
use App\Support\PartCatalogLocalizedNameCleaner;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NameMarkerController extends Controller
{
    public function index(Request $request): View
    {
        $showAllLanguageMarkers = $request->boolean('show_all_language_markers');

        $this->seedDefaultLanguageMarkers();
        $this->repairLanguageMarkerEncoding();

        $languageMarkerCount = 0;
        $languageMarkers = collect();

        if (Schema::hasTable('translation_language_markers')) {
            $languageMarkerQuery = TranslationLanguageMarker::query()
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $languageMarkerCount = (clone $languageMarkerQuery)->count();
            $languageMarkers = $showAllLanguageMarkers
                ? $languageMarkerQuery->get()
                : $languageMarkerQuery
                    ->paginate(10, ['*'], 'language_markers_page')
                    ->withQueryString();
        }

        $unclassifiedLocalizedNameQuery = $this->unclassifiedLocalizedNameQuery();
        $unclassifiedLocalizedNameCount = (clone $unclassifiedLocalizedNameQuery)->count();
        $unclassifiedLocalizedNameItems = $unclassifiedLocalizedNameQuery
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        $unclassifiedLocalizedNameWords = $this->unclassifiedLocalizedNameWords($request);

        return view('admin.name_markers.index', [
            'languageMarkers' => $languageMarkers,
            'languageMarkerCount' => $languageMarkerCount,
            'showAllLanguageMarkers' => $showAllLanguageMarkers,
            'languageMarkerLetters' => [
                'ua' => ['і', 'ї', 'є', 'ґ'],
                'ru' => ['ы', 'э', 'ё', 'ъ'],
            ],
            'unclassifiedLocalizedNameItems' => $unclassifiedLocalizedNameItems,
            'unclassifiedLocalizedNameCount' => $unclassifiedLocalizedNameCount,
            'unclassifiedLocalizedNameWords' => $unclassifiedLocalizedNameWords,
            'itemUrl' => fn (PartCatalogItem $item): string => $this->itemUrl($item),
        ]);
    }

    public function storeLanguageMarker(
        Request $request,
        TeslaPartsUkraineCatalogImporter $teslaPartsUkraineImporter,
        TskCatalogImporter $tskImporter,
        PartCatalogLanguageMarkerNameRebuilder $rebuilder
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'ua_marker' => ['required', 'string', 'max:255'],
            'ru_marker' => ['required', 'string', 'max:255'],
        ]);

        $uaMarker = mb_strtolower(trim((string) $validated['ua_marker']));
        $ruMarker = mb_strtolower(trim((string) $validated['ru_marker']));

        if ($uaMarker === '' || $ruMarker === '') {
            throw ValidationException::withMessages([
                'ua_marker' => 'Заполните оба маркера.',
            ]);
        }

        $exists = TranslationLanguageMarker::query()
            ->where('ua_marker', $uaMarker)
            ->where('ru_marker', $ruMarker)
            ->exists();

        if ($exists) {
            $stats = $rebuilder->rebuild([$uaMarker, $ruMarker]);
            $updatedTeslaPartsUkraineNames = $stats['teslapartsukraine'];
            $updatedTskNames = $stats['tsk'];

            $message = "Такая пара маркеров уже есть. Я заново проверил названия: TeslaPartsUkraine {$updatedTeslaPartsUkraineNames}, TSK {$updatedTskNames}.";

            if ($request->expectsJson()) {
                return response()->json(['message' => $message]);
            }

            return back()->with('status', $message);
        }

        TranslationLanguageMarker::query()->create([
            'ua_marker' => $uaMarker,
            'ru_marker' => $ruMarker,
        ]);

        $updatedTeslaPartsUkraineNames = $this->refreshUnclassifiedLocalizedNames(
            'teslapartsukraine',
            $teslaPartsUkraineImporter,
            'TeslaPartsUkraine',
            [$uaMarker, $ruMarker],
        );
        $updatedTskNames = $this->refreshUnclassifiedLocalizedNames(
            'tsk',
            $tskImporter,
            'TSK',
            [$uaMarker, $ruMarker],
        );

        $stats = $rebuilder->rebuild([$uaMarker, $ruMarker]);
        $updatedTeslaPartsUkraineNames = $stats['teslapartsukraine'];
        $updatedTskNames = $stats['tsk'];
        $message = "Маркер языка добавлен. Названия обновлены: TeslaPartsUkraine {$updatedTeslaPartsUkraineNames}, TSK {$updatedTskNames}.";

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 201);
        }

        return back()->with('status', $message);
    }

    public function destroyLanguageMarker(
        TranslationLanguageMarker $marker,
        TeslaPartsUkraineCatalogImporter $teslaPartsUkraineImporter,
        TskCatalogImporter $tskImporter,
        PartCatalogLanguageMarkerNameRebuilder $rebuilder
    ): RedirectResponse {
        $uaMarker = (string) $marker->ua_marker;
        $ruMarker = (string) $marker->ru_marker;

        $marker->delete();

        $rollback = $this->rollbackLocalizedNamesForDeletedMarker(
            $uaMarker,
            $ruMarker,
            $teslaPartsUkraineImporter,
            $tskImporter,
        );
        $stats = $rebuilder->rebuild([$uaMarker, $ruMarker]);
        $rollback['teslapartsukraine'] += $stats['teslapartsukraine'];
        $rollback['tsk'] += $stats['tsk'];

        return back()->with(
            'status',
            "Маркер языка удален. Откат названий: TeslaPartsUkraine {$rollback['teslapartsukraine']}, TSK {$rollback['tsk']}."
        );
    }

    public function rotateLanguageMarker(
        TranslationLanguageMarker $marker,
        TeslaPartsUkraineCatalogImporter $teslaPartsUkraineImporter,
        TskCatalogImporter $tskImporter,
        PartCatalogLanguageMarkerNameRebuilder $rebuilder
    ): RedirectResponse {
        $oldUaMarker = (string) $marker->ua_marker;
        $oldRuMarker = (string) $marker->ru_marker;
        $newUaMarker = mb_strtolower(trim($oldRuMarker));
        $newRuMarker = mb_strtolower(trim($oldUaMarker));

        if ($newUaMarker === '' || $newRuMarker === '') {
            return back()->withErrors(['ua_marker' => 'Нельзя ротировать пустой маркер.']);
        }

        $existing = TranslationLanguageMarker::query()
            ->whereKeyNot($marker->id)
            ->where('ua_marker', $newUaMarker)
            ->where('ru_marker', $newRuMarker)
            ->first();

        if ($existing instanceof TranslationLanguageMarker) {
            $marker->delete();
        } else {
            $marker->forceFill([
                'ua_marker' => $newUaMarker,
                'ru_marker' => $newRuMarker,
            ])->save();
        }

        $rollback = $this->rollbackLocalizedNamesForDeletedMarker(
            $oldUaMarker,
            $oldRuMarker,
            $teslaPartsUkraineImporter,
            $tskImporter,
        );
        $updatedTeslaPartsUkraineNames = $this->refreshUnclassifiedLocalizedNames(
            'teslapartsukraine',
            $teslaPartsUkraineImporter,
            'TeslaPartsUkraine',
            [$newUaMarker, $newRuMarker],
        );
        $updatedTskNames = $this->refreshUnclassifiedLocalizedNames(
            'tsk',
            $tskImporter,
            'TSK',
            [$newUaMarker, $newRuMarker],
        );
        $stats = $rebuilder->rebuild([$oldUaMarker, $oldRuMarker, $newUaMarker, $newRuMarker]);

        return back()->with(
            'status',
            'Маркер языка ротирован. Перепроверено названий: TeslaPartsUkraine '
                .($rollback['teslapartsukraine'] + $updatedTeslaPartsUkraineNames + $stats['teslapartsukraine'])
                .', TSK '
                .($rollback['tsk'] + $updatedTskNames + $stats['tsk'])
                .'.'
        );
    }

    public function updateNamePair(
        Request $request
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'old_name_ru' => ['required', 'string', 'max:255'],
            'old_name_ua' => ['required', 'string', 'max:255'],
            'field' => ['required', Rule::in(['name_ru', 'name_ua'])],
            'value' => ['required', 'string', 'max:255'],
        ]);

        $field = (string) $validated['field'];
        $value = PartCatalogLocalizedNameCleaner::clean($validated['value']);

        $query = PartCatalogItem::query()
            ->where('name_ru', $validated['old_name_ru'])
            ->where('name_ua', $validated['old_name_ua']);

        $updated = (clone $query)->count();
        $query->update([$field => $value]);

        $message = "Название обновлено у {$updated} позиций.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'updated' => $updated,
                'value' => $value,
            ]);
        }

        return back()->with('status', $message);
    }

    protected function unclassifiedLocalizedNameQuery()
    {
        return PartCatalogItem::query()
            ->whereIn('source', ['teslapartsukraine', 'tsk'])
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->where('name', 'regexp', '[А-Яа-яЁёІіЇїЄєҐґ]')
            ->where(function ($query): void {
                $query
                    ->whereNull('name_ru')
                    ->orWhere('name_ru', '');
            })
            ->where(function ($query): void {
                $query
                    ->whereNull('name_ua')
                    ->orWhere('name_ua', '');
            })
            ->select([
                'id',
                'source',
                'source_url',
                'name',
                'part_number',
                'model_label',
                'updated_at',
            ]);
    }

    protected function unclassifiedLocalizedNameWords(Request $request): LengthAwarePaginator
    {
        $counts = [];
        $existingMarkers = $this->existingLanguageMarkerWords();

        $this->unclassifiedLocalizedNameQuery()
            ->pluck('name')
            ->each(function (?string $name) use (&$counts): void {
                preg_match_all('/[\p{L}\p{N}]+(?:[-\/]?[\p{L}\p{N}]+)*/u', Str::lower((string) $name), $matches);

                foreach ($matches[0] ?? [] as $word) {
                    $word = trim($word, "-_/ \t\n\r\0\x0B");

                    if (! $this->isUsableLanguageMarkerWord($word)) {
                        continue;
                    }

                    $counts[$word] = ($counts[$word] ?? 0) + 1;
                }
            });

        arsort($counts, SORT_NUMERIC);
        $counts = array_diff_key($counts, $existingMarkers);

        $rows = [];
        foreach ($counts as $word => $count) {
            $rows[] = (object) [
                'word' => $word,
                'count' => $count,
            ];
        }

        $perPage = 50;
        $pageName = 'unclassified_words_page';
        $page = max(1, (int) $request->query($pageName, 1));

        return new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => $pageName,
                'query' => $request->query(),
            ]
        );
    }

    protected function existingLanguageMarkerWords(): array
    {
        if (! Schema::hasTable('translation_language_markers')) {
            return [];
        }

        $words = [];

        TranslationLanguageMarker::query()
            ->get(['ua_marker', 'ru_marker'])
            ->each(function (TranslationLanguageMarker $marker) use (&$words): void {
                foreach ([$marker->ua_marker, $marker->ru_marker] as $value) {
                    preg_match_all('/[\p{L}\p{N}]+(?:[-\/]?[\p{L}\p{N}]+)*/u', Str::lower((string) $value), $matches);

                    foreach ($matches[0] ?? [] as $word) {
                        $word = trim($word, "-_/ \t\n\r\0\x0B");

                        if ($this->isUsableLanguageMarkerWord($word)) {
                            $words[$word] = true;
                        }
                    }
                }
            });

        return $words;
    }

    protected function isUsableLanguageMarkerWord(string $word): bool
    {
        $word = trim($word, "-_/ \t\n\r\0\x0B");

        if ($word === '' || preg_match('/\d/u', $word) || ! preg_match('/\p{Cyrillic}/u', $word)) {
            return false;
        }

        preg_match_all('/\p{Cyrillic}/u', $word, $letters);

        return count($letters[0] ?? []) >= 3;
    }

    protected function normalizeNameForCompare(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = preg_replace('/\s+/u', ' ', $value) ?: '';

        return trim($value);
    }

    protected function itemUrl(PartCatalogItem $item): string
    {
        $routePrefix = $item->source === 'tesla_official'
            ? 'admin.tesla-official-catalog'
            : (string) data_get(config('catalog_sources.sources.'.$item->source), 'route_prefix', 'admin.part-catalog');

        return route($routePrefix.'.show', $item);
    }

    protected function seedDefaultLanguageMarkers(): void
    {
        if (! Schema::hasTable('translation_language_markers')) {
            return;
        }

        if (TranslationLanguageMarker::query()->exists()) {
            return;
        }

        foreach (TeslaPartsUkraineCatalogImporter::DEFAULT_LOCALIZED_NAME_MARKER_PAIRS as $pair) {
            TranslationLanguageMarker::query()->firstOrCreate([
                'ua_marker' => $pair['ua'],
                'ru_marker' => $pair['ru'],
            ]);
        }
    }

    protected function repairLanguageMarkerEncoding(): void
    {
        if (! Schema::hasTable('translation_language_markers')) {
            return;
        }

        TranslationLanguageMarker::query()
            ->orderBy('id')
            ->get()
            ->each(function (TranslationLanguageMarker $marker): void {
                $uaMarker = CatalogTextEncoding::repair((string) $marker->ua_marker);
                $ruMarker = CatalogTextEncoding::repair((string) $marker->ru_marker);

                if ($uaMarker === $marker->ua_marker && $ruMarker === $marker->ru_marker) {
                    return;
                }

                $duplicate = TranslationLanguageMarker::query()
                    ->whereKeyNot($marker->id)
                    ->where('ua_marker', $uaMarker)
                    ->where('ru_marker', $ruMarker)
                    ->first();

                if ($duplicate) {
                    $marker->delete();

                    return;
                }

                $marker->forceFill([
                    'ua_marker' => $uaMarker,
                    'ru_marker' => $ruMarker,
                ])->save();
            });
    }

    protected function refreshUnclassifiedLocalizedNames(
        string $source,
        object $importer,
        string $sourceLabel,
        array $markers = []
    ): int {
        $updated = 0;
        $markers = collect($markers)
            ->map(fn (string $marker): string => trim($marker))
            ->filter()
            ->unique()
            ->values()
            ->all();

        PartCatalogItem::query()
            ->where('source', $source)
            ->where(fn ($query) => $query->whereNull('name_ru')->orWhere('name_ru', ''))
            ->where(fn ($query) => $query->whereNull('name_ua')->orWhere('name_ua', ''))
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->when($markers !== [], function ($query) use ($markers): void {
                $query->where(function ($query) use ($markers): void {
                    foreach ($markers as $marker) {
                        $query->orWhere('name', 'like', '%'.$marker.'%');
                    }
                });
            })
            ->select(['id', 'name', 'name_ru', 'name_ua', 'source_url', 'raw_attributes'])
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($importer, $sourceLabel, $markers, &$updated): void {
                foreach ($items as $item) {
                    $detection = method_exists($importer, 'localizedNameDetection')
                        ? $importer->localizedNameDetection((string) $item->name)
                        : [];
                    $payload = $importer->localizedNamePayload((string) $item->name);

                    if ($payload === []) {
                        continue;
                    }

                    $rawAttributes = PartCatalogRawAttributes::from($item);
                    $sourceUrl = (string) ($item->source_url ?: data_get($rawAttributes, 'product_url') ?: data_get($rawAttributes, 'listing_product_url'));
                    $matchedMarker = $this->matchedLanguageMarker((string) $item->name, $markers);

                    if ($sourceUrl !== '') {
                        if (array_key_exists('name_ru', $payload)) {
                            $rawAttributes['name_source_url_ru'] = $sourceUrl;
                            $rawAttributes['name_source_site_ru'] = $sourceLabel;
                            $rawAttributes['name_source_item_id_ru'] = $item->id;
                            if ($matchedMarker !== null) {
                                $rawAttributes['name_source_type_ru'] = 'language_marker';
                                $rawAttributes['name_source_marker_ru'] = $matchedMarker;
                            }
                            $rawAttributes = PartCatalogLanguageMarkerConflict::apply($rawAttributes, 'ru', $detection);
                        }

                        if (array_key_exists('name_ua', $payload)) {
                            $rawAttributes['name_source_url_ua'] = $sourceUrl;
                            $rawAttributes['name_source_site_ua'] = $sourceLabel;
                            $rawAttributes['name_source_item_id_ua'] = $item->id;
                            if ($matchedMarker !== null) {
                                $rawAttributes['name_source_type_ua'] = 'language_marker';
                                $rawAttributes['name_source_marker_ua'] = $matchedMarker;
                            }
                            $rawAttributes = PartCatalogLanguageMarkerConflict::apply($rawAttributes, 'ua', $detection);
                        }

                        $payload['raw_attributes'] = $rawAttributes;
                    }

                    $item->forceFill($payload)->save();
                    $updated++;
                }
            });

        return $updated;
    }

    protected function matchedLanguageMarker(string $name, array $markers): ?string
    {
        $name = Str::lower($this->normalizeNameForCompare($name));

        foreach ($markers as $marker) {
            $marker = Str::lower($this->normalizeNameForCompare($marker));

            if ($marker !== '' && str_contains($name, $marker)) {
                return $marker;
            }
        }

        return null;
    }

    protected function rollbackLocalizedNamesForDeletedMarker(
        string $uaMarker,
        string $ruMarker,
        TeslaPartsUkraineCatalogImporter $teslaPartsUkraineImporter,
        TskCatalogImporter $tskImporter
    ): array {
        $stats = [
            'teslapartsukraine' => 0,
            'tsk' => 0,
        ];
        $importers = [
            'teslapartsukraine' => $teslaPartsUkraineImporter,
            'tsk' => $tskImporter,
        ];
        $labels = [
            'teslapartsukraine' => 'TeslaPartsUkraine',
            'tsk' => 'TSK',
        ];
        $selectColumns = array_values(array_filter([
            'id',
            'source',
            'name',
            'name_ru',
            'name_ua',
            'source_url',
            'raw_attributes',
            Schema::hasColumn('part_catalog_items', 'name_ru_manually_locked_at') ? 'name_ru_manually_locked_at' : null,
            Schema::hasColumn('part_catalog_items', 'name_ua_manually_locked_at') ? 'name_ua_manually_locked_at' : null,
        ]));

        foreach ($importers as $source => $importer) {
            PartCatalogItem::query()
                ->where('source', $source)
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->where(function ($query): void {
                    $query
                        ->where(fn ($nameQuery) => $nameQuery->whereNotNull('name_ru')->where('name_ru', '!=', ''))
                        ->orWhere(fn ($nameQuery) => $nameQuery->whereNotNull('name_ua')->where('name_ua', '!=', ''));
                })
                ->select($selectColumns)
                ->orderBy('id')
                ->chunkById(200, function ($items) use ($source, $importer, $labels, $uaMarker, $ruMarker, &$stats): void {
                    foreach ($items as $item) {
                        $rawAttributes = PartCatalogRawAttributes::from($item);
                        $detectionAfterDelete = method_exists($importer, 'localizedNameDetection')
                            ? $importer->localizedNameDetection((string) $item->name)
                            : [];
                        $payloadAfterDelete = $importer->localizedNamePayload((string) $item->name);
                        $updates = [];
                        $newRawAttributes = $this->refreshedLocalizedNameMarkerConflicts(
                            $rawAttributes,
                            $payloadAfterDelete,
                            $detectionAfterDelete
                        );
                        $wasAffectedByDeletedMarker = false;

                        foreach ([
                            'ru' => ['column' => 'name_ru'],
                            'ua' => ['column' => 'name_ua'],
                        ] as $locale => $config) {
                            $column = $config['column'];
                            $currentName = trim((string) $item->{$column});

                            if ($currentName === ''
                                || $this->isLocalizedNameManuallyLocked($item, $rawAttributes, $column, $locale)
                                || ! $this->containsAnyDeletedLanguageMarker((string) $item->name, $uaMarker, $ruMarker)
                                || $this->normalizeNameForCompare($currentName) !== $this->normalizeNameForCompare((string) $item->name)
                                || (
                                    array_key_exists($column, $payloadAfterDelete)
                                    && $this->normalizeNameForCompare((string) $payloadAfterDelete[$column]) === $this->normalizeNameForCompare($currentName)
                                )
                            ) {
                                continue;
                            }

                            $wasAffectedByDeletedMarker = true;
                            $updates[$column] = null;
                            $newRawAttributes = $this->withoutLocalizedNameSource($newRawAttributes, $locale, (int) $item->id, $labels[$source]);
                        }

                        if (! $wasAffectedByDeletedMarker && $newRawAttributes === $rawAttributes) {
                            continue;
                        }

                        if ($wasAffectedByDeletedMarker) {
                            $sourceUrl = (string) ($item->source_url ?: data_get($rawAttributes, 'product_url') ?: data_get($rawAttributes, 'listing_product_url'));
                            foreach ([
                                'ru' => 'name_ru',
                                'ua' => 'name_ua',
                            ] as $locale => $column) {
                                if (! array_key_exists($column, $payloadAfterDelete)
                                    || $this->isLocalizedNameManuallyLocked($item, $rawAttributes, $column, $locale)
                                ) {
                                    continue;
                                }

                                $updates[$column] = $payloadAfterDelete[$column];

                                if ($sourceUrl !== '') {
                                    $newRawAttributes['name_source_url_'.$locale] = $sourceUrl;
                                    $newRawAttributes['name_source_site_'.$locale] = $labels[$source];
                                    $newRawAttributes['name_source_item_id_'.$locale] = $item->id;
                                }
                            }
                        }

                        $updates['raw_attributes'] = $newRawAttributes;
                        $item->forceFill($updates)->save();
                        $stats[$source]++;
                    }
                });
        }

        return $stats;
    }

    protected function refreshedLocalizedNameMarkerConflicts(
        array $rawAttributes,
        array $payload,
        array $detection
    ): array {
        unset(
            $rawAttributes['name_language_marker_conflict_ru'],
            $rawAttributes['name_language_marker_conflict_ua']
        );

        foreach ([
            'ru' => 'name_ru',
            'ua' => 'name_ua',
        ] as $locale => $column) {
            if (array_key_exists($column, $payload)) {
                $rawAttributes = PartCatalogLanguageMarkerConflict::apply($rawAttributes, $locale, $detection);
            }
        }

        return $rawAttributes;
    }

    protected function isLocalizedNameManuallyLocked(PartCatalogItem $item, array $rawAttributes, string $column, string $locale): bool
    {
        $lockColumn = $column.'_manually_locked_at';

        return (Schema::hasColumn('part_catalog_items', $lockColumn) && filled($item->{$lockColumn}))
            || (bool) data_get($rawAttributes, 'manual_name_locks.'.$locale);
    }

    protected function containsAnyDeletedLanguageMarker(string $name, string $uaMarker, string $ruMarker): bool
    {
        return $this->containsDeletedLanguageMarker($name, $uaMarker)
            || $this->containsDeletedLanguageMarker($name, $ruMarker);
    }

    protected function containsDeletedLanguageMarker(string $name, string $marker): bool
    {
        $name = Str::lower($this->normalizeNameForCompare($name));
        $marker = Str::lower($this->normalizeNameForCompare($marker));

        if ($name === '' || $marker === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($marker, '/').'(?![\p{L}\p{N}])/u', $name) === 1
            || str_contains($name, $marker);
    }

    protected function withoutLocalizedNameSource(array $rawAttributes, string $locale, int $itemId, string $sourceLabel): array
    {
        $sourceItemId = data_get($rawAttributes, 'name_source_item_id_'.$locale);
        $sourceSite = data_get($rawAttributes, 'name_source_site_'.$locale);

        if ($sourceItemId === null && $sourceSite === null) {
            return $rawAttributes;
        }

        if ((string) $sourceItemId !== (string) $itemId && (string) $sourceSite !== $sourceLabel) {
            return $rawAttributes;
        }

        unset(
            $rawAttributes['name_source_url_'.$locale],
            $rawAttributes['name_source_site_'.$locale],
            $rawAttributes['name_source_item_id_'.$locale]
        );

        if ($locale === 'ru') {
            unset($rawAttributes['name_source_url'], $rawAttributes['name_source_site']);
        }

        return $rawAttributes;
    }
}
