<div class="panel" style="margin-top:18px;">
    <h2 style="margin-top:0;">&#1059;&#1087;&#1088;&#1072;&#1074;&#1083;&#1077;&#1085;&#1095;&#1077;&#1089;&#1082;&#1080;&#1081; &#1088;&#1077;&#1079;&#1091;&#1083;&#1100;&#1090;&#1072;&#1090;</h2>
    <table>
        <tbody>
        <tr><td>&#1055;&#1088;&#1080;&#1073;&#1099;&#1083;&#1100; &#1089; &#1079;&#1072;&#1087;&#1095;&#1072;&#1089;&#1090;&#1077;&#1081;</td><td style="text-align:right;">{{ $money($profit['parts_profit']) }} &#1075;&#1088;&#1085;</td></tr>
        <tr><td>&#1055;&#1088;&#1080;&#1073;&#1099;&#1083;&#1100; &#1089; &#1088;&#1077;&#1084;&#1086;&#1085;&#1090;&#1072;</td><td style="text-align:right;">{{ $money($profit['repair_profit']) }} &#1075;&#1088;&#1085;</td></tr>
        <tr><td>&#1055;&#1088;&#1086;&#1095;&#1080;&#1077; &#1076;&#1086;&#1093;&#1086;&#1076;&#1099; (&#1089;&#1091;&#1073;&#1072;&#1088;&#1077;&#1085;&#1076;&#1072;)</td><td style="text-align:right;">{{ $money($profit['other_income']) }} &#1075;&#1088;&#1085;</td></tr>
        <tr><td>&#1047;&#1072;&#1090;&#1088;&#1072;&#1090;&#1099; &#1085;&#1072; &#1057;&#1058;&#1054;</td><td style="text-align:right;">{{ $money($profit['sto_expenses']) }} &#1075;&#1088;&#1085;</td></tr>
        <tr><td>&#1047;&#1055; &#1089;&#1086;&#1090;&#1088;&#1091;&#1076;&#1085;&#1080;&#1082;&#1086;&#1074;</td><td style="text-align:right;">{{ $money($profit['payroll']) }} &#1075;&#1088;&#1085;</td></tr>
        <tr><th>&#1048;&#1090;&#1086;&#1075;&#1086;</th><th style="text-align:right;">{{ $money($profit['net']) }} &#1075;&#1088;&#1085;</th></tr>
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
                <th rowspan="2">&#1060;&#1072;&#1084;&#1080;&#1083;&#1080;&#1103;</th>
                <th colspan="2" style="text-align:center;color:#000;font-weight:800;">&#1047;&#1055;</th>
            </tr>
            <tr>
                <th style="color:#000;font-weight:800;">&#1057;&#1090;&#1072;&#1074;&#1082;&#1072;</th>
                <th style="color:#000;font-weight:800;">&#1041;&#1086;&#1085;&#1091;&#1089;</th>
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
                <tr><td colspan="3" class="empty">&#1053;&#1077;&#1090; &#1076;&#1072;&#1085;&#1085;&#1099;&#1093;.</td></tr>
            @endforelse
            </tbody>
            @if ($employeeSummary->isNotEmpty())
                <tfoot>
                <tr>
                    <th style="color:#000;font-size:16px;">&#1048;&#1090;&#1086;&#1075;&#1086;</th>
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
