(function($){
  'use strict';

  var config = window.iot_ajax || {};
  var ajaxUrl = config.ajax_url || '/wp-admin/admin-ajax.php';
  var nonce = config.nonce || '';
  var captureDelay = Math.max(500, Math.min(10000, parseInt(config.capture_delay || 1200, 10)));
  var timer = null;
  var activeRequest = null;
  var lastSignature = '';
  var checkoutSubmitting = false;

  function readCookie(name){
    var prefix = name + '=';
    var parts = document.cookie ? document.cookie.split(';') : [];
    for(var i = 0; i < parts.length; i++){
      var part = parts[i].trim();
      if(part.indexOf(prefix) === 0) {
        try { return decodeURIComponent(part.substring(prefix.length)); } catch(e) { return ''; }
      }
    }
    return '';
  }

  function persistSessionKey(key){
    if(!key) return;
    try { window.sessionStorage.setItem('iot_session_key', key); } catch(e) {}
    try { window.localStorage.setItem('iot_session_key', key); } catch(e) {}
    document.cookie = 'iot_session_key=' + encodeURIComponent(key) + '; path=/; max-age=2592000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    ensureSessionField(key);
  }

  function createSessionKey(){
    var random = '';
    if(window.crypto && typeof window.crypto.randomUUID === 'function') {
      random = window.crypto.randomUUID().replace(/-/g, '');
    } else {
      random = Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
    }
    return ('iot_' + random).slice(0, 64);
  }

  function getSessionKey(){
    var key = readCookie('iot_session_key');
    try { key = key || window.sessionStorage.getItem('iot_session_key') || ''; } catch(e) {}
    try { key = key || window.localStorage.getItem('iot_session_key') || ''; } catch(e) {}
    if(!key) key = createSessionKey();
    persistSessionKey(key);
    return key;
  }

  function clearSessionKey(){
    try { window.sessionStorage.removeItem('iot_session_key'); } catch(e) {}
    try { window.localStorage.removeItem('iot_session_key'); } catch(e) {}
    document.cookie = 'iot_session_key=; path=/; max-age=0; SameSite=Lax';
  }

  function ensureSessionField(key){
    $('form.checkout').each(function(){
      var $form = $(this);
      var $field = $form.find('input[name="iot_session_key"]');
      if(!$field.length) $field = $('<input>', {type: 'hidden', name: 'iot_session_key'}).appendTo($form);
      $field.val(key);
    });
  }

  function firstValue(selectors){
    var $field = $(selectors).filter(':input').first();
    var value = $field.length ? $field.val() : '';
    return typeof value === 'string' ? value.trim() : '';
  }

  function uniqueArray(items){
    var seen = {};
    return items.filter(function(item){
      item = item || '';
      if(!item || seen[item]) return false;
      seen[item] = true;
      return true;
    });
  }

  function normalizeText(value){
    return String(value || '').replace(/\s+/g, ' ').trim();
  }

  function cleanProductName(value){
    return normalizeText(value).replace(/\s*(?:x|\u00d7)\s*\d+\s*$/i, '').trim();
  }

  function isPlaceholderProductName(value){
    var blocked = ['product','products','item','items','subtotal','total','shipping','tax','coupon','discount','cart','checkout'];
    var normalized = cleanProductName(value).toLowerCase();
    return !normalized || blocked.indexOf(normalized) !== -1;
  }

  function collectProducts(){
    var names = [];
    var links = [];

    function addName(value){
      var name = cleanProductName(value);
      if(!isPlaceholderProductName(name)) names.push(name);
    }

    function addLink(value){
      value = String(value || '').trim();
      if(!value || value.indexOf('#') === 0 || /^javascript:/i.test(value) || value.indexOf('remove_item=') !== -1) return;
      links.push(value);
    }

    var rows = '.woocommerce-checkout-review-order-table tbody tr, table.shop_table tbody tr.cart_item, .wc-block-components-order-summary-item';
    $(rows).each(function(){
      var $row = $(this);
      var $name = $row.find('.product-name, .wc-block-components-product-name').first();
      if(!$name.length) return;
      var $link = $name.find('a[href]').first();
      if($link.length) addLink($link.prop('href') || $link.attr('href'));
      var $copy = $name.clone();
      $copy.find('.quantity, .product-quantity, .screen-reader-text').remove();
      addName($copy.text());
    });

    $('.woocommerce-cart-form .product-name a, .wc-block-components-product-name, .woocommerce-mini-cart-item__title').each(function(){
      var $item = $(this);
      addName($item.text());
      if($item.is('a')) addLink($item.prop('href') || $item.attr('href'));
    });

    $('.woocommerce-cart-form .product-thumbnail a[href], .wc-block-components-order-summary-item__image a[href]').each(function(){
      addLink($(this).prop('href') || $(this).attr('href'));
    });

    return {names: uniqueArray(names).slice(0, 20), links: uniqueArray(links).slice(0, 20)};
  }

  function collectData(){
    var firstName = firstValue('input[name="billing_first_name"], #billing_first_name, input[id="billing-first_name"], input[autocomplete="given-name"]');
    var lastName = firstValue('input[name="billing_last_name"], #billing_last_name, input[id="billing-last_name"], input[autocomplete="family-name"]');
    var combinedName = firstValue('input[name="billing_name"], #billing_name, input[autocomplete="name"]');
    var products = collectProducts();
    var addressParts = [
      firstValue('input[name="billing_address_1"], #billing_address_1, input[id="billing-address_1"], input[autocomplete="address-line1"]'),
      firstValue('input[name="billing_address_2"], #billing_address_2, input[id="billing-address_2"], input[autocomplete="address-line2"]'),
      firstValue('input[name="billing_city"], #billing_city, input[id="billing-city"], input[autocomplete="address-level2"]'),
      firstValue('input[name="billing_state"], #billing_state, select[name="billing_state"], input[autocomplete="address-level1"]'),
      firstValue('input[name="billing_postcode"], #billing_postcode, input[id="billing-postcode"], input[autocomplete="postal-code"]')
    ].filter(Boolean);

    var data = {
      session_key: getSessionKey(),
      email: firstValue('input[name="billing_email"], #billing_email, input[id="email"], input[autocomplete="email"]'),
      name: normalizeText((firstName + ' ' + lastName).trim() || combinedName),
      phone: firstValue('input[name="billing_phone"], #billing_phone, input[id="billing-phone"], input[autocomplete="tel"]'),
      address: normalizeText(addressParts.join(' ')),
      product_names: products.names,
      product_links: products.links
    };

    if(!data.email && !data.phone && !data.name) return null;
    return data;
  }

  function buildPayload(data){
    return {
      action: 'iot_save',
      nonce: nonce,
      session_key: data.session_key,
      email: data.email,
      name: data.name,
      phone: data.phone,
      address: data.address,
      product_links: JSON.stringify(data.product_links || []),
      product_names: JSON.stringify(data.product_names || [])
    };
  }

  function payloadSignature(payload){
    return [payload.email, payload.name, payload.phone, payload.address, payload.product_links, payload.product_names].join('|');
  }

  function sendData(force){
    var data = collectData();
    if(!data || !nonce) return;
    var payload = buildPayload(data);
    var signature = payloadSignature(payload);
    if(!force && signature === lastSignature) return;
    lastSignature = signature;

    if(activeRequest && typeof activeRequest.abort === 'function') activeRequest.abort();
    activeRequest = $.ajax({url: ajaxUrl, method: 'POST', data: payload, dataType: 'json'})
      .done(function(response){
        if(response && response.success && response.data && response.data.session_key) {
          persistSessionKey(response.data.session_key);
        }
      })
      .fail(function(xhr, status){
        if(status !== 'abort') lastSignature = '';
      })
      .always(function(){ activeRequest = null; });
  }

  function scheduleSend(){
    window.clearTimeout(timer);
    timer = window.setTimeout(function(){ sendData(false); }, captureDelay);
  }

  function sendBeacon(){
    if(checkoutSubmitting || !navigator.sendBeacon || !nonce) return;
    var data = collectData();
    if(!data) return;
    var params = new URLSearchParams(buildPayload(data));
    navigator.sendBeacon(ajaxUrl, params);
  }

  $(function(){
    if(document.body.classList.contains('woocommerce-order-received') || $('.woocommerce-order-received').length) {
      clearSessionKey();
      return;
    }

    var sessionKey = getSessionKey();
    ensureSessionField(sessionKey);
    window.setTimeout(function(){ sendData(true); }, captureDelay);

    $(document).on('input change', 'form.checkout :input, .wc-block-checkout :input, .wp-block-woocommerce-checkout :input', scheduleSend);
    $(document).on('click', '.quantity .plus, .quantity .minus, .remove, .remove-from-cart, .wc-remove-item, .cart_item .remove', scheduleSend);
    $(document).on('submit', 'form.checkout', function(){ checkoutSubmitting = true; });
    $(document).on('click', '.wc-block-components-checkout-place-order-button', function(){ checkoutSubmitting = true; });
    $(document.body).on('updated_checkout updated_wc_div wc_fragments_refreshed', function(){ ensureSessionField(getSessionKey()); scheduleSend(); });
    $(document.body).on('checkout_error', function(){ checkoutSubmitting = false; scheduleSend(); });

    if(window.MutationObserver) {
      var observer = new MutationObserver(function(){ scheduleSend(); });
      var target = document.querySelector('.wc-block-checkout, .wp-block-woocommerce-checkout');
      if(target) observer.observe(target, {childList: true, subtree: true});
    }

    window.addEventListener('pagehide', sendBeacon);
  });

})(jQuery);
