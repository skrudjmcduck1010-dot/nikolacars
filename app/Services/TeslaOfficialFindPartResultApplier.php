<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TeslaOfficialFindPartResultApplier
{
    public function apply(array $result, bool $dryRun = false): bool
    {
        $partNumber = $this->normalizePartNumber((string) ($result['part_number'] ?? ''));

        if ($partNumber === '') {
            return false;
        }

        $item = PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->whereNotNull('part_number')
            ->whereRaw("replace(replace(upper(part_number), '-', ''), ' ', '') = ?", [$this->compactPartNumber($partNumber)])
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->orderByRaw("case when source_url like '%/find-part?%' then 0 else 1 end")
            ->orderBy('id')
            ->first();

        if (! $item instanceof PartCatalogItem) {
            $item = PartCatalogItem::query()
                ->where('source', 'tesla_official')
                ->whereNotNull('part_number')
                ->whereRaw("replace(replace(upper(part_number), '-', ''), ' ', '') = ?", [$this->compactPartNumber($partNumber)])
                ->where('source_url', 'like', 'tesla-common://donor-product/%')
                ->orderBy('id')
                ->first();
        }

        if (! $item instanceof PartCatalogItem) {
            return false;
        }

        if ($dryRun) {
            return true;
        }

        $rows = collect((array) ($result['related_matches'] ?? []))
            ->filter(fn (mixed $row): bool => is_array($row) && trim((string) ($row['part_number'] ?? '')) !== '')
            ->map(fn (array $row): array => [
                'part_number' => $this->normalizePartNumber((string) ($row['part_number'] ?? '')),
                'description' => trim((string) ($row['description'] ?? $row['title'] ?? '')) ?: null,
                'localized_description' => trim((string) ($row['localized_description'] ?? '')) ?: null,
                'model' => trim((string) ($row['model'] ?? '')) ?: null,
                'category' => trim((string) ($row['category'] ?? '')) ?: null,
                'subcategory' => trim((string) ($row['subcategory'] ?? '')) ?: null,
                'group' => trim((string) ($row['group'] ?? '')) ?: null,
                'visibility' => trim((string) ($row['visibility'] ?? '')) ?: null,
                'source' => trim((string) ($row['source'] ?? '')) ?: null,
            ])
            ->values();
        $rows = $this->withHiddenOfficialCatalogRows($rows);

        if ($rows->isEmpty()) {
            $rows = $this->savedOfficialCatalogRowsForItem($item, $partNumber);
        }

        $exactPartNumbers = $rows
            ->pluck('part_number')
            ->filter(fn (string $value): bool => $this->compactPartNumber($value) === $this->compactPartNumber($partNumber))
            ->unique()
            ->values();
        $similarPartNumbers = $rows
            ->pluck('part_number')
            ->filter(fn (string $value): bool => $this->compactPartNumber($value) !== $this->compactPartNumber($partNumber))
            ->unique()
            ->values();
        $status = (string) ($result['status'] ?? 'api_error');
        if (! in_array($status, ['api_error', 'auth_required', 'security_blocked', 'exact', 'similar'], true)) {
            $status = $exactPartNumbers->isNotEmpty()
                ? 'exact'
                : ($similarPartNumbers->isNotEmpty() ? 'similar' : $status);
        }

        $raw = PartCatalogRawAttributes::from($item);
        $raw['find_part_found_by_requested_part_numbers'] = collect((array) ($raw['find_part_found_by_requested_part_numbers'] ?? []))
            ->map(fn (mixed $value): string => $this->normalizePartNumber((string) $value))
            ->filter(fn (string $value): bool => $this->compactPartNumber($value) !== $this->compactPartNumber($partNumber))
            ->unique()
            ->values()
            ->all();

        $raw['official_part_match_status'] = $status;
        $hasSavedOfficialCatalogData = $this->hasSavedOfficialCatalogData($raw);
        $raw['official_presence'] = match ($status) {
            'exact' => 'part_search_exact',
            'similar' => 'part_search_similar',
            'not_found' => $hasSavedOfficialCatalogData ? 'official_catalog_exact' : 'not_found',
            'auth_required' => $hasSavedOfficialCatalogData ? 'official_catalog_exact' : 'part_search_auth_required',
            'security_blocked' => $hasSavedOfficialCatalogData ? 'official_catalog_exact' : 'part_search_security_blocked',
            default => $hasSavedOfficialCatalogData ? 'official_catalog_exact' : 'part_search_api_error',
        };
        $raw['tesla_part_search_error'] = collect((array) ($result['errors'] ?? []))
            ->map(fn (mixed $error): string => is_array($error)
                ? trim((string) ($error['error'] ?? $error['status'] ?? json_encode($error, JSON_UNESCAPED_UNICODE)))
                : trim((string) $error))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $raw['tesla_part_search_requested_part_number'] = $partNumber;
        $raw['tesla_part_search_primary_part_number'] = $exactPartNumbers->first() ?: $similarPartNumbers->first();
        $raw['tesla_part_search_exact_part_numbers'] = $exactPartNumbers->all();
        $raw['tesla_part_search_similar_part_numbers'] = $similarPartNumbers->all();
        $raw['tesla_part_search_related_part_numbers'] = $rows->pluck('part_number')->unique()->values()->all();
        $raw['tesla_part_search_results'] = $rows->all();
        $raw['tesla_part_search_url'] = $result['url'] ?? null;
        $raw['tesla_part_search_checked_at'] = now()->toIso8601String();

        $item->forceFill([
            'raw_attributes' => $raw,
            'source_updated_at' => now(),
        ])->save();

        $rows
            ->pluck('part_number')
            ->filter(fn (string $relatedPartNumber): bool => $this->compactPartNumber($relatedPartNumber) !== $this->compactPartNumber($partNumber))
            ->unique()
            ->each(fn (string $relatedPartNumber): bool => $this->rememberFoundByRequestedPartNumber($relatedPartNumber, $partNumber));

        return true;
    }

    protected function rememberFoundByRequestedPartNumber(string $partNumber, string $requestedPartNumber): bool
    {
        $item = PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->whereNotNull('part_number')
            ->whereRaw("replace(replace(upper(part_number), '-', ''), ' ', '') = ?", [$this->compactPartNumber($partNumber)])
            ->orderByRaw("case when source_url like '%/find-part?%' then 0 else 1 end")
            ->orderBy('id')
            ->first();

        if (! $item instanceof PartCatalogItem) {
            return false;
        }

        $raw = PartCatalogRawAttributes::from($item);
        $raw['find_part_found_by_requested_part_numbers'] = collect((array) ($raw['find_part_found_by_requested_part_numbers'] ?? []))
            ->push($requestedPartNumber)
            ->map(fn (mixed $value): string => $this->normalizePartNumber((string) $value))
            ->filter(fn (string $value): bool => $value !== '' && $this->compactPartNumber($value) !== $this->compactPartNumber((string) $item->part_number))
            ->unique()
            ->values()
            ->all();

        $item->forceFill([
            'raw_attributes' => $raw,
            'source_updated_at' => now(),
        ])->save();

        return true;
    }

    protected function hasSavedOfficialCatalogData(array $raw): bool
    {
        if (collect((array) ($raw['official_catalog_occurrences'] ?? []))->filter(fn (mixed $row): bool => is_array($row))->isNotEmpty()) {
            return true;
        }

        return collect([
            $raw['catalog_external_reference'] ?? null,
            $raw['category_external_reference'] ?? null,
            $raw['subcategory_external_reference'] ?? null,
            $raw['system_group_external_reference'] ?? null,
        ])->contains(fn (mixed $value): bool => trim((string) $value) !== '');
    }

    protected function withHiddenOfficialCatalogRows(Collection $rows): Collection
    {
        $partNumbers = $rows
            ->pluck('part_number')
            ->filter()
            ->unique()
            ->values();

        if ($partNumbers->isEmpty()) {
            return $rows;
        }

        $visibleKeys = $rows
            ->mapWithKeys(fn (array $row): array => [$this->resultKey($row) => true]);
        $descriptionsByPartNumber = $rows
            ->mapWithKeys(fn (array $row): array => [$row['part_number'] => $row['description'] ?? null]);
        $hiddenRows = collect();

        PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->whereIn('part_number', $partNumbers->all())
            ->get()
            ->each(function (PartCatalogItem $item) use ($visibleKeys, $descriptionsByPartNumber, $hiddenRows): void {
                $raw = PartCatalogRawAttributes::from($item);
                $occurrences = collect((array) data_get($raw, 'official_catalog_occurrences', []))
                    ->filter(fn (mixed $row): bool => is_array($row));

                if ($occurrences->isEmpty()) {
                    $occurrences = collect([[
                        'model_label' => $item->model_label,
                        'main_category_code' => $item->main_category_code,
                        'main_category_name' => $item->main_category_name,
                        'subcategory_code' => $item->subcategory_code,
                        'subcategory_name' => $item->subcategory_name,
                        'node_name' => $item->node_name,
                    ]]);
                }

                foreach ($occurrences as $occurrence) {
                    $row = [
                        'part_number' => $this->normalizePartNumber((string) $item->part_number),
                        'description' => $descriptionsByPartNumber[$item->part_number] ?? $item->name_en ?: $item->name ?: null,
                        'localized_description' => null,
                        'model' => trim((string) ($occurrence['model_label'] ?? $occurrence['model_name'] ?? $item->model_label ?? $item->model_name ?? '')) ?: null,
                        'category' => $this->codeName($occurrence['main_category_code'] ?? null, $occurrence['main_category_name'] ?? null),
                        'subcategory' => $this->codeName($occurrence['subcategory_code'] ?? null, $occurrence['subcategory_name'] ?? null),
                        'group' => trim((string) ($occurrence['node_name'] ?? $item->node_name ?? '')) ?: null,
                        'visibility' => 'saved_official_catalog',
                        'source' => 'saved_official_catalog',
                    ];

                    if ($visibleKeys->has($this->resultKey($row))) {
                        continue;
                    }

                    $hiddenRows->push($row);
                    $visibleKeys[$this->resultKey($row)] = true;
                }
            });

        return $rows
            ->merge($hiddenRows)
            ->values();
    }

    protected function savedOfficialCatalogRowsForItem(PartCatalogItem $item, string $partNumber): Collection
    {
        $raw = PartCatalogRawAttributes::from($item);
        $occurrences = collect((array) data_get($raw, 'official_catalog_occurrences', []))
            ->filter(fn (mixed $row): bool => is_array($row));

        if ($occurrences->isEmpty() && $this->hasSavedOfficialCatalogData($raw)) {
            $occurrences = collect([[
                'model_label' => $item->model_label,
                'main_category_code' => $item->main_category_code,
                'main_category_name' => $item->main_category_name,
                'subcategory_code' => $item->subcategory_code,
                'subcategory_name' => $item->subcategory_name,
                'node_name' => $item->node_name,
            ]]);
        }

        return $occurrences
            ->map(fn (array $occurrence): array => [
                'part_number' => $this->normalizePartNumber($partNumber),
                'description' => $this->partNameWithAnnotation($item->name_en ?: $item->name ?: '', (string) ($raw['annotation'] ?? '')),
                'localized_description' => null,
                'model' => trim((string) ($occurrence['model_label'] ?? $occurrence['model_name'] ?? $item->model_label ?? $item->model_name ?? '')) ?: null,
                'category' => $this->codeName($occurrence['main_category_code'] ?? null, $occurrence['main_category_name'] ?? null),
                'subcategory' => $this->codeName($occurrence['subcategory_code'] ?? null, $occurrence['subcategory_name'] ?? null),
                'group' => trim((string) ($occurrence['node_name'] ?? $item->node_name ?? '')) ?: null,
                'visibility' => 'saved_official_catalog',
                'source' => 'saved_official_catalog',
            ])
            ->unique(fn (array $row): string => $this->resultKey($row))
            ->values();
    }

    protected function resultKey(array $row): string
    {
        return implode('|', [
            $this->compactPartNumber((string) ($row['part_number'] ?? '')),
            $this->normalizeModelForKey((string) ($row['model'] ?? '')),
            trim((string) ($row['category'] ?? '')),
            trim((string) ($row['subcategory'] ?? '')),
            trim((string) ($row['group'] ?? '')),
        ]);
    }

    protected function partNameWithAnnotation(string $name, string $annotation): ?string
    {
        $name = trim($name);
        $annotation = trim($annotation);

        if ($name === '') {
            return null;
        }

        if ($annotation === '' || ! ctype_digit($annotation)) {
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

    protected function normalizeModelForKey(string $value): string
    {
        $value = trim($value);

        return match ($value) {
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
            default => $value,
        };
    }

    protected function codeName(mixed $code, mixed $name): ?string
    {
        $code = trim((string) $code);
        $name = trim((string) $name);

        if ($code !== '' && $name !== '') {
            return "{$code} - {$name}";
        }

        return $name !== '' ? $name : null;
    }

    protected function normalizePartNumber(string $value): string
    {
        $value = Str::upper(trim($value));

        if (preg_match('/\b(\d{7})[-\s]?([A-Z0-9]{2})[-\s]?([A-Z0-9])\b/', $value, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return $value;
    }

    protected function compactPartNumber(string $value): string
    {
        return Str::upper(preg_replace('/[^A-Z0-9]/i', '', $value) ?: '');
    }
}
