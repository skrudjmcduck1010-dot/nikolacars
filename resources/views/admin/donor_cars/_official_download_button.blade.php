@php
    $modalId = 'official-download-'.$donorCar->id;
    $iconOnly = $iconOnly ?? false;
    $downloadTitle = 'Выкачать запчасти с официального каталога';
    $needsOfficialRequirements = ! $donorCar->drive_type || ! $donorCar->battery_type || $donorCar->is_performance === null;
    $hasOfficialDownloadedProducts = array_key_exists('has_official_downloaded_products', $donorCar->getAttributes())
        ? (bool) $donorCar->has_official_downloaded_products
        : ($donorCar->relationLoaded('products')
            && $donorCar->products
                ->filter(fn ($product) => $product->is_auto_generated && $product->sourcePartCatalogItem)
                ->pluck('sourcePartCatalogItem.source')
                ->contains('tesla_official'));
@endphp

@if($hasOfficialDownloadedProducts)
@elseif($needsOfficialRequirements)
    <button
        type="button"
        @class(['btn', 'btn-secondary', 'official-download-icon-button' => $iconOnly])
        data-open-official-download-modal="{{ $modalId }}"
        title="{{ $downloadTitle }}"
        aria-label="{{ $downloadTitle }}"
    >
        @if($iconOnly)
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"></path>
            </svg>
        @else
            {{ $downloadTitle }}
        @endif
    </button>

    <dialog id="{{ $modalId }}" class="modal" data-official-download-modal>
        <div class="modal-header">
            <h2>Параметры донора</h2>
            <button type="button" class="btn btn-secondary" data-close-official-download-modal aria-label="Закрыть">&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.donor-cars.products.download-official', $donorCar) }}" class="form-grid" data-official-download-form data-official-download-donor-car-id="{{ $donorCar->id }}">
            @csrf
            @unless($donorCar->drive_type)
                <div>
                    <label>Привод</label>
                    <select name="drive_type" required>
                        <option value="">Выберите привод</option>
                        @foreach(\App\Models\DonorCar::DRIVE_TYPES as $driveType => $label)
                            <option value="{{ $driveType }}" @selected(old('drive_type', $donorCar->drive_type) === $driveType)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('drive_type')<div class="error">{{ $message }}</div>@enderror
                </div>
            @endunless
            @unless($donorCar->battery_type)
                <div>
                    <label>Батарея</label>
                    <select name="battery_type" required>
                        <option value="">Выберите батарею</option>
                        @foreach(\App\Models\DonorCar::batteryTypeOptionsForModel($donorCar->model) as $batteryType => $label)
                            <option value="{{ $batteryType }}" @selected(old('battery_type', $donorCar->battery_type) === $batteryType)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('battery_type')<div class="error">{{ $message }}</div>@enderror
                </div>
            @endunless
            @if($donorCar->is_performance === null)
                <div>
                    <label>Performance</label>
                    <select name="is_performance" required>
                        <option value="">Выберите</option>
                        @foreach(\App\Models\DonorCar::PERFORMANCE_OPTIONS as $value => $label)
                            <option value="{{ $value }}" @selected((string) old('is_performance', '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('is_performance')<div class="error">{{ $message }}</div>@enderror
                </div>
            @endif
            <div class="full help">После сохранения параметров выкачка начнется автоматически.</div>
            <div class="full actions" style="justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" data-close-official-download-modal>Отмена</button>
                <button type="submit" class="btn" data-official-download-submit>Сохранить и выкачать</button>
            </div>
        </form>
    </dialog>
@else
    <form method="POST" action="{{ route('admin.donor-cars.products.download-official', $donorCar) }}" class="inline-form" data-official-download-form data-official-download-donor-car-id="{{ $donorCar->id }}" data-official-download-confirm="Выкачать запчасти с официального каталога Tesla по этому донору?">
        @csrf
        <button
            type="submit"
            @class(['btn', 'btn-secondary', 'official-download-icon-button' => $iconOnly])
            title="{{ $downloadTitle }}"
            aria-label="{{ $downloadTitle }}"
            data-official-download-submit
        >
            @if($iconOnly)
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"></path>
                </svg>
            @else
                {{ $downloadTitle }}
            @endif
        </button>
    </form>
@endif

@once
    <style>
        .official-download-icon-button {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .official-download-icon-button svg {
            width: 18px;
            height: 18px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-open-official-download-modal]').forEach((button) => {
                const modal = document.getElementById(button.dataset.openOfficialDownloadModal);

                if (! modal) {
                    return;
                }

                button.addEventListener('click', () => {
                    if (typeof modal.showModal === 'function') {
                        modal.showModal();
                    } else {
                        modal.setAttribute('open', 'open');
                    }
                });
            });

            document.querySelectorAll('[data-official-download-modal]').forEach((modal) => {
                modal.querySelectorAll('[data-close-official-download-modal]').forEach((button) => {
                    button.addEventListener('click', () => {
                        if (typeof modal.close === 'function') {
                            modal.close();
                        } else {
                            modal.removeAttribute('open');
                        }
                    });
                });
            });

            document.querySelectorAll('[data-official-download-form]').forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    if (form.dataset.officialDownloadBusy === '1') {
                        return;
                    }

                    const confirmMessage = form.dataset.officialDownloadConfirm;

                    if (confirmMessage && ! window.confirm(confirmMessage)) {
                        return;
                    }

                    form.dataset.officialDownloadBusy = '1';
                    form.querySelectorAll('[data-official-download-submit], button[type="submit"]').forEach((button) => {
                        button.disabled = true;
                    });

                    const modal = form.closest('[data-official-download-modal]');

                    if (modal) {
                        if (typeof modal.close === 'function') {
                            modal.close();
                        } else {
                            modal.removeAttribute('open');
                        }
                    }

                    const donorCarId = Number(form.dataset.officialDownloadDonorCarId || '0') || null;

                    window.officialDownloadStatus?.showRunning('Выкачка официального каталога запущена в фоне.', donorCarId);

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            body: new FormData(form),
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        const payload = await response.json().catch(() => ({}));

                        if (! response.ok && response.status !== 409) {
                            const message = payload.message
                                || Object.values(payload.errors || {})[0]
                                || 'Не удалось запустить выкачку официального каталога.';

                            window.officialDownloadStatus?.showDownload({
                                state: 'failed',
                                message: Array.isArray(message) ? message[0] : message,
                            });
                            form.dataset.officialDownloadBusy = '0';
                            form.querySelectorAll('[data-official-download-submit], button[type="submit"]').forEach((button) => {
                                button.disabled = false;
                            });

                            return;
                        }

                        if (payload.download) {
                            window.officialDownloadStatus?.showDownload(payload.download);
                        } else if (payload.message) {
                            window.officialDownloadStatus?.showRunning(payload.message);
                        }

                        window.officialDownloadStatus?.pollNow();
                    } catch (error) {
                        console.error(error);
                        window.officialDownloadStatus?.showDownload({
                            state: 'failed',
                            message: 'Не удалось запустить выкачку официального каталога.',
                        });
                        form.dataset.officialDownloadBusy = '0';
                        form.querySelectorAll('[data-official-download-submit], button[type="submit"]').forEach((button) => {
                            button.disabled = false;
                        });
                    }
                });
            });
        });
    </script>
@endonce
