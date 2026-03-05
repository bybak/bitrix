
; /* Start:"a:4:{s:4:"full";s:64:"/bitrix/templates/eshop_bootstrap_v4/mf-header.js?17722275823295";s:6:"source";s:49:"/bitrix/templates/eshop_bootstrap_v4/mf-header.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
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


/* End */
;
; /* Start:"a:4:{s:4:"full";s:82:"/bitrix/components/bitrix/menu/templates/bootstrap_v4/script.min.js?17712652644044";s:6:"source";s:63:"/bitrix/components/bitrix/menu/templates/bootstrap_v4/script.js";s:3:"min";s:67:"/bitrix/components/bitrix/menu/templates/bootstrap_v4/script.min.js";s:3:"map";s:67:"/bitrix/components/bitrix/menu/templates/bootstrap_v4/script.map.js";}"*/
(function(e){if(!e.BX||BX.CatalogMenu)return;BX.CatalogMenu={items:{},idCnt:1,currentItem:null,overItem:null,outItem:null,timeoutOver:null,timeoutOut:null,getItem:function(e){if(!BX.type.isDomNode(e))return null;var o=!e.id||!BX.type.isNotEmptyString(e.id)?e.id="menu-item-"+this.idCnt++:e.id;if(!this.items[o])this.items[o]=new t(e);return this.items[o]},itemOver:function(e){var t=this.getItem(e);if(!t)return;if(this.outItem==t){clearTimeout(t.timeoutOut)}this.overItem=t;if(t.timeoutOver){clearTimeout(t.timeoutOver)}t.timeoutOver=setTimeout(function(){if(BX.CatalogMenu.overItem==t){t.itemOver()}},100)},itemOut:function(e){var t=this.getItem(e);if(!t)return;this.outItem=t;if(t.timeoutOut){clearTimeout(t.timeoutOut)}t.timeoutOut=setTimeout(function(){if(t!=BX.CatalogMenu.overItem){t.itemOut()}if(t==BX.CatalogMenu.outItem){t.itemOut()}},100)},removeHover:function(e){if(typeof e!=="object")return false;var t=e.parentNode.querySelectorAll('[data-role="bx-menu-item"]');for(var o=0;o<t.length;o++){if(e==t[o])continue;if(BX.hasClass(t[o],"bx-hover"))BX.removeClass(t[o],"bx-hover")}}};var t=function(e){this.element=e;this.popup=BX.findChild(e,{className:"bx_children_container"},false,false);this.isLastItem=BX.lastChild(this.element.parentNode)==this.element};t.prototype.itemOver=function(){if(!BX.hasClass(this.element,"bx-hover")){BX.addClass(this.element,"bx-hover");var e=BX.findChild(this.element,{className:"bx-nav-2-lvl-container"},true,false);if(e){var t=e.getBoundingClientRect().left+e.offsetWidth;if(t>document.body.clientWidth)e.style.right=0}}};t.prototype.itemOut=function(){BX.removeClass(this.element,"bx-hover")}})(window);BX.namespace("BX.Main.MenuComponent");BX.Main.MenuComponent.CatalogHorizontal=function(){var e=function(e,t){this.menuBlockId=e||"";this.itemImgDesc=t||"";this.resizeMenu();BX.bind(window,"resize",BX.proxy(this.resizeMenu,this))};e.prototype.clickInMobile=function(e,t){if(!BX.hasClass(e,"bx-hover")){t.preventDefault()}};e.prototype.toggleInMobile=function(e){var t=BX.findParent(e,{className:"bx-nav-parent"});var o=e.firstChild;if(BX.hasClass(t,"bx-opened")){BX.removeClass(t,"bx-opened");BX.removeClass(o,"bx-nav-angle-top");BX.addClass(o,"bx-nav-angle-bottom")}else{BX.addClass(t,"bx-opened");BX.addClass(o,"bx-nav-angle-top");BX.removeClass(o,"bx-nav-angle-bottom")}};e.prototype.resizeMenu=function(){var e=this.templateWrap;var t=document.body.querySelector("[data-role='bx-menu-mobile']");var o=document.body.querySelector("[data-role='bx-menu-button-mobile']");var i=document.body.querySelector("[data-role='bx-menu-button-mobile-position']");if(document.body.clientWidth<=767){if(!t){t=BX.create("div",{attrs:{className:"bx-aside-nav","data-role":"bx-menu-mobile"},children:[BX.clone(BX("ul_"+this.menuBlockId))]});document.body.insertBefore(t,document.body.firstChild)}if(!o){o=BX.create("div",{attrs:{className:"bx-aside-nav-control bx-closed","data-role":"bx-menu-button-mobile"},children:[BX.create("i",{attrs:{className:"bx-nav-bars"}})],events:{click:function(){if(BX.hasClass(this,"bx-opened")){BX.removeClass(this,"bx-opened");BX.removeClass(t,"bx-opened");BX.addClass(this,"bx-closed");document.body.style.overflow="";BX.removeClass(document.body,"bx-opened")}else{BX.addClass(this,"bx-opened");BX.addClass(t,"bx-opened");BX.removeClass(this,"bx-closed");document.body.style.overflow="hidden";BX.addClass(document.body,"bx-opened")}}}});i.appendChild(o)}}else{BX.removeClass(t,"bx-opened");BX.removeClass(document.body,"bx-opened");document.body.style.overflow="";if(o)BX.removeClass(o,"bx-opened")}};e.prototype.changeSectionPicure=function(e,t){var o=BX.findParent(e,{className:"bx-nav-1-lvl"});if(!o)return;var i=o.querySelector("[data-role='desc-img-block']");if(!i)return;var n=BX.findChild(i,{tagName:"img"},true,false);if(n)n.src=this.itemImgDesc[t]["PICTURE"];var a=BX.findChild(i,{tagName:"a"},true,false);if(a)a.href=e.href;var s=BX.findChild(i,{tagName:"p"},true,false);if(s)s.innerHTML=this.itemImgDesc[t]["DESC"]};return e}();
/* End */
;; /* /bitrix/templates/eshop_bootstrap_v4/mf-header.js?17722275823295*/
; /* /bitrix/components/bitrix/menu/templates/bootstrap_v4/script.min.js?17712652644044*/

//# sourceMappingURL=template_b1d9a714d929b1db51837748db3e03ff.map.js