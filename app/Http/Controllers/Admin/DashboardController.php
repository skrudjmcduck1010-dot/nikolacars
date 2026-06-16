<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\StockItem;
use App\Models\Warehouse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'warehouseCount' => Warehouse::query()->count(),
            'productCount' => Product::query()->count(),
            'stockQuantity' => StockItem::query()->sum('quantity'),
            'reservedQuantity' => StockItem::query()->sum('reserved_quantity'),
            'activeReservationCount' => Reservation::query()->where('status', 'active')->count(),
            'recentMovements' => Movement::query()
                ->with(['product', 'fromLocation', 'toLocation', 'user'])
                ->latest('created_at')
                ->limit(10)
                ->get(),
        ]);
    }
}
