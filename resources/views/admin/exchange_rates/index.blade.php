@extends('layouts.admin', ['heading' => 'Курсы валют', 'subheading' => 'Ежедневный курс НБУ для отчетов в у.е.'])

@section('content')
    <div class="grid" style="gap:18px;">
        <div class="panel">
            <div class="exchange-rate-summary">
                <div>
                    <div class="help">Курс на сегодня, {{ $today->format('d.m.Y') }}</div>
                    <div class="exchange-rate-summary__value">
                        @if ($effectiveRate)
                            $ {{ number_format((float) $effectiveRate->rate, 4, '.', ' ') }}
                        @else
                            не загружен
                        @endif
                    </div>
                    @if ($todayRate)
                        <div class="help">
                            Загружен из НБУ
                            {{ $todayRate->fetched_at?->format('d.m.Y H:i') ? '· '.$todayRate->fetched_at?->format('d.m.Y H:i') : '' }}
                        </div>
                    @elseif ($effectiveRate)
                        <div class="help">
                            За сегодня записи нет, используется последний сохраненный курс за {{ $effectiveRate->rate_date?->format('d.m.Y') }}.
                        </div>
                    @else
                        <div class="help">Запустите php artisan exchange-rates:fetch, чтобы загрузить курс.</div>
                    @endif
                    @if ($fetchError)
                        <div class="help exchange-rate-summary__warning">{{ $fetchError }}</div>
                    @endif
                </div>
                <div class="exchange-rate-summary__status">
                    {{ $todayRate ? 'Сегодня загружен' : 'Сегодня не найден' }}
                </div>
            </div>
        </div>

        <div class="panel">
            @if ($exchangeRates->isEmpty())
                <div class="empty">Курсов пока нет. Запустите команду php artisan exchange-rates:fetch.</div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Валюта</th>
                            <th>Курс НБУ</th>
                            <th>Загружен</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($exchangeRates as $exchangeRate)
                            <tr @class(['exchange-rate-today-row' => $exchangeRate->rate_date?->isSameDay($today)])>
                                <td>
                                    {{ $exchangeRate->rate_date?->format('d.m.Y') }}
                                    @if ($exchangeRate->rate_date?->isSameDay($today))
                                        <div class="help">сегодня</div>
                                    @endif
                                </td>
                                <td>{{ $exchangeRate->currency }}</td>
                                <td>{{ number_format((float) $exchangeRate->rate, 4, '.', ' ') }}</td>
                                <td>
                                    {{ $exchangeRate->fetched_at?->format('d.m.Y H:i') ?? '—' }}
                                    <div class="help">обновлено {{ $exchangeRate->updated_at?->format('d.m.Y H:i') }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div style="margin-top:16px;">
                    {{ $exchangeRates->links() }}
                </div>
            @endif
        </div>
    </div>

    <style>
        .exchange-rate-summary {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: center;
        }

        .exchange-rate-summary__value {
            margin-top: 4px;
            font-size: 32px;
            font-weight: 800;
            line-height: 1.1;
        }

        .exchange-rate-summary__status {
            padding: 8px 12px;
            border-radius: 999px;
            background: #eef6ff;
            color: #174ea6;
            font-weight: 700;
            white-space: nowrap;
        }

        .exchange-rate-summary__warning {
            margin-top: 8px;
            color: #b45309;
        }

        .exchange-rate-today-row {
            background: #f8fbff;
        }

        @media (max-width: 760px) {
            .exchange-rate-summary {
                grid-template-columns: 1fr;
            }

            .exchange-rate-summary__status {
                justify-self: start;
            }
        }
    </style>
@endsection
