<?php

namespace App\Services;

use App\Support\PartCatalogLanguageMarkers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PartCatalogFilterService
{
    private static array $blankNameColumns = [];

    public function catalogImageFilterCounts(Builder $query): array
    {
        $withImagesQuery = clone $query;
        $this->applyCatalogImageFilter($withImagesQuery, 'with');

        $withoutImagesQuery = clone $query;
        $this->applyCatalogImageFilter($withoutImagesQuery, 'without');

        return [
            'total' => (clone $query)->count(),
            'with' => $withImagesQuery->count(),
            'without' => $withoutImagesQuery->count(),
        ];
    }

    public function catalogNameFilterCounts(Builder $query): array
    {
        $conflictQuery = clone $query;
        $this->applyCompetitorNameFilter($conflictQuery, 'conflict');

        $missingRuQuery = clone $query;
        $this->applyCompetitorNameFilter($missingRuQuery, 'missing_ru');

        $missingUaQuery = clone $query;
        $this->applyCompetitorNameFilter($missingUaQuery, 'missing_ua');

        return [
            'conflict' => $conflictQuery->count(),
            'missing_ru' => $missingRuQuery->count(),
            'missing_ua' => $missingUaQuery->count(),
        ];
    }

    public function applyCatalogImageFilter(Builder $query, string $filter): Builder
    {
        if ($filter === '') {
            return $query;
        }

        $driver = DB::connection()->getDriverName();
        [$hasImageSqlParts, $bindings] = $this->catalogItemHasImageSqlParts($driver);
        $missingImageSql = $this->catalogItemMissingImageSql($driver);

        return $query->where(function (Builder $builder) use ($bindings, $filter, $hasImageSqlParts, $missingImageSql): void {
            if ($filter === 'with') {
                $builder->whereRaw('not ('.$missingImageSql.')');

                $builder->where(function (Builder $builder) use ($bindings, $hasImageSqlParts): void {
                    foreach ($hasImageSqlParts as $index => $hasImageSql) {
                        $index === 0
                            ? $builder->whereRaw($hasImageSql, $bindings)
                            : $builder->orWhereRaw($hasImageSql, $bindings);
                    }
                });

                return;
            }

            $builder->whereRaw($missingImageSql)
                ->orWhere(function (Builder $builder) use ($bindings, $hasImageSqlParts): void {
                    foreach ($hasImageSqlParts as $hasImageSql) {
                        $builder->whereRaw('not ('.$hasImageSql.')', $bindings);
                    }
                });
        });
    }

    public function applyCompetitorNameFilter(Builder $query, string $filter): Builder
    {
        if ($filter === '') {
            return $query;
        }

        if ($filter === 'missing_ru') {
            return $this->whereBlank($query, 'name_ru');
        }

        if ($filter === 'missing_ua') {
            return $this->whereBlank($query, 'name_ua');
        }

        if ($filter === 'conflict') {
            $driver = DB::connection()->getDriverName();
            [$ruConflictSql, $uaConflictSql] = $this->localizedNameConflictSqlParts($driver);

            return $query->where(function (Builder $builder) use ($ruConflictSql, $uaConflictSql): void {
                $builder
                    ->whereRaw($ruConflictSql)
                    ->orWhereRaw($uaConflictSql);
            });
        }

        return $query;
    }

    public function applyTeslaCheckFilter(Builder $query, string $filter): Builder
    {
        if ($filter === '') {
            return $query;
        }

        $driver = DB::connection()->getDriverName();
        $checkedSql = match ($driver) {
            'pgsql' => "nullif(trim(coalesce(raw_attributes::jsonb ->> 'tesla_part_search_checked_at', '')), '') is not null",
            'sqlite' => "nullif(trim(coalesce(json_extract(raw_attributes, '$.tesla_part_search_checked_at'), '')), '') is not null",
            default => "nullif(trim(coalesce(json_unquote(json_extract(if(json_valid(`part_catalog_items`.`raw_attributes`), `part_catalog_items`.`raw_attributes`, json_object()), '$.tesla_part_search_checked_at')), '')), '') is not null",
        };

        $statusSql = match ($driver) {
            'pgsql' => "raw_attributes::jsonb ->> 'official_part_match_status'",
            'sqlite' => "json_extract(raw_attributes, '$.official_part_match_status')",
            default => "json_unquote(json_extract(if(json_valid(`part_catalog_items`.`raw_attributes`), `part_catalog_items`.`raw_attributes`, json_object()), '$.official_part_match_status'))",
        };
        $savedOfficialCatalogDataSql = $this->savedOfficialCatalogDataSql($driver);

        if ($filter === 'checked') {
            return $query
                ->whereRaw($checkedSql)
                ->where(function (Builder $builder) use ($savedOfficialCatalogDataSql, $statusSql): void {
                    $builder
                        ->whereRaw('coalesce('.$statusSql.", '') <> ?", ['api_error'])
                        ->whereRaw('coalesce('.$statusSql.", '') <> ?", ['auth_required'])
                        ->whereRaw('coalesce('.$statusSql.", '') <> ?", ['security_blocked'])
                        ->orWhereRaw($savedOfficialCatalogDataSql);
                });
        }

        if ($filter === 'unchecked') {
            return $query->whereRaw('not ('.$checkedSql.')');
        }

        if ($filter === 'api_error') {
            return $query
                ->where(function (Builder $builder) use ($statusSql): void {
                    $builder
                        ->whereRaw($statusSql.' = ?', ['api_error'])
                        ->orWhereRaw($statusSql.' = ?', ['auth_required'])
                        ->orWhereRaw($statusSql.' = ?', ['security_blocked']);
                })
                ->whereRaw('not ('.$savedOfficialCatalogDataSql.')');
        }

        return $query->whereRaw($statusSql.' = ?', [$filter]);
    }

    public function teslaCheckFilterCounts(Builder $query): array
    {
        $counts = [
            'total' => (clone $query)->count(),
        ];

        foreach (['checked', 'unchecked', 'exact', 'similar', 'not_found', 'api_error'] as $filter) {
            $filterQuery = clone $query;
            $this->applyTeslaCheckFilter($filterQuery, $filter);
            $counts[$filter] = $filterQuery->count();
        }

        return $counts;
    }

    public function applyTeslaVisualFilter(Builder $query, string $filter): Builder
    {
        if ($filter === '') {
            return $query;
        }

        $driver = DB::connection()->getDriverName();
        $partPhotoSql = $this->teslaPartPhotoSql($driver);
        $schemeSql = $this->teslaSchemeSql($driver);

        return match ($filter) {
            'part_photo' => $query->whereRaw($partPhotoSql),
            'scheme' => $query->whereRaw($schemeSql),
            'part_photo_and_scheme' => $query
                ->whereRaw($partPhotoSql)
                ->whereRaw($schemeSql),
            default => $query,
        };
    }

    private function savedOfficialCatalogDataSql(string $driver): string
    {
        if ($driver === 'pgsql') {
            return "(
                jsonb_array_length(coalesce(raw_attributes::jsonb -> 'official_catalog_occurrences', '[]'::jsonb)) > 0
                or nullif(trim(coalesce(raw_attributes::jsonb ->> 'catalog_external_reference', '')), '') is not null
                or nullif(trim(coalesce(raw_attributes::jsonb ->> 'category_external_reference', '')), '') is not null
                or nullif(trim(coalesce(raw_attributes::jsonb ->> 'subcategory_external_reference', '')), '') is not null
                or nullif(trim(coalesce(raw_attributes::jsonb ->> 'system_group_external_reference', '')), '') is not null
            )";
        }

        if ($driver === 'sqlite') {
            return "(
                json_array_length(coalesce(json_extract(raw_attributes, '$.official_catalog_occurrences'), '[]')) > 0
                or nullif(trim(coalesce(json_extract(raw_attributes, '$.catalog_external_reference'), '')), '') is not null
                or nullif(trim(coalesce(json_extract(raw_attributes, '$.category_external_reference'), '')), '') is not null
                or nullif(trim(coalesce(json_extract(raw_attributes, '$.subcategory_external_reference'), '')), '') is not null
                or nullif(trim(coalesce(json_extract(raw_attributes, '$.system_group_external_reference'), '')), '') is not null
            )";
        }

        $json = 'if(json_valid(`part_catalog_items`.`raw_attributes`), `part_catalog_items`.`raw_attributes`, json_object())';

        return "(
            json_length(coalesce(json_extract({$json}, '$.official_catalog_occurrences'), json_array())) > 0
            or nullif(trim(coalesce(json_unquote(json_extract({$json}, '$.catalog_external_reference')), '')), '') is not null
            or nullif(trim(coalesce(json_unquote(json_extract({$json}, '$.category_external_reference')), '')), '') is not null
            or nullif(trim(coalesce(json_unquote(json_extract({$json}, '$.subcategory_external_reference')), '')), '') is not null
            or nullif(trim(coalesce(json_unquote(json_extract({$json}, '$.system_group_external_reference')), '')), '') is not null
        )";
    }

    private function whereBlank(Builder $query, string $column): Builder
    {
        $blankColumn = match ($column) {
            'name_ru' => 'name_ru_blank',
            'name_ua' => 'name_ua_blank',
            default => null,
        };

        if ($blankColumn !== null
            && DB::connection()->getDriverName() === 'mysql'
            && $this->hasBlankNameColumn($blankColumn)) {
            return $query->where($blankColumn, 1);
        }

        return $query->where(fn (Builder $builder) => $builder
            ->whereNull($column)
            ->orWhere($column, ''));
    }

    private function localizedNameConflictSqlParts(string $driver): array
    {
        $ruLockColumnSql = $this->manualNameLockColumnSql($driver, 'name_ru_manually_locked_at');
        $uaLockColumnSql = $this->manualNameLockColumnSql($driver, 'name_ua_manually_locked_at');
        $activeMarkerSql = $this->activeLanguageMarkerConflictSqlParts($driver);

        if ($driver === 'pgsql') {
            return [
                "coalesce(nullif(raw_attributes::jsonb #>> '{name_language_marker_conflict_ru,count}', '')::int, 0) > 0
                    and {$ruLockColumnSql}nullif(trim(coalesce(raw_attributes::jsonb #>> '{manual_name_locks,ru}', '')), '') is null
                    {$activeMarkerSql['ru']}",
                "coalesce(nullif(raw_attributes::jsonb #>> '{name_language_marker_conflict_ua,count}', '')::int, 0) > 0
                    and {$uaLockColumnSql}nullif(trim(coalesce(raw_attributes::jsonb #>> '{manual_name_locks,ua}', '')), '') is null
                    {$activeMarkerSql['ua']}",
            ];
        }

        if ($driver === 'sqlite') {
            return [
                "cast(coalesce(json_extract(raw_attributes, '$.name_language_marker_conflict_ru.count'), 0) as integer) > 0
                    and {$ruLockColumnSql}nullif(trim(coalesce(json_extract(raw_attributes, '$.manual_name_locks.ru'), '')), '') is null
                    {$activeMarkerSql['ru']}",
                "cast(coalesce(json_extract(raw_attributes, '$.name_language_marker_conflict_ua.count'), 0) as integer) > 0
                    and {$uaLockColumnSql}nullif(trim(coalesce(json_extract(raw_attributes, '$.manual_name_locks.ua'), '')), '') is null
                    {$activeMarkerSql['ua']}",
            ];
        }

        $json = 'if(json_valid(`part_catalog_items`.`raw_attributes`), `part_catalog_items`.`raw_attributes`, json_object())';

        return [
            "cast(coalesce(json_unquote(json_extract({$json}, '$.name_language_marker_conflict_ru.count')), '0') as unsigned) > 0
                and {$ruLockColumnSql}nullif(trim(coalesce(json_unquote(json_extract({$json}, '$.manual_name_locks.ru')), '')), '') is null
                {$activeMarkerSql['ru']}",
            "cast(coalesce(json_unquote(json_extract({$json}, '$.name_language_marker_conflict_ua.count')), '0') as unsigned) > 0
                and {$uaLockColumnSql}nullif(trim(coalesce(json_unquote(json_extract({$json}, '$.manual_name_locks.ua')), '')), '') is null
                {$activeMarkerSql['ua']}",
        ];
    }

    private function activeLanguageMarkerConflictSqlParts(string $driver): array
    {
        $markers = PartCatalogLanguageMarkers::activeNormalized()->all();

        if ($markers === []) {
            return ['ru' => 'and 1 = 0', 'ua' => 'and 1 = 0'];
        }

        return [
            'ru' => 'and '.$this->activeLanguageMarkerConflictSql($driver, 'ru', $markers),
            'ua' => 'and '.$this->activeLanguageMarkerConflictSql($driver, 'ua', $markers),
        ];
    }

    private function activeLanguageMarkerConflictSql(string $driver, string $locale, array $markers): string
    {
        $quotedMarkers = collect($markers)
            ->map(fn (string $marker): string => DB::connection()->getPdo()->quote($marker))
            ->implode(', ');

        if ($driver === 'pgsql') {
            return "exists (
                select 1
                from jsonb_array_elements_text(coalesce(raw_attributes::jsonb #> '{name_language_marker_conflict_{$locale},markers}', '[]'::jsonb)) as marker(value)
                where lower(marker.value) in ({$quotedMarkers})
            )";
        }

        if ($driver === 'sqlite') {
            return "exists (
                select 1
                from json_each(raw_attributes, '$.name_language_marker_conflict_{$locale}.markers') as marker
                where lower(marker.value) in ({$quotedMarkers})
            )";
        }

        $json = 'if(json_valid(`part_catalog_items`.`raw_attributes`), `part_catalog_items`.`raw_attributes`, json_object())';

        return '('.collect($markers)
            ->map(fn (string $marker): string => "json_contains(json_extract({$json}, '$.name_language_marker_conflict_{$locale}.markers'), json_quote(".DB::connection()->getPdo()->quote($marker).'))')
            ->implode(' or ').')';
    }

    private function manualNameLockColumnSql(string $driver, string $column): string
    {
        if (! Schema::hasColumn('part_catalog_items', $column)) {
            return '';
        }

        return match ($driver) {
            'pgsql' => 'part_catalog_items.'.$column.' is null and ',
            'sqlite' => 'part_catalog_items.'.$column.' is null and ',
            default => '`part_catalog_items`.`'.$column.'` is null and ',
        };
    }

    private function teslaPartPhotoSql(string $driver): string
    {
        if ($driver === 'pgsql') {
            return "part_catalog_items.source = 'tesla_official'
                and exists (
                    select 1
                    from jsonb_array_elements_text(coalesce(raw_attributes::jsonb -> 'part_image_urls', '[]'::jsonb)) as image_url(value)
                    where image_url.value ilike '%tesla-official/part-images/%'
                )";
        }

        if ($driver === 'sqlite') {
            return "part_catalog_items.source = 'tesla_official'
                and exists (
                    select 1
                    from json_each(coalesce(json_extract(raw_attributes, '$.part_image_urls'), '[]'))
                    where json_each.value like '%tesla-official/part-images/%'
                )";
        }

        $json = 'if(json_valid(`part_catalog_items`.`raw_attributes`), `part_catalog_items`.`raw_attributes`, json_object())';

        return "`part_catalog_items`.`source` = 'tesla_official'
            and json_search({$json}, 'one', '%tesla-official/part-images/%', null, '$.part_image_urls[*]') is not null";
    }

    private function teslaSchemeSql(string $driver): string
    {
        if ($driver === 'pgsql') {
            return "part_catalog_items.source = 'tesla_official'
                and (
                    coalesce(raw_attributes::jsonb ->> 'image_url', '') ilike '%tesla-official/resources-images/%'
                    or coalesce(raw_attributes::jsonb ->> 'image_url', '') ilike '%epc.tesla.com/resources/images/%'
                    or exists (
                        select 1
                        from jsonb_array_elements_text(coalesce(raw_attributes::jsonb -> 'image_urls', '[]'::jsonb)) as image_url(value)
                        where image_url.value ilike '%tesla-official/resources-images/%'
                            or image_url.value ilike '%epc.tesla.com/resources/images/%'
                    )
                )";
        }

        if ($driver === 'sqlite') {
            return "part_catalog_items.source = 'tesla_official'
                and (
                    coalesce(json_extract(raw_attributes, '$.image_url'), '') like '%tesla-official/resources-images/%'
                    or coalesce(json_extract(raw_attributes, '$.image_url'), '') like '%epc.tesla.com/resources/images/%'
                    or exists (
                        select 1
                        from json_each(coalesce(json_extract(raw_attributes, '$.image_urls'), '[]'))
                        where json_each.value like '%tesla-official/resources-images/%'
                            or json_each.value like '%epc.tesla.com/resources/images/%'
                    )
                )";
        }

        $json = 'if(json_valid(`part_catalog_items`.`raw_attributes`), `part_catalog_items`.`raw_attributes`, json_object())';

        return "`part_catalog_items`.`source` = 'tesla_official'
            and (
                json_unquote(json_extract({$json}, '$.image_url')) like '%tesla-official/resources-images/%'
                or json_unquote(json_extract({$json}, '$.image_url')) like '%epc.tesla.com/resources/images/%'
                or json_search({$json}, 'one', '%tesla-official/resources-images/%', null, '$.image_urls[*]') is not null
                or json_search({$json}, 'one', '%epc.tesla.com/resources/images/%', null, '$.image_urls[*]') is not null
            )";
    }

    private function catalogItemHasImageSqlParts(string $driver): array
    {
        if ($driver === 'sqlite') {
            return [
                [
                    "exists (
                        select 1
                        from json_each(coalesce(json_extract(raw_attributes, '$.part_image_urls'), '[]'))
                        where nullif(trim(json_each.value), '') is not null
                            and part_catalog_items.source = 'tesla_official'
                            and json_each.value not like '%/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2_%'
                            and json_each.value not like '%/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600_%'
                    )",
                    "exists (
                        select 1
                        from json_each(coalesce(json_extract(raw_attributes, '$.image_urls'), '[]'))
                        where nullif(trim(json_each.value), '') is not null
                            and part_catalog_items.source <> 'tesla_official'
                            and json_each.value not like '%/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2_%'
                            and json_each.value not like '%/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600_%'
                    )",
                    "exists (
                        select 1
                        from json_each(coalesce(json_extract(raw_attributes, '$.remote_image_urls'), '[]'))
                        where nullif(trim(json_each.value), '') is not null
                            and part_catalog_items.source <> 'tesla_official'
                            and json_each.value not like '%/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2_%'
                            and json_each.value not like '%/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600_%'
                    )",
                    "(
                        nullif(trim(coalesce(json_extract(raw_attributes, '$.image_url'), '')), '') is not null
                        and json_extract(raw_attributes, '$.image_url') not like '%/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2_%'
                        and json_extract(raw_attributes, '$.image_url') not like '%/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600_%'
                    )",
                    "(
                        nullif(trim(coalesce(json_extract(raw_attributes, '$.remote_image_url'), '')), '') is not null
                        and part_catalog_items.source <> 'tesla_official'
                        and json_extract(raw_attributes, '$.remote_image_url') not like '%/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2_%'
                        and json_extract(raw_attributes, '$.remote_image_url') not like '%/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600_%'
                    )",
                    "(
                        nullif(trim(coalesce(json_extract(raw_attributes, '$.primary_image_url'), '')), '') is not null
                        and part_catalog_items.source <> 'tesla_official'
                        and json_extract(raw_attributes, '$.primary_image_url') not like '%/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2_%'
                        and json_extract(raw_attributes, '$.primary_image_url') not like '%/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600_%'
                    )",
                ],
                [],
            ];
        }

        if ($driver === 'pgsql') {
            return [
                [
                    "exists (
                        select 1
                        from jsonb_array_elements_text(coalesce(raw_attributes::jsonb -> 'part_image_urls', '[]'::jsonb)) as image_url(value)
                        where nullif(trim(image_url.value), '') is not null
                            and part_catalog_items.source = 'tesla_official'
                            and image_url.value !~* '/storage/editor/fotos/(6f46fee0ab4e187090a1f63b7a570bb2|59968e2a90ed37d309bb00d2e4423600)_[0-9]+\\.(jpe?g|png|webp)([?#].*)?$'
                    )",
                    "exists (
                        select 1
                        from jsonb_array_elements_text(coalesce(raw_attributes::jsonb -> 'image_urls', '[]'::jsonb)) as image_url(value)
                        where nullif(trim(image_url.value), '') is not null
                            and part_catalog_items.source <> 'tesla_official'
                            and image_url.value !~* '/storage/editor/fotos/(6f46fee0ab4e187090a1f63b7a570bb2|59968e2a90ed37d309bb00d2e4423600)_[0-9]+\\.(jpe?g|png|webp)([?#].*)?$'
                    )",
                    "exists (
                        select 1
                        from jsonb_array_elements_text(coalesce(raw_attributes::jsonb -> 'remote_image_urls', '[]'::jsonb)) as image_url(value)
                        where nullif(trim(image_url.value), '') is not null
                            and part_catalog_items.source <> 'tesla_official'
                            and image_url.value !~* '/storage/editor/fotos/(6f46fee0ab4e187090a1f63b7a570bb2|59968e2a90ed37d309bb00d2e4423600)_[0-9]+\\.(jpe?g|png|webp)([?#].*)?$'
                    )",
                    "(
                        nullif(trim(coalesce(raw_attributes::jsonb ->> 'image_url', '')), '') is not null
                        and (raw_attributes::jsonb ->> 'image_url') !~* '/storage/editor/fotos/(6f46fee0ab4e187090a1f63b7a570bb2|59968e2a90ed37d309bb00d2e4423600)_[0-9]+\\.(jpe?g|png|webp)([?#].*)?$'
                    )",
                    "(
                        nullif(trim(coalesce(raw_attributes::jsonb ->> 'remote_image_url', '')), '') is not null
                        and part_catalog_items.source <> 'tesla_official'
                        and (raw_attributes::jsonb ->> 'remote_image_url') !~* '/storage/editor/fotos/(6f46fee0ab4e187090a1f63b7a570bb2|59968e2a90ed37d309bb00d2e4423600)_[0-9]+\\.(jpe?g|png|webp)([?#].*)?$'
                    )",
                    "(
                        nullif(trim(coalesce(raw_attributes::jsonb ->> 'primary_image_url', '')), '') is not null
                        and part_catalog_items.source <> 'tesla_official'
                        and (raw_attributes::jsonb ->> 'primary_image_url') !~* '/storage/editor/fotos/(6f46fee0ab4e187090a1f63b7a570bb2|59968e2a90ed37d309bb00d2e4423600)_[0-9]+\\.(jpe?g|png|webp)([?#].*)?$'
                    )",
                ],
                [],
            ];
        }

        $json = 'if(json_valid(`part_catalog_items`.`raw_attributes`), `part_catalog_items`.`raw_attributes`, json_object())';

        return [
            [
                "(
                    json_length(coalesce(json_extract({$json}, '$.part_image_urls'), json_array())) > 0
                    and `part_catalog_items`.`source` = 'tesla_official'
                    and not (
                        json_length(coalesce(json_extract({$json}, '$.part_image_urls'), json_array())) = 1
                        and (
                            json_unquote(json_extract({$json}, '$.part_image_urls[0]')) like '%/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2\\_%'
                            or json_unquote(json_extract({$json}, '$.part_image_urls[0]')) like '%/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600\\_%'
                        )
                    )
                )",
                "(
                    json_length(coalesce(json_extract({$json}, '$.image_urls'), json_array())) > 0
                    and `part_catalog_items`.`source` <> 'tesla_official'
                    and not (
                        json_length(coalesce(json_extract({$json}, '$.image_urls'), json_array())) = 1
                        and (
                            json_unquote(json_extract({$json}, '$.image_urls[0]')) like '%/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2\\_%'
                            or json_unquote(json_extract({$json}, '$.image_urls[0]')) like '%/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600\\_%'
                        )
                    )
                )",
                "(
                    json_length(coalesce(json_extract({$json}, '$.remote_image_urls'), json_array())) > 0
                    and `part_catalog_items`.`source` <> 'tesla_official'
                    and not (
                        json_length(coalesce(json_extract({$json}, '$.remote_image_urls'), json_array())) = 1
                        and (
                            json_unquote(json_extract({$json}, '$.remote_image_urls[0]')) like '%/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2\\_%'
                            or json_unquote(json_extract({$json}, '$.remote_image_urls[0]')) like '%/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600\\_%'
                        )
                    )
                )",
                "(
                    nullif(trim(coalesce(json_unquote(json_extract({$json}, '$.image_url')), '')), '') is not null
                    and json_unquote(json_extract({$json}, '$.image_url')) not like '%/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2\\_%'
                    and json_unquote(json_extract({$json}, '$.image_url')) not like '%/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600\\_%'
                )",
                "(
                    nullif(trim(coalesce(json_unquote(json_extract({$json}, '$.remote_image_url')), '')), '') is not null
                    and `part_catalog_items`.`source` <> 'tesla_official'
                    and json_unquote(json_extract({$json}, '$.remote_image_url')) not like '%/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2\\_%'
                    and json_unquote(json_extract({$json}, '$.remote_image_url')) not like '%/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600\\_%'
                )",
                "(
                    nullif(trim(coalesce(json_unquote(json_extract({$json}, '$.primary_image_url')), '')), '') is not null
                    and `part_catalog_items`.`source` <> 'tesla_official'
                    and json_unquote(json_extract({$json}, '$.primary_image_url')) not like '%/storage/editor/fotos/6f46fee0ab4e187090a1f63b7a570bb2\\_%'
                    and json_unquote(json_extract({$json}, '$.primary_image_url')) not like '%/storage/editor/fotos/59968e2a90ed37d309bb00d2e4423600\\_%'
                )",
            ],
            [],
        ];
    }

    private function catalogItemMissingImageSql(string $driver): string
    {
        if ($driver === 'sqlite') {
            return "coalesce(json_extract(raw_attributes, '$.catalog_image_missing'), 0) = 1";
        }

        if ($driver === 'pgsql') {
            return "coalesce((raw_attributes::jsonb ->> 'catalog_image_missing')::boolean, false) = true";
        }

        $json = 'if(json_valid(`part_catalog_items`.`raw_attributes`), `part_catalog_items`.`raw_attributes`, json_object())';

        return "coalesce(json_unquote(json_extract({$json}, '$.catalog_image_missing')), 'false') = 'true'";
    }

    private function hasBlankNameColumn(string $column): bool
    {
        return self::$blankNameColumns[$column] ??= Schema::hasColumn('part_catalog_items', $column);
    }
}
