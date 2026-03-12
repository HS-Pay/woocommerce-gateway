(function() {
  const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
  if (!registerPaymentMethod) {
    return;
  }

  const settings = window.wc?.wcSettings?.getSetting('hcwc_data', {}) || {};
  const isEcheck = settings.paymentType === 'echeck';

  const Label = () => {
    const el = window.wp.element;
    const children = [];

    children.push(el.createElement('span', {
      style: { verticalAlign: 'middle', marginRight: '8px', fontWeight: 500 }
    }, settings.title || (isEcheck ? 'Pay by Bank Transfer' : 'Pay by Credit or Debit Card')));

    const icons = [];

    if (isEcheck) {
      icons.push(el.createElement('img', {
        key: 'bank',
        src: settings.pluginUrl + '/assets/images/bank.svg',
        alt: 'Bank Transfer',
        className: 'hcwc-card-icon',
        style: { marginLeft: '8px', verticalAlign: 'middle' }
      }));
    } else {
      icons.push(el.createElement('img', {
        key: 'visa',
        src: settings.pluginUrl + '/assets/images/visa.svg',
        alt: 'Visa',
        className: 'hcwc-card-icon',
        style: { marginLeft: '8px', marginRight: '2px', verticalAlign: 'middle' }
      }));
      icons.push(el.createElement('img', {
        key: 'mastercard',
        src: settings.pluginUrl + '/assets/images/mastercard.svg',
        alt: 'Mastercard',
        className: 'hcwc-card-icon',
        style: { marginRight: '2px', verticalAlign: 'middle' }
      }));
      icons.push(el.createElement('img', {
        key: 'amex',
        src: settings.pluginUrl + '/assets/images/amex.svg',
        alt: 'American Express',
        className: 'hcwc-card-icon',
        style: { marginRight: '2px', verticalAlign: 'middle' }
      }));
      icons.push(el.createElement('img', {
        key: 'discover',
        src: settings.pluginUrl + '/assets/images/discover.svg',
        alt: 'Discover',
        className: 'hcwc-card-icon',
        style: { verticalAlign: 'middle' }
      }));
    }

    children.push(el.createElement('span', {
      className: 'hcwc-card-icons',
      style: { marginLeft: '8px', verticalAlign: 'middle' }
    }, ...icons));

    return el.createElement('div', {
      style: { display: 'flex', alignItems: 'center' }
    }, ...children);
  };

  const Content = () => {
    const el = window.wp.element;
    const raw = (settings.description || '').toString();

    const desc = raw.replace(/<[^>]*>/g, '');
    if (!desc) return null;

    const [lead, ...restParts] = desc.trim().split(/\r?\n+/);
    const leadEl = el.createElement('strong', null, lead || '');
    const restText = restParts.join(' ').trim();
    const restEl = restText ? el.createElement('span', { style: { display: 'block', marginTop: '8px' } }, restText) : null;

    return el.createElement('div', null, leadEl, restEl);
  };

  registerPaymentMethod({
    name: 'hcwc',
    label: window.wp.element.createElement(Label),
    content: window.wp.element.createElement(Content),
    edit: window.wp.element.createElement(Content),
    ariaLabel: settings.title || (settings.brandName || 'Pay'),
    canMakePayment: () => true,
    supports: { features: ['products'] },
  });
})();
