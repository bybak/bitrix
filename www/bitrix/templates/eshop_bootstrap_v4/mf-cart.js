(function () {
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  // Global image fallback for MF images (both direct host and local proxy).
  // Bound immediately so we don't miss early load errors.
  (function bindMfImgFallback() {
    if (typeof window !== 'undefined' && window.__mfImgFallbackBound) return;
    if (typeof window !== 'undefined') window.__mfImgFallbackBound = true;

    var PLACEHOLDER = '/bitrix/templates/eshop_bootstrap_v4/images/mf-no-photo.svg';
    var HOST_RE = /(^\/mf-img\/)|(^|\/\/)img-motor-force\.ru\//i;

    document.addEventListener(
      'error',
      function (e) {
        var t = e && e.target;
        if (!t || !t.tagName || t.tagName.toLowerCase() !== 'img') return;
        var src = t.getAttribute('src') || '';
        if (!HOST_RE.test(src)) return;
        if (src === PLACEHOLDER) return;
        try {
          t.removeAttribute('srcset');
        } catch (e2) {}
        t.setAttribute('src', PLACEHOLDER);
      },
      true
    );
  })();

  function getProductCodeFromUrl(url) {
    try {
      var u = new URL(url, window.location.origin);
      var m = u.pathname.match(/\/products\/([^\/]+)\/?$/);
      return m ? m[1] : '';
    } catch (e) {
      return '';
    }
  }

  function patchBasketImages(root) {
    root = root || document;
    var imgs = root.querySelectorAll('.basket-item-image');
    for (var i = 0; i < imgs.length; i++) {
      var img = imgs[i];
      var item = img.closest ? img.closest('[data-entity="basket-item"]') : null;
      if (!item) continue;

      var link =
        item.querySelector('a[href*="/products/"]') ||
        item.querySelector('a.basket-item-image-link[href]') ||
        item.querySelector('a.basket-item-info-name-link[href]');
      if (!link) continue;

      var code = getProductCodeFromUrl(link.getAttribute('href') || link.href || '');
      if (!code) continue;

      // Local dev: use same-origin proxy path to avoid HSTS/TLS issues with img-motor-force.ru.
      var hn = (window.location && window.location.hostname ? String(window.location.hostname).toLowerCase() : '');
      var isLocal = hn === 'localhost' || hn === '127.0.0.1' || /\.local$/.test(hn) || /\.test$/.test(hn);
      var base = isLocal
        ? '/mf-img'
        : (window.location && window.location.protocol === 'https:' ? 'https://' : 'http://') + 'img-motor-force.ru';
      var target = base + '/products/' + code + '/0001.jpg';
      var cur = img.getAttribute('src') || '';
      // Bitrix basket UI may re-render and restore original /upload/ URLs.
      // Keep enforcing our canonical image URL.
      if (cur !== target) {
        img.setAttribute('src', target);
      }
      if (img.dataset) {
        img.dataset.mfPatched = '1';
        img.dataset.mfTarget = target;
      }
    }
  }

  function hideBasketInternalProps(root) {
    root = root || document;
    // Hide internal basket properties used for store selection.
    // They must remain in the basket for backend logic, but should not be shown to the user.
    var hide = {
      MF_STORE_ID: true,
      MF_STORE_TITLE: true,
      MF_STORE_CODE: true,
    };

    var nodes = root.querySelectorAll('.basket-item-property-value[data-property-code]');
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      var code = n.getAttribute('data-property-code') || '';
      if (!code) continue;
      if (!hide[code] && code.indexOf('MF_STORE_') !== 0) continue;

      var wrap = n.closest ? n.closest('.basket-item-property') : null;
      if (wrap && wrap.parentNode) {
        wrap.parentNode.removeChild(wrap);
      } else {
        // Fallback: hide just in case we can't locate the wrapper
        n.style.display = 'none';
      }
    }
  }

  function collectBasketItemIds(root) {
    root = root || document;
    var ids = [];
    var seen = {};
    var items = root.querySelectorAll('[data-entity="basket-item"][data-id]');
    for (var i = 0; i < items.length; i++) {
      var id = parseInt(items[i].getAttribute('data-id') || '0', 10);
      if (!id || seen[id]) continue;
      seen[id] = true;
      ids.push(id);
    }
    return ids;
  }

  function mfDeliverySpbIconHtml(ok, title) {
    var mod = ok ? 'ok' : 'bad';
    var t = title || '';
    var glyph = ok ? '\u2713' : '\u00D7';
    return (
      '<span class="mf-store-delivery-spb mf-store-delivery-spb--' +
      mod +
      '" title="' +
      BX.util.htmlspecialchars(t) +
      '" aria-label="' +
      BX.util.htmlspecialchars(t) +
      '"><span class="mf-store-delivery-spb__glyph" aria-hidden="true">' +
      glyph +
      '</span></span>'
    );
  }

  function ensureStoreMetaMount(item) {
    if (!item) return null;
    var info = item.querySelector('.basket-item-block-info');
    if (!info) return null;
    var node = info.querySelector('.mf-cart-store-meta');
    if (node) return node;
    node = document.createElement('div');
    node.className = 'mf-cart-store-meta';
    info.appendChild(node);
    return node;
  }

  function renderStoreMeta(item, data) {
    var mount = ensureStoreMetaMount(item);
    if (!mount || !data) return;

    var html = '';
    if (data.current_store_title) {
      html += '<div class="mf-cart-store-meta__line"><span class="mf-cart-store-meta__label">Склад:</span><span class="mf-cart-store-meta__value">' + BX.util.htmlspecialchars(String(data.current_store_title)) + '</span></div>';
    }
    if (data.delivery_term) {
      html += '<div class="mf-cart-store-meta__line"><span class="mf-cart-store-meta__label">Срок доставки:</span><span class="mf-cart-store-meta__value">' + BX.util.htmlspecialchars(String(data.delivery_term)) + '</span></div>';
    }
    if (typeof data.delivery_spb_ok !== 'undefined') {
      html +=
        '<div class="mf-cart-store-meta__line mf-cart-store-meta__line--delivery-spb"><span class="mf-cart-store-meta__label">Доставка:</span><span class="mf-cart-store-meta__value mf-cart-store-meta__value--delivery-spb">' +
        mfDeliverySpbIconHtml(!!data.delivery_spb_ok, String(data.delivery_spb_title || '')) +
        '</span></div>';
    }
    if (data.can_switch && data.options && data.options.length > 1) {
      html += '<div class="mf-cart-store-meta__switch">';
      html += '<div class="mf-cart-store-meta__switch-title">Выбрать склад</div>';
      html += '<div class="mf-cart-store-meta__switch-controls">';
      html += '<select class="mf-cart-store-meta__select" data-entity="mf-cart-store-select">';
      for (var i = 0; i < data.options.length; i++) {
        var opt = data.options[i] || {};
        var selected = parseInt(opt.store_id || 0, 10) === parseInt(data.current_store_id || 0, 10);
        var label = String(opt.title || ('Склад #' + String(opt.store_id || '')));
        if (opt.price_fmt) label += ' • ' + String(opt.price_fmt);
        if (opt.delivery_term) label += ' • ' + String(opt.delivery_term);
        if (opt.delivery_spb_ok === false) label += ' • СПб \u2717';
        else if (opt.delivery_spb_ok === true) label += ' • СПб \u2713';
        html += '<option value="' + BX.util.htmlspecialchars(String(opt.store_id || '')) + '"' + (selected ? ' selected' : '') + '>' + BX.util.htmlspecialchars(label) + '</option>';
      }
      html += '</select>';
      html += '<button type="button" class="mf-cart-store-meta__apply" data-entity="mf-cart-store-apply">Применить</button>';
      html += '</div>';
      html += '</div>';
    }
    mount.innerHTML = html;
    mount.setAttribute('data-basket-item-id', String(data.basket_item_id || ''));
  }

  function fetchStoreMeta(root) {
    root = root || document;
    var ids = collectBasketItemIds(root);
    if (!ids.length) return;
    var key = ids.join(',');
    if (root.__mfStoreMetaInFlight && root.__mfStoreMetaKey === key) return;
    if (root.__mfStoreMetaLoadedKey === key) {
      var ready = true;
      var existingRows = root.querySelectorAll('[data-entity="basket-item"][data-id]');
      for (var r = 0; r < existingRows.length; r++) {
        if (!existingRows[r].querySelector('.mf-cart-store-meta')) {
          ready = false;
          break;
        }
      }
      if (ready) return;
    }
    root.__mfStoreMetaInFlight = true;
    root.__mfStoreMetaKey = key;

    BX.ajax({
      url: '/ajax/mf_cart_store_meta.php',
      method: 'POST',
      dataType: 'json',
      data: { basketItemIds: ids },
      onsuccess: function (resp) {
        root.__mfStoreMetaInFlight = false;
        if (!resp || !resp.ok || !resp.items) return;
        root.__mfStoreMetaLoadedKey = key;
        var rows = root.querySelectorAll('[data-entity="basket-item"][data-id]');
        for (var i = 0; i < rows.length; i++) {
          var row = rows[i];
          var id = String(row.getAttribute('data-id') || '');
          if (!id || !resp.items[id]) continue;
          renderStoreMeta(row, resp.items[id]);
        }
      },
      onfailure: function () {
        root.__mfStoreMetaInFlight = false;
      }
    });
  }

  function applyStoreChange(btn) {
    var wrap = btn && btn.closest ? btn.closest('.mf-cart-store-meta') : null;
    if (!wrap) return;
    var basketItemId = wrap.getAttribute('data-basket-item-id') || '';
    var select = wrap.querySelector('[data-entity="mf-cart-store-select"]');
    if (!basketItemId || !select) return;
    var storeId = select.value || '';
    if (!storeId) return;

    btn.disabled = true;
    select.disabled = true;
    var oldText = btn.textContent;
    btn.textContent = 'Пересчитываем...';

    BX.ajax({
      url: '/ajax/mf_cart_update_store.php',
      method: 'POST',
      dataType: 'json',
      data: {
        sessid: BX.bitrix_sessid(),
        basket_item_id: basketItemId,
        store_id: storeId
      },
      onsuccess: function (resp) {
        if (!resp || !resp.ok) {
          alert((resp && resp.error) ? resp.error : 'Не удалось сменить склад');
          btn.disabled = false;
          select.disabled = false;
          btn.textContent = oldText;
          return;
        }
        window.location.reload();
      },
      onfailure: function () {
        alert('Не удалось сменить склад');
        btn.disabled = false;
        select.disabled = false;
        btn.textContent = oldText;
      }
    });
  }

  function layoutCart(root) {
    root = root || document;
    var basketRoot = root.querySelector('#basket-root');
    if (!basketRoot) return;

    // Mark columns so CSS can place them reliably (no nth-of-type guesses).
    var itemsWrap = basketRoot.querySelector('#basket-items-list-wrapper');
    var itemsCol = itemsWrap && itemsWrap.closest ? itemsWrap.closest('.col') : null;
    var totalCol = basketRoot.querySelector('[data-entity="basket-total-block"]');
    var warnEl = basketRoot.querySelector('#basket-warning');
    var warnCol = warnEl && warnEl.closest ? warnEl.closest('.col') : null;

    if (itemsCol) itemsCol.classList.add('mf-cart__items');
    if (totalCol) totalCol.classList.add('mf-cart__total');
    if (warnCol) warnCol.classList.add('mf-cart__warn');

    // Hide warning row container when warning block is hidden,
    // so grid "warn" row collapses to 0.
    if (warnCol && warnEl) {
      var visible = true;
      try {
        visible = window.getComputedStyle(warnEl).display !== 'none';
      } catch (e) {}
      warnCol.style.display = visible ? '' : 'none';
    }
  }

  onReady(function () {
    var cartRoot = document.querySelector('.mf-cart');
    if (!cartRoot) return;

    layoutCart(cartRoot);
    patchBasketImages(cartRoot);
    hideBasketInternalProps(cartRoot);
    fetchStoreMeta(cartRoot);

    cartRoot.addEventListener('click', function (e) {
      var storeBtn = e && e.target && e.target.closest ? e.target.closest('[data-entity="mf-cart-store-apply"]') : null;
      if (storeBtn) {
        e.preventDefault();
        applyStoreChange(storeBtn);
        return;
      }

      var partialBtn = e && e.target && e.target.closest ? e.target.closest('[data-entity="basket-checkout-selected-button"]') : null;
      if (!partialBtn) return;
      e.preventDefault();

      var ids = [];
      var boxes = cartRoot.querySelectorAll('[data-entity="mf-cart-partial-checkbox"]');
      for (var b = 0; b < boxes.length; b++) {
        if (!boxes[b].checked) continue;
        var row = boxes[b].closest ? boxes[b].closest('[data-entity="basket-item"]') : null;
        var bid = row ? parseInt(row.getAttribute('data-id') || '0', 10) : 0;
        if (bid) ids.push(bid);
      }
      if (!ids.length) {
        alert('Отметьте хотя бы один товар для оформления.');
        return;
      }

      if (partialBtn.disabled) return;
      partialBtn.disabled = true;
      var oldText = partialBtn.textContent;
      partialBtn.textContent = 'Подготовка...';

      var qs =
        'sessid=' +
        encodeURIComponent(BX.bitrix_sessid() || '') +
        ids
          .map(function (id) {
            return '&selected_ids[]=' + encodeURIComponent(String(id));
          })
          .join('');

      BX.ajax({
        url: '/ajax/mf_cart_partial_checkout.php',
        method: 'POST',
        dataType: 'json',
        data: qs,
        onsuccess: function (resp) {
          if (resp && resp.ok && resp.redirect) {
            window.location.href = resp.redirect;
            return;
          }
          alert((resp && resp.error) ? resp.error : 'Не удалось подготовить заказ');
          partialBtn.disabled = false;
          partialBtn.textContent = oldText;
        },
        onfailure: function () {
          alert('Не удалось подготовить заказ');
          partialBtn.disabled = false;
          partialBtn.textContent = oldText;
        },
      });
    });

    // Basket UI re-renders via JS. Re-apply image patch on mutations.
    var raf = 0;
    var obs = new MutationObserver(function () {
      if (raf) return;
      raf = window.requestAnimationFrame(function () {
        raf = 0;
        layoutCart(cartRoot);
        patchBasketImages(cartRoot);
        hideBasketInternalProps(cartRoot);
        fetchStoreMeta(cartRoot);
      });
    });
    obs.observe(cartRoot, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['class', 'style'],
    });

    // Safety net: Bitrix sometimes updates DOM in ways that don't trigger our observer reliably.
    // Re-apply layout + image patch a few times after load.
    var tries = 0;
    var iv = window.setInterval(function () {
      tries++;
      layoutCart(cartRoot);
      patchBasketImages(cartRoot);
      hideBasketInternalProps(cartRoot);
      fetchStoreMeta(cartRoot);
      if (tries >= 10) {
        window.clearInterval(iv);
      }
    }, 800);
  });
})();

