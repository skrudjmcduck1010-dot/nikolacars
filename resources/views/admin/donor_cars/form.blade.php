@extends('layouts.admin', ['heading' => $donorCar->exists ? html_entity_decode('&#1056;&#1077;&#1076;&#1072;&#1082;&#1090;&#1080;&#1088;&#1086;&#1074;&#1072;&#1085;&#1080;&#1077; &#1076;&#1086;&#1085;&#1086;&#1088;&#1089;&#1082;&#1086;&#1075;&#1086; &#1072;&#1074;&#1090;&#1086;&#1084;&#1086;&#1073;&#1080;&#1083;&#1103;') : html_entity_decode('&#1053;&#1086;&#1074;&#1099;&#1081; &#1076;&#1086;&#1085;&#1086;&#1088;&#1089;&#1082;&#1080;&#1081; &#1072;&#1074;&#1090;&#1086;&#1084;&#1086;&#1073;&#1080;&#1083;&#1100;')])

@section('content')
    <form method="POST" action="{{ $donorCar->exists ? route('admin.donor-cars.update', $donorCar) : route('admin.donor-cars.store') }}" class="panel" enctype="multipart/form-data">
        @csrf
        @if($donorCar->exists) @method('PUT') @endif
        <div class="form-grid">
            <div>
                <label>VIN</label>
                @if($donorCar->exists)
                    <div class="readonly-value">{{ $donorCar->vin }}</div>
                @else
                    <input name="vin" value="{{ old('vin', $donorCar->vin) }}" required data-vin-input>
                @endif
                @error('vin')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label>&#1057;&#1090;&#1072;&#1090;&#1091;&#1089;</label>
                <div class="readonly-value">{{ $donorCar->exists ? $donorCar->status_label : \App\Support\CatalogTextEncoding::repair(\App\Models\DonorCar::STATUSES[\App\Models\DonorCar::STATUS_IN_TRANSIT]) }}</div>
            </div>
            <div>
                <label>&#1052;&#1072;&#1088;&#1082;&#1072;</label>
                @if($donorCar->exists)
                    <div class="readonly-value">{{ $donorCar->brand }}</div>
                @else
                    <select name="brand" required data-brand-input>
                        @foreach($brands as $brand)
                            <option value="{{ $brand }}" @selected(old('brand', $donorCar->brand ?: 'Tesla') === $brand)>{{ $brand }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
            <div>
                <label>&#1052;&#1086;&#1076;&#1077;&#1083;&#1100;</label>
                @php($selectedModel = old('model', $donorCar->model))
                @if($donorCar->exists)
                    <div class="readonly-value">{{ $donorCar->model }}</div>
                @else
                    <select name="model" required data-model-input>
                        <option value="">&#1042;&#1099;&#1073;&#1077;&#1088;&#1080;&#1090;&#1077; &#1084;&#1086;&#1076;&#1077;&#1083;&#1100;</option>
                        @foreach($models as $model)
                            <option value="{{ $model }}" @selected($selectedModel === $model)>{{ $model }}</option>
                        @endforeach
                        @if($selectedModel && ! in_array($selectedModel, $models, true))
                            <option value="{{ $selectedModel }}" selected>{{ $selectedModel }}</option>
                        @endif
                    </select>
                @endif
            </div>
            <div><label>&#1043;&#1086;&#1076;</label><input type="number" name="year" value="{{ old('year', $donorCar->year) }}" min="1990" max="2100" inputmode="numeric" data-year-input></div>
            <div>
                <label>Привод</label>
                <select name="drive_type">
                    <option value="">Выберите привод</option>
                    @foreach(\App\Models\DonorCar::DRIVE_TYPES as $driveType => $label)
                        <option value="{{ $driveType }}" @selected(old('drive_type', $donorCar->drive_type) === $driveType)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('drive_type')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label>Батарея</label>
                <select name="battery_type" data-battery-type-input>
                    <option value="">Выберите батарею</option>
                    @foreach(\App\Models\DonorCar::batteryTypeOptionsForModel(old('model', $donorCar->model)) as $batteryType => $label)
                        <option value="{{ $batteryType }}" @selected(old('battery_type', $donorCar->battery_type) === $batteryType)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('battery_type')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label>Performance</label>
                <select name="is_performance">
                    <option value="">Выберите</option>
                    @foreach(\App\Models\DonorCar::PERFORMANCE_OPTIONS as $value => $label)
                        <option value="{{ $value }}" @selected((string) old('is_performance', $donorCar->is_performance === null ? '' : (int) $donorCar->is_performance) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('is_performance')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div><label>&#1062;&#1074;&#1077;&#1090;</label><input name="color" value="{{ old('color', $donorCar->color) }}" required></div>
            <div><label>&#1052;&#1072;&#1088;&#1082;&#1080;&#1088;&#1086;&#1074;&#1082;&#1072; &#1094;&#1074;&#1077;&#1090;&#1072;</label><input name="paint_code" value="{{ old('paint_code', $donorCar->paint_code) }}" maxlength="50" placeholder="PPMR"></div>
            <div>
                <label>&#1055;&#1088;&#1086;&#1073;&#1077;&#1075;</label>
                <input type="number" name="mileage" value="{{ old('mileage', $donorCar->mileage) }}" min="0" max="2000000" inputmode="numeric" required>
            </div>
            <div>
                <label>&#1044;&#1072;&#1090;&#1072; &#1087;&#1086;&#1082;&#1091;&#1087;&#1082;&#1080; &#1076;&#1086;&#1085;&#1086;&#1088;&#1072;</label>
                <input type="date" name="purchase_date" value="{{ old('purchase_date', $donorCar->purchase_date?->format('Y-m-d')) }}" @required(! $donorCar->exists)>
            </div>
            <div>
                <label>&#1044;&#1072;&#1090;&#1072; &#1087;&#1088;&#1080;&#1093;&#1086;&#1076;&#1072; &#1076;&#1086;&#1085;&#1086;&#1088;&#1072; &#1085;&#1072; &#1057;&#1058;&#1054;</label>
                <input type="date" name="warehouse_arrival_date" value="{{ old('warehouse_arrival_date', $donorCar->warehouse_arrival_date?->format('Y-m-d')) }}">
            </div>
            <div>
                <label>Цена покупки(Со сборами) ($)</label>
                @if($donorCar->exists && $donorCar->isDonorExpenseFieldLocked('estimated_cost_usd'))
                    <div class="readonly-value">{{ $donorCar->estimated_cost_usd }}</div>
                    <div class="help">Заполнено из {{ $donorCar->donorExpenseSourceLabelFor('estimated_cost_usd') }}</div>
                @else
                    <input type="number" name="estimated_cost_usd" value="{{ old('estimated_cost_usd', $donorCar->estimated_cost_usd) }}" min="0" step="0.01" inputmode="decimal">
                @endif
                @error('estimated_cost_usd')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label>Цена доставка США ($)</label>
                @if($donorCar->exists && $donorCar->isDonorExpenseFieldLocked('usa_delivery_price_usd'))
                    <div class="readonly-value">{{ $donorCar->usa_delivery_price_usd }}</div>
                    <div class="help">Заполнено из {{ $donorCar->donorExpenseSourceLabelFor('usa_delivery_price_usd') }}</div>
                @else
                    <input type="number" name="usa_delivery_price_usd" value="{{ old('usa_delivery_price_usd', $donorCar->usa_delivery_price_usd) }}" min="0" step="0.01" inputmode="decimal">
                @endif
            </div>
            <div>
                <label>Цена Доставка Клайпеда-Украина ($)</label>
                @if($donorCar->exists && $donorCar->isDonorExpenseFieldLocked('klaipeda_ukraine_delivery_price_usd'))
                    <div class="readonly-value">{{ $donorCar->klaipeda_ukraine_delivery_price_usd }}</div>
                    <div class="help">Заполнено из {{ $donorCar->donorExpenseSourceLabelFor('klaipeda_ukraine_delivery_price_usd') }}</div>
                @else
                    <input type="number" name="klaipeda_ukraine_delivery_price_usd" value="{{ old('klaipeda_ukraine_delivery_price_usd', $donorCar->klaipeda_ukraine_delivery_price_usd) }}" min="0" step="0.01" inputmode="decimal">
                @endif
            </div>
            <div>
                <label>&#1056;&#1072;&#1089;&#1090;&#1072;&#1084;&#1086;&#1078;&#1082;&#1072; ($)</label>
                @if($donorCar->exists && $donorCar->isDonorExpenseFieldLocked('customs_clearance_price_usd'))
                    <div class="readonly-value">{{ $donorCar->customs_clearance_price_usd }}</div>
                    <div class="help">Заполнено из {{ $donorCar->donorExpenseSourceLabelFor('customs_clearance_price_usd') }}</div>
                @else
                    <input type="number" name="customs_clearance_price_usd" value="{{ old('customs_clearance_price_usd', $donorCar->customs_clearance_price_usd) }}" min="0" step="0.01" inputmode="decimal">
                @endif
            </div>
            <div class="full">
                <label>&#1060;&#1086;&#1090;&#1086;&#1075;&#1088;&#1072;&#1092;&#1080;&#1080;</label>
                <input id="donor-form-photos" class="donor-form-photo-input" type="file" name="photos[]" accept="image/*" multiple data-donor-form-photos data-existing-photo-count="{{ count($donorCar->photos ?? []) }}">
                <label class="donor-form-photo-dropzone" for="donor-form-photos" data-donor-form-photo-dropzone>
                    <span class="donor-form-photo-dropzone__title">&#1055;&#1077;&#1088;&#1077;&#1090;&#1072;&#1097;&#1080;&#1090;&#1077; &#1092;&#1086;&#1090;&#1086; &#1089;&#1102;&#1076;&#1072;</span>
                    <span class="donor-form-photo-dropzone__hint">&#1080;&#1083;&#1080; &#1085;&#1072;&#1078;&#1084;&#1080;&#1090;&#1077;, &#1095;&#1090;&#1086;&#1073;&#1099; &#1074;&#1099;&#1073;&#1088;&#1072;&#1090;&#1100; &#1092;&#1072;&#1081;&#1083;&#1099;. &#1044;&#1086; {{ \App\Models\DonorCar::PHOTO_LIMIT }} &#1092;&#1086;&#1090;&#1086;.</span>
                    <span class="donor-form-photo-dropzone__status" data-donor-form-photo-status>&#1060;&#1072;&#1081;&#1083;&#1099; &#1085;&#1077; &#1074;&#1099;&#1073;&#1088;&#1072;&#1085;&#1099;</span>
                </label>
            </div>
            @if($donorCar->photos)
                <div class="full photo-grid">
                    @foreach($donorCar->photos as $photo)
                        <label class="photo-item">
                            <img src="{{ \App\Support\PublicStorageUrl::url($photo) }}" alt="&#1060;&#1086;&#1090;&#1086; {{ $donorCar->vin }}">
                            <span><input type="checkbox" name="remove_photos[]" value="{{ $photo }}"> &#1059;&#1076;&#1072;&#1083;&#1080;&#1090;&#1100;</span>
                        </label>
                    @endforeach
                </div>
            @endif
            <div class="full"><label>&#1055;&#1088;&#1080;&#1084;&#1077;&#1095;&#1072;&#1085;&#1080;&#1103;</label><textarea name="notes">{{ old('notes', $donorCar->notes) }}</textarea></div>
        </div>
        <div class="actions" style="margin-top:20px;"><button type="submit">&#1057;&#1086;&#1093;&#1088;&#1072;&#1085;&#1080;&#1090;&#1100;</button></div>
    </form>

    <style>
        .donor-form-photo-input {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
            clip-path: inset(50%);
        }
        .donor-form-photo-dropzone {
            display: grid;
            align-content: center;
            gap: 6px;
            min-height: 138px;
            margin: 0;
            padding: 18px;
            border: 1px dashed var(--line);
            border-radius: 12px;
            background: #f8fbfb;
            color: var(--text);
            cursor: pointer;
            text-align: center;
            transition: border-color .16s ease, background-color .16s ease, color .16s ease;
        }
        .donor-form-photo-dropzone:hover,
        .donor-form-photo-dropzone.is-dragover {
            border-color: var(--accent);
            background: var(--accent-soft);
            color: var(--accent);
        }
        .donor-form-photo-dropzone__title {
            font-weight: 800;
            line-height: 1.25;
        }
        .donor-form-photo-dropzone__hint,
        .donor-form-photo-dropzone__status {
            color: var(--muted);
            font-size: 13px;
            font-weight: 400;
            line-height: 1.35;
        }
        .donor-form-photo-dropzone__status {
            font-weight: 700;
        }
        .readonly-value {
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #f8fbfb;
            color: var(--text);
            font-weight: 700;
            line-height: 1.35;
        }
    </style>

    <script>
        (() => {
            const vinInput = document.querySelector('[data-vin-input]');
            const brandInput = document.querySelector('[data-brand-input]');
            const modelInput = document.querySelector('[data-model-input]');
            const yearInput = document.querySelector('[data-year-input]');
            const batteryTypeInput = document.querySelector('[data-battery-type-input]');
            const photoInput = document.querySelector('[data-donor-form-photos]');
            const photoDropzone = document.querySelector('[data-donor-form-photo-dropzone]');
            const photoStatus = document.querySelector('[data-donor-form-photo-status]');
            const removePhotoInputs = document.querySelectorAll('input[name="remove_photos[]"]');
            const photoLimit = @json(\App\Models\DonorCar::PHOTO_LIMIT);
            const batteryTypeLabels = {
                default: @json(\App\Models\DonorCar::BATTERY_TYPES),
                highland: @json(\App\Models\DonorCar::batteryTypeOptionsForModel('Model 3 Highland 01.2024 - ')),
                model3: @json(\App\Models\DonorCar::batteryTypeOptionsForModel('Model 3')),
                modelY: @json(\App\Models\DonorCar::batteryTypeOptionsForModel('Model Y')),
                modelS: @json(\App\Models\DonorCar::batteryTypeOptionsForModel('Model S')),
                modelX: @json(\App\Models\DonorCar::batteryTypeOptionsForModel('Model X')),
                cybertruck: @json(\App\Models\DonorCar::batteryTypeOptionsForModel('Cybertruck')),
            };

            const selectedNewPhotoCount = () => photoInput?.files.length || 0;

            const selectedRemovePhotoCount = () => Array.from(removePhotoInputs)
                .filter((input) => input.checked)
                .length;

            const remainingPhotoCount = () => {
                const existingPhotoCount = Number(photoInput?.dataset.existingPhotoCount || 0);

                return Math.max(0, photoLimit - existingPhotoCount + selectedRemovePhotoCount());
            };

            const setPhotoStatus = () => {
                if (!photoStatus) {
                    return;
                }

                const count = selectedNewPhotoCount();
                photoStatus.textContent = count > 0
                    ? `\u0412\u044b\u0431\u0440\u0430\u043d\u043e \u0444\u0430\u0439\u043b\u043e\u0432: ${count}`
                    : '\u0424\u0430\u0439\u043b\u044b \u043d\u0435 \u0432\u044b\u0431\u0440\u0430\u043d\u044b';
            };

            const setPhotoFiles = (files) => {
                if (!photoInput) {
                    return;
                }

                const imageFiles = Array.from(files || []).filter((file) => file.type.startsWith('image/'));

                if (imageFiles.length === 0) {
                    photoInput.value = '';
                    setPhotoStatus();
                    alert('\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u0444\u043e\u0442\u043e \u0432 \u0444\u043e\u0440\u043c\u0430\u0442\u0435 \u0438\u0437\u043e\u0431\u0440\u0430\u0436\u0435\u043d\u0438\u044f.');
                    return;
                }

                if (imageFiles.length > remainingPhotoCount()) {
                    photoInput.value = '';
                    setPhotoStatus();
                    alert(`\u041c\u043e\u0436\u043d\u043e \u0434\u043e\u0431\u0430\u0432\u0438\u0442\u044c \u043d\u0435 \u0431\u043e\u043b\u044c\u0448\u0435 ${photoLimit} \u0444\u043e\u0442\u043e\u0433\u0440\u0430\u0444\u0438\u0439 \u043a \u043e\u0434\u043d\u043e\u043c\u0443 \u0434\u043e\u043d\u043e\u0440\u0443.`);
                    return;
                }

                const transfer = new DataTransfer();
                imageFiles.forEach((file) => transfer.items.add(file));
                photoInput.files = transfer.files;
                setPhotoStatus();
            };

            photoInput?.addEventListener('change', () => setPhotoFiles(photoInput.files));
            removePhotoInputs.forEach((input) => input.addEventListener('change', () => {
                if (selectedNewPhotoCount() > remainingPhotoCount()) {
                    photoInput.value = '';
                }

                setPhotoStatus();
            }));
            photoDropzone?.addEventListener('dragenter', (event) => {
                event.preventDefault();
                photoDropzone.classList.add('is-dragover');
            });
            photoDropzone?.addEventListener('dragover', (event) => {
                event.preventDefault();
                photoDropzone.classList.add('is-dragover');
            });
            photoDropzone?.addEventListener('dragleave', (event) => {
                if (!photoDropzone.contains(event.relatedTarget)) {
                    photoDropzone.classList.remove('is-dragover');
                }
            });
            photoDropzone?.addEventListener('drop', (event) => {
                event.preventDefault();
                photoDropzone.classList.remove('is-dragover');
                setPhotoFiles(event.dataTransfer?.files || []);
            });

            if (!vinInput || !brandInput || !modelInput || !yearInput) {
                return;
            }

            const yearCodes = {
                D: 2013,
                E: 2014,
                F: 2015,
                G: 2016,
                H: 2017,
                J: 2018,
                K: 2019,
                L: 2020,
                M: 2021,
                N: 2022,
                P: 2023,
                R: 2024,
                S: 2025,
                T: 2026,
            };

            const modelFromVin = (vin, year) => {
                const prefix = vin.slice(0, 4);

                if (prefix === '5YJ3' || prefix === '7SA3') {
                    return year >= 2024 ? 'Model 3 Highland 01.2024 - ' : 'Model 3 06.2017 - 12.2023';
                }

                if (prefix === '5YJY' || prefix === '7SAY') {
                    return 'Model Y 01.2020 - 01.2025';
                }

                if (prefix === '5YJS' || prefix === '7SAS') {
                    return year >= 2021 ? 'Model S 01.2021 - ' : 'Model S 05.2016 - 12.2020';
                }

                if (prefix === '5YJX' || prefix === '7SAX') {
                    return year >= 2021 ? 'Model X 01.2021 - ' : 'Model X 05.2016 - 12.2020';
                }

                return null;
            };

            const selectValue = (select, value) => {
                if (!value) {
                    return;
                }

                if (![...select.options].some((option) => option.value === value)) {
                    select.add(new Option(value, value));
                }

                select.value = value;
            };

            const batteryLabelsForModel = (model) => {
                const normalizedModel = (model || '').toLowerCase();

                if (normalizedModel.includes('model 3 highland')) {
                    return batteryTypeLabels.highland;
                }

                if (normalizedModel.includes('model 3')) {
                    return batteryTypeLabels.model3;
                }

                if (normalizedModel.includes('model y')) {
                    return batteryTypeLabels.modelY;
                }

                if (normalizedModel.includes('model s')) {
                    return batteryTypeLabels.modelS;
                }

                if (normalizedModel.includes('model x')) {
                    return batteryTypeLabels.modelX;
                }

                if (normalizedModel.includes('cybertruck')) {
                    return batteryTypeLabels.cybertruck;
                }

                return batteryTypeLabels.default;
            };

            const updateBatteryTypeLabels = () => {
                if (!batteryTypeInput) {
                    return;
                }

                const selectedValue = batteryTypeInput.value;
                const labels = batteryLabelsForModel(modelInput?.value);
                const placeholder = batteryTypeInput.options[0]?.textContent || 'Выберите батарею';

                batteryTypeInput.replaceChildren(new Option(placeholder, ''));

                Object.entries(labels).forEach(([value, label]) => {
                    batteryTypeInput.add(new Option(label, value));
                });

                batteryTypeInput.value = labels[selectedValue] ? selectedValue : '';
            };

            const applyVinDetails = () => {
                const vin = vinInput.value.trim().toUpperCase();
                vinInput.value = vin;

                if (!/^[A-HJ-NPR-Z0-9]{17}$/.test(vin)) {
                    return;
                }

                const year = yearCodes[vin[9]] || null;
                const model = modelFromVin(vin, year);

                if (!model) {
                    return;
                }

                if (!brandInput.value) {
                    selectValue(brandInput, 'Tesla');
                }

                if (!modelInput.value) {
                    selectValue(modelInput, model);
                    updateBatteryTypeLabels();
                }

                if (!yearInput.value && year) {
                    yearInput.value = year;
                }
            };

            modelInput?.addEventListener('change', updateBatteryTypeLabels);
            vinInput.addEventListener('input', applyVinDetails);
            vinInput.addEventListener('blur', applyVinDetails);
            updateBatteryTypeLabels();
            applyVinDetails();
        })();
    </script>
@endsection
