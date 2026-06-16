@extends('layouts.admin', [
    'heading' => 'Продажи NikolaCars',
    'subheading' => 'История продаж из выгрузки, связанная с каталогом NikolaCars по коду и с донорами по VIN',
])

@section('content')
    @php
        $donorPartPresenter = app(\App\View\Admin\DonorCars\DonorPartDisplayPresenter::class);
    @endphp

    <div class="grid grid-4" style="margin-bottom:18px;">
        <div class="panel">
            <div class="help">Продаж</div>
            <div class="stat">{{ (int) ($summary->sales_count ?? 0) }}</div>
        </div>
        <div class="panel">
            <div class="help">Продано, шт</div>
            <div class="stat">{{ rtrim(rtrim(number_format((float) ($summary->quantity_sum ?? 0), 3, '.', ''), '0'), '.') }}</div>
        </div>
        <div class="panel">
            <div class="help">Сумма</div>
            <div class="stat">{{ number_format((float) ($summary->amount_sum ?? 0), 2, '.', ' ') }} {{ $currency }}</div>
        </div>
        <div class="panel">
            <div class="help">Не связаны с каталогом</div>
            <div class="stat">{{ (int) ($summary->unmatched_count ?? 0) }}</div>
        </div>
    </div>

    <div class="panel">
        <form method="GET" action="{{ route('admin.nikolacars-sales.index') }}" class="form-grid" style="margin-bottom:18px;">
            <div>
                <label>Поиск</label>
                <input name="q" value="{{ $query }}" placeholder="Код, артикул, название, VIN, документ или покупатель" autocomplete="off">
            </div>
            <div>
                <label>Связь с каталогом</label>
                <select name="matched">
                    <option value="" @selected($matched === '')>Все продажи</option>
                    <option value="yes" @selected($matched === 'yes')>Только связанные</option>
                    <option value="no" @selected($matched === 'no')>Только не связанные</option>
                </select>
            </div>
            <div>
                <label>С даты</label>
                <input type="date" name="from" value="{{ $from }}">
            </div>
            <div>
                <label>По дату</label>
                <input type="date" name="to" value="{{ $to }}">
            </div>
            <div class="actions full">
                <button type="submit">Найти</button>
                <a class="btn btn-secondary" href="{{ route('admin.nikolacars-sales.index') }}">Сбросить</a>
                <a class="btn btn-secondary" href="{{ route('admin.zapchasti.index') }}">Запчасти НиколаКарз</a>
            </div>
        </form>

        <table>
            <thead>
                <tr>
                    <th></th>
                    <th>Дата</th>
                    <th>Код</th>
                    <th>Артикул</th>
                    <th>Запчасть</th>
                    <th>Донор</th>
                    <th>Кол-во</th>
                    <th>Цена</th>
                    <th>Сумма</th>
                    <th>Документ</th>
                    <th>Контрагент</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sales as $sale)
                    @php
                        $canCancelManualSale = $sale->source === 'nikolacars'
                            && $sale->document_number === 'manual-sold-before-june-2026'
                            && $sale->source_file === 'manual-zapchasti-cleanup'
                            && str_starts_with((string) $sale->source_row_hash, 'manual-sold-before-june-2026-');
                        $saleProduct = $donorPartPresenter->resolveSaleProduct($sale, $saleProducts, $saleProductsByCatalogItem);
                        $saleDisplayCatalogItem = $saleProduct?->sourcePartCatalogItem ?: $sale->partCatalogItem;
                        $saleDisplayName = $sale->name
                            ?: $saleProduct?->name
                            ?: $saleDisplayCatalogItem?->name_ua
                            ?: $saleDisplayCatalogItem?->name_ru
                            ?: $saleDisplayCatalogItem?->name;
                    @endphp
                    <tr>
                        <td>
                            @if($canCancelManualSale)
                                <form method="POST" action="{{ route('admin.nikolacars-sales.cancel-manual', $sale) }}" class="inline-form" onsubmit="return confirm('Отменить продажу и вернуть запчасть в Запчасти НиколаКарз?')">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-small btn-secondary">Отменить</button>
                                </form>
                            @endif
                        </td>
                        <td>{{ $sale->sold_at ? $sale->sold_at->timezone('Europe/Kiev')->format('Y-m-d') : '-' }}</td>
                        <td>{{ $sale->code ?: '-' }}</td>
                        <td>{{ $donorPartPresenter->originalPartNumber($sale) ?: '-' }}</td>
                        <td>
                            @if(! $saleProduct && $sale->partCatalogItem)
                                <strong>{{ $saleDisplayName ?: '-' }}</strong>
                            @elseif($saleProduct)
                                <a href="{{ route('admin.products.show', $saleProduct) }}">
                                    <strong>{{ $saleDisplayName ?: '-' }}</strong>
                                </a>
                                @unless($saleDisplayCatalogItem)
                                <div class="help">Не найдена строка каталога по коду</div>
                                @endunless
                            @else
                                <strong>{{ $saleDisplayName ?: '-' }}</strong>
                                <div class="help">Не найдена строка каталога по коду</div>
                            @endif
                            @if($sale->category_path)
                                <div class="help">{{ $sale->category_path }}</div>
                            @endif
                        </td>
                        <td>
                            @if($sale->donorCar)
                                <a href="{{ route('admin.donor-cars.show', $sale->donorCar) }}">{{ $sale->donorCar->vin }}</a>
                                <div class="help">{{ collect([$sale->donorCar->model, $sale->donorCar->year, $sale->donorCar->color])->filter()->implode(' · ') }}</div>
                            @else
                                {{ $sale->donor_vin ?: '-' }}
                            @endif
                        </td>
                        <td>{{ rtrim(rtrim(number_format((float) $sale->quantity, 3, '.', ''), '0'), '.') }}</td>
                        <td>{{ $sale->unit_price !== null ? number_format((float) $sale->unit_price, 2, '.', ' ').' '.$sale->currency : '-' }}</td>
                        <td>{{ $sale->total_amount !== null ? number_format($sale->total_amount, 2, '.', ' ').' '.$sale->currency : '-' }}</td>
                        <td>{{ $sale->document_number ?: '-' }}</td>
                        <td>{{ $sale->counterparty ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="empty">Продажи не найдены.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top:16px;">
            {{ $sales->links() }}
        </div>
    </div>
@endsection
