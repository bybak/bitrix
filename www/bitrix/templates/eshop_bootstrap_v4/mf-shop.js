(function () {
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    // Global image fallback (for <img>, not CSS backgrounds)
    // Works even when inline onerror handlers are blocked by CSP.
    var PLACEHOLDER = '/bitrix/templates/eshop_bootstrap_v4/images/mf-no-photo.svg';
    var HOST_RE = /(^|\/\/)img-motor-force\.ru\//i;

    document.addEventListener(
      'error',
      function (e) {
        var t = e && e.target;
        if (!t || !t.tagName || t.tagName.toLowerCase() !== 'img') return;

        var src = t.getAttribute('src') || '';
        // Only touch external MF images (sections/products). Never touch local images/icons.
        if (!HOST_RE.test(src)) return;
        if (src === PLACEHOLDER) return;

        t.setAttribute('src', PLACEHOLDER);
      },
      true
    );

    // Product detail: move the "small card" buy panel into the pay block (near gallery),
    // remove empty info blocks, and simplify tabs into a plain H2 heading.
    var detailRoot = document.querySelector('.mf-shop--detail');
    if (detailRoot) {
      try {
        // Drop empty right-side info section entirely (it's often empty in our setup).
        var infoSection = detailRoot.querySelector('.product-item-detail-info-section');
        if (infoSection && !infoSection.children.length && !(infoSection.textContent || '').trim()) {
          infoSection.remove();
        }

        // Replace Bitrix tabs header with a simple H2.
        var tabs = detailRoot.querySelector('.product-item-detail-tabs-container');
        var tabContent = detailRoot.querySelector('.product-item-detail-tab-content[data-value="description"]');
        if (tabs && tabContent && !detailRoot.querySelector('.mf-detail-h2')) {
          var h2 = document.createElement('h2');
          h2.className = 'mf-detail-h2';
          h2.textContent = 'Описание';
          tabContent.parentNode && tabContent.parentNode.insertBefore(h2, tabContent);
          tabs.remove();
        }

        var payBlock = detailRoot.querySelector('.product-item-detail-pay-block');
        var shortCard = detailRoot.querySelector('.product-item-detail-short-card-fixed');
        if (payBlock && shortCard) {
          shortCard.classList.remove('product-item-detail-short-card-fixed');
          shortCard.classList.remove('hidden-xs');
          payBlock.appendChild(shortCard);

          // Hide the fixed tabs bar (it looks redundant with the normal tabs).
          var fixedTabs = detailRoot.querySelector('.product-item-detail-tabs-container-fixed');
          if (fixedTabs) fixedTabs.style.display = 'none';

          // Bitrix element JS expects quantity input to exist; keep it hidden with default=1.
          var buyBtn = shortCard.querySelector('a[id$="_add_basket_link"], a[id$="_buy_link"]');
          if (buyBtn && buyBtn.id) {
            var prefix = buyBtn.id.replace(/_(add_basket_link|buy_link)$/, '');
            var qtyId = prefix + '_quantity';
            if (qtyId && !document.getElementById(qtyId)) {
              var qty = document.createElement('input');
              qty.type = 'hidden';
              qty.id = qtyId;
              qty.name = 'quantity';
              qty.value = '1';
              shortCard.appendChild(qty);
            }

            // Bitrix JCCatalogElement also requires a "main price" node + basket actions container,
            // even if we display price/actions only inside the small card panel.
            var priceId = prefix + '_price';
            if (!document.getElementById(priceId)) {
              var priceTextEl = shortCard.querySelector('[data-entity="panel-price"]');
              var price = document.createElement('span');
              price.id = priceId;
              price.style.display = 'none';
              price.textContent = priceTextEl ? (priceTextEl.textContent || '').trim() : '';
              shortCard.appendChild(price);
            }

            var basketActionsId = prefix + '_basket_actions';
            var panelAddContainer = shortCard.querySelector('[data-entity="panel-add-button"]');
            if (panelAddContainer && !document.getElementById(basketActionsId)) {
              panelAddContainer.id = basketActionsId;
            }

            var notAvailId = prefix + '_not_avail';
            var panelNotAvail = shortCard.querySelector('[data-entity="panel-not-available-button"]');
            if (panelNotAvail && !document.getElementById(notAvailId)) {
              panelNotAvail.id = notAvailId;
            }
          }

          // Fill panel picture from the main slider image if it was left empty.
          var panelImg = shortCard.querySelector('img[data-entity="panel-picture"]');
          if (panelImg && !panelImg.getAttribute('src')) {
            var mainImg =
              detailRoot.querySelector('.product-item-detail-slider-image.active img') ||
              detailRoot.querySelector('.product-item-detail-slider-image img');
            var src = mainImg && mainImg.getAttribute ? mainImg.getAttribute('src') : '';
            if (src) panelImg.setAttribute('src', src);
          }
        }

        // Product detail gallery: big image + thumbnails row.
        // Bitrix markup already contains both big images and thumbnail controls; we only ensure
        // sane layout and add a small click fallback (keep Bitrix logic intact).
        var slider = detailRoot.querySelector('.product-item-detail-slider-container');
        if (slider) {
          var controls = slider.querySelectorAll('.product-item-detail-slider-controls-image[data-entity="slider-control"][data-value]');
          slider.classList.toggle('mf-gallery--single', controls.length <= 1);
          slider.classList.toggle('mf-gallery--multi', controls.length > 1);

          var controlsBlock = slider.querySelector('.product-item-detail-slider-controls-block');
          if (controlsBlock && !controlsBlock.__mfBound) {
            controlsBlock.__mfBound = true;
            controlsBlock.addEventListener('click', function (e) {
              var t = e && e.target;
              var btn = t && t.closest ? t.closest('.product-item-detail-slider-controls-image[data-value]') : null;
              if (!btn) return;

              var id = btn.getAttribute('data-value') || '';
              if (!id) return;

              // Update thumbs active state
              var thumbs = slider.querySelectorAll('.product-item-detail-slider-controls-image');
              for (var i = 0; i < thumbs.length; i++) {
                thumbs[i].classList.toggle('active', thumbs[i] === btn);
              }

              // Update big image active state
              var images = slider.querySelectorAll('.product-item-detail-slider-image[data-entity="image"][data-id]');
              for (var j = 0; j < images.length; j++) {
                images[j].classList.toggle('active', images[j].getAttribute('data-id') === id);
              }
            });
          }
        }
      } catch (e) {
        // no-op: never break the page if markup differs
      }
    }

    var root = document.querySelector('.mf-shop-tree');
    if (!root) return;

    // UX requirement: clicking a category in the tree should navigate (URL changes),
    // and the server will render the branch expanded + left cards updated.
    root.addEventListener('click', function (e) {
      var summary = e.target && e.target.closest ? e.target.closest('.mf-shop-tree__summary') : null;
      if (!summary) return;
      var link = summary.querySelector && summary.querySelector('a[href]');
      if (!link) return;
      e.preventDefault();
      // If clicking the already-open current category, go one level up.
      var isCurrent = link.classList && link.classList.contains('is-current');
      var details = summary.closest ? summary.closest('details') : null;
      var parentUrl = link.getAttribute && link.getAttribute('data-parent-url');
      if (isCurrent && details && details.open && parentUrl) {
        window.location.href = parentUrl;
        return;
      }
      window.location.href = link.href;
    });
  });
})();

