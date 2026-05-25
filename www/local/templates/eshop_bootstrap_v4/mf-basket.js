(function () {
  if (window.__mfAddStoreBasketBound || window.__mfSearchAddStoreBound) {
    return;
  }
  window.__mfAddStoreBasketBound = true;

  function mfSetHeaderBasketCount(cnt) {
    var n = 0;
    try { n = parseInt(cnt, 10); } catch (e0) { n = 0; }
    if (!isFinite(n) || n < 0) n = 0;

    try {
      var els = document.querySelectorAll('[data-role="mf-cart-count"]');
      for (var i = 0; i < els.length; i++) {
        els[i].textContent = String(n);
      }
    } catch (e1) {}

    if (n > 0) {
      try {
        var links = document.querySelectorAll('a.mf-cart-link');
        for (var j = 0; j < links.length; j++) {
          if (links[j].querySelector('[data-role="mf-cart-count"]')) continue;
          var s = document.createElement('span');
          s.className = 'mf-cart-count';
          s.setAttribute('data-role', 'mf-cart-count');
          s.textContent = String(n);
          links[j].appendChild(s);
        }
      } catch (e2) {}
    }
  }

  function mfApplyInBasketState(productQtyMap) {
    productQtyMap = productQtyMap || {};
    try {
      var btns = document.querySelectorAll('.js-mf-add-store');
      for (var i = 0; i < btns.length; i++) {
        var b = btns[i];
        if (!b) continue;
        var pid = b.getAttribute('data-product-id') || '';
        var btnStore = b.getAttribute('data-store-id') || '';
        var raw = productQtyMap[String(pid)];
        var q = 0;
        var basketStore = 0;
        var basketStoreList = [];
        if (raw !== undefined && raw !== null) {
          if (typeof raw === 'number') {
            try { q = parseInt(raw, 10) || 0; } catch (eN) { q = 0; }
          } else if (typeof raw === 'object') {
            try { q = parseInt(raw.qty || 0, 10) || 0; } catch (eQ) { q = 0; }
            try { basketStore = parseInt(raw.store_id || 0, 10) || 0; } catch (eS) { basketStore = 0; }
            var arr = raw.store_ids;
            if (arr && arr.length) {
              for (var ai = 0; ai < arr.length; ai++) {
                try {
                  var sid = parseInt(arr[ai], 10) || 0;
                  if (sid > 0) basketStoreList.push(String(sid));
                } catch (eA) {}
              }
            }
          }
        }
        if (basketStoreList.length === 0 && basketStore > 0) {
          basketStoreList.push(String(basketStore));
        }
        var inBasketHere = false;
        if (q > 0) {
          if (basketStoreList.length > 0) {
            inBasketHere = (basketStoreList.indexOf(String(btnStore)) >= 0);
          } else {
            inBasketHere = true;
          }
        }
        var defaultLabel = b.getAttribute('data-default-label') || 'В корзину';
        if (inBasketHere) {
          b.setAttribute('data-in-basket', '1');
          b.textContent = 'В корзине';
          if (b.classList.contains('btn-warning')) {
            try { b.classList.remove('btn-warning'); b.classList.add('btn-secondary'); } catch (e1) {}
          }
        } else {
          b.removeAttribute('data-in-basket');
          b.textContent = defaultLabel;
          if (b.classList.contains('btn-secondary')) {
            try { b.classList.remove('btn-secondary'); b.classList.add('btn-warning'); } catch (e2) {}
          }
        }
      }
    } catch (e3) {}
  }

  function mfSyncBasketState() {
    var ids = [];
    try {
      var btns = document.querySelectorAll('.js-mf-add-store');
      var seen = {};
      for (var i = 0; i < btns.length; i++) {
        var pid = btns[i].getAttribute('data-product-id') || '';
        if (!pid || seen[pid]) continue;
        seen[pid] = 1;
        ids.push(pid);
      }
    } catch (e0) { ids = []; }

    if (!ids.length) return;

    var done = function (resp) {
      if (!resp || !resp.ok) return;
      try { mfSetHeaderBasketCount(resp.basket_count); } catch (e1) {}
      try { mfApplyInBasketState(resp.products || {}); } catch (e2) {}
    };

    if (window.BX && BX.ajax) {
      BX.ajax({
        url: '/ajax/mf_basket_state.php',
        method: 'POST',
        dataType: 'json',
        data: { productIds: ids },
        onsuccess: done
      });
      return;
    }

    if (window.fetch) {
      try {
        var fd = new FormData();
        for (var i2 = 0; i2 < ids.length; i2++) fd.append('productIds[]', ids[i2]);
        fetch('/ajax/mf_basket_state.php', { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (r) { return r.json(); })
          .then(done);
      } catch (e3) {}
    }
  }

  window.__mfSyncBasketState = mfSyncBasketState;
  window.mfSetHeaderBasketCount = mfSetHeaderBasketCount;

  function mfRememberDefaultLabels() {
    try {
      document.querySelectorAll('.js-mf-add-store').forEach(function (btn) {
        if (!btn.getAttribute('data-default-label')) {
          btn.setAttribute('data-default-label', (btn.textContent || 'В корзину').trim());
        }
      });
    } catch (e0) {}
  }

  function mfHandleAddStore(btn, e) {
    if (!btn || btn.disabled) return;
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }

    if (btn.getAttribute('data-in-basket') === '1') {
      window.location.href = '/personal/cart/';
      return;
    }

    var pid = btn.getAttribute('data-product-id') || '';
    var sid = btn.getAttribute('data-store-id') || '';
    var qty = btn.getAttribute('data-qty') || '1';
    var qtyWrap = btn.closest ? btn.closest('.mf-search-qty') : null;
    if (!qtyWrap && btn.closest) {
      var row = btn.closest('tr');
      if (row) qtyWrap = row.querySelector('.mf-search-qty');
    }
    var qtyInput = qtyWrap && qtyWrap.querySelector ? qtyWrap.querySelector('.js-mf-qty-input') : null;
    if (qtyInput) {
      var qtyParsed = parseInt(qtyInput.value || '1', 10);
      if (!isFinite(qtyParsed) || qtyParsed < 1) qtyParsed = 1;
      qtyInput.value = String(qtyParsed);
      qty = String(qtyParsed);
    }
    if (!pid || !sid) return;

    btn.disabled = true;
    var oldText = btn.textContent;
    btn.textContent = 'Добавляем…';

    var restoreBtn = function () {
      btn.disabled = false;
      btn.textContent = btn.getAttribute('data-default-label') || oldText || 'В корзину';
      try { btn.removeAttribute('data-in-basket'); } catch (e0) {}
    };

    var onSuccess = function (resp) {
      if (!resp || !resp.ok) {
        restoreBtn();
        return;
      }
      btn.disabled = false;
      btn.setAttribute('data-in-basket', '1');
      btn.textContent = 'В корзине';
      if (btn.classList.contains('btn-warning')) {
        try { btn.classList.remove('btn-warning'); btn.classList.add('btn-secondary'); } catch (e1) {}
      }
      try {
        if (resp.basket_count !== undefined && resp.basket_count !== null) {
          mfSetHeaderBasketCount(resp.basket_count);
        }
      } catch (e2) {}
      try { setTimeout(mfSyncBasketState, 30); } catch (e3) {}
    };

    if (window.BX && BX.ajax) {
      BX.ajax({
        url: '/ajax/mf_add_to_basket_store.php',
        method: 'POST',
        dataType: 'json',
        data: { productId: pid, storeId: sid, qty: qty },
        onsuccess: onSuccess,
        onfailure: restoreBtn
      });
      return;
    }

    if (window.fetch) {
      var fd = new FormData();
      fd.append('productId', pid);
      fd.append('storeId', sid);
      fd.append('qty', qty);
      fetch('/ajax/mf_add_to_basket_store.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json(); })
        .then(onSuccess)
        .catch(restoreBtn);
      return;
    }

    window.location.href = '/ajax/mf_add_to_basket_store.php?productId=' + encodeURIComponent(pid)
      + '&storeId=' + encodeURIComponent(sid) + '&qty=' + encodeURIComponent(qty);
  }

  var lastTouchBtn = null;
  var lastTouchTs = 0;

  document.addEventListener('touchend', function (e) {
    var btn = e && e.target && e.target.closest ? e.target.closest('.js-mf-add-store') : null;
    if (!btn) return;
    lastTouchBtn = btn;
    lastTouchTs = Date.now();
    mfHandleAddStore(btn, e);
  }, { capture: true, passive: false });

  document.addEventListener('click', function (e) {
    var btn = e && e.target && e.target.closest ? e.target.closest('.js-mf-add-store') : null;
    if (!btn) return;
    if (lastTouchBtn === btn && (Date.now() - lastTouchTs) < 700) {
      e.preventDefault();
      return;
    }
    mfHandleAddStore(btn, e);
  }, true);

  function mfInitBasketUi() {
    mfRememberDefaultLabels();
    try {
      if (window.requestIdleCallback) {
        requestIdleCallback(mfSyncBasketState, { timeout: 2500 });
      } else {
        setTimeout(mfSyncBasketState, 400);
      }
    } catch (e0) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mfInitBasketUi);
  } else {
    mfInitBasketUi();
  }
})();
