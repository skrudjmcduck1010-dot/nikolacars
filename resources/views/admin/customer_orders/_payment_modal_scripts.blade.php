(() => {
    const paymentForms = Array.from(document.querySelectorAll('[data-customer-order-payment-form]'));

    if (!paymentForms.length) return;

    const cashUsdType = @json(\App\Models\CustomerOrder::PAYMENT_TYPE_CASH_USD);
    const formatUah = (value) => `${Math.round(value).toLocaleString('ru-RU').replace(/\u00a0/g, ' ')} грн`;
    const formatUsd = (value) => `${value.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).replace(/,/g, ' ')} USD`;
    const rowCurrency = (row) => row.querySelector('[data-payment-type]')?.value === cashUsdType ? 'USD' : 'UAH';
    const parseAmount = (row) => {
        const input = row.querySelector('[data-payment-amount]');
        const rawValue = input?.value.trim().replace(',', '.') ?? '';

        if (rawValue === '') {
            return null;
        }

        const amount = Number.parseFloat(rawValue);

        return Number.isFinite(amount) && amount > 0 ? amount : 0;
    };

    paymentForms.forEach((form) => {
        const rowsNode = form.querySelector('[data-customer-order-payment-rows]');
        const template = form.querySelector('[data-customer-order-payment-row-template]');
        const dueUah = Number.parseFloat(form.dataset.paymentDueUah || '0');
        const dueUsdRaw = Number.parseFloat(form.dataset.paymentDueUsd || '');
        const dueUsd = Number.isFinite(dueUsdRaw) && dueUsdRaw > 0 ? dueUsdRaw : null;
        const usdRate = Number.parseFloat(form.dataset.paymentUsdRate || '0');
        const requiresFullAmount = form.dataset.paymentRequiresFullAmount !== '0';

        if (!rowsNode || !template) return;

        const dialog = form.closest('dialog');
        const dialogTitle = dialog?.querySelector('.modal-header h2');
        const submitButton = form.querySelector('button[type="submit"]');

        if (dialogTitle && form.dataset.paymentDialogTitle) {
            dialogTitle.textContent = form.dataset.paymentDialogTitle;
        }

        if (submitButton && form.dataset.paymentSubmitLabel) {
            submitButton.textContent = form.dataset.paymentSubmitLabel;
        }

        const rows = () => Array.from(rowsNode.querySelectorAll('[data-customer-order-payment-row]'));
        const addButtons = () => Array.from(rowsNode.querySelectorAll('[data-payment-add]'));
        const selectOptions = () => Array.from(rowsNode.querySelector('[data-payment-type]')?.options || []);
        const selectedTypes = (exceptRow = null) => rows()
            .filter((row) => row !== exceptRow)
            .map((row) => row.querySelector('[data-payment-type]')?.value)
            .filter(Boolean);
        const selectedFixedAmount = (row) => {
            const selectedOption = row.querySelector('[data-payment-type]')?.selectedOptions?.[0];
            const fixedAmount = selectedOption?.dataset.fixedAmount || '';

            return fixedAmount !== '' ? fixedAmount : null;
        };
        const hasFixedAmountRow = () => rows().some((row) => selectedFixedAmount(row) !== null);
        const firstAvailableType = (unavailableTypes) => {
            const option = selectOptions().find((candidate) => !unavailableTypes.includes(candidate.value));

            return option?.value || '';
        };

        const syncFixedAmount = (row) => {
            const amountInput = row.querySelector('[data-payment-amount]');
            const fixedAmount = selectedFixedAmount(row);

            if (!amountInput) return;

            if (fixedAmount !== null) {
                amountInput.value = fixedAmount;
                amountInput.readOnly = true;
                amountInput.dataset.paymentAutofill = '1';
                amountInput.dataset.paymentFixedAmount = '1';
                return;
            }

            amountInput.readOnly = false;
            delete amountInput.dataset.paymentFixedAmount;
        };

        const syncPaymentTypes = () => {
            const currentRows = rows();
            const totalTypes = selectOptions().length;
            const seenTypes = [];

            currentRows.forEach((row) => {
                const typeSelect = row.querySelector('[data-payment-type]');
                if (!typeSelect) return;

                if (seenTypes.includes(typeSelect.value)) {
                    const nextType = firstAvailableType(seenTypes);

                    if (nextType !== '') {
                        typeSelect.value = nextType;
                    }
                }

                seenTypes.push(typeSelect.value);
            });

            currentRows.forEach((row) => {
                const typeSelect = row.querySelector('[data-payment-type]');
                if (!typeSelect) return;

                const unavailableTypes = selectedTypes(row);

                Array.from(typeSelect.options).forEach((option) => {
                    option.disabled = option.value !== typeSelect.value && unavailableTypes.includes(option.value);
                });
            });

            addButtons().forEach((button) => {
                button.disabled = currentRows.length >= totalTypes || hasFixedAmountRow();
            });
        };

        const syncNames = () => {
            const currentRows = rows();

            currentRows.forEach((row, index) => {
                const typeSelect = row.querySelector('[data-payment-type]');
                const amountInput = row.querySelector('[data-payment-amount]');
                const removeButton = row.querySelector('[data-payment-remove]');
                const addButton = row.querySelector('[data-payment-add]');

                if (typeSelect) typeSelect.name = `payments[${index}][payment_type]`;
                if (amountInput) amountInput.name = `payments[${index}][received_amount]`;
                if (removeButton) removeButton.style.visibility = currentRows.length > 1 ? '' : 'hidden';
                if (addButton) addButton.style.visibility = index === currentRows.length - 1 ? '' : 'hidden';
            });

            syncPaymentTypes();
        };

        const amountUah = (row, amount) => {
            if (amount === null || amount <= 0) {
                return 0;
            }

            return rowCurrency(row) === 'USD' ? amount * usdRate : amount;
        };
        const amountUsd = (row, amount) => {
            if (amount === null || amount <= 0) {
                return 0;
            }

            return rowCurrency(row) === 'USD' ? amount : (usdRate > 0 ? amount / usdRate : 0);
        };
        const totalPaymentUah = () => rows()
            .reduce((sum, row) => sum + amountUah(row, parseAmount(row)), 0);
        const paymentCoversDue = () => Math.round(totalPaymentUah()) >= Math.round(dueUah);
        const suggestedAmountFor = (row) => {
            if (rowCurrency(row) === 'USD') {
                if (dueUsd !== null) {
                    return dueUsd.toFixed(2);
                }

                return usdRate > 0 ? (dueUah / usdRate).toFixed(2) : '';
            }

            return dueUah.toFixed(2);
        };

        const refreshRemainders = () => {
            const currentRows = rows();
            const firstEmptyRow = currentRows.find((row) => parseAmount(row) === null);
            const lastFilledRow = currentRows
                .filter((row) => parseAmount(row) !== null)
                .at(-1);
            const hintRow = firstEmptyRow || lastFilledRow || currentRows[0];

            currentRows.forEach((row) => {
                const hint = row.querySelector('[data-payment-remainder]');

                if (hint) hint.textContent = '';
            });

            if (!hintRow) return;

            const filledUah = currentRows
                .filter((row) => row !== hintRow)
                .reduce((sum, row) => sum + amountUah(row, parseAmount(row)), 0);
            const filledUsd = currentRows
                .filter((row) => row !== hintRow)
                .reduce((sum, row) => sum + amountUsd(row, parseAmount(row)), 0);
            const remainingUah = Math.max(0, dueUah - filledUah);
            const hint = hintRow.querySelector('[data-payment-remainder]');

            if (!hint) return;

            if (rowCurrency(hintRow) === 'USD') {
                const remainingUsd = dueUsd !== null
                    ? Math.max(0, dueUsd - filledUsd)
                    : (usdRate > 0 ? remainingUah / usdRate : null);
                hint.textContent = remainingUsd !== null
                    ? `Осталось внести ${formatUsd(remainingUsd)}`
                    : 'Не указан курс USD для подсказки остатка.';
                return;
            }

            hint.textContent = `Осталось внести ${formatUah(remainingUah)}`;
        };

        const addRow = () => {
            const currentRows = rows();
            const firstAmount = currentRows[0]?.querySelector('[data-payment-amount]');

            if (hasFixedAmountRow()) {
                return;
            }

            if (currentRows.length === 1 && firstAmount?.dataset.paymentAutofill === '1') {
                firstAmount.value = '';
                firstAmount.dataset.paymentAutofill = '0';
            }

            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-customer-order-payment-row]');

            rowsNode.appendChild(fragment);
            bindRow(row);
            syncNames();
            refreshRemainders();
        };

        const bindRow = (row) => {
            row.querySelector('[data-payment-type]')?.addEventListener('change', () => {
                const amountInput = row.querySelector('[data-payment-amount]');

                if (selectedFixedAmount(row) !== null) {
                    rows()
                        .filter((candidate) => candidate !== row)
                        .forEach((candidate) => candidate.remove());
                }

                syncFixedAmount(row);

                if (amountInput?.dataset.paymentAutofill === '1' && amountInput?.dataset.paymentFixedAmount !== '1') {
                    amountInput.value = suggestedAmountFor(row);
                }

                refreshRemainders();
                syncPaymentTypes();
            });
            row.querySelector('[data-payment-amount]')?.addEventListener('input', (event) => {
                if (event.currentTarget.dataset.paymentFixedAmount === '1') {
                    syncFixedAmount(row);
                    refreshRemainders();
                    return;
                }

                event.currentTarget.dataset.paymentAutofill = '0';
                event.currentTarget.setCustomValidity('');
                refreshRemainders();
            });
            row.querySelector('[data-payment-remove]')?.addEventListener('click', () => {
                row.remove();
                syncNames();
                refreshRemainders();
            });
            row.querySelector('[data-payment-add]')?.addEventListener('click', addRow);
        };

        rows().forEach(bindRow);
        rows().forEach(syncFixedAmount);
        syncNames();
        refreshRemainders();

        form.closest('dialog')?.querySelectorAll('[data-customer-order-payment-close]').forEach((button) => {
            button.addEventListener('click', () => button.closest('dialog')?.close());
        });

        form.addEventListener('submit', (event) => {
            const firstAmountInput = rows()[0]?.querySelector('[data-payment-amount]');

            firstAmountInput?.setCustomValidity('');

            if (!requiresFullAmount || paymentCoversDue()) {
                return;
            }

            event.preventDefault();
            firstAmountInput?.setCustomValidity(`Сумма оплаты должна быть не меньше ${formatUah(dueUah)}.`);
            firstAmountInput?.reportValidity();
        });
    });
})();
