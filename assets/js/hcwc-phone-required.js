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

    // Only write when the value actually changes. Writing unconditionally (especially
    // textContent, which rebuilds the text node every time) retriggers the MutationObserver
    // below and creates an infinite update loop that hangs the checkout.
    function setText(el, value) {
        if (el && el.textContent !== value) el.textContent = value;
    }

    function setAria(el, value) {
        if (el && (el.getAttribute('aria-label') || '') !== value) el.setAttribute('aria-label', value);
    }

    // classList.add/remove still emit a class-attribute mutation even when the token is
    // already in the desired state, so guard these too or the observer loops.
    function addClass(el, name) {
        if (el && !el.classList.contains(name)) el.classList.add(name);
    }

    function removeClass(el, name) {
        if (el && el.classList.contains(name)) el.classList.remove(name);
    }

    function update() {
        const isOurs = getSelectedGateway() === GATEWAY;

        findPhoneWrappers().forEach(function (wrapper) {
            const label = wrapper.querySelector('label');
            const input = wrapper.querySelector('input');

            if (label) {
                if (isOurs) {
                    // === undefined (not truthiness) so an empty-string original still latches
                    // and this capture write runs at most once, or it re-arms the observer loop.
                    if (label.dataset.hcwcOriginal === undefined) {
                        label.dataset.hcwcOriginal = label.textContent;
                    }
                    setText(label, label.dataset.hcwcOriginal.replace(/\s*\(optional\)/i, ''));
                } else if (label.dataset.hcwcOriginal) {
                    setText(label, label.dataset.hcwcOriginal);
                }
            }

            if (input) {
                if (isOurs) {
                    if (input.dataset.hcwcOriginalAria === undefined) {
                        input.dataset.hcwcOriginalAria = input.getAttribute('aria-label') || '';
                    }
                    setAria(input, (input.dataset.hcwcOriginalAria || '').replace(/\s*\(optional\)/i, ''));
                } else if (input.dataset.hcwcOriginalAria) {
                    setAria(input, input.dataset.hcwcOriginalAria);
                }
            }

            if (isOurs) {
                addClass(wrapper, 'hcwc-phone-required');
            } else {
                removeClass(wrapper, 'hcwc-phone-required');
            }
        });
    }

    function init() {
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
