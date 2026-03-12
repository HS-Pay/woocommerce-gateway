(function () {
    const GATEWAY = 'hcwc';

    function findPhoneWrappers() {
        return document.querySelectorAll(
            '.wc-block-components-address-form__phone, .form-row[id*="phone"]'
        );
    }

    function getSelectedGateway() {
        const checked = document.querySelector(
            'input[name="payment_method"]:checked, input[name="radio-control-wc-payment-method-options"]:checked'
        );
        if (checked) return checked.value;
        const active = document.querySelector('.wc-block-components-radio-control__option--checked input[type="radio"]');
        return active ? active.value : '';
    }

    function update() {
        const isOurs = getSelectedGateway() === GATEWAY;

        findPhoneWrappers().forEach(function (wrapper) {
            const label = wrapper.querySelector('label');
            const input = wrapper.querySelector('input');

            if (label) {
                if (isOurs) {
                    if (!label.dataset.hcwcOriginal) {
                        label.dataset.hcwcOriginal = label.textContent;
                    }
                    label.textContent = label.dataset.hcwcOriginal.replace(/\s*\(optional\)/i, '');
                } else if (label.dataset.hcwcOriginal) {
                    label.textContent = label.dataset.hcwcOriginal;
                }
            }

            if (input) {
                if (isOurs) {
                    if (!input.dataset.hcwcOriginalAria) {
                        input.dataset.hcwcOriginalAria = input.getAttribute('aria-label') || '';
                    }
                    input.setAttribute('aria-label', (input.dataset.hcwcOriginalAria || '').replace(/\s*\(optional\)/i, ''));
                } else if (input.dataset.hcwcOriginalAria) {
                    input.setAttribute('aria-label', input.dataset.hcwcOriginalAria);
                }
            }

            if (isOurs) {
                wrapper.classList.add('hcwc-phone-required');
            } else {
                wrapper.classList.remove('hcwc-phone-required');
            }
        });
    }

    function init() {
        const style = document.createElement('style');
        style.textContent =
            '.hcwc-phone-required.wc-block-components-text-input { outline: 2px solid #cc1818; border-radius: 4px; }' +
            '.hcwc-phone-required input { border-color: #cc1818 !important; }';
        document.head.appendChild(style);

        update();

        const observer = new MutationObserver(update);
        const checkout = document.querySelector('.wc-block-checkout, form.checkout');
        if (checkout) {
            observer.observe(checkout, { childList: true, subtree: true, attributes: true });
        }

        document.addEventListener('change', update);
        document.addEventListener('click', function () { setTimeout(update, 50); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
