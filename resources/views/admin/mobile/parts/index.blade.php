@extends('layouts.mobile', [
    'heading' => 'Добавить фото запчасти',
    'subheading' => 'Выберите донора, потом добавьте деталь и фото с телефона.',
])

@section('content')
    <form method="GET" action="{{ route('admin.mobile.parts.index') }}" class="panel search-row">
        <input name="q" value="{{ $query }}" placeholder="VIN, модель или год" autocomplete="off">
        <button type="submit">Найти</button>
    </form>

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

@endsection
