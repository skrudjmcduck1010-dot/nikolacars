(() => {
    const csrfToken = @json(csrf_token());
    const editors = Array.from(document.querySelectorAll('[data-customer-order-ttn-editor]'));
    if (!editors.length) return;
    const addModal = document.querySelector('[data-customer-order-ttn-add-modal]');
    const addForm = addModal?.querySelector('[data-customer-order-ttn-add-form]');
    const addInput = addModal?.querySelector('[data-customer-order-ttn-add-input]');
    const addItemsNode = addModal?.querySelector('[data-customer-order-ttn-add-items]');
    const addError = addModal?.querySelector('[data-customer-order-ttn-add-error]');
    let activeAddEditor = null;

    const setEditing = (editor, editing) => {
        editor.querySelector('[data-customer-order-ttn-display]')?.toggleAttribute('hidden', editing);
        editor.querySelector('[data-customer-order-ttn-edit]')?.toggleAttribute('hidden', editing);
        editor.querySelector('[data-customer-order-ttn-add]')?.toggleAttribute('hidden', editing);
        editor.querySelectorAll('[data-customer-order-ttn-label-link], [data-customer-order-ttn-tracking-link]').forEach((link) => {
            link.toggleAttribute('hidden', editing);
        });
        const form = editor.querySelector('[data-customer-order-ttn-form]');
        form?.toggleAttribute('hidden', !editing);
        if (editing) {
            const input = editor.querySelector('[data-customer-order-ttn-input]');
            input?.focus();
            input?.select();
        }
    };

    const setError = (editor, message = '') => {
        const error = editor.querySelector('[data-customer-order-ttn-error]');
        if (!error) return;
        error.textContent = message;
        error.toggleAttribute('hidden', message === '');
    };

    const setAddError = (message = '') => {
        if (!addError) return;
        addError.textContent = message;
        addError.toggleAttribute('hidden', message === '');
    };

    const closeAddModal = () => {
        activeAddEditor = null;
        setAddError();
        addForm?.reset();
        addItemsNode?.replaceChildren();
        addModal?.close();
    };

    const openAddModal = (editor) => {
        if (!addModal || !addForm || !addInput || !addItemsNode) return;

        activeAddEditor = editor;
        setAddError();
        addForm.reset();
        addItemsNode.replaceChildren();

        let items = [];
        try {
            items = JSON.parse(editor.dataset.addItems || '[]');
        } catch (error) {
            console.error(error);
            items = [];
        }

        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'help';
            empty.textContent = '\u0412 \u0437\u0430\u043A\u0430\u0437\u0435 \u043D\u0435\u0442 \u043F\u043E\u0437\u0438\u0446\u0438\u0439 \u0434\u043B\u044F \u0432\u044B\u0431\u043E\u0440\u0430.';
            addItemsNode.appendChild(empty);
        }

        items.forEach((item) => {
            const label = document.createElement('label');
            label.className = 'customer-order-ttn-add-item';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = 'item_ids[]';
            checkbox.value = String(item.id);

            const text = document.createElement('span');
            text.textContent = item.label || `#${item.id}`;

            label.append(checkbox, text);
            addItemsNode.appendChild(label);
        });

        if (typeof addModal.showModal === 'function') {
            addModal.showModal();
        } else {
            addModal.setAttribute('open', 'open');
        }
        addInput.focus();
    };

    const syncOrderEditors = (orderId, payload) => {
        const buildWarning = () => {
            const warning = document.createElement('span');
            warning.className = 'customer-order-ttn-warning';
            warning.title = 'Наложенный платеж и предоплата меньше суммы заказа';
            warning.setAttribute('aria-label', 'Внимание: наложенный платеж и предоплата меньше суммы заказа');
            warning.setAttribute('role', 'img');
            warning.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"></path><path d="M12 9v4"></path><path d="M12 17h.01"></path></svg>';

            return warning;
        };
        const renderAfterpayment = (node, payload) => {
            node.textContent = 'Наложенный платеж: ';
            const value = document.createElement('strong');
            value.textContent = payload.afterpayment_text;
            node.appendChild(value);

            if (payload.afterpayment_warning) {
                const warning = document.createElement('span');
                warning.className = 'customer-order-ttn-warning';
                warning.title = 'Наложенный платеж и предоплата меньше суммы заказа';
                warning.setAttribute('aria-label', 'Внимание: наложенный платеж и предоплата меньше суммы заказа');
                warning.setAttribute('role', 'img');
                warning.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"></path><path d="M12 9v4"></path><path d="M12 17h.01"></path></svg>';
                node.appendChild(warning);
            }
        };

        const selector = payload.shipment_id
            ? `[data-customer-order-ttn-editor][data-customer-order-shipment-id="${CSS.escape(String(payload.shipment_id))}"]`
            : `[data-customer-order-ttn-editor][data-customer-order-id="${CSS.escape(orderId)}"]`;

        document.querySelectorAll(selector).forEach((editor) => {
            editor.querySelector('[data-customer-order-ttn-value]').textContent = payload.tracking_number;
            const input = editor.querySelector('[data-customer-order-ttn-input]');
            if (input) input.value = payload.tracking_number;
            let afterpaymentNode = editor.querySelector('[data-customer-order-ttn-afterpayment]');
            if (payload.afterpayment_text && Number(payload.afterpayment_amount || 0) > 0) {
                if (!afterpaymentNode) {
                    afterpaymentNode = document.createElement('span');
                    afterpaymentNode.className = 'customer-order-ttn-afterpayment';
                    afterpaymentNode.dataset.customerOrderTtnAfterpayment = '';
                    editor.appendChild(afterpaymentNode);
                }
                renderAfterpayment(afterpaymentNode, payload);
            } else if (afterpaymentNode) {
                afterpaymentNode.remove();
            }
            setError(editor);
            setEditing(editor, false);
        });
        document.querySelectorAll(`[data-customer-order-ttn-editor][data-customer-order-id="${CSS.escape(orderId)}"] [data-customer-order-ttn-afterpayment]`).forEach((node) => {
            node.querySelector('.customer-order-ttn-warning')?.remove();
            if (payload.afterpayment_warning) {
                node.appendChild(buildWarning());
            }
        });
        const linkSelector = payload.shipment_id
            ? `[data-customer-order-shipment-id="${CSS.escape(String(payload.shipment_id))}"]`
            : `[data-customer-order-id="${CSS.escape(orderId)}"]`;
        document.querySelectorAll(`[data-customer-order-ttn-tracking-link]${linkSelector}`).forEach((link) => {
            if (payload.tracking_url) link.href = payload.tracking_url;
        });
        document.querySelectorAll(`[data-customer-order-ttn-label-link]${linkSelector}`).forEach((link) => {
            if (payload.label_url) link.href = payload.label_url;
        });
        document.querySelectorAll(`[data-customer-order-status-label][data-customer-order-id="${CSS.escape(orderId)}"]`).forEach((node) => {
            if (payload.display_status) node.textContent = payload.display_status;
            if (payload.display_status_class !== undefined) {
                node.classList.remove('tag-warning', 'tag-danger', 'tag-paid');
                if (payload.display_status_class) node.classList.add(payload.display_status_class);
            }
        });
    };

    editors.forEach((editor) => {
        const editButton = editor.querySelector('[data-customer-order-ttn-edit]');
        const cancelButton = editor.querySelector('[data-customer-order-ttn-cancel]');
        const addButton = editor.querySelector('[data-customer-order-ttn-add]');
        const form = editor.querySelector('[data-customer-order-ttn-form]');
        const input = editor.querySelector('[data-customer-order-ttn-input]');

        editButton?.addEventListener('click', () => {
            setError(editor);
            setEditing(editor, true);
        });

        cancelButton?.addEventListener('click', () => {
            if (input) input.value = editor.querySelector('[data-customer-order-ttn-value]')?.textContent?.trim() || '';
            setError(editor);
            setEditing(editor, false);
        });

        addButton?.addEventListener('click', () => openAddModal(editor));

        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            setError(editor);

            const submitButton = form.querySelector('button[type="submit"]');
            submitButton?.setAttribute('disabled', 'disabled');

            try {
                const response = await fetch(editor.dataset.updateUrl, {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ tracking_number: input?.value || '' }),
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const message = payload?.errors?.tracking_number?.[0] || payload?.message || 'Не удалось сохранить ТТН.';
                    setError(editor, message);
                    return;
                }

                syncOrderEditors(editor.dataset.customerOrderId, payload);
            } catch (error) {
                console.error(error);
                setError(editor, 'Не удалось сохранить ТТН.');
            } finally {
                submitButton?.removeAttribute('disabled');
            }
        });
    });

    addModal?.querySelector('[data-customer-order-ttn-add-close]')?.addEventListener('click', closeAddModal);
    addModal?.querySelector('[data-customer-order-ttn-add-cancel]')?.addEventListener('click', closeAddModal);
    addModal?.addEventListener('cancel', () => {
        activeAddEditor = null;
    });
    addForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!activeAddEditor || !addInput) return;

        const trackingNumber = addInput.value.trim();
        const itemIds = Array.from(addForm.querySelectorAll('input[name="item_ids[]"]:checked')).map((input) => input.value);
        const addButton = activeAddEditor.querySelector('[data-customer-order-ttn-add]');

        if (trackingNumber === '') {
            setAddError('\u0423\u043A\u0430\u0436\u0438\u0442\u0435 \u043D\u043E\u043C\u0435\u0440 \u0422\u0422\u041D.');
            return;
        }

        if (!itemIds.length) {
            setAddError('\u0412\u044B\u0431\u0435\u0440\u0438\u0442\u0435 \u0437\u0430\u043F\u0447\u0430\u0441\u0442\u0438 \u0434\u043B\u044F \u044D\u0442\u043E\u0439 \u0422\u0422\u041D.');
            return;
        }

        setAddError();
        setError(activeAddEditor);
        addButton?.setAttribute('disabled', 'disabled');

        try {
            const response = await fetch(activeAddEditor.dataset.storeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ tracking_number: trackingNumber, item_ids: itemIds }),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                const message = payload?.errors?.tracking_number?.[0]
                    || payload?.errors?.item_ids?.[0]
                    || payload?.message
                    || '\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u0434\u043E\u0431\u0430\u0432\u0438\u0442\u044C \u0422\u0422\u041D.';
                setAddError(message);
                return;
            }

            window.location.reload();
        } catch (error) {
            console.error(error);
            setAddError('\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u0434\u043E\u0431\u0430\u0432\u0438\u0442\u044C \u0422\u0422\u041D.');
        } finally {
            addButton?.removeAttribute('disabled');
        }
    });
})();
