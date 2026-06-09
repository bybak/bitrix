(function () {
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function getProductCodeFromUrl(url) {
    try {
      var u = new URL(url, window.location.origin);
      var m = u.pathname.match(/\/products\/([^\/]+)\/?$/);
      return m ? decodeURIComponent(m[1]) : '';
    } catch (e) {
      return '';
    }
  }

  function isLegacyBitrixUpload(src) {
    return !!src && /\/upload\/iblock\//i.test(src);
  }

  function isPlaceholderImage(src) {
    if (!src) return true;
    return /no_photo\.png/i.test(src) || /mf-no-photo\.svg/i.test(src);
  }

  function resolveCheckoutImageSrc(data, link) {
    if (!data) return '';

    if (data.MF_IMAGE_URL && String(data.MF_IMAGE_URL).length) {
      return String(data.MF_IMAGE_URL);
    }
    if (data.PREVIEW_PICTURE_SRC && String(data.PREVIEW_PICTURE_SRC).length && !isLegacyBitrixUpload(data.PREVIEW_PICTURE_SRC)) {
      return String(data.PREVIEW_PICTURE_SRC);
    }
    if (data.DETAIL_PICTURE_SRC && String(data.DETAIL_PICTURE_SRC).length && !isLegacyBitrixUpload(data.DETAIL_PICTURE_SRC)) {
      return String(data.DETAIL_PICTURE_SRC);
    }

    var href = link ? (link.getAttribute('href') || link.href || '') : '';
    var code = getProductCodeFromUrl(href);
    if (!code) return '';

    var hn = (window.location && window.location.hostname ? String(window.location.hostname).toLowerCase() : '');
    var isLocal = hn === 'localhost' || hn === '127.0.0.1' || /\.local$/.test(hn) || /\.test$/.test(hn);
    var isHttps = !!(window.location && window.location.protocol === 'https:');
    var base = isLocal || isHttps ? '/mf-img' : 'http://img-motor-force.ru';
    return base + '/products/' + encodeURIComponent(code) + '/0001.jpg';
  }

  function applyCheckoutImage(node, src) {
    if (!node || !src) return;
    node.setAttribute('style', 'background-image: url("' + src.replace(/"/g, '\\"') + '");');
    node.classList.add('mf-soa-item-img--product');
  }

  function patchCheckoutImages(root) {
    root = root || document;
    var blocks = root.querySelectorAll('.bx-soa-item-block');
    for (var i = 0; i < blocks.length; i++) {
      var block = blocks[i];
      var imgNode = block.querySelector('.bx-soa-item-imgcontainer');
      if (!imgNode) continue;

      var style = imgNode.getAttribute('style') || '';
      var link = block.querySelector('a[href*="/products/"]');
      var currentBg = '';
      var m = style.match(/background-image:\s*url\(["']?([^"')]+)["']?\)/i);
      if (m && m[1]) currentBg = m[1];

      if (currentBg && !isLegacyBitrixUpload(currentBg) && !isPlaceholderImage(currentBg) && currentBg.indexOf('/mf-img/') >= 0) {
        continue;
      }

      var src = resolveCheckoutImageSrc(null, link);
      if (!src && currentBg && !isLegacyBitrixUpload(currentBg) && !isPlaceholderImage(currentBg)) {
        src = currentBg;
      }
      if (src) {
        applyCheckoutImage(imgNode, src);
      }
    }
  }

  function patchCheckoutImagesFromResult(result) {
    if (!result || !result.GRID || !result.GRID.ROWS) return;
    var rows = result.GRID.ROWS;
    var keys = Object.keys(rows);
    for (var i = 0; i < keys.length; i++) {
      var row = rows[keys[i]];
      if (!row || !row.data) continue;
      var src = resolveCheckoutImageSrc(row.data, null);
      if (src) {
        row.data.MF_IMAGE_URL = src;
        row.data.PREVIEW_PICTURE_SRC = src;
        row.data.PREVIEW_PICTURE_SRC_2X = src;
        row.data.PREVIEW_PICTURE_SRC_ORIGINAL = src;
      }
    }
  }

  onReady(function () {
    var orderRoot = document.querySelector('.mf-order[data-mf="order-make"]');
    if (!orderRoot) return;

    patchCheckoutImages(orderRoot);

    if (window.BX && typeof BX.addCustomEvent === 'function') {
      BX.addCustomEvent(window, 'mf-checkout-order-refreshed', function (component, result) {
        if (result && result.order) {
          patchCheckoutImagesFromResult(result.order);
        }
        setTimeout(function () {
          patchCheckoutImages(orderRoot);
        }, 0);
      });
    }

    var observer = window.MutationObserver
      ? new MutationObserver(function () {
          patchCheckoutImages(orderRoot);
        })
      : null;
    if (observer) {
      var basketNode = document.getElementById('bx-soa-basket');
      if (basketNode) {
        observer.observe(basketNode, {childList: true, subtree: true});
      }
    }
  });
})();
