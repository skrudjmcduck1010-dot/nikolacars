@extends('layouts.admin', ['heading' => 'Склады'])

@section('content')
    <div class="panel">
        <div class="actions" style="margin-bottom:16px;">
            <a class="btn" href="{{ route('admin.warehouses.create') }}">Добавить склад</a>
        </div>
        <table>
            <thead><tr><th>Название</th><th>Статус</th><th>Товары</th><th>Ячейки</th><th></th></tr></thead>
            <tbody>
            @forelse ($warehouses as $warehouse)
                @php($isDonorWarehouse = $warehouse->type === \App\Models\Warehouse::TYPE_DONOR)
                <tr>
                    <td><a href="{{ route('admin.warehouses.show', $warehouse) }}">{{ $warehouse->name }}</a></td>
                    <td><span class="tag {{ $warehouse->is_active ? '' : 'tag-warning' }}">{{ $warehouse->is_active ? 'Активен' : 'Отключен' }}</span></td>
                    <td>
                        <strong>{{ (int) ($warehouse->stock_quantity ?? 0) }}</strong>
                        <div class="help">
                            Позиций {{ (int) ($warehouse->product_positions_count ?? 0) }} · доступно {{ (int) ($warehouse->available_quantity ?? 0) }} · резерв {{ (int) ($warehouse->reserved_quantity ?? 0) }}
                        </div>
                    </td>
                    <td>
                        @if($warehouse->locations->isNotEmpty())
                            <div class="location-floor-list">
                                @foreach($warehouse->locations->groupBy(fn ($location) => $location->floor ?: 'not_set') as $floor => $locations)
                                    <div class="location-floor-group">
                                        <div class="location-floor-title">{{ $locations->first()->floorLabel() }}</div>
                                        <div class="actions">
                                            @foreach($locations as $location)
                                                <a class="tag {{ $location->is_active ? '' : 'tag-warning' }}" href="{{ route('admin.locations.show', $location) }}">{{ $location->full_code }}</a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="empty" style="padding-top:0;">Ячейки пока не добавлены.</div>
                        @endif

                        @unless($isDonorWarehouse)
                        <div class="actions" style="margin-top:10px;">
                            <button type="button" class="btn-secondary btn-small" onclick="document.getElementById('location-dialog-{{ $warehouse->id }}').showModal()">
                                Добавить ячейку
                            </button>
                        </div>
                        @endunless

                        @unless($isDonorWarehouse)
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
                                <div class="help" style="margin:-8px 0 16px;">{{ $warehouse->name }}</div>

                                <div class="form-grid">
                                    <div><label>Полный код</label><input name="full_code" required></div>
                                    <div>
                                        <label>Этаж</label>
                                        <select name="floor">
                                            @foreach($warehouse->availableFloors() as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label>Зона</label>
                                        <select name="zone" required>
                                            @foreach(\App\Models\Location::ZONES as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div><label></label><input name="row"></div>
                                    <div><label>Полка</label><input name="shelf"></div>
                                    <div><label>Ячейка</label><input name="cell"></div>
                                </div>

                                <div class="actions" style="margin-top:18px;">
                                    <button type="submit">Добавить</button>
                                </div>
                            </form>
                        </dialog>
                        @endunless
                    </td>
                    <td class="actions">
                        <a class="btn-secondary btn" href="{{ route('admin.warehouses.edit', $warehouse) }}">Изменить</a>
                        @if($warehouse->has_stock)
                            <span class="help">Есть товары, удалить нельзя</span>
                        @else
                        <form method="POST" action="{{ route('admin.warehouses.destroy', $warehouse) }}" class="inline-form" onsubmit="return confirm('Удалить склад {{ $warehouse->name }}?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-danger">Удалить</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Склады пока не добавлены.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div style="margin-top:16px;">{{ $warehouses->links() }}</div>
    </div>

    <script>
        document.querySelectorAll('dialog[data-floor-count]').forEach((dialog) => {
            if (Number(dialog.dataset.floorCount || 1) > 1) {
                return;
            }

            const floorSelect = dialog.querySelector('select[name="floor"]');
            floorSelect?.closest('div')?.setAttribute('hidden', 'hidden');
        });
    </script>
@endsection
