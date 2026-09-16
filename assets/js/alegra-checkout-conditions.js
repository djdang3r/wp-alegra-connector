/**
 * Alegra Connector — legacy checkout conditional fields.
 *
 * Reproduces on the shortcode checkout what the Checkout Blocks API provides
 * natively: dynamic id-type/regime options per person type, conditional
 * visibility for dv/company/second-name fields, and a collapsible group C.
 *
 * Vanilla JS — jQuery is only used, when present, to hook WooCommerce's own
 * `updated_checkout` event (WooCommerce triggers it through jQuery).
 */
(function () {
    'use strict';

    var cfg = window.alegraCheckout || {};
    var PREFIX = cfg.prefix || 'billing_alegra_';
    var CONDITIONALLY_REQUIRED = { dv: true, company: true };
    var bound = false;

    function byName(key) {
        return document.querySelector('[name="' + PREFIX + key + '"]');
    }

    function wrapper(el) {
        if (!el) {
            return null;
        }
        return el.closest('.form-row') || el.closest('p') || el.parentNode;
    }

    function toggleField(key, visible) {
        var el = byName(key);
        if (!el) {
            return;
        }

        var box = wrapper(el);
        if (box) {
            box.style.display = visible ? '' : 'none';
        }

        if (visible && CONDITIONALLY_REQUIRED[key]) {
            el.setAttribute('required', 'required');
        } else if (!visible) {
            el.removeAttribute('required');
            el.classList.remove('woocommerce-invalid', 'woocommerce-invalid-required-field');
        }
    }

    function populateSelect(key, options) {
        var select = byName(key);
        if (!select || !options) {
            return;
        }

        var previous = select.value;
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = (cfg.strings && cfg.strings.selectPlaceholder) || 'Seleccione…';

        while (select.firstChild) {
            select.removeChild(select.firstChild);
        }
        select.appendChild(placeholder);

        Object.keys(options).forEach(function (value) {
            var option = document.createElement('option');
            option.value = value;
            option.textContent = options[value];
            select.appendChild(option);
        });

        select.value = Object.prototype.hasOwnProperty.call(options, previous) ? previous : '';
    }

    function applyIdtype() {
        var idtype = byName('idtype');
        toggleField('dv', !!idtype && idtype.value === 'NIT');
    }

    function applyKind() {
        var kind = byName('kindofperson');
        var value = kind ? kind.value : '';
        var isLegal = value === 'LEGAL_ENTITY';

        toggleField('company', isLegal);
        toggleField('secondname', value === 'PERSON_ENTITY');
        toggleField('secondlastname', value === 'PERSON_ENTITY');
        applyIdtype();
    }

    function onKindChange(select) {
        var isLegal = select.value === 'LEGAL_ENTITY';
        populateSelect('idtype', isLegal ? cfg.idTypesLegal : cfg.idTypesPerson);
        populateSelect('regime', isLegal ? cfg.regimesLegal : cfg.regimesPerson);
        applyKind();
    }

    function wrapGroupC() {
        var fields = document.querySelectorAll('.alegra-group-c');
        if (!fields.length) {
            return;
        }

        var first = wrapper(fields[0]);
        if (!first || !first.parentNode) {
            return;
        }
        if (first.closest('details.alegra-group-c-details')) {
            return;
        }

        var details = document.createElement('details');
        details.className = 'alegra-group-c-details';

        var summary = document.createElement('summary');
        summary.textContent = (cfg.strings && cfg.strings.moreData) || 'Más datos (opcional)';
        details.appendChild(summary);

        first.parentNode.insertBefore(details, first);

        for (var i = 0; i < fields.length; i++) {
            var box = wrapper(fields[i]);
            if (box) {
                details.appendChild(box);
            }
        }
    }

    function init() {
        applyKind();
        wrapGroupC();
    }

    function onChange(event) {
        var target = event.target;
        if (!target || !target.name) {
            return;
        }
        if (target.name === PREFIX + 'kindofperson') {
            onKindChange(target);
        } else if (target.name === PREFIX + 'idtype') {
            applyIdtype();
        }
    }

    function bind() {
        if (bound) {
            return;
        }
        bound = true;

        document.addEventListener('change', onChange);

        var jq = window.jQuery;
        if (jq) {
            jq(document.body).on('updated_checkout init_checkout', init);
        }
        document.addEventListener('updated_checkout', init);

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    }

    bind();
})();
