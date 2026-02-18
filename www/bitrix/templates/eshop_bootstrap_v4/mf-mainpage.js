// Motor-Force-like mainpage slider interactions (no deps)
(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function normalizeUrl(url) {
    if (!url) return url;
    if (url.indexOf('//') === 0) return 'https:' + url;
    return url;
  }

  function initMainPageSlider() {
    var root = qs('.main-page-slider');
    if (!root) return;

    var slider = qs('.js-slider', root);
    if (!slider) return;

    var list = qs('.slick-list', slider);
    var track = qs('.slick-track', slider);
    if (!list || !track) return;

    var slides = qsa('.slick-slide', track);
    if (!slides.length) return;

    // Apply lazy backgrounds and protocol-less src
    slides.forEach(function (slide) {
      qsa('img', slide).forEach(function (img) {
        img.setAttribute('src', normalizeUrl(img.getAttribute('src')));
      });
      var bg = qs('.slider-item__background', slide);
      if (bg && bg.getAttribute('data-bg')) {
        bg.style.backgroundImage = 'url(' + normalizeUrl(bg.getAttribute('data-bg')) + ')';
      }
    });

    var state = { index: 0, w: 0, dragging: false, startX: 0, dx: 0 };

    function ensureDots() {
      var dots = qs('.slick-dots', slider);
      if (!dots) {
        dots = document.createElement('ul');
        dots.className = 'slick-dots';
        slider.appendChild(dots);
      }
      dots.innerHTML = '';
      slides.forEach(function (_, i) {
        var li = document.createElement('li');
        if (i === state.index) li.className = 'slick-active';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.addEventListener('click', function () { go(i, true); });
        li.appendChild(btn);
        dots.appendChild(li);
      });
    }

    function updateDots() {
      var lis = qsa('.slick-dots li', slider);
      lis.forEach(function (li, i) {
        if (i === state.index) li.classList.add('slick-active');
        else li.classList.remove('slick-active');
      });
    }

    function layout() {
      state.w = list.clientWidth || 0;
      if (!state.w) return;
      track.style.width = (state.w * slides.length) + 'px';
      slides.forEach(function (s) { s.style.width = state.w + 'px'; });
      go(state.index, false);
    }

    function go(i, animate) {
      if (!slides.length) return;
      state.index = (i + slides.length) % slides.length;
      track.style.transition = animate ? 'transform 420ms ease' : 'none';
      track.style.transform = 'translate3d(' + (-state.index * state.w) + 'px,0,0)';
      updateDots();
    }

    function next() { go(state.index + 1, true); }
    function prev() { go(state.index - 1, true); }

    var btnNext = qs('.main-slider-arrow__next', root);
    var btnPrev = qs('.main-slider-arrow__prev', root);
    if (btnNext) btnNext.addEventListener('click', function (e) { e.preventDefault(); next(); });
    if (btnPrev) btnPrev.addEventListener('click', function (e) { e.preventDefault(); prev(); });

    // Touch swipe
    list.addEventListener('touchstart', function (e) {
      if (!e.touches || !e.touches.length) return;
      state.dragging = true;
      state.startX = e.touches[0].clientX;
      state.dx = 0;
      track.style.transition = 'none';
    }, { passive: true });

    list.addEventListener('touchmove', function (e) {
      if (!state.dragging || !e.touches || !e.touches.length) return;
      state.dx = e.touches[0].clientX - state.startX;
      track.style.transform = 'translate3d(' + (-state.index * state.w + state.dx) + 'px,0,0)';
    }, { passive: true });

    list.addEventListener('touchend', function () {
      if (!state.dragging) return;
      state.dragging = false;
      if (Math.abs(state.dx) > Math.max(40, state.w * 0.12)) {
        if (state.dx < 0) next();
        else prev();
      } else {
        go(state.index, true);
      }
    });

    // Init
    ensureDots();
    layout();

    var resizeTimer = 0;
    window.addEventListener('resize', function () {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(layout, 120);
    });
  }

  document.addEventListener('DOMContentLoaded', initMainPageSlider);
})();

