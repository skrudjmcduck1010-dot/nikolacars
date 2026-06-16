<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PartCatalogSearchService
{
    private ?bool $hasSuggestionFullTextIndex = null;

    private ?bool $hasCompactPartNumberIndex = null;

    public function applyItemSearch(Builder $itemsQuery, string $query, ?string $driver = null, bool $allowFullText = true): void
    {
        $driver ??= DB::connection()->getDriverName();
        $likeQuery = '%'.$query.'%';
        $partNumberQuery = $this->compactPartNumberSearch($query);
        $partNumberLikeQuery = $partNumberQuery === '' ? null : '%'.$partNumberQuery.'%';

        if ($driver === 'sqlite') {
            $ids = (clone $itemsQuery)
                ->get([
                    'id',
                    'name',
                    'name_en',
                    'name_ru',
                    'name_ua',
                    'part_number',
                    'model_label',
                    'model_name',
                    'main_category_name',
                    'subcategory_name',
                    'node_name',
                    'compatibility_text',
                    'availability',
                    'raw_attributes',
                ])
                ->filter(fn (PartCatalogItem $item) => collect([
                    $item->name,
                    $item->name_en,
                    $item->name_ru,
                    $item->name_ua,
                    $item->part_number,
                    $this->compactPartNumberSearch((string) $item->part_number),
                    $item->model_label,
                    $item->model_name,
                    $item->main_category_name,
                    $item->subcategory_name,
                    $item->node_name,
                    $item->compatibility_text,
                    $item->availability,
                    (string) data_get($item->raw_attributes, 'code', ''),
                    (string) data_get($item->raw_attributes, 'donor_vin', ''),
                    (string) data_get($item->raw_attributes, 'category_display', ''),
                    (string) data_get($item->raw_attributes, 'category_path', ''),
                ])->filter()->contains(fn (string $value) => mb_stripos($value, $query) !== false
                    || ($partNumberQuery !== '' && mb_stripos($value, $partNumberQuery) !== false)))
                ->pluck('id');

            $itemsQuery->whereIn('id', $ids);

            return;
        }

        if ($allowFullText && $driver === 'mysql' && $this->hasSuggestionFullTextIndex()) {
            $this->applyMysqlItemSearch($itemsQuery, $query);

            return;
        }

        $operator = $driver === 'pgsql' ? 'ilike' : 'like';
        $compactPartNumberSql = "replace(replace(part_number, '-', ''), ' ', '')";

        $itemsQuery->where(function (Builder $builder) use ($driver, $likeQuery, $operator, $partNumberLikeQuery, $compactPartNumberSql): void {
            $builder
                ->where('name', $operator, $likeQuery)
                ->orWhere('name_en', $operator, $likeQuery)
                ->orWhere('name_ru', $operator, $likeQuery)
                ->orWhere('name_ua', $operator, $likeQuery)
                ->orWhere('part_number', $operator, $likeQuery)
                ->orWhere('model_label', $operator, $likeQuery)
                ->orWhere('model_name', $operator, $likeQuery)
                ->orWhere('main_category_name', $operator, $likeQuery)
                ->orWhere('subcategory_name', $operator, $likeQuery)
                ->orWhere('node_name', $operator, $likeQuery)
                ->orWhere('compatibility_text', $operator, $likeQuery)
                ->orWhere('availability', $operator, $likeQuery);

            if ($partNumberLikeQuery !== null) {
                $builder->orWhereRaw($compactPartNumberSql.' '.$operator.' ?', [$partNumberLikeQuery]);
            }

            if ($driver !== 'pgsql') {
                $builder
                    ->orWhereRaw("json_unquote(json_extract(raw_attributes, '$.code')) like ?", [$likeQuery])
                    ->orWhereRaw("json_unquote(json_extract(raw_attributes, '$.donor_vin')) like ?", [$likeQuery])
                    ->orWhereRaw("json_unquote(json_extract(raw_attributes, '$.category_display')) like ?", [$likeQuery])
                    ->orWhereRaw("json_unquote(json_extract(raw_attributes, '$.category_path')) like ?", [$likeQuery]);
            }
        });
    }

    public function suggestionItems(string $source, string $query, callable $filterSource, ?string $driver = null): Collection
    {
        $driver ??= DB::connection()->getDriverName();
        $columns = ['id', 'name', 'name_en', 'name_ru', 'name_ua', 'part_number', 'model_label', 'main_category_name', 'subcategory_name', 'node_name'];
        $items = collect();
        $seenIds = [];
        $operator = $driver === 'pgsql' ? 'ilike' : 'like';
        $prefixQuery = $query.'%';
        $partNumberQuery = $this->compactPartNumberSearch($query);
        $compactPartNumberSql = "replace(replace(part_number, '-', ''), ' ', '')";

        $append = function (callable $callback) use ($source, $filterSource, $columns, &$items, &$seenIds): void {
            if ($items->count() >= 12) {
                return;
            }

            $queryBuilder = PartCatalogItem::query()
                ->tap(fn (Builder $builder) => $filterSource($builder, $source));

            $callback($queryBuilder);

            $queryBuilder
                ->orderByRaw('part_number is null')
                ->orderBy('part_number')
                ->orderBy('name')
                ->limit(12 - $items->count())
                ->get($columns)
                ->each(function (PartCatalogItem $item) use (&$items, &$seenIds): void {
                    if (isset($seenIds[$item->id])) {
                        return;
                    }

                    $seenIds[$item->id] = true;
                    $items->push($item);
                });
        };

        $append(fn (Builder $builder) => $builder->where('part_number', $operator, $prefixQuery));

        if ($partNumberQuery !== '') {
            $append(fn (Builder $builder) => $builder->whereRaw($compactPartNumberSql.' '.$operator.' ?', [$partNumberQuery.'%']));
        }

        if (mb_strlen($query) >= 3 && ($driver !== 'mysql' || $this->hasSuggestionFullTextIndex())) {
            $append(fn (Builder $builder) => $builder->where(function (Builder $builder) use ($operator, $prefixQuery): void {
                $builder
                    ->where('name', $operator, $prefixQuery)
                    ->orWhere('name_en', $operator, $prefixQuery)
                    ->orWhere('name_ru', $operator, $prefixQuery)
                    ->orWhere('name_ua', $operator, $prefixQuery);
            }));
        }

        if ($driver === 'mysql' && mb_strlen($query) >= 3 && $this->hasSuggestionFullTextIndex()) {
            $fullTextQuery = $this->fullTextBooleanQuery($query);

            if ($fullTextQuery !== '') {
                $append(fn (Builder $builder) => $builder
                    ->whereRaw(
                        'MATCH(name, name_en, name_ru, name_ua, part_number) AGAINST (? IN BOOLEAN MODE)',
                        [$fullTextQuery]
                    )
                    ->orderByRaw(
                        'MATCH(name, name_en, name_ru, name_ua, part_number) AGAINST (? IN BOOLEAN MODE) desc',
                        [$fullTextQuery]
                    ));
            }
        }

        return $items;
    }

    public function compactPartNumberSearch(string $value): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
    }

    private function applyMysqlItemSearch(Builder $itemsQuery, string $query): void
    {
        $partNumberQuery = $this->compactPartNumberSearch($query);
        $partNumberPrefixQuery = $query.'%';
        $fullTextQuery = mb_strlen($query) >= 3 ? $this->fullTextBooleanQuery($query) : '';
        $compactPartNumberSql = $this->hasCompactPartNumberIndex()
            ? 'part_number_compact'
            : "replace(replace(upper(part_number), '-', ''), ' ', '')";
        $looksLikePartNumber = $partNumberQuery !== ''
            && mb_strlen($partNumberQuery) >= 4
            && preg_match('/\d/', $partNumberQuery) === 1;

        if ($looksLikePartNumber) {
            if ($this->hasCompactPartNumberIndex()) {
                $itemsQuery->where('part_number_compact', 'like', $partNumberQuery.'%');

                return;
            }

            $itemsQuery->where(function (Builder $builder) use ($partNumberPrefixQuery, $partNumberQuery, $compactPartNumberSql): void {
                $builder
                    ->where('part_number', 'like', $partNumberPrefixQuery)
                    ->orWhereRaw($compactPartNumberSql.' like ?', [$partNumberQuery.'%']);
            });

            return;
        }

        if ($fullTextQuery !== '') {
            $itemsQuery->whereRaw(
                'MATCH(name, name_en, name_ru, name_ua, part_number) AGAINST (? IN BOOLEAN MODE)',
                [$fullTextQuery]
            );

            return;
        }

        $itemsQuery->where(function (Builder $builder) use ($partNumberPrefixQuery, $partNumberQuery, $compactPartNumberSql): void {
            $builder->where('part_number', 'like', $partNumberPrefixQuery);

            if ($partNumberQuery !== '') {
                $builder->orWhereRaw($compactPartNumberSql.' like ?', [$partNumberQuery.'%']);
            }
        });
    }

    private function hasSuggestionFullTextIndex(): bool
    {
        if ($this->hasSuggestionFullTextIndex !== null) {
            return $this->hasSuggestionFullTextIndex;
        }

        try {
            $index = DB::selectOne(
                "SHOW INDEX FROM part_catalog_items WHERE Key_name = 'part_catalog_items_suggestions_fulltext'"
            );

            return $this->hasSuggestionFullTextIndex = $index !== null;
        } catch (\Throwable) {
            return $this->hasSuggestionFullTextIndex = false;
        }
    }

    private function hasCompactPartNumberIndex(): bool
    {
        if ($this->hasCompactPartNumberIndex !== null) {
            return $this->hasCompactPartNumberIndex;
        }

        try {
            $column = DB::selectOne("SHOW COLUMNS FROM part_catalog_items LIKE 'part_number_compact'");

            return $this->hasCompactPartNumberIndex = $column !== null;
        } catch (\Throwable) {
            return $this->hasCompactPartNumberIndex = false;
        }
    }

    private function fullTextBooleanQuery(string $query): string
    {
        $terms = preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query) ?? ''));

        return collect($terms)
            ->map(fn (?string $term): string => trim((string) $term))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 2)
            ->map(fn (string $term): string => '+'.$term.'*')
            ->implode(' ');
    }
}
