<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartCatalogItem;
use App\Models\PartSale;
use App\Models\Product;
use App\Services\NikolaCarsInventoryService;
use App\Support\PartCatalogRawAttributes;
use App\View\Admin\DonorCars\DonorPartDisplayPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class NikolaCarsSaleController extends Controller
{
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));
        $matched = $request->query('matched', '');
        $duplicateManualProductSaleIds = $this->duplicateManualProductSaleIds();
        $donorPartPresenter = app(DonorPartDisplayPresenter::class);

        $salesQuery = PartSale::query()
            ->with([
                'partCatalogItem:id,part_number,name,name_ua,name_ru,price_amount,currency,raw_attributes',
                'donorCar:id,vin,model,year,color',
            ])
            ->where('source', 'nikolacars')
            ->when($duplicateManualProductSaleIds !== [], fn (Builder $builder) => $builder->whereNotIn('id', $duplicateManualProductSaleIds))
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $like = '%'.$query.'%';
                $builder->where(function (Builder $nested) use ($like): void {
                    $nested
                        ->where('code', 'like', $like)
                        ->orWhere('part_number', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('donor_vin', 'like', $like)
                        ->orWhere('document_number', 'like', $like)
                        ->orWhere('counterparty', 'like', $like);
                });
            })
            ->when($from !== '', fn (Builder $builder) => $builder->whereDate('sold_at', '>=', $from))
            ->when($to !== '', fn (Builder $builder) => $builder->whereDate('sold_at', '<=', $to))
            ->when($matched === 'yes', fn (Builder $builder) => $builder->whereNotNull('product_id'))
            ->when($matched === 'no', fn (Builder $builder) => $builder->whereNull('product_id'))
            ->orderByDesc('sold_at')
            ->orderByDesc('id');

        $summary = PartSale::query()
            ->where('source', 'nikolacars')
            ->when($duplicateManualProductSaleIds !== [], fn (Builder $builder) => $builder->whereNotIn('id', $duplicateManualProductSaleIds))
            ->selectRaw('count(*) as sales_count')
            ->selectRaw('coalesce(sum(quantity), 0) as quantity_sum')
            ->selectRaw('coalesce(sum(quantity * coalesce(unit_price, 0)), 0) as amount_sum')
            ->selectRaw('sum(case when product_id is null then 1 else 0 end) as unmatched_count')
            ->first();

        $sales = $salesQuery->paginate(50)->withQueryString();
        $saleProductIds = collect($sales->items())
            ->flatMap(fn (PartSale $sale): array => $donorPartPresenter->saleProductIdCandidates($sale))
            ->filter()
            ->unique()
            ->values();
        $saleCatalogItemIds = collect($sales->items())
            ->pluck('part_catalog_item_id')
            ->filter()
            ->unique()
            ->values();

        $saleProductsQuery = Product::query()
            ->with(['sourcePartCatalogItem:id,part_number,name,name_ua,name_ru,source'])
            ->select(['id', 'sku', 'external_sku', 'name', 'source_part_catalog_item_id']);

        $saleProducts = $saleProductIds->isEmpty() && $saleCatalogItemIds->isEmpty()
            ? collect()
            : $saleProductsQuery
                ->where(function (Builder $query) use ($saleProductIds, $saleCatalogItemIds): void {
                    if ($saleProductIds->isNotEmpty()) {
                        $query->whereIn('id', $saleProductIds);
                    }

                    if ($saleCatalogItemIds->isNotEmpty()) {
                        $method = $saleProductIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('source_part_catalog_item_id', $saleCatalogItemIds);
                    }
                })
                ->get()
                ->keyBy('id');
        $saleProductsByCatalogItem = $saleProducts
            ->whereNotNull('source_part_catalog_item_id')
            ->keyBy('source_part_catalog_item_id');

        return view('admin.nikolacars_sales.index', [
            'sales' => $sales,
            'saleProducts' => $saleProducts,
            'saleProductsByCatalogItem' => $saleProductsByCatalogItem,
            'summary' => $summary,
            'query' => $query,
            'from' => $from,
            'to' => $to,
            'matched' => $matched,
            'currency' => 'USD',
            'driver' => DB::connection()->getDriverName(),
        ]);
    }

    public function cancelManualSoldBeforeJune(PartSale $partSale): RedirectResponse
    {
        abort_unless($this->isManualSoldBeforeJuneSale($partSale), 404);

        DB::transaction(function () use ($partSale): void {
            $item = $partSale->partCatalogItem;
            $saleIdsToDelete = collect([$partSale->id])
                ->merge($this->duplicateManualSiblingSaleIds($partSale, $item))
                ->unique()
                ->values();

            if ($item) {
                $rawAttributes = PartCatalogRawAttributes::from($item);
                $restoredQuantity = data_get($rawAttributes, 'stock_quantity_before_manual_sold');
                $restoredQuantity = $restoredQuantity !== null && $restoredQuantity !== ''
                    ? round((float) $restoredQuantity, 3)
                    : max(1.0, (float) $partSale->quantity);

                unset(
                    $rawAttributes['manual_sold_at'],
                    $rawAttributes['manual_sold_note'],
                    $rawAttributes['stock_quantity_before_manual_sold']
                );

                $rawAttributes['stock_quantity'] = $restoredQuantity;
                $rawAttributes['storage_status'] = data_get($rawAttributes, 'donor_vin')
                    ? Product::STORAGE_STATUS_ON_DONOR
                    : Product::STORAGE_STATUS_IN_STOCK;

                $item->forceFill([
                    'availability' => app(NikolaCarsInventoryService::class)->availability($restoredQuantity),
                    'raw_attributes' => $rawAttributes,
                ])->save();

                $productId = (int) ($partSale->product_id ?: data_get($rawAttributes, 'product_id'));
                Product::query()
                    ->where(function (Builder $query) use ($item, $productId): void {
                        $query->where('source_part_catalog_item_id', $item->id);

                        if ($productId > 0) {
                            $query->orWhere('id', $productId);
                        }
                    })
                    ->get()
                    ->each(function (Product $product): void {
                        $product->forceFill([
                            'storage_status' => $product->donor_car_id !== null
                                ? Product::STORAGE_STATUS_ON_DONOR
                                : Product::STORAGE_STATUS_IN_STOCK,
                            'is_active' => true,
                        ])->save();
                    });
            }

            if (! $item) {
                $saleRawAttributes = PartCatalogRawAttributes::fromValue($partSale->raw_attributes);
                $productId = (int) ($partSale->product_id ?: data_get($saleRawAttributes, 'product_id'));

                if ($productId > 0) {
                    Product::query()
                        ->where('id', $productId)
                        ->get()
                        ->each(function (Product $product): void {
                            $product->forceFill([
                                'storage_status' => $product->donor_car_id !== null
                                    ? Product::STORAGE_STATUS_ON_DONOR
                                    : Product::STORAGE_STATUS_IN_STOCK,
                                'is_active' => true,
                            ])->save();
                        });
                }
            }

            PartSale::query()
                ->whereIn('id', $saleIdsToDelete)
                ->delete();
        });

        return redirect()
            ->route('admin.nikolacars-sales.index')
            ->with('status', "\u{041F}\u{0440}\u{043E}\u{0434}\u{0430}\u{0436}\u{0430} \u{043E}\u{0442}\u{043C}\u{0435}\u{043D}\u{0435}\u{043D}\u{0430}, \u{0437}\u{0430}\u{043F}\u{0447}\u{0430}\u{0441}\u{0442}\u{044C} \u{0432}\u{0435}\u{0440}\u{043D}\u{0443}\u{0442}\u{0430} \u{0432} NikolaCars.");
    }

    protected function isManualSoldBeforeJuneSale(PartSale $partSale): bool
    {
        return $partSale->source === 'nikolacars'
            && $partSale->document_number === 'manual-sold-before-june-2026'
            && $partSale->source_file === 'manual-zapchasti-cleanup'
            && str_starts_with((string) $partSale->source_row_hash, 'manual-sold-before-june-2026-');
    }

    protected function duplicateManualProductSaleIds(): array
    {
        $sales = PartSale::query()
            ->with('partCatalogItem:id,raw_attributes')
            ->where('source', 'nikolacars')
            ->where('document_number', 'manual-sold-before-june-2026')
            ->where('source_file', 'manual-zapchasti-cleanup')
            ->where(function (Builder $query): void {
                $query
                    ->where('source_row_hash', 'like', 'manual-sold-before-june-2026-%')
                    ->orWhere('source_row_hash', 'like', 'manual-sold-before-june-2026-product-%');
            })
            ->get([
                'id',
                'part_catalog_item_id',
                'code',
                'part_number',
                'quantity',
                'unit_price',
                'sold_at',
                'raw_attributes',
                'source_row_hash',
            ]);

        return $sales
            ->map(function (PartSale $sale): array {
                $productId = (int) (
                    $sale->product_id
                    ?: data_get($sale->raw_attributes, 'product_id')
                    ?: data_get($sale->partCatalogItem?->raw_attributes, 'product_id')
                );

                return [
                    'sale' => $sale,
                    'product_id' => $productId,
                    'key' => implode('|', [
                        $productId,
                        $sale->sold_at?->toDateString() ?: '',
                        trim((string) $sale->code),
                        trim((string) $sale->part_number),
                        (string) $sale->quantity,
                        (string) $sale->unit_price,
                    ]),
                ];
            })
            ->filter(fn (array $row): bool => $row['product_id'] > 0)
            ->groupBy('key')
            ->flatMap(function ($rows) {
                $hasLinkedSale = $rows->contains(fn (array $row): bool => $row['sale']->part_catalog_item_id !== null);

                if (! $hasLinkedSale) {
                    return [];
                }

                return $rows
                    ->filter(fn (array $row): bool => $row['sale']->part_catalog_item_id === null
                        && str_starts_with((string) $row['sale']->source_row_hash, 'manual-sold-before-june-2026-product-'))
                    ->map(fn (array $row): int => (int) $row['sale']->id);
            })
            ->values()
            ->all();
    }

    protected function duplicateManualSiblingSaleIds(PartSale $partSale, ?PartCatalogItem $item): array
    {
        $productId = $this->manualSaleProductId($partSale, $item);

        if ($productId <= 0) {
            return [];
        }

        $signature = $this->manualSaleDuplicateSignature($partSale);

        return PartSale::query()
            ->with('partCatalogItem:id,raw_attributes')
            ->where('id', '!=', $partSale->id)
            ->where('source', 'nikolacars')
            ->where('document_number', 'manual-sold-before-june-2026')
            ->where('source_file', 'manual-zapchasti-cleanup')
            ->where(function (Builder $query): void {
                $query
                    ->where('source_row_hash', 'like', 'manual-sold-before-june-2026-%')
                    ->orWhere('source_row_hash', 'like', 'manual-sold-before-june-2026-product-%');
            })
            ->get([
                'id',
                'part_catalog_item_id',
                'code',
                'part_number',
                'quantity',
                'unit_price',
                'sold_at',
                'raw_attributes',
                'source_row_hash',
            ])
            ->filter(fn (PartSale $sale): bool => $this->manualSaleProductId($sale, $sale->partCatalogItem) === $productId)
            ->filter(fn (PartSale $sale): bool => $this->manualSaleDuplicateSignature($sale) === $signature)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    protected function manualSaleProductId(PartSale $sale, ?PartCatalogItem $item = null): int
    {
        $productId = (int) (
            $sale->product_id
            ?: data_get($sale->raw_attributes, 'product_id')
            ?: data_get($item?->raw_attributes, 'product_id')
        );

        if ($productId > 0) {
            return $productId;
        }

        $sourceRowHash = (string) $sale->source_row_hash;
        if (preg_match('/^manual-sold-before-june-2026-product-(\d+)$/', $sourceRowHash, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    protected function manualSaleDuplicateSignature(PartSale $sale): string
    {
        return implode('|', [
            $sale->sold_at?->toDateString() ?: '',
            trim((string) $sale->code),
            trim((string) $sale->part_number),
            (string) $sale->quantity,
            (string) $sale->unit_price,
        ]);
    }
}
