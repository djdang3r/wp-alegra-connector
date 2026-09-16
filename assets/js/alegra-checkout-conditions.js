/**
 * Alegra Connector — legacy checkout conditional fields.
 *
 * The only conditional field is the NIT verification digit (DV): it is shown
 * and required only when the customer selected the NIT document type. Vanilla
 * JS — jQuery is only used, when present, to hook WooCommerce's own
 * `updated_checkout` event (WooCommerce triggers it through jQuery).
 */
(function () {
    'use strict';

    var cfg = window.alegraCheckout || {};
    var PREFIX = cfg.prefix || 'billing_alegra_';
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

    function applyIdtype() {
        var idtype = byName('idtype');
        var dv = byName('dv');
        if (!dv) {
            return;
        }

        var isNit = !!idtype && idtype.value === 'NIT';
        var box = wrapper(dv);
        if (box) {
            box.style.display = isNit ? '' : 'none';
        }

        if (isNit) {
            dv.setAttribute('required', 'required');
        } else {
            dv.removeAttribute('required');
            dv.classList.remove('woocommerce-invalid', 'woocommerce-invalid-required-field');
        }
    }

    function init() {
        applyIdtype();
    }

    function onChange(event) {
        var target = event.target;
        if (target && target.name === PREFIX + 'idtype') {
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
