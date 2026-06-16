<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\CashTransaction;
use App\Models\Counterparty;
use App\Models\Location;
use App\Models\PartCatalogCategory;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    private const PARTS_PURCHASE_LABEL = 'Закупка ЗЧК';

    public function __construct(private readonly StockService $stockService) {}

    public function index(): View
    {
        return view('admin.purchases.index', [
            'purchaseItems' => PurchaseItem::query()
                ->select('purchase_items.*')
                ->join('purchases', 'purchase_items.purchase_id', '=', 'purchases.id')
                ->with(['purchase.counterparty', 'purchase.cashTransaction', 'product', 'warehouse', 'location', 'stockItem'])
                ->orderByDesc('purchases.purchase_date')
                ->orderByDesc('purchase_items.id')
                ->paginate(30),
        ]);
    }

    public function create(): View
    {
        return view('admin.purchases.form', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedPayload($request);

        $purchase = DB::transaction(function () use ($validated): Purchase {
            $counterpartyId = $this->resolveCounterpartyId($validated);
            $transaction = CashTransaction::query()->create($this->cashTransactionPayload($validated));
            $items = collect($validated['items']);
            $firstItem = $items->first();
            $totalUsd = $items->sum(fn (array $item): float => (float) ($item['purchase_price_usd'] ?? 0) * (int) $item['quantity']);
            $totalUah = $items->sum(fn (array $item): float => (float) ($item['purchase_price_uah'] ?? 0) * (int) $item['quantity']);
            $currency = $totalUsd > 0 ? 'USD' : 'UAH';
            $totalAmount = $totalUsd > 0 ? $totalUsd : $totalUah;

            $purchase = Purchase::query()->create([
                'cash_transaction_id' => $transaction->id,
                'counterparty_id' => $counterpartyId,
                'warehouse_id' => $firstItem['warehouse_id'] ?? null,
                'purchase_date' => Carbon::parse($validated['operation_date'] ?? now())->toDateString(),
                'document_number' => trim((string) ($validated['document_number'] ?? '')) ?: null,
                'status' => 'posted',
                'currency' => $currency,
                'total_amount' => $totalAmount,
                'comment' => trim((string) ($validated['comment'] ?? '')) ?: null,
            ]);

            foreach ($items as $item) {
                $product = $this->resolveProductForItem($item);
                $this->appendPhotosToExistingProduct($product, $item);
                $purchasePrice = (float) ($item['purchase_price_usd'] ?? 0) > 0
                    ? (float) $item['purchase_price_usd']
                    : (float) ($item['purchase_price_uah'] ?? 0);
                $sellingPrice = (float) ($item['selling_price_usd'] ?? 0);
                $itemCurrency = (float) ($item['purchase_price_usd'] ?? 0) > 0 ? 'USD' : 'UAH';
                $locationId = $this->resolveLocationIdForItem($item);

                $stockItem = $this->stockService->intake([
                    'product_id' => $product->id,
                    'warehouse_id' => $item['warehouse_id'],
                    'location_id' => $locationId,
                    'quantity' => $item['quantity'],
                    'counterparty_id' => $counterpartyId,
                    'document_number' => $validated['document_number'] ?? null,
                    'comment' => trim((string) ($item['comment'] ?? '')) ?: $transaction->comment,
                ]);

                $purchase->items()->create([
                    'product_id' => $product->id,
                    'stock_item_id' => $stockItem->id,
                    'warehouse_id' => $item['warehouse_id'],
                    'location_id' => $locationId,
                    'quantity' => $item['quantity'],
                    'color' => trim((string) ($item['color'] ?? '')) ?: null,
                    'purchase_price' => $purchasePrice,
                    'selling_price' => $sellingPrice,
                    'currency' => $itemCurrency,
                    'comment' => trim((string) ($item['comment'] ?? '')) ?: null,
                ]);

                $product->update([
                    'condition_type' => $item['condition_type'] ?? null,
                    'purchase_price' => $purchasePrice,
                    'selling_price' => $sellingPrice,
                    'currency' => $itemCurrency,
                ]);
            }

            $purchaseDetails = $this->purchaseDetailsFromPostedItems($purchase);

            if ($purchaseDetails !== '') {
                if (trim((string) $transaction->comment) === '') {
                    $transaction->update(['comment' => $purchaseDetails]);
                }

                if (trim((string) $purchase->comment) === '') {
                    $purchase->update(['comment' => $purchaseDetails]);
                }
            }

            return $purchase;
        });

        return redirect()->route('admin.purchases.show', $purchase)->with('status', 'Закупка создана и размещена на складе.');
    }

    public function show(Purchase $purchase): View
    {
        return view('admin.purchases.show', [
            'purchase' => $purchase->load([
                'counterparty',
                'warehouse',
                'cashTransaction',
                'items.product',
                'items.stockItem',
                'items.location',
            ]),
        ]);
    }

    public function destroy(Purchase $purchase): RedirectResponse
    {
        DB::transaction(function () use ($purchase): void {
            $purchase->load(['items.stockItem', 'cashTransaction']);

            foreach ($purchase->items as $item) {
                $stockItem = $item->stockItem;

                if (! $stockItem) {
                    continue;
                }

                $stockItem->refresh();
                $quantity = (int) $item->quantity;

                if ((int) $stockItem->available_quantity < $quantity) {
                    throw ValidationException::withMessages([
                        'purchase' => 'Нельзя удалить закупку: часть товара уже продана, перемещена или зарезервирована.',
                    ]);
                }

                $stockItem->quantity = max(0, (int) $stockItem->quantity - $quantity);
                $stockItem->syncAvailableQuantity();
                $stockItem->save();
            }

            $cashTransaction = $purchase->cashTransaction;

            if ($cashTransaction && ! $cashTransaction->canBeDeleted()) {
                throw ValidationException::withMessages([
                    'purchase' => 'Нельзя удалить закупку: связанная операция кассы старше 1 суток.',
                ]);
            }

            $purchase->delete();
            $cashTransaction?->delete();
        });

        return redirect()->route('admin.purchases.index')->with('status', 'Закупка удалена.');
    }

    protected function validatedPayload(Request $request): array
    {
        $validated = $request->validate([
            'operation_date' => ['nullable', 'date'],
            'counterparty_id' => ['nullable', 'exists:counterparties,id'],
            'new_counterparty_name' => ['nullable', 'string', 'max:255'],
            'new_counterparty_phone' => ['nullable', 'string', 'max:255'],
            'new_counterparty_email' => ['nullable', 'email', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'expense_payment_method' => ['nullable', Rule::in(['cash', 'bank'])],
            'comment' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.external_sku' => ['required', 'string', 'max:255'],
            'items.*.brand_id' => ['nullable', 'exists:brands,id'],
            'items.*.model' => ['required', Rule::in($this->teslaModels($request))],
            'items.*.description' => ['nullable', 'string'],
            'items.*.color' => ['nullable', 'string', 'max:255'],
            'items.*.condition_type' => ['required', Rule::in(Product::CONDITION_TYPES)],
            'items.*.warehouse_id' => ['required', 'exists:warehouses,id'],
            'items.*.location_id' => ['nullable', 'exists:locations,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.purchase_price_uah' => ['nullable', 'numeric', 'min:0'],
            'items.*.purchase_price_usd' => ['required', 'numeric', 'gt:0'],
            'items.*.selling_price_usd' => ['required', 'numeric', 'min:0'],
            'items.*.photos' => ['nullable', 'array', 'max:5'],
            'items.*.photos.*' => ['image', 'max:10240'],
            'items.*.comment' => ['nullable', 'string'],
        ]);

        $hasPurchaseAmount = false;

        foreach ($validated['items'] as $index => $item) {
            $location = ! empty($item['location_id'])
                ? Location::query()->find($item['location_id'])
                : null;

            if ($location && (int) $location->warehouse_id !== (int) $item['warehouse_id']) {
                throw ValidationException::withMessages([
                    "items.{$index}.location_id" => 'Выбранная ячейка не относится к выбранному складу.',
                ]);
            }

            if (! $location && Location::query()->where('warehouse_id', $item['warehouse_id'])->exists()) {
                throw ValidationException::withMessages([
                    "items.{$index}.location_id" => 'The location field is required.',
                ]);
            }

            $hasPurchaseAmount = $hasPurchaseAmount
                || (float) ($item['purchase_price_uah'] ?? 0) > 0
                || (float) ($item['purchase_price_usd'] ?? 0) > 0;
        }

        if (! $hasPurchaseAmount) {
            throw ValidationException::withMessages([
                'items.0.purchase_price_usd' => 'Укажите цену закупки хотя бы в одной позиции.',
            ]);
        }

        return $validated;
    }

    protected function cashTransactionPayload(array $validated): array
    {
        $expenseUah = collect($validated['items'])->sum(
            fn (array $item): float => (float) ($item['purchase_price_uah'] ?? 0) * (int) $item['quantity']
        );
        $expenseUsd = collect($validated['items'])->sum(
            fn (array $item): float => (float) ($item['purchase_price_usd'] ?? 0) * (int) $item['quantity']
        );
        $paymentMethod = $validated['expense_payment_method'] ?? 'cash';

        return [
            'operation_date' => Carbon::parse($validated['operation_date'] ?? now())->toDateString(),
            'income_bank_uah' => 0,
            'income_cash_uah' => 0,
            'income_cash_usd' => 0,
            'expense_bank_uah' => $paymentMethod === 'bank' ? $expenseUah : 0,
            'expense_cash_uah' => $paymentMethod === 'cash' ? $expenseUah : 0,
            'expense_cash_usd' => $expenseUsd,
            'label' => self::PARTS_PURCHASE_LABEL,
            'employee' => null,
            'vehicle_vin' => null,
            'comment' => trim((string) ($validated['comment'] ?? '')) ?: $this->purchaseItemsDetails($validated['items']),
            'source' => 'manual',
            'source_sheet' => null,
        ];
    }

    protected function resolveProductForItem(array $item): Product
    {
        if (! empty($item['product_id'])) {
            return Product::query()->findOrFail($item['product_id']);
        }

        $name = trim((string) $item['name']);
        $sku = $this->nextSku();
        $photos = [];

        foreach ($item['photos'] ?? [] as $photo) {
            $photos[] = $photo->store('product-photos', 'public');
        }

        return Product::query()->create([
            'sku' => $sku,
            'external_sku' => trim((string) ($item['external_sku'] ?? '')) ?: null,
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'category_id' => null,
            'brand_id' => $item['brand_id'] ?? null,
            'description' => trim((string) ($item['description'] ?? '')) ?: null,
            'model' => trim((string) ($item['model'] ?? '')) ?: null,
            'color' => trim((string) ($item['color'] ?? '')) ?: null,
            'condition_type' => $item['condition_type'],
            'testing_status' => 'not_tested',
            'unit' => 'pcs',
            'purchase_price' => (float) ($item['purchase_price_usd'] ?? 0) > 0
                ? (float) $item['purchase_price_usd']
                : (float) ($item['purchase_price_uah'] ?? 0),
            'selling_price' => (float) ($item['selling_price_usd'] ?? 0),
            'currency' => (float) ($item['purchase_price_usd'] ?? 0) > 0 ? 'USD' : 'UAH',
            'barcode' => $sku,
            'qr_code' => $sku,
            'main_image' => $photos[0] ?? null,
            'images_json' => $photos ?: null,
            'is_active' => true,
        ]);
    }

    protected function appendPhotosToExistingProduct(Product $product, array $item): void
    {
        if (empty($item['product_id']) || empty($item['photos'])) {
            return;
        }

        $photos = $product->images_json ? (array) $product->images_json : [];

        foreach ($item['photos'] as $photo) {
            if (count($photos) >= 5) {
                break;
            }

            $photos[] = $photo->store('product-photos', 'public');
        }

        $product->update([
            'main_image' => $product->main_image ?: ($photos[0] ?? null),
            'images_json' => $photos ?: null,
        ]);
    }

    protected function resolveLocationIdForItem(array $item): int
    {
        if (! empty($item['location_id'])) {
            return (int) $item['location_id'];
        }

        $warehouseId = (int) $item['warehouse_id'];
        $fullCode = "WH-{$warehouseId}-DEFAULT";

        return Location::query()->firstOrCreate(
            ['full_code' => $fullCode],
            [
                'warehouse_id' => $warehouseId,
                'floor' => null,
                'zone' => null,
                'row' => null,
                'shelf' => null,
                'cell' => null,
                'is_active' => true,
            ],
        )->id;
    }

    protected function resolveCounterpartyId(array $validated): ?int
    {
        if (! empty($validated['counterparty_id'])) {
            return (int) $validated['counterparty_id'];
        }

        $name = trim((string) ($validated['new_counterparty_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        return Counterparty::query()->create([
            'type' => 'supplier',
            'name' => $name,
            'phone' => trim((string) ($validated['new_counterparty_phone'] ?? '')) ?: null,
            'email' => trim((string) ($validated['new_counterparty_email'] ?? '')) ?: null,
            'is_active' => true,
        ])->id;
    }

    protected function purchaseItemsDetails(array $items): string
    {
        return collect($items)
            ->map(function (array $item): string {
                return trim(collect([
                    trim((string) ($item['name'] ?? '')),
                    trim((string) ($item['model'] ?? '')),
                ])->filter()->join(' - '));
            })
            ->filter()
            ->implode('; ');
    }

    protected function purchaseDetailsFromPostedItems(Purchase $purchase): string
    {
        return $purchase->items()
            ->with('product')
            ->get()
            ->map(function (PurchaseItem $item): string {
                return trim(collect([
                    $item->product?->name,
                    $item->product?->model,
                ])->filter()->join(' - '));
            })
            ->filter()
            ->implode('; ');
    }

    protected function nextSku(): string
    {
        $lastNumber = Product::query()
            ->where('sku', 'like', 'ZAK-%')
            ->pluck('sku')
            ->map(function (string $sku): int {
                if (preg_match('/^ZAK-(\d{6})$/', $sku, $matches) !== 1) {
                    return 0;
                }

                return (int) $matches[1];
            })
            ->max() ?? 0;

        do {
            $lastNumber++;
            $sku = 'ZAK-'.str_pad((string) $lastNumber, 6, '0', STR_PAD_LEFT);
        } while (Product::query()->where('sku', $sku)->exists());

        return $sku;
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $counter = 2;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    protected function formOptions(): array
    {
        $warehouses = Warehouse::query()->orderBy('name')->get();
        $locations = Location::query()->with('warehouse')->orderBy('full_code')->get();
        $colorOptions = Product::query()
            ->whereNotNull('color')
            ->where('color', '!=', '')
            ->distinct()
            ->orderBy('color')
            ->pluck('color');

        return [
            'warehouses' => $warehouses,
            'locations' => $locations,
            'warehouseOptions' => $warehouses
                ->map(fn (Warehouse $warehouse): array => [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'floor_count' => $warehouse->floor_count ?? 1,
                    'floors' => collect($warehouse->availableFloors())
                        ->map(fn (string $label, string $value): array => [
                            'value' => $value,
                            'label' => $label,
                        ])
                        ->values(),
                ])
                ->values(),
            'locationOptions' => $locations
                ->map(fn (Location $location): array => [
                    'id' => $location->id,
                    'warehouse_id' => $location->warehouse_id,
                    'floor' => $location->floor,
                    'full_code' => $location->full_code,
                ])
                ->values(),
            'counterparties' => Counterparty::query()->whereIn('type', ['supplier', 'both'])->orderBy('name')->get(),
            'brands' => Brand::query()->orderBy('name')->get(),
            'teslaModels' => $this->teslaModels(),
            'conditionTypes' => $this->conditionTypes(),
            'colorOptions' => $colorOptions,
        ];
    }

    protected function teslaModels(?Request $request = null): array
    {
        return PartCatalogCategory::modelOptions(
            $request ? collect($request->input('items', []))->pluck('model')->filter()->first() : null
        );
    }

    protected function conditionTypes(): array
    {
        return Product::CONDITION_TYPE_LABELS;
    }
}
