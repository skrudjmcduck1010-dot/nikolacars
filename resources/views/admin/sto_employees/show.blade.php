@extends('layouts.admin', [
    'heading' => $employee->full_name,
    'subheading' => 'Карточка сотрудника СТО',
])

@php
    $money = fn ($value) => number_format((float) $value, 2, ',', ' ');
    $monthlyPayroll = $monthlyPayroll ?? collect();
    $maxUah = max((float) $monthlyPayroll->max('salary_uah'), 0.0);
    $maxUsd = max((float) $monthlyPayroll->max('salary_usd'), 0.0);

    $chartWidth = 1200;
    $chartHeight = 380;
    $chartPaddingLeft = 84;
    $chartPaddingRight = 84;
    $chartPaddingTop = 30;
    $chartPaddingBottom = 64;
    $plotWidth = max($chartWidth - $chartPaddingLeft - $chartPaddingRight, 1);
    $plotHeight = max($chartHeight - $chartPaddingTop - $chartPaddingBottom, 1);
    $monthCount = max($monthlyPayroll->count(), 1);
    $gridSteps = 4;

    $pointX = fn ($index) => $monthCount > 1
        ? $chartPaddingLeft + (($plotWidth / ($monthCount - 1)) * $index)
        : $chartPaddingLeft + ($plotWidth / 2);

    $pointY = fn ($value, $maxValue) => $chartPaddingTop + $plotHeight - (($maxValue > 0 ? $value / $maxValue : 0) * $plotHeight);

    $linePoints = function (string $field, float $maxValue) use ($monthlyPayroll, $pointX, $pointY) {
        return $monthlyPayroll->values()->map(function (array $month, int $index) use ($field, $maxValue, $pointX, $pointY) {
            return round($pointX($index), 2).','.round($pointY((float) $month[$field], $maxValue), 2);
        })->implode(' ');
    };

    $yLabelValue = fn (float $maxValue, int $step) => $gridSteps > 0
        ? round(($maxValue / $gridSteps) * ($gridSteps - $step), 2)
        : 0;
@endphp

