(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  var MF_SEARCH_PENDING_KEY = 'mf_search_pending';

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
    document.body.appendChild(overlay);
    return overlay;
  }

  function showSearchLoading() {
    ensureSearchLoadingOverlay();
    document.documentElement.classList.add('mf-search-loading-active');
  }

  function hideSearchLoading() {
    document.documentElement.classList.remove('mf-search-loading-active');
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

  function initSearchPendingOverlay() {
    var pendingFromPage = document.documentElement.classList.contains('mf-search-page-pending');
    var pendingFromStorage = false;
    try { pendingFromStorage = sessionStorage.getItem(MF_SEARCH_PENDING_KEY) === '1'; } catch (_) {}
    if (!pendingFromPage && !pendingFromStorage) return;

    try { sessionStorage.removeItem(MF_SEARCH_PENDING_KEY); } catch (_) {}
    showSearchLoading();
    window.addEventListener('load', function () {
      hideSearchLoading();
      document.documentElement.classList.remove('mf-search-page-pending');
    });
  }

  function mfLoadSearchAnalogs() {
    var hosts = qsa('[data-mf-analogs-for]');
    if (!hosts.length) return;

    var ids = [];
    var seen = {};
    hosts.forEach(function (h) {
      var id = h.getAttribute('data-mf-analogs-for') || '';
      if (!id || seen[id]) return;
      seen[id] = 1;
      ids.push(id);
    });
    if (!ids.length) return;

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

    var applyBlocks = function (resp) {
      if (statusBar) statusBar.remove();
      if (!resp || !resp.ok || !resp.blocks) return;
      var blocks = resp.blocks;
      hosts.forEach(function (host) {
        var pid = host.getAttribute('data-mf-analogs-for') || '';
        var html = blocks[pid];
        if (!html) {
          host.removeAttribute('data-mf-analogs-for');
          return;
        }
        var wrap = document.createElement('div');
        wrap.className = 'mf-search-card__analogs';
        wrap.innerHTML = html;
        host.appendChild(wrap);
        host.removeAttribute('data-mf-analogs-for');
      });
      try {
        if (typeof window.__mfSearchSyncBasket === 'function') {
          window.__mfSearchSyncBasket();
        }
      } catch (_) {}
    };

    var fail = function () {
      if (statusBar) statusBar.remove();
    };

    if (window.fetch) {
      var fd = new FormData();
      ids.forEach(function (id) { fd.append('productIds[]', id); });
      fd.append('limit', '8');
      fetch('/ajax/mf_search_analogs.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json(); })
        .then(applyBlocks)
        .catch(fail);
      return;
    }

    if (window.BX && BX.ajax) {
      BX.ajax({
        url: '/ajax/mf_search_analogs.php',
        method: 'POST',
        dataType: 'json',
        data: { productIds: ids, limit: 8 },
        onsuccess: applyBlocks,
        onfailure: fail
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    initSearchPendingOverlay();
    if (!qsa('[data-mf-analogs-for]').length) return;
    if (window.requestIdleCallback) {
      requestIdleCallback(mfLoadSearchAnalogs, { timeout: 1200 });
    } else {
      setTimeout(mfLoadSearchAnalogs, 200);
    }
  });
})();
