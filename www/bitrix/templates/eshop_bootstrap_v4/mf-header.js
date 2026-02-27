// Motor-Force-like header interactions (no external deps)
(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function syncHeaderHeight() {
    var header = qs('.mf-header');
    if (!header) return;
    var h = header.offsetHeight || 0;
    document.documentElement.style.setProperty('--mf-header-h', h + 'px');

    var nav = qs('.mf-nav');
    var nh = nav ? (nav.offsetHeight || 0) : 0;
    document.documentElement.style.setProperty('--mf-nav-h', nh + 'px');
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
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeMenu();
  });

  // Close menu after click on a menu link (mobile UX)
  document.addEventListener('click', function (e) {
    var link = e.target.closest && e.target.closest('[data-mf="menu-panel"] a');
    if (!link) return;
    var href = link.getAttribute('href') || '';
    if (href && href !== '#') closeMenu();
  });

  // Ensure ARIA initial state
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