@section('content')
    <div class="grid grid-2">
        <div class="panel">
            <h2 style="margin-top:0;">Информация</h2>
            <table>
                <tbody>
                <tr><th>Имя в кассе</th><td>{{ $employee->cash_employee_name }}</td></tr>
                <tr>
                    <th>Аккаунт доступа</th>
                    <td>
                        @if ($employee->user)
                            <div>{{ $employee->user->name }}</div>
                            <div class="help" style="margin-top:6px;">{{ $employee->user->email }}</div>
                        @else
                            —
                        @endif
                    </td>
                </tr>
                <tr>
                    <th>Логин</th>
                    <td>
                        @if ($employee->user)
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;min-height:34px;">
                                <span>{{ $employee->user->email }}</span>
                                <button
                                    type="button"
                                    class="btn btn-small btn-secondary"
                                    data-access-login-edit
                                    aria-label="Изменить логин"
                                    title="Изменить логин"
                                    style="width:34px;height:34px;padding:0;"
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;">
                                        <path d="M12 20h9"></path>
                                        <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                                    </svg>
                                </button>
                            </div>
                        @else
                            —
                        @endif
                    </td>
                </tr>
                <tr>
                    <th>Пароль</th>
                    <td>
                        @if ($employee->user)
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;min-height:34px;">
                                <span data-access-password-mask>••••••••</span>
                                <button
                                    type="button"
                                    class="btn btn-small btn-secondary"
                                    data-access-password-edit
                                    aria-label="Изменить пароль"
                                    title="Изменить пароль"
                                    style="width:34px;height:34px;padding:0;"
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;">
                                        <path d="M12 20h9"></path>
                                        <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                                    </svg>
                                </button>
                            </div>
                        @else
                            —
                        @endif
                    </td>
                </tr>
                <tr><th>Роль доступа</th><td>{{ $employee->user?->roleLabel() ?: '—' }}</td></tr>
                <tr><th>Должность</th><td>{{ $employee->position ?: '—' }}</td></tr>
                <tr><th>Ставка</th><td>{{ $employee->rate !== null ? $money($employee->rate) : '—' }}</td></tr>
                <tr>
                    <th> </th>
                    <td>
                        @if ($bonusCalculation)
                            <div>{{ $bonusCalculation['label'] }}</div>
                            <div class="help" style="margin-top:6px;">{{ $bonusCalculation['description'] }}</div>
                            <div class="help" style="margin-top:6px;">
                                Период: {{ $bonusCalculation['period_label'] }}
                                @if (array_key_exists('usd_rate', $bonusCalculation))
                                    , курс $ {{ $money($bonusCalculation['usd_rate']) }}
                                @endif
                            </div>
                            <div style="margin-top:8px;font-weight:700;">{{ $money($bonusCalculation['bonus_amount_uah']) }} грн</div>
                        @else
                            —
                        @endif
                    </td>
                </tr>
                <tr><th>Дата начала работы</th><td>{{ $employee->start_date?->format('d.m.Y') ?: '—' }}</td></tr>
                <tr><th>Статус</th><td><span class="tag {{ $employee->is_active ? '' : 'tag-warning' }}">{{ $employee->is_active ? 'Работает' : 'Не работает' }}</span></td></tr>
                <tr><th>Создан</th><td>{{ optional($employee->created_at)->format('d.m.Y H:i') ?: '—' }}</td></tr>
                <tr><th>Обновлен</th><td>{{ optional($employee->updated_at)->format('d.m.Y H:i') ?: '—' }}</td></tr>
                </tbody>
            </table>
            <div class="actions" style="margin-top:18px;">
                <a class="btn" href="{{ route('admin.sto-employees.edit', $employee) }}">Редактировать</a>
                <a class="btn btn-secondary" href="{{ route('admin.sto-employees.index') }}">К списку</a>
            </div>
        </div>

        <div class="panel">
            <h2 style="margin-top:0;">ЗП</h2>
            <div class="grid grid-2">
                <div>
                    <div class="help">Всего грн</div>
                    <div class="stat">{{ $money($summary?->salary_uah ?? 0) }}</div>
                </div>
                <div>
                    <div class="help">Всего $</div>
                    <div class="stat">{{ $money($summary?->salary_usd ?? 0) }}</div>
                </div>
                <div>
                    <div class="help">Операций</div>
                    <div class="stat">{{ $summary?->transactions_count ?? 0 }}</div>
                </div>
                <div>
                    <div class="help">Последняя ЗП</div>
                    <div class="stat" style="font-size:22px;">{{ $summary?->latest_operation_date ? \Illuminate\Support\Carbon::parse($summary->latest_operation_date)->format('d.m.Y') : '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    @if ($employee->user)
        <dialog
            class="modal"
            data-access-login-dialog
            data-access-login-has-errors="{{ $errors->has('email') ? '1' : '0' }}"
        >
            <form method="POST" action="{{ route('admin.sto-employees.access-login.update', $employee) }}" class="grid" style="gap:16px;">
                @csrf
                @method('PATCH')

                <div class="modal-header">
                    <h2>Изменить логин</h2>
                    <button type="button" class="btn btn-secondary btn-small" data-access-login-close aria-label="Закрыть" style="width:34px;height:34px;padding:0;">×</button>
                </div>

                <div>
                    <label for="access_email">Логин</label>
                    <input id="access_email" type="email" name="email" value="{{ old('email', $employee->user->email) }}" autocomplete="username" required>
                    @error('email')
                        <div class="error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="actions">
                    <button type="submit">Сохранить логин</button>
                    <button type="button" class="btn btn-secondary" data-access-login-close>Отмена</button>
                </div>
            </form>
        </dialog>

        <dialog
            class="modal"
            data-access-password-dialog
            data-access-password-has-errors="{{ $errors->has('password') || $errors->has('password_confirmation') ? '1' : '0' }}"
        >
            <form method="POST" action="{{ route('admin.sto-employees.access-password.update', $employee) }}" class="grid" style="gap:16px;">
                @csrf
                @method('PATCH')

                <div class="modal-header">
                    <h2>Изменить пароль</h2>
                    <button type="button" class="btn btn-secondary btn-small" data-access-password-close aria-label="Закрыть" style="width:34px;height:34px;padding:0;">×</button>
                </div>

                <div>
                    <label for="access_password">Новый пароль</label>
                    <input id="access_password" type="password" name="password" autocomplete="new-password" required minlength="8">
                    @error('password')
                        <div class="error">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="access_password_confirmation">Повторите пароль</label>
                    <input id="access_password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required minlength="8">
                    @error('password_confirmation')
                        <div class="error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="actions">
                    <button type="submit">Сохранить пароль</button>
                    <button type="button" class="btn btn-secondary" data-access-password-close>Отмена</button>
                </div>
            </form>
        </dialog>
    @endif

    @if ($bonusCalculation)
        <div class="panel" style="margin-top:18px;">
            <h2 style="margin-top:0;">Детализация бонуса</h2>
            <table>
                <tbody>
                @if (array_key_exists('repair_uah', $bonusCalculation))
                    <tr><th>Прибыль за мес, грн</th><td>{{ $money($bonusCalculation['repair_uah']) }}</td></tr>
                @endif
                @if (array_key_exists('parts_uah', $bonusCalculation))
                    <tr><th>Общая прибыль с Запчастей, грн</th><td>{{ $money($bonusCalculation['parts_uah']) }}</td></tr>
                @endif
                @if (array_key_exists('repair_usd', $bonusCalculation))
                    <tr><th>Прибыль за мес, $</th><td>{{ $money($bonusCalculation['repair_usd']) }}</td></tr>
                @endif
                @if (array_key_exists('parts_usd', $bonusCalculation))
                    <tr><th>Общая прибыль с Запчастей, $</th><td>{{ $money($bonusCalculation['parts_usd']) }}</td></tr>
                @endif
                <tr><th>База для 7%</th><td>{{ $money($bonusCalculation['base_amount_uah']) }} грн</td></tr>
                <tr><th>Бонус</th><td>{{ $money($bonusCalculation['bonus_amount_uah']) }} грн</td></tr>
                </tbody>
            </table>
        </div>
    @endif

    <div class="panel" style="margin-top:18px;">
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
            <div>
                <h2 style="margin:0;">График ЗП по месяцам</h2>
                <div class="help" style="margin-top:6px;">Одна шкала по месяцам, две линии: `грн` и `$`. Значения по оси Y показаны слева и справа.</div>
            </div>
            @if ($monthlyPayroll->isNotEmpty())
                <div class="actions" style="gap:8px;">
                    <span class="tag" style="background:#d7ede7;color:#0c6b58;">грн</span>
                    <span class="tag" style="background:#f6ead0;color:#ab6a00;">$</span>
                    <span class="tag">{{ $monthlyPayroll->count() }} мес.</span>
                </div>
            @endif
        </div>

        @if ($monthlyPayroll->isEmpty())
            <div class="empty">Данных для графика пока нет.</div>
        @else
            <div style="margin-top:18px; border:1px solid var(--line); border-radius:16px; overflow:hidden; background:
                linear-gradient(180deg, rgba(215,237,231,.22) 0%, rgba(246,234,208,.18) 48%, rgba(255,253,248,1) 100%);">
                <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" preserveAspectRatio="none" style="display:block; width:100%; height:{{ $chartHeight }}px;">
                    @for ($step = 0; $step <= $gridSteps; $step++)
                        @php($y = $chartPaddingTop + (($plotHeight / $gridSteps) * $step))
                        <line x1="{{ $chartPaddingLeft }}" y1="{{ $y }}" x2="{{ $chartWidth - $chartPaddingRight }}" y2="{{ $y }}" stroke="rgba(106,116,121,.16)" stroke-width="1" />
                        <text x="{{ $chartPaddingLeft - 10 }}" y="{{ $y + 4 }}" text-anchor="end" fill="#0c6b58" font-size="12">
                            {{ $money($yLabelValue($maxUah, $step)) }}
                        </text>
                        <text x="{{ $chartWidth - $chartPaddingRight + 10 }}" y="{{ $y + 4 }}" text-anchor="start" fill="#ab6a00" font-size="12">
                            {{ $money($yLabelValue($maxUsd, $step)) }}
                        </text>
                    @endfor

                    <line x1="{{ $chartPaddingLeft }}" y1="{{ $chartHeight - $chartPaddingBottom }}" x2="{{ $chartWidth - $chartPaddingRight }}" y2="{{ $chartHeight - $chartPaddingBottom }}" stroke="rgba(106,116,121,.24)" stroke-width="1" />

                    <polyline fill="none" stroke="#0c6b58" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" points="{{ $linePoints('salary_uah', $maxUah) }}" />
                    <polyline fill="none" stroke="#ab6a00" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" points="{{ $linePoints('salary_usd', $maxUsd) }}" />

                    @foreach ($monthlyPayroll->values() as $index => $month)
                        <line x1="{{ $pointX($index) }}" y1="{{ $chartPaddingTop }}" x2="{{ $pointX($index) }}" y2="{{ $chartHeight - $chartPaddingBottom }}" stroke="rgba(106,116,121,.08)" stroke-width="1" />

                        <circle cx="{{ $pointX($index) }}" cy="{{ $pointY((float) $month['salary_uah'], $maxUah) }}" r="5.5" fill="#0c6b58" />
                        <circle cx="{{ $pointX($index) }}" cy="{{ $pointY((float) $month['salary_usd'], $maxUsd) }}" r="5.5" fill="#ab6a00" />

                        <text x="{{ $pointX($index) }}" y="{{ $chartHeight - 24 }}" text-anchor="middle" fill="#6a7479" font-size="11">
                            {{ $month['month_label'] }}
                        </text>
                    @endforeach
                </svg>
            </div>
        @endif
    </div>

    <div class="panel" style="margin-top:18px;">
        <h2 style="margin-top:0;">Таблица выплат ЗП и бонусов по месяцам</h2>
        <table>
            <thead>
            <tr>
                <th>Месяц</th>
                <th>ЗП грн</th>
                <th>Бонус грн</th>
                <th>Итог грн</th>
            </tr>
            </thead>
            <tbody>
            @forelse (($monthlyCompensation ?? collect()) as $row)
                <tr>
                    <td>{{ $row['month_label'] }}</td>
                    <td>{{ $money($row['rate_uah']) }}</td>
                    <td>{{ $money($row['bonus_uah']) }}</td>
                    <td>{{ $money($row['total_uah']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Помесячных выплат пока нет.</td></tr>
            @endforelse
            </tbody>
            @if (! empty($monthlyCompensation) && $monthlyCompensation->isNotEmpty())
                <tfoot>
                <tr>
                    <th>Итого</th>
                    <th>{{ $money($monthlyCompensation->sum('rate_uah')) }}</th>
                    <th>{{ $money($monthlyCompensation->sum('bonus_uah')) }}</th>
                    <th>{{ $money($monthlyCompensation->sum('total_uah')) }}</th>
                </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div class="panel" style="margin-top:18px;">
        <h2 style="margin-top:0;">История выплат</h2>
        <table>
            <thead>
            <tr>
                <th>Дата</th>
                <th>Месяц</th>
                <th>грн</th>
                <th>$</th>
                <th>Комментарий</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($transactions as $transaction)
                <tr>
                    <td>{{ $transaction->operation_date?->format('d.m.Y') }}</td>
                    <td>{{ $transaction->source_sheet ?: '—' }}</td>
                    <td>{{ $money((float) $transaction->expense_bank_uah + (float) $transaction->expense_cash_uah) }}</td>
                    <td>{{ $money($transaction->expense_cash_usd) }}</td>
                    <td>{{ $transaction->comment ?: '—' }}</td>
                    <td><a class="btn btn-small btn-secondary" href="{{ route('admin.cashbook.show', $transaction) }}">Операция</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Выплат пока нет.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <script>
        (() => {
            const setupDialog = (openSelector, dialogSelector, closeSelector, errorFlag) => {
                const openButton = document.querySelector(openSelector);
                const dialog = document.querySelector(dialogSelector);
                const closeButtons = document.querySelectorAll(closeSelector);

                const openDialog = () => {
                    if (!dialog) {
                        return;
                    }

                    if (typeof dialog.showModal === 'function') {
                        dialog.showModal();
                    } else {
                        dialog.setAttribute('open', 'open');
                    }
                };

                openButton?.addEventListener('click', openDialog);
                closeButtons.forEach((button) => {
                    button.addEventListener('click', () => dialog?.close());
                });

                if (dialog?.dataset[errorFlag] === '1') {
                    openDialog();
                }
            };

            setupDialog(
                '[data-access-login-edit]',
                '[data-access-login-dialog]',
                '[data-access-login-close]',
                'accessLoginHasErrors'
            );
            setupDialog(
                '[data-access-password-edit]',
                '[data-access-password-dialog]',
                '[data-access-password-close]',
                'accessPasswordHasErrors'
            );
        })();
    </script>
@endsection
