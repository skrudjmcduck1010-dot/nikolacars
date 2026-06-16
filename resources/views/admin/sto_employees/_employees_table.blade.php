<table>
    <thead>
    <tr>
        @if ($showRowNumbers ?? false)
            <th>#</th>
        @endif
        <th><a href="{{ $sortUrl('cash_employee_name') }}">Имя в кассе{{ $sortMark('cash_employee_name') }}</a></th>
        <th><a href="{{ $sortUrl('user') }}">Аккаунт доступа{{ $sortMark('user') }}</a></th>
        <th><a href="{{ $sortUrl('position') }}">Должность{{ $sortMark('position') }}</a></th>
        <th><a href="{{ $sortUrl('rate') }}">Ставка{{ $sortMark('rate') }}</a></th>
        <th><a href="{{ $sortUrl('bonus_calculation') }}"> {{ $sortMark('bonus_calculation') }}</a></th>
        <th><a href="{{ $sortUrl('is_active') }}">Статус{{ $sortMark('is_active') }}</a></th>
        <th><a href="{{ $sortUrl('salary_uah') }}">ЗП грн{{ $sortMark('salary_uah') }}</a></th>
        <th><a href="{{ $sortUrl('salary_usd') }}">ЗП ${{ $sortMark('salary_usd') }}</a></th>
        <th><a href="{{ $sortUrl('transactions_count') }}">Операций{{ $sortMark('transactions_count') }}</a></th>
        <th><a href="{{ $sortUrl('latest_operation_date') }}">Последняя ЗП{{ $sortMark('latest_operation_date') }}</a></th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    @forelse ($tableEmployees as $index => $employee)
        @php($summary = $payroll->get($employee->cash_employee_name))
        @php($bonus = $bonusCalculations->get($employee->id))
        <tr>
            @if ($showRowNumbers ?? false)
                <td>{{ $index + 1 }}</td>
            @endif
            <td><a href="{{ route('admin.sto-employees.show', $employee) }}">{{ $employee->cash_employee_name }}</a></td>
            <td>
                @if ($employee->user)
                    <div>{{ $employee->user->email }}</div>
                    <div class="help">{{ $employee->user->roleLabel() }}</div>
                @else
                    —
                @endif
            </td>
            <td>{{ $employee->position ?: '—' }}</td>
            <td>{{ $employee->rate !== null ? $money($employee->rate) : '—' }}</td>
            <td>
                @if ($bonus)
                    <div>{{ $bonus['label'] }}</div>
                    <div class="help">{{ $money($bonus['bonus_amount_uah']) }} грн за {{ $currentMonthBonusPeriod['label'] }}</div>
                @else
                    —
                @endif
            </td>
            <td>
                <span class="tag {{ $employee->is_active ? '' : 'tag-warning' }}">
                    {{ $employee->is_active ? 'Работает' : 'Не работает' }}
                </span>
            </td>
            <td>{{ $money($summary?->salary_uah ?? 0) }}</td>
            <td>{{ $money($summary?->salary_usd ?? 0) }}</td>
            <td>{{ $summary?->transactions_count ?? 0 }}</td>
            <td>{{ $summary?->latest_operation_date ? \Illuminate\Support\Carbon::parse($summary->latest_operation_date)->format('d.m.Y') : '—' }}</td>
            <td>
                <a class="btn btn-small" href="{{ route('admin.sto-employees.edit', $employee) }}">Править</a>
            </td>
        </tr>
    @empty
        <tr><td colspan="{{ ($showRowNumbers ?? false) ? 12 : 11 }}" class="empty">{{ $emptyMessage }}</td></tr>
    @endforelse
    </tbody>
</table>
