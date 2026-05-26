(function () {
  try {
    if (typeof window !== 'undefined' && window.__mfImgFallbackBound) return;
    if (typeof window !== 'undefined') window.__mfImgFallbackBound = true;
  } catch (e) {}

  var PLACEHOLDER = '/bitrix/templates/eshop_bootstrap_v4/images/mf-no-photo.svg';
  var HOST_RE = /(^\/mf-img\/)|(^|\/\/)img-motor-force\.ru\//i;

  function isPlaceholderSrc(src) {
    return !src || /mf-no-photo\.svg/i.test(src) || /no_photo\.png/i.test(src);
  }

  function isMfProductImgSrc(src) {
    return !!src && HOST_RE.test(src) && !isPlaceholderSrc(src);
  }

  function markPlaceholderWrap(img) {
    if (!img || !img.closest) return;
    var wrap = img.closest('.mf-search-card__img, .mf-pcard__media-inner, .mf-pcard__media, .mf-pline__media-link, .basket-item-block-image');
    if (!wrap) return;
    if (wrap.classList.contains('mf-search-card__img')) {
      wrap.classList.add('mf-search-card__img--placeholder');
    } else if (wrap.classList.contains('mf-pcard__media-inner')) {
      wrap.classList.add('mf-pcard__media-inner--placeholder');
    } else if (wrap.classList.contains('mf-pline__media-link')) {
      wrap.classList.add('mf-pline__media-link--placeholder');
    } else if (wrap.classList.contains('basket-item-block-image')) {
      wrap.classList.add('basket-item-block-image--placeholder');
    }
  }

  function applyImgPlaceholder(img) {
    if (!img || img.getAttribute('data-mf-img-fallback') === '1') return;
    img.setAttribute('data-mf-img-fallback', '1');
    try {
      img.removeAttribute('srcset');
    } catch (e1) {}
    try {
      img.removeAttribute('loading');
    } catch (e2) {}
    img.classList.add('mf-img--placeholder');
    markPlaceholderWrap(img);
    try {
      img.setAttribute('alt', '');
      img.setAttribute('aria-hidden', 'true');
    } catch (e3) {}

    var cartWrap = img.closest ? img.closest('.basket-item-block-image') : null;
    if (cartWrap) {
      cartWrap.classList.add('basket-item-block-image--placeholder');
      img.setAttribute('src', 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
      return;
    }

    if ((img.getAttribute('src') || '') !== PLACEHOLDER) {
      img.setAttribute('src', PLACEHOLDER);
    }
  }

  function stabilizeBrokenImages(root) {
    root = root || document;
    var imgs = root.querySelectorAll ? root.querySelectorAll('img') : [];
    for (var i = 0; i < imgs.length; i++) {
      var img = imgs[i];
      if (!img || img.getAttribute('data-mf-img-fallback') === '1') continue;
      var src = img.getAttribute('src') || '';
      if (!isMfProductImgSrc(src)) continue;
      if (img.complete && img.naturalWidth === 0) {
        applyImgPlaceholder(img);
      }
    }
  }

  document.addEventListener(
    'error',
    function (e) {
      var t = e && e.target;
      if (!t || !t.tagName || t.tagName.toLowerCase() !== 'img') return;
      var src = t.getAttribute('src') || '';
      if (!isMfProductImgSrc(src)) return;
      applyImgPlaceholder(t);
    },
    true
  );

  function initStabilize() {
    stabilizeBrokenImages(document);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStabilize);
  } else {
    initStabilize();
  }

  if (typeof window !== 'undefined') {
    window.__mfStabilizeBrokenImages = stabilizeBrokenImages;
  }
})();
