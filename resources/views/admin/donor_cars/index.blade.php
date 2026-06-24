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

        <div class="donor-parts-search" data-donor-parts-search-url="{{ route('admin.donor-cars.parts.search') }}">
            <label for="donor-parts-search">Поиск запчастей по всем донорам</label>
            <input id="donor-parts-search" type="search" placeholder="Название или артикул" autocomplete="off" data-donor-parts-search-input>
            <div class="donor-parts-search__results" data-donor-parts-search-results hidden></div>
        </div>

        <style>
            .donor-parts-search { position: relative; display: grid; gap: 8px; max-width: 720px; margin-bottom: 16px; }
            .donor-parts-search label { font-weight: 700; }
            .donor-parts-search input { width: 100%; }
            .donor-parts-search__results { position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 20; display: grid; max-height: 360px; overflow: auto; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; box-shadow: 0 18px 45px rgba(15, 23, 42, .16); }
            .donor-parts-search__item { display: grid; grid-template-columns: 56px minmax(0, 1fr); gap: 10px; align-items: center; padding: 9px 12px; color: inherit; text-align: left; text-decoration: none; border-bottom: 1px solid var(--line); background: transparent; }
            .donor-parts-search__item:hover,
            .donor-parts-search__item:focus { background: var(--accent-soft); outline: none; }
            .donor-parts-search__item:last-child { border-bottom: 0; }
            .donor-parts-search__thumb { display: grid; place-items: center; width: 56px; height: 42px; overflow: hidden; border: 1px solid var(--line); border-radius: 6px; background: var(--accent-soft); color: var(--muted); font-size: 10px; font-weight: 700; line-height: 1.1; text-align: center; }
            .donor-parts-search__thumb img { width: 100%; height: 100%; object-fit: cover; }
            .donor-parts-search__body { display: grid; min-width: 0; gap: 4px; }
            .donor-parts-search__title { font-weight: 700; }
            .donor-parts-search__status { justify-self: start; padding: 2px 8px; border-radius: 999px; background: var(--accent-soft); color: var(--accent); font-size: 12px; font-weight: 700; line-height: 1.35; }
            .donor-parts-search__status--unknown { background: #fff3cd; color: #866000; }
            .donor-parts-search__status--sold { background: #dbeafe; color: #1d4ed8; }
            .donor-parts-search__meta { color: var(--muted); font-size: 12px; line-height: 1.35; }
            .donor-parts-search__empty { padding: 11px 12px; color: var(--muted); font-size: 13px; }
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
                <th><span class="donor-table-heading"><span>Фото</span><span>&nbsp;</span></span></th>
                <th><a href="{{ $sortUrl('vin') }}"><span>VIN{{ $sortMark('vin') }}</span><span>&nbsp;</span></a></th>
                <th><a href="{{ $sortUrl('status') }}"><span>Статус{{ $sortMark('status') }}</span><span>&nbsp;</span></a></th>
                <th><a href="{{ $sortUrl('purchase_date') }}"><span>Дата</span><span>покупки{{ $sortMark('purchase_date') }}</span></a></th>
                <th><a href="{{ $sortUrl('warehouse_arrival_date') }}"><span>Дата прихода</span><span>на СТО{{ $sortMark('warehouse_arrival_date') }}</span></a></th>
                <th><a href="{{ $sortUrl('model') }}"><span>Модель{{ $sortMark('model') }}</span><span>&nbsp;</span></a></th>
                <th><span class="donor-table-heading"><span>Привод</span><span>&nbsp;</span></span></th>
                <th><a href="{{ $sortUrl('year') }}"><span>Год{{ $sortMark('year') }}</span><span>&nbsp;</span></a></th>
                <th><a href="{{ $sortUrl('mileage') }}"><span>Пробег{{ $sortMark('mileage') }}</span><span>&nbsp;</span></a></th>
                <th><span class="donor-table-heading"><span>Цвет</span><span>&nbsp;</span></span></th>
                <th><span class="donor-table-heading"><span>Маркировка</span><span>цвета</span></span></th>
                <th><a href="{{ $sortUrl('products_count') }}"><span>Кол-во</span><span>запчастей{{ $sortMark('products_count') }}</span></a></th>
                <th><a href="{{ $sortUrl('part_sales_count') }}"><span>Продано</span><span>запчастей{{ $sortMark('part_sales_count') }}</span></a></th>
                <th><a href="{{ $sortUrl('sold_parts_amount') }}"><span>Сумма</span><span>продаж{{ $sortMark('sold_parts_amount') }}</span></a></th>
                <th><a href="{{ $sortUrl('total_cost_usd') }}"><span>Полная</span><span>стоимость{{ $sortMark('total_cost_usd') }}</span></a></th>
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
                <th>Цвет</th>
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
                                <button type="button" class="btn-secondary donor-paint-code__edit" title="Редактировать маркировку цвета" aria-label="Редактировать маркировку цвета" data-donor-paint-code-edit>&#9998;</button>
                            </div>
                            <form method="POST" action="{{ route('admin.donor-cars.paint-code.update', $donorCar) }}" class="donor-paint-code__form" data-donor-paint-code-form hidden>
                                @csrf
                                <input type="text" name="paint_code" value="{{ $donorCar->paint_code }}" maxlength="50" list="donor-paint-code-suggestions" autocomplete="off" data-donor-paint-code-input>
                                <button type="submit" class="btn-secondary donor-paint-code__save" title="Сохранить" aria-label="Сохранить" data-donor-paint-code-save>&#10003;</button>
                                <button type="button" class="btn-secondary donor-paint-code__cancel" title="Отмена" aria-label="Отмена" data-donor-paint-code-cancel>&#215;</button>
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
            const root = document.querySelector('[data-donor-parts-search-url]');
            const input = root?.querySelector('[data-donor-parts-search-input]');
            const results = root?.querySelector('[data-donor-parts-search-results]');
            let searchTimeout = null;
            let searchController = null;

            if (!root || !input || !results) {
                return;
            }

            const hideResults = () => {
                results.hidden = true;
                results.innerHTML = '';
            };

            const renderResults = (items) => {
                results.innerHTML = '';

                if (!items.length) {
                    const empty = document.createElement('div');
                    empty.className = 'donor-parts-search__empty';
                    empty.textContent = 'Ничего не найдено';
                    results.appendChild(empty);
                    results.hidden = false;

                    return;
                }

                items.forEach((item) => {
                    const link = document.createElement('a');
                    link.className = 'donor-parts-search__item';
                    link.href = item.url || '#';

                    const thumb = document.createElement('span');
                    thumb.className = 'donor-parts-search__thumb';

                    if (item.donor_photo_url) {
                        const image = document.createElement('img');
                        image.src = item.donor_photo_url;
                        image.alt = item.donor ? `\u041f\u0440\u0435\u0432\u044c\u044e ${item.donor}` : '\u041f\u0440\u0435\u0432\u044c\u044e \u0434\u043e\u043d\u043e\u0440\u0430';
                        image.loading = 'lazy';
                        image.decoding = 'async';
                        thumb.appendChild(image);
                    } else {
                        thumb.textContent = '\u041d\u0435\u0442 \u0444\u043e\u0442\u043e';
                    }

                    const body = document.createElement('span');
                    body.className = 'donor-parts-search__body';

                    const title = document.createElement('span');
                    title.className = 'donor-parts-search__title';
                    title.textContent = item.name || item.part_number || '-';

                    const status = document.createElement('span');
                    status.className = 'donor-parts-search__status';
                    status.textContent = item.status || '';
                    status.hidden = !item.status;
                    status.classList.toggle('donor-parts-search__status--unknown', String(item.status || '').trim() === '\u041d\u0435\u0438\u0437\u0432\u0435\u0441\u0442\u043d\u043e');
                    status.classList.toggle('donor-parts-search__status--sold', String(item.status || '').trim() === '\u041f\u0440\u043e\u0434\u0430\u043d');

                    const meta = document.createElement('span');
                    meta.className = 'donor-parts-search__meta';
                    meta.textContent = item.meta || item.donor || '\u00a0';

                    body.append(title, status, meta);
                    link.append(thumb, body);
                    results.appendChild(link);
                });

                results.hidden = false;
            };

            input.addEventListener('input', () => {
                const query = input.value.trim();
                window.clearTimeout(searchTimeout);

                if (query.length < 2) {
                    hideResults();
                    return;
                }

                searchTimeout = window.setTimeout(async () => {
                    searchController?.abort();
                    searchController = new AbortController();

                    try {
                        const url = new URL(root.dataset.donorPartsSearchUrl, window.location.origin);
                        url.searchParams.set('q', query);

                        const response = await fetch(url, {
                            headers: { Accept: 'application/json' },
                            signal: searchController.signal,
                        });

                        if (!response.ok) {
                            hideResults();
                            return;
                        }

                        renderResults(await response.json());
                    } catch (error) {
                        if (error.name !== 'AbortError') {
                            hideResults();
                        }
                    }
                }, 220);
            });

            document.addEventListener('click', (event) => {
                if (!root.contains(event.target)) {
                    hideResults();
                }
            });

            input.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    hideResults();
                }
            });
        })();

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
