@extends('layouts.admin', ['heading' => $location->exists ? ' ' : ' '])

@section('content')
    <form method="POST" action="{{ $location->exists ? route('admin.locations.update', $location) : route('admin.locations.store') }}" class="panel">
        @csrf
        @if($location->exists) @method('PUT') @endif
        <div class="form-grid">
            <div>
                <label>Склад</label>
                <select name="warehouse_id" required data-warehouse-select>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" data-floor-count="{{ $warehouse->floor_count }}" @selected(old('warehouse_id', $location->warehouse_id) == $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>
            <div data-floor-field>
                <label>Этаж</label>
                <select name="floor" data-floor-select data-selected-floor="{{ old('floor', $location->floor) }}">
                    @foreach(\App\Models\Location::floorsForCount(20) as $value => $label)
                        <option value="{{ $value }}" @selected(old('floor', $location->floor) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="help" data-floor-count-help style="margin-top:6px;"></div>
            </div>
            <div><label>Полный код</label><input name="full_code" value="{{ old('full_code', $location->full_code) }}" required></div>
            <div>
                <label>Зона</label>
                <select name="zone" required>
                    @foreach(\App\Models\Location::ZONES as $value => $label)
                        <option value="{{ $value }}" @selected(old('zone', $location->zone) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div><label></label><input name="row" value="{{ old('row', $location->row) }}"></div>
            <div><label>Полка</label><input name="shelf" value="{{ old('shelf', $location->shelf) }}"></div>
            <div><label>Ячейка</label><input name="cell" value="{{ old('cell', $location->cell) }}"></div>
            <div style="display:flex;align-items:end;"><label><input type="checkbox" name="is_active" value="1" {{ old('is_active', $location->is_active ?? true) ? 'checked' : '' }} style="width:auto;"> Активна</label></div>
        </div>
        <div class="actions" style="margin-top:20px;"><button type="submit">Сохранить</button></div>
    </form>
    <script>
        const warehouseSelect = document.querySelector('[data-warehouse-select]');
        const floorField = document.querySelector('[data-floor-field]');
        const floorSelect = document.querySelector('[data-floor-select]');
        const floorCountHelp = document.querySelector('[data-floor-count-help]');

        function floorWord(count) {
            if (count % 10 === 1 && count % 100 !== 11) {
                return 'этаж';
            }

            if ([2, 3, 4].includes(count % 10) && ! [12, 13, 14].includes(count % 100)) {
                return 'этажа';
            }

            return 'этажей';
        }

        function syncFloorOptions() {
            const selectedOption = warehouseSelect?.options[warehouseSelect.selectedIndex];
            const floorCount = Math.max(1, Number(selectedOption?.dataset.floorCount || 1));
            const selectedFloor = floorSelect.dataset.selectedFloor || floorSelect.value || 'floor_1';

            floorField.hidden = floorCount === 1;
            floorCountHelp.textContent = floorCount > 1 ? `В выбранном складе ${floorCount} ${floorWord(floorCount)}` : '';

            Array.from(floorSelect.options).forEach((option) => {
                const floorNumber = Number(option.value.replace('floor_', ''));
                option.hidden = floorNumber > floorCount;
                option.disabled = floorNumber > floorCount;
            });

            if (floorCount === 1 || Number(floorSelect.value.replace('floor_', '')) > floorCount) {
                const selectedFloorNumber = Number(selectedFloor.replace('floor_', ''));
                floorSelect.value = selectedFloorNumber >= 1 && selectedFloorNumber <= floorCount ? selectedFloor : 'floor_1';
            }
        }

        warehouseSelect?.addEventListener('change', () => {
            floorSelect.dataset.selectedFloor = '';
            syncFloorOptions();
        });

        syncFloorOptions();
    </script>
@endsection
