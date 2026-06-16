<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Movement;
use Illuminate\View\View;

class MovementController extends Controller
{
    public function index(): View
    {
        return view('admin.movements.index', [
            'movements' => Movement::query()
                ->with(['product', 'stockItem.location', 'fromLocation', 'toLocation', 'counterparty', 'user'])
                ->latest('created_at')
                ->paginate(30),
        ]);
    }

    public function show(Movement $movement): View
    {
        return view('admin.movements.show', [
            'movement' => $movement->load(['product', 'stockItem', 'fromLocation', 'toLocation', 'counterparty', 'user']),
        ]);
    }
}
