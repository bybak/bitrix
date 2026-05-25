(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  var MF_SEARCH_PENDING_KEY = 'mf_search_pending';
  var MF_STORES_URL = '/ajax/mf_search_stores.php';
  var MF_ANALOGS_URL = '/ajax/mf_search_analogs.php';
  var MF_STAGE_URL = '/ajax/mf_search_stage.php';

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

  function mfCollectSearchProductIds(root) {
    root = root || document;
    var ids = [];
    var seen = {};
    qsa('.mf-search-card--root[data-product-id]', root).forEach(function (el) {
      var id = el.getAttribute('data-product-id') || '';
      if (!id || seen[id]) return;
      seen[id] = 1;
      ids.push(id);
    });
    return ids;
  }

  function mfUpdateSearchSummary(root) {
    if (!root) return;
    var countEl = qs('[data-mf-search-count]', root);
    if (countEl) {
      countEl.textContent = String(mfCollectSearchProductIds(root).length);
    }
  }

  function mfSetSearchStageNote(root, text, visible) {
    if (!root) return;
    var note = qs('[data-mf-search-stage-note]', root);
    if (!note) return;
    if (typeof text === 'string') note.textContent = text;
    note.hidden = !visible;
  }

  function mfHideSearchEmptyPending(root) {
    if (!root) return;
    var pending = qs('[data-mf-search-empty-pending]', root);
    if (pending) pending.remove();
  }

  function mfShowSearchEmptyFinal(root, query) {
    if (!root) return;
    mfHideSearchEmptyPending(root);
    if (mfCollectSearchProductIds(root).length > 0) return;
    if (qs('[data-mf-search-empty-final]', root)) return;
    var box = document.createElement('div');
    box.className = 'mf-search__empty';
    box.setAttribute('data-mf-search-empty-final', '1');
    box.innerHTML = '<strong>Ничего не найдено</strong>'
      + (query ? (' по запросу «' + String(query).replace(/</g, '&lt;') + '».') : '.')
      + '<div style="margin-top: 8px;">Попробуйте изменить формулировку или сократить запрос.</div>';
    var results = qs('.mf-search__results', root);
    if (results && results.parentNode) {
      results.parentNode.insertBefore(box, results.nextSibling);
    }
  }

  function mfLoadSearchStage(root, stage, query) {
    var excludeIds = mfCollectSearchProductIds(root);
    return mfPostJson(MF_STAGE_URL, excludeIds, { q: query, stage: String(stage) }).then(function (resp) {
      if (!resp || !resp.ok) {
        throw new Error('stage failed');
      }
      if (resp.html) {
        var results = qs('.mf-search__results', root);
        if (results) {
          var wrap = document.createElement('div');
          wrap.innerHTML = resp.html;
          while (wrap.firstChild) {
            results.appendChild(wrap.firstChild);
          }
        }
        mfHideSearchEmptyPending(root);
        mfUpdateSearchSummary(root);
        return mfLoadSearchStores();
      }
      return null;
    });
  }

  function mfLoadSearchProgressive() {
    var root = qs('.mf-search[data-mf-search-progressive="1"]');
    if (!root) return Promise.resolve();
    var query = root.getAttribute('data-mf-search-query') || '';
    if (query.trim() === '') return Promise.resolve();

    var runStage = function (stage) {
      var labels = { 2: 'Ищем по названию…', 3: 'Расширенный поиск…' };
      mfSetSearchStageNote(root, labels[stage] || 'Ищем…', true);
      return mfLoadSearchStage(root, stage, query).then(function () {
        if (stage === 2) {
          return runStage(3);
        }
        return null;
      });
    };

    return runStage(2).then(function () {
      mfSetSearchStageNote(root, '', false);
      mfUpdateSearchSummary(root);
      if (mfCollectSearchProductIds(root).length === 0) {
        mfShowSearchEmptyFinal(root, query);
      }
    }).catch(function () {
      mfSetSearchStageNote(root, '', false);
      mfUpdateSearchSummary(root);
    });
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

  function mfAvailHasNoStoreData(html) {
    return String(html || '').indexOf('Нет данных по складам') !== -1;
  }

  function mfAvailDomHasNoStoreData(availHost) {
    if (!availHost) return true;
    if (availHost.querySelector('.mf-search-card__no-stock')) return true;
    var rows = availHost.querySelectorAll('.mf-search-stock-table tbody tr');
    return !rows || !rows.length;
  }

  function mfResolveSearchPriceFrom(block) {
    if (!block) return '';
    if (mfAvailHasNoStoreData(block.avail)) return '';
    if (block.show_price_from === false || block.show_price_from === 0) return '';
    return String(block.price_from || '').trim();
  }

  function mfEscAttr(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function mfApplyPricePill(host, pid, text) {
    if (!host || !pid) return;
    var meta = host.querySelector ? host.querySelector('.mf-product-meta') : null;
    if (!meta) return;

    var priceText = String(text || '').trim();
    var item = meta.querySelector('[data-mf-price-item="' + pid + '"]');

    if (!priceText) {
      if (item && item.parentNode) item.parentNode.removeChild(item);
      return;
    }

    if (!item) {
      item = document.createElement('div');
      item.className = 'mf-product-meta__item';
      item.setAttribute('data-mf-price-item', pid);
      item.innerHTML = ''
        + '<span class="mf-product-meta__label">От</span>'
        + '<span class="mf-product-meta__value" data-mf-price-for="' + mfEscAttr(pid) + '"></span>';
      meta.insertBefore(item, meta.firstChild);
    }

    item.hidden = false;
    item.style.display = '';
    var el = item.querySelector('[data-mf-price-for]');
    if (el) {
      el.classList.remove('mf-product-meta__value--pending');
      el.textContent = priceText;
    }
  }

  function mfSyncPricePillFromAvail(host, pid, availHost, block) {
    if (!host || !pid || !availHost) return;
    var priceText = block ? mfResolveSearchPriceFrom(block) : '';
    if (mfAvailDomHasNoStoreData(availHost)) {
      mfApplyPricePill(host, pid, '');
      return;
    }
    mfApplyPricePill(host, pid, priceText);
  }

  function mfUpdatePriceFrom(pid, text) {
    qsa('.mf-search-card[data-product-id="' + pid + '"]').forEach(function (host) {
      var availHost = host.querySelector('.mf-search-card__avail');
      if (availHost && mfAvailDomHasNoStoreData(availHost)) {
        mfApplyPricePill(host, pid, '');
        return;
      }
      mfApplyPricePill(host, pid, text);
    });
  }

  function mfRemoveAnalogPending(pid) {
    qsa('[data-mf-analogs-pending-for="' + pid + '"]').forEach(function (n) { n.remove(); });
  }

  function mfBuildNoStockAvailHtml(host, pid, message) {
    var card = host && host.closest ? host.closest('.mf-search-card--root, .mf-search-card') : null;
    var name = card ? (card.getAttribute('data-product-name') || '') : '';
    var url = card ? (card.getAttribute('data-product-url') || '') : '';
    return ''
      + '<div class="mf-search-card__no-stock-row">'
      + '<div class="mf-search-card__no-stock">' + mfEscAttr(message || 'Нет данных по складам') + '</div>'
      + '<button type="button"'
      + ' class="btn btn-sm btn-warning mf-search-stock__btn mf-search-stock__btn--request js-mf-request-price"'
      + ' data-product-id="' + mfEscAttr(pid) + '"'
      + ' data-product-name="' + mfEscAttr(name) + '"'
      + ' data-product-url="' + mfEscAttr(url) + '"'
      + '>Запросить цену</button>'
      + '</div>';
  }

  var mfStoresLoadChain = Promise.resolve();

  function mfLoadSearchStoresOnce() {
    var hosts = qsa('[data-mf-stores-for]');
    if (!hosts.length) return Promise.resolve();

    var ids = [];
    var seen = {};
    hosts.forEach(function (h) {
      var id = h.getAttribute('data-mf-stores-for') || h.getAttribute('data-product-id') || '';
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
        var pid = host.getAttribute('data-mf-stores-for') || host.getAttribute('data-product-id') || '';
        var block = blocks[pid];
        host.removeAttribute('data-mf-stores-for');
        var availHost = host.querySelector('.mf-search-card__avail--lazy') || host.querySelector('.mf-search-card__avail');
        if (!availHost) return;
        if (block && block.avail) {
          availHost.classList.remove('mf-search-card__avail--lazy');
          availHost.removeAttribute('aria-busy');
          availHost.innerHTML = block.avail;
        } else {
          availHost.classList.remove('mf-search-card__avail--lazy');
          availHost.removeAttribute('aria-busy');
          availHost.innerHTML = mfBuildNoStockAvailHtml(host, pid, 'Нет данных по складам');
        }
        mfSyncPricePillFromAvail(host, pid, availHost, block || null);
      });
    }).catch(function () {
      hosts.forEach(function (host) {
        var pid = host.getAttribute('data-mf-stores-for') || host.getAttribute('data-product-id') || '';
        var availHost = host.querySelector('.mf-search-card__avail--lazy') || host.querySelector('.mf-search-card__avail');
        if (availHost) {
          availHost.innerHTML = mfBuildNoStockAvailHtml(host, pid, 'Не удалось загрузить склады');
          availHost.classList.remove('mf-search-card__avail--lazy');
          availHost.removeAttribute('aria-busy');
        }
        host.removeAttribute('data-mf-stores-for');
        mfSyncPricePillFromAvail(host, pid, availHost, null);
      });
    });
  }

  function mfLoadSearchStores() {
    mfStoresLoadChain = mfStoresLoadChain.then(function () {
      return mfLoadSearchStoresOnce();
    }, function () {
      return mfLoadSearchStoresOnce();
    });
    return mfStoresLoadChain;
  }

  function mfApplyAnalogBlocks(blocks, ids) {
    if (!blocks) return;
    var idSet = {};
    (ids || []).forEach(function (id) { idSet[String(id)] = 1; });
    qsa('[data-mf-analogs-for]').forEach(function (host) {
      var pid = host.getAttribute('data-mf-analogs-for') || '';
      if (!pid) return;
      if (ids && ids.length && !idSet[pid]) return;
      mfRemoveAnalogPending(pid);
      var html = blocks[pid];
      host.removeAttribute('data-mf-analogs-for');
      if (!html) return;
      var wrap = document.createElement('div');
      wrap.className = 'mf-search-card__analogs';
      wrap.innerHTML = html;
      host.appendChild(wrap);
    });
  }

  function mfEnsureAnalogsStatusBar() {
    var statusBar = document.getElementById('mf-search-analogs-status');
    if (statusBar) return statusBar;
    var results = qs('.mf-search__results');
    if (!results || !results.parentNode) return null;
    statusBar = document.createElement('div');
    statusBar.id = 'mf-search-analogs-status';
    statusBar.className = 'mf-search-analogs-status';
    statusBar.innerHTML = '<span class="mf-search-analogs-status__spinner" aria-hidden="true"></span> Подбираем аналоги…';
    results.parentNode.insertBefore(statusBar, results.nextSibling);
    return statusBar;
  }

  function mfLoadSearchAnalogsChunk(ids) {
    if (!ids.length) return Promise.resolve();
    var idSet = {};
    ids.forEach(function (id) { idSet[String(id)] = 1; });
    return mfPostJson(MF_ANALOGS_URL, ids, { limit: '8' }).then(function (resp) {
      if (!resp || !resp.ok || !resp.blocks) {
        ids.forEach(mfRemoveAnalogPending);
        return;
      }
      mfApplyAnalogBlocks(resp.blocks);
    }).catch(function () {
      ids.forEach(mfRemoveAnalogPending);
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

    var statusBar = mfEnsureAnalogsStatusBar();
    var chunkSize = 6;
    var chunks = [];
    for (var i = 0; i < ids.length; i += chunkSize) {
      chunks.push(ids.slice(i, i + chunkSize));
    }

    var chain = Promise.resolve();
    chunks.forEach(function (chunk, idx) {
      chain = chain.then(function () {
        return mfLoadSearchAnalogsChunk(chunk).then(function () {
          if (idx === 0 && statusBar) {
            statusBar.remove();
          }
          if (idx === 0) {
            return mfLoadSearchStores();
          }
          return null;
        });
      });
    });

    return chain.then(function () {
      if (statusBar && statusBar.parentNode) statusBar.remove();
      return mfLoadSearchStores();
    }).then(function () {
      try {
        if (typeof window.__mfSearchSyncBasket === 'function') {
          window.__mfSearchSyncBasket();
        }
      } catch (_) {}
    }).catch(function () {
      if (statusBar && statusBar.parentNode) statusBar.remove();
      ids.forEach(mfRemoveAnalogPending);
    });
  }

  function mfInitSearchUi() {
    initSearchPendingOverlay();
    var storesPromise = mfLoadSearchStores();
    var analogsPromise = mfLoadSearchAnalogs();
    storesPromise
      .then(function () {
        return mfLoadSearchProgressive();
      })
      .then(function () {
        return mfLoadSearchStores();
      })
      .then(function () {
        return mfLoadSearchAnalogs();
      })
      .then(function () {
        try {
          if (typeof window.__mfSearchSyncBasket === 'function') {
            window.__mfSearchSyncBasket();
          }
        } catch (_) {}
      });
    analogsPromise.catch(function () {});
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
