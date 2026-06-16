<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use Illuminate\Database\Eloquent\Builder;

class NikolaCarsOfficialPartMatcher
{
    public function match(PartCatalogItem|string $itemOrPartNumber, array $options = []): NikolaCarsOfficialPartMatch
    {
        $partNumber = $itemOrPartNumber instanceof PartCatalogItem
            ? (string) $itemOrPartNumber->part_number
            : $itemOrPartNumber;
        $normalizedPartNumber = app(NikolaCarsInventoryService::class)->normalizePartNumber($partNumber);

        if (! app(NikolaCarsInventoryService::class)->isTeslaPartNumberShape($normalizedPartNumber)) {
            return new NikolaCarsOfficialPartMatch(
                officialItem: null,
                matchType: NikolaCarsOfficialPartMatch::TYPE_NONE,
                normalizedPartNumber: $normalizedPartNumber,
                partPrefix: null,
            );
        }

        $partPrefix = preg_match('/^(\d{7})/', $normalizedPartNumber, $matches) === 1
            ? $matches[1]
            : null;

        if ($partPrefix === null) {
            return new NikolaCarsOfficialPartMatch(
                officialItem: null,
                matchType: NikolaCarsOfficialPartMatch::TYPE_NONE,
                normalizedPartNumber: $normalizedPartNumber,
                partPrefix: null,
            );
        }

        $exact = $this->officialItemsQuery($options)
            ->where('part_number', $normalizedPartNumber)
            ->tap(fn (Builder $query): Builder => $this->preferCanonicalOfficialItems($query))
            ->first();

        if ($exact instanceof PartCatalogItem) {
            return new NikolaCarsOfficialPartMatch(
                officialItem: $exact,
                matchType: NikolaCarsOfficialPartMatch::TYPE_EXACT,
                normalizedPartNumber: $normalizedPartNumber,
                partPrefix: $partPrefix,
            );
        }

        $fallback = $this->officialItemsQuery($options)
            ->where('part_number', 'like', $partPrefix.'%')
            ->tap(fn (Builder $query): Builder => $this->preferCanonicalOfficialItems($query))
            ->first();

        if ($fallback instanceof PartCatalogItem) {
            return new NikolaCarsOfficialPartMatch(
                officialItem: $fallback,
                matchType: NikolaCarsOfficialPartMatch::TYPE_SEVEN_DIGIT_PREFIX,
                normalizedPartNumber: $normalizedPartNumber,
                partPrefix: $partPrefix,
            );
        }

        return new NikolaCarsOfficialPartMatch(
            officialItem: null,
            matchType: NikolaCarsOfficialPartMatch::TYPE_NONE,
            normalizedPartNumber: $normalizedPartNumber,
            partPrefix: $partPrefix,
        );
    }

    protected function officialItemsQuery(array $options): Builder
    {
        $query = PartCatalogItem::query()
            ->with(['category.parent.parent.parent.parent', 'occurrences.category.parent.parent.parent.parent'])
            ->where('source', 'tesla_official');

        if ((bool) ($options['require_category_data'] ?? false)) {
            $query->where(function (Builder $builder): void {
                $builder
                    ->whereNotNull('part_catalog_category_id')
                    ->orWhereNotNull('main_category_name')
                    ->orWhereHas('occurrences', fn (Builder $occurrenceQuery) => $occurrenceQuery->whereNotNull('part_catalog_category_id'));
            });
        }

        return $query;
    }

    protected function preferCanonicalOfficialItems(Builder $query): Builder
    {
        return $query
            ->orderByRaw("case when source_url like ? then 1 else 0 end", ['%vin=%'])
            ->orderByRaw("case when raw_attributes like ? then 1 else 0 end", ['%"donor_vin"%'])
            ->orderByRaw('part_catalog_category_id is null')
            ->orderBy('id');
    }
}
