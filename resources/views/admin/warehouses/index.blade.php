@extends('layouts.admin', ['heading' => 'Склад'])

@section('content')
    <div class="panel">
        <div class="actions" style="margin-bottom:16px;">
            <a class="btn" href="{{ route('admin.warehouses.create') }}">Добавить склад</a>
        </div>
        <table>
            <thead><tr><th>Название</th><th>Статус</th><th>Товары</th><th>Ячейки</th></tr></thead>
            <tbody>
            @forelse ($warehouses as $warehouse)
                @php($isDonorWarehouse = $warehouse->type === \App\Models\Warehouse::TYPE_DONOR)
                @php($usesStructuredLocations = $warehouse->usesStructuredLocations())
                <tr>
                    <td>
                        <a href="{{ route('admin.warehouses.index', ['warehouse_id' => $warehouse->id]) }}">{{ $warehouse->name }}</a>
                    </td>
                    <td><span class="tag {{ $warehouse->is_active ? '' : 'tag-warning' }}">{{ $warehouse->is_active ? 'Активен' : 'Отключен' }}</span></td>
                    <td>
                        <strong>{{ (int) ($warehouse->stock_quantity ?? 0) }}</strong>
                        <div class="help">
                            Позиций {{ (int) ($warehouse->product_positions_count ?? 0) }} · доступно {{ (int) ($warehouse->available_quantity ?? 0) }} · резерв {{ (int) ($warehouse->reserved_quantity ?? 0) }}
                        </div>
                    </td>
                    <td>
                        @if(! $usesStructuredLocations)
                            <span class="help">Не используется</span>
                        @else
                            @if($isDonorWarehouse)
                                <div class="actions">
                                    @foreach($warehouse->locations as $location)
                                        <a class="location-cell-tag {{ $location->is_active ? '' : 'location-cell-tag--warning' }}" href="{{ route('admin.warehouses.index', ['warehouse_id' => $warehouse->id, 'location_id' => $location->id]) }}">
                                            <span>{{ $location->shortCode() }}</span>
                                            <small>{{ (int) ($location->parts_quantity ?? 0) }} запчастей</small>
                                        </a>
                                    @endforeach
                                </div>
                            @else
                                @php($locationsByFloor = $warehouse->locations->groupBy(fn ($location) => $location->floor ?: 'not_set'))
                                <div class="location-floor-list">
                                    @foreach($warehouse->availableFloors() as $floorValue => $floorLabel)
                                        @php($locations = $locationsByFloor->get($floorValue, collect()))
                                        <div class="location-floor-group">
                                            <div class="location-floor-title">
                                                <a href="{{ route('admin.warehouses.index', ['warehouse_id' => $warehouse->id, 'floor' => $floorValue]) }}">{{ $floorLabel }}</a>
                                                <button
                                                    type="button"
                                                    class="btn-secondary btn-small"
                                                    title="Добавить ячейку"
                                                    aria-label="Добавить ячейку на {{ $floorLabel }}"
                                                    data-location-dialog-open="location-dialog-{{ $warehouse->id }}"
                                                    data-location-floor="{{ $floorValue }}"
                                                    data-location-floor-label="{{ $floorLabel }}"
                                                >+</button>
                                            </div>
                                            @if($locations->isNotEmpty())
                                                <div class="actions">
                                                    @foreach($locations as $location)
                                                        <a class="location-cell-tag {{ $location->is_active ? '' : 'location-cell-tag--warning' }}" href="{{ route('admin.warehouses.index', ['warehouse_id' => $warehouse->id, 'location_id' => $location->id]) }}">
                                                            <span>{{ $location->shortCode() }}</span>
                                                            <small>{{ (int) ($location->parts_quantity ?? 0) }} запчастей</small>
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif

                        @if(! $isDonorWarehouse && $usesStructuredLocations)
                        <dialog class="modal" id="location-dialog-{{ $warehouse->id }}" data-floor-count="{{ $warehouse->floor_count }}">
                            <form method="POST" action="{{ route('admin.locations.store') }}">
                                @csrf
                                <input type="hidden" name="warehouse_id" value="{{ $warehouse->id }}">
                                <input type="hidden" name="redirect_to" value="warehouses">
                                <input type="hidden" name="is_active" value="1">

                                <div class="modal-header">
                                    <h2>Новая ячейка</h2>
                                    <button type="button" class="btn-secondary btn-small" onclick="this.closest('dialog').close()">Закрыть</button>
                                </div>
                                <div class="help" style="margin:-8px 0 16px;">{{ $warehouse->name }} · <span data-location-floor-label></span></div>

                                <div class="form-grid">
                                    <input type="hidden" name="full_code" data-location-full-code>
                                    <div hidden>
                                        <label>Этаж</label>
                                        <select name="floor">
                                            @foreach($warehouse->availableFloors() as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div hidden>
                                        <label>Зона</label>
                                        <select name="zone" required>
                                            @foreach(\App\Models\Location::ZONES as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div hidden><label></label><input name="row"></div>
                                    <div hidden><label>Полка</label><input name="shelf"></div>
                                    <div class="full"><label>Название ячейки</label><input name="cell" data-location-cell-name required></div>
                                </div>

                                <div class="actions" style="margin-top:18px;">
                                    <button type="submit">Добавить</button>
                                </div>
                            </form>
                        </dialog>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Склады пока не добавлены.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div style="margin-top:16px;">{{ $warehouses->links() }}</div>
    </div>

    @if($selectedWarehouse)
        <div class="panel" id="warehouse-parts">
            <div class="actions" style="justify-content:space-between; margin-bottom:16px;">
                <div>
                    <h2 style="margin-bottom:4px;">Запчасти: {{ $selectedPartsTitle }}</h2>
                    <div class="help">
                        Найдено позиций: {{ $selectedStockItems->count() }}
                    </div>
                </div>
                <a class="btn btn-secondary" href="{{ route('admin.warehouses.index') }}">Сбросить</a>
            </div>

            <table>
                <thead>
                <tr>
                    <th>Запчасть</th>
                    <th>Артикул</th>
                    <th>Склад</th>
                    <th>Ячейка</th>
                    <th>Кол-во</th>
                    <th>Резерв</th>
                    <th>Доступно</th>
                </tr>
                </thead>
                <tbody>
                @forelse($selectedStockItems as $stockItem)
                    @php($product = $stockItem->product)
                    <tr>
                        <td>
                            @if($product)
                                <a href="{{ route('admin.products.show', $product) }}">{{ $product->name }}</a>
                                @if($product->sku)
                                    <div class="help">{{ $product->sku }}</div>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $product?->external_sku ?: $product?->sourcePartCatalogItem?->part_number ?: '—' }}</td>
                        <td>{{ $stockItem->warehouse?->name ?? '—' }}</td>
                        <td>
                            @if($stockItem->location)
                                @if($stockItem->warehouse?->type === \App\Models\Warehouse::TYPE_DONOR)
                                    {{ $stockItem->location->shortCode() }}
                                @else
                                    {{ $stockItem->location->floorLabel() }} · {{ $stockItem->location->shortCode() }}
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ (int) $stockItem->quantity }}</td>
                        <td>{{ (int) $stockItem->reserved_quantity }}</td>
                        <td>{{ (int) $stockItem->available_quantity }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">В выбранной зоне нет запчастей.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif

    <script>
        document.querySelectorAll('dialog[data-floor-count]').forEach((dialog) => {
            const form = dialog.querySelector('form');
            const floorSelect = dialog.querySelector('select[name="floor"]');
            const cellInput = dialog.querySelector('[data-location-cell-name]');
            const fullCodeInput = dialog.querySelector('[data-location-full-code]');
            const floorLabel = dialog.querySelector('[data-location-floor-label]');
            const warehouseId = dialog.querySelector('input[name="warehouse_id"]')?.value || '';

            const selectedFloorNumber = () => (floorSelect?.value || 'floor_1').replace('floor_', '');
            const buildFullCode = () => {
                if (! fullCodeInput || ! cellInput) {
                    return;
                }

                const cellName = cellInput.value.trim().replace(/\s+/g, ' ');
                cellInput.value = cellName;
                fullCodeInput.value = cellName === '' ? '' : `WH${warehouseId}-F${selectedFloorNumber()}-${cellName}`;
            };

            form?.addEventListener('submit', buildFullCode);

            dialog.addEventListener('close', () => {
                form?.reset();
                if (fullCodeInput) {
                    fullCodeInput.value = '';
                }
            });

            document.querySelectorAll(`[data-location-dialog-open="${dialog.id}"]`).forEach((button) => {
                button.addEventListener('click', () => {
                    if (floorSelect) {
                        floorSelect.value = button.dataset.locationFloor || 'floor_1';
                    }

                    if (floorLabel) {
                        floorLabel.textContent = button.dataset.locationFloorLabel || '';
                    }

                    if (typeof dialog.showModal === 'function') {
                        dialog.showModal();
                    } else {
                        dialog.setAttribute('open', 'open');
                    }

                    cellInput?.focus();
                });
            });
        });
    </script>
@endsection
