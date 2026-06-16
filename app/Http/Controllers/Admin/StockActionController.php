<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockActionRequest;
use App\Models\Counterparty;
use App\Models\Location;
use App\Models\Movement;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Services\InventoryAutocompleteService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;
use InvalidArgumentException;

class StockActionController extends Controller
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly InventoryAutocompleteService $autocomplete,
    ) {}

    public function create(Request $request, string $type): View
    {
        abort_unless(in_array($type, Movement::TYPES, true), 404);

        return view('admin.stock_actions.form', [
            'type' => $type,
            'selectedProduct' => $this->autocomplete->selectedProduct($request->old('product_id')),
            'selectedStockItem' => $this->autocomplete->selectedStockItem($request->old('stock_item_id')),
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'locations' => Location::query()->with('warehouse')->orderBy('full_code')->get(),
            'counterparties' => Counterparty::query()->orderBy('name')->get(),
        ]);
    }

    public function productOptions(Request $request): JsonResponse
    {
        return response()->json($this->autocomplete->productOptions((string) $request->query('q', '')));
    }

    public function stockItemOptions(Request $request): JsonResponse
    {
        return response()->json($this->autocomplete->stockItemOptions((string) $request->query('q', '')));
    }

    public function store(StockActionRequest $request): RedirectResponse
    {
        $type = $request->string('type')->toString();

        try {
            match ($type) {
                'intake' => $this->stockService->intake($request->validated()),
                'move' => $this->stockService->move(
                    StockItem::query()->findOrFail($request->integer('stock_item_id')),
                    $request->integer('quantity'),
                    $request->integer('to_location_id'),
                    $this->movementMeta($request),
                ),
                'reserve' => $this->stockService->reserve(
                    StockItem::query()->findOrFail($request->integer('stock_item_id')),
                    $request->validated(),
                ),
                'unreserve' => $this->stockService->unreserve(
                    StockItem::query()->findOrFail($request->integer('stock_item_id')),
                    $request->integer('quantity'),
                    $this->movementMeta($request),
                ),
                'sale' => $this->stockService->sale(
                    StockItem::query()->findOrFail($request->integer('stock_item_id')),
                    $request->integer('quantity'),
                    $this->movementMeta($request),
                ),
                'writeoff' => $this->stockService->writeoff(
                    StockItem::query()->findOrFail($request->integer('stock_item_id')),
                    $request->integer('quantity'),
                    $this->movementMeta($request),
                ),
                'adjustment' => $this->stockService->adjust(
                    StockItem::query()->findOrFail($request->integer('stock_item_id')),
                    $request->integer('target_quantity'),
                    $this->movementMeta($request),
                ),
                default => throw new InvalidArgumentException('Неизвестный тип складской операции.'),
            };
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['quantity' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('admin.movements.index')->with('status', 'Операция выполнена: '.[
            'intake' => 'приемка',
            'move' => 'перемещение',
            'reserve' => 'резерв',
            'unreserve' => 'снятие резерва',
            'sale' => 'продажа',
            'writeoff' => 'списание',
            'adjustment' => 'корректировка',
        ][$type].'.');
    }

    protected function movementMeta(StockActionRequest $request): array
    {
        return Arr::only($request->validated(), [
            'counterparty_id',
            'reason',
            'document_number',
            'comment',
            'customer_order_id',
            'expires_at',
        ]);
    }
}
