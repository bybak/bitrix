// Motor-Force-like header interactions (no external deps)
(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  var feedbackModals = {
    write: 'mf-write-us-modal',
    callback: 'mf-callback-modal'
  };

  function syncHeaderHeight() {
    var header = qs('.mf-header');
    if (!header) return;
    var h = header.offsetHeight || 0;
    document.documentElement.style.setProperty('--mf-header-h', h + 'px');

    var nav = qs('.mf-nav');
    var nh = nav ? (nav.offsetHeight || 0) : 0;
    document.documentElement.style.setProperty('--mf-nav-h', nh + 'px');

    var legend = qs('.mf-delivery-spb-legend');
    var lh = legend ? (legend.offsetHeight || 0) : 0;
    document.documentElement.style.setProperty('--mf-delivery-spb-legend-h', lh + 'px');
  }

  function initFooterAccordion() {
    qsa('#footer .nh-footer-columns__title-container').forEach(function (title) {
      title.addEventListener('click', function () {
        if (window.matchMedia && window.matchMedia('(min-width: 64rem)').matches) return;
        var section = title.closest && title.closest('.nh-footer-columns__section');
        if (!section) return;
        section.classList.toggle('nh-footer-columns__section--opened');
      });
    });
  }

  function openMenu() {
    document.documentElement.classList.add('mf-menu-open');
    var panel = qs('[data-mf="menu-panel"]');
    if (panel) panel.setAttribute('aria-hidden', 'false');
    var btn = qs('[data-mf="menu-open"]');
    if (btn) btn.setAttribute('aria-expanded', 'true');
  }

  function closeMenu() {
    document.documentElement.classList.remove('mf-menu-open');
    var panel = qs('[data-mf="menu-panel"]');
    if (panel) panel.setAttribute('aria-hidden', 'true');
    var btn = qs('[data-mf="menu-open"]');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }

  function getOpenFeedbackModal() {
    return qs('.mf-header-modal:not([hidden])');
  }

  function setFeedbackMessage(modal, text, isError) {
    if (!modal) return;
    var msg = qs('.mf-header-modal__message', modal);
    if (!msg) return;
    msg.textContent = String(text || '');
    msg.className = 'mf-header-modal__message' + (isError ? ' is-error' : ' is-success');
    msg.hidden = !text;
  }

  function openFeedback(type) {
    var id = feedbackModals[type];
    if (!id) return;
    var modal = document.getElementById(id);
    if (!modal) return;
    closeMenu();
    qsa('.mf-header-modal').forEach(function (m) { m.hidden = true; });
    setFeedbackMessage(modal, '', false);
    modal.hidden = false;
    document.documentElement.classList.add('mf-header-modal-open');
    document.body.classList.add('mf-header-modal-open');
    setTimeout(function () {
      var input = qs('input, textarea', modal);
      if (input) {
        try { input.focus(); } catch (e0) {}
      }
    }, 0);
  }

  function closeFeedback() {
    qsa('.mf-header-modal').forEach(function (m) { m.hidden = true; });
    document.documentElement.classList.remove('mf-header-modal-open');
    document.body.classList.remove('mf-header-modal-open');
  }

  function submitFeedbackForm(form) {
    if (!form) return;
    var modal = form.closest('.mf-header-modal');
    var submitBtn = form.querySelector('button[type="submit"]');
    var oldText = submitBtn ? submitBtn.textContent : '';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Отправляем…';
    }
    setFeedbackMessage(modal, '', false);

    var done = function (resp) {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = oldText;
      }
      if (!resp || !resp.ok) {
        setFeedbackMessage(modal, (resp && resp.error) ? resp.error : 'Не удалось отправить сообщение.', true);
        return;
      }
      setFeedbackMessage(modal, 'Сообщение отправлено. Мы свяжемся с вами.', false);
      form.reset();
      setTimeout(function () {
        closeFeedback();
      }, 1200);
    };

    var payload = {};
    try {
      var fd = new FormData(form);
      fd.forEach(function (value, key) { payload[key] = value; });
    } catch (eFd) {
      done({ ok: false, error: 'Не удалось отправить сообщение.' });
      return;
    }

    if (window.BX && BX.ajax) {
      BX.ajax({
        url: '/ajax/mf_header_feedback.php',
        method: 'POST',
        dataType: 'json',
        data: payload,
        onsuccess: done,
        onfailure: function () {
          done({ ok: false, error: 'Не удалось отправить сообщение.' });
        }
      });
      return;
    }

    if (window.fetch) {
      var body = new FormData(form);
      fetch('/ajax/mf_header_feedback.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: body
      }).then(function (r) { return r.json(); })
        .then(done)
        .catch(function () {
          done({ ok: false, error: 'Не удалось отправить сообщение.' });
        });
    }
  }

  document.addEventListener('click', function (e) {
    var up = e.target.closest && e.target.closest('.js-scroll-up');
    if (up) {
      e.preventDefault();
      try { window.scrollTo({ top: 0, behavior: 'smooth' }); }
      catch (_) { window.scrollTo(0, 0); }
      return;
    }

    var openBtn = e.target.closest && e.target.closest('[data-mf="menu-open"]');
    if (openBtn) { e.preventDefault(); openMenu(); return; }

    var closeBtn = e.target.closest && e.target.closest('[data-mf="menu-close"]');
    if (closeBtn) { e.preventDefault(); closeMenu(); return; }

    var overlay = e.target.closest && e.target.closest('[data-mf="menu-overlay"]');
    if (overlay) { e.preventDefault(); closeMenu(); return; }

    var feedbackOpen = e.target.closest && e.target.closest('[data-mf="open-feedback"]');
    if (feedbackOpen) {
      e.preventDefault();
      openFeedback(feedbackOpen.getAttribute('data-mf-feedback') || 'write');
      return;
    }

    var feedbackClose = e.target.closest && e.target.closest('[data-mf="close-feedback"]');
    if (feedbackClose) {
      e.preventDefault();
      closeFeedback();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (getOpenFeedbackModal()) {
        closeFeedback();
        return;
      }
      closeMenu();
    }
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.hasAttribute || !form.hasAttribute('data-mf-feedback-form')) return;
    e.preventDefault();
    submitFeedbackForm(form);
  });

  // Close menu after click on a menu link (mobile UX)
  document.addEventListener('click', function (e) {
    var link = e.target.closest && e.target.closest('[data-mf="menu-panel"] a');
    if (!link) return;
    var href = link.getAttribute('href') || '';
    if (href && href !== '#') closeMenu();
  });

  document.addEventListener('DOMContentLoaded', function () {
    closeMenu();
    syncHeaderHeight();
    initFooterAccordion();
    qsa('[data-mf="tel"]').forEach(function (a) {
      var t = (a.textContent || '').replace(/[^\d+]/g, '');
      if (t && !a.getAttribute('href')) a.setAttribute('href', 'tel:' + t);
    });
  });

  window.addEventListener('load', syncHeaderHeight);
  window.addEventListener('resize', syncHeaderHeight);
})();
