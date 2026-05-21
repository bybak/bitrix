(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  var MF_SEARCH_PENDING_KEY = 'mf_search_pending';
  var MF_STORES_URL = '/ajax/mf_search_stores.php';
  var MF_ANALOGS_URL = '/ajax/mf_search_analogs.php';

  function mfAppendRoot(el) {
    if (!el) return;
    if (document.body) {
      document.body.appendChild(el);
      return;
    }
    document.documentElement.appendChild(el);
  }

  function ensureSearchLoadingOverlay() {
    var overlay = document.getElementById('mf-search-loading');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.id = 'mf-search-loading';
    overlay.className = 'mf-search-loading';
    overlay.setAttribute('role', 'status');
    overlay.setAttribute('aria-live', 'polite');
    overlay.setAttribute('aria-label', 'Идёт поиск');
    overlay.innerHTML = '<div class="mf-search-loading__panel"><div class="mf-search-loading__spinner" aria-hidden="true"></div><div class="mf-search-loading__text">Идёт поиск…</div></div>';
    mfAppendRoot(overlay);
    return overlay;
  }

  function showSearchLoading() {
    ensureSearchLoadingOverlay();
    document.documentElement.classList.add('mf-search-loading-active');
  }

  function hideSearchLoading() {
    document.documentElement.classList.remove('mf-search-loading-active');
  }

  function initSearchPendingOverlay() {
    var pendingFromPage = document.documentElement.classList.contains('mf-search-page-pending');
    var pendingFromStorage = false;
    try { pendingFromStorage = sessionStorage.getItem(MF_SEARCH_PENDING_KEY) === '1'; } catch (_) {}
    if (!pendingFromPage && !pendingFromStorage) return;

    try { sessionStorage.removeItem(MF_SEARCH_PENDING_KEY); } catch (_) {}
    showSearchLoading();
    hideSearchLoading();
    document.documentElement.classList.remove('mf-search-page-pending');
  }

  function mfPostJson(url, ids, extra) {
    extra = extra || {};
    if (window.fetch) {
      var fd = new FormData();
      ids.forEach(function (id) { fd.append('productIds[]', id); });
      Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
      return fetch(url, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) {
          return r.text().then(function (text) {
            try {
              return JSON.parse(text);
            } catch (e0) {
              throw new Error('bad json');
            }
          });
        });
    }
    return new Promise(function (resolve, reject) {
      if (!(window.BX && BX.ajax)) {
        reject(new Error('no ajax'));
        return;
      }
      var data = { productIds: ids };
      Object.keys(extra).forEach(function (k) { data[k] = extra[k]; });
      BX.ajax({
        url: url,
        method: 'POST',
        dataType: 'json',
        data: data,
        onsuccess: resolve,
        onfailure: reject
      });
    });
  }

  function mfUpdatePriceFrom(pid, text) {
    var el = qs('[data-mf-price-for="' + pid + '"]');
    if (!el) return;
    el.classList.remove('mf-product-meta__value--pending');
    el.textContent = String(text || 'Запросить цену');
  }

  function mfRemoveAnalogPending(pid) {
    qsa('[data-mf-analogs-pending-for="' + pid + '"]').forEach(function (n) { n.remove(); });
  }

  function mfLoadSearchStores() {
    var hosts = qsa('[data-mf-stores-for]');
    if (!hosts.length) return Promise.resolve();

    var ids = [];
    var seen = {};
    hosts.forEach(function (h) {
      var id = h.getAttribute('data-mf-stores-for') || '';
      if (!id || seen[id]) return;
      seen[id] = 1;
      ids.push(id);
    });
    if (!ids.length) return Promise.resolve();

    return mfPostJson(MF_STORES_URL, ids).then(function (resp) {
      if (!resp || !resp.ok || !resp.blocks) {
        throw new Error('stores failed');
      }
      var blocks = resp.blocks;
      hosts.forEach(function (host) {
        var pid = host.getAttribute('data-mf-stores-for') || '';
        var block = blocks[pid];
        host.removeAttribute('data-mf-stores-for');
        var availHost = host.querySelector('.mf-search-card__avail--lazy') || host.querySelector('.mf-search-card__avail');
        if (!availHost) return;
        if (block && block.avail) {
          availHost.classList.remove('mf-search-card__avail--lazy');
          availHost.removeAttribute('aria-busy');
          availHost.innerHTML = block.avail;
          if (block.price_from) {
            mfUpdatePriceFrom(pid, block.price_from);
          }
        } else {
          availHost.classList.remove('mf-search-card__avail--lazy');
          availHost.removeAttribute('aria-busy');
          availHost.innerHTML = '<div class="mf-search-card__no-stock">Нет данных по складам</div>';
          mfUpdatePriceFrom(pid, 'Запросить цену');
        }
      });
      try {
        if (typeof window.__mfSearchSyncBasket === 'function') {
          window.__mfSearchSyncBasket();
        }
      } catch (_) {}
    }).catch(function () {
      hosts.forEach(function (host) {
        var pid = host.getAttribute('data-mf-stores-for') || '';
        var availHost = host.querySelector('.mf-search-card__avail--lazy') || host.querySelector('.mf-search-card__avail');
        if (availHost) {
          availHost.innerHTML = '<div class="mf-search-card__no-stock">Не удалось загрузить склады</div>';
          availHost.classList.remove('mf-search-card__avail--lazy');
          availHost.removeAttribute('aria-busy');
        }
        host.removeAttribute('data-mf-stores-for');
        if (pid) mfUpdatePriceFrom(pid, 'Запросить цену');
      });
    });
  }

  function mfLoadSearchAnalogs() {
    var hosts = qsa('[data-mf-analogs-for]');
    if (!hosts.length) return Promise.resolve();

    var ids = [];
    var seen = {};
    hosts.forEach(function (h) {
      var id = h.getAttribute('data-mf-analogs-for') || '';
      if (!id || seen[id]) return;
      seen[id] = 1;
      ids.push(id);
    });
    if (!ids.length) return Promise.resolve();

    var statusBar = document.getElementById('mf-search-analogs-status');
    if (!statusBar) {
      var results = qs('.mf-search__results');
      if (results && results.parentNode) {
        statusBar = document.createElement('div');
        statusBar.id = 'mf-search-analogs-status';
        statusBar.className = 'mf-search-analogs-status';
        statusBar.innerHTML = '<span class="mf-search-analogs-status__spinner" aria-hidden="true"></span> Подбираем аналоги…';
        results.parentNode.insertBefore(statusBar, results.nextSibling);
      }
    }

    return mfPostJson(MF_ANALOGS_URL, ids, { limit: '8' }).then(function (resp) {
      if (statusBar) statusBar.remove();
      if (!resp || !resp.ok || !resp.blocks) {
        ids.forEach(mfRemoveAnalogPending);
        return;
      }
      var blocks = resp.blocks;
      hosts.forEach(function (host) {
        var pid = host.getAttribute('data-mf-analogs-for') || '';
        mfRemoveAnalogPending(pid);
        var html = blocks[pid];
        host.removeAttribute('data-mf-analogs-for');
        if (!html) return;
        var wrap = document.createElement('div');
        wrap.className = 'mf-search-card__analogs';
        wrap.innerHTML = html;
        host.appendChild(wrap);
      });
      try {
        if (typeof window.__mfSearchSyncBasket === 'function') {
          window.__mfSearchSyncBasket();
        }
      } catch (_) {}
    }).catch(function () {
      if (statusBar) statusBar.remove();
      ids.forEach(mfRemoveAnalogPending);
    });
  }

  function mfInitSearchLazy() {
    var hasStores = qsa('[data-mf-stores-for]').length > 0;
    var hasAnalogs = qsa('[data-mf-analogs-for]').length > 0;
    if (!hasStores && !hasAnalogs) return;

    mfLoadSearchStores().then(function () {
      return mfLoadSearchAnalogs();
    });
  }

  function mfInitSearchUi() {
    initSearchPendingOverlay();
    mfInitSearchLazy();
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.classList || !form.classList.contains('mf-shop-search__form')) return;
    var qInput = form.querySelector('input[name="q"], input[name="text"]');
    var val = qInput ? String(qInput.value || '').trim() : '';
    if (val === '') return;
    try { sessionStorage.setItem(MF_SEARCH_PENDING_KEY, '1'); } catch (_) {}
    showSearchLoading();
  }, true);

  document.addEventListener('click', function (e) {
    var link = e.target && e.target.closest ? e.target.closest('.mf-search a[href*="PAGEN_"], .mf-search .bx-pagination a') : null;
    if (!link) return;
    try { sessionStorage.setItem(MF_SEARCH_PENDING_KEY, '1'); } catch (_) {}
    showSearchLoading();
  }, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mfInitSearchUi);
  } else {
    mfInitSearchUi();
  }
})();
