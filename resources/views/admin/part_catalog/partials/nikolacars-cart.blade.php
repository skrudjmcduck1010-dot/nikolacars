<div class="nikolacars-cart-bar" data-nikolacars-cart-bar hidden>
    <div class="nikolacars-cart-bar__summary">
        <span class="nikolacars-cart-bar__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="8" cy="21" r="1"></circle>
                <circle cx="19" cy="21" r="1"></circle>
                <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h8.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"></path>
            </svg>
        </span>
        <span class="nikolacars-cart-bar__meta">
            <span><strong data-nikolacars-cart-count>0</strong> в корзине</span>
            <span class="nikolacars-cart-bar__items" data-nikolacars-cart-items></span>
        </span>
    </div>
    <div class="nikolacars-cart-bar__actions">
        <span class="nikolacars-cart-bar__total" data-nikolacars-cart-total></span>
        <button type="button" class="btn btn-small btn-secondary" data-nikolacars-cart-clear>Очистить</button>
        <button type="button" class="btn btn-small" data-nikolacars-cart-checkout>Оформить заказ</button>
    </div>
</div>
<dialog class="nikolacars-cart-dialog" data-nikolacars-cart-dialog>
    <div class="nikolacars-cart-dialog__header">
        <div>
            <h2>Заказ клиента</h2>
            <div class="help" data-nikolacars-cart-dialog-count></div>
        </div>
        <button type="button" class="btn btn-secondary nikolacars-cart-dialog__close" data-nikolacars-cart-close aria-label="Закрыть">&times;</button>
    </div>
    <div class="nikolacars-cart-customer">
        <div class="nikolacars-cart-phone-field">
            <input type="text" data-nikolacars-cart-phone placeholder="Телефон" autocomplete="off">
            <div class="nikolacars-cart-phone-suggestions" data-nikolacars-cart-phone-suggestions hidden></div>
        </div>
        <input type="text" data-nikolacars-cart-first-name placeholder="Имя">
        <input type="text" data-nikolacars-cart-last-name placeholder="Фамилия">
        <select data-nikolacars-cart-delivery-method required aria-label="Способ получения">
            <option value="">Способ получения</option>
            <option value="pickup">Самовывоз</option>
            <option value="nova_poshta">Новая почта</option>
            <option value="sto">СТО</option>
        </select>
        <div class="nikolacars-cart-nova-poshta" data-nikolacars-cart-nova-poshta hidden>
            <div class="nikolacars-cart-autocomplete">
                <input type="text" data-nikolacars-cart-np-city placeholder="&#1043;&#1086;&#1088;&#1086;&#1076; &#1053;&#1086;&#1074;&#1086;&#1081; &#1087;&#1086;&#1095;&#1090;&#1099;" autocomplete="off">
                <input type="hidden" data-nikolacars-cart-np-city-ref>
                <div class="nikolacars-cart-phone-suggestions" data-nikolacars-cart-np-city-suggestions hidden></div>
            </div>
            <div class="nikolacars-cart-autocomplete">
                <input type="text" data-nikolacars-cart-np-warehouse placeholder="&#1054;&#1090;&#1076;&#1077;&#1083;&#1077;&#1085;&#1080;&#1077; &#1080;&#1083;&#1080; &#1087;&#1086;&#1095;&#1090;&#1086;&#1084;&#1072;&#1090;" autocomplete="off">
                <input type="hidden" data-nikolacars-cart-np-warehouse-ref>
                <div class="nikolacars-cart-phone-suggestions" data-nikolacars-cart-np-warehouse-suggestions hidden></div>
            </div>
        </div>
    </div>
    <div class="nikolacars-cart-list" data-nikolacars-cart-list></div>
    <div class="nikolacars-cart-dialog__footer">
        <strong data-nikolacars-cart-dialog-total></strong>
        <div class="actions">
            <button type="button" class="btn btn-small" data-nikolacars-cart-create>Создать</button>
        </div>
    </div>
</dialog>
