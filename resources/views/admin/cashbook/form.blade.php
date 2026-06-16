@extends('layouts.admin', [
    'heading' => $transaction->exists ? ' ' : ' ',
    'subheading' => 'Форма повторяет строки кассовой части Google-таблицы.',
])

@section('content')
    <form method="POST" action="{{ $transaction->exists ? route('admin.cashbook.update', $transaction) : route('admin.cashbook.store') }}" class="panel">
        @csrf
        @if ($transaction->exists)
            @method('PUT')
        @endif

        @include('admin.cashbook._form_fields', ['formPrefix' => '', 'transaction' => $transaction])

        <input type="hidden" name="source" value="{{ old('source', $transaction->source ?: 'manual') }}">
        <input type="hidden" name="source_sheet" value="{{ old('source_sheet', $transaction->source_sheet) }}">
        <input type="hidden" name="exchange_rate" value="{{ old('exchange_rate', $transaction->exchange_rate) }}">

        <div class="actions" style="margin-top:18px;">
            <button type="submit">Сохранить</button>
            <a class="btn btn-secondary" href="{{ route('admin.cashbook.index') }}">Назад</a>
        </div>
    </form>
    <script>
        (() => {
            const labelSelect = document.getElementById('label');
            const employeeSelect = document.getElementById('employee');
            const vinSelect = document.getElementById('vehicle_vin');
            const donorExpenseField = document.querySelector('[data-cashbook-donor-expense-field]');
            const donorExpenseSelect = document.querySelector('[data-cashbook-donor-expense-select]');

            if (!labelSelect || !employeeSelect) {
                return;
            }

            const applyDonorExpenseOptions = () => {
                const selectedVinOption = vinSelect?.selectedOptions[0];
                let filledTypes = [];

                if (selectedVinOption?.dataset.donorFilledExpenseTypes) {
                    try {
                        filledTypes = JSON.parse(selectedVinOption.dataset.donorFilledExpenseTypes);
                    } catch {
                        filledTypes = [];
                    }
                }

                if (!donorExpenseSelect) {
                    return;
                }

                Array.from(donorExpenseSelect.options).forEach((option) => {
                    option.hidden = Boolean(option.value) && filledTypes.includes(option.value);
                });

                if (donorExpenseSelect.selectedOptions[0]?.hidden) {
                    donorExpenseSelect.value = '';
                }
            };

            const applyFormState = () => {
                const isRepairMechanic = labelSelect.value === '';
                const isDonor = labelSelect.value === 'Донор';

                Array.from(employeeSelect.options).forEach((option) => {
                    option.hidden = isRepairMechanic && option.value && option.dataset.cashbookMechanic !== '1';
                });

                if (employeeSelect.selectedOptions[0]?.hidden) {
                    employeeSelect.value = '';
                }

                if (vinSelect) {
                    vinSelect.required = isDonor;
                }

                if (donorExpenseField) {
                    donorExpenseField.hidden = !isDonor;
                }

                if (donorExpenseSelect) {
                    donorExpenseSelect.required = isDonor;
                    donorExpenseSelect.disabled = !isDonor;

                    if (!isDonor) {
                        donorExpenseSelect.value = '';
                    }
                }

                applyDonorExpenseOptions();
            };

            vinSelect?.addEventListener('change', () => {
                if (vinSelect.value === '__add_donor__' && vinSelect.dataset.addDonorUrl) {
                    window.location.href = vinSelect.dataset.addDonorUrl;
                }

                applyDonorExpenseOptions();
            });

            labelSelect.addEventListener('change', applyFormState);
            applyFormState();
        })();
    </script>
@endsection
