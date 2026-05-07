(function () {
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  // Global image fallback (for <img>, not CSS backgrounds).
  // Bound immediately (not waiting for DOMContentLoaded), so we don't miss early load errors.
  (function bindMfImgFallback() {
    if (typeof window !== 'undefined' && window.__mfImgFallbackBound) return;
    if (typeof window !== 'undefined') window.__mfImgFallbackBound = true;

    var PLACEHOLDER = '/bitrix/templates/eshop_bootstrap_v4/images/mf-no-photo.svg';
    // Match both legacy direct host and local proxy.
    var HOST_RE = /(^\/mf-img\/)|(^|\/\/)img-motor-force\.ru\//i;

    document.addEventListener(
      'error',
      function (e) {
        var t = e && e.target;
        if (!t || !t.tagName || t.tagName.toLowerCase() !== 'img') return;

        var src = t.getAttribute('src') || '';
        // Only touch MF images. Never touch local icons/assets.
        if (!HOST_RE.test(src)) return;
        if (src === PLACEHOLDER) return;

        // Prevent the browser from trying broken candidates from srcset.
        try {
          t.removeAttribute('srcset');
        } catch (e2) {}
        t.setAttribute('src', PLACEHOLDER);
      },
      true
    );
  })();

  onReady(function () {
    function mfBuildAuthUrlsWithBackUrl() {
      try {
        var u = new URL(window.location.href);
        [
          'login',
          'login_form',
          'logout',
          'register',
          'forgot_password',
          'change_password',
          'confirm_registration',
          'confirm_code',
          'confirm_user_id',
          'logout_butt',
          'auth_service_id',
          'clear_cache',
          'backurl'
        ].forEach(function (k) {
          u.searchParams.delete(k);
        });
        var back = encodeURIComponent(u.pathname + (u.search || ''));
        return {
          login: '/login/?login=yes&backurl=' + back,
          register: '/login/?register=yes&backurl=' + back
        };
      } catch (e) {
        var path = (window.location && window.location.pathname) ? window.location.pathname : '/';
        var qs = (window.location && window.location.search) ? window.location.search : '';
        var back2 = encodeURIComponent(path + qs);
        return {
          login: '/login/?login=yes&backurl=' + back2,
          register: '/login/?register=yes&backurl=' + back2
        };
      }
    }

    function ensureRequestPriceModal() {
      var modal = document.getElementById('mf-global-request-price-modal');
      if (modal) return modal;

      var wrap = document.createElement('div');
      wrap.innerHTML = [
        '<div class="mf-shop-modal" id="mf-global-request-price-modal" hidden>',
        '  <div class="mf-shop-modal__backdrop js-mf-shop-request-close"></div>',
        '  <div class="mf-shop-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mf-shop-request-title">',
        '    <button type="button" class="mf-shop-modal__close js-mf-shop-request-close" aria-label="Закрыть">×</button>',
        '    <div class="mf-shop-modal__title" id="mf-shop-request-title">Запросить цену</div>',
        '    <div class="mf-shop-modal__subtitle" id="mf-shop-request-product"></div>',
        '    <div class="mf-shop-modal__auth js-mf-shop-request-auth" hidden>',
        '      <p class="mf-shop-modal__auth-text">Войдите или зарегистрируйтесь — после возврата на эту страницу данные профиля подставятся в форму, и вы сможете отправить запрос.</p>',
        '      <div class="mf-shop-modal__auth-actions">',
        '        <a class="btn btn-outline-dark mf-shop-modal__auth-btn js-mf-request-price-auth-link js-mf-shop-login-link" href="/login/">Войти</a>',
        '        <a class="btn btn-outline-dark mf-shop-modal__auth-btn js-mf-request-price-auth-link js-mf-shop-register-link" href="/login/">Регистрация</a>',
        '      </div>',
        '    </div>',
        '    <div class="mf-shop-modal__message" id="mf-shop-request-message" hidden></div>',
        '    <form class="mf-shop-modal__form" id="mf-shop-request-form">',
        '      <input type="hidden" name="sessid" value="' + ((window.BX && BX.bitrix_sessid) ? BX.bitrix_sessid() : '') + '">',
        '      <input type="hidden" name="product_id" value="">',
        '      <input type="hidden" name="product_name" value="">',
        '      <input type="hidden" name="product_url" value="">',
        '      <div class="form-group">',
        '        <label for="mf-shop-request-name">Имя</label>',
        '        <input id="mf-shop-request-name" type="text" class="form-control" name="name" value="">',
        '      </div>',
        '      <div class="form-group">',
        '        <label for="mf-shop-request-email">E-mail</label>',
        '        <input id="mf-shop-request-email" type="email" class="form-control" name="email" value="">',
        '      </div>',
        '      <div class="form-group">',
        '        <label for="mf-shop-request-comment">Комментарий</label>',
        '        <textarea id="mf-shop-request-comment" class="form-control" name="comment" rows="5"></textarea>',
        '      </div>',
        '      <div class="mf-shop-modal__actions">',
        '        <button type="submit" class="btn btn-warning mf-shop-modal__submit">Отправить</button>',
        '      </div>',
        '    </form>',
        '  </div>',
        '</div>'
      ].join('');
      modal = wrap.firstChild;
      document.body.appendChild(modal);
      return modal;
    }

    function setRequestMessage(text, isError) {
      var box = document.getElementById('mf-shop-request-message');
      if (!box) return;
      box.textContent = String(text || '');
      box.className = 'mf-shop-modal__message' + (isError ? ' is-error' : ' is-success');
      box.hidden = !text;
    }

    function closeRequestModal() {
      var modal = document.getElementById('mf-global-request-price-modal');
      if (!modal) return;
      modal.hidden = true;
      document.documentElement.classList.remove('mf-shop-modal-open');
      document.body.classList.remove('mf-shop-modal-open');
    }

    function openRequestModal(btn) {
      var modal = ensureRequestPriceModal();
      var form = document.getElementById('mf-shop-request-form');
      var subtitle = document.getElementById('mf-shop-request-product');
      if (!modal || !form) return;

      var productName = btn.getAttribute('data-product-name') || '';
      var userName = btn.getAttribute('data-user-name') || '';
      var userEmail = btn.getAttribute('data-user-email') || '';
      var locked = btn.getAttribute('data-user-locked') === '1';

      form.elements.product_id.value = btn.getAttribute('data-product-id') || '';
      form.elements.product_name.value = productName;
      form.elements.product_url.value = btn.getAttribute('data-product-url') || '';
      form.elements.name.value = userName;
      form.elements.email.value = userEmail;
      form.elements.comment.value = '';
      form.elements.name.readOnly = locked && !!userName;
      form.elements.email.readOnly = locked && !!userEmail;
      if (subtitle) subtitle.textContent = productName;
      var authBox = modal.querySelector('.js-mf-shop-request-auth');
      if (authBox) {
        authBox.hidden = locked;
        if (!locked) {
          var urls = mfBuildAuthUrlsWithBackUrl();
          var aLogin = authBox.querySelector('.js-mf-shop-login-link');
          var aReg = authBox.querySelector('.js-mf-shop-register-link');
          if (aLogin) aLogin.href = urls.login;
          if (aReg) aReg.href = urls.register;
        }
      }
      setRequestMessage('', false);
      modal.hidden = false;
      document.documentElement.classList.add('mf-shop-modal-open');
      document.body.classList.add('mf-shop-modal-open');
      setTimeout(function () {
        try {
          if (form.elements.comment) form.elements.comment.focus();
        } catch (e) {}
      }, 0);
    }

    function submitRequestModal() {
      var form = document.getElementById('mf-shop-request-form');
      if (!form) return;
      var submitBtn = form.querySelector('button[type="submit"]');
      var oldText = submitBtn ? submitBtn.textContent : '';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Отправляем…';
      }
      setRequestMessage('', false);

      var done = function (resp) {
        if (!resp || !resp.ok) {
          setRequestMessage((resp && resp.error) ? resp.error : 'Не удалось отправить сообщение.', true);
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = oldText;
          }
          return;
        }
        setRequestMessage('Сообщение отправлено. Мы свяжемся с вами.', false);
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = oldText;
        }
        setTimeout(closeRequestModal, 900);
      };

      if (window.BX && BX.ajax) {
        BX.ajax({
          url: '/ajax/mf_request_price.php',
          method: 'POST',
          dataType: 'json',
          data: {
            sessid: form.elements.sessid.value,
            product_id: form.elements.product_id.value,
            product_name: form.elements.product_name.value,
            product_url: form.elements.product_url.value,
            name: form.elements.name.value,
            email: form.elements.email.value,
            comment: form.elements.comment.value
          },
          onsuccess: done,
          onfailure: function () {
            done({ ok: false, error: 'Не удалось отправить сообщение.' });
          }
        });
        return;
      }

      if (window.fetch) {
        var fd = new FormData(form);
        fetch('/ajax/mf_request_price.php', {
          method: 'POST',
          credentials: 'same-origin',
          body: fd
        }).then(function (r) { return r.json(); })
          .then(done)
          .catch(function () {
            done({ ok: false, error: 'Не удалось отправить сообщение.' });
          });
      }
    }

    document.addEventListener('click', function (e) {
      var reqBtn = e && e.target && e.target.closest ? e.target.closest('.js-mf-request-price-global') : null;
      if (reqBtn) {
        e.preventDefault();
        openRequestModal(reqBtn);
        return;
      }
      var closeBtn = e && e.target && e.target.closest ? e.target.closest('.js-mf-shop-request-close') : null;
      if (closeBtn) {
        e.preventDefault();
        closeRequestModal();
      }
    }, true);

    document.addEventListener('keydown', function (e) {
      if (e && e.key === 'Escape') closeRequestModal();
    });

    document.addEventListener('submit', function (e) {
      var form = e && e.target && e.target.closest ? e.target.closest('#mf-shop-request-form') : null;
      if (!form) return;
      e.preventDefault();
      submitRequestModal();
    }, true);

    var MF_REQ_PRICE_RESUME_KEY = 'mf_request_price_resume';

    function mfSaveShopRequestPriceResumeForAuth() {
      var form = document.getElementById('mf-shop-request-form');
      if (!form) return;
      try {
        sessionStorage.setItem(MF_REQ_PRICE_RESUME_KEY, JSON.stringify({
          v: 1,
          scope: 'shop',
          product_id: form.elements.product_id ? form.elements.product_id.value : '',
          product_name: form.elements.product_name ? form.elements.product_name.value : '',
          product_url: form.elements.product_url ? form.elements.product_url.value : '',
          user_name: form.elements.name ? form.elements.name.value : '',
          user_email: form.elements.email ? form.elements.email.value : '',
          user_locked: !!(form.elements.name && form.elements.name.readOnly)
        }));
      } catch (e) {}
    }

    function mfTryResumeRequestPriceShop() {
      try {
        var raw = sessionStorage.getItem(MF_REQ_PRICE_RESUME_KEY);
        if (!raw) return;
        var data = JSON.parse(raw);
        if (!data || data.v !== 1 || data.scope !== 'shop') return;
        sessionStorage.removeItem(MF_REQ_PRICE_RESUME_KEY);
        var pid = String(data.product_id || '');
        var btn = null;
        if (pid) {
          var nodes = document.querySelectorAll('.js-mf-request-price-global');
          for (var i = 0; i < nodes.length; i++) {
            if ((nodes[i].getAttribute('data-product-id') || '') === pid) {
              btn = nodes[i];
              break;
            }
          }
        }
        if (btn) {
          openRequestModal(btn);
          return;
        }
        var modal = ensureRequestPriceModal();
        var form = document.getElementById('mf-shop-request-form');
        if (!modal || !form) return;
        form.elements.product_id.value = data.product_id || '';
        form.elements.product_name.value = data.product_name || '';
        form.elements.product_url.value = data.product_url || '';
        form.elements.name.value = data.user_name || '';
        form.elements.email.value = data.user_email || '';
        form.elements.comment.value = '';
        var locked = !!data.user_locked;
        form.elements.name.readOnly = locked && !!(data.user_name);
        form.elements.email.readOnly = locked && !!(data.user_email);
        var subtitle = document.getElementById('mf-shop-request-product');
        if (subtitle) subtitle.textContent = form.elements.product_name.value;
        var authBox = modal.querySelector('.js-mf-shop-request-auth');
        if (authBox) {
          authBox.hidden = locked;
          if (!locked) {
            var urls = mfBuildAuthUrlsWithBackUrl();
            var aLogin = authBox.querySelector('.js-mf-shop-login-link');
            var aReg = authBox.querySelector('.js-mf-shop-register-link');
            if (aLogin) aLogin.href = urls.login;
            if (aReg) aReg.href = urls.register;
          }
        }
        setRequestMessage('', false);
        modal.hidden = false;
        document.documentElement.classList.add('mf-shop-modal-open');
        document.body.classList.add('mf-shop-modal-open');
        setTimeout(function () {
          try {
            if (form.elements.comment) form.elements.comment.focus();
          } catch (e2) {}
        }, 0);
      } catch (e1) {}
    }

    document.addEventListener('click', function (e) {
      var a = e && e.target && e.target.closest ? e.target.closest('a.js-mf-request-price-auth-link') : null;
      if (!a || !a.getAttribute('href')) return;
      var modal = document.getElementById('mf-global-request-price-modal');
      if (!modal || modal.hidden || !modal.contains(a)) return;
      mfSaveShopRequestPriceResumeForAuth();
    }, true);

    setTimeout(mfTryResumeRequestPriceShop, 0);

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

