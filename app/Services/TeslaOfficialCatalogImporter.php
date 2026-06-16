<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use App\Models\Product;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TeslaOfficialCatalogImporter
{
    protected string $source = 'tesla_official';

    public function __construct(
        protected HttpFactory $http,
    ) {}

    public function import(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $allCatalogs = (bool) ($options['all_catalogs'] ?? false);
        $catalogExternalReference = trim((string) ($options['catalog_external_reference'] ?? ''));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $maxCatalogs = max(0, (int) ($options['max_catalogs'] ?? 0));
        $maxSystemGroups = max(0, (int) ($options['max_system_groups'] ?? 0));
        $maxParts = max(0, (int) ($options['max_parts'] ?? 0));
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;

        $stats = [
            'catalogs_scanned' => 0,
            'models_saved' => 0,
            'main_categories_saved' => 0,
            'subcategories_saved' => 0,
            'system_groups_saved' => 0,
            'system_groups_fetched' => 0,
            'items_saved' => 0,
            'items_skipped' => 0,
            'previews_saved' => 0,
            'translations_updated' => 0,
        ];
        $savedPartNumbers = collect();

        $catalogs = $allCatalogs
            ? $this->vehicleCatalogs()
            : collect([['externalReference' => $catalogExternalReference]]);

        if ($maxCatalogs > 0) {
            $catalogs = $catalogs->take($maxCatalogs);
        }

        foreach ($catalogs as $catalog) {
            $externalReference = trim((string) ($catalog['externalReference'] ?? ''));

            if ($externalReference === '') {
                continue;
            }

            $catalog = $this->catalogWithDefaults($catalog, $externalReference);
            $this->progress($progress, $verbose, "Catalog: {$catalog['name']}");

            $tree = $this->get("api/catalogs/{$externalReference}/categories");
            $categories = collect((array) ($tree['categories'] ?? $tree));
            $firstCategory = $categories->first();
            $modelMeta = $this->catalogWithDefaults((array) ($tree['catalog'] ?? $firstCategory['catalog'] ?? $catalog), $externalReference);
            $stats['catalogs_scanned']++;

            $modelCategory = null;
            if (! $dryRun) {
                $modelCategory = $this->saveCanonicalCategory(
                    sourceUrl: $this->canonicalModelCategoryUrl($modelMeta),
                    attributes: [
                        'source' => $this->source,
                        'parent_id' => null,
                        'depth' => 0,
                        'code' => null,
                        'name' => $modelMeta['name'],
                        'name_en' => $modelMeta['name'],
                        'name_ru' => null,
                        'name_ua' => null,
                        'model_label' => $modelMeta['name'],
                        'model_name' => $modelMeta['model_name'],
                        'year_from' => $modelMeta['year_from'],
                        'year_to' => $modelMeta['year_to'],
                        'preview_image_url' => $this->firstTreeImage($categories),
                        'sort_order' => $this->modelSortOrder($modelMeta['name']),
                        'children_scanned_at' => now(),
                    ]
                );
                $stats['models_saved']++;
                $stats['previews_saved'] += $modelCategory->preview_image_url ? 1 : 0;
            }

            $systemGroupsSeen = 0;

            foreach ($categories as $categoryIndex => $categoryPayload) {
                [$mainCode, $mainName] = $this->splitCodeTitle((string) ($categoryPayload['title'] ?? $categoryPayload['name'] ?? ''));
                $mainExternalReference = (string) ($categoryPayload['externalReference'] ?? $categoryPayload['id'] ?? $mainCode);

                $mainCategory = null;
                if (! $dryRun) {
                    $mainCategory = $this->saveCanonicalCategory(
                        sourceUrl: $this->canonicalMainCategoryUrl($modelMeta, $mainCode, $mainName),
                        attributes: [
                            'source' => $this->source,
                            'parent_id' => $modelCategory?->id,
                            'depth' => 1,
                            'code' => $mainCode,
                            'name' => $mainName,
                            'name_en' => $mainName,
                            'name_ru' => null,
                            'name_ua' => null,
                            'model_label' => $modelMeta['name'],
                            'model_name' => $modelMeta['model_name'],
                            'year_from' => $modelMeta['year_from'],
                            'year_to' => $modelMeta['year_to'],
                            'preview_image_url' => $this->absoluteResourceUrl($categoryPayload['image'] ?? null),
                            'sort_order' => (int) $categoryIndex,
                            'children_scanned_at' => now(),
                        ]
                    );
                    $stats['main_categories_saved']++;
                    $stats['previews_saved'] += $mainCategory->preview_image_url ? 1 : 0;
                }

                foreach ((array) ($categoryPayload['subCategories'] ?? $categoryPayload['subcategories'] ?? []) as $subcategoryIndex => $subcategoryPayload) {
                    [$subcategoryCode, $subcategoryName] = $this->splitCodeTitle((string) ($subcategoryPayload['title'] ?? $subcategoryPayload['name'] ?? ''));
                    $subcategoryExternalReference = (string) ($subcategoryPayload['externalReference'] ?? $subcategoryPayload['id'] ?? $subcategoryCode);

                    $subcategory = null;
                    if (! $dryRun) {
                        $subcategory = $this->saveCanonicalCategory(
                            sourceUrl: $this->canonicalSubcategoryUrl($modelMeta, $mainCode, $mainName, $subcategoryCode, $subcategoryName),
                            attributes: [
                                'source' => $this->source,
                                'parent_id' => $mainCategory?->id,
                                'depth' => 2,
                                'code' => $subcategoryCode,
                                'name' => $subcategoryName,
                                'name_en' => $subcategoryName,
                                'name_ru' => null,
                                'name_ua' => null,
                                'model_label' => $modelMeta['name'],
                                'model_name' => $modelMeta['model_name'],
                                'year_from' => $modelMeta['year_from'],
                                'year_to' => $modelMeta['year_to'],
                                'preview_image_url' => $this->firstSystemGroupImage((array) ($subcategoryPayload['systemGroups'] ?? $subcategoryPayload['systemgroups'] ?? [])),
                                'sort_order' => (int) $subcategoryIndex,
                                'children_scanned_at' => now(),
                            ]
                        );
                        $stats['subcategories_saved']++;
                        $stats['previews_saved'] += $subcategory->preview_image_url ? 1 : 0;
                    }

                    foreach ((array) ($subcategoryPayload['systemGroups'] ?? $subcategoryPayload['systemgroups'] ?? []) as $systemGroupIndex => $systemGroupPayload) {
                        if ($maxSystemGroups > 0 && $systemGroupsSeen >= $maxSystemGroups) {
                            break 3;
                        }

                        $systemGroupsSeen++;
                        $systemGroupExternalReference = (string) ($systemGroupPayload['externalReference'] ?? $systemGroupPayload['id'] ?? '');
                        $systemGroupName = trim((string) ($systemGroupPayload['title'] ?? $systemGroupPayload['name'] ?? $systemGroupExternalReference));

                        if ($systemGroupExternalReference === '') {
                            $stats['items_skipped']++;

                            continue;
                        }

                        $systemGroupCategory = null;
                        if (! $dryRun) {
                            $systemGroupCategory = $this->saveCanonicalCategory(
                                sourceUrl: $this->canonicalSystemGroupUrl($modelMeta, $mainCode, $mainName, $subcategoryCode, $subcategoryName, $systemGroupName),
                                attributes: [
                                    'source' => $this->source,
                                    'parent_id' => $subcategory?->id,
                                    'depth' => 3,
                                    'code' => null,
                                    'name' => $systemGroupName,
                                    'name_en' => $systemGroupName,
                                    'name_ru' => null,
                                    'name_ua' => null,
                                    'model_label' => $modelMeta['name'],
                                    'model_name' => $modelMeta['model_name'],
                                    'year_from' => $modelMeta['year_from'],
                                    'year_to' => $modelMeta['year_to'],
                                    'preview_image_url' => $this->firstPayloadImage($systemGroupPayload),
                                    'sort_order' => (int) $systemGroupIndex,
                                    'products_scanned_at' => now(),
                                ]
                            );
                            $stats['system_groups_saved']++;
                            $stats['previews_saved'] += $systemGroupCategory->preview_image_url ? 1 : 0;
                        }

                        $details = $this->get("api/catalogs/{$externalReference}/systemgroups/{$systemGroupExternalReference}");
                        $systemGroupImageUrls = $this->payloadImageUrls($details ?: $systemGroupPayload);
                        $stats['system_groups_fetched']++;

                        foreach ((array) ($details['parts'] ?? []) as $partPayload) {
                            if ($maxParts > 0 && $stats['items_saved'] >= $maxParts) {
                                break 4;
                            }

                            $saved = $this->savePart(
                                partPayload: (array) $partPayload,
                                modelMeta: $modelMeta,
                                catalogExternalReference: $externalReference,
                                categoryExternalReference: $mainExternalReference,
                                subcategoryExternalReference: $subcategoryExternalReference,
                                systemGroupExternalReference: $systemGroupExternalReference,
                                systemGroupCategory: $systemGroupCategory,
                                mainCode: $mainCode,
                                mainName: $mainName,
                                subcategoryCode: $subcategoryCode,
                                subcategoryName: $subcategoryName,
                                systemGroupName: $systemGroupName,
                                dryRun: $dryRun,
                                rawAttributesExtra: [
                                    'system_group_image_urls' => $systemGroupImageUrls,
                                ],
                            );

                            $stats[$saved ? 'items_saved' : 'items_skipped']++;
                            if ($saved && ! $dryRun) {
                                $savedPartNumbers->push($partPayload['partNumber'] ?? $partPayload['catalogPartNumber'] ?? null);
                            }
                        }

                        if ($sleepMs > 0) {
                            usleep($sleepMs * 1000);
                        }
                    }
                }
            }
        }

        return $stats;
    }

    public function syncExistingPartImagesAcrossMatchingItems(int $limit = 0): array
    {
        $sourceItems = PartCatalogItem::query()
            ->where('source', $this->source)
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->whereNotNull('part_number')
            ->where('raw_attributes', 'like', '%tesla-official/part-images%')
            ->orderBy('part_number')
            ->orderBy('id')
            ->get();

        $imagePayloads = [];

        foreach ($sourceItems as $item) {
            $partNumber = Str::upper(trim((string) $item->part_number));

            if ($partNumber === '' || isset($imagePayloads[$partNumber])) {
                continue;
            }

            $rawAttributes = PartCatalogRawAttributes::from($item);
            $partImageUrls = collect((array) data_get($rawAttributes, 'part_image_urls', []))
                ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
                ->values()
                ->all();

            if ($partImageUrls === []) {
                continue;
            }

            $imagePayloads[$partNumber] = [
                'part_image_urls' => $partImageUrls,
                'part_image_source_urls' => collect((array) data_get($rawAttributes, 'part_image_source_urls', []))
                    ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
                    ->values()
                    ->all(),
            ];
        }

        $stats = [
            'part_numbers_with_images' => count($imagePayloads),
            'part_numbers_seen' => 0,
            'items_updated' => 0,
        ];

        foreach ($imagePayloads as $partNumber => $payload) {
            if ($limit > 0 && $stats['part_numbers_seen'] >= $limit) {
                break;
            }

            $stats['part_numbers_seen']++;

            PartCatalogItem::query()
                ->where('source', $this->source)
                ->where('source_url', 'like', 'https://parts.tesla.com/%')
                ->where('part_number', $partNumber)
                ->where(function ($query): void {
                    $query
                        ->whereNull('raw_attributes')
                        ->orWhere('raw_attributes', 'not like', '%tesla-official/part-images%');
                })
                ->chunkById(200, function ($items) use ($partNumber, $payload, &$stats): void {
                    foreach ($items as $item) {
                        $this->attachOfficialPartImagesToItem(
                            $item,
                            $partNumber,
                            $payload['part_image_urls'],
                            $payload['part_image_source_urls']
                        );
                        $stats['items_updated']++;
                    }
                });
        }

        return $stats;
    }

    public function importPartSearchExactMatches(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 200));
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $countries = collect((array) ($options['countries'] ?? ['US', 'CA', 'MX', 'DE', 'NO', 'GB']))
            ->map(fn (mixed $country): string => Str::upper(trim((string) $country)))
            ->filter()
            ->unique()
            ->values();
        $competitorSources = collect((array) ($options['competitor_sources'] ?? [
            'tcarservice',
            'teslapartsukraine',
            'tsk',
            'stock-tesla',
            'teslahelp',
            'driveparts',
            'dkparts',
            'erazborka',
            'toprazborka',
            'teslawestparts',
            'teslacompany',
        ]))->filter()->values()->all();
        $requestedPartNumbers = collect((array) ($options['part_numbers'] ?? []))
            ->map(fn (mixed $partNumber): string => Str::upper(trim((string) $partNumber)))
            ->filter()
            ->unique()
            ->values();
        $checkedPath = storage_path('app/tesla-official-part-search-checked.json');
        $checkedPartNumbers = $requestedPartNumbers->isEmpty()
            ? collect(array_keys($this->loadPartSearchChecked($checkedPath)))
            : collect();

        $stats = [
            'candidate_part_numbers' => 0,
            'part_numbers_checked' => 0,
            'exact_part_numbers_found' => 0,
            'items_saved' => 0,
            'items_skipped_existing' => 0,
            'items_skipped_no_exact' => 0,
            'part_numbers_skipped_checked' => 0,
            'translations_updated' => 0,
        ];
        $savedPartNumbers = collect();

        $officialPartNumbers = PartCatalogItem::query()
            ->where('source', $this->source)
            ->whereNotNull('part_number')
            ->selectRaw('upper(trim(part_number)) as part_number')
            ->pluck('part_number')
            ->all();

        $candidates = $requestedPartNumbers->isNotEmpty()
            ? $requestedPartNumbers
            : PartCatalogItem::query()
                ->whereIn('source', $competitorSources)
                ->whereNotNull('part_number')
                ->where('part_number', 'regexp', '^[0-9]{7}-[A-Z0-9]{2}-[A-Z]$')
                ->whereNotIn(\DB::raw('upper(trim(part_number))'), $officialPartNumbers)
                ->selectRaw('upper(trim(part_number)) as part_number')
                ->distinct()
                ->orderBy('part_number')
                ->when($checkedPartNumbers->isNotEmpty(), fn ($query) => $query->whereNotIn(\DB::raw('upper(trim(part_number))'), $checkedPartNumbers->all()))
                ->when($limit > 0, fn ($query) => $query->limit($limit))
                ->pluck('part_number');

        $stats['candidate_part_numbers'] = $candidates->count();
        $stats['part_numbers_skipped_checked'] = $checkedPartNumbers->count();

        foreach ($candidates as $partNumber) {
            $stats['part_numbers_checked']++;
            $this->progress($progress, $verbose, "partSearch: {$partNumber}");

            $exactMatches = collect();
            foreach ($countries as $country) {
                $matches = collect($this->partSearch($partNumber, $country))
                    ->filter(fn (array $match): bool => Str::upper(trim((string) ($match['partNumber'] ?? ''))) === $partNumber)
                    ->map(function (array $match) use ($country): array {
                        $match['searchCountryCode'] = $country;

                        return $match;
                    });

                $exactMatches = $exactMatches->merge($matches);

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }

            $exactMatches = $exactMatches
                ->unique(fn (array $match): string => implode('|', [
                    $match['catalogExternalReference'] ?? '',
                    $match['systemGroupExternalReference'] ?? '',
                    $match['partNumber'] ?? '',
                ]))
                ->values();

            if ($exactMatches->isEmpty()) {
                $stats['items_skipped_no_exact']++;
                if (! $dryRun) {
                    $this->rememberPartSearchChecked($checkedPath, $partNumber, false, []);
                }

                continue;
            }

            $stats['exact_part_numbers_found']++;

            if ($this->officialPartNumberExists($partNumber)) {
                $stats['items_skipped_existing']++;
                if (! $dryRun) {
                    $this->rememberPartSearchChecked($checkedPath, $partNumber, true, $exactMatches->pluck('searchCountryCode')->unique()->values()->all());
                }

                continue;
            }

            if (! $dryRun) {
                $this->savePartSearchMatch(
                    $exactMatches->first(),
                    $exactMatches->pluck('searchCountryCode')->unique()->values()->all(),
                    $exactMatches->all(),
                );
            }

            $stats['items_saved']++;
            if (! $dryRun) {
                $savedPartNumbers->push($partNumber);
            }

            if (! $dryRun) {
                $this->rememberPartSearchChecked($checkedPath, $partNumber, true, $exactMatches->pluck('searchCountryCode')->unique()->values()->all());
            }
        }

        return $stats;
    }

    public function importBrowserSnapshot(array $snapshot, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $maxParts = max(0, (int) ($options['max_parts'] ?? 0));
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;
        $rawAttributesExtra = (array) ($options['raw_attributes_extra'] ?? []);
        $recommendationTypes = collect((array) ($options['recommendation_types'] ?? []))
            ->map(fn (mixed $type): string => Str::upper(trim((string) $type)))
            ->filter()
            ->values();
        $missingOnly = (bool) ($options['missing_only'] ?? false);
        $stats = [
            'catalogs_scanned' => 0,
            'models_saved' => 0,
            'main_categories_saved' => 0,
            'subcategories_saved' => 0,
            'system_groups_saved' => 0,
            'system_groups_fetched' => 0,
            'items_saved' => 0,
            'items_skipped' => 0,
            'previews_saved' => 0,
            'translations_updated' => 0,
        ];
        $savedPartNumbers = collect();

        foreach ((array) ($snapshot['catalogs'] ?? []) as $catalogSnapshot) {
            $catalog = (array) ($catalogSnapshot['catalog'] ?? []);
            $externalReference = trim((string) ($catalog['externalReference'] ?? $catalogSnapshot['catalogExternalReference'] ?? ''));

            if ($externalReference === '') {
                continue;
            }

            $rawCategories = (array) ($catalogSnapshot['categories'] ?? []);
            $categories = collect((array) ($rawCategories['responseObject'] ?? $rawCategories['categories'] ?? $rawCategories));
            $firstCategory = $categories->first();
            $tree = (array) ($catalogSnapshot['tree'] ?? []);
            $modelMeta = $this->catalogWithDefaults((array) ($tree['catalog'] ?? $firstCategory['catalog'] ?? $catalog), $externalReference);
            $this->progress($progress, $verbose, "Browser catalog: {$modelMeta['name']}");
            $stats['catalogs_scanned']++;

            $modelCategory = null;
            if (! $dryRun) {
                $modelCategory = $this->saveCanonicalCategory(
                    sourceUrl: $this->canonicalModelCategoryUrl($modelMeta),
                    attributes: [
                        'source' => $this->source,
                        'parent_id' => null,
                        'depth' => 0,
                        'code' => null,
                        'name' => $modelMeta['name'],
                        'name_en' => $modelMeta['name'],
                        'name_ru' => null,
                        'name_ua' => null,
                        'model_label' => $modelMeta['name'],
                        'model_name' => $modelMeta['model_name'],
                        'year_from' => $modelMeta['year_from'],
                        'year_to' => $modelMeta['year_to'],
                        'preview_image_url' => $this->firstTreeImage($categories),
                        'sort_order' => $this->modelSortOrder($modelMeta['name']),
                        'children_scanned_at' => now(),
                    ]
                );
                $stats['models_saved']++;
                $stats['previews_saved'] += $modelCategory->preview_image_url ? 1 : 0;
            }

            $detailsBySystemGroup = collect((array) ($catalogSnapshot['system_group_details'] ?? []))
                ->keyBy(fn (array $detail): string => (string) (
                    $detail['system_group_external_reference']
                    ?? $detail['externalReference']
                    ?? data_get($detail, 'details.externalReference')
                    ?? data_get($detail, 'details.responseObject.externalReference')
                    ?? ''
                ));

            foreach ($categories as $categoryIndex => $categoryPayload) {
                $categoryPayload = (array) $categoryPayload;
                [$mainCode, $mainName] = $this->splitCodeTitle((string) ($categoryPayload['title'] ?? $categoryPayload['name'] ?? ''));
                $mainExternalReference = (string) ($categoryPayload['externalReference'] ?? $categoryPayload['id'] ?? $mainCode);

                $mainCategory = null;
                if (! $dryRun) {
                    $mainCategory = $this->saveCanonicalCategory(
                        sourceUrl: $this->canonicalMainCategoryUrl($modelMeta, $mainCode, $mainName),
                        attributes: [
                            'source' => $this->source,
                            'parent_id' => $modelCategory?->id,
                            'depth' => 1,
                            'code' => $mainCode,
                            'name' => $mainName,
                            'name_en' => $mainName,
                            'model_label' => $modelMeta['name'],
                            'model_name' => $modelMeta['model_name'],
                            'year_from' => $modelMeta['year_from'],
                            'year_to' => $modelMeta['year_to'],
                            'preview_image_url' => $this->absoluteResourceUrl($categoryPayload['image'] ?? null),
                            'sort_order' => (int) $categoryIndex,
                            'children_scanned_at' => now(),
                        ]
                    );
                    $stats['main_categories_saved']++;
                    $stats['previews_saved'] += $mainCategory->preview_image_url ? 1 : 0;
                }

                foreach ((array) ($categoryPayload['subCategories'] ?? $categoryPayload['subcategories'] ?? []) as $subcategoryIndex => $subcategoryPayload) {
                    $subcategoryPayload = (array) $subcategoryPayload;
                    [$subcategoryCode, $subcategoryName] = $this->splitCodeTitle((string) ($subcategoryPayload['title'] ?? $subcategoryPayload['name'] ?? ''));
                    $subcategoryExternalReference = (string) ($subcategoryPayload['externalReference'] ?? $subcategoryPayload['id'] ?? $subcategoryCode);

                    $subcategory = null;
                    if (! $dryRun) {
                        $subcategory = $this->saveCanonicalCategory(
                            sourceUrl: $this->canonicalSubcategoryUrl($modelMeta, $mainCode, $mainName, $subcategoryCode, $subcategoryName),
                            attributes: [
                                'source' => $this->source,
                                'parent_id' => $mainCategory?->id,
                                'depth' => 2,
                                'code' => $subcategoryCode,
                                'name' => $subcategoryName,
                                'name_en' => $subcategoryName,
                                'model_label' => $modelMeta['name'],
                                'model_name' => $modelMeta['model_name'],
                                'year_from' => $modelMeta['year_from'],
                                'year_to' => $modelMeta['year_to'],
                                'preview_image_url' => $this->firstSystemGroupImage((array) ($subcategoryPayload['systemGroups'] ?? $subcategoryPayload['systemgroups'] ?? [])),
                                'sort_order' => (int) $subcategoryIndex,
                                'children_scanned_at' => now(),
                            ]
                        );
                        $stats['subcategories_saved']++;
                        $stats['previews_saved'] += $subcategory->preview_image_url ? 1 : 0;
                    }

                    foreach ((array) ($subcategoryPayload['systemGroups'] ?? $subcategoryPayload['systemgroups'] ?? []) as $systemGroupIndex => $systemGroupPayload) {
                        $systemGroupPayload = (array) $systemGroupPayload;
                        $systemGroupExternalReference = (string) ($systemGroupPayload['externalReference'] ?? $systemGroupPayload['id'] ?? '');

                        if ($systemGroupExternalReference === '' || ! $detailsBySystemGroup->has($systemGroupExternalReference)) {
                            continue;
                        }

                        $systemGroupName = trim((string) ($systemGroupPayload['title'] ?? $systemGroupPayload['name'] ?? $systemGroupExternalReference));
                        $systemGroupCategory = null;
                        if (! $dryRun) {
                            $systemGroupCategory = $this->saveCanonicalCategory(
                                sourceUrl: $this->canonicalSystemGroupUrl($modelMeta, $mainCode, $mainName, $subcategoryCode, $subcategoryName, $systemGroupName),
                                attributes: [
                                    'source' => $this->source,
                                    'parent_id' => $subcategory?->id,
                                    'depth' => 3,
                                    'name' => $systemGroupName,
                                    'name_en' => $systemGroupName,
                                    'model_label' => $modelMeta['name'],
                                    'model_name' => $modelMeta['model_name'],
                                    'year_from' => $modelMeta['year_from'],
                                    'year_to' => $modelMeta['year_to'],
                                    'preview_image_url' => $this->firstPayloadImage($systemGroupPayload),
                                    'sort_order' => (int) $systemGroupIndex,
                                    'products_scanned_at' => now(),
                                ]
                            );
                            $stats['system_groups_saved']++;
                            $stats['previews_saved'] += $systemGroupCategory->preview_image_url ? 1 : 0;
                        }

                        $details = (array) data_get($detailsBySystemGroup->get($systemGroupExternalReference), 'details', []);
                        $systemGroupImageUrls = $this->payloadImageUrls($details ?: $systemGroupPayload);
                        $stats['system_groups_fetched']++;

                        foreach ($this->partsFromSnapshotDetails($details) as $partPayload) {
                            if ($maxParts > 0 && $stats['items_saved'] >= $maxParts) {
                                break 4;
                            }

                            $partPayload = (array) $partPayload;
                            $recommendationType = Str::upper(trim((string) ($partPayload['recommendationType'] ?? '')));
                            if ($recommendationTypes->isNotEmpty() && ! $recommendationTypes->contains($recommendationType)) {
                                $stats['items_skipped']++;

                                continue;
                            }

                            if ($missingOnly) {
                                $partNumber = trim((string) ($partPayload['partNumber'] ?? $partPayload['catalogPartNumber'] ?? ''));
                                if ($partNumber !== '' && $this->officialPartNumberExists($partNumber)) {
                                    $stats['items_skipped']++;

                                    continue;
                                }
                            }

                            $saved = $this->savePart(
                                partPayload: $partPayload,
                                modelMeta: $modelMeta,
                                catalogExternalReference: $externalReference,
                                categoryExternalReference: $mainExternalReference,
                                subcategoryExternalReference: $subcategoryExternalReference,
                                systemGroupExternalReference: $systemGroupExternalReference,
                                systemGroupCategory: $systemGroupCategory,
                                mainCode: $mainCode,
                                mainName: $mainName,
                                subcategoryCode: $subcategoryCode,
                                subcategoryName: $subcategoryName,
                                systemGroupName: $systemGroupName,
                                dryRun: $dryRun,
                                rawAttributesExtra: array_filter([
                                    ...$rawAttributesExtra,
                                    'browser_imported_at' => now()->toIso8601String(),
                                    'system_group_image_urls' => $systemGroupImageUrls,
                                ], fn ($value) => $value !== null && $value !== '' && $value !== []),
                            );

                            $stats[$saved ? 'items_saved' : 'items_skipped']++;
                            if ($saved && ! $dryRun) {
                                $savedPartNumbers->push($partPayload['partNumber'] ?? $partPayload['catalogPartNumber'] ?? null);
                            }
                        }
                    }
                }
            }
        }

        return $stats;
    }

    protected function partsFromSnapshotDetails(array $details): Collection
    {
        $parts = collect();

        $visit = function (mixed $node) use (&$visit, $parts): void {
            if (! is_array($node)) {
                return;
            }

            if (array_is_list($node)) {
                foreach ($node as $child) {
                    $visit($child);
                }

                return;
            }

            foreach ((array) ($node['parts'] ?? []) as $part) {
                if (is_array($part)) {
                    $parts->push($part);
                }
            }

            foreach ($node as $key => $value) {
                if ($key === 'parts') {
                    continue;
                }

                if (is_array($value)) {
                    $visit($value);
                }
            }
        };

        $visit($details);

        return $parts;
    }

    public function refreshPreviewImages(array $options = []): array
    {
        $allCatalogs = (bool) ($options['all_catalogs'] ?? false);
        $catalogExternalReference = trim((string) ($options['catalog_external_reference'] ?? ''));
        $verbose = (bool) ($options['verbose'] ?? false);
        $progress = $options['progress'] ?? null;

        $stats = [
            'catalogs_scanned' => 0,
            'previews_saved' => 0,
            'categories_missing' => 0,
        ];

        $catalogs = $allCatalogs
            ? $this->vehicleCatalogs()
            : collect([['externalReference' => $catalogExternalReference]]);

        foreach ($catalogs as $catalog) {
            $externalReference = trim((string) ($catalog['externalReference'] ?? ''));

            if ($externalReference === '') {
                continue;
            }

            $this->progress($progress, $verbose, "Images: {$externalReference}");
            $tree = $this->get("api/catalogs/{$externalReference}/categories");
            $categories = collect((array) ($tree['categories'] ?? $tree));
            $stats['catalogs_scanned']++;

            $this->updatePreview($this->catalogUrl($externalReference), $this->firstTreeImage($categories), $stats);

            foreach ($categories as $categoryPayload) {
                $mainExternalReference = (string) ($categoryPayload['externalReference'] ?? $categoryPayload['id'] ?? '');
                $this->updatePreview($this->categoryUrl($externalReference, $mainExternalReference), $this->absoluteResourceUrl($categoryPayload['image'] ?? null), $stats);

                foreach ((array) ($categoryPayload['subCategories'] ?? $categoryPayload['subcategories'] ?? []) as $subcategoryPayload) {
                    $subcategoryExternalReference = (string) ($subcategoryPayload['externalReference'] ?? $subcategoryPayload['id'] ?? '');
                    $systemGroups = (array) ($subcategoryPayload['systemGroups'] ?? $subcategoryPayload['systemgroups'] ?? []);
                    $this->updatePreview($this->categoryUrl($externalReference, $subcategoryExternalReference), $this->firstSystemGroupImage($systemGroups), $stats);

                    foreach ($systemGroups as $systemGroupPayload) {
                        $systemGroupExternalReference = (string) ($systemGroupPayload['externalReference'] ?? $systemGroupPayload['id'] ?? '');
                        $this->updatePreview($this->systemGroupUrl($externalReference, $systemGroupExternalReference), $this->firstPayloadImage($systemGroupPayload), $stats);
                    }
                }
            }
        }

        return $stats;
    }

    public function importFindPartBrowserResult(array $result, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $downloadImages = (bool) ($options['download_images'] ?? true);
        $stats = [
            'rows_seen' => 0,
            'rows_skipped' => 0,
            'items_saved' => 0,
        ];

        foreach ((array) ($result['related_matches'] ?? []) as $row) {
            if (! is_array($row)) {
                $stats['rows_skipped']++;

                continue;
            }

            $stats['rows_seen']++;
            $partNumber = Str::upper(trim((string) ($row['part_number'] ?? $row['partNumber'] ?? '')));
            $requestedPartNumber = Str::upper(trim((string) ($result['part_number'] ?? '')));
            $catalogExternalReference = trim((string) ($row['catalogExternalReference'] ?? $row['catalog_external_reference'] ?? ''));
            $systemGroupExternalReference = trim((string) ($row['systemGroupExternalReference'] ?? $row['system_group_external_reference'] ?? ''));

            if ($partNumber === '') {
                $stats['rows_skipped']++;

                continue;
            }

            [$mainCode, $mainName] = $this->splitCodeTitle((string) ($row['category'] ?? ''));
            [$subcategoryCode, $subcategoryName] = $this->splitCodeTitle((string) ($row['subcategory'] ?? ''));
            $systemGroupName = trim((string) ($row['group'] ?? $systemGroupExternalReference));
            $categoryExternalReference = trim((string) ($row['categoryExternalReference'] ?? $row['category_external_reference'] ?? ''));
            $subcategoryExternalReference = trim((string) ($row['subcategoryExternalReference'] ?? $row['subcategory_external_reference'] ?? ''));
            $modelLabel = trim((string) ($row['model'] ?? ''));

            if ($catalogExternalReference === '') {
                $catalogExternalReference = $this->findPartSyntheticReference('catalog', [$modelLabel ?: 'Tesla']);
            }

            if ($systemGroupExternalReference === '') {
                $systemGroupExternalReference = $this->findPartSyntheticReference('system-group', [
                    $catalogExternalReference,
                    $mainName,
                    $subcategoryName,
                    $systemGroupName,
                ]);
            }

            if ($categoryExternalReference === '') {
                $categoryExternalReference = 'find-part-'.md5($catalogExternalReference.'|'.$mainName);
            }

            if ($subcategoryExternalReference === '') {
                $subcategoryExternalReference = 'find-part-'.md5($catalogExternalReference.'|'.$mainName.'|'.$subcategoryName);
            }

            $modelMeta = $this->catalogWithDefaults([
                'name' => $modelLabel,
            ], $catalogExternalReference);

            $systemGroupCategory = null;
            if (! $dryRun) {
                $modelCategory = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $this->catalogUrl($catalogExternalReference)],
                    [
                        'source' => $this->source,
                        'parent_id' => null,
                        'depth' => 0,
                        'code' => null,
                        'name' => $modelMeta['name'],
                        'name_en' => $modelMeta['name'],
                        'model_label' => $modelMeta['name'],
                        'model_name' => $modelMeta['model_name'],
                        'year_from' => $modelMeta['year_from'],
                        'year_to' => $modelMeta['year_to'],
                        'sort_order' => $this->modelSortOrder($modelMeta['name']),
                        'children_scanned_at' => now(),
                    ]
                );

                $mainCategory = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $this->categoryUrl($catalogExternalReference, $categoryExternalReference)],
                    [
                        'source' => $this->source,
                        'parent_id' => $modelCategory->id,
                        'depth' => 1,
                        'code' => $mainCode,
                        'name' => $mainName,
                        'name_en' => $mainName,
                        'model_label' => $modelMeta['name'],
                        'model_name' => $modelMeta['model_name'],
                        'year_from' => $modelMeta['year_from'],
                        'year_to' => $modelMeta['year_to'],
                        'children_scanned_at' => now(),
                    ]
                );

                $subcategory = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $this->categoryUrl($catalogExternalReference, $subcategoryExternalReference)],
                    [
                        'source' => $this->source,
                        'parent_id' => $mainCategory->id,
                        'depth' => 2,
                        'code' => $subcategoryCode,
                        'name' => $subcategoryName,
                        'name_en' => $subcategoryName,
                        'model_label' => $modelMeta['name'],
                        'model_name' => $modelMeta['model_name'],
                        'year_from' => $modelMeta['year_from'],
                        'year_to' => $modelMeta['year_to'],
                        'children_scanned_at' => now(),
                    ]
                );

                $systemGroupCategory = PartCatalogCategory::query()->updateOrCreate(
                    ['source_url' => $this->systemGroupUrl($catalogExternalReference, $systemGroupExternalReference)],
                    [
                        'source' => $this->source,
                        'parent_id' => $subcategory->id,
                        'depth' => 3,
                        'name' => $systemGroupName,
                        'name_en' => $systemGroupName,
                        'model_label' => $modelMeta['name'],
                        'model_name' => $modelMeta['model_name'],
                        'year_from' => $modelMeta['year_from'],
                        'year_to' => $modelMeta['year_to'],
                        'products_scanned_at' => now(),
                    ]
                );
            }

            $detail = is_array($row['detail'] ?? null) ? $row['detail'] : [];
            $partPayload = array_filter([
                ...$detail,
                'partNumber' => $partNumber,
                'title' => $detail['title'] ?? $detail['description'] ?? $row['description'] ?? $row['title'] ?? null,
                'description' => $detail['description'] ?? $row['description'] ?? $row['title'] ?? null,
                'price' => $detail['price'] ?? $row['price'] ?? null,
                'currencyCode' => $detail['currencyCode'] ?? $detail['currency'] ?? $row['currency'] ?? null,
                'partRestrictionMessage' => $detail['partRestrictionMessage'] ?? $detail['partRestriction'] ?? $row['part_restriction'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            $saved = $this->savePart(
                partPayload: $partPayload,
                modelMeta: $modelMeta,
                catalogExternalReference: $catalogExternalReference,
                categoryExternalReference: $categoryExternalReference,
                subcategoryExternalReference: $subcategoryExternalReference,
                systemGroupExternalReference: $systemGroupExternalReference,
                systemGroupCategory: $systemGroupCategory,
                mainCode: $mainCode,
                mainName: $mainName,
                subcategoryCode: $subcategoryCode,
                subcategoryName: $subcategoryName,
                systemGroupName: $systemGroupName,
                dryRun: $dryRun,
                rawAttributesExtra: array_filter([
                    'official_presence' => $requestedPartNumber !== '' && $this->compactPartNumber($requestedPartNumber) !== $this->compactPartNumber($partNumber)
                        ? 'find_part_related'
                        : null,
                    'find_part_requested_part_number' => $requestedPartNumber !== '' && $this->compactPartNumber($requestedPartNumber) !== $this->compactPartNumber($partNumber)
                        ? $requestedPartNumber
                        : null,
                    'find_part_result_imported_at' => now()->toIso8601String(),
                    'find_part_result_url' => $row['url'] ?? null,
                    'system_group_image_urls' => collect((array) ($row['system_group_image_urls'] ?? $row['systemGroupImageUrls'] ?? []))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'download_part_images' => $downloadImages,
                ], fn ($value) => $value !== null && $value !== ''),
            );

            $stats[$saved ? 'items_saved' : 'rows_skipped']++;
        }

        return $stats;
    }

    protected function savePart(
        array $partPayload,
        array $modelMeta,
        string $catalogExternalReference,
        string $categoryExternalReference,
        string $subcategoryExternalReference,
        string $systemGroupExternalReference,
        ?PartCatalogCategory $systemGroupCategory,
        ?string $mainCode,
        string $mainName,
        ?string $subcategoryCode,
        string $subcategoryName,
        string $systemGroupName,
        bool $dryRun,
        array $rawAttributesExtra = [],
    ): bool {
        $partNumber = trim((string) ($partPayload['partNumber'] ?? $partPayload['catalogPartNumber'] ?? ''));
        $teslaReturnedPartNumber = $partNumber;
        $recommendedPartNumber = trim((string) ($partPayload['recommendedPartNumber'] ?? ''));
        $incomingDonorVin = Str::upper(trim((string) ($rawAttributesExtra['donor_vin'] ?? '')));
        $isRecommendedDonorVinPart = $incomingDonorVin !== ''
            && strtoupper(trim((string) ($partPayload['recommendationType'] ?? ''))) === 'RECOMMENDED';

        if ($isRecommendedDonorVinPart
            && $recommendedPartNumber !== ''
            && $this->compactPartNumber($recommendedPartNumber) !== $this->compactPartNumber($partNumber)) {
            $partNumber = $recommendedPartNumber;
        }

        $name = trim((string) ($partPayload['title'] ?? $partPayload['name'] ?? $partPayload['description'] ?? ''));

        if ($partNumber === '' && $name === '') {
            return false;
        }

        if ($dryRun) {
            return true;
        }

        $notes = trim((string) ($partPayload['notes'] ?? $partPayload['note'] ?? ''));

        $priceAmount = $this->priceAmount($partPayload['price'] ?? null);
        $currency = strtoupper(trim((string) ($partPayload['currencyCode'] ?? $partPayload['currency'] ?? '')));
        $availability = trim((string) ($partPayload['partRestrictionMessage'] ?? $partPayload['partRestriction'] ?? ''));
        $canonicalSourceUrl = $this->partUrl($catalogExternalReference, $systemGroupExternalReference, $partNumber, $name);
        $sourceUrl = $canonicalSourceUrl;
        $downloadPartImages = (bool) ($rawAttributesExtra['download_part_images'] ?? false);
        unset($rawAttributesExtra['download_part_images']);
        $donorVin = $isRecommendedDonorVinPart ? $incomingDonorVin : '';
        if (! $isRecommendedDonorVinPart) {
            unset($rawAttributesExtra['donor_vin'], $rawAttributesExtra['donor_car_id'], $rawAttributesExtra['vin_catalog_imported_at']);
        }

        $partImagePayload = $downloadPartImages
            ? $this->officialPartImagePayload($partNumber)
            : ['part_image_urls' => [], 'part_image_source_urls' => []];
        $existing = $this->existingOfficialPart($partNumber, $canonicalSourceUrl, $sourceUrl);
        $existingRawAttributes = $this->rawAttributesArray($existing);
        $annotation = trim((string) ($partPayload['annotation'] ?? ''));
        $existingAnnotation = trim((string) data_get($existingRawAttributes, 'annotation'));
        $rawAnnotation = $annotation !== '' ? $annotation : $existingAnnotation;
        $numericAnnotation = ctype_digit($rawAnnotation) ? $rawAnnotation : '';
        $schemeNumber = $numericAnnotation !== ''
            ? (int) $numericAnnotation
            : ($existing?->scheme_number !== null ? (int) $existing->scheme_number : null);
        $displayName = $this->partNameWithAnnotation($name, $rawAnnotation);
        $partImageUrls = collect((array) ($existingRawAttributes['part_image_urls'] ?? []))
            ->merge($partImagePayload['part_image_urls'])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $partImageSourceUrls = collect((array) ($existingRawAttributes['part_image_source_urls'] ?? []))
            ->merge($partImagePayload['part_image_source_urls'])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $systemGroupImageUrls = collect((array) ($existingRawAttributes['system_group_image_urls'] ?? []))
            ->merge((array) ($rawAttributesExtra['system_group_image_urls'] ?? []))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $imageUrls = collect((array) ($existingRawAttributes['image_urls'] ?? []))
            ->merge($partImageUrls)
            ->merge($systemGroupImageUrls)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $officialPresence = data_get($existingRawAttributes, 'tesla_part_search_checked_at')
            ? data_get($existingRawAttributes, 'official_presence')
            : ($rawAttributesExtra['official_presence'] ?? null);
        unset($rawAttributesExtra['official_presence']);
        $catalogOccurrence = $this->catalogOccurrence(
            sourceUrl: $sourceUrl,
            canonicalSourceUrl: $canonicalSourceUrl,
            modelMeta: $modelMeta,
            systemGroupCategory: $systemGroupCategory,
            mainCode: $mainCode,
            mainName: $mainName,
            subcategoryCode: $subcategoryCode,
            subcategoryName: $subcategoryName,
            systemGroupName: $systemGroupName,
            catalogExternalReference: $catalogExternalReference,
            categoryExternalReference: $categoryExternalReference,
            subcategoryExternalReference: $subcategoryExternalReference,
            systemGroupExternalReference: $systemGroupExternalReference,
            donorVin: $donorVin,
            donorCarId: $rawAttributesExtra['donor_car_id'] ?? null,
        );
        $catalogOccurrences = $this->mergeCatalogOccurrences(
            (array) ($existingRawAttributes['official_catalog_occurrences'] ?? []),
            [$catalogOccurrence]
        );
        $sourceUrls = collect((array) ($existingRawAttributes['source_urls'] ?? []))
            ->push($existing?->source_url)
            ->push($sourceUrl)
            ->filter(fn (mixed $url): bool => is_string($url) && str_contains($url, '/find-part?'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $findPartRequestedPartNumbers = collect((array) ($existingRawAttributes['find_part_found_by_requested_part_numbers'] ?? []))
            ->push($rawAttributesExtra['find_part_requested_part_number'] ?? null)
            ->map(fn (mixed $partNumber): string => Str::upper(trim((string) $partNumber)))
            ->filter()
            ->reject(fn (string $requestedPartNumber): bool => $this->compactPartNumber($requestedPartNumber) === $this->compactPartNumber($partNumber))
            ->unique()
            ->values()
            ->all();
        $compatibilityText = $this->officialCompatibilityText($catalogOccurrences) ?: ($modelMeta['name'] ?? null);
        $modelLabel = $this->officialModelLabel($catalogOccurrences, (string) $modelMeta['name']);

        $item = PartCatalogItem::query()->updateOrCreate(
            ['source_url' => $existing?->source_url ?: $canonicalSourceUrl],
            [
                'source_url' => $canonicalSourceUrl,
                'part_catalog_category_id' => $this->preferredOfficialCategoryId($existing, $systemGroupCategory),
                'source' => $this->source,
                'part_number' => $partNumber ?: null,
                'name' => $displayName ?: $partNumber,
                'name_en' => $displayName ?: $partNumber,
                'name_ru' => $existing?->name_ru,
                'name_ua' => $existing?->name_ua,
                'scheme_number' => $schemeNumber,
                'price_amount' => $priceAmount,
                'currency' => $currency !== '' ? $currency : ($priceAmount !== null ? 'USD' : null),
                'model_label' => $modelLabel,
                'model_name' => $modelMeta['model_name'],
                'year_from' => $modelMeta['year_from'],
                'year_to' => $modelMeta['year_to'],
                'main_category_code' => $mainCode,
                'main_category_name' => $mainName,
                'subcategory_code' => $subcategoryCode,
                'subcategory_name' => $subcategoryName,
                'node_name' => $systemGroupName,
                'compatibility_text' => $compatibilityText,
                'notes_en' => $notes ?: null,
                'notes_ru' => $existing?->notes_ru,
                'notes_ua' => $existing?->notes_ua,
                'condition' => null,
                'quality' => null,
                'availability' => $availability ?: null,
                'raw_attributes' => array_filter([
                    'annotation' => $rawAnnotation ?: null,
                    'display_order' => $partPayload['displayOrder'] ?? null,
                    'quantity' => $partPayload['quantity'] ?? null,
                    'item_quantity' => $partPayload['itemQuantity'] ?? null,
                    'catalog_quantity' => $partPayload['catalogQuantity'] ?? null,
                    'minimum_order_quantity' => $partPayload['minimumOrderQuantity'] ?? null,
                    'order_multiple_quantity' => $partPayload['orderMultipleQuantity'] ?? null,
                    'part_type' => $partPayload['partType'] ?? null,
                    'part_restriction' => $partPayload['partRestriction'] ?? null,
                    'part_restriction_id' => $partPayload['partRestrictionID'] ?? null,
                    'pricing_notes' => $partPayload['pricingNotes'] ?? null,
                    'discount_percentage' => $partPayload['discountPercentage'] ?? null,
                    'has_supersession' => $partPayload['hasSuperSession'] ?? null,
                    'recommendation_type' => $partPayload['recommendationType'] ?? null,
                    'part_source' => $partPayload['partSource'] ?? null,
                    'recommended_part_source' => $partPayload['recommendedPartSource'] ?? null,
                    'recommended_part_number' => $partPayload['recommendedPartNumber'] ?? null,
                    'tesla_returned_part_number' => $teslaReturnedPartNumber !== $partNumber ? $teslaReturnedPartNumber : null,
                    'catalog_part_number' => $partPayload['catalogPartNumber'] ?? null,
                    'notes' => $notes ?: null,
                    'part_image_urls' => $partImageUrls,
                    'part_image_source_urls' => $partImageSourceUrls,
                    'image_urls' => $imageUrls,
                    'system_group_image_urls' => $systemGroupImageUrls,
                    'source_urls' => $sourceUrls !== [] ? $sourceUrls : [$canonicalSourceUrl],
                    'official_catalog_occurrences' => $catalogOccurrences,
                    'official_presence' => $officialPresence,
                    'catalog_external_reference' => $catalogExternalReference,
                    'category_external_reference' => $categoryExternalReference,
                    'subcategory_external_reference' => $subcategoryExternalReference,
                    'system_group_external_reference' => $systemGroupExternalReference,
                    'find_part_found_by_requested_part_numbers' => $findPartRequestedPartNumbers,
                    'extended_attributes' => $partPayload['extendedAttributes'] ?? null,
                ] + $rawAttributesExtra + Arr::only($existingRawAttributes, $this->preservedLocalizedRawAttributeKeys()), fn ($value) => $value !== null && $value !== ''),
                'source_updated_at' => now(),
            ]
        );

        $this->recordOfficialOccurrence(
            item: $item,
            category: $systemGroupCategory,
            occurrence: $catalogOccurrence,
            pageUrl: $this->systemGroupUrl($catalogExternalReference, $systemGroupExternalReference),
            productUrl: $canonicalSourceUrl,
            partName: $displayName ?: $partNumber,
            schemeNumber: $schemeNumber,
            annotation: $rawAnnotation,
            displayOrder: $partPayload['displayOrder'] ?? null,
            systemGroupImageUrls: $systemGroupImageUrls,
            quantity: $partPayload['quantity'] ?? null,
        );

        return true;
    }

    protected function preferredOfficialCategoryId(?PartCatalogItem $existing, ?PartCatalogCategory $systemGroupCategory): ?int
    {
        $existingCategory = $existing?->category;

        if ($existingCategory instanceof PartCatalogCategory
            && $existingCategory->source === $this->source
            && ! Str::contains((string) $existingCategory->source_url, 'find-part-')) {
            return (int) $existingCategory->id;
        }

        return $systemGroupCategory?->id;
    }

    protected function recordOfficialOccurrence(
        PartCatalogItem $item,
        ?PartCatalogCategory $category,
        array $occurrence,
        string $pageUrl,
        string $productUrl,
        string $partName,
        ?int $schemeNumber,
        string $annotation,
        mixed $displayOrder,
        array $systemGroupImageUrls,
        mixed $quantity,
    ): void {
        if (! $category instanceof PartCatalogCategory) {
            return;
        }

        $occurrenceKey = hash('sha256', collect([
            $this->source,
            $item->id,
            $category->id,
            $occurrence['catalog_external_reference'] ?? null,
            $occurrence['system_group_external_reference'] ?? null,
            $occurrence['donor_vin'] ?? null,
        ])->map(fn (mixed $value): string => trim((string) $value))->implode('|'));

        PartCatalogItemOccurrence::query()->updateOrCreate(
            ['occurrence_key' => $occurrenceKey],
            [
                'part_catalog_item_id' => $item->id,
                'part_catalog_category_id' => $category->id,
                'source' => $this->source,
                'page_url' => $pageUrl,
                'product_url' => $productUrl,
                'part_number' => $item->part_number,
                'name' => $partName,
                'scheme_number' => $schemeNumber,
                'quantity' => $quantity !== null && $quantity !== '' ? (string) $quantity : null,
                'raw_attributes' => array_filter([
                    ...$occurrence,
                    'annotation' => $annotation !== '' ? $annotation : null,
                    'display_order' => $displayOrder,
                    'system_group_image_urls' => $systemGroupImageUrls !== [] ? $systemGroupImageUrls : null,
                ], fn ($value) => $value !== null && $value !== ''),
            ]
        );
    }

    protected function partNameWithAnnotation(string $name, string $annotation): string
    {
        $name = trim($name);
        $annotation = trim($annotation);

        if ($name === '' || $annotation === '' || ! ctype_digit($annotation)) {
            return $name;
        }

        if (preg_match('/^'.preg_quote($annotation, '/').'(?:\s*\.|\s+)/u', $name) === 1) {
            return trim((string) preg_replace(
                '/^'.preg_quote($annotation, '/').'(?:\s*\.|\s+)/u',
                "{$annotation}. ",
                $name,
                1
            ));
        }

        return "{$annotation}. {$name}";
    }

    protected function existingOfficialPart(string $partNumber, string $canonicalSourceUrl, string $sourceUrl): ?PartCatalogItem
    {
        $compactPartNumber = $this->compactPartNumber($partNumber);

        $byUrl = PartCatalogItem::query()
            ->where('source', $this->source)
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->whereIn('source_url', array_values(array_unique([$canonicalSourceUrl, $sourceUrl])))
            ->orderByRaw('case when source_url = ? then 0 when source_url = ? then 1 else 2 end', [$canonicalSourceUrl, $sourceUrl])
            ->orderBy('id')
            ->first();

        if ($byUrl || $compactPartNumber === '') {
            return $byUrl;
        }

        return PartCatalogItem::query()
            ->where('source', $this->source)
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->where('part_number_compact', $compactPartNumber)
            ->orderBy('id')
            ->first();
    }

    protected function preservedLocalizedRawAttributeKeys(): array
    {
        return [
            'name_source_url',
            'name_source_site',
            'name_source_url_ru',
            'name_source_site_ru',
            'name_source_item_id_ru',
            'name_source_url_ua',
            'name_source_site_ua',
            'name_source_item_id_ua',
            'manual_name_locks',
            'official_part_match_status',
            'official_presence',
            'tesla_part_search_requested_part_number',
            'tesla_part_search_primary_part_number',
            'tesla_part_search_exact_part_numbers',
            'tesla_part_search_similar_part_numbers',
            'tesla_part_search_related_part_numbers',
            'tesla_part_search_results',
            'tesla_part_search_contexts',
            'tesla_part_search_url',
            'tesla_part_search_checked_at',
            'find_part_found_by_requested_part_numbers',
        ];
    }

    protected function catalogOccurrence(
        string $sourceUrl,
        string $canonicalSourceUrl,
        array $modelMeta,
        ?PartCatalogCategory $systemGroupCategory,
        string $mainCode,
        string $mainName,
        string $subcategoryCode,
        string $subcategoryName,
        string $systemGroupName,
        string $catalogExternalReference,
        string $categoryExternalReference,
        string $subcategoryExternalReference,
        string $systemGroupExternalReference,
        string $donorVin,
        mixed $donorCarId,
    ): array {
        return array_filter([
            'category_id' => $systemGroupCategory?->id,
            'model_label' => $modelMeta['name'] ?? null,
            'model_name' => $modelMeta['model_name'] ?? null,
            'main_category_code' => $mainCode ?: null,
            'main_category_name' => $mainName ?: null,
            'subcategory_code' => $subcategoryCode ?: null,
            'subcategory_name' => $subcategoryName ?: null,
            'node_name' => $systemGroupName ?: null,
            'catalog_external_reference' => $catalogExternalReference ?: null,
            'category_external_reference' => $categoryExternalReference ?: null,
            'subcategory_external_reference' => $subcategoryExternalReference ?: null,
            'system_group_external_reference' => $systemGroupExternalReference ?: null,
            'donor_vin' => $donorVin ?: null,
            'donor_car_id' => $donorCarId ?: null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function mergeCatalogOccurrences(array $current, array $incoming): array
    {
        return collect($current)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->merge($incoming)
            ->map(function (array $row): array {
                unset($row['source_url'], $row['canonical_source_url']);

                return $row;
            })
            ->map(fn (array $row): array => array_filter($row, fn ($value) => $value !== null && $value !== ''))
            ->unique(fn (array $row): string => implode('|', [
                (string) ($row['model_label'] ?? ''),
                (string) ($row['category_id'] ?? ''),
                (string) ($row['catalog_external_reference'] ?? ''),
                (string) ($row['system_group_external_reference'] ?? ''),
                (string) ($row['donor_vin'] ?? ''),
            ]))
            ->values()
            ->all();
    }

    protected function officialCompatibilityText(array $catalogOccurrences): string
    {
        return collect($catalogOccurrences)
            ->map(fn (array $row): string => trim((string) ($row['model_label'] ?? $row['model_name'] ?? '')))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values()
            ->implode(', ');
    }

    protected function officialModelLabel(array $catalogOccurrences, string $fallback): string
    {
        $models = collect($catalogOccurrences)
            ->pluck('model_label')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values();

        return $models->count() === 1 ? $models->first() : ($fallback ?: ($models->first() ?? ''));
    }

    protected function rawAttributesArray(?PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    protected function savePartSearchMatch(array $match, array $countriesFound, array $contexts = []): void
    {
        $catalogExternalReference = (string) ($match['catalogExternalReference'] ?? '');
        $categoryExternalReference = (string) ($match['categoryExternalReference'] ?? '');
        $subcategoryExternalReference = (string) ($match['subcategoryExternalReference'] ?? '');
        $systemGroupExternalReference = (string) ($match['systemGroupExternalReference'] ?? '');

        if ($catalogExternalReference === '' || $systemGroupExternalReference === '') {
            return;
        }

        $modelMeta = $this->catalogWithDefaults([
            'name' => $match['catalogName'] ?? null,
            'title' => $match['catalogName'] ?? null,
        ], $catalogExternalReference);
        [$mainCode, $mainName] = $this->splitCodeTitle((string) ($match['categoryTitle'] ?? ''));
        [$subcategoryCode, $subcategoryName] = $this->splitCodeTitle((string) ($match['subcategoryTitle'] ?? ''));
        $systemGroupName = trim((string) ($match['systemGroupTitle'] ?? ''));

        $modelCategory = $this->saveCanonicalCategory(
            sourceUrl: $this->canonicalModelCategoryUrl($modelMeta),
            attributes: [
                'source' => $this->source,
                'parent_id' => null,
                'depth' => 0,
                'code' => null,
                'name' => $modelMeta['name'],
                'name_en' => $modelMeta['name'],
                'model_label' => $modelMeta['name'],
                'model_name' => $modelMeta['model_name'],
                'year_from' => $modelMeta['year_from'],
                'year_to' => $modelMeta['year_to'],
                'sort_order' => $this->modelSortOrder($modelMeta['name']),
                'children_scanned_at' => now(),
            ],
        );

        $mainCategory = $this->saveCanonicalCategory(
            sourceUrl: $this->canonicalMainCategoryUrl($modelMeta, $mainCode, $mainName),
            attributes: [
                'source' => $this->source,
                'parent_id' => $modelCategory->id,
                'depth' => 1,
                'code' => $mainCode,
                'name' => $mainName,
                'name_en' => $mainName,
                'model_label' => $modelMeta['name'],
                'model_name' => $modelMeta['model_name'],
                'year_from' => $modelMeta['year_from'],
                'year_to' => $modelMeta['year_to'],
                'children_scanned_at' => now(),
            ],
        );

        $subcategory = $this->saveCanonicalCategory(
            sourceUrl: $this->canonicalSubcategoryUrl($modelMeta, $mainCode, $mainName, $subcategoryCode, $subcategoryName),
            attributes: [
                'source' => $this->source,
                'parent_id' => $mainCategory->id,
                'depth' => 2,
                'code' => $subcategoryCode,
                'name' => $subcategoryName,
                'name_en' => $subcategoryName,
                'model_label' => $modelMeta['name'],
                'model_name' => $modelMeta['model_name'],
                'year_from' => $modelMeta['year_from'],
                'year_to' => $modelMeta['year_to'],
                'children_scanned_at' => now(),
            ],
        );

        $systemGroupCategory = $this->saveCanonicalCategory(
            sourceUrl: $this->canonicalSystemGroupUrl($modelMeta, $mainCode, $mainName, $subcategoryCode, $subcategoryName, $systemGroupName),
            attributes: [
                'source' => $this->source,
                'parent_id' => $subcategory->id,
                'depth' => 3,
                'name' => $systemGroupName,
                'name_en' => $systemGroupName,
                'model_label' => $modelMeta['name'],
                'model_name' => $modelMeta['model_name'],
                'year_from' => $modelMeta['year_from'],
                'year_to' => $modelMeta['year_to'],
                'products_scanned_at' => now(),
            ],
        );

        $this->savePart(
            partPayload: [
                'partNumber' => $match['partNumber'] ?? null,
                'title' => $match['title'] ?? $match['description'] ?? null,
                'description' => $match['description'] ?? $match['title'] ?? null,
                'notes' => $match['notes'] ?? null,
            ],
            modelMeta: $modelMeta,
            catalogExternalReference: $catalogExternalReference,
            categoryExternalReference: $categoryExternalReference,
            subcategoryExternalReference: $subcategoryExternalReference,
            systemGroupExternalReference: $systemGroupExternalReference,
            systemGroupCategory: $systemGroupCategory,
            mainCode: $mainCode,
            mainName: $mainName,
            subcategoryCode: $subcategoryCode,
            subcategoryName: $subcategoryName,
            systemGroupName: $systemGroupName,
            dryRun: false,
            rawAttributesExtra: [
                'official_presence' => 'part_search_exact',
                'diagram_presence' => 'no',
                'part_search_countries_found' => $countriesFound,
                'part_search_contexts' => collect($contexts ?: [$match])
                    ->map(fn (array $context): array => $this->partSearchContext($context))
                    ->values()
                    ->all(),
                'part_search_score' => $match['score'] ?? null,
                'part_search_request_id' => $match['searchRequestId'] ?? null,
            ],
        );
    }

    protected function priceAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    protected function compactPartNumber(string $value): string
    {
        return Str::upper(preg_replace('/[^A-Z0-9]/i', '', $value) ?: '');
    }

    public function downloadPartImagesForSavedItems(array $options = []): array
    {
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $partNumbers = collect((array) ($options['part_numbers'] ?? []))
            ->map(fn (mixed $partNumber): string => Str::upper(trim((string) $partNumber)))
            ->filter()
            ->unique()
            ->values();

        $query = PartCatalogItem::query()
            ->where('source', $this->source)
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->whereNotNull('part_number')
            ->when($partNumbers->isNotEmpty(), fn ($query) => $query->whereIn('part_number', $partNumbers->all()))
            ->when($partNumbers->isEmpty(), fn ($query) => $query->where('raw_attributes', 'like', '%"donor_vin"%'))
            ->when($partNumbers->isEmpty(), fn ($query) => $query->where(function ($query): void {
                $query
                    ->whereNull('raw_attributes')
                    ->orWhere('raw_attributes', 'not like', '%"part_images_downloaded_at"%');
            }))
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $stats = [
            'items_seen' => 0,
            'items_updated' => 0,
            'images_downloaded' => 0,
            'items_without_images' => 0,
        ];
        $processedPartNumbers = [];

        $query->get()->each(function (PartCatalogItem $item) use (&$stats, &$processedPartNumbers): void {
            $stats['items_seen']++;
            $partNumber = Str::upper(trim((string) $item->part_number));

            if ($partNumber === '' || isset($processedPartNumbers[$partNumber])) {
                return;
            }

            $processedPartNumbers[$partNumber] = true;
            $partImagePayload = $this->officialPartImagePayload($partNumber);
            $partImageUrls = $partImagePayload['part_image_urls'];

            if ($partImageUrls === []) {
                $this->attachOfficialPartImagesToItem($item, $partNumber, []);
                $stats['items_without_images']++;

                return;
            }

            $updatedItems = $this->attachOfficialPartImagesToMatchingItems(
                $item,
                $partNumber,
                $partImageUrls,
                $partImagePayload['part_image_source_urls']
            );
            $stats['items_updated'] += $updatedItems;
            $stats['images_downloaded'] += count($partImageUrls);
        });

        return $stats;
    }

    protected function attachOfficialPartImagesToMatchingItems(
        PartCatalogItem $item,
        string $partNumber,
        array $partImageUrls,
        ?array $partImageSourceUrls = null
    ): int {
        $items = PartCatalogItem::query()
            ->where('source', $this->source)
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->where('part_number', $partNumber)
            ->get();

        $updated = 0;

        foreach ($items as $matchingItem) {
            $this->attachOfficialPartImagesToItem($matchingItem, $partNumber, $partImageUrls, $partImageSourceUrls);
            $updated++;
        }

        return max(1, $updated);
    }

    protected function attachOfficialPartImagesToItem(
        PartCatalogItem $item,
        string $partNumber,
        array $partImageUrls,
        ?array $partImageSourceUrls = null
    ): void {
        $rawAttributes = PartCatalogRawAttributes::from($item);

        $existingImageUrls = collect((array) data_get($rawAttributes, 'image_urls', []))
            ->reject(fn (string $url): bool => str_contains($url, '/resources/images/'))
            ->values();
        $rawAttributes['part_image_urls'] = $partImageUrls;
        $rawAttributes['part_image_source_urls'] = $partImageSourceUrls
            ?? ($partImageUrls === [] ? [] : $this->publicPartImageSourceUrls($partNumber));
        $rawAttributes['image_urls'] = $partImageUrls === []
            ? $existingImageUrls->values()->all()
            : $existingImageUrls
                ->merge($partImageUrls)
                ->merge((array) data_get($rawAttributes, 'system_group_image_urls', []))
                ->filter()
                ->unique()
                ->values()
                ->all();
        $rawAttributes['part_images_downloaded_at'] = now()->toIso8601String();

        $item->forceFill(['raw_attributes' => $rawAttributes])->save();

        $products = Product::query()
            ->where('source_part_catalog_item_id', $item->id)
            ->where('is_auto_generated', true);

        if ($partImageUrls === []) {
            $products
                ->where(function ($query): void {
                    $query
                        ->whereNull('main_image')
                        ->orWhere('main_image', '')
                        ->orWhere('main_image', 'like', 'http%');
                })
                ->update([
                    'main_image' => null,
                    'images_json' => null,
                ]);

            return;
        }

        $products->update([
            'main_image' => $partImageUrls[0],
            'images_json' => $partImageUrls,
        ]);
    }

    protected function officialPartImageUrls(string $partNumber): array
    {
        return $this->officialPartImagePayload($partNumber)['part_image_urls'];
    }

    protected function officialPartImagePayload(string $partNumber): array
    {
        $seenHashes = [];
        $partImageUrls = [];
        $partImageSourceUrls = [];

        foreach ($this->publicPartImageSourceUrls($partNumber) as $sourceUrl) {
            $path = $this->downloadOfficialPartImage($partNumber, $sourceUrl);

            if (! $path) {
                continue;
            }

            $keep = true;
            $hash = $this->storedPublicFileHash($path);

            if ($hash !== null) {
                $keep = ! isset($seenHashes[$hash]);
                $seenHashes[$hash] = true;
            }

            if (! $keep) {
                continue;
            }

            $partImageUrls[] = $path;
            $partImageSourceUrls[] = $sourceUrl;
        }

        return [
            'part_image_urls' => $partImageUrls,
            'part_image_source_urls' => $partImageSourceUrls,
        ];
    }

    protected function storedPublicFileHash(string $path): ?string
    {
        $fullPath = Storage::disk('public')->path($path);

        if (! is_file($fullPath)) {
            return null;
        }

        $hash = hash_file('sha256', $fullPath);

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    protected function publicPartImageSourceUrls(string $partNumber, int $maxImages = 5): array
    {
        $partNumber = Str::upper(trim($partNumber));

        if ($partNumber === '' || ! preg_match('/^\d{7}/', $partNumber)) {
            return [];
        }

        $folder = substr($partNumber, 0, 4);

        return collect(range(1, $maxImages))
            ->map(fn (int $index): string => "https://epc.tesla.com/resources/partimages/public/{$folder}/{$partNumber}/{$partNumber}_{$index}.jpeg")
            ->all();
    }

    protected function downloadOfficialPartImage(string $partNumber, string $url): ?string
    {
        $path = 'tesla-official/part-images/'.$this->compactPartNumber($partNumber).'/'.basename(parse_url($url, PHP_URL_PATH) ?: '');

        if ($path !== '' && Storage::disk('public')->exists($path)) {
            return $path;
        }

        try {
            $response = $this->http
                ->timeout(5)
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->ok() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            return null;
        }

        Storage::disk('public')->put($path, $response->body());

        return $path;
    }

    protected function vehicleCatalogs(): Collection
    {
        return collect((array) $this->get('api/catalogs?countryCode=US'))
            ->filter(fn (array $catalog): bool => (int) ($catalog['catalogModelTypeId'] ?? 0) === 1)
            ->reject(fn (array $catalog): bool => Str::lower((string) ($catalog['name'] ?? '')) === 'roadster')
            ->values();
    }

    protected function partSearch(string $partNumber, string $countryCode): array
    {
        $response = $this->get('api/catalogs/partSearch?'.http_build_query([
            'Term' => $partNumber,
            'CatalogExternalReference' => '',
            'SessionID' => (string) Str::uuid(),
            'CountryCode' => $countryCode,
        ]));

        return (array) ($response['parts'] ?? []);
    }

    protected function officialPartExists(string $partNumber, string $catalogExternalReference, string $systemGroupExternalReference): bool
    {
        return PartCatalogItem::query()
            ->where('source', $this->source)
            ->where('part_number', $partNumber)
            ->where('source_url', $this->partUrl($catalogExternalReference, $systemGroupExternalReference, $partNumber, ''))
            ->exists();
    }

    protected function officialPartNumberExists(string $partNumber): bool
    {
        $compactPartNumber = $this->compactPartNumber($partNumber);

        return PartCatalogItem::query()
            ->where('source', $this->source)
            ->when(
                $compactPartNumber !== '',
                fn ($query) => $query->where('part_number_compact', $compactPartNumber),
                fn ($query) => $query->where('part_number', $partNumber),
            )
            ->exists();
    }

    protected function partSearchContext(array $match): array
    {
        $catalogExternalReference = (string) ($match['catalogExternalReference'] ?? '');
        $systemGroupExternalReference = (string) ($match['systemGroupExternalReference'] ?? '');
        $partNumber = (string) ($match['partNumber'] ?? '');

        return array_filter([
            'country' => $match['searchCountryCode'] ?? null,
            'catalog_external_reference' => $catalogExternalReference ?: null,
            'catalog_name' => $match['catalogName'] ?? null,
            'category_external_reference' => $match['categoryExternalReference'] ?? null,
            'category_title' => $match['categoryTitle'] ?? null,
            'subcategory_external_reference' => $match['subcategoryExternalReference'] ?? null,
            'subcategory_title' => $match['subcategoryTitle'] ?? null,
            'system_group_external_reference' => $systemGroupExternalReference ?: null,
            'system_group_title' => $match['systemGroupTitle'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function loadPartSearchChecked(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true);

        return is_array($payload) ? $payload : [];
    }

    protected function rememberPartSearchChecked(string $path, string $partNumber, bool $exactFound, array $countriesFound): void
    {
        $checked = $this->loadPartSearchChecked($path);
        $checked[$partNumber] = [
            'exact_found' => $exactFound,
            'countries_found' => array_values(array_unique($countriesFound)),
            'checked_at' => now()->toIso8601String(),
        ];

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode($checked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function get(string $path): array
    {
        $json = $this->http
            ->baseUrl('https://epcapi.tesla.com')
            ->acceptJson()
            ->withHeaders([
                'Accept-Language' => 'en-US',
                'Authorization' => 'Bearer 123',
                'X-Correlation-ID' => (string) Str::uuid(),
            ])
            ->timeout(30)
            ->retry(2, 500)
            ->get($path)
            ->throw()
            ->json();

        if (is_array($json) && array_key_exists('responseObject', $json)) {
            return (array) $json['responseObject'];
        }

        return (array) $json;
    }

    protected function catalogWithDefaults(array $catalog, string $externalReference): array
    {
        $title = trim((string) ($catalog['title'] ?? $catalog['name'] ?? ''));
        $modelTitle = trim((string) ($catalog['catalogModelTitle'] ?? $catalog['modelTitle'] ?? 'Tesla'));
        $name = $this->canonicalModelLabel($title !== '' ? $title : $externalReference);

        return [
            'externalReference' => $externalReference,
            'name' => $name,
            'model_name' => $modelTitle ?: $this->modelNameFromTitle($name),
            'year_from' => $this->yearFromDate(Arr::get($catalog, 'startDate')) ?? $this->yearFromText($name),
            'year_to' => $this->yearFromDate(Arr::get($catalog, 'endDate')) ?? $this->yearToText($name),
        ];
    }

    protected function splitCodeTitle(string $title): array
    {
        $title = trim($title);

        if (preg_match('/^([0-9A-Z.]+)\s*-\s*(.+)$/i', $title, $matches) === 1) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return [null, $title];
    }

    protected function findPartSyntheticReference(string $type, array $parts): string
    {
        $key = collect($parts)
            ->map(fn (mixed $part): string => trim((string) $part))
            ->filter()
            ->implode('|');

        return 'find-part-'.$type.'-'.md5($key !== '' ? $key : $type);
    }

    protected function modelNameFromTitle(string $title): string
    {
        if (preg_match('/Model\s+[S3XY]/i', $title, $matches) === 1) {
            return $matches[0];
        }

        return Str::before($title, ' ');
    }

    protected function yearFromDate(mixed $date): ?int
    {
        if (! is_string($date) || $date === '') {
            return null;
        }

        return preg_match('/^(\d{4})/', $date, $matches) === 1 ? (int) $matches[1] : null;
    }

    protected function yearFromText(string $text): ?int
    {
        return preg_match('/\b(20\d{2}|19\d{2})\b/', $text, $matches) === 1 ? (int) $matches[1] : null;
    }

    protected function yearToText(string $text): ?int
    {
        preg_match_all('/\b(20\d{2}|19\d{2})\b/', $text, $matches);

        return isset($matches[1][1]) ? (int) $matches[1][1] : null;
    }

    protected function modelSortOrder(string $label): int
    {
        $labels = [
            'Model S 02.2012-03.2016',
            'Model S2 04.2016-01.2021',
            'Model S Palladium 02.2021-05.2025',
            'Model S 06.2025-',
            'Model X 09.2015-02.2021',
            'Model X Palladium 03.2021-05.2025',
            'Model X 06.2025-',
            'Model 3 06.2017 - 12.2023',
            'Model 3 Highland 01.2024 -',
            'Model Y 01.2020 - 01.2025',
            'Model Y Juniper 02.2025 -',
        ];

        $index = array_search($label, $labels, true);

        return $index === false ? 999 : $index + 1;
    }

    protected function canonicalModelLabel(string $label): string
    {
        return match ($label) {
            'Model S Feb 2012 - Mar 2016' => 'Model S 02.2012-03.2016',
            'Model S Apr 2016 - Jan 2021' => 'Model S2 04.2016-01.2021',
            'Model S Feb 2021 - May 2025' => 'Model S Palladium 02.2021-05.2025',
            'Model S June 2025' => 'Model S 06.2025-',
            'Model X Sep 2015 - Feb 2021' => 'Model X 09.2015-02.2021',
            'Model X Mar 2021 - May 2025' => 'Model X Palladium 03.2021-05.2025',
            'Model X June 2025' => 'Model X 06.2025-',
            'Model 3 Jun 2017 - Dec 2023' => 'Model 3 06.2017 - 12.2023',
            'Model 3 Jan 2024' => 'Model 3 Highland 01.2024 -',
            'Model Y Jan 2020 - Jan 2025' => 'Model Y 01.2020 - 01.2025',
            'Model Y Feb 2025' => 'Model Y Juniper 02.2025 -',
            default => $label,
        };
    }

    protected function catalogUrl(string $catalogExternalReference): string
    {
        return "https://parts.tesla.com/en-US/catalogs?catalogExternalReference={$catalogExternalReference}";
    }

    protected function saveCanonicalCategory(string $sourceUrl, array $attributes): PartCatalogCategory
    {
        $category = PartCatalogCategory::query()->firstOrNew(['source_url' => $sourceUrl]);
        $previewImageUrl = $attributes['preview_image_url'] ?? null;

        if ($category->exists && trim((string) $category->preview_image_url) !== '') {
            unset($attributes['preview_image_url']);
        } elseif ($previewImageUrl === null || $previewImageUrl === '') {
            unset($attributes['preview_image_url']);
        }

        $category->fill($attributes);
        $category->source_url = $sourceUrl;
        $category->save();

        return $category;
    }

    protected function canonicalModelCategoryUrl(array $modelMeta): string
    {
        return 'tesla-official://catalog/'.Str::slug((string) ($modelMeta['name'] ?? 'tesla'));
    }

    protected function canonicalMainCategoryUrl(array $modelMeta, ?string $mainCode, string $mainName): string
    {
        return $this->canonicalModelCategoryUrl($modelMeta)
            .'/category/'.$this->canonicalCategorySegment($mainCode, $mainName);
    }

    protected function canonicalSubcategoryUrl(array $modelMeta, ?string $mainCode, string $mainName, ?string $subcategoryCode, string $subcategoryName): string
    {
        return $this->canonicalMainCategoryUrl($modelMeta, $mainCode, $mainName)
            .'/subcategory/'.$this->canonicalCategorySegment($subcategoryCode, $subcategoryName);
    }

    protected function canonicalSystemGroupUrl(
        array $modelMeta,
        ?string $mainCode,
        string $mainName,
        ?string $subcategoryCode,
        string $subcategoryName,
        string $systemGroupName
    ): string {
        return $this->canonicalSubcategoryUrl($modelMeta, $mainCode, $mainName, $subcategoryCode, $subcategoryName)
            .'/system-group/'.$this->canonicalCategorySegment(null, $systemGroupName);
    }

    protected function canonicalCategorySegment(?string $code, string $name): string
    {
        $label = trim(collect([$code, $name])->filter()->implode(' '));

        return Str::slug($label !== '' ? $label : 'category');
    }

    protected function categoryUrl(string $catalogExternalReference, string $categoryExternalReference): string
    {
        return $this->catalogUrl($catalogExternalReference).'&categoryExternalReference='.rawurlencode($categoryExternalReference);
    }

    protected function systemGroupUrl(string $catalogExternalReference, string $systemGroupExternalReference): string
    {
        return $this->catalogUrl($catalogExternalReference).'&systemGroupExternalReference='.rawurlencode($systemGroupExternalReference);
    }

    protected function partUrl(string $catalogExternalReference, string $systemGroupExternalReference, string $partNumber, string $name): string
    {
        $reference = $partNumber !== '' ? $partNumber : Str::slug($name);

        return 'https://parts.tesla.com/en-US/find-part?searchTerm='.rawurlencode($reference);
    }

    protected function updatePreview(string $sourceUrl, ?string $imageUrl, array &$stats): void
    {
        if ($sourceUrl === '' || $imageUrl === null) {
            return;
        }

        $updated = PartCatalogCategory::query()
            ->where('source', $this->source)
            ->where('source_url', $sourceUrl)
            ->update(['preview_image_url' => $imageUrl]);

        if ($updated > 0) {
            $stats['previews_saved'] += $updated;
        } else {
            $stats['categories_missing']++;
        }
    }

    protected function firstTreeImage(Collection $categories): ?string
    {
        foreach ($categories as $category) {
            $image = $this->absoluteResourceUrl($category['image'] ?? null);

            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    protected function firstSystemGroupImage(array $systemGroups): ?string
    {
        foreach ($systemGroups as $systemGroup) {
            $image = $this->firstPayloadImage((array) $systemGroup);

            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    protected function firstPayloadImage(array $payload): ?string
    {
        return collect($this->payloadImageUrls($payload))->first();
    }

    protected function payloadImageUrls(array $payload): array
    {
        $images = $payload['partImageURLs'] ?? $payload['partImageUrls'] ?? $payload['images'] ?? $payload['systemGroupImages'] ?? null;

        if (is_string($images)) {
            $decoded = json_decode(trim($images), true);
            $images = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($images)) {
            return [];
        }

        return collect($images)
            ->map(function ($item): ?array {
                if (is_string($item) && trim($item) !== '') {
                    return ['url' => $item];
                }

                return is_array($item) ? $item : null;
            })
            ->filter(fn (?array $item): bool => is_array($item) && trim((string) ($item['ImageURL'] ?? $item['imageURL'] ?? $item['imageUrl'] ?? $item['url'] ?? '')) !== '')
            ->sortBy(fn (array $item): int => str_contains(Str::lower((string) ($item['Mimetype'] ?? $item['mimeType'] ?? '')), 'png') ? 0 : 1)
            ->map(fn (array $item): ?string => $this->absoluteResourceUrl((string) ($item['ImageURL'] ?? $item['imageURL'] ?? $item['imageUrl'] ?? $item['url'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function absoluteResourceUrl(mixed $value): ?string
    {
        $url = trim((string) $value);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return 'https://epc.tesla.com/'.ltrim($url, '/');
    }

    protected function progress(?callable $progress, bool $verbose, string $message): void
    {
        if ($verbose && $progress !== null) {
            $progress($message);
        }
    }
}
