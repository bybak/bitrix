(function () {
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function isLegacyBitrixUpload(src) {
    return !!src && /\/upload\/iblock\//i.test(src);
  }

  function isPlaceholderImage(src) {
    if (!src) return true;
    return /no_photo\.png/i.test(src) || /mf-no-photo\.svg/i.test(src);
  }

  function resolveImageSrc(img) {
    var map = window.MF_CHECKOUT_PRODUCT_IMAGES || {};
    var pid = parseInt(img.getAttribute('data-product-id') || '0', 10);
    if (pid > 0 && map[pid]) {
      return String(map[pid]);
    }
    var cur = img.getAttribute('src') || '';
    if (cur && !isLegacyBitrixUpload(cur) && !isPlaceholderImage(cur)) {
      return cur;
    }
    return '';
  }

  function patchCheckoutImages(root) {
    root = root || document;
    var imgs = root.querySelectorAll('.bx-soa-item-img-el[data-product-id]');
    for (var i = 0; i < imgs.length; i++) {
      var img = imgs[i];
      var src = resolveImageSrc(img);
      if (!src) continue;
      if (img.getAttribute('src') !== src) {
        img.setAttribute('src', src);
      }
      img.classList.add('mf-soa-item-img--product');
    }

    if (typeof window.__mfStabilizeBrokenImages === 'function') {
      window.__mfStabilizeBrokenImages(root);
    }
  }

  onReady(function () {
    var orderRoot = document.querySelector('.mf-order[data-mf="order-make"]');
    if (!orderRoot) return;

    patchCheckoutImages(orderRoot);

    if (window.BX && typeof BX.addCustomEvent === 'function') {
      BX.addCustomEvent(window, 'mf-checkout-order-refreshed', function () {
        setTimeout(function () {
          patchCheckoutImages(orderRoot);
        }, 0);
      });
    }

    if (window.MutationObserver) {
      var basketNode = document.getElementById('bx-soa-basket');
      if (basketNode) {
        var observer = new MutationObserver(function () {
          patchCheckoutImages(orderRoot);
        });
        observer.observe(basketNode, { childList: true, subtree: true });
      }
    }
  });
})();
