<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PartCatalogCategoryRouteService
{
    private const SYNTHETIC_PATH_SOURCES = [
        'tesla_official',
        'teslapartsukraine',
        'tsk',
        'teslahelp',
        'stock-tesla',
        'driveparts',
        'dkparts',
        'erazborka',
        'toprazborka',
        'teslawestparts',
        'teslacompany',
        'nikolacars',
    ];

    public function categoryTrail(?PartCatalogCategory $category): Collection
    {
        $trail = collect();

        while ($category) {
            $trail->prepend($category);

            $category = $category->parent_id
                ? PartCatalogCategory::query()->select(['id', 'parent_id', 'source', 'name', 'name_en', 'name_ru', 'name_ua', 'code', 'source_url', 'depth', 'model_label'])->find($category->parent_id)
                : null;
        }

        return $trail->values();
    }

    public function categoryUrl(
        ?PartCatalogCategory $category,
        array $catalog,
        array $models = [],
        bool $includeCybertruck = false,
        bool $reuseTeslaOfficialTcarsSegments = true
    ): string {
        $modelQuery = $this->modelQuery($models, $includeCybertruck);
        $routePrefix = (string) $catalog['route_prefix'];

        if ($category === null) {
            $route = route($routePrefix.'.index');

            return $modelQuery === [] ? $route : $route.'?'.http_build_query($modelQuery);
        }

        $catalogPath = $this->catalogPath($category, $reuseTeslaOfficialTcarsSegments);
        $query = $modelQuery;

        if (($catalog['has_category_route'] ?? true) === false) {
            $route = route($routePrefix.'.index');
            $query = ['category_id' => $category->id] + $query;

            return $route.'?'.http_build_query($query);
        }

        if ($catalogPath !== null) {
            $route = route($routePrefix.'.category', ['catalogPath' => $catalogPath]);

            return $query === [] ? $route : $route.'?'.http_build_query($query);
        }

        $route = route($routePrefix.'.index');
        $query = ['category_id' => $category->id] + $query;

        return $route.'?'.http_build_query($query);
    }

    public function catalogPath(PartCatalogCategory $category, bool $reuseTeslaOfficialTcarsSegments = true): ?string
    {
        if ($category->source === 'tcarservice' && str_starts_with($category->source_url, 'https://tcarservice.com/zapchasty/')) {
            $path = parse_url($category->source_url, PHP_URL_PATH) ?: '';

            return trim(Str::after($path, '/zapchasty/'), '/');
        }

        if (in_array($category->source, self::SYNTHETIC_PATH_SOURCES, true)) {
            return $this->syntheticCategoryPath($category, $reuseTeslaOfficialTcarsSegments);
        }

        return null;
    }

    public function categoryIdByCatalogPath(
        string $source,
        string $catalogPath,
        bool $allowTeslaOfficialTcarsFallback = true
    ): int {
        if (! in_array($source, self::SYNTHETIC_PATH_SOURCES, true)) {
            return 0;
        }

        $segments = collect(explode('/', trim($catalogPath, '/')))->filter()->values();
        $lastSegment = $segments->last();

        if (is_string($lastSegment) && preg_match('/-(\d+)$/', $lastSegment, $matches) === 1) {
            $id = (int) $matches[1];
            $category = PartCatalogCategory::query()
                ->where('source', $source)
                ->whereKey($id)
                ->first(['id']);

            if ($category !== null) {
                return $id;
            }
        }

        $sourceUrlCategoryId = $this->categoryIdBySourceUrlPath($source, $catalogPath);
        if ($sourceUrlCategoryId > 0) {
            return $sourceUrlCategoryId;
        }

        if ($source === 'dkparts') {
            $dkPartsCategoryId = $this->dkPartsCategoryIdByTcarsPathSegments($segments);
            if ($dkPartsCategoryId > 0) {
                return $dkPartsCategoryId;
            }
        }

        if ($source === 'tesla_official' && ! $allowTeslaOfficialTcarsFallback) {
            return 0;
        }

        $tcarsCategory = $this->tcarsCategoryByPathSegments($segments);

        if ($tcarsCategory === null) {
            return 0;
        }

        $query = PartCatalogCategory::query()
            ->where('source', $source)
            ->where('model_label', $tcarsCategory->model_label)
            ->where('depth', $tcarsCategory->depth);

        if ((int) $tcarsCategory->depth === 0) {
            return (int) ($query->value('id') ?: 0);
        }

        if ($tcarsCategory->code !== null) {
            return (int) ($query->where('code', $tcarsCategory->code)->value('id') ?: 0);
        }

        return (int) ($query->where('name', $tcarsCategory->name)->value('id') ?: 0);
    }

    public function matchingTcarsCategory(PartCatalogCategory $category): ?PartCatalogCategory
    {
        if ($category->source === 'dkparts') {
            return $this->matchingTcarsCategoryForDkParts($category);
        }

        $query = PartCatalogCategory::query()
            ->where('source', 'tcarservice')
            ->where('model_label', $category->model_label)
            ->where('depth', $category->depth);

        if ((int) $category->depth === 0) {
            return $query->first();
        }

        if ($category->code !== null) {
            return $query->where('code', $category->code)->first();
        }

        return $query->where('name', $category->name)->first();
    }

    private function dkPartsCategoryIdByTcarsPathSegments(Collection $segments): int
    {
        $modelSegment = $segments->first();
        if (! is_string($modelSegment)) {
            return 0;
        }

        $modelSourceUrl = $this->dkPartsSourceUrlForTcarsModelSegment($modelSegment);
        if ($modelSourceUrl === null) {
            return 0;
        }

        $category = PartCatalogCategory::query()
            ->where('source', 'dkparts')
            ->where('source_url', $modelSourceUrl)
            ->first(['id', 'parent_id', 'source', 'source_url', 'name', 'code', 'depth']);

        if ($category === null) {
            return 0;
        }

        foreach ($segments->slice(1)->values() as $depth => $segment) {
            if (! is_string($segment)) {
                return 0;
            }

            $query = PartCatalogCategory::query()
                ->where('source', 'dkparts')
                ->where('parent_id', $category->id)
                ->where('depth', $depth + 1);

            $code = $this->codeFromTcarsPathSegment($segment);
            $category = $code !== null
                ? $query->where('code', $code)->first(['id', 'parent_id', 'source', 'source_url', 'name', 'code', 'depth'])
                : $query->get(['id', 'parent_id', 'source', 'source_url', 'name', 'code', 'depth'])
                    ->first(fn (PartCatalogCategory $candidate): bool => $this->categoryPathSegment($candidate) === $segment);

            if ($category === null) {
                return 0;
            }
        }

        return (int) $category->id;
    }

    private function matchingTcarsCategoryForDkParts(PartCatalogCategory $category): ?PartCatalogCategory
    {
        $root = $category;
        while ($root->parent_id) {
            $parent = PartCatalogCategory::query()
                ->where('source', 'dkparts')
                ->whereKey($root->parent_id)
                ->first(['id', 'parent_id', 'source', 'source_url', 'name', 'code', 'depth']);

            if ($parent === null) {
                return null;
            }

            $root = $parent;
        }

        $tcarsRootUrl = $this->tcarsSourceUrlForDkPartsModelUrl((string) $root->source_url);
        if ($tcarsRootUrl === null) {
            return null;
        }

        $query = PartCatalogCategory::query()
            ->where('source', 'tcarservice')
            ->where('depth', $category->depth);

        if ((int) $category->depth === 0) {
            return $query->where('source_url', $tcarsRootUrl)->first();
        }

        if ($category->code !== null) {
            return $query->where('code', $category->code)->first();
        }

        return $query->where('name', $category->name)->first();
    }

    private function dkPartsSourceUrlForTcarsModelSegment(string $segment): ?string
    {
        return match ($segment) {
            'model-s-321' => 'https://dk-parts.com.ua/ru/model-s-before-2016/',
            'model-s2-322' => 'https://dk-parts.com.ua/ru/model-s-after-2016/',
            'model-s-plaid', 'model-s-palladium' => 'https://dk-parts.com.ua/ru/model-s-plaid/',
            'model-x-323', 'model-x' => 'https://dk-parts.com.ua/ru/model-x/',
            'model-x-plaid', 'model-x-palladium' => 'https://dk-parts.com.ua/ru/model-x-plaid/',
            'model-3-326' => 'https://dk-parts.com.ua/ru/model-3/',
            'model-y-327' => 'https://dk-parts.com.ua/ru/model-y/',
            default => null,
        };
    }

    private function tcarsSourceUrlForDkPartsModelUrl(string $sourceUrl): ?string
    {
        return match (rtrim($sourceUrl, '/').'/') {
            'https://dk-parts.com.ua/ru/model-s-before-2016/' => 'https://tcarservice.com/zapchasty/model-s-321',
            'https://dk-parts.com.ua/ru/model-s-after-2016/' => 'https://tcarservice.com/zapchasty/model-s2-322',
            'https://dk-parts.com.ua/ru/model-3/' => 'https://tcarservice.com/zapchasty/model-3-326',
            'https://dk-parts.com.ua/ru/model-y/' => 'https://tcarservice.com/zapchasty/model-y-327',
            default => null,
        };
    }

    private function codeFromTcarsPathSegment(string $segment): ?string
    {
        return preg_match('/^(\d{2,5})(?:-|$)/', $segment, $matches) === 1 ? $matches[1] : null;
    }

    private function syntheticCategoryPath(PartCatalogCategory $category, bool $reuseTeslaOfficialTcarsSegments = true): string
    {
        return $this->categoryTrail($category)
            ->map(fn (PartCatalogCategory $trailCategory): string => $this->categoryPathSegment($trailCategory, $reuseTeslaOfficialTcarsSegments))
            ->implode('/');
    }

    private function categoryPathSegment(PartCatalogCategory $category, bool $reuseTeslaOfficialTcarsSegments = true): string
    {
        if ($category->source === 'tesla_official' && ! $reuseTeslaOfficialTcarsSegments) {
            return Str::slug(collect([$category->code, $category->name])->filter()->implode(' ')).'-'.$category->id;
        }

        $tcarsSegment = $this->tcarsCategoryPathSegment($category);

        if ($tcarsSegment !== null) {
            return $tcarsSegment;
        }

        return Str::slug(collect([$category->code, $category->name])->filter()->implode(' ')).'-'.$category->id;
    }

    private function categoryIdBySourceUrlPath(string $source, string $catalogPath): int
    {
        $baseUrl = $this->catalogSiteUrl($source);
        if ($baseUrl === null) {
            return 0;
        }

        $path = trim($catalogPath, '/');
        if ($path === '') {
            return 0;
        }

        $paths = [$path];
        if (! str_starts_with($path, 'category/')) {
            $paths[] = 'category/'.$path;
        }

        $urls = collect($paths)
            ->flatMap(fn (string $candidate): array => [
                rtrim($baseUrl, '/').'/'.$candidate,
                rtrim($baseUrl, '/').'/'.$candidate.'/',
            ])
            ->unique()
            ->values()
            ->all();

        return (int) (PartCatalogCategory::query()
            ->where('source', $source)
            ->whereIn('source_url', $urls)
            ->value('id') ?: 0);
    }

    private function tcarsCategoryPathSegment(PartCatalogCategory $category): ?string
    {
        $tcarsCategory = $category->source === 'tcarservice'
            ? $category
            : $this->matchingTcarsCategory($category);

        if ($tcarsCategory === null) {
            return null;
        }

        $path = $this->catalogPath($tcarsCategory);

        if ($path === null) {
            return null;
        }

        return collect(explode('/', $path))->filter()->last();
    }

    private function tcarsCategoryByPathSegments(Collection $segments): ?PartCatalogCategory
    {
        $matched = null;

        foreach ($segments as $depth => $segment) {
            if (! is_string($segment)) {
                return null;
            }

            $candidates = PartCatalogCategory::query()
                ->where('source', 'tcarservice')
                ->where('depth', $depth)
                ->get(['id', 'parent_id', 'source', 'source_url', 'name', 'code', 'model_label', 'depth']);

            $matched = $candidates->first(function (PartCatalogCategory $category) use ($segment, $matched): bool {
                if ($matched !== null && $category->parent_id !== $matched->id) {
                    return false;
                }

                return $this->tcarsCategoryPathSegment($category) === $segment;
            });

            if ($matched === null) {
                return null;
            }
        }

        return $matched;
    }

    private function modelQuery(array $models, bool $includeCybertruck): array
    {
        $query = [];

        if ($models !== []) {
            $query['models'] = array_values($models);
        }

        if ($includeCybertruck) {
            $query['include_cybertruck'] = '1';
        }

        return $query;
    }

    private function catalogSiteUrl(string $source): ?string
    {
        return [
            'tcarservice' => 'https://tcarservice.com/zapchasty',
            'teslapartsukraine' => 'https://teslapartsukraine.com.ua/tesla-model-3/?limit=10000',
            'tsk' => 'https://tsk.ua/katalog-zapchastey296/',
            'stock-tesla' => 'https://stock-tesla.com',
            'teslahelp' => 'https://teslahelp.ru',
            'driveparts' => 'https://drive-parts.com.ua/ru/kataloh/',
            'dkparts' => 'https://dk-parts.com.ua/ru',
            'erazborka' => 'https://erazborka.com.ua/catalog/',
            'toprazborka' => 'https://toprazborka.com.ua/',
            'teslawestparts' => 'https://teslawestparts.com.ua',
            'teslacompany' => 'https://teslacompany.com.ua/category/tesla-model-y-552772/',
        ][$source] ?? null;
    }
}
