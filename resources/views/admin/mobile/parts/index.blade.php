@extends('layouts.mobile', [
    'heading' => 'Добавить фото запчасти',
    'subheading' => 'Выберите донора, потом добавьте деталь и фото с телефона.',
])

@section('content')
    <style>
        [data-mobile-part-global-search-url] button[type="submit"] { display: none; }
    </style>

    <div class="panel autocomplete" data-mobile-part-global-search-url="{{ route('admin.mobile.parts.search') }}">
        <label for="mobile-part-global-search">Поиск запчастей</label>
        <input name="q" value="{{ $query }}" placeholder="VIN, модель или год" autocomplete="off">
        <button type="submit">Найти</button>
    </div>

        <div class="suggestions" data-mobile-part-global-search-suggestions hidden></div>

    <section class="donor-list">
        @forelse($donorCars as $donorCar)
            <a class="donor-card" href="{{ route('admin.mobile.donor-cars.parts.show', $donorCar) }}">
                @php($donorPreview = collect($donorCar->photos ?? [])->first())
                @php($mobilePartsCount = (int) $donorCar->checked_products_count + (int) $donorCar->sold_parts_count)
                <div class="donor-card__preview">
                    @if($donorPreview)
                        <img src="{{ \App\Support\PublicStorageUrl::url($donorPreview) }}" alt="{{ $donorCar->vin }}">
                    @else
                        <span class="donor-card__preview-empty">Нет фото</span>
                    @endif
                </div>
                <div class="donor-card__top">
                    <div>
                        <div class="donor-card__vin">{{ $donorCar->vin }}</div>
                        <div class="donor-card__meta">
                            {{ collect([$donorCar->brand, $donorCar->display_model, $donorCar->year])->filter()->join(' ') }}
                        </div>
                    </div>
                    <span class="tag">{{ $mobilePartsCount }} шт.</span>
                </div>
                <div class="donor-card__meta">
                    {{ $donorCar->status_label }}
                    @if($donorCar->color)
                        · {{ $donorCar->color }}
                    @endif
                    @if($donorCar->mileage !== null)
                        · {{ number_format($donorCar->mileage, 0, ',', ' ') }} mi
                    @endif
                </div>
            </a>
        @empty
            <div class="panel empty">Донор не найден.</div>
        @endforelse
    </section>

    <script>
        (() => {
            const root = document.querySelector('[data-mobile-part-global-search-url]');
            const input = root?.querySelector('input');
            const button = root?.querySelector('button');
            const suggestions = document.querySelector('[data-mobile-part-global-search-suggestions]');
            let searchTimeout = null;
            let searchController = null;

            if (!root || !input || !suggestions) {
                return;
            }

            root.appendChild(suggestions);
            input.id = 'mobile-part-global-search';
            input.type = 'search';
            input.name = '';
            input.value = '';
            input.placeholder = 'Название или артикул';
            input.dataset.mobilePartGlobalSearchInput = '1';

            if (button) {
                button.hidden = true;
                button.type = 'button';
            }

            const hideSuggestions = () => {
                suggestions.hidden = true;
                suggestions.innerHTML = '';
            };

            const renderSuggestions = (items) => {
                suggestions.innerHTML = '';

                if (!items.length) {
                    const empty = document.createElement('div');
                    empty.className = 'suggestion-empty';
                    empty.textContent = 'Ничего не найдено';
                    suggestions.appendChild(empty);
                    suggestions.hidden = false;

                    return;
                }

                items.forEach((item) => {
                    const link = document.createElement('a');
                    link.className = 'suggestion';
                    link.href = item.url || '#';

                    const title = document.createElement('span');
                    title.className = 'suggestion__title';
                    title.textContent = item.name || item.part_number || '-';

                    const status = document.createElement('span');
                    status.className = 'tag';
                    status.textContent = item.status || '';
                    status.hidden = !item.status;

                    const meta = document.createElement('span');
                    meta.className = 'suggestion__meta';
                    meta.textContent = item.meta || item.donor || '\u00a0';

                    link.append(title, status, meta);
                    suggestions.appendChild(link);
                });

                suggestions.hidden = false;
            };

            input.addEventListener('input', () => {
                const query = input.value.trim();
                window.clearTimeout(searchTimeout);

                if (query.length < 2) {
                    hideSuggestions();
                    return;
                }

                searchTimeout = window.setTimeout(async () => {
                    searchController?.abort();
                    searchController = new AbortController();

                    try {
                        const url = new URL(root.dataset.mobilePartGlobalSearchUrl, window.location.origin);
                        url.searchParams.set('q', query);
                        url.searchParams.set('context', 'mobile');

                        const response = await fetch(url, {
                            headers: { Accept: 'application/json' },
                            signal: searchController.signal,
                        });

                        if (!response.ok) {
                            hideSuggestions();
                            return;
                        }

                        renderSuggestions(await response.json());
                    } catch (error) {
                        if (error.name !== 'AbortError') {
                            hideSuggestions();
                        }
                    }
                }, 220);
            });

            document.addEventListener('click', (event) => {
                if (!root.contains(event.target)) {
                    hideSuggestions();
                }
            });

            input.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    hideSuggestions();
                }
            });
        })();
    </script>
@endsection
