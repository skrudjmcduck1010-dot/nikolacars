@once
    <style>
        .async-autocomplete {
            position: relative;
        }

        .async-autocomplete-meta {
            min-height: 18px;
            margin-top: 4px;
        }

        .async-autocomplete-results {
            position: absolute;
            z-index: 20;
            left: 0;
            right: 0;
            top: 100%;
            max-height: 320px;
            overflow: auto;
            margin-top: 6px;
            border: 1px solid #d7dde8;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.12);
        }

        .async-autocomplete-result {
            display: flex;
            width: 100%;
            flex-direction: column;
            gap: 3px;
            padding: 10px 12px;
            border: 0;
            border-bottom: 1px solid #eef2f7;
            background: transparent;
            color: inherit;
            text-align: left;
            cursor: pointer;
        }

        .async-autocomplete-result:hover,
        .async-autocomplete-result:focus {
            background: #f6f8fb;
            outline: none;
        }

        .async-autocomplete-result:last-child {
            border-bottom: 0;
        }
    </style>

    <script>
        (() => {
            const debounceMs = 220;

            document.querySelectorAll('[data-async-autocomplete]').forEach((root) => {
                if (root.dataset.asyncAutocompleteReady === '1') return;

                root.dataset.asyncAutocompleteReady = '1';

                const input = root.querySelector('[data-autocomplete-input]');
                const idInput = root.querySelector('[data-autocomplete-id]');
                const results = root.querySelector('[data-autocomplete-results]');
                const meta = root.querySelector('[data-autocomplete-meta]');
                const searchUrl = root.dataset.searchUrl;
                let timer = null;

                const hideResults = () => {
                    results.hidden = true;
                    results.innerHTML = '';
                };

                const applyOption = (option) => {
                    idInput.value = option.id || '';
                    input.value = option.label || '';
                    meta.textContent = option.meta || '';
                    root.dispatchEvent(new CustomEvent('async-autocomplete:selected', {
                        bubbles: true,
                        detail: { option },
                    }));
                    hideResults();
                };

                const renderResults = (options) => {
                    results.innerHTML = '';

                    options.forEach((option) => {
                        const button = document.createElement('button');
                        const label = document.createElement('span');
                        const optionMeta = document.createElement('span');

                        button.type = 'button';
                        button.className = 'async-autocomplete-result';
                        label.textContent = option.label || '';
                        optionMeta.className = 'help';
                        optionMeta.textContent = option.meta || '';

                        button.append(label, optionMeta);
                        button.addEventListener('mousedown', (event) => event.preventDefault());
                        button.addEventListener('click', () => applyOption(option));
                        results.append(button);
                    });

                    results.hidden = options.length === 0;
                };

                input.addEventListener('input', () => {
                    const query = input.value.trim();
                    idInput.value = '';
                    meta.textContent = '';
                    clearTimeout(timer);

                    if (query.length < 2) {
                        hideResults();
                        return;
                    }

                    timer = setTimeout(async () => {
                        const requestedQuery = query;
                        const response = await fetch(`${searchUrl}?q=${encodeURIComponent(requestedQuery)}`, {
                            headers: { Accept: 'application/json' },
                        });

                        if (!response.ok || input.value.trim() !== requestedQuery) {
                            return;
                        }

                        renderResults(await response.json());
                    }, debounceMs);
                });

                input.addEventListener('blur', () => setTimeout(hideResults, 160));
            });
        })();
    </script>
@endonce
