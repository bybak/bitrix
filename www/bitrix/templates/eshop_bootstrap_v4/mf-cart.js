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

    // Basket UI re-renders via JS. Re-apply image patch on mutations.
    var raf = 0;
    var obs = new MutationObserver(function () {
      if (raf) return;
      raf = window.requestAnimationFrame(function () {
        raf = 0;
        layoutCart(cartRoot);
        patchBasketImages(cartRoot);
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
      if (tries >= 10) {
        window.clearInterval(iv);
      }
    }, 800);
  });
})();

