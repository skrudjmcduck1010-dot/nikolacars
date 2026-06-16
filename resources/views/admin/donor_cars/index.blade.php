@extends('layouts.admin', ['heading' => 'Донорские автомобили'])

@section('content')
    @php
        $sort = $sort ?? 'purchase_date';
        $direction = $direction ?? 'desc';
        $defaultDirection = fn ($field) => in_array($field, ['vin', 'status', 'model'], true) ? 'asc' : 'desc';
        $sortUrl = fn ($field) => route('admin.donor-cars.index', [
            'sort' => $field,
            'direction' => $sort === $field ? ($direction === 'asc' ? 'desc' : 'asc') : $defaultDirection($field),
        ]);
        $sortMark = fn ($field) => $sort === $field ? ($direction === 'asc' ? ' ^' : ' v') : '';
        $donorStats = $donorStats ?? ['count' => $donorCars->total(), 'totalCostUsd' => 0, 'soldPartsQuantity' => 0, 'soldPartsAmount' => 0];
        $statuses = $statuses ?? \App\Models\DonorCar::STATUSES;
        $driveTypeShortLabels = [
            \App\Models\DonorCar::DRIVE_TYPE_ALL => 'AWD',
            \App\Models\DonorCar::DRIVE_TYPE_REAR => 'RWD',
        ];
        $donorColorSwatchHex = function (?string $color): ?string {
            $normalized = mb_strtolower(trim((string) $color));

            if ($normalized === '') {
                return null;
            }

            return match (true) {
                preg_match('/\b(white|pearl)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0431}\u{0435}\u{043B}") || str_contains($normalized, "\u{0431}\u{0456}\u{043B}") => '#f8fafc',
                preg_match('/\b(black|obsidian)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0447}\u{0435}\u{0440}\u{043D}") || str_contains($normalized, "\u{0447}\u{043E}\u{0440}\u{043D}") => '#111827',
                preg_match('/\b(red|ultra)\b/u', $normalized) === 1 || str_contains($normalized, "\u{043A}\u{0440}\u{0430}\u{0441}\u{043D}") || str_contains($normalized, "\u{0447}\u{0435}\u{0440}\u{0432}\u{043E}\u{043D}") => '#dc2626',
                preg_match('/\b(blue|navy)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0441}\u{0438}\u{043D}") => '#2563eb',
                preg_match('/\b(grey|gray|silver|quicksilver|midnight)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0441}\u{0435}\u{0440}") || str_contains($normalized, "\u{0441}\u{0440}\u{0456}\u{0431}") => '#94a3b8',
                preg_match('/\b(green)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0437}\u{0435}\u{043B}\u{0435}\u{043D}") || str_contains($normalized, "\u{0437}\u{0435}\u{043B}") => '#16a34a',
                preg_match('/\b(yellow|gold)\b/u', $normalized) === 1 || str_contains($normalized, "\u{0436}\u{0435}\u{043B}\u{0442}") || str_contains($normalized, "\u{0436}\u{043E}\u{0432}\u{0442}") || str_contains($normalized, "\u{0437}\u{043E}\u{043B}\u{043E}\u{0442}") => '#facc15',
                preg_match('/\b(brown|bronze)\b/u', $normalized) === 1 || str_contains($normalized, "\u{043A}\u{043E}\u{0440}\u{0438}\u{0447}") || str_contains($normalized, "\u{0431}\u{0440}\u{043E}\u{043D}\u{0437}") => '#92400e',
                default => null,
            };
        };
    @endphp

    <div class="grid grid-4" style="margin-bottom:18px;">
        <div class="panel">
            <div class="help">Кол-во доноров</div>
            <div class="stat">{{ number_format((int) $donorStats['count'], 0, ',', ' ') }}</div>
        </div>
        <div class="panel">
            <div class="help">Цена всех доноров</div>
            <div class="stat">${{ number_format((float) $donorStats['totalCostUsd'], 2, '.', ' ') }}</div>
        </div>
        <div class="panel">
            <div class="help">Продано запчастей NikolaCars</div>
            <div class="stat">{{ rtrim(rtrim(number_format((float) $donorStats['soldPartsQuantity'], 3, '.', ''), '0'), '.') }}</div>
        </div>
        <div class="panel">
            <div class="help">Сумма проданных запчастей</div>
            <div class="stat">${{ number_format((float) $donorStats['soldPartsAmount'], 2, '.', ' ') }}</div>
        </div>
    </div>

    <div class="panel">
        <div class="actions" style="margin-bottom:16px;">
            <a class="btn" href="{{ route('admin.donor-cars.create') }}">Добавить донорский автомобиль</a>
        </div>

        <style>
            .donor-cars-table th { line-height: 1.25; }
            .donor-cars-table th a,
            .donor-cars-table .donor-table-heading { display: inline-grid; gap: 1px; }
            .donor-cars-table thead tr.donor-cars-original-heading { display: none; }
            .donor-paint-code { display: grid; gap: 6px; min-width: 112px; }
            .donor-paint-code__view { display: inline-flex; align-items: center; gap: 6px; }
            .donor-paint-code__edit { width: 28px; height: 28px; padding: 0; border-radius: 999px; font-size: 13px; line-height: 1; }
            .donor-paint-code__form { display: flex; align-items: center; gap: 6px; }
            .donor-paint-code__form input { min-width: 104px; padding: 7px 9px; border-radius: 10px; }
            .donor-paint-code__save,
            .donor-paint-code__cancel { width: 28px; height: 28px; padding: 0; border-radius: 999px; font-size: 13px; line-height: 1; }
            .donor-color { display: inline-flex; align-items: center; }
            .donor-color__swatch { flex: 0 0 auto; width: 16px; height: 16px; border: 1px solid rgba(17, 24, 39, .24); border-radius: 999px; background-color: var(--donor-color, #e5e7eb); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .62); }
        </style>

        <datalist id="donor-paint-code-suggestions">
            @foreach($paintCodeSuggestions ?? [] as $paintCodeSuggestion)
                <option value="{{ $paintCodeSuggestion }}"></option>
            @endforeach
        </datalist>

        <table class="donor-cars-table">
            <thead>
            <tr>
                <th><span class="donor-table-heading"><span>&#1060;&#1086;&#1090;&#1086;</span><span>&nbsp;</span></span></th>
                <th><a href="{{ $sortUrl('vin') }}"><span>VIN{{ $sortMark('vin') }}</span><span>&nbsp;</span></a></th>
                <th><a href="{{ $sortUrl('status') }}"><span>&#1057;&#1090;&#1072;&#1090;&#1091;&#1089;{{ $sortMark('status') }}</span><span>&nbsp;</span></a></th>
                <th><a href="{{ $sortUrl('purchase_date') }}"><span>&#1044;&#1072;&#1090;&#1072;</span><span>&#1087;&#1086;&#1082;&#1091;&#1087;&#1082;&#1080;{{ $sortMark('purchase_date') }}</span></a></th>
                <th><a href="{{ $sortUrl('warehouse_arrival_date') }}"><span>&#1044;&#1072;&#1090;&#1072; &#1087;&#1088;&#1080;&#1093;&#1086;&#1076;&#1072;</span><span>&#1085;&#1072; &#1057;&#1058;&#1054;{{ $sortMark('warehouse_arrival_date') }}</span></a></th>
                <th><a href="{{ $sortUrl('model') }}"><span>&#1052;&#1086;&#1076;&#1077;&#1083;&#1100;{{ $sortMark('model') }}</span><span>&nbsp;</span></a></th>
                <th><span class="donor-table-heading"><span>&#1055;&#1088;&#1080;&#1074;&#1086;&#1076;</span><span>&nbsp;</span></span></th>
                <th><a href="{{ $sortUrl('year') }}"><span>&#1043;&#1086;&#1076;{{ $sortMark('year') }}</span><span>&nbsp;</span></a></th>
                <th><a href="{{ $sortUrl('mileage') }}"><span>&#1055;&#1088;&#1086;&#1073;&#1077;&#1075;{{ $sortMark('mileage') }}</span><span>&nbsp;</span></a></th>
                <th><span class="donor-table-heading"><span>&#1062;&#1074;&#1077;&#1090;</span><span>&nbsp;</span></span></th>
                <th><span class="donor-table-heading"><span>&#1052;&#1072;&#1088;&#1082;&#1080;&#1088;&#1086;&#1074;&#1082;&#1072;</span><span>&#1094;&#1074;&#1077;&#1090;&#1072;</span></span></th>
                <th><a href="{{ $sortUrl('products_count') }}"><span>&#1050;&#1086;&#1083;-&#1074;&#1086;</span><span>&#1079;&#1072;&#1087;&#1095;&#1072;&#1089;&#1090;&#1077;&#1081;{{ $sortMark('products_count') }}</span></a></th>
                <th><a href="{{ $sortUrl('part_sales_count') }}"><span>&#1055;&#1088;&#1086;&#1076;&#1072;&#1085;&#1086;</span><span>&#1079;&#1072;&#1087;&#1095;&#1072;&#1089;&#1090;&#1077;&#1081;{{ $sortMark('part_sales_count') }}</span></a></th>
                <th><a href="{{ $sortUrl('sold_parts_amount') }}"><span>&#1057;&#1091;&#1084;&#1084;&#1072;</span><span>&#1087;&#1088;&#1086;&#1076;&#1072;&#1078;{{ $sortMark('sold_parts_amount') }}</span></a></th>
                <th><a href="{{ $sortUrl('total_cost_usd') }}"><span>&#1055;&#1086;&#1083;&#1085;&#1072;&#1103;</span><span>&#1089;&#1090;&#1086;&#1080;&#1084;&#1086;&#1089;&#1090;&#1100;{{ $sortMark('total_cost_usd') }}</span></a></th>
                <th></th>
            </tr>
            <tr class="donor-cars-original-heading">
                <th>Фото</th>
                <th><a href="{{ $sortUrl('vin') }}"><span>VIN{{ $sortMark('vin') }}</span><span>&nbsp;</span></a></th>
                <th><a href="{{ $sortUrl('status') }}">Статус{{ $sortMark('status') }}</a></th>
                <th><a href="{{ $sortUrl('purchase_date') }}">Дата покупки{{ $sortMark('purchase_date') }}</a></th>
                <th><a href="{{ $sortUrl('warehouse_arrival_date') }}">Дата прихода донора на СТО{{ $sortMark('warehouse_arrival_date') }}</a></th>
                <th><a href="{{ $sortUrl('model') }}">Модель{{ $sortMark('model') }}</a></th>
                <th>Привод</th>
                <th><a href="{{ $sortUrl('year') }}">Год{{ $sortMark('year') }}</a></th>
                <th><a href="{{ $sortUrl('mileage') }}">Пробег{{ $sortMark('mileage') }}</a></th>
                <th>&#1062;&#1074;&#1077;&#1090;</th>
                <th><a href="{{ $sortUrl('products_count') }}">Кол-во запчастей{{ $sortMark('products_count') }}</a></th>
                <th><a href="{{ $sortUrl('part_sales_count') }}">Продано Запчастей{{ $sortMark('part_sales_count') }}</a></th>
                <th><a href="{{ $sortUrl('sold_parts_amount') }}">Сумма продаж{{ $sortMark('sold_parts_amount') }}</a></th>
                <th><a href="{{ $sortUrl('total_cost_usd') }}">Полная стоимость{{ $sortMark('total_cost_usd') }}</a></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($donorCars as $donorCar)
                <tr>
                    <td>
                        @if($donorCar->photos)
                            <img class="table-preview" src="{{ \App\Support\PublicStorageUrl::url($donorCar->photos[0]) }}" alt="Превью {{ $donorCar->display_vin }}">
                        @else
                            <span class="preview-placeholder">Нет фото</span>
                        @endif
                    </td>
                    <td><a href="{{ route('admin.donor-cars.show', $donorCar) }}">{{ $donorCar->display_vin }}</a></td>
                    <td>
                        <span class="donor-status {{ $donorCar->status_class }}">{{ $donorCar->status_label }}</span>
                    </td>
                    <td>{{ $donorCar->purchase_date?->format('d.m.Y') }}</td>
                    <td data-warehouse-arrival-date>{{ $donorCar->warehouse_arrival_date?->format('d.m.Y') }}</td>
                    <td>{{ $donorCar->display_model }}</td>
                    <td data-drive-type-label>{{ $driveTypeShortLabels[$donorCar->drive_type] ?? '—' }}</td>
                    <td>{{ $donorCar->year }}</td>
                    <td>{{ $donorCar->mileage !== null ? number_format($donorCar->mileage, 0, ',', ' ').' mi' : '' }}</td>
                    <td>
                        @php($donorColor = trim((string) $donorCar->color))
                        @if($donorColor !== '')
                            @php($donorColorHex = $donorColorSwatchHex($donorColor))
                            <span class="donor-color">
                                <span
                                    class="donor-color__swatch"
                                    style="--donor-color: {{ $donorColorHex ?: '#e5e7eb' }}"
                                    title="{{ $donorColor }}"
                                    aria-label="{{ $donorColor }}"
                                ></span>
                            </span>
                        @endif
                    </td>
                    <td>
                        <div class="donor-paint-code" data-donor-paint-code>
                            <div class="donor-paint-code__view" data-donor-paint-code-view>
                                <span data-donor-paint-code-value>{{ filled($donorCar->paint_code) ? $donorCar->paint_code : '-' }}</span>
                                <button type="button" class="btn-secondary donor-paint-code__edit" title="&#1056;&#1077;&#1076;&#1072;&#1082;&#1090;&#1080;&#1088;&#1086;&#1074;&#1072;&#1090;&#1100; &#1084;&#1072;&#1088;&#1082;&#1080;&#1088;&#1086;&#1074;&#1082;&#1091; &#1094;&#1074;&#1077;&#1090;&#1072;" aria-label="&#1056;&#1077;&#1076;&#1072;&#1082;&#1090;&#1080;&#1088;&#1086;&#1074;&#1072;&#1090;&#1100; &#1084;&#1072;&#1088;&#1082;&#1080;&#1088;&#1086;&#1074;&#1082;&#1091; &#1094;&#1074;&#1077;&#1090;&#1072;" data-donor-paint-code-edit>&#9998;</button>
                            </div>
                            <form method="POST" action="{{ route('admin.donor-cars.paint-code.update', $donorCar) }}" class="donor-paint-code__form" data-donor-paint-code-form hidden>
                                @csrf
                                <input type="text" name="paint_code" value="{{ $donorCar->paint_code }}" maxlength="50" list="donor-paint-code-suggestions" autocomplete="off" data-donor-paint-code-input>
                                <button type="submit" class="btn-secondary donor-paint-code__save" title="&#1057;&#1086;&#1093;&#1088;&#1072;&#1085;&#1080;&#1090;&#1100;" aria-label="&#1057;&#1086;&#1093;&#1088;&#1072;&#1085;&#1080;&#1090;&#1100;" data-donor-paint-code-save>&#10003;</button>
                                <button type="button" class="btn-secondary donor-paint-code__cancel" title="&#1054;&#1090;&#1084;&#1077;&#1085;&#1072;" aria-label="&#1054;&#1090;&#1084;&#1077;&#1085;&#1072;" data-donor-paint-code-cancel>&#215;</button>
                            </form>
                            <div class="error" data-donor-paint-code-error hidden></div>
                        </div>
                    </td>
                    <td>{{ number_format((int) $donorCar->products_count, 0, ',', ' ') }}</td>
                    <td>
                        {{ number_format((int) $donorCar->part_sales_count, 0, ',', ' ') }}
                        @if((float) $donorCar->sold_parts_quantity > 0)
                            <div class="help">{{ rtrim(rtrim(number_format((float) $donorCar->sold_parts_quantity, 3, '.', ''), '0'), '.') }} шт</div>
                        @endif
                    </td>
                    <td>${{ number_format((float) $donorCar->sold_parts_amount, 2, '.', ' ') }}</td>
                    <td>
                        @if($donorCar->total_cost_usd !== null)
                            ${{ number_format((float) $donorCar->total_cost_usd, 2, '.', ' ') }}
                        @endif
                        @if($donorCar->has_incomplete_cost)
                            <span class="donor-cost-note">Не все расходы</span>
                        @endif
                    </td>
                    <td>
                        <div class="actions">
                            <a class="btn btn-secondary" href="{{ route('admin.donor-cars.edit', $donorCar) }}">Изменить</a>
                            @include('admin.donor_cars._official_download_button', ['donorCar' => $donorCar, 'iconOnly' => true])
                            @if($donorCar->canBeDeleted())
                                <form method="POST" action="{{ route('admin.donor-cars.destroy', $donorCar) }}" onsubmit="return prompt('Для подтверждения введите слово: удалить') === 'удалить';">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-danger">Удалить</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="16" class="empty">Донорские автомобили пока не добавлены.</td></tr>
            @endforelse
            </tbody>
        </table>

        <div style="margin-top:16px;">{{ $donorCars->links() }}</div>
    </div>

    <script>
        (() => {
            const suggestions = document.getElementById('donor-paint-code-suggestions');
            const emptyLabel = '-';

            const addSuggestion = (value) => {
                if (! suggestions || ! value) {
                    return;
                }

                const exists = Array.from(suggestions.options).some((option) => option.value === value);
                if (exists) {
                    return;
                }

                const option = document.createElement('option');
                option.value = value;
                suggestions.appendChild(option);
            };

            document.querySelectorAll('[data-donor-paint-code]').forEach((root) => {
                const view = root.querySelector('[data-donor-paint-code-view]');
                const editButton = root.querySelector('[data-donor-paint-code-edit]');
                const form = root.querySelector('[data-donor-paint-code-form]');
                const input = root.querySelector('[data-donor-paint-code-input]');
                const saveButton = root.querySelector('[data-donor-paint-code-save]');
                const cancelButton = root.querySelector('[data-donor-paint-code-cancel]');
                const value = root.querySelector('[data-donor-paint-code-value]');
                const error = root.querySelector('[data-donor-paint-code-error]');
                let previousValue = input?.value || '';
                let isSaving = false;

                if (! view || ! editButton || ! form || ! input || ! saveButton || ! cancelButton || ! value || ! error) {
                    return;
                }

                const showEditor = () => {
                    previousValue = input.value;
                    error.hidden = true;
                    error.textContent = '';
                    view.hidden = true;
                    form.hidden = false;
                    input.focus();
                    input.select();
                };

                const hideEditor = () => {
                    form.hidden = true;
                    view.hidden = false;
                };

                const savePaintCode = async () => {
                    if (isSaving) {
                        return;
                    }

                    isSaving = true;
                    error.hidden = true;
                    error.textContent = '';
                    input.disabled = true;
                    saveButton.disabled = true;
                    cancelButton.disabled = true;

                    try {
                        const csrfToken = form.querySelector('input[name="_token"]')?.value || '';
                        const response = await fetch(form.action, {
                            method: 'PATCH',
                            body: JSON.stringify({
                                paint_code: input.value,
                            }),
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        const data = await response.json().catch(() => ({}));

                        if (! response.ok) {
                            throw new Error(data.message || 'Не удалось сохранить маркировку цвета.');
                        }

                        previousValue = data.paint_code || '';
                        input.value = previousValue;
                        value.textContent = previousValue || emptyLabel;
                        addSuggestion(previousValue);
                        hideEditor();
                        editButton.focus();
                    } catch (saveError) {
                        error.textContent = saveError.message || 'Не удалось сохранить маркировку цвета.';
                        error.hidden = false;
                    } finally {
                        input.disabled = false;
                        saveButton.disabled = false;
                        cancelButton.disabled = false;
                        isSaving = false;
                    }
                };

                editButton.addEventListener('click', showEditor);
                cancelButton.addEventListener('click', () => {
                    input.value = previousValue;
                    error.hidden = true;
                    error.textContent = '';
                    hideEditor();
                    editButton.focus();
                });
                input.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        input.value = previousValue;
                        hideEditor();
                        editButton.focus();
                    }

                    if (event.key === 'Enter' && ! event.isComposing) {
                        event.preventDefault();
                        savePaintCode();
                    }
                });

                form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    savePaintCode();
                });
            });
        })();
    </script>
@endsection
