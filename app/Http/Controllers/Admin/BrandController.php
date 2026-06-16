<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrandRequest;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class BrandController extends Controller
{
    public function index(): View
    {
        return view('admin.brands.index', [
            'brands' => Brand::query()->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.brands.form', [
            'brand' => new Brand,
        ]);
    }

    public function store(BrandRequest $request): RedirectResponse
    {
        Brand::query()->create($this->payload($request));

        return redirect()->route('admin.brands.index')->with('status', 'Бренд создан.');
    }

    public function show(Brand $brand): View
    {
        return view('admin.brands.show', [
            'brand' => $brand->load('products'),
        ]);
    }

    public function edit(Brand $brand): View
    {
        return view('admin.brands.form', compact('brand'));
    }

    public function update(BrandRequest $request, Brand $brand): RedirectResponse
    {
        $brand->update($this->payload($request));

        return redirect()->route('admin.brands.index')->with('status', 'Бренд обновлен.');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        $brand->delete();

        return redirect()->route('admin.brands.index')->with('status', 'Бренд удален.');
    }

    protected function payload(BrandRequest $request): array
    {
        return [
            ...$request->validated(),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
