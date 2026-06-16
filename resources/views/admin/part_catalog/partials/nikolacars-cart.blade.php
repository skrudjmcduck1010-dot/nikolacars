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
            <span><strong data-nikolacars-cart-count>0</strong> &#1074; &#1082;&#1086;&#1088;&#1079;&#1080;&#1085;&#1077;</span>
            <span class="nikolacars-cart-bar__items" data-nikolacars-cart-items></span>
        </span>
    </div>
    <div class="nikolacars-cart-bar__actions">
        <span class="nikolacars-cart-bar__total" data-nikolacars-cart-total></span>
        <button type="button" class="btn btn-small btn-secondary" data-nikolacars-cart-clear>&#1054;&#1095;&#1080;&#1089;&#1090;&#1080;&#1090;&#1100;</button>
        <button type="button" class="btn btn-small" data-nikolacars-cart-checkout>&#1054;&#1092;&#1086;&#1088;&#1084;&#1080;&#1090;&#1100; &#1079;&#1072;&#1082;&#1072;&#1079;</button>
    </div>
</div>
<dialog class="nikolacars-cart-dialog" data-nikolacars-cart-dialog>
    <div class="nikolacars-cart-dialog__header">
        <div>
            <h2>&#1047;&#1072;&#1082;&#1072;&#1079; &#1082;&#1083;&#1080;&#1077;&#1085;&#1090;&#1072;</h2>
            <div class="help" data-nikolacars-cart-dialog-count></div>
        </div>
        <button type="button" class="btn btn-secondary nikolacars-cart-dialog__close" data-nikolacars-cart-close aria-label="&#1047;&#1072;&#1082;&#1088;&#1099;&#1090;&#1100;">&times;</button>
    </div>
    <div class="nikolacars-cart-customer">
        <div class="nikolacars-cart-phone-field">
            <input type="text" data-nikolacars-cart-phone placeholder="&#1058;&#1077;&#1083;&#1077;&#1092;&#1086;&#1085;" autocomplete="off">
            <div class="nikolacars-cart-phone-suggestions" data-nikolacars-cart-phone-suggestions hidden></div>
        </div>
        <input type="text" data-nikolacars-cart-first-name placeholder="&#1048;&#1084;&#1103;">
        <input type="text" data-nikolacars-cart-last-name placeholder="&#1060;&#1072;&#1084;&#1080;&#1083;&#1080;&#1103;">
        <select data-nikolacars-cart-delivery-method required aria-label="&#1057;&#1087;&#1086;&#1089;&#1086;&#1073; &#1087;&#1086;&#1083;&#1091;&#1095;&#1077;&#1085;&#1080;&#1103;">
            <option value="">&#1057;&#1087;&#1086;&#1089;&#1086;&#1073; &#1087;&#1086;&#1083;&#1091;&#1095;&#1077;&#1085;&#1080;&#1103;</option>
            <option value="pickup">&#1057;&#1072;&#1084;&#1086;&#1074;&#1099;&#1074;&#1086;&#1079;</option>
            <option value="nova_poshta">&#1053;&#1086;&#1074;&#1072;&#1103; &#1087;&#1086;&#1095;&#1090;&#1072;</option>
            <option value="sto">&#1057;&#1058;&#1054;</option>
        </select>
    </div>
    <div class="nikolacars-cart-list" data-nikolacars-cart-list></div>
    <div class="nikolacars-cart-dialog__footer">
        <strong data-nikolacars-cart-dialog-total></strong>
        <div class="actions">
            <button type="button" class="btn btn-small" data-nikolacars-cart-create>&#1057;&#1086;&#1079;&#1076;&#1072;&#1090;&#1100;</button>
        </div>
    </div>
</dialog>
