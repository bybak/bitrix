
; /* Start:"a:4:{s:4:"full";s:64:"/bitrix/templates/eshop_bootstrap_v4/mf-header.js?17713965362070";s:6:"source";s:49:"/bitrix/templates/eshop_bootstrap_v4/mf-header.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
// Motor-Force-like header interactions (no external deps)
(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

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
    qsa('[data-mf="tel"]').forEach(function (a) {
      var t = (a.textContent || '').replace(/[^\d+]/g, '');
      if (t && !a.getAttribute('href')) a.setAttribute('href', 'tel:' + t);
    });
  });
})();


/* End */
;
; /* Start:"a:4:{s:4:"full";s:101:"/bitrix/components/bitrix/sale.basket.basket.line/templates/bootstrap_v4/script.min.js?17712652643841";s:6:"source";s:82:"/bitrix/components/bitrix/sale.basket.basket.line/templates/bootstrap_v4/script.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
"use strict";function BitrixSmallCart(){}BitrixSmallCart.prototype={activate:function(){this.cartElement=BX(this.cartId);this.fixedPosition=this.arParams.POSITION_FIXED=="Y";if(this.fixedPosition){this.cartClosed=true;this.maxHeight=false;this.itemRemoved=false;this.verticalPosition=this.arParams.POSITION_VERTICAL;this.horizontalPosition=this.arParams.POSITION_HORIZONTAL;this.topPanelElement=BX("bx-panel");this.fixAfterRender();this.fixAfterRenderClosure=this.closure("fixAfterRender");var t=this.closure("fixCart");this.fixCartClosure=t;if(this.topPanelElement&&this.verticalPosition=="top")BX.addCustomEvent(window,"onTopPanelCollapse",t);var e=null;BX.bind(window,"resize",function(){clearTimeout(e);e=setTimeout(t,200)})}this.setCartBodyClosure=this.closure("setCartBody");BX.addCustomEvent(window,"OnBasketChange",this.closure("refreshCart",{}))},fixAfterRender:function(){this.statusElement=BX(this.cartId+"status");if(this.statusElement){if(this.cartClosed)this.statusElement.innerHTML=this.openMessage;else this.statusElement.innerHTML=this.closeMessage}this.productsElement=BX(this.cartId+"products");this.fixCart()},closure:function(t,e){var i=this;return e?function(){i[t](e)}:function(e){i[t](e)}},toggleOpenCloseCart:function(){if(this.cartClosed){BX.removeClass(this.cartElement,"bx-closed");BX.addClass(this.cartElement,"bx-opener");this.statusElement.innerHTML=this.closeMessage;this.cartClosed=false;this.fixCart()}else{BX.addClass(this.cartElement,"bx-closed");BX.removeClass(this.cartElement,"bx-opener");BX.removeClass(this.cartElement,"bx-max-height");this.statusElement.innerHTML=this.openMessage;this.cartClosed=true;var t=this.cartElement.querySelector("[data-role='basket-item-list']");if(t)t.style.top="auto"}setTimeout(this.fixCartClosure,100)},setVerticalCenter:function(t){var e=t/2-this.cartElement.offsetHeight/2;if(e<5)e=5;this.cartElement.style.top=e+"px"},fixCart:function(){if(this.horizontalPosition=="hcenter"){var t="innerWidth"in window?window.innerWidth:document.documentElement.offsetWidth;var e=t/2-this.cartElement.offsetWidth/2;if(e<5)e=5;this.cartElement.style.left=e+"px"}var i="innerHeight"in window?window.innerHeight:document.documentElement.offsetHeight;switch(this.verticalPosition){case"top":if(this.topPanelElement)this.cartElement.style.top=this.topPanelElement.offsetHeight+5+"px";break;case"vcenter":this.setVerticalCenter(i);break}if(this.productsElement){var s=this.cartElement.querySelector("[data-role='basket-item-list']");if(this.cartClosed){if(this.maxHeight){BX.removeClass(this.cartElement,"bx-max-height");if(s)s.style.top="auto";this.maxHeight=false}}else{if(this.maxHeight){if(this.productsElement.scrollHeight==this.productsElement.clientHeight){BX.removeClass(this.cartElement,"bx-max-height");if(s)s.style.top="auto";this.maxHeight=false}}else{if(this.verticalPosition=="top"||this.verticalPosition=="vcenter"){if(this.cartElement.offsetTop+this.cartElement.offsetHeight>=i){BX.addClass(this.cartElement,"bx-max-height");if(s)s.style.top=82+"px";this.maxHeight=true}}else{if(this.cartElement.offsetHeight>=i){BX.addClass(this.cartElement,"bx-max-height");if(s)s.style.top=82+"px";this.maxHeight=true}}}}if(this.verticalPosition=="vcenter")this.setVerticalCenter(i)}},refreshCart:function(t){if(this.itemRemoved){this.itemRemoved=false;return}t.sessid=BX.bitrix_sessid();t.siteId=this.siteId;t.templateName=this.templateName;t.arParams=this.arParams;BX.ajax({url:this.ajaxPath,method:"POST",dataType:"html",data:t,onsuccess:this.setCartBodyClosure})},setCartBody:function(t){if(this.cartElement)this.cartElement.innerHTML=t.replace(/#CURRENT_URL#/g,this.currentUrl);if(this.fixedPosition)setTimeout(this.fixAfterRenderClosure,100)},removeItemFromCart:function(t){this.refreshCart({sbblRemoveItemFromCart:t});this.itemRemoved=true;BX.onCustomEvent("OnBasketChange")}};
/* End */
;
; /* Start:"a:4:{s:4:"full";s:82:"/bitrix/components/bitrix/menu/templates/bootstrap_v4/script.min.js?17712652644044";s:6:"source";s:63:"/bitrix/components/bitrix/menu/templates/bootstrap_v4/script.js";s:3:"min";s:67:"/bitrix/components/bitrix/menu/templates/bootstrap_v4/script.min.js";s:3:"map";s:67:"/bitrix/components/bitrix/menu/templates/bootstrap_v4/script.map.js";}"*/
(function(e){if(!e.BX||BX.CatalogMenu)return;BX.CatalogMenu={items:{},idCnt:1,currentItem:null,overItem:null,outItem:null,timeoutOver:null,timeoutOut:null,getItem:function(e){if(!BX.type.isDomNode(e))return null;var o=!e.id||!BX.type.isNotEmptyString(e.id)?e.id="menu-item-"+this.idCnt++:e.id;if(!this.items[o])this.items[o]=new t(e);return this.items[o]},itemOver:function(e){var t=this.getItem(e);if(!t)return;if(this.outItem==t){clearTimeout(t.timeoutOut)}this.overItem=t;if(t.timeoutOver){clearTimeout(t.timeoutOver)}t.timeoutOver=setTimeout(function(){if(BX.CatalogMenu.overItem==t){t.itemOver()}},100)},itemOut:function(e){var t=this.getItem(e);if(!t)return;this.outItem=t;if(t.timeoutOut){clearTimeout(t.timeoutOut)}t.timeoutOut=setTimeout(function(){if(t!=BX.CatalogMenu.overItem){t.itemOut()}if(t==BX.CatalogMenu.outItem){t.itemOut()}},100)},removeHover:function(e){if(typeof e!=="object")return false;var t=e.parentNode.querySelectorAll('[data-role="bx-menu-item"]');for(var o=0;o<t.length;o++){if(e==t[o])continue;if(BX.hasClass(t[o],"bx-hover"))BX.removeClass(t[o],"bx-hover")}}};var t=function(e){this.element=e;this.popup=BX.findChild(e,{className:"bx_children_container"},false,false);this.isLastItem=BX.lastChild(this.element.parentNode)==this.element};t.prototype.itemOver=function(){if(!BX.hasClass(this.element,"bx-hover")){BX.addClass(this.element,"bx-hover");var e=BX.findChild(this.element,{className:"bx-nav-2-lvl-container"},true,false);if(e){var t=e.getBoundingClientRect().left+e.offsetWidth;if(t>document.body.clientWidth)e.style.right=0}}};t.prototype.itemOut=function(){BX.removeClass(this.element,"bx-hover")}})(window);BX.namespace("BX.Main.MenuComponent");BX.Main.MenuComponent.CatalogHorizontal=function(){var e=function(e,t){this.menuBlockId=e||"";this.itemImgDesc=t||"";this.resizeMenu();BX.bind(window,"resize",BX.proxy(this.resizeMenu,this))};e.prototype.clickInMobile=function(e,t){if(!BX.hasClass(e,"bx-hover")){t.preventDefault()}};e.prototype.toggleInMobile=function(e){var t=BX.findParent(e,{className:"bx-nav-parent"});var o=e.firstChild;if(BX.hasClass(t,"bx-opened")){BX.removeClass(t,"bx-opened");BX.removeClass(o,"bx-nav-angle-top");BX.addClass(o,"bx-nav-angle-bottom")}else{BX.addClass(t,"bx-opened");BX.addClass(o,"bx-nav-angle-top");BX.removeClass(o,"bx-nav-angle-bottom")}};e.prototype.resizeMenu=function(){var e=this.templateWrap;var t=document.body.querySelector("[data-role='bx-menu-mobile']");var o=document.body.querySelector("[data-role='bx-menu-button-mobile']");var i=document.body.querySelector("[data-role='bx-menu-button-mobile-position']");if(document.body.clientWidth<=767){if(!t){t=BX.create("div",{attrs:{className:"bx-aside-nav","data-role":"bx-menu-mobile"},children:[BX.clone(BX("ul_"+this.menuBlockId))]});document.body.insertBefore(t,document.body.firstChild)}if(!o){o=BX.create("div",{attrs:{className:"bx-aside-nav-control bx-closed","data-role":"bx-menu-button-mobile"},children:[BX.create("i",{attrs:{className:"bx-nav-bars"}})],events:{click:function(){if(BX.hasClass(this,"bx-opened")){BX.removeClass(this,"bx-opened");BX.removeClass(t,"bx-opened");BX.addClass(this,"bx-closed");document.body.style.overflow="";BX.removeClass(document.body,"bx-opened")}else{BX.addClass(this,"bx-opened");BX.addClass(t,"bx-opened");BX.removeClass(this,"bx-closed");document.body.style.overflow="hidden";BX.addClass(document.body,"bx-opened")}}}});i.appendChild(o)}}else{BX.removeClass(t,"bx-opened");BX.removeClass(document.body,"bx-opened");document.body.style.overflow="";if(o)BX.removeClass(o,"bx-opened")}};e.prototype.changeSectionPicure=function(e,t){var o=BX.findParent(e,{className:"bx-nav-1-lvl"});if(!o)return;var i=o.querySelector("[data-role='desc-img-block']");if(!i)return;var n=BX.findChild(i,{tagName:"img"},true,false);if(n)n.src=this.itemImgDesc[t]["PICTURE"];var a=BX.findChild(i,{tagName:"a"},true,false);if(a)a.href=e.href;var s=BX.findChild(i,{tagName:"p"},true,false);if(s)s.innerHTML=this.itemImgDesc[t]["DESC"]};return e}();
/* End */
;; /* /bitrix/templates/eshop_bootstrap_v4/mf-header.js?17713965362070*/
; /* /bitrix/components/bitrix/sale.basket.basket.line/templates/bootstrap_v4/script.min.js?17712652643841*/
; /* /bitrix/components/bitrix/menu/templates/bootstrap_v4/script.min.js?17712652644044*/

//# sourceMappingURL=template_b8fc8998ed8d212baf398d118af0cd4f.map.js