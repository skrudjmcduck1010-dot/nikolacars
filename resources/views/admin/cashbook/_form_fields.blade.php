<div class="form-grid">
    <div>
        <label for="{{ $formPrefix }}operation_date">Дата</label>
        <input id="{{ $formPrefix }}operation_date" type="date" name="operation_date" value="{{ old('operation_date', optional($transaction->operation_date)->format('Y-m-d')) }}" required>
        @error('operation_date')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div>
        <label for="{{ $formPrefix }}label">Метка</label>
        @php($selectedLabel = $transaction->exists ? $transaction->label : old('label', $transaction->label))
        @php($labelTypes = $labelTypes ?? collect())
        @php($labelParents = $labelParents ?? collect())
        @php($parentLabels = $parentLabels ?? collect())
        @php($labelsWithoutEmployeeField = collect($labelsWithoutEmployeeField ?? [
            'Аренда',
            'Возврат Запчасти и денег',
            'Донор',
            'Закупка ЗЧК',
            'Инкассо Валера',
            'Инструмент',
            'Коммунальные',
            'Налоги',
            'Продукты',
            'Прочие',
            '',
            '',
            ' ',
            'Сайт',
            'Связь',
            'Транспортные ЗЧ',
            ' ',
        ]))
        @if ($transaction->exists)
            <input id="{{ $formPrefix }}label" value="{{ $selectedLabel ?: 'без метки' }}" disabled>
            <input type="hidden" name="label" value="{{ $transaction->label }}">
        @else
            <select id="{{ $formPrefix }}label" name="label" required>
                <option value="">Выберите метку</option>
                @foreach ($labels as $label)
                    <option
                        value="{{ $label }}"
                        data-cashbook-label-type="{{ $labelTypes[$label] ?? 'income' }}"
                        data-cashbook-parent-label="{{ $labelParents[$label] ?? '' }}"
                        data-cashbook-has-children="{{ $parentLabels->contains($label) ? '1' : '0' }}"
                        data-cashbook-expense-label="{{ ($labelTypes[$label] ?? 'income') === 'expense' ? '1' : '0' }}"
                        data-cashbook-hide-employee="{{ $labelsWithoutEmployeeField->contains($label) ? '1' : '0' }}"
                        @disabled($parentLabels->contains($label))
                        @selected($selectedLabel === $label)
                    >{{ $label }}</option>
                @endforeach
                @if ($selectedLabel && ! $labels->contains($selectedLabel))
                    <option value="{{ $selectedLabel }}" selected>{{ $selectedLabel }}</option>
                @endif
            </select>
        @endif
        @error('label')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div data-cashbook-employee-field>
        <label for="{{ $formPrefix }}employee">Ответственный</label>
        @php($activeEmployees = $activeEmployees ?? collect())
        @php($activeMechanicEmployees = $activeMechanicEmployees ?? collect())
        @php($selectedEmployee = old('employee', $transaction->employee))
        <select id="{{ $formPrefix }}employee" name="employee">
            <option value="">Выберите ответственного</option>
            @foreach ($activeEmployees as $employee)
                <option
                    value="{{ $employee }}"
                    data-cashbook-mechanic="{{ $activeMechanicEmployees->contains($employee) ? '1' : '0' }}"
                    @selected($selectedEmployee === $employee)
                >{{ $employee }}</option>
            @endforeach
            @if ($selectedEmployee && ! $activeEmployees->contains($selectedEmployee))
                <option value="{{ $selectedEmployee }}" selected>{{ $selectedEmployee }}</option>
            @endif
        </select>
        @error('employee')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div data-cashbook-vin-field>
        <label for="{{ $formPrefix }}vehicle_vin">VIN авто</label>
        @php($donorCars = $donorCars ?? collect())
        @php($selectedVin = old('vehicle_vin', $transaction->vehicle_vin))
        <select
            id="{{ $formPrefix }}vehicle_vin"
            name="vehicle_vin"
            data-cashbook-vin-select
            data-add-donor-url="{{ route('admin.donor-cars.create') }}"
        >
            <option value="">Выберите VIN донора</option>
            @foreach ($donorCars as $donorCar)
                @php($donorModel = $donorCar->display_model)
                @php($filledDonorExpenseTypes = collect([
                    'purchase_with_fees' => $donorCar->estimated_cost_usd,
                    'usa_delivery' => $donorCar->usa_delivery_price_usd,
                    'klaipeda_ukraine_delivery' => $donorCar->klaipeda_ukraine_delivery_price_usd,
                    'customs_clearance' => $donorCar->customs_clearance_price_usd,
                ])->filter(fn ($value) => $value !== null)->keys()->values())
                <option value="{{ $donorCar->vin }}" data-donor-filled-expense-types='@json($filledDonorExpenseTypes)' @selected($selectedVin === $donorCar->vin)>
                    {{ collect([$donorCar->display_vin, $donorModel, $donorCar->purchase_date?->format('d.m.Y')])->filter()->implode(' - ') }}
                </option>
            @endforeach
            @if ($selectedVin && ! $donorCars->contains('vin', $selectedVin))
                <option value="{{ $selectedVin }}" selected>{{ $selectedVin }}</option>
            @endif
            <option value="__add_donor__">Добавить Донора</option>
        </select>
        @error('vehicle_vin')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div data-cashbook-donor-expense-field hidden>
        <label for="{{ $formPrefix }}donor_expense_type">Статья расхода донора</label>
        @php($selectedDonorExpenseType = old('donor_expense_type'))
        <select id="{{ $formPrefix }}donor_expense_type" name="donor_expense_type" data-cashbook-donor-expense-select disabled>
            <option value="">Выберите статью</option>
            <option value="purchase_with_fees" @selected($selectedDonorExpenseType === 'purchase_with_fees')>Цена покупки(со сборами)</option>
            <option value="usa_delivery" @selected($selectedDonorExpenseType === 'usa_delivery')>Цена доставка США</option>
            <option value="klaipeda_ukraine_delivery" @selected($selectedDonorExpenseType === 'klaipeda_ukraine_delivery')>Цена Доставка Клайпеда-Украина</option>
            <option value="customs_clearance" @selected($selectedDonorExpenseType === 'customs_clearance')>Растаможка</option>
        </select>
        @error('donor_expense_type')<div class="error">{{ $message }}</div>@enderror
    </div>

    @php($incomeBankUah = (float) old('income_bank_uah', $transaction->income_bank_uah ?? 0))
    @php($incomeCashUah = (float) old('income_cash_uah', $transaction->income_cash_uah ?? 0))
    @php($selectedIncomePaymentMethod = old('income_payment_method', $incomeBankUah > 0 ? 'bank' : 'cash'))
    @php($incomeUah = old('income_uah', $selectedIncomePaymentMethod === 'bank' ? $incomeBankUah : $incomeCashUah))
    <div data-cashbook-income-field>
        <label for="{{ $formPrefix }}income_uah">ГРН</label>
        <div style="display:grid;grid-template-columns:minmax(0,1fr) 120px;gap:8px;">
            <input id="{{ $formPrefix }}income_uah" type="number" step="0.01" min="0" name="income_uah" value="{{ $incomeUah }}">
            <select id="{{ $formPrefix }}income_payment_method" name="income_payment_method" aria-label="Тип оплаты">
                <option value="cash" @selected($selectedIncomePaymentMethod === 'cash')>Нал</option>
                <option value="bank" @selected($selectedIncomePaymentMethod === 'bank')>БезНал</option>
            </select>
        </div>
        @error('income_uah')<div class="error">{{ $message }}</div>@enderror
        @error('income_bank_uah')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div data-cashbook-income-field></div>
    <div data-cashbook-income-field>
        <label for="{{ $formPrefix }}income_cash_usd">Приход $</label>
        <input id="{{ $formPrefix }}income_cash_usd" type="number" step="0.01" min="0" name="income_cash_usd" value="{{ old('income_cash_usd', $transaction->income_cash_usd ?? 0) }}">
        @error('income_cash_usd')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div data-cashbook-income-field></div>

    @php($expenseBankUah = (float) old('expense_bank_uah', $transaction->expense_bank_uah ?? 0))
    @php($expenseCashUah = (float) old('expense_cash_uah', $transaction->expense_cash_uah ?? 0))
    @php($selectedExpensePaymentMethod = old('expense_payment_method', $expenseBankUah > 0 ? 'bank' : 'cash'))
    @php($expenseUah = old('expense_uah', $selectedExpensePaymentMethod === 'bank' ? $expenseBankUah : $expenseCashUah))
    <div data-cashbook-expense-field>
        <label for="{{ $formPrefix }}expense_uah">ГРН</label>
        <div style="display:grid;grid-template-columns:minmax(0,1fr) 120px;gap:8px;">
            <input id="{{ $formPrefix }}expense_uah" type="number" step="0.01" min="0" name="expense_uah" value="{{ $expenseUah }}">
            <select id="{{ $formPrefix }}expense_payment_method" name="expense_payment_method" aria-label="Тип оплаты">
                <option value="cash" @selected($selectedExpensePaymentMethod === 'cash')>Нал</option>
                <option value="bank" @selected($selectedExpensePaymentMethod === 'bank')>БезНал</option>
            </select>
        </div>
        @error('expense_uah')<div class="error">{{ $message }}</div>@enderror
        @error('expense_bank_uah')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div data-cashbook-expense-field></div>
    <div data-cashbook-expense-field>
        <label for="{{ $formPrefix }}expense_cash_usd"> $</label>
        <input id="{{ $formPrefix }}expense_cash_usd" type="number" step="0.01" min="0" name="expense_cash_usd" value="{{ old('expense_cash_usd', $transaction->expense_cash_usd ?? 0) }}">
        @error('expense_cash_usd')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div data-cashbook-expense-field></div>

    @unless($transaction->exists)
    @php($oldPurchaseItems = old('purchase_items', [['quantity' => 1, 'currency' => 'USD']]))
    <style>
        .purchase-line-grid {
            display: grid;
            grid-template-columns: minmax(180px, 1.4fr) minmax(130px, 1fr) minmax(130px, 1fr) 90px 120px 120px 90px;
            gap: 16px;
        }

        @media (max-width: 980px) {
            .purchase-line-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <div class="full" data-parts-purchase-section data-parts-purchase-label="{{ $partsPurchaseLabel ?? 'Закупка ЗЧК' }}" hidden>
        <div class="panel" style="box-shadow:none;">
            <h3 style="margin:0 0 14px;">Закупка запчастей</h3>
            <div class="form-grid">
                <div>
                    <label for="{{ $formPrefix }}purchase_counterparty_id">Поставщик</label>
                    <select id="{{ $formPrefix }}purchase_counterparty_id" name="purchase_counterparty_id" data-parts-purchase-input disabled>
                        <option value="">Выберите поставщика</option>
                        @foreach (($purchaseCounterparties ?? collect()) as $counterparty)
                            <option value="{{ $counterparty->id }}" @selected(old('purchase_counterparty_id') == $counterparty->id)>{{ $counterparty->name }}</option>
                        @endforeach
                    </select>
                    @error('purchase_counterparty_id')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label for="{{ $formPrefix }}purchase_document_number">Номер документа</label>
                    <input id="{{ $formPrefix }}purchase_document_number" name="purchase_document_number" value="{{ old('purchase_document_number') }}" data-parts-purchase-input disabled>
                    @error('purchase_document_number')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
            <div data-parts-purchase-items style="display:grid;gap:12px;margin-top:14px;">
                @foreach ($oldPurchaseItems as $index => $item)
                    <div class="purchase-item-row" data-parts-purchase-row>
                        <div class="purchase-line-grid">
                            <div>
                                <label>Товар</label>
                                <select name="purchase_items[{{ $index }}][product_id]" data-parts-purchase-input disabled>
                                    <option value="">Выберите товар</option>
                                    @foreach (($purchaseProducts ?? collect()) as $product)
                                        <option value="{{ $product->id }}" @selected(($item['product_id'] ?? null) == $product->id)>{{ $product->sku }} · {{ $product->name }}</option>
                                    @endforeach
                                </select>
                                @error("purchase_items.{$index}.product_id")<div class="error">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label>Склад</label>
                                <select name="purchase_items[{{ $index }}][warehouse_id]" data-parts-purchase-input data-parts-purchase-warehouse-select disabled>
                                    <option value="">Склад</option>
                                    @foreach (($purchaseWarehouses ?? collect()) as $warehouse)
                                        <option value="{{ $warehouse->id }}" @selected(($item['warehouse_id'] ?? null) == $warehouse->id)>{{ $warehouse->name }}</option>
                                    @endforeach
                                </select>
                                @error("purchase_items.{$index}.warehouse_id")<div class="error">{{ $message }}</div>@enderror
                            </div>
                            <div data-parts-purchase-floor-wrap hidden>
                                <label>Этаж</label>
                                <select data-parts-purchase-input data-parts-purchase-floor-select disabled>
                                    <option value="">Этаж</option>
                                </select>
                            </div>
                            <div data-parts-purchase-location-wrap>
                                <label>Ячейка</label>
                                <select name="purchase_items[{{ $index }}][location_id]" data-parts-purchase-input data-parts-purchase-location-select data-selected-location="{{ $item['location_id'] ?? '' }}" disabled>
                                    <option value="">Ячейка</option>
                                    @foreach (($purchaseLocations ?? collect()) as $location)
                                        <option value="{{ $location->id }}" @selected(($item['location_id'] ?? null) == $location->id)>{{ $location->full_code }}</option>
                                    @endforeach
                                </select>
                                @error("purchase_items.{$index}.location_id")<div class="error">{{ $message }}</div>@enderror
                            </div>
                            <div><label>Кол-во</label><input type="number" min="1" name="purchase_items[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? 1 }}" data-parts-purchase-input disabled></div>
                            <div><label>Закуп</label><input type="number" step="0.01" min="0" name="purchase_items[{{ $index }}][purchase_price]" value="{{ $item['purchase_price'] ?? '' }}" data-parts-purchase-input disabled></div>
                            <div><label>Продажа</label><input type="number" step="0.01" min="0" name="purchase_items[{{ $index }}][selling_price]" value="{{ $item['selling_price'] ?? '' }}" data-parts-purchase-input disabled></div>
                            <div><label>Валюта</label><input name="purchase_items[{{ $index }}][currency]" value="{{ $item['currency'] ?? 'USD' }}" maxlength="3" data-parts-purchase-input disabled></div>
                        </div>
                        <div style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;margin-top:8px;">
                            <input name="purchase_items[{{ $index }}][comment]" value="{{ $item['comment'] ?? '' }}" placeholder="Комментарий к позиции" data-parts-purchase-input disabled>
                            <button type="button" class="btn btn-small btn-secondary" data-remove-parts-purchase-row>Убрать</button>
                        </div>
                    </div>
                @endforeach
            </div>
            <template data-parts-purchase-template>
                <div class="purchase-item-row" data-parts-purchase-row>
                    <div class="purchase-line-grid">
                        <div><label>Товар</label><select name="purchase_items[__INDEX__][product_id]" data-parts-purchase-input disabled><option value="">Выберите товар</option>@foreach (($purchaseProducts ?? collect()) as $product)<option value="{{ $product->id }}">{{ $product->sku }} · {{ $product->name }}</option>@endforeach</select></div>
                        <div><label>Склад</label><select name="purchase_items[__INDEX__][warehouse_id]" data-parts-purchase-input data-parts-purchase-warehouse-select disabled><option value="">Склад</option>@foreach (($purchaseWarehouses ?? collect()) as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
                        <div data-parts-purchase-floor-wrap hidden><label>Этаж</label><select data-parts-purchase-input data-parts-purchase-floor-select disabled><option value="">Этаж</option></select></div>
                        <div data-parts-purchase-location-wrap><label>Ячейка</label><select name="purchase_items[__INDEX__][location_id]" data-parts-purchase-input data-parts-purchase-location-select disabled><option value="">Ячейка</option>@foreach (($purchaseLocations ?? collect()) as $location)<option value="{{ $location->id }}">{{ $location->full_code }}</option>@endforeach</select></div>
                        <div><label>Кол-во</label><input type="number" min="1" name="purchase_items[__INDEX__][quantity]" value="1" data-parts-purchase-input disabled></div>
                        <div><label>Закуп</label><input type="number" step="0.01" min="0" name="purchase_items[__INDEX__][purchase_price]" data-parts-purchase-input disabled></div>
                        <div><label>Продажа</label><input type="number" step="0.01" min="0" name="purchase_items[__INDEX__][selling_price]" data-parts-purchase-input disabled></div>
                        <div><label>Валюта</label><input name="purchase_items[__INDEX__][currency]" value="USD" maxlength="3" data-parts-purchase-input disabled></div>
                    </div>
                    <div style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;margin-top:8px;">
                        <input name="purchase_items[__INDEX__][comment]" placeholder="Комментарий к позиции" data-parts-purchase-input disabled>
                        <button type="button" class="btn btn-small btn-secondary" data-remove-parts-purchase-row>Убрать</button>
                    </div>
                </div>
            </template>
            <div class="actions" style="margin-top:14px;">
                <button type="button" class="btn btn-secondary" data-add-parts-purchase-row>Добавить позицию</button>
            </div>
        </div>
    </div>
    @endunless

    <div class="full">
        <label for="{{ $formPrefix }}comment">Комментарий операции</label>
        <textarea id="{{ $formPrefix }}comment" name="comment" required>{{ old('comment', $transaction->comment) }}</textarea>
        @error('comment')<div class="error">{{ $message }}</div>@enderror
    </div>
</div>
<script>
    (() => {
        document.querySelectorAll('[data-parts-purchase-section]').forEach((section) => {
            const form = section.closest('form');
            if (!form || section.dataset.partsPurchaseReady === '1') return;

            section.dataset.partsPurchaseReady = '1';

            const labelSelect = form.querySelector('[name="label"]');
            const transactionTypeInput = form.querySelector('[name="transaction_type"]');
            const itemsRoot = section.querySelector('[data-parts-purchase-items]');
            const template = section.querySelector('[data-parts-purchase-template]');
            const addButton = section.querySelector('[data-add-parts-purchase-row]');
            const commentInput = form.querySelector('[name="comment"]');
            const warehouses = @json($purchaseWarehouseOptions ?? []);
            const locations = @json($purchaseLocationOptions ?? []);

            const setInputsEnabled = (enabled) => {
                section.querySelectorAll('[data-parts-purchase-input]').forEach((input) => {
                    input.disabled = !enabled;
                });
            };

            const syncVisibility = () => {
                const isPurchaseLabel = labelSelect?.value === section.dataset.partsPurchaseLabel;
                const mode = transactionTypeInput?.value;
                const shouldShow = isPurchaseLabel && (!mode || mode === 'expense');

                section.hidden = !shouldShow;
                setInputsEnabled(shouldShow);
            };

            const nextIndex = () => section.querySelectorAll('[data-parts-purchase-row]').length;

            const bindRow = (row) => {
                const warehouseSelect = row.querySelector('[data-parts-purchase-warehouse-select]');
                const floorWrap = row.querySelector('[data-parts-purchase-floor-wrap]');
                const floorSelect = row.querySelector('[data-parts-purchase-floor-select]');
                const locationWrap = row.querySelector('[data-parts-purchase-location-wrap]');
                const locationSelect = row.querySelector('[data-parts-purchase-location-select]');

                if (!warehouseSelect || !floorWrap || !floorSelect || !locationWrap || !locationSelect) return;

                const renderLocations = () => {
                    const warehouse = warehouses.find((item) => String(item.id) === warehouseSelect.value);
                    const warehouseLocations = locations.filter((item) => String(item.warehouse_id) === warehouseSelect.value);
                    const locationFloors = [...new Set(warehouseLocations.map((item) => item.floor).filter(Boolean))];
                    const floors = warehouse?.floors?.length ? warehouse.floors.map((floor) => floor.value) : locationFloors;
                    const hasLocations = warehouseLocations.length > 0;
                    const selectedLocation = warehouseLocations.find((item) => String(item.id) === String(locationSelect.dataset.selectedLocation || locationSelect.value));
                    const selectedFloor = floors.includes(floorSelect.value)
                        ? floorSelect.value
                        : selectedLocation?.floor || '';

                    floorWrap.hidden = !warehouse || Number(warehouse.floor_count || 1) <= 1 || floors.length <= 1;
                    floorSelect.innerHTML = '<option value="">Этаж</option>' + floors.map((floor) => `<option value="${floor}">${floor.replace('floor_', 'Этаж ')}</option>`).join('');
                    floorSelect.value = selectedFloor;
                    locationWrap.hidden = Boolean(warehouse) && !hasLocations;
                    locationSelect.disabled = Boolean(warehouse) && !hasLocations;
                    locationSelect.required = !locationSelect.disabled;

                    const renderCells = () => {
                        const selectedLocation = locationSelect.dataset.selectedLocation || locationSelect.value;
                        const selectedFloor = floorWrap.hidden ? '' : floorSelect.value;
                        const filtered = warehouseLocations.filter((item) => !selectedFloor || item.floor === selectedFloor);
                        locationSelect.innerHTML = '<option value="">Ячейка</option>' + filtered.map((item) => `<option value="${item.id}" ${String(item.id) === String(selectedLocation) ? 'selected' : ''}>${item.full_code}</option>`).join('');
                        locationSelect.dataset.selectedLocation = '';
                    };

                    floorSelect.onchange = renderCells;
                    renderCells();
                };

                warehouseSelect.addEventListener('change', renderLocations);
                renderLocations();
            };

            section.querySelectorAll('[data-parts-purchase-row]').forEach(bindRow);

            addButton?.addEventListener('click', () => {
                if (!template || !itemsRoot) return;

                const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex()));
                const wrapper = document.createElement('div');
                wrapper.innerHTML = html.trim();
                const row = wrapper.firstElementChild;

                itemsRoot.appendChild(row);
                bindRow(row);
                syncVisibility();
            });

            section.addEventListener('click', (event) => {
                const button = event.target.closest('[data-remove-parts-purchase-row]');
                if (!button) return;

                const rows = section.querySelectorAll('[data-parts-purchase-row]');
                if (rows.length <= 1) return;

                button.closest('[data-parts-purchase-row]')?.remove();
            });

            labelSelect?.addEventListener('change', syncVisibility);
            transactionTypeInput?.addEventListener('change', syncVisibility);
            form.addEventListener('submit', () => {
                if (!section.hidden && commentInput && !commentInput.value.trim()) {
                    commentInput.value = section.dataset.partsPurchaseLabel;
                }
            });
            syncVisibility();
        });
    })();
</script>
