<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeletedPart;
use App\Services\DeletedPartRestoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeletedPartController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->string('search')->toString());

        $deletedParts = DeletedPart::query()
            ->with(['donorCar:id,vin,model,year,color', 'deletedBy:id,name'])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';

                $query->where(function ($query) use ($like): void {
                    $query
                        ->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('part_number', 'like', $like)
                        ->orWhere('donor_vin', 'like', $like);
                });
            })
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.deleted_parts.index', [
            'deletedParts' => $deletedParts,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function restore(DeletedPart $deletedPart, DeletedPartRestoreService $restore): RedirectResponse
    {
        $restore->restore($deletedPart);

        return redirect()
            ->route('admin.deleted-parts.index')
            ->with('status', 'Запчасть восстановлена.');
    }

    public function show(DeletedPart $deletedPart): View
    {
        $deletedPart->loadMissing(['donorCar:id,vin,model,year,color', 'deletedBy:id,name']);

        return view('admin.deleted_parts.show', [
            'deletedPart' => $deletedPart,
        ]);
    }
}
