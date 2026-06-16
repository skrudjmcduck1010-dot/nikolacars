<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PartCatalogSourceQueryService
{
    public function sourceFilteredQuery(Builder $query, ?string $source, bool $hideNikolaCarsSold = true): Builder
    {
        if ($source === 'tesla_official' && $query->getModel() instanceof PartCatalogItem) {
            $this->whereRealTeslaOfficialItem($query);

            return $query;
        }

        if ($source === 'tesla_official' && $query->getModel() instanceof PartCatalogCategory) {
            $query->where('source', 'tesla_official');

            if (PartCatalogCategory::query()
                ->where('source', 'tesla_official')
                ->where('source_url', 'like', 'tesla-official://%')
                ->exists()) {
                $query->where('source_url', 'like', 'tesla-official://%');
            }

            return $query;
        }

        $query->where('source', $this->catalogSourceValue($source));

        if ($source === 'nikolacars' && $hideNikolaCarsSold && $query->getModel() instanceof PartCatalogItem) {
            $stockQuantity = $this->jsonTextExpression('raw_attributes', 'stock_quantity');

            $query->where(function (Builder $builder): void {
                $builder
                    ->whereNull('raw_attributes')
                    ->orWhere('raw_attributes', 'not like', '%"manual_sold_at"%');
            });
            $query->where(function (Builder $builder) use ($stockQuantity): void {
                $builder
                    ->whereDoesntHave('sales', fn (Builder $salesQuery) => $salesQuery
                        ->where('source', NikolaCarsInventoryService::SOURCE))
                    ->orWhereRaw("cast(coalesce({$stockQuantity}, '0') as decimal(12,3)) > 0");
            });
            $query->where(function (Builder $builder): void {
                $builder
                    ->whereNull('raw_attributes')
                    ->orWhere(function (Builder $activeQuery): void {
                        foreach ([Product::STORAGE_STATUS_SOLD, Product::STORAGE_STATUS_WRITTEN_OFF] as $status) {
                            $activeQuery
                                ->where('raw_attributes', 'not like', '%"storage_status":"'.$status.'"%')
                                ->where('raw_attributes', 'not like', '%"storage_status": "'.$status.'"%');
                        }
                    });
            });
            $query->where(function (Builder $builder): void {
                $builder
                    ->whereNull('quality')
                    ->orWhere('quality', '!=', NikolaCarsProductInventorySyncService::BROKEN_DAMAGE_STATUS);
            });
            $this->whereCheckedDonorInventoryStatus($query);
        }

        if ($source === 'teslapartsukraine' && $query->getModel() instanceof PartCatalogItem) {
            $this->whereTeslaPartsUkraineStoreItem($query);
        }

        return $query;
    }

    public function whereTeslaPartsUkraineStoreItem(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNotNull('raw_attributes->product_url')
                    ->orWhereNotNull('raw_attributes->listing_product_url');
            })
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('source_url')
                    ->orWhere('source_url', 'not like', '%route=tesla/catalog/product%');
            });
    }

    public function whereRealTeslaOfficialItem(Builder $query): Builder
    {
        return $query
            ->where('source_url', '>=', 'https://parts.tesla.com/')
            ->where('source_url', '<', 'https://parts.tesla.com0')
            ->where('source_url', 'not like', 'https://parts.tesla.com/%?vin=%')
            ->where('source_url', 'not like', 'https://parts.tesla.com/%&vin=%');
    }

    public function isRealTeslaOfficialItem(PartCatalogItem $item): bool
    {
        $sourceUrl = (string) $item->source_url;

        return Str::startsWith($sourceUrl, 'https://parts.tesla.com/')
            && ! Str::contains($sourceUrl, ['?vin=', '&vin=']);
    }

    public function catalogSourceValue(?string $source): string
    {
        return $source ?? 'tesla_official';
    }

    private function whereCheckedDonorInventoryStatus(Builder $query): void
    {
        $checkedStatuses = NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES;
        $productId = $this->jsonTextExpression('part_catalog_items.raw_attributes', 'product_id');
        $productIdCast = match (DB::connection()->getDriverName()) {
            'pgsql' => "cast({$productId} as bigint)",
            'sqlite' => "cast({$productId} as integer)",
            default => "cast({$productId} as unsigned)",
        };
        $donorDamageStatus = $this->jsonTextExpression('part_catalog_items.raw_attributes', 'donor_damage_status');
        $projectedStatus = "coalesce(nullif(trim(part_catalog_items.quality), ''), nullif(trim({$donorDamageStatus}), ''))";

        $query->where(function (Builder $builder) use ($checkedStatuses, $productId, $productIdCast, $projectedStatus): void {
            $builder
                ->where(function (Builder $nonDonor): void {
                    $nonDonor
                        ->where(function (Builder $sourceUrl): void {
                            $sourceUrl
                                ->whereNull('part_catalog_items.source_url')
                                ->orWhere('part_catalog_items.source_url', 'not like', 'nikolacars://donor-product/%');
                        })
                        ->where(function (Builder $rawAttributes): void {
                            $rawAttributes
                                ->whereNull('part_catalog_items.raw_attributes')
                                ->orWhere(function (Builder $raw): void {
                                    $raw
                                        ->where('part_catalog_items.raw_attributes', 'not like', '%"source_type":"donor"%')
                                        ->where('part_catalog_items.raw_attributes', 'not like', '%"source_type": "donor"%')
                                        ->where('part_catalog_items.raw_attributes', 'not like', '%"donor_car_id"%');
                                });
                        });
                })
                ->orWhere(function (Builder $donor): void {
                    $donor
                        ->where('part_catalog_items.source_url', 'like', 'nikolacars://donor-product/%')
                        ->orWhere('part_catalog_items.raw_attributes', 'like', '%"source_type":"donor"%')
                        ->orWhere('part_catalog_items.raw_attributes', 'like', '%"source_type": "donor"%')
                        ->orWhere('part_catalog_items.raw_attributes', 'like', '%"donor_car_id"%');
                })
                ->where(function (Builder $status) use ($checkedStatuses, $productId, $productIdCast, $projectedStatus): void {
                    $status
                        ->whereExists(function ($exists) use ($checkedStatuses, $productIdCast): void {
                            $exists
                                ->selectRaw('1')
                                ->from('products')
                                ->whereRaw("products.id = {$productIdCast}")
                                ->whereIn('products.notes', $checkedStatuses);
                        })
                        ->orWhere(function (Builder $legacy) use ($checkedStatuses, $productId, $projectedStatus): void {
                            $legacy
                                ->whereRaw("coalesce({$productId}, '') = ''")
                                ->whereRaw("{$projectedStatus} in (?, ?, ?)", $checkedStatuses);
                        });
                });
        });
    }

    public function whereNameSource(Builder $query, string $site): Builder
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return $query->where(function (Builder $builder) use ($site): void {
                $builder
                    ->where('raw_attributes', 'like', '%"name_source_site":"'.$site.'"%')
                    ->orWhere('raw_attributes', 'like', '%"name_source_site": "'.$site.'"%');
            });
        }

        return $query->whereRaw("json_unquote(json_extract(raw_attributes, '$.name_source_site')) = ?", [$site]);
    }

    private function jsonTextExpression(string $column, string $key): string
    {
        $path = '$.'.$key;

        return match (DB::connection()->getDriverName()) {
            'pgsql' => "{$column}::jsonb ->> '{$key}'",
            'sqlite' => "json_extract({$column}, '{$path}')",
            default => "json_unquote(json_extract({$column}, '{$path}'))",
        };
    }
}
