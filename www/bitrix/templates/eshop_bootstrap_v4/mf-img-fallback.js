(function () {
  try {
    if (typeof window !== 'undefined' && window.__mfImgFallbackBound) return;
    if (typeof window !== 'undefined') window.__mfImgFallbackBound = true;
  } catch (e) {}

  var PLACEHOLDER = '/bitrix/templates/eshop_bootstrap_v4/images/mf-no-photo.svg';
  // Match both legacy direct host and local proxy.
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

