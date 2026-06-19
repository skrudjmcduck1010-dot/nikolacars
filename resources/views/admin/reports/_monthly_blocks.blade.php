<div class="panel" style="margin-top:18px;">
    <h2 style="margin-top:0;">Управленческий результат</h2>
    <table>
        <tbody>
        <tr><td>Прибыль с запчастей</td><td style="text-align:right;">{{ $money($profit['parts_profit']) }} грн</td></tr>
        <tr><td>Прибыль с ремонта</td><td style="text-align:right;">{{ $money($profit['repair_profit']) }} грн</td></tr>
        <tr><td>Прочие доходы (субаренда)</td><td style="text-align:right;">{{ $money($profit['other_income']) }} грн</td></tr>
        <tr><td>Затраты на СТО</td><td style="text-align:right;">{{ $money($profit['sto_expenses']) }} грн</td></tr>
        <tr><td>ЗП сотрудников</td><td style="text-align:right;">{{ $money($profit['payroll']) }} грн</td></tr>
        <tr><th>Итого</th><th style="text-align:right;">{{ $money($profit['net']) }} грн</th></tr>
        </tbody>
    </table>
</div>

<div class="grid grid-2" style="margin-top:18px;">
    <div class="panel">
        <div class="actions" style="justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px;">
            <div>
                <h2 style="margin:0;">Таблица прибыли с Запчастей</h2>
                <div class="help" style="margin-top:6px;">Курс $ для общей прибыли: {{ $money($partsProfitTable['usd_rate']) }}</div>
            </div>
        </div>
        <table>
            <thead>
            <tr>
                <th>Строка</th>
                <th style="text-align:right;">грн</th>
                <th style="text-align:right;">$</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>{{ $partsProfitTable['rows']['sales_retail']['label'] }}</td>
                <td style="text-align:right;">{{ $money($partsProfitTable['rows']['sales_retail']['uah']) }}</td>
                <td style="text-align:right;">{{ $money($partsProfitTable['rows']['sales_retail']['usd']) }}</td>
            </tr>
            <tr>
                <td>{{ $partsProfitTable['rows']['returns_retail']['label'] }}</td>
                <td style="text-align:right;">{{ $money($partsProfitTable['rows']['returns_retail']['uah']) }}</td>
                <td style="text-align:right;">{{ $money($partsProfitTable['rows']['returns_retail']['usd']) }}</td>
            </tr>
            <tr>
                <td>{{ $partsProfitTable['rows']['sales_wholesale']['label'] }}</td>
                <td style="text-align:right;">{{ $money($partsProfitTable['rows']['sales_wholesale']['uah']) }}</td>
                <td style="text-align:right;">{{ $money($partsProfitTable['rows']['sales_wholesale']['usd']) }}</td>
            </tr>
            <tr>
                <td>{{ $partsProfitTable['rows']['purchases_wholesale']['label'] }}</td>
                <td style="text-align:right;">{{ $money($partsProfitTable['rows']['purchases_wholesale']['uah']) }}</td>
                <td style="text-align:right;">{{ $money($partsProfitTable['rows']['purchases_wholesale']['usd']) }}</td>
            </tr>
            @if ($partsProfitTable['rows']['transport']['present'])
                <tr>
                    <td>{{ $partsProfitTable['rows']['transport']['label'] }}</td>
                    <td style="text-align:right;">{{ $money($partsProfitTable['rows']['transport']['uah']) }}</td>
                    <td style="text-align:right;">{{ $money($partsProfitTable['rows']['transport']['usd']) }}</td>
                </tr>
            @endif
            <tr>
                <th>Общая прибыль</th>
                <th colspan="2" style="text-align:center;">{{ $money($partsProfitTable['total']['uah_total']) }}</th>
            </tr>
            </tbody>
        </table>
        <div class="help" style="margin-top:10px;">
            Формула: (Продажа ЗЧР по всем каналам * 0.35 + Продажа ЗЧК - Закупка ЗЧК - Транспортные ЗЧ - Возврат Запчасти и денег) + $ * курс.
        </div>
    </div>

    <div class="panel">
        <h2 style="margin-top:0;">   </h2>
        <table>
            <thead>
            <tr>
                <th>Строка</th>
                <th style="text-align:right;">грн</th>
                <th style="text-align:right;">$</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($repairProfitTable['rows'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td style="text-align:right;">{{ $money($row['uah']) }}</td>
                    <td style="text-align:right;">{{ $money($row['usd']) }}</td>
                </tr>
            @endforeach
            <tr>
                <th>Прибыль за мес, грн</th>
                <th colspan="2" style="text-align:center;">{{ $money($repairProfitTable['total_full_uah']) }}</th>
            </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="grid grid-2" style="margin-top:18px;">
    <div class="panel">
        <h2 style="margin-top:0;">Таблица расходов на СТО</h2>
        <table>
            <thead><tr><th></th><th></th><th></th><th>$</th></tr></thead>
            <tbody>
            @forelse ($labelSummary as $row)
                <tr>
                    <td>{{ $row->label }}</td>
                    <td>{{ $money($row->income_uah) }}</td>
                    <td>{{ $money($row->expense_uah) }}</td>
                    <td>{{ $money($row->net_usd) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Нет данных.</td></tr>
            @endforelse
            </tbody>
            @if ($labelSummary->isNotEmpty())
                <tfoot>
                <tr>
                    <th style="color:#000;font-size:16px;">Итого</th>
                    <th style="color:#000;font-size:16px;">{{ $money($labelSummary->sum('income_uah')) }}</th>
                    <th style="color:#000;font-size:16px;">{{ $money($labelSummary->sum('expense_uah')) }}</th>
                    <th style="color:#000;font-size:16px;">{{ $money($labelSummary->sum('net_usd')) }}</th>
                </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div class="panel">
        <div class="actions" style="justify-content:space-between;margin-bottom:12px;">
            <h2 style="margin:0;">ЗП сотрудников</h2>
            <a class="btn btn-small btn-secondary" href="{{ route('admin.sto-employees.index') }}">Сотрудники</a>
        </div>
        <table>
            <thead>
            <tr>
                <th rowspan="2">Фамилия</th>
                <th colspan="2" style="text-align:center;color:#000;font-weight:800;">ЗП</th>
            </tr>
            <tr>
                <th style="color:#000;font-weight:800;">Ставка</th>
                <th style="color:#000;font-weight:800;">Бонус</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($employeeSummary as $row)
                <tr>
                    <td>
                        @if ($row->sto_employee_id)
                            <a href="{{ route('admin.sto-employees.show', $row->sto_employee_id) }}">{{ $row->employee }}</a>
                        @else
                            {{ $row->employee }}
                        @endif
                    </td>
                    <td>{{ $money($row->rate_uah) }}</td>
                    <td>{{ $money($row->bonus_uah) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">Нет данных.</td></tr>
            @endforelse
            </tbody>
            @if ($employeeSummary->isNotEmpty())
                <tfoot>
                <tr>
                    <th style="color:#000;font-size:16px;">Итого</th>
                    <th colspan="2" style="color:#000;font-size:16px;text-align:center;">
                        {{ $money($employeeSummary->sum('rate_uah') + $employeeSummary->sum('bonus_uah')) }}
                    </th>
                </tr>
                </tfoot>
            @endif
        </table>
        <table style="margin-top:14px;">
            <thead>
            <tr>
                <th>Метка</th>
                <th style="text-align:right;">грн</th>
                <th style="text-align:right;">$</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <th style="color:#000;font-size:16px;">Дивиденды</th>
                <th style="color:#000;font-size:16px;text-align:right;">{{ $money($dividendsSummary?->uah ?? 0) }}</th>
                <th style="color:#000;font-size:16px;text-align:right;">$ {{ $money($dividendsSummary?->usd ?? 0) }}</th>
            </tr>
            </tbody>
        </table>
    </div>
</div>
