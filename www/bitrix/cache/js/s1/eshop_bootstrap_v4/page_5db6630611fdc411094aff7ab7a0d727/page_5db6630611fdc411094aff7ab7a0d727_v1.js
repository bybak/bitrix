
; /* Start:"a:4:{s:4:"full";s:103:"/bitrix/components/bitrix/sale.location.selector.search/templates/.default/script.min.js?17712652647796";s:6:"source";s:84:"/bitrix/components/bitrix/sale.location.selector.search/templates/.default/script.js";s:3:"min";s:88:"/bitrix/components/bitrix/sale.location.selector.search/templates/.default/script.min.js";s:3:"map";s:88:"/bitrix/components/bitrix/sale.location.selector.search/templates/.default/script.map.js";}"*/
BX.namespace("BX.Sale.component.location.selector");if(typeof BX.Sale.component.location.selector.search=="undefined"&&typeof BX.ui!="undefined"&&typeof BX.ui.widget!="undefined"){BX.Sale.component.location.selector.search=function(e,t){this.parentConstruct(BX.Sale.component.location.selector.search,e);BX.merge(this,{opts:{usePagingOnScroll:true,pageSize:10,arrowScrollAdditional:2,pageUpWardOffset:3,provideLinkBy:"id",bindEvents:{"after-input-value-modify":function(){this.ctrls.fullRoute.value=""},"after-select-item":function(e){var t=this.opts;var i=this.vars.cache.nodes[e];var s=i.DISPLAY;if(typeof i.PATH=="object"){for(var o=0;o<i.PATH.length;o++){s+=", "+this.vars.cache.path[i.PATH[o]]}}this.ctrls.inputs.fake.setAttribute("title",s);this.ctrls.fullRoute.value=s;if(typeof this.opts.callback=="string"&&this.opts.callback.length>0&&this.opts.callback in window)window[this.opts.callback].apply(this,[e,this])},"after-deselect-item":function(){this.ctrls.fullRoute.value="";this.ctrls.inputs.fake.setAttribute("title","")},"before-render-variant":function(e){if(e.PATH.length>0){var t="";for(var i=0;i<e.PATH.length;i++)t+=", "+this.vars.cache.path[e.PATH[i]];e.PATH=t}else e.PATH="";var s="";if(this.vars&&this.vars.lastQuery&&this.vars.lastQuery.QUERY)s=this.vars.lastQuery.QUERY;if(BX.type.isNotEmptyString(s)){var o=[];if(this.opts.wrapSeparate)o=s.split(/\s+/);else o=[s];e["=display_wrapped"]=BX.util.wrapSubstring(e.DISPLAY+e.PATH,o,this.opts.wrapTagName,true)}else e["=display_wrapped"]=BX.util.htmlspecialchars(e.DISPLAY);e["=id"]=BX.util.htmlspecialchars(e.VALUE)}}},vars:{cache:{path:{},nodesByCode:{}}},sys:{code:"sls"}});this.handleInitStack(t,BX.Sale.component.location.selector.search,e)};BX.extend(BX.Sale.component.location.selector.search,BX.ui.autoComplete);BX.merge(BX.Sale.component.location.selector.search.prototype,{init:function(){if(typeof this.opts.pathNames=="object")BX.merge(this.vars.cache.path,this.opts.pathNames);this.pushFuncStack("buildUpDOM",BX.Sale.component.location.selector.search);this.pushFuncStack("bindEvents",BX.Sale.component.location.selector.search)},buildUpDOM:function(){var e=this.ctrls,t=this.opts,i=this.vars,s=this,o=this.sys.code;e.fullRoute=BX.create("input",{props:{className:"bx-ui-"+o+"-route"},attrs:{type:"text",disabled:"disabled",autocomplete:"off"}});BX.style(e.fullRoute,"paddingTop",BX.style(e.inputs.fake,"paddingTop"));BX.style(e.fullRoute,"paddingLeft",BX.style(e.inputs.fake,"paddingLeft"));BX.style(e.fullRoute,"paddingRight","0px");BX.style(e.fullRoute,"paddingBottom","0px");BX.style(e.fullRoute,"marginTop",BX.style(e.inputs.fake,"marginTop"));BX.style(e.fullRoute,"marginLeft",BX.style(e.inputs.fake,"marginLeft"));BX.style(e.fullRoute,"marginRight","0px");BX.style(e.fullRoute,"marginBottom","0px");if(BX.style(e.inputs.fake,"borderTopStyle")!="none"){BX.style(e.fullRoute,"borderTopStyle","solid");BX.style(e.fullRoute,"borderTopColor","transparent");BX.style(e.fullRoute,"borderTopWidth",BX.style(e.inputs.fake,"borderTopWidth"))}if(BX.style(e.inputs.fake,"borderLeftStyle")!="none"){BX.style(e.fullRoute,"borderLeftStyle","solid");BX.style(e.fullRoute,"borderLeftColor","transparent");BX.style(e.fullRoute,"borderLeftWidth",BX.style(e.inputs.fake,"borderLeftWidth"))}BX.prepend(e.fullRoute,e.container);e.inputBlock=this.getControl("input-block");e.loader=this.getControl("loader")},bindEvents:function(){var e=this;BX.bindDelegate(this.getControl("quick-locations",true),"click",{tag:"a"},(function(){e.setValueByLocationId(BX.data(this,"id"))}));this.vars.outSideClickScope=this.ctrls.inputBlock},setValueByLocationId:function(e,t){BX.Sale.component.location.selector.search.superclass.setValue.apply(this,[e,t])},setValueByLocationIds:function(e){if(e.IDS){this.displayPage({VALUE:e.IDS,order:{TYPE_ID:"ASC","NAME.NAME":"ASC"}})}},setValueByLocationCode:function(e,t){var i=this.vars,s=this.opts,o=this.ctrls,n=this;this.hideError();if(e==null||e==false||typeof e=="undefined"||e.toString().length==0){this.resetVariables();BX.cleanNode(o.vars);if(BX.type.isElementNode(o.nothingFound))BX.hide(o.nothingFound);this.fireEvent("after-deselect-item");this.fireEvent("after-clear-selection");return}if(t!==false)i.forceSelectSingeOnce=true;if(typeof i.cache.nodesByCode[e]=="undefined"){this.resetNavVariables();n.downloadBundle({CODE:e},(function(t){n.fillCache(t,false);if(typeof i.cache.nodesByCode[e]=="undefined"){n.showNothingFound()}else{var o=i.cache.nodesByCode[e].VALUE;if(s.autoSelectIfOneVariant||i.forceSelectSingeOnce)n.selectItem(o);else n.displayVariants([o])}}),(function(){i.forceSelectSingeOnce=false}))}else{var a=i.cache.nodesByCode[e].VALUE;if(i.forceSelectSingeOnce)this.selectItem(a);else this.displayVariants([a]);i.forceSelectSingeOnce=false}},getNodeByValue:function(e){if(this.opts.provideLinkBy=="id")return this.vars.cache.nodes[e];else return this.vars.cache.nodesByCode[e]},getNodeByLocationId:function(e){return this.vars.cache.nodes[e]},setValue:function(e){if(this.opts.provideLinkBy=="id")BX.Sale.component.location.selector.search.superclass.setValue.apply(this,[e]);else this.setValueByLocationCode(e)},getValue:function(){if(this.opts.provideLinkBy=="id")return this.vars.value===false?"":this.vars.value;else{return this.vars.value?this.vars.cache.nodes[this.vars.value].CODE:""}},getSelectedPath:function(){var e=this.vars,t=[];if(typeof e.value=="undefined"||e.value==false||e.value=="")return t;if(typeof e.cache.nodes[e.value]!="undefined"){var i=BX.clone(e.cache.nodes[e.value]);if(typeof i.TYPE_ID!="undefined"&&typeof this.opts.types!="undefined")i.TYPE=this.opts.types[i.TYPE_ID].CODE;var s=i.PATH;delete i.PATH;t.push(i);if(typeof s!="undefined"){for(var o in s){var i=BX.clone(e.cache.nodes[s[o]]);if(typeof i.TYPE_ID!="undefined"&&typeof this.opts.types!="undefined")i.TYPE=this.opts.types[i.TYPE_ID].CODE;delete i.PATH;t.push(i)}}}return t},setInitialValue:function(){if(this.opts.selectedItem!==false)this.setValueByLocationId(this.opts.selectedItem);else if(this.ctrls.inputs.origin.value.length>0){if(this.opts.provideLinkBy=="id")this.setValueByLocationId(this.ctrls.inputs.origin.value);else this.setValueByLocationCode(this.ctrls.inputs.origin.value)}},addItem2Cache:function(e){this.vars.cache.nodes[e.VALUE]=e;this.vars.cache.nodesByCode[e.CODE]=e},refineRequest:function(e){var t={};if(typeof e["QUERY"]!="undefined")t["=PHRASE"]=e.QUERY;if(typeof e["VALUE"]!="undefined")t["=ID"]=e.VALUE;if(typeof e["CODE"]!="undefined")t["=CODE"]=e.CODE;if(typeof this.opts.query.BEHAVIOUR.LANGUAGE_ID!="undefined")t["=NAME.LANGUAGE_ID"]=this.opts.query.BEHAVIOUR.LANGUAGE_ID;if(BX.type.isNotEmptyString(this.opts.query.FILTER.SITE_ID))t["=SITE_ID"]=this.opts.query.FILTER.SITE_ID;var i={select:{VALUE:"ID",DISPLAY:"NAME.NAME",1:"CODE",2:"TYPE_ID"},additionals:{1:"PATH"},filter:t,version:"2"};if(typeof e["order"]!="undefined")i["order"]=e.order;return i},refineResponce:function(e,t){if(typeof e.ETC.PATH_ITEMS!="undefined"){for(var i in e.ETC.PATH_ITEMS){if(BX.type.isNotEmptyString(e.ETC.PATH_ITEMS[i].DISPLAY))this.vars.cache.path[i]=e.ETC.PATH_ITEMS[i].DISPLAY}for(var i in e.ITEMS){var s=e.ITEMS[i];if(typeof s.PATH!="undefined"){var o=BX.clone(s.PATH);for(var n in s.PATH){var a=s.PATH[n];o.shift();if(typeof this.vars.cache.nodes[a]=="undefined"&&typeof e.ETC.PATH_ITEMS[a]!="undefined"){var l=BX.clone(e.ETC.PATH_ITEMS[a]);l.PATH=BX.clone(o);this.vars.cache.nodes[a]=l}}}}}return e.ITEMS},refineItems:function(e){return e},refineItemDataForTemplate:function(e){return e},getSelectorValue:function(e){if(this.opts.provideLinkBy=="id")return e;if(typeof this.vars.cache.nodes[e]!="undefined")return this.vars.cache.nodes[e].CODE;else return""},whenLoaderToggle:function(e){BX[e?"show":"hide"](this.ctrls.loader)}})}
/* End */
;
; /* Start:"a:4:{s:4:"full";s:90:"/bitrix/components/bitrix/sale.order.ajax/templates/bootstrap_v4/script.js?177378867636496";s:6:"source";s:74:"/bitrix/components/bitrix/sale.order.ajax/templates/bootstrap_v4/script.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
BX.saleOrderAjax = { // bad solution, actually, a singleton at the page

	BXCallAllowed: false,

	options: {},
	indexCache: {},
	controls: {},

	modes: {},
	properties: {},

	// called once, on component load
	init: function(options)
	{
		var ctx = this;
		this.options = options;

		window.submitFormProxy = BX.proxy(function(){
			ctx.submitFormProxy.apply(ctx, arguments);
		}, this);

		BX(function(){
			ctx.initDeferredControl();
		});
		BX(function(){
			ctx.BXCallAllowed = true; // unlock form refresher
		});

		this.controls.scope = BX('bx-soa-order');

		// Hardening: before any AJAX refresh, force-sync selected LOCATION values
		// from the UI control into the hidden ORDER_PROP_* inputs.
		// This prevents "fallback to default location (Moscow)" when the hidden input
		// is missing/disabled/lagging behind the UI selection.
		try {
			if (BX.Event && BX.Event.EventEmitter && typeof BX.Event.EventEmitter.subscribe === 'function')
			{
				BX.Event.EventEmitter.subscribe('BX.Sale.OrderAjaxComponent:onBeforeSendRequest', function() {
					if (!ctx || !ctx.controls || !ctx.controls.scope || !ctx.properties)
						return;

					// If location was manually touched, reset profile selection so server doesn't restore profile's location.
					try {
						if (ctx.__mfLocationTouched)
						{
							var form = BX('bx-soa-order-form');
							if (form)
							{
								var profile = form.querySelector('input[name="PROFILE_ID"], select[name="PROFILE_ID"]');
								if (profile)
								{
									profile.value = '0';
								}
								var profileChange = form.querySelector('input[name="profile_change"]');
								if (profileChange)
								{
									profileChange.value = 'Y';
								}
							}
						}
					} catch(e) {}

					for (var pid in ctx.properties)
					{
						if (!ctx.properties.hasOwnProperty(pid))
							continue;
						if (ctx.properties[pid].type !== 'LOCATION')
							continue;
						if (!ctx.properties[pid].control || typeof ctx.properties[pid].control.getValue !== 'function')
							continue;

						var v = ctx.properties[pid].control.getValue();
						if (!v)
							continue;

						// The hidden value input may be nested deep inside the location selector markup.
						var hidden = ctx.controls.scope.querySelector('input[type="hidden"][name="ORDER_PROP_' + pid + '"]');
						if (!hidden)
							continue;

						hidden.disabled = false;
						hidden.value = v;
					}
				});
			}
		} catch(e) {}

		// user presses "add location" when he cannot find location in popup mode
		BX.bindDelegate(this.controls.scope, 'click', {className: '-bx-popup-set-mode-add-loc'}, function(){

			var input = BX.create('input', {
				attrs: {
					type: 'hidden',
					name: 'PERMANENT_MODE_STEPS',
					value: '1'
				}
			});

			BX.prepend(input, BX('bx-soa-order'));

			ctx.BXCallAllowed = false;
			BX.Sale.OrderAjaxComponent.sendRequest();
		});
	},

	cleanUp: function(){

		for(var k in this.properties)
		{
			if (this.properties.hasOwnProperty(k))
			{
				if(typeof this.properties[k].input != 'undefined')
				{
					BX.unbindAll(this.properties[k].input);
					this.properties[k].input = null;
				}

				if(typeof this.properties[k].control != 'undefined')
					BX.unbindAll(this.properties[k].control);
			}
		}

		this.properties = {};
	},

	addPropertyDesc: function(desc){
		this.properties[desc.id] = desc.attributes;
		this.properties[desc.id].id = desc.id;
	},

	// called each time form refreshes
	initDeferredControl: function()
	{
		var ctx = this,
			k,
			row,
			input,
			locPropId,
			m,
			control,
			code,
			townInputFlag,
			altPropId,
			altRow,
			altInput,
			adapter;

		// Motor-Force customization (virtual eDost delivery list):
		// Show eDost tariffs dynamically (no delivery services in DB),
		// store selected tariff into hidden fields, and keep real Bitrix DELIVERY price = 0.
		var mfEdost = ctx.__mfEdost || (ctx.__mfEdost = {});
		if (typeof mfEdost.ensureFields !== 'function')
		{
			mfEdost._inFlight = false;
			mfEdost._lastKey = '';
			mfEdost.offers = [];
			mfEdost.selectedId = '';
			mfEdost.selected = null;
			mfEdost.isDeliveryActive = function(){
				try {
					var del = BX('bx-soa-delivery');
					return !!(del && del.classList && del.classList.contains('bx-selected'));
				} catch(e) { return false; }
			};
			mfEdost.getCurrentLocationCode = function(){
				try {
					for (var pid in ctx.properties)
					{
						if (!ctx.properties.hasOwnProperty(pid)) continue;
						if (ctx.properties[pid].type !== 'LOCATION') continue;
						if (!ctx.properties[pid].control || typeof ctx.properties[pid].control.getValue !== 'function') continue;
						return String(ctx.properties[pid].control.getValue() || '');
					}
				} catch(e) {}
				return '';
			};

			mfEdost.ensureFields = function(){
				var form = BX('bx-soa-order-form');
				if (!form) return;

				var ensureHidden = function(name){
					var el = form.querySelector('input[type="hidden"][name="' + name + '"]');
					if (!el)
					{
						el = BX.create('INPUT', {props: {type: 'hidden', name: name, value: ''}});
						form.appendChild(el);
					}
					return el;
				};

				var idEl = ensureHidden('MF_EDOST_TARIF_ID');
				var cEl = ensureHidden('MF_EDOST_TARIF_COMPANY');
				var nEl = ensureHidden('MF_EDOST_TARIF_NAME');
				var pEl = ensureHidden('MF_EDOST_TARIF_PRICE');

				// Restore selection after Bitrix re-renders the form.
				try {
					if (mfEdost.selectedId && (!idEl.value || String(idEl.value) === ''))
					{
						idEl.value = String(mfEdost.selectedId);
						cEl.value = mfEdost.selected && mfEdost.selected.company ? String(mfEdost.selected.company) : '';
						nEl.value = mfEdost.selected && mfEdost.selected.name ? String(mfEdost.selected.name) : '';
						pEl.value = mfEdost.selected && typeof mfEdost.selected.price !== 'undefined' ? String(mfEdost.selected.price) : '';
					}
				} catch(e) {}

				// Container in delivery section content (NOT before the title).
				var del = BX('bx-soa-delivery');
				if (!del || !del.querySelector) return;
				var content = del.querySelector('.bx-soa-section-content');
				if (!content) return;

				// Hard-hide Bitrix delivery cards (including "Стандартный") via CSS.
				// This is more reliable than walking the DOM after each refresh.
				try {
					if (!document.getElementById('mf-edost-style'))
					{
						var st = document.createElement('style');
						st.id = 'mf-edost-style';
						st.type = 'text/css';
						st.appendChild(document.createTextNode(
							'#bx-soa-delivery .bx-soa-pp-item-container,' +
							'#bx-soa-delivery .bx-soa-pp-company,' +
							'#bx-soa-delivery .bx-soa-pp-desc-container,' +
							'#bx-soa-delivery .bx-soa-pp-list,' +
							'#bx-soa-delivery .bx-soa-pp{display:none !important;}' +
							'#bx-soa-delivery:not(.bx-selected) #mf-edost-box{display:none !important;}' +
							'#bx-soa-delivery .bx-soa-more-btn button[disabled]{pointer-events:none;opacity:.55;}'
						));
						(document.head || document.documentElement).appendChild(st);
					}
				} catch(e) {}

				// Do not render the virtual tariffs UI into collapsed delivery block.
				// When delivery step is collapsed, Bitrix replaces its content with a summary container.
				if (!mfEdost.isDeliveryActive())
				{
					return;
				}

				var box = content.querySelector('#mf-edost-box');
				if (!box)
				{
					box = BX.create('DIV', {attrs: {id: 'mf-edost-box'}, style: {margin: '10px 0'}});
					var note = BX.create('DIV', {text: 'Расчёт доставки ориентировочный. Финальная стоимость будет подтверждена менеджером после уточнения деталей.', style: {fontSize: '12px', opacity: '0.75', marginBottom: '10px'}});
					var warn = BX.create('DIV', {attrs: {id: 'mf-edost-warning'}, style: {display: 'none', marginBottom: '10px'}});
					var list = BX.create('DIV', {attrs: {id: 'mf-edost-list'}});
					var sel = BX.create('DIV', {attrs: {id: 'mf-edost-selected'}, style: {marginTop: '10px', fontSize: '13px'}});
					box.appendChild(note);
					box.appendChild(warn);
					box.appendChild(list);
					box.appendChild(sel);
					content.insertBefore(box, content.firstChild);
				}
			};

			mfEdost.hideBitrixDeliveryCards = function(){
				// Kept for backward compatibility; CSS injection does the real work.
				mfEdost.ensureFields();
			};

			mfEdost.updateGate = function(locationCode){
				var del = BX('bx-soa-delivery');
				if (!del || !del.querySelector) return;

				var form = BX('bx-soa-order-form');
				var idEl = form ? form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]') : null;
				var hasTariff = idEl && BX.type.isNotEmptyString(idEl.value);
				var hasLoc = BX.type.isNotEmptyString(String(locationCode || ''));

				var nextBtn = del.querySelector('.bx-soa-more-btn button.pull-right.btn.btn-primary, .bx-soa-more-btn button.btn.btn-primary');
				if (nextBtn)
				{
					if (!hasLoc || !hasTariff)
					{
						nextBtn.setAttribute('disabled', 'disabled');
						BX.addClass(nextBtn, 'disabled');
					}
					else
					{
						nextBtn.removeAttribute('disabled');
						BX.removeClass(nextBtn, 'disabled');
					}
				}

				var warn = del.querySelector('#mf-edost-warning');
				if (warn)
				{
					if (!hasLoc)
					{
						warn.style.display = '';
						warn.className = 'alert alert-warning';
						warn.textContent = 'Выберите местоположение, чтобы увидеть варианты доставки.';
					}
					else if (!hasTariff)
					{
						warn.style.display = '';
						warn.className = 'alert alert-warning';
						warn.textContent = 'Выберите один из способов доставки, чтобы перейти дальше.';
					}
					else
					{
						warn.style.display = 'none';
						warn.textContent = '';
					}
				}

				// Do NOT force-open delivery block here. It was preventing the step from collapsing.
			};

			mfEdost.setSelected = function(offer){
				mfEdost.selected = offer || null;
				var form = BX('bx-soa-order-form');
				if (!form) return;
				var idEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]');
				var cEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_COMPANY"]');
				var nEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_NAME"]');
				var pEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_PRICE"]');
				if (!idEl || !cEl || !nEl || !pEl) return;

				idEl.value = (offer && offer.id) ? String(offer.id) : '';
				mfEdost.selectedId = idEl.value;
				cEl.value = (offer && offer.company) ? String(offer.company) : '';
				nEl.value = (offer && offer.name) ? String(offer.name) : '';
				pEl.value = (offer && typeof offer.price !== 'undefined') ? String(offer.price) : '';

				var root = BX('bx-soa-order') || document;
				var sel = root && root.querySelector ? root.querySelector('#mf-edost-selected') : null;
				if (sel)
				{
					if (idEl.value)
					{
						sel.textContent = 'Выбрано: ' + (cEl.value ? (cEl.value + ' — ') : '') + nEl.value + ' — ' + pEl.value + ' ₽';
					}
					else
					{
						sel.textContent = '';
					}
				}

				try {
					var locNow = '';
					for (var pidX in ctx.properties)
					{
						if (!ctx.properties.hasOwnProperty(pidX)) continue;
						if (ctx.properties[pidX].type !== 'LOCATION') continue;
						if (!ctx.properties[pidX].control || typeof ctx.properties[pidX].control.getValue !== 'function') continue;
						locNow = ctx.properties[pidX].control.getValue() || '';
						break;
					}
					mfEdost.updateGate(locNow);
				} catch(e) {}

				try { mfEdost.applyDeliverySummary(); } catch(e) {}
				try { mfEdost.applyTotalDeliveryLine(); } catch(e2) {}
			};

			mfEdost.clearSelection = function(){
				try {
					mfEdost.selected = null;
					mfEdost.selectedId = '';
					var form = BX('bx-soa-order-form');
					if (!form) return;
					var idEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]');
					var cEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_COMPANY"]');
					var nEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_NAME"]');
					var pEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_PRICE"]');
					if (idEl) idEl.value = '';
					if (cEl) cEl.value = '';
					if (nEl) nEl.value = '';
					if (pEl) pEl.value = '';
					var root = BX('bx-soa-order') || document;
					var sel = root && root.querySelector ? root.querySelector('#mf-edost-selected') : null;
					if (sel) sel.textContent = '';
				} catch(e) {}
				try { mfEdost.applyDeliverySummary(); } catch(e2) {}
				try { mfEdost.applyTotalDeliveryLine(); } catch(e3) {}
			};

			mfEdost.applyDeliverySummary = function(){
				var form = BX('bx-soa-order-form');
				if (!form) return;

				var idEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]');
				var cEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_COMPANY"]');
				var nEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_NAME"]');
				var pEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_PRICE"]');
				if (!idEl || !nEl || !pEl) return;

				var tid = String(idEl.value || '');
				var company = cEl ? String(cEl.value || '') : '';
				var name = String(nEl.value || '');
				var price = String(pEl.value || '');
				if (!tid || !name)
				{
					return;
				}

				var text = (company ? (company + ' — ') : '') + name;
				var priceText = price !== '' ? (price + ' ₽') : '';

				var blocks = [];
				try { BX('bx-soa-delivery') && blocks.push(BX('bx-soa-delivery')); } catch(e) {}
				try { BX('bx-soa-delivery-hidden') && blocks.push(BX('bx-soa-delivery-hidden')); } catch(e2) {}

				for (var i = 0; i < blocks.length; i++)
				{
					var del = blocks[i];
					if (!del || !del.querySelector) continue;

					var nameBox = del.querySelector('.bx-soa-pp-company-selected');
					if (nameBox)
					{
						var strong = nameBox.querySelector('strong');
						if (!strong)
						{
							strong = BX.create('STRONG', {text: ''});
							nameBox.appendChild(strong);
						}
						strong.textContent = text;
					}

					var priceBox = del.querySelector('.bx-soa-pp-price');
					if (priceBox)
					{
						priceBox.textContent = priceText;
					}
				}
			};

			mfEdost.applyTotalDeliveryLine = function(){
				try {
					var form = BX('bx-soa-order-form');
					if (!form) return;

					var tidEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]');
					var priceEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_PRICE"]');
					var tid = tidEl ? String(tidEl.value || '') : '';
					var price = priceEl ? String(priceEl.value || '') : '';

					var text = 'Не выбрано';
					if (tid)
					{
						text = price !== '' ? (price + ' ₽') : 'Не выбрано';
					}

					var roots = [];
					try { BX('bx-soa-total') && roots.push(BX('bx-soa-total')); } catch(e1) {}
					try { BX('bx-soa-total-mobile') && roots.push(BX('bx-soa-total-mobile')); } catch(e2) {}
					for (var r = 0; r < roots.length; r++)
					{
						var root = roots[r];
						if (!root || !root.querySelectorAll) continue;
						var lines = root.querySelectorAll('.bx-soa-cart-total-line');
						for (var i = 0; i < lines.length; i++)
						{
							var t = lines[i].querySelector('.bx-soa-cart-t');
							var d = lines[i].querySelector('.bx-soa-cart-d');
							if (!t || !d) continue;
							var label = (t.textContent || '').trim();
							if (label.indexOf('Доставка') === 0)
							{
								d.textContent = text;
							}
						}
					}
				} catch(e) {}
			};

			mfEdost.syncSelectedRadio = function(){
				try {
					var form = BX('bx-soa-order-form');
					if (!form) return;
					var idEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]');
					var tid = idEl ? String(idEl.value || '') : '';
					if (!tid) return;
					var root = BX('bx-soa-order') || document;
					if (!root || !root.querySelector) return;
					var r = root.querySelector('input[type="radio"][name="MF_EDOST_TARIF_UI"][value="' + tid + '"]');
					if (r) r.checked = true;
				} catch(e) {}
			};

			mfEdost.onEnterDelivery = function(){
				try {
					if (!mfEdost.isDeliveryActive())
						return;
					mfEdost.ensureFields();
					mfEdost.hideBitrixDeliveryCards();

					var loc = mfEdost.getCurrentLocationCode();
					// Re-render cached offers for current location (fetchOffers already does a cheap re-render
					// when key matches and offers are cached).
					if (BX.type.isNotEmptyString(loc))
					{
						mfEdost.fetchOffers(loc, '');
					}
					else
					{
						mfEdost.renderOffers([]);
					}

					mfEdost.syncSelectedRadio();
					mfEdost.updateGate(loc);
					mfEdost.applyDeliverySummary();
				} catch(e) {}
			};

			mfEdost.renderOffers = function(offers){
				var root = BX('bx-soa-order') || document;
				var list = root && root.querySelector ? root.querySelector('#mf-edost-list') : null;
				if (!list) return;

				BX.cleanNode(list);
				offers = offers && offers.length ? offers : [];

				if (!offers.length)
				{
					try {
						var locEmpty = '';
						for (var pidZ in ctx.properties)
						{
							if (!ctx.properties.hasOwnProperty(pidZ)) continue;
							if (ctx.properties[pidZ].type !== 'LOCATION') continue;
							if (!ctx.properties[pidZ].control || typeof ctx.properties[pidZ].control.getValue !== 'function') continue;
							locEmpty = ctx.properties[pidZ].control.getValue() || '';
							break;
						}
						mfEdost.updateGate(locEmpty);

						// If location is not chosen yet, don't show "no delivery options" message.
						// The warning above ("Выберите местоположение...") is enough.
						if (!BX.type.isNotEmptyString(String(locEmpty || '')))
						{
							return;
						}
					} catch(e) {}

					list.appendChild(BX.create('DIV', {text: 'Нет доступных способов доставки для выбранного адреса.', style: {opacity: '0.8'}}));
					return;
				}

				var form = BX('bx-soa-order-form');
				var selectedId = '';
				try {
					if (form)
					{
						var idEl = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]');
						selectedId = idEl ? String(idEl.value || '') : '';
					}
				} catch(e) {}
				if (!selectedId && mfEdost.selectedId)
				{
					selectedId = String(mfEdost.selectedId);
				}

				for (var i = 0; i < offers.length; i++)
				{
					(function(o){
						var id = String(o.id || '');
						var title = (o.company ? (o.company + ' — ') : '') + (o.name || ('тариф ' + id));
						var price = (typeof o.price !== 'undefined') ? String(o.price) : '';
						var days = '';
						try {
							var df = parseInt(o.days_from || 0, 10) || 0;
							var dt = parseInt(o.days_to || 0, 10) || 0;
							if (df > 0 || dt > 0)
								days = ' (' + df + '–' + (dt || df) + ' дн.)';
						} catch(e2) {}

						var row = BX.create('DIV', {style: {padding: '8px 10px', border: '1px solid #e5e5e5', borderRadius: '6px', marginBottom: '8px', cursor: 'pointer'}});
						var radio = BX.create('INPUT', {props: {type: 'radio', name: 'MF_EDOST_TARIF_UI', value: id}});
						if (selectedId !== '' && selectedId === id)
						{
							radio.checked = true;
						}
						var label = BX.create('SPAN', {text: ' ' + title + ' — ' + price + ' ₽' + days});
						row.appendChild(radio);
						row.appendChild(label);
						BX.bind(row, 'click', function(){
							try { radio.checked = true; } catch(e3) {}
							mfEdost.setSelected(o);
						});
						list.appendChild(row);
					})(offers[i]);
				}

				// If selection exists, ensure it is checked after re-render.
				try { mfEdost.syncSelectedRadio(); } catch(e) {}

				try {
					var locNow2 = '';
					for (var pidY in ctx.properties)
					{
						if (!ctx.properties.hasOwnProperty(pidY)) continue;
						if (ctx.properties[pidY].type !== 'LOCATION') continue;
						if (!ctx.properties[pidY].control || typeof ctx.properties[pidY].control.getValue !== 'function') continue;
						locNow2 = ctx.properties[pidY].control.getValue() || '';
						break;
					}
					mfEdost.updateGate(locNow2);
				} catch(e) {}
			};

			mfEdost.fetchOffers = function(locationCode, zipDigits){
				// Fetch/render offers only when Delivery step is open.
				if (!mfEdost.isDeliveryActive())
					return;

				locationCode = String(locationCode || '');
				zipDigits = String(zipDigits || '');
				if (!BX || !BX.ajax || !BX.type || !BX.type.isNotEmptyString(locationCode))
					return;

				var key = locationCode + '|' + zipDigits;
				// If form was re-rendered, we may need to re-render offers even without refetch.
				if (!mfEdost._inFlight && mfEdost._lastKey === key && mfEdost.offers && mfEdost.offers.length)
				{
					mfEdost.ensureFields();
					mfEdost.renderOffers(mfEdost.offers);
					mfEdost.hideBitrixDeliveryCards();
					return;
				}
				if (mfEdost._inFlight)
					return;

				// New destination: require user to re-select eDost tariff.
				if (mfEdost._lastKey !== '' && mfEdost._lastKey !== key)
				{
					mfEdost.clearSelection();
				}

				mfEdost._inFlight = true;
				mfEdost._lastKey = key;

				BX.ajax({
					url: '/ajax/mf_edost_offers.php',
					method: 'POST',
					dataType: 'json',
					data: {
						sessid: BX.bitrix_sessid(),
						location_code: locationCode,
						zip: zipDigits
					},
					onsuccess: function(resp){
						mfEdost._inFlight = false;
						if (resp && resp.ok && resp.offers && resp.offers.length)
						{
							mfEdost.offers = resp.offers;
							mfEdost.renderOffers(resp.offers);
							mfEdost.hideBitrixDeliveryCards();
						}
						else
						{
							mfEdost.offers = [];
							mfEdost.renderOffers([]);
							mfEdost.hideBitrixDeliveryCards();
						}
					},
					onfailure: function(){
						mfEdost._inFlight = false;
						mfEdost.offers = [];
						mfEdost.renderOffers([]);
						mfEdost.hideBitrixDeliveryCards();
					}
				});
			};
		}

		// first, init all controls
		if(typeof window.BX.locationsDeferred != 'undefined'){

			this.BXCallAllowed = false;

			for(k in window.BX.locationsDeferred){

				window.BX.locationsDeferred[k].call(this);
				window.BX.locationsDeferred[k] = null;
				delete(window.BX.locationsDeferred[k]);

				this.properties[k].control = window.BX.locationSelectors[k];
				delete(window.BX.locationSelectors[k]);
			}
		}

		for(k in this.properties){

			// zip input handling
			if(this.properties[k].isZip){
				row = this.controls.scope.querySelector('[data-property-id-row="'+k+'"]');
				if(BX.type.isElementNode(row)){

					input = row.querySelector('input[type="text"]');
					if(BX.type.isElementNode(input)){
						this.properties[k].input = input;
						// Motor-Force customization:
						// ZIP is hidden and not required; we use only LOCATION for delivery.
						try { input.value = ''; } catch(e) {}
						try { input.disabled = true; } catch(e) {}
						try { BX.hide(row); } catch(e) {}

					}
				}
			}

			// location handling, town property, etc...
			if(this.properties[k].type == 'LOCATION')
			{

				if(typeof this.properties[k].control != 'undefined'){

					control = this.properties[k].control; // reference to sale.location.selector.*
					code = control.getSysCode();

					// Motor-Force hardening:
					// Some setups stop calling global submitFormProxy on location selection.
					// Ensure we still refresh order when a real location is selected.
					try {
						if (!this.properties[k]._mfBoundRefresh && typeof control.bindEvent === 'function')
						{
							this.properties[k]._mfBoundRefresh = true;
							control.bindEvent('after-select-real-value', function(){
								try {
									if (!ctx.BXCallAllowed) return;
									ctx.BXCallAllowed = false;
									setTimeout(function(){
										// Refresh virtual eDost offers for this destination.
										var locVal = '';
										try { locVal = control.getValue ? control.getValue() : ''; } catch(e2) {}
										mfEdost.ensureFields();
										mfEdost.fetchOffers(locVal, '');
										mfEdost.updateGate(locVal);
									}, 50);
								} catch(e) {}
							});
						}
					} catch(e) {}

					// we have town property (alternative location)
					if(typeof this.properties[k].altLocationPropId != 'undefined')
					{
						// Motor-Force customization: мы не используем ручное поле "Город" (alt location).
						// Оно часто приводит к тому, что выбор местоположения "откатывается" на дефолт.
						altPropId = this.properties[k].altLocationPropId;
						altRow = this.getRowByPropId(altPropId);
						altInput = this.getInputByPropId(altPropId);
						if (altInput !== false)
						{
							try { altInput.value = ''; } catch(e) {}
							altInput.disabled = true;
						}
						if (BX.type.isElementNode(altRow))
						{
							BX.hide(altRow);
						}
						this.properties[k]._mfAltDisabled = true;

						// Всегда держим режим "ручного города" выключенным.
						townInputFlag = BX('LOCATION_ALT_PROP_DISPLAY_MANUAL['+parseInt(k)+']');
						if (BX.type.isDomNode(townInputFlag))
						{
							townInputFlag.value = '0';
						}

						if(code == 'sls') // for sale.location.selector.search
						{
							// replace default boring "nothing found" label for popup with "-bx-popup-set-mode-add-loc" inside
							control.replaceTemplate('nothing-found', this.options.messages.notFoundPrompt);
						}

						if(code == 'slst')  // for sale.location.selector.steps
						{
							// Если "Город" отключён, не добавляем псевдо-опцию "другое местоположение".
							if (this.properties[k] && this.properties[k]._mfAltDisabled)
							{
								continue;
							}
							(function(k, control){

								// control can have "select other location" option
								control.setOption('pseudoValues', ['other']);

								// insert "other location" option to popup
								control.bindEvent('control-before-display-page', function(adapter){

									control = null;

									var parentValue = adapter.getParentValue();

									// you can choose "other" location only if parentNode is not root and is selectable
									if(parentValue == this.getOption('rootNodeValue') || !this.checkCanSelectItem(parentValue))
										return;

									var controlInApater = adapter.getControl();

									if(typeof controlInApater.vars.cache.nodes['other'] == 'undefined')
									{
										controlInApater.fillCache([{
											CODE:		'other', 
											DISPLAY:	ctx.options.messages.otherLocation, 
											IS_PARENT:	false,
											VALUE:		'other'
										}], {
											modifyOrigin:			true,
											modifyOriginPosition:	'prepend'
										});
									}
								});

								townInputFlag = BX('LOCATION_ALT_PROP_DISPLAY_MANUAL['+parseInt(k)+']');

								control.bindEvent('after-select-real-value', function(){

									// some location chosen
									if(BX.type.isDomNode(townInputFlag))
										townInputFlag.value = '0';
								});
								control.bindEvent('after-select-pseudo-value', function(){

									// option "other location" chosen
									if(BX.type.isDomNode(townInputFlag))
										townInputFlag.value = '1';
								});

								// when user click at default location or call .setValueByLocation*()
								control.bindEvent('before-set-value', function(){
									if(BX.type.isDomNode(townInputFlag))
										townInputFlag.value = '0';
								});

								// restore "other location" label on the last control
								if(BX.type.isDomNode(townInputFlag) && townInputFlag.value == '1'){

									// a little hack: set "other location" text display
									adapter = control.getAdapterAtPosition(control.getStackSize() - 1);

									if(typeof adapter != 'undefined' && adapter !== null)
										adapter.setValuePair('other', ctx.options.messages.otherLocation);
								}

							})(k, control);
						}
					}
				}
			}
		}

		this.BXCallAllowed = true;

		// Motor-Force customization:
		// Virtual eDost list: always hide Bitrix delivery cards and render eDost offers.
		try {
			mfEdost.ensureFields();
			mfEdost.hideBitrixDeliveryCards();
			mfEdost.applyDeliverySummary();
			mfEdost.applyTotalDeliveryLine();
		} catch(e) {}

		try {
			// Force empty location on first load: user must choose destination manually.
			if (!this.__mfEdostLocClearedOnce)
			{
				var locPropId0 = null;
				var locCtrl0 = null;
				for (var pidA in this.properties)
				{
					if (!this.properties.hasOwnProperty(pidA)) continue;
					if (this.properties[pidA].type !== 'LOCATION') continue;
					locPropId0 = pidA;
					locCtrl0 = this.properties[pidA].control || null;
					break;
				}
				if (locCtrl0 && typeof locCtrl0.getValue === 'function')
				{
					var v0 = '';
					try { v0 = locCtrl0.getValue() || ''; } catch(e0) {}
					if (BX.type.isNotEmptyString(String(v0)) && !this.__mfLocationTouched)
					{
						this.__mfEdostLocClearedOnce = true;
						try {
							if (typeof locCtrl0.clearSelected === 'function')
								locCtrl0.clearSelected();
						} catch(e1) {}
						try {
							// Clear hidden ORDER_PROP_<pid>
							var hidden0 = this.controls.scope.querySelector('input[type="hidden"][name="ORDER_PROP_' + locPropId0 + '"]');
							if (hidden0) { hidden0.disabled = false; hidden0.value = ''; }
						} catch(e2) {}

						// Also clear ZIP default on first load (until user chooses location).
					}
				}
			}

			// Pull offers on each refresh using current LOCATION/ZIP values.
			var locInit = '';
			for (var pid0 in this.properties)
			{
				if (!this.properties.hasOwnProperty(pid0)) continue;
				if (this.properties[pid0].type === 'LOCATION' && this.properties[pid0].control && typeof this.properties[pid0].control.getValue === 'function')
				{
					try { locInit = this.properties[pid0].control.getValue() || ''; } catch(e0) {}
				}
			}
			// Only fetch/render offers when Delivery step is open (bx-selected).
			if (mfEdost.isDeliveryActive && mfEdost.isDeliveryActive())
			{
				mfEdost.fetchOffers(locInit, '');
			}
			if (!BX.type.isNotEmptyString(String(locInit || '')))
			{
				mfEdost.clearSelection();
			}
			mfEdost.updateGate(locInit);
			mfEdost.applyDeliverySummary();
			mfEdost.applyTotalDeliveryLine();
			// If Delivery step is currently open, ensure offers are shown and selection is restored.
			try { mfEdost.onEnterDelivery(); } catch(e) {}
		} catch(e) {}

		//set location initialized flag and refresh region & property actual content
		if (BX.Sale.OrderAjaxComponent)
			BX.Sale.OrderAjaxComponent.locationsCompletion();
	},

	checkMode: function(propId, mode){

		//if(typeof this.modes[propId] == 'undefined')
		//	this.modes[propId] = {};

		//if(typeof this.modes[propId] != 'undefined' && this.modes[propId][mode])
		//	return true;

		if(mode == 'altLocationChoosen'){

			if(this.checkAbility(propId, 'canHaveAltLocation')){

				var input = this.getInputByPropId(this.properties[propId].altLocationPropId);
				var altPropId = this.properties[propId].altLocationPropId;

				if(input !== false && input.value.length > 0 && !input.disabled && this.properties[altPropId].valueSource != 'default'){

					//this.modes[propId][mode] = true;
					return true;
				}
			}
		}

		return false;
	},

	checkAbility: function(propId, ability){

		if(typeof this.properties[propId] == 'undefined')
			this.properties[propId] = {};

		if(typeof this.properties[propId].abilities == 'undefined')
			this.properties[propId].abilities = {};

		if(typeof this.properties[propId].abilities != 'undefined' && this.properties[propId].abilities[ability])
			return true;

		if(ability == 'canHaveAltLocation'){

			if(this.properties[propId].type == 'LOCATION'){

				// try to find corresponding alternate location prop
				if(typeof this.properties[propId].altLocationPropId != 'undefined' && typeof this.properties[this.properties[propId].altLocationPropId]){

					var altLocPropId = this.properties[propId].altLocationPropId;

					if(typeof this.properties[propId].control != 'undefined' && this.properties[propId].control.getSysCode() == 'slst'){

						if(this.getInputByPropId(altLocPropId) !== false){
							this.properties[propId].abilities[ability] = true;
							return true;
						}
					}
				}
			}

		}

		return false;
	},

	getInputByPropId: function(propId){
		if(typeof this.properties[propId].input != 'undefined')
			return this.properties[propId].input;

		var row = this.getRowByPropId(propId);
		if(BX.type.isElementNode(row)){
			var input = row.querySelector('input[type="text"]');
			if(BX.type.isElementNode(input)){
				this.properties[propId].input = input;
				return input;
			}
		}

		return false;
	},

	getRowByPropId: function(propId){

		if(typeof this.properties[propId].row != 'undefined')
			return this.properties[propId].row;

		var row = this.controls.scope.querySelector('[data-property-id-row="'+propId+'"]');
		if(BX.type.isElementNode(row)){
			this.properties[propId].row = row;
			return row;
		}

		return false;
	},

	getAltLocPropByRealLocProp: function(propId){
		if(typeof this.properties[propId].altLocationPropId != 'undefined')
			return this.properties[this.properties[propId].altLocationPropId];

		return false;
	},

	toggleProperty: function(propId, way, dontModifyRow){

		var prop = this.properties[propId];

		if(typeof prop.row == 'undefined')
			prop.row = this.getRowByPropId(propId);

		if(typeof prop.input == 'undefined')
			prop.input = this.getInputByPropId(propId);

		if(!way){
			if(!dontModifyRow)
				BX.hide(prop.row);
			prop.input.disabled = true;
		}else{
			if(!dontModifyRow)
				BX.show(prop.row);
			prop.input.disabled = false;
		}
	},

	submitFormProxy: function(item, control)
	{
		var propId = false;
		for(var k in this.properties){
			if(typeof this.properties[k].control != 'undefined' && this.properties[k].control == control){
				propId = k;
				break;
			}
		}

		// turning LOCATION_ALT_PROP_DISPLAY_MANUAL on\off

		if(item != 'other'){

			if(this.BXCallAllowed){

				this.BXCallAllowed = false;
				// If user manually changed LOCATION, don't let Bitrix "order profile" overwrite it.
				// We'll clear PROFILE_ID before the next refresh.
				try {
					if (propId !== false && this.properties[propId] && this.properties[propId].type === 'LOCATION')
					{
						this.__mfLocationTouched = true;
					}
				} catch(e) {}
				// Ensure the real hidden value is synced before AJAX refresh.
				// In some setups the control UI changes faster than the hidden ORDER_PROP_* gets updated,
				// causing server to fall back to default location (e.g. Moscow).
				try {
					if (propId !== false && control && typeof control.getValue === 'function')
					{
						var row = this.getRowByPropId(propId);
						var hidden = row ? row.querySelector('input[type=hidden][name="ORDER_PROP_' + propId + '"]') : null;
						if (hidden)
						{
							hidden.value = control.getValue();
						}
					}
				} catch(e) {}

				setTimeout(function(){BX.Sale.OrderAjaxComponent.sendRequest()}, 80);
			}

		}
	},

	getPreviousAdapterSelectedNode: function(control, adapter){

		var index = adapter.getIndex();
		var prevAdapter = control.getAdapterAtPosition(index - 1);

		if(typeof prevAdapter !== 'undefined' && prevAdapter != null){
			var prevValue = prevAdapter.getControl().getValue();

			if(typeof prevValue != 'undefined'){
				var node = control.getNodeByValue(prevValue);

				if(typeof node != 'undefined')
					return node;

				return false;
			}
		}

		return false;
	},
	getLocationsByZip: function(value, successCallback, notFoundCallback)
	{
		if(typeof this.indexCache[value] != 'undefined')
		{
			successCallback.apply(this, [this.indexCache[value]]);
			return;
		}

		var ctx = this;

		BX.ajax({
			url: this.options.source,
			method: 'post',
			dataType: 'json',
			async: true,
			processData: true,
			emulateOnload: true,
			start: true,
			data: {'ACT': 'GET_LOCS_BY_ZIP', 'ZIP': value},
			//cache: true,
			onsuccess: function(result){
				if(result.result)
				{
					ctx.indexCache[value] = result.data;
					successCallback.apply(ctx, [result.data]);
				}
				else
				{
					notFoundCallback.call(ctx);
				}
			},
			onfailure: function(type, e){
				// on error do nothing
			}
		});
	}
};
/* End */
;
; /* Start:"a:4:{s:4:"full";s:95:"/bitrix/components/bitrix/sale.order.ajax/templates/bootstrap_v4/order_ajax.js?1773788614242586";s:6:"source";s:78:"/bitrix/components/bitrix/sale.order.ajax/templates/bootstrap_v4/order_ajax.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
BX.namespace('BX.Sale.OrderAjaxComponent');

(function() {
	'use strict';

	/**
	 * Show empty default property value to multiple properties without default values
	 */
	if (BX.Sale && BX.Sale.Input && BX.Sale.Input.Utils)
	{
		BX.Sale.Input.Utils.asMultiple = function (value)
		{
			if (value === undefined || value === null || value === '')
			{
				return [];
			}
			else if (value.constructor === Array)
			{
				var i = 0, length = value.length, val;

				for (; i < length;)
				{
					val = value[i];

					if (val === undefined || val === null || val === '')
					{
						value.splice(i, 1);
						--length;
					}
					else
					{
						++i;
					}
				}

				return value.length ? value : [''];
			}
			else
			{
				return [value];
			}
		};
	}

	BX.Sale.OrderAjaxComponent = {

		initializePrimaryFields: function()
		{
			this.BXFormPosting = false;
			this.regionBlockNotEmpty = false;
			this.locationsInitialized = false;
			this.locations = {};
			this.cleanLocations = {};
			this.locationsTemplate = '';
			this.pickUpMapFocused = false;
			this.options = {};
			this.activeSectionId = '';
			this.firstLoad = true;
			this.initialized = {};
			this.mapsReady = false;
			this.lastSelectedDelivery = 0;
			this.deliveryLocationInfo = {};
			this.deliveryPagination = {};
			this.deliveryCachedInfo = [];
			this.paySystemPagination = {};
			this.validation = {};
			this.hasErrorSection = {};
			this.pickUpPagination = {};
			this.timeOut = {};
			this.isMobile = BX.browser.IsMobile();
			this.isHttps = window.location.protocol === "https:";
			this.orderSaveAllowed = false;
			this.socServiceHiddenNode = false;
		},

		/**
		 * Initialization of sale.order.ajax component js
		 */
		init: function(parameters)
		{
			this.initializePrimaryFields();

			this.result = parameters.result || {};
			this.prepareLocations(parameters.locations);
			this.params = parameters.params || {};
			this.signedParamsString = parameters.signedParamsString || '';
			this.siteId = parameters.siteID || '';
			this.ajaxUrl = parameters.ajaxUrl || '';
			this.templateFolder = parameters.templateFolder || '';
			this.defaultBasketItemLogo = this.templateFolder + "/images/product_logo.png";
			this.defaultStoreLogo = this.templateFolder + "/images/pickup_logo.png";
			this.defaultDeliveryLogo = this.templateFolder + "/images/delivery_logo.png";
			this.defaultPaySystemLogo = this.templateFolder + "/images/pay_system_logo.png";

			this.orderBlockNode = BX(parameters.orderBlockId);
			this.totalBlockNode = BX(parameters.totalBlockId);
			this.mobileTotalBlockNode = BX(parameters.totalBlockId + '-mobile');
			this.savedFilesBlockNode = BX('bx-soa-saved-files');
			this.orderSaveBlockNode = BX('bx-soa-orderSave');
			this.mainErrorsNode = BX('bx-soa-main-notifications');

			this.authBlockNode = BX(parameters.authBlockId);
			this.authHiddenBlockNode = BX(parameters.authBlockId + '-hidden');
			this.basketBlockNode = BX(parameters.basketBlockId);
			this.basketHiddenBlockNode = BX(parameters.basketBlockId + '-hidden');
			this.regionBlockNode = BX(parameters.regionBlockId);
			this.regionHiddenBlockNode = BX(parameters.regionBlockId + '-hidden');
			this.paySystemBlockNode = BX(parameters.paySystemBlockId);
			this.paySystemHiddenBlockNode = BX(parameters.paySystemBlockId + '-hidden');
			this.deliveryBlockNode = BX(parameters.deliveryBlockId);
			this.deliveryHiddenBlockNode = BX(parameters.deliveryBlockId + '-hidden');
			this.pickUpBlockNode = BX(parameters.pickUpBlockId);
			this.pickUpHiddenBlockNode = BX(parameters.pickUpBlockId + '-hidden');
			this.propsBlockNode = BX(parameters.propsBlockId);
			this.propsHiddenBlockNode = BX(parameters.propsBlockId + '-hidden');

			if (this.result.SHOW_AUTH)
			{
				this.authBlockNode.style.display = '';
				BX.addClass(this.authBlockNode, 'bx-active');
				this.authGenerateUser = this.result.AUTH.new_user_registration_email_confirmation !== 'Y' && this.result.AUTH.new_user_phone_required !== 'Y';
			}

			if (this.totalBlockNode)
			{
				this.totalInfoBlockNode = this.totalBlockNode.querySelector('.bx-soa-cart-total');
				this.totalGhostBlockNode = this.totalBlockNode.querySelector('.bx-soa-cart-total-ghost');
			}

			this.options.deliveriesPerPage = parseInt(parameters.params.DELIVERIES_PER_PAGE);
			this.options.paySystemsPerPage = parseInt(parameters.params.PAY_SYSTEMS_PER_PAGE);
			this.options.pickUpsPerPage = parseInt(parameters.params.PICKUPS_PER_PAGE);

			this.options.showWarnings = !!parameters.showWarnings;
			this.options.propertyValidation = !!parameters.propertyValidation;
			this.options.priceDiffWithLastTime = false;

			this.options.pickUpMap = parameters.pickUpMap;
			this.options.propertyMap = parameters.propertyMap;

			this.options.totalPriceChanged = false;

			if (!this.result.IS_AUTHORIZED || typeof this.result.LAST_ORDER_DATA.FAIL !== 'undefined')
				this.initFirstSection();

			this.initOptions();
			this.editOrder();
			this.bindEvents();

			this.orderBlockNode.removeAttribute('style');
			this.basketBlockScrollCheck();

			if (this.params.USE_ENHANCED_ECOMMERCE === 'Y')
			{
				this.setAnalyticsDataLayer('checkout');
			}

			if (this.params.USER_CONSENT === 'Y')
			{
				this.initUserConsent();
			}
		},

		/**
		 * Send ajax request with order data and executes callback by action
		 */
		sendRequest: function(action, actionData)
		{
			var form;

			if (!this.startLoader())
				return;

			this.firstLoad = false;

			action = BX.type.isNotEmptyString(action) ? action : 'refreshOrderAjax';

			var eventArgs = {
				action: action,
				actionData: actionData,
				cancel: false
			};
			BX.Event.EventEmitter.emit('BX.Sale.OrderAjaxComponent:onBeforeSendRequest', eventArgs);
			if (eventArgs.cancel)
			{
				this.endLoader();
				return;
			}

			if (action === 'saveOrderAjax')
			{
				form = BX('bx-soa-order-form');
				if (form)
				{
					form.querySelector('input[type=hidden][name=sessid]').value = BX.bitrix_sessid();
				}

				BX.ajax.submitAjax(
					BX('bx-soa-order-form'),
					{
						url: this.ajaxUrl,
						method: 'POST',
						dataType: 'json',
						data: {
							via_ajax: 'Y',
							action: 'saveOrderAjax',
							sessid: BX.bitrix_sessid(),
							SITE_ID: this.siteId,
							signedParamsString: this.signedParamsString
						},
						onsuccess: BX.proxy(this.saveOrderWithJson, this),
						onfailure: BX.proxy(this.handleNotRedirected, this)
					}
				);
			}
			else
			{
				BX.ajax({
					method: 'POST',
					dataType: 'json',
					url: this.ajaxUrl,
					data: this.getData(eventArgs.action, eventArgs.actionData),
					onsuccess: BX.delegate(function(result) {
						if (result.redirect && result.redirect.length)
							document.location.href = result.redirect;

						this.saveFiles();
						switch (eventArgs.action)
						{
							case 'refreshOrderAjax':
								this.refreshOrder(result);
								break;
							case 'confirmSmsCode':
							case 'showAuthForm':
								this.firstLoad = true;
								this.refreshOrder(result);
								break;
							case 'enterCoupon':
								if (result && result.order)
								{
									this.deliveryCachedInfo = [];
									this.refreshOrder(result);
								}
								else
								{
									this.addCoupon(result);
								}

								break;
							case 'removeCoupon':
								if (result && result.order)
								{
									this.deliveryCachedInfo = [];
									this.refreshOrder(result);
								}
								else
								{
									this.removeCoupon(result);
								}

								break;
						}
						BX.cleanNode(this.savedFilesBlockNode);
						this.endLoader();
					}, this),
					onfailure: BX.delegate(function(){
						this.endLoader();
					}, this)
				});
			}
		},

		getData: function(action, actionData)
		{
			var data = {
				order: this.getAllFormData(),
				sessid: BX.bitrix_sessid(),
				via_ajax: 'Y',
				SITE_ID: this.siteId,
				signedParamsString: this.signedParamsString
			};

			data[this.params.ACTION_VARIABLE] = action;

			if (action === 'enterCoupon' || action === 'removeCoupon')
				data.coupon = actionData;

			return data;
		},

		getAllFormData: function()
		{
			var form = BX('bx-soa-order-form'),
				prepared = BX.ajax.prepareForm(form),
				i;

			for (i in prepared.data)
			{
				if (prepared.data.hasOwnProperty(i) && i == '')
				{
					delete prepared.data[i];
				}
			}

			return !!prepared && prepared.data ? prepared.data : {};
		},

		/**
		 * Refreshes order via json data from ajax request
		 */
		refreshOrder: function(result)
		{
			if (result.error)
			{
				this.showError(this.mainErrorsNode, result.error);
				this.animateScrollTo(this.mainErrorsNode, 800, 20);
			}
			else if (result.order.SHOW_AUTH)
			{
				var animation;

				if (
					(result.order.OK_MESSAGE && result.order.OK_MESSAGE.length)
					|| (result.order.SMS_AUTH && result.order.SMS_AUTH.TYPE === 'OK')
				)
				{
					animation = 'bx-step-good';
				}
				else
				{
					animation = 'bx-step-bad';
				}

				this.addAnimationEffect(this.authBlockNode, animation);
				BX.merge(this.result, result.order);
				this.editAuthBlock();
				this.showAuthBlock();
				this.showErrors(result.order.ERROR, false);
				this.animateScrollTo(this.authBlockNode);
			}
			else
			{
				this.isPriceChanged(result);

				if (this.activeSectionId !== this.deliveryBlockNode.id)
					this.deliveryCachedInfo = [];

				this.result = result.order;
				this.prepareLocations(result.locations);
				this.locationsInitialized = false;
				this.maxWaitTimeExpired = false;
				this.pickUpMapFocused = false;
				this.deliveryLocationInfo = {};

				this.initialized = {};
				this.clearHiddenBlocks();

				this.initOptions();
				this.editOrder();
				this.mapsReady && this.initMaps();
				BX.saleOrderAjax && BX.saleOrderAjax.initDeferredControl();
			}

			return true;
		},

		saveOrderWithJson: function(result)
		{
			var redirected = false;

			if (result && result.order)
			{
				result = result.order;

				if (result.REDIRECT_URL)
				{
					if (this.params.USE_ENHANCED_ECOMMERCE === 'Y')
					{
						this.setAnalyticsDataLayer('purchase', result.ID);
					}

					(window.b24order=window.b24order||[]).push({id: result.ID, sum: this.result.TOTAL.ORDER_PRICE});

					redirected = true;
					location.href = result.REDIRECT_URL;
				}
				else if (result.SHOW_AUTH)
				{
					this.result.SHOW_AUTH = result.SHOW_AUTH;
					this.result.AUTH = result.AUTH;
					this.result.SMS_AUTH = result.SMS_AUTH;

					this.editAuthBlock();
					this.showAuthBlock();
					this.animateScrollTo(this.authBlockNode);
				}
				else
				{
					this.showErrors(result.ERROR, true, true);
				}
			}

			if (!redirected)
			{
				this.handleNotRedirected();
			}
		},

		handleNotRedirected: function()
		{
			this.endLoader();
			this.disallowOrderSave();
		},

		/**
		 * Showing loader image with overlay.
		 */
		startLoader: function()
		{
			if (this.BXFormPosting === true)
				return false;

			this.BXFormPosting = true;

			if (!this.loadingScreen)
			{
				this.loadingScreen = new BX.PopupWindow('loading_screen', null, {
					overlay: {backgroundColor: 'white', opacity: 1},
					events: {
						onAfterPopupShow: BX.delegate(function(){
							BX.cleanNode(this.loadingScreen.popupContainer);
							BX.removeClass(this.loadingScreen.popupContainer, 'popup-window');
							this.loadingScreen.popupContainer.appendChild(
								BX.create('IMG', {props: {src: this.templateFolder + '/images/loader.gif'}})
							);
							this.loadingScreen.popupContainer.removeAttribute('style');
							this.loadingScreen.popupContainer.style.display = 'block';
						}, this)
					}
				});
				BX.addClass(this.loadingScreen.overlay.element, 'bx-step-opacity');
			}

			this.loadingScreen.overlay.element.style.opacity = '0';
			this.loadingScreen.show();
			this.loadingScreen.overlay.element.style.opacity = '0.6';

			return true;
		},

		/**
		 * Hiding loader image with overlay.
		 */
		endLoader: function()
		{
			this.BXFormPosting = false;

			if (this.loadingScreen && this.loadingScreen.isShown())
			{
				this.loadingScreen.close();
			}
		},

		htmlspecialcharsEx: function(str)
		{
			return str.replace(/&amp;/g, '&amp;amp;')
				.replace(/&lt;/g, '&amp;lt;').replace(/&gt;/g, '&amp;gt;')
				.replace(/&quot;/g, '&amp;quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
		},

		saveFiles: function()
		{
			if (this.result.ORDER_PROP && this.result.ORDER_PROP.properties)
			{
				var props = this.result.ORDER_PROP.properties, i, prop;
				for (i = 0; i < props.length; i++)
				{
					if (props[i].TYPE == 'FILE')
					{
						prop = this.orderBlockNode.querySelector('div[data-property-id-row="' + props[i].ID + '"]');
						if (prop)
							this.savedFilesBlockNode.appendChild(prop);
					}
				}
			}
		},

		/**
		 * Animating scroll to certain node
		 */
		animateScrollTo: function(node, duration, shiftToTop)
		{
			if (!node)
				return;

			var scrollTop = BX.GetWindowScrollPos().scrollTop,
				orderBlockPos = BX.pos(this.orderBlockNode),
				ghostTop = BX.pos(node).top - (this.isMobile ? 50 : 0);

			if (shiftToTop)
				ghostTop -= parseInt(shiftToTop);

			if (ghostTop + window.innerHeight > orderBlockPos.bottom)
				ghostTop = orderBlockPos.bottom - window.innerHeight + 17;

			new BX.easing({
				duration: duration || 800,
				start: {scroll: scrollTop},
				finish: {scroll: ghostTop},
				transition: BX.easing.makeEaseOut(BX.easing.transitions.quad),
				step: BX.delegate(function(state){
					window.scrollTo(0, state.scroll);
				}, this)
			}).animate();
		},

		checkKeyPress: function(event)
		{
			if (event.keyCode == 13)
			{
				var target = event.target || event.srcElement,
					send = target.getAttribute('data-send'),
					nextAttr, next;

				if (!send)
				{
					nextAttr = target.getAttribute('data-next');
					if (nextAttr)
					{
						next = this.orderBlockNode.querySelector('input[name=' + nextAttr + ']');
						next && next.focus();
					}

					return BX.PreventDefault(event);
				}
			}
		},

		getSizeString: function(maxSize, len)
		{
			var gbDivider = 1024 * 1024 * 1024,
				mbDivider = 1024 * 1024,
				kbDivider = 1024,
				str;

			maxSize = parseInt(maxSize);
			len = parseInt(len);

			if (maxSize > gbDivider)
				str = parseFloat(maxSize / gbDivider).toFixed(len) + ' Gb';
			else if (maxSize > mbDivider)
				str = parseFloat(maxSize / mbDivider).toFixed(len) + ' Mb';
			else if (maxSize > kbDivider)
				str = parseFloat(maxSize / kbDivider).toFixed(len) + ' Kb';
			else
				str = maxSize + ' B';

			return str;
		},

		getFileAccepts: function(accepts)
		{
			var arr = [],
				arAccepts = accepts.split(','),
				i, currentAccept;

			var mimeTypesMap = {
				json: 'application/json', javascript: 'application/javascript', 'octet-stream': 'application/octet-stream',
				ogg: 'application/ogg', pdf: 'application/pdf', zip: 'application/zip', gzip: 'application/gzip',
				aac: 'audio/aac', mp3: 'audio/mpeg', gif: 'image/gif', jpeg: 'image/jpeg', png: 'image/png', svg: 'image/svg+xml',
				tiff: 'image/tiff', css: 'text/css', csv: 'text/csv', html: 'text/html', plain: 'text/plain',
				php: 'text/php', xml: 'text/xml', mpeg: 'video/mpeg', mp4: 'video/mp4', quicktime: 'video/quicktime',
				flv: 'video/x-flv', doc: 'application/msword', docx: 'application/msword',
				xls: 'application/vnd.ms-excel', xlsx: 'application/vnd.ms-excel'
			};

			for (i = 0; i < arAccepts.length; i++)
			{
				currentAccept = BX.util.trim(arAccepts[i]);
				currentAccept = mimeTypesMap[currentAccept] || currentAccept;
				arr.push(currentAccept);
			}

			return arr.join(',');
		},

		uniqueText: function(text, separator)
		{
			var phrases, i, output = [];

			text = text || '';
			separator = separator || '<br>';

			phrases = text.split(separator);
			phrases = BX.util.array_unique(phrases);

			for (i = 0; i < phrases.length; i++)
			{
				if (phrases[i] == '')
					continue;

				output.push(BX.util.trim(phrases[i]));
			}

			return output.join(separator);
		},

		getImageSources: function(item, key)
		{
			if (!item || !key || !item[key])
				return false;

			return {
				src_1x: item[key + '_SRC'],
				src_2x: item[key + '_SRC_2X'],
				src_orig: item[key + '_SRC_ORIGINAL']
			};
		},

		getErrorContainer: function(node)
		{
			if (!node)
				return;

			node.appendChild(
				BX.create('DIV', {props: {className: 'alert alert-danger'}, style: {display: 'none'}})
			);
		},

		showError: function(node, msg, border)
		{
			if (BX.type.isArray(msg))
				msg = msg.join('<br>');

			var errorContainer = node.querySelector('.alert.alert-danger'), animate;
			if (errorContainer && msg.length)
			{
				BX.cleanNode(errorContainer);
				errorContainer.appendChild(BX.create('DIV', {html: msg}));

				animate = !this.hasErrorSection[node.id];
				if (animate)
				{
					errorContainer.style.opacity = 0;
					errorContainer.style.display = '';
					new BX.easing({
						duration: 300,
						start: {opacity: 0},
						finish: {opacity: 100},
						transition: BX.easing.makeEaseOut(BX.easing.transitions.quad),
						step: function(state){
							errorContainer.style.opacity = state.opacity / 100;
						},
						complete: function(){
							errorContainer.removeAttribute('style');
						}
					}).animate();
				}
				else
					errorContainer.style.display = '';

				if (!!border)
					BX.addClass(node, 'bx-step-error');
			}
		},

		showErrors: function(errors, scroll, showAll)
		{
			var errorNodes = this.orderBlockNode.querySelectorAll('div.alert.alert-danger'),
				section, k, blockErrors;

			for (k = 0; k < errorNodes.length; k++)
			{
				section = BX.findParent(errorNodes[k], {className: 'bx-soa-section'});
				BX.removeClass(section, 'bx-step-error');
				errorNodes[k].style.display = 'none';
				BX.cleanNode(errorNodes[k]);
			}

			if (!errors || BX.util.object_keys(errors).length < 1)
				return;

			for (k in errors)
			{
				if (!errors.hasOwnProperty(k))
					continue;

				blockErrors = errors[k];
				switch (k.toUpperCase())
				{
					case 'MAIN':
						this.showError(this.mainErrorsNode, blockErrors);
						this.animateScrollTo(this.mainErrorsNode, 800, 20);
						scroll = false;
						break;
					case 'AUTH':
						if (this.authBlockNode.style.display == 'none')
						{
							this.showError(this.mainErrorsNode, blockErrors, true);
							this.animateScrollTo(this.mainErrorsNode, 800, 20);
							scroll = false;
						}
						else
							this.showError(this.authBlockNode, blockErrors, true);
						break;
					case 'REGION':
						if (showAll || this.regionBlockNode.getAttribute('data-visited') === 'true')
						{
							// Motor-Force customization: region step disabled; show region-related errors in Delivery block.
							this.showError(this.deliveryBlockNode, blockErrors, true);
							this.showError(this.deliveryHiddenBlockNode, blockErrors);
						}
						break;
					case 'DELIVERY':
						if (showAll || this.deliveryBlockNode.getAttribute('data-visited') === 'true')
						{
							this.showError(this.deliveryBlockNode, blockErrors, true);
							this.showError(this.deliveryHiddenBlockNode, blockErrors);
						}
						break;
					case 'PAY_SYSTEM':
						if (showAll || this.paySystemBlockNode.getAttribute('data-visited') === 'true')
						{
							this.showError(this.paySystemBlockNode, blockErrors, true);
							this.showError(this.paySystemHiddenBlockNode, blockErrors);
						}
						break;
					case 'PROPERTY':
						if (showAll || this.propsBlockNode.getAttribute('data-visited') === 'true')
						{
							this.showError(this.propsBlockNode, blockErrors, true);
							this.showError(this.propsHiddenBlockNode, blockErrors);
						}
						break;
				}
			}

			!!scroll && this.scrollToError();
		},

		showBlockErrors: function(node)
		{
			var errorNode = node.querySelector('div.alert.alert-danger'),
				hiddenNode, errors;

			if (!errorNode)
				return;

			BX.removeClass(node, 'bx-step-error');
			errorNode.style.display = 'none';
			BX.cleanNode(errorNode);

			switch (node.id)
			{
				case this.regionBlockNode.id:
					hiddenNode = this.regionHiddenBlockNode;
					errors = this.result.ERROR.REGION;
					break;
				case this.deliveryBlockNode.id:
					hiddenNode = this.deliveryHiddenBlockNode;
					errors = this.result.ERROR.DELIVERY;
					break;
				case this.paySystemBlockNode.id:
					hiddenNode = this.paySystemHiddenBlockNode;
					errors = this.result.ERROR.PAY_SYSTEM;
					break;
				case this.propsBlockNode.id:
					hiddenNode = this.propsHiddenBlockNode;
					errors = this.result.ERROR.PROPERTY;
					break;
			}

			if (errors && BX.util.object_keys(errors).length)
			{
				this.showError(node, errors, true);
				this.showError(hiddenNode, errors);
			}
		},

		checkNotifications: function()
		{
			var informer = this.mainErrorsNode.querySelector('[data-type="informer"]'),
				success, sections, className, text, scrollTop, informerPos;

			if (informer)
			{
				if (this.firstLoad && this.result.IS_AUTHORIZED && typeof this.result.LAST_ORDER_DATA.FAIL === 'undefined')
				{
					sections = this.orderBlockNode.querySelectorAll('.bx-soa-section.bx-active');
					success = sections.length && sections[sections.length - 1].getAttribute('data-visited') == 'true';
					className = success ? 'success' : 'warning';
					text = (success ? this.params.MESS_SUCCESS_PRELOAD_TEXT : this.params.MESS_FAIL_PRELOAD_TEXT).split('#ORDER_BUTTON#').join(this.params.MESS_ORDER);

					informer.appendChild(

						BX.create('DIV', {
							style: { paddingLeft: '48px' },
							children: [
								BX.create('DIV', {props: {className: 'icon-' + className}}),
								BX.create('p', {
									props: {className: 'pb-0 mb-0'},
									html: text
								})
							]
						})

					);
					BX.addClass(informer, 'alert alert-' + className);
					informer.style.display = '';
				}
				else if (BX.hasClass(informer, 'alert'))
				{
					scrollTop = BX.GetWindowScrollPos().scrollTop;
					informerPos = BX.pos(informer);

					new BX.easing({
						duration: 300,
						start: {opacity: 100},
						finish: {opacity: 0},
						transition: BX.easing.transitions.linear,
						step: function(state){
							informer.style.opacity = state.opacity / 100;
						},
						complete: function(){
							if (scrollTop > informerPos.top)
								window.scrollBy(0, -(informerPos.height + 20));

							informer.style.display = 'none';
							BX.cleanNode(informer);
							informer.removeAttribute('class');
							informer.removeAttribute('style');
						}
					}).animate();
				}
			}
		},

		/**
		 * Returns status of preloaded data from back-end for certain block
		 */
		checkPreload: function(node)
		{
			var status;

			switch (node.id)
			{
				case this.regionBlockNode.id:
					status = this.result.LAST_ORDER_DATA && this.result.LAST_ORDER_DATA.PERSON_TYPE;
					break;
				case this.paySystemBlockNode.id:
					status = this.result.LAST_ORDER_DATA && this.result.LAST_ORDER_DATA.PAY_SYSTEM;
					break;
				case this.deliveryBlockNode.id:
					status = this.result.LAST_ORDER_DATA && this.result.LAST_ORDER_DATA.DELIVERY;
					break;
				case this.pickUpBlockNode.id:
					status = this.result.LAST_ORDER_DATA && this.result.LAST_ORDER_DATA.PICK_UP;
					break;
				default:
					status = true;
			}

			return status;
		},

		checkBlockErrors: function(node)
		{
			var hiddenNode, errorNode, showError, showWarning, errorTooltips, i;

			if (hiddenNode = BX(node.id + '-hidden'))
			{
				errorNode = hiddenNode.querySelector('div.alert.alert-danger');
				showError = errorNode && errorNode.style.display != 'none';
				showWarning = hiddenNode.querySelector('div.alert.alert-warning.alert-show');

				if (!showError)
				{
					errorTooltips = hiddenNode.querySelectorAll('div.tooltip');
					for (i = 0; i < errorTooltips.length; i++)
					{
						if (errorTooltips[i].getAttribute('data-state') == 'opened')
						{
							showError = true;
							break;
						}
					}
				}
			}

			if (showError)
				BX.addClass(node, 'bx-step-error');
			else if (showWarning)
				BX.addClass(node, 'bx-step-warning');
			else
				BX.removeClass(node, 'bx-step-error bx-step-warning');

			return !showError;
		},

		scrollToError: function()
		{
			var sections = this.orderBlockNode.querySelectorAll('div.bx-soa-section.bx-active'),
				i, errorNode;

			for (i in sections)
			{
				if (sections.hasOwnProperty(i))
				{
					errorNode = sections[i].querySelector('.alert.alert-danger');
					if (errorNode && errorNode.style.display != 'none')
					{
						this.animateScrollTo(sections[i]);
						break;
					}
				}
			}
		},

		showWarnings: function()
		{
			var sections = this.orderBlockNode.querySelectorAll('div.bx-soa-section.bx-active'),
				currentDelivery = this.getSelectedDelivery(),
				k,  warningString;

			for (k = 0; k < sections.length; k++)
			{
				BX.removeClass(sections[k], 'bx-step-warning');

				if (sections[k].getAttribute('data-visited') == 'false')
					BX.removeClass(sections[k], 'bx-step-completed');
			}

			if (currentDelivery && currentDelivery.CALCULATE_ERRORS)
			{
				BX.addClass(this.deliveryBlockNode, 'bx-step-warning');

				warningString = '<strong>' + this.params.MESS_DELIVERY_CALC_ERROR_TITLE + '</strong>';
				if (this.params.MESS_DELIVERY_CALC_ERROR_TEXT.length)
					warningString += '<br><small>' + this.params.MESS_DELIVERY_CALC_ERROR_TEXT + '</small>';

				this.showBlockWarning(this.deliveryBlockNode, warningString);
				this.showBlockWarning(this.deliveryHiddenBlockNode, warningString);

				if (this.activeSectionId != this.deliveryBlockNode.id)
				{
					BX.addClass(this.deliveryBlockNode, 'bx-step-completed');
					BX.bind(this.deliveryBlockNode.querySelector('.alert.alert-warning'), 'click', BX.proxy(this.showByClick, this));
				}
			}
			else if (BX.hasClass(this.deliveryBlockNode, 'bx-step-warning') && this.activeSectionId != this.deliveryBlockNode.id)
			{
				BX.removeClass(this.deliveryBlockNode, 'bx-step-warning');
			}

			if (!this.result.WARNING || !this.options.showWarnings)
				return;

			for (k in this.result.WARNING)
			{
				if (this.result.WARNING.hasOwnProperty(k))
				{
					switch (k.toUpperCase())
					{
						case 'DELIVERY':
							if (this.deliveryBlockNode.getAttribute('data-visited') === 'true')
							{
								this.showBlockWarning(this.deliveryBlockNode, this.result.WARNING[k], true);
								this.showBlockWarning(this.deliveryHiddenBlockNode, this.result.WARNING[k], true);
							}

							break;
						case 'PAY_SYSTEM':
							if (this.paySystemBlockNode.getAttribute('data-visited') === 'true')
							{
								this.showBlockWarning(this.paySystemBlockNode, this.result.WARNING[k], true);
								this.showBlockWarning(this.paySystemHiddenBlockNode, this.result.WARNING[k], true);
							}

							break;
					}
				}
			}
		},

		notifyAboutWarnings: function(node)
		{
			if (!BX.type.isDomNode(node))
				return;

			switch (node.id)
			{
				case this.deliveryBlockNode.id:
					this.showBlockWarning(this.deliveryBlockNode, this.result.WARNING.DELIVERY, true);
					break;
				case this.paySystemBlockNode.id:
					this.showBlockWarning(this.paySystemBlockNode, this.result.WARNING.PAY_SYSTEM, true);
					break;
			}
		},

		showBlockWarning: function(node, warnings, hide)
		{
			var errorNode = node.querySelector('.alert.alert-danger'),
				warnStr = '',
				i, warningNode, existedWarningNodes;

			if (errorNode)
			{
				if (BX.type.isString(warnings))
				{
					warnStr = warnings;
				}
				else
				{
					for (i in warnings)
					{
						if (warnings.hasOwnProperty(i) && warnings[i])
						{
							warnStr += warnings[i] + '<br>';
						}
					}
				}

				if (!warnStr)
				{
					return;
				}

				existedWarningNodes = node.querySelectorAll('.alert.alert-warning');
				for (i in existedWarningNodes)
				{
					if (existedWarningNodes.hasOwnProperty(i) && BX.type.isDomNode(existedWarningNodes[i]))
					{
						if (existedWarningNodes[i].innerHTML.indexOf(warnStr) !== -1)
						{
							return;
						}
					}
				}

				warningNode = BX.create('DIV', {
					props: {className: 'alert alert-warning' + (!!hide ? ' alert-hide' : ' alert-show')},
					html: warnStr
				});
				BX.prepend(warningNode, errorNode.parentNode);
				BX.addClass(node, 'bx-step-warning');
			}
		},

		showPagination: function(entity, node)
		{
			if (!node || !entity)
				return;

			var pagination, navigation = [], i,
				pageCounter, active,
				colorTheme, paginationNode;

			switch (entity)
			{
				case 'delivery':
					pagination = this.deliveryPagination; break;
				case 'paySystem':
					pagination = this.paySystemPagination; break;
				case 'pickUp':
					pagination = this.pickUpPagination; break;
			}

			if (pagination.pages.length > 1)
			{
				navigation.push(
					BX.create('LI', {
						attrs: {
							'data-action': 'prev',
							'data-entity': entity
						},
						props: {className: 'bx-pag-prev'},
						html: pagination.pageNumber == 1
							? '<span>' + this.params.MESS_NAV_BACK + '</span>'
							: '<a href=""><span>' + this.params.MESS_NAV_BACK + '</span></a>',
						events: {click: BX.proxy(this.doPagination, this)}
					})
				);
				for (i = 0; i < pagination.pages.length; i++)
				{
					pageCounter = parseInt(i) + 1;
					active = pageCounter == pagination.pageNumber ? 'bx-active' : '';

					navigation.push(
						BX.create('LI', {
							attrs: {
								'data-action': pageCounter,
								'data-entity': entity
							},
							props: {className: active},
							html: '<a href=""><span>' + pageCounter  + '</span></a>',
							events: {click: BX.proxy(this.doPagination, this)}
						})
					);
				}

				navigation.push(
					BX.create('LI', {
						attrs: {
							'data-action': 'next',
							'data-entity': entity
						},
						props: {className: 'bx-pag-next'},
						html: pagination.pageNumber == pagination.pages.length
							? '<span>' + this.params.MESS_NAV_FORWARD + '</span>'
							: '<a href=""><span>' + this.params.MESS_NAV_FORWARD + '</span></a>',
						events: {click: BX.proxy(this.doPagination, this)}
					})
				);
				colorTheme = this.params.TEMPLATE_THEME || '';
				paginationNode = BX.create('DIV', {
					props: {className: 'bx-pagination' + (colorTheme ? ' bx-' + colorTheme : '')},
					children: [
						BX.create('DIV', {
							props: {className: 'bx-pagination-container'},
							children: [BX.create('UL', {children: navigation})]
						})
					]
				});

				node.appendChild(BX.create('DIV', {style: {clear: 'both'}}));
				node.appendChild(paginationNode);
			}
		},

		doPagination: function(e)
		{
			var target = e.target || e.srcElement,
				node = target.tagName == 'LI' ? target : BX.findParent(target, {tagName: 'LI'}),
				page = node.getAttribute('data-action'),
				entity = node.getAttribute('data-entity'),
				pageNum;

			if (BX.hasClass(node, 'bx-active'))
				return BX.PreventDefault(e);

			if (page == 'prev' || page == 'next')
			{
				pageNum = parseInt(BX.findParent(node).querySelector('.bx-active').getAttribute('data-action'));
				page = page == 'next' ? ++pageNum : --pageNum;
			}

			if (entity == 'delivery')
				this.showDeliveryItemsPage(page);
			else if (entity == 'paySystem')
				this.showPaySystemItemsPage(page);
			else if (entity == 'pickUp')
				this.showPickUpItemsPage(page);

			return BX.PreventDefault(e);
		},

		showDeliveryItemsPage: function(page)
		{
			this.getCurrentPageItems('delivery', page);

			var selectedDelivery = this.getSelectedDelivery(), hidden,
				deliveryItemsContainer, k, deliveryItemNode;

			if (selectedDelivery && selectedDelivery.ID)
			{
				hidden = this.deliveryBlockNode.querySelector('input[type=hidden][name=DELIVERY_ID]');
				if (!hidden)
				{
					hidden = BX.create('INPUT', {
						props: {
							type: 'hidden',
							name: 'DELIVERY_ID',
							value: selectedDelivery.ID
						}
					})
				}
			}

			deliveryItemsContainer = this.deliveryBlockNode.querySelector('.bx-soa-pp-item-container');
			BX.cleanNode(deliveryItemsContainer);

			if (BX.type.isDomNode(hidden))
				BX.prepend(hidden, BX.findParent(deliveryItemsContainer));

			var deliveryItemsContainerRow = BX.create('div', {props: {className: 'row'}});

			for (k = 0; k < this.deliveryPagination.currentPage.length; k++)
			{
				deliveryItemNode = this.createDeliveryItem(this.deliveryPagination.currentPage[k]);
				deliveryItemsContainerRow.appendChild(deliveryItemNode);
			}

			deliveryItemsContainer.appendChild(deliveryItemsContainerRow);

			this.showPagination('delivery', deliveryItemsContainer);
		},

		showPaySystemItemsPage: function(page)
		{
			this.getCurrentPageItems('paySystem', page);

			var selectedPaySystem = this.getSelectedPaySystem(), hidden,
				paySystemItemsContainer, k, paySystemItemNode;

			if (selectedPaySystem && selectedPaySystem.ID)
			{
				hidden = this.paySystemBlockNode.querySelector('input[type=hidden][name=PAY_SYSTEM_ID]');
				if (!hidden)
				{
					hidden = BX.create('INPUT', {
						props: {
							type: 'hidden',
							name: 'PAY_SYSTEM_ID',
							value: selectedPaySystem.ID
						}
					})
				}
			}

			paySystemItemsContainer = this.paySystemBlockNode.querySelector('.bx-soa-pp-item-container');
			BX.cleanNode(paySystemItemsContainer);

			if (BX.type.isDomNode(hidden))
				BX.prepend(hidden, BX.findParent(paySystemItemsContainer));

			var paySystemItemsContainerRow = BX.create('div', {props: {className: 'row'}});

			for (k = 0; k < this.paySystemPagination.currentPage.length; k++)
			{
				paySystemItemNode = this.createPaySystemItem(this.paySystemPagination.currentPage[k]);
				paySystemItemsContainerRow.appendChild(paySystemItemNode);
			}

			paySystemItemsContainer.appendChild(paySystemItemsContainerRow);

			this.showPagination('paySystem', paySystemItemsContainer);
		},

		showPickUpItemsPage: function(page)
		{
			this.getCurrentPageItems('pickUp', page);
			this.editPickUpList(false);
		},

		getCurrentPageItems: function(entity, page)
		{
			if (!entity || typeof page === 'undefined')
				return;

			var pagination, perPage;

			switch (entity)
			{
				case 'delivery':
					pagination = this.deliveryPagination;
					perPage = this.options.deliveriesPerPage;
					break;
				case 'paySystem':
					pagination = this.paySystemPagination;
					perPage = this.options.paySystemsPerPage;
					break;
				case 'pickUp':
					pagination = this.pickUpPagination;
					perPage = this.options.pickUpsPerPage;
					break;
			}

			if (pagination && perPage > 0)
			{
				if (page <= 0 || page > pagination.pages.length)
					return;

				pagination.pageNumber = page;
				pagination.currentPage = pagination.pages.slice(pagination.pageNumber - 1, pagination.pageNumber)[0];
			}
		},

		initPropsListForLocation: function()
		{
			if (BX.saleOrderAjax && this.result.ORDER_PROP && this.result.ORDER_PROP.properties)
			{
				var i, k, curProp, attrObj;

				BX.saleOrderAjax.cleanUp();

				for (i = 0; i < this.result.ORDER_PROP.properties.length; i++)
				{
					curProp = this.result.ORDER_PROP.properties[i];

					if (curProp.TYPE == 'LOCATION' && curProp.MULTIPLE == 'Y' && curProp.IS_LOCATION != 'Y')
					{
						for (k = 0; k < this.locations[curProp.ID].length; k++)
						{
							BX.saleOrderAjax.addPropertyDesc({
								id: curProp.ID + '_' + k,
								attributes: {
									id: curProp.ID + '_' + k,
									type: curProp.TYPE,
									valueSource: curProp.SOURCE == 'DEFAULT' ? 'default' : 'form'
								}
							});
						}
					}
					else
					{
						attrObj = {
							id: curProp.ID,
							type: curProp.TYPE,
							valueSource: curProp.SOURCE == 'DEFAULT' ? 'default' : 'form'
						};

						if (!this.deliveryLocationInfo.city && parseInt(curProp.INPUT_FIELD_LOCATION) > 0)
						{
							attrObj.altLocationPropId = parseInt(curProp.INPUT_FIELD_LOCATION);
							this.deliveryLocationInfo.city = curProp.INPUT_FIELD_LOCATION;
						}

						if (!this.deliveryLocationInfo.loc && curProp.IS_LOCATION == 'Y')
							this.deliveryLocationInfo.loc = curProp.ID;

						if (!this.deliveryLocationInfo.zip && curProp.IS_ZIP == 'Y')
						{
							attrObj.isZip = true;
							this.deliveryLocationInfo.zip = curProp.ID;
						}

						BX.saleOrderAjax.addPropertyDesc({
							id: curProp.ID,
							attributes: attrObj
						});
					}
				}
			}
		},

		/**
		 * Binds main events for scrolling/resizing
		 */
		bindEvents: function()
		{
			BX.bind(this.orderSaveBlockNode.querySelector('[data-save-button]'), 'click', BX.proxy(this.clickOrderSaveAction, this));
			BX.bind(window, 'scroll', BX.proxy(this.totalBlockScrollCheck, this));
			BX.bind(window, 'resize', BX.throttle(function(){
				this.totalBlockResizeCheck();
				this.alignBasketColumns();
				this.basketBlockScrollCheck();
				this.mapsReady && this.resizeMapContainers();
			}, 50, this));
			BX.addCustomEvent('onDeliveryExtraServiceValueChange', BX.proxy(this.sendRequest, this));
		},

		initFirstSection: function()
		{
			var firstSection = this.orderBlockNode.querySelector('.bx-soa-section.bx-active');
			BX.addClass(firstSection, 'bx-selected');
			this.activeSectionId = firstSection.id;
		},

		initOptions: function()
		{
			var headers, i, total;

			this.initPropsListForLocation();

			this.propertyCollection = new BX.Sale.PropertyCollection(BX.merge({publicMode: true}, this.result.ORDER_PROP));
			this.fadedPropertyCollection = new BX.Sale.PropertyCollection(BX.merge({publicMode: true}, this.result.ORDER_PROP));

			if (this.options.propertyValidation)
				this.initValidation();

			this.initPagination();

			this.options.showPreviewPicInBasket = false;
			this.options.showDetailPicInBasket = false;
			this.options.showPropsInBasket = false;
			this.options.showPriceNotesInBasket = false;

			if (this.result.GRID && this.result.GRID.HEADERS)
			{
				headers = this.result.GRID.HEADERS;
				for (i = 0; i < headers.length; i++)
				{
					if (headers[i].id === 'PREVIEW_PICTURE')
						this.options.showPreviewPicInBasket = true;

					if (headers[i].id === 'DETAIL_PICTURE')
						this.options.showDetailPicInBasket = true;

					if (headers[i].id === 'PROPS')
						this.options.showPropsInBasket = true;

					if (headers[i].id === 'NOTES')
						this.options.showPriceNotesInBasket = true;
				}
			}

			if (this.result.TOTAL)
			{
				total = this.result.TOTAL;
				this.options.showOrderWeight = total.ORDER_WEIGHT && parseFloat(total.ORDER_WEIGHT) > 0;
				this.options.showPriceWithoutDiscount = parseFloat(total.ORDER_PRICE) < parseFloat(total.PRICE_WITHOUT_DISCOUNT_VALUE);
				this.options.showDiscountPrice = total.DISCOUNT_PRICE && parseFloat(total.DISCOUNT_PRICE) > 0;
				this.options.showTaxList = total.TAX_LIST && total.TAX_LIST.length;
				this.options.showPayedFromInnerBudget = total.PAYED_FROM_ACCOUNT_FORMATED && total.PAYED_FROM_ACCOUNT_FORMATED.length;
			}
		},

		reachGoal: function(goal, section)
		{
			var counter = this.params.YM_GOALS_COUNTER || '',
				useGoals = this.params.USE_YM_GOALS == 'Y' && typeof window['yaCounter' + counter] !== 'undefined',
				goalId;

			if (useGoals)
			{
				goalId = this.getGoalId(goal, section);
				window['yaCounter' + counter].reachGoal(goalId);
			}
		},

		getGoalId: function(goal, section)
		{
			if (!goal)
				return '';

			if (goal == 'initialization')
				return this.params.YM_GOALS_INITIALIZE;

			if (goal == 'order')
				return this.params.YM_GOALS_SAVE_ORDER;

			var goalId = '',
				isEdit = goal == 'edit';

			if (!section || !section.id)
				return '';

			switch (section.id)
			{
				case this.basketBlockNode.id:
					goalId = isEdit ? this.params.YM_GOALS_EDIT_BASKET : this.params.YM_GOALS_NEXT_BASKET; break;
				case this.regionBlockNode.id:
					goalId = isEdit ? this.params.YM_GOALS_EDIT_REGION : this.params.YM_GOALS_NEXT_REGION; break;
				case this.paySystemBlockNode.id:
					goalId = isEdit ? this.params.YM_GOALS_EDIT_PAY_SYSTEM : this.params.YM_GOALS_NEXT_PAY_SYSTEM; break;
				case this.deliveryBlockNode.id:
					goalId = isEdit ? this.params.YM_GOALS_EDIT_DELIVERY : this.params.YM_GOALS_NEXT_DELIVERY; break;
				case this.pickUpBlockNode.id:
					goalId = isEdit ? this.params.YM_GOALS_EDIT_PICKUP : this.params.YM_GOALS_NEXT_PICKUP; break;
				case this.propsBlockNode.id:
					goalId = isEdit ? this.params.YM_GOALS_EDIT_PROPERTIES : this.params.YM_GOALS_NEXT_PROPERTIES; break;
			}

			return goalId;
		},

		isPriceChanged: function(result)
		{
			var priceBefore = this.result.TOTAL.ORDER_TOTAL_LEFT_TO_PAY === null || this.result.TOTAL.ORDER_TOTAL_LEFT_TO_PAY === ''
					? this.result.TOTAL.ORDER_TOTAL_PRICE
					: this.result.TOTAL.ORDER_TOTAL_LEFT_TO_PAY,
				priceAfter = result.order.TOTAL.ORDER_TOTAL_LEFT_TO_PAY === null ? result.order.TOTAL.ORDER_TOTAL_PRICE : result.order.TOTAL.ORDER_TOTAL_LEFT_TO_PAY;

			this.options.totalPriceChanged = parseFloat(priceBefore) != parseFloat(priceAfter);
		},

		initValidation: function()
		{
			if (!this.result.ORDER_PROP || !this.result.ORDER_PROP.properties)
				return;

			var properties = this.result.ORDER_PROP.properties,
				obj = {}, i;

			for (i in properties)
			{
				if (properties.hasOwnProperty(i))
					obj[properties[i].ID] = properties[i];
			}

			this.validation.properties = obj;
		},

		initPagination: function()
		{
			var arReserve, pages, arPages, i;

			if (this.result.DELIVERY)
			{
				this.result.DELIVERY = this.getDeliverySortedArray(this.result.DELIVERY);

				if (this.options.deliveriesPerPage > 0 && this.result.DELIVERY.length > this.options.deliveriesPerPage)
				{
					arReserve = this.result.DELIVERY.slice();
					pages = Math.ceil(arReserve.length / this.options.deliveriesPerPage);
					arPages = [];

					for (i = 0; i < pages; i++)
					{
						arPages.push(arReserve.splice(0, this.options.deliveriesPerPage));
					}
					this.deliveryPagination.pages = arPages;

					for (i = 0; i < this.result.DELIVERY.length; i++)
					{
						if (this.result.DELIVERY[i].CHECKED == 'Y')
						{
							this.deliveryPagination.pageNumber = Math.ceil(++i / this.options.deliveriesPerPage);
							break;
						}
					}

					this.deliveryPagination.pageNumber = this.deliveryPagination.pageNumber || 1;
					this.deliveryPagination.currentPage = arPages.slice(this.deliveryPagination.pageNumber - 1, this.deliveryPagination.pageNumber)[0];
					this.deliveryPagination.show = true
				}
				else
				{
					this.deliveryPagination.pageNumber = 1;
					this.deliveryPagination.currentPage = this.result.DELIVERY;
					this.deliveryPagination.show = false;
				}
			}

			if (this.result.PAY_SYSTEM)
			{
				if (this.options.paySystemsPerPage > 0 && this.result.PAY_SYSTEM.length > this.options.paySystemsPerPage)
				{
					arReserve = this.result.PAY_SYSTEM.slice();
					pages = Math.ceil(arReserve.length / this.options.paySystemsPerPage);
					arPages = [];

					for (i = 0; i < pages; i++)
					{
						arPages.push(arReserve.splice(0, this.options.paySystemsPerPage));
					}
					this.paySystemPagination.pages = arPages;

					for (i = 0; i < this.result.PAY_SYSTEM.length; i++)
					{
						if (this.result.PAY_SYSTEM[i].CHECKED == 'Y')
						{
							this.paySystemPagination.pageNumber = Math.ceil(++i / this.options.paySystemsPerPage);
							break;
						}
					}

					this.paySystemPagination.pageNumber = this.paySystemPagination.pageNumber || 1;
					this.paySystemPagination.currentPage = arPages.slice(this.paySystemPagination.pageNumber - 1, this.paySystemPagination.pageNumber)[0];
					this.paySystemPagination.show = true
				}
				else
				{
					this.paySystemPagination.pageNumber = 1;
					this.paySystemPagination.currentPage = this.result.PAY_SYSTEM;
					this.paySystemPagination.show = false;
				}
			}
		},

		initPickUpPagination: function()
		{
			var usePickUpPagination = false,
				usePickUp = false,
				stores, i = 0,
				arReserve, pages, arPages;

			if (this.options.pickUpsPerPage >= 0 && this.result.DELIVERY)
			{
				for (i = 0; i < this.result.DELIVERY.length; i++)
				{
					if (this.result.DELIVERY[i].CHECKED === 'Y' && this.result.DELIVERY[i].STORE_MAIN)
					{
						usePickUp = this.result.DELIVERY[i].STORE_MAIN.length > 0;
						usePickUpPagination = this.result.DELIVERY[i].STORE_MAIN.length > this.options.pickUpsPerPage;
						if (usePickUp)
							stores = this.getPickUpInfoArray(this.result.DELIVERY[i].STORE_MAIN);
						break;
					}
				}
			}

			if (usePickUp)
			{
				if (this.options.pickUpsPerPage > 0 && usePickUpPagination)
				{
					arReserve = stores.slice();
					pages = Math.ceil(arReserve.length / this.options.pickUpsPerPage);
					arPages = [];

					for (i = 0; i < pages; i++)
						arPages.push(arReserve.splice(0, this.options.pickUpsPerPage));

					this.pickUpPagination.pages = arPages;

					for (i = 0; i < stores.length; i++)
					{
						if (!this.result.BUYER_STORE || stores[i].ID == this.result.BUYER_STORE)
						{
							this.pickUpPagination.pageNumber = Math.ceil(++i / this.options.pickUpsPerPage);
							break;
						}
					}

					if (!this.pickUpPagination.pageNumber)
						this.pickUpPagination.pageNumber = 1;

					this.pickUpPagination.currentPage = arPages.slice(this.pickUpPagination.pageNumber - 1, this.pickUpPagination.pageNumber)[0];
					this.pickUpPagination.show = true
				}
				else
				{
					this.pickUpPagination.pageNumber = 1;
					this.pickUpPagination.currentPage = stores;
					this.pickUpPagination.show = false;
				}
			}
		},

		prepareLocations: function(locations)
		{
			this.locations = {};
			this.cleanLocations = {};

			var temporaryLocations,
				i, k, output;

			if (BX.util.object_keys(locations).length)
			{
				for (i in locations)
				{
					if (!locations.hasOwnProperty(i))
						continue;

					this.locationsTemplate = locations[i].template || '';
					temporaryLocations = [];
					output = locations[i].output;

					if (output.clean)
					{
						this.cleanLocations[i] = BX.processHTML(output.clean, false);
						delete output.clean;
					}

					for (k in output)
					{
						if (output.hasOwnProperty(k))
						{
							temporaryLocations.push({
								output: BX.processHTML(output[k], false),
								showAlt: locations[i].showAlt,
								lastValue: locations[i].lastValue,
								coordinates: locations[i].coordinates || false
							});
						}
					}

					this.locations[i] = temporaryLocations;
				}
			}
		},

		locationsCompletion: function()
		{
			var i, locationNode, clearButton, inputStep, inputSearch,
				arProperty, data, section;

			this.locationsInitialized = true;
			// Motor-Force customization: location+zip are rendered in Delivery block.
			this.fixLocationsStyle(this.deliveryBlockNode, this.deliveryHiddenBlockNode);
			this.fixLocationsStyle(this.propsBlockNode, this.propsHiddenBlockNode);

			for (i in this.locations)
			{
				if (!this.locations.hasOwnProperty(i))
					continue;

				locationNode = this.orderBlockNode.querySelector('div[data-property-id-row="' + i + '"]');
				if (!locationNode)
					continue;

				clearButton = locationNode.querySelector('div.bx-ui-sls-clear');
				inputStep = locationNode.querySelector('div.bx-ui-slst-pool');
				inputSearch = locationNode.querySelector('input.bx-ui-sls-fake[type=text]');

				locationNode.removeAttribute('style');
				this.bindValidation(i, locationNode);
				if (clearButton)
				{
					BX.bind(clearButton, 'click', function(e){
						var target = e.target || e.srcElement,
							parent = BX.findParent(target, {tagName: 'DIV', className: 'form-group'}),
							locationInput;

						if (parent)
							locationInput = parent.querySelector('input.bx-ui-sls-fake[type=text]');

						if (locationInput)
							BX.fireEvent(locationInput, 'keyup');
					});
				}

				if (!this.firstLoad && this.options.propertyValidation)
				{
					if (inputStep)
					{
						arProperty = this.validation.properties[i];
						data = this.getValidationData(arProperty, locationNode);
						section = BX.findParent(locationNode, {className: 'bx-soa-section'});

						if (section && section.getAttribute('data-visited') == 'true')
							this.isValidProperty(data);
					}

					if (inputSearch)
						BX.fireEvent(inputSearch, 'keyup');
				}
			}

			if (this.firstLoad && this.result.IS_AUTHORIZED && typeof this.result.LAST_ORDER_DATA.FAIL === 'undefined')
			{
				this.showActualBlock();
			}
			else if (!this.result.SHOW_AUTH)
			{
				this.changeVisibleContent();
			}

			this.checkNotifications();

			// Motor-Force customization: region block disabled (location+zip are in Delivery block).

			if (this.activeSectionId != this.propsBlockNode.id)
				this.editFadePropsContent(this.propsBlockNode.querySelector('.bx-soa-section-content'));
		},

		fixLocationsStyle: function(section, hiddenSection)
		{
			if (!section || !hiddenSection)
				return;

			var regionActive = this.activeSectionId == section.id ? section : hiddenSection,
				locationSearchInputs, locationStepInputs, i;

			locationSearchInputs = regionActive.querySelectorAll('div.bx-sls div.dropdown-block.bx-ui-sls-input-block');
			locationStepInputs = regionActive.querySelectorAll('div.bx-slst div.dropdown-block.bx-ui-slst-input-block');

			if (locationSearchInputs.length)
				for (i = 0; i < locationSearchInputs.length; i++)
					BX.addClass(locationSearchInputs[i], 'form-control');

			if (locationStepInputs.length)
				for (i = 0; i < locationStepInputs.length; i++)
					BX.addClass(locationStepInputs[i], 'form-control');
		},

		/**
		 * Order saving action with validation. Doesn't send request while have errors
		 */
		clickOrderSaveAction: function(event)
		{
			if (this.isValidForm())
			{
				this.allowOrderSave();

				if (this.params.USER_CONSENT === 'Y' && BX.UserConsent)
				{
					BX.onCustomEvent('bx-soa-order-save', []);
				}
				else
				{
					this.doSaveAction();
				}
			}

			return BX.PreventDefault(event);
		},

		doSaveAction: function()
		{
			if (this.isOrderSaveAllowed())
			{
				this.reachGoal('order');
				this.sendRequest('saveOrderAjax');
			}
		},

		/**
		 * Hiding current block node and showing next available block node
		 */
		clickNextAction: function(event)
		{
			var target = event.target || event.srcElement,
				actionSection = BX.findParent(target, {className : "bx-active"}),
				section = this.getNextSection(actionSection),
				allSections, titleNode, editStep;

			// Motor-Force customization:
			// In "virtual eDost tariffs" mode, do not allow leaving Delivery step
			// until user explicitly selects a tariff (MF_EDOST_TARIF_ID).
			try
			{
				if (actionSection && actionSection.id === this.deliveryBlockNode.id)
				{
					var f = BX('bx-soa-order-form');
					var virtual = f && (f.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]') || f.querySelector('#mf-edost-box'));
					if (virtual)
					{
						var tidEl = f ? f.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]') : null;
						var tid = tidEl ? BX.util.trim(String(tidEl.value || '')) : '';
						// Also require location to be chosen (the selector may exist but be empty).
						var locHidden = f ? f.querySelector('input[type="hidden"][name^="ORDER_PROP_"]') : null;
						if (!tid)
						{
							this.showError(this.deliveryBlockNode, 'Выберите способ доставки, чтобы перейти дальше.', true);
							this.animateScrollTo(this.deliveryBlockNode, 400, 20);
							return BX.PreventDefault(event);
						}
					}
				}
			}
			catch (e)
			{
				// ignore
			}

			this.reachGoal('next', actionSection);

			if (
				(!this.result.IS_AUTHORIZED || typeof this.result.LAST_ORDER_DATA.FAIL !== 'undefined')
				&& section.next.getAttribute('data-visited') == 'false'
			)
			{
				titleNode = section.next.querySelector('.bx-soa-section-title-container');
				BX.bind(titleNode, 'click', BX.proxy(this.showByClick, this));
				editStep = section.next.querySelector('.bx-soa-editstep');
				if (editStep)
					editStep.style.display = '';

				allSections = this.orderBlockNode.querySelectorAll('.bx-soa-section.bx-active');
				if (section.next.id == allSections[allSections.length - 1].id)
					this.switchOrderSaveButtons(true);
			}

			this.fade(actionSection, section.next);
			this.show(section.next);

			// Motor-Force customization:
			// After fading Delivery block, Bitrix renders the collapsed summary ("Стандартный 0 ₽").
			// Replace it with the selected virtual delivery tariff name/price (display-only).
			try
			{
				if (actionSection && actionSection.id === this.deliveryBlockNode.id)
				{
					setTimeout(function(){
						try {
							if (BX && BX.saleOrderAjax && BX.saleOrderAjax.__mfEdost && typeof BX.saleOrderAjax.__mfEdost.applyDeliverySummary === 'function')
							{
								BX.saleOrderAjax.__mfEdost.applyDeliverySummary();
							}
						} catch(e2) {}
					}, 30);
				}
				// When navigating into Delivery step, re-render virtual offers and restore selection.
				if (section && section.next && section.next.id === this.deliveryBlockNode.id)
				{
					setTimeout(function(){
						try {
							if (BX && BX.saleOrderAjax && BX.saleOrderAjax.__mfEdost && typeof BX.saleOrderAjax.__mfEdost.onEnterDelivery === 'function')
							{
								BX.saleOrderAjax.__mfEdost.onEnterDelivery();
							}
						} catch(e3) {}
					}, 30);
				}
			}
			catch (e)
			{
				// ignore
			}

			return BX.PreventDefault(event);
		},

		/**
		 * Hiding current block node and showing previous available block node
		 */
		clickPrevAction: function(event)
		{
			var target = event.target || event.srcElement,
				actionSection = BX.findParent(target, {className: "bx-active"}),
				section = this.getPrevSection(actionSection);

			this.fade(actionSection);
			this.show(section.next);
			// Motor-Force customization:
			// When leaving Delivery step backwards (Delivery -> Basket), Bitrix re-renders the collapsed
			// delivery summary and can show the technical delivery ("Стандартный"). Replace it again.
			try
			{
				if (actionSection && actionSection.id === this.deliveryBlockNode.id)
				{
					setTimeout(function(){
						try {
							if (BX && BX.saleOrderAjax && BX.saleOrderAjax.__mfEdost && typeof BX.saleOrderAjax.__mfEdost.applyDeliverySummary === 'function')
							{
								BX.saleOrderAjax.__mfEdost.applyDeliverySummary();
							}
						} catch(e3) {}
					}, 30);
				}
			}
			catch (e)
			{
				// ignore
			}
			// Motor-Force customization: when navigating into Delivery step, restore virtual offers.
			try
			{
				if (section && section.next && section.next.id === this.deliveryBlockNode.id)
				{
					setTimeout(function(){
						try {
							if (BX && BX.saleOrderAjax && BX.saleOrderAjax.__mfEdost && typeof BX.saleOrderAjax.__mfEdost.onEnterDelivery === 'function')
							{
								BX.saleOrderAjax.__mfEdost.onEnterDelivery();
							}
						} catch(e2) {}
					}, 30);
				}
			}
			catch (e)
			{
				// ignore
			}
			this.animateScrollTo(section.next, 800);
			return BX.PreventDefault(event);
		},

		/**
		 * Showing authentication block node
		 */
		showAuthBlock: function()
		{
			var showNode = this.authBlockNode,
				fadeNode = BX(this.activeSectionId);

			if (!showNode || BX.hasClass(showNode, 'bx-selected'))
				return;

			fadeNode && this.fade(fadeNode);
			this.show(showNode);
		},

		/**
		 * Hiding authentication block node
		 */
		closeAuthBlock: function()
		{
			var actionSection = this.authBlockNode,
				nextSection = this.getNextSection(actionSection).next;

			this.fade(actionSection);
			BX.cleanNode(BX(nextSection.id + '-hidden'));
			this.show(nextSection);
		},

		/**
		 * Checks possibility to skip section
		 */
		shouldSkipSection: function(section)
		{
			var skip = false;

			if (this.params.SKIP_USELESS_BLOCK === 'Y')
			{
				if (section.id === this.pickUpBlockNode.id)
				{
					var delivery = this.getSelectedDelivery();
					if (delivery)
					{
						skip = this.getPickUpInfoArray(delivery.STORE).length === 1;
					}
				}

				if (section.id === this.deliveryBlockNode.id)
				{
					// Motor-Force customization:
					// We use a single technical Bitrix delivery ("Стандартный", price=0) and show
					// real delivery options as virtual eDost tariffs. Therefore we must NOT skip
					// the Delivery step even if Bitrix sees only one delivery service.
					try
					{
						var f = BX('bx-soa-order-form');
						if (f && (f.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]') || f.querySelector('#mf-edost-box')))
						{
							return false;
						}
					}
					catch (e)
					{
						// ignore
					}

					skip = this.result.DELIVERY && this.result.DELIVERY.length === 1
						&& this.result.DELIVERY[0].EXTRA_SERVICES.length === 0
						&& !this.result.DELIVERY[0].CALCULATE_ERRORS;
				}

				if (section.id === this.paySystemBlockNode.id)
				{
					skip = this.result.PAY_SYSTEM && this.result.PAY_SYSTEM.length === 1 && this.result.PAY_FROM_ACCOUNT !== 'Y';
				}
			}

			return skip;
		},

		/**
		 * Returns next available block node (node skipped while have one pay system, delivery or pick up)
		 */
		getNextSection: function(actionSection, skippedSection)
		{
			if (!this.orderBlockNode || !actionSection)
				return {};

			var allSections = this.orderBlockNode.querySelectorAll('.bx-soa-section.bx-active'),
				nextSection, i;

			for (i = 0; i < allSections.length; i++)
			{
				if (allSections[i].id === actionSection.id && allSections[i + 1])
				{
					nextSection = allSections[i + 1];

					if (this.shouldSkipSection(nextSection))
					{
						this.markSectionAsCompleted(nextSection);

						return this.getNextSection(nextSection, nextSection);
					}

					return {
						prev: actionSection,
						next: nextSection,
						skip: skippedSection
					};
				}
			}

			return {next: actionSection};
		},

		markSectionAsCompleted: function(section)
		{
			var titleNode;

			if (
				(!this.result.IS_AUTHORIZED || typeof this.result.LAST_ORDER_DATA.FAIL !== 'undefined')
				&& section.getAttribute('data-visited') === 'false'
			)
			{
				this.changeVisibleSection(section, true);
				titleNode = section.querySelector('.bx-soa-section-title-container');
				BX.bind(titleNode, 'click', BX.proxy(this.showByClick, this));
			}

			section.setAttribute('data-visited', 'true');
			BX.addClass(section, 'bx-step-completed');
			BX.remove(section.querySelector('.alert.alert-warning.alert-hide'));
			this.checkBlockErrors(section);
		},

		/**
		 * Returns previous available block node (node skipped while have one pay system, delivery or pick up)
		 */
		getPrevSection: function(actionSection)
		{
			if (!this.orderBlockNode || !actionSection)
				return {};

			var allSections = this.orderBlockNode.querySelectorAll('.bx-soa-section.bx-active'),
				prevSection, i;

			for (i = 0; i < allSections.length; i++)
			{
				if (allSections[i].id === actionSection.id && allSections[i - 1])
				{
					prevSection = allSections[i - 1];

					if (this.shouldSkipSection(prevSection))
					{
						this.markSectionAsCompleted(prevSection);

						return this.getPrevSection(prevSection);
					}

					return {
						prev: actionSection,
						next: prevSection
					};
				}
			}

			return {next: actionSection};
		},

		addAnimationEffect: function(node, className, timeout)
		{
			if (!node || !className)
				return;

			if (this.timeOut[node.id])
			{
				clearTimeout(this.timeOut[node.id].timer);
				BX.removeClass(node, this.timeOut[node.id].className);
			}

			setTimeout(function(){BX.addClass(node, className)}, 10);
			this.timeOut[node.id] = {
				className: className,
				timer: setTimeout(
					BX.delegate(function(){
						BX.removeClass(node, className);
						delete this.timeOut[node.id];
					}, this),
					timeout || 5000)
			};
		},

		/**
		 * Replacing current active block node with generated fade block node
		 */
		fade: function(node, nextSection)
		{
			if (!node || !node.id || this.activeSectionId != node.id)
				return;

			this.hasErrorSection[node.id] = false;

			var objHeightOrig = node.offsetHeight,
				objHeight;

			switch (node.id)
			{
				case this.authBlockNode.id:
					this.authBlockNode.style.display = 'none';
					BX.removeClass(this.authBlockNode, 'bx-active');
					break;
				case this.basketBlockNode.id:
					this.editFadeBasketBlock();
					break;
				case this.regionBlockNode.id:
					this.editFadeRegionBlock();
					break;
				case this.paySystemBlockNode.id:
					BX.remove(this.paySystemBlockNode.querySelector('.alert.alert-warning.alert-hide'));
					this.editFadePaySystemBlock();
					break;
				case this.deliveryBlockNode.id:
					BX.remove(this.deliveryBlockNode.querySelector('.alert.alert-warning.alert-hide'));
					this.editFadeDeliveryBlock();
					break;
				case this.pickUpBlockNode.id:
					this.editFadePickUpBlock();
					break;
				case this.propsBlockNode.id:
					this.editFadePropsBlock();
					break;
			}

			BX.addClass(node, 'bx-step-completed');
			BX.removeClass(node, 'bx-selected');

			objHeight = node.offsetHeight;
			node.style.height = objHeightOrig + 'px';

			// calculations of scrolling animation
			if (nextSection)
			{
				var windowScrollTop = BX.GetWindowScrollPos().scrollTop,
					orderPos = BX.pos(this.orderBlockNode),
					nodePos = BX.pos(node),
					diff, scrollTo, nextSectionHeightBefore, nextSectionHeightAfter, nextSectionHidden, offset;

				nextSectionHidden = BX(nextSection.id + '-hidden');
				nextSectionHidden.style.left = '-10000';
				nextSectionHidden.style.position = 'absolute';
				this.orderBlockNode.appendChild(nextSectionHidden);
				nextSectionHeightBefore = nextSection.offsetHeight;
				nextSectionHeightAfter = nextSectionHidden.offsetHeight + 57;
				BX(node.id + '-hidden').parentNode.appendChild(nextSectionHidden);
				nextSectionHidden.removeAttribute('style');

				diff = objHeight + nextSectionHeightAfter - objHeightOrig - nextSectionHeightBefore;

				offset = window.innerHeight - orderPos.height - diff;
				if (offset > 0)
					scrollTo = orderPos.top - offset/2;
				else
				{
					if (nodePos.top > windowScrollTop)
						scrollTo = nodePos.top;
					else
						scrollTo = nodePos.bottom + 6 - objHeightOrig + objHeight;

					if (scrollTo + window.innerHeight > orderPos.bottom + 25 + diff)
						scrollTo = orderPos.bottom + 25 + diff - window.innerHeight;
				}

				scrollTo -= this.isMobile ? 50 : 0;
			}

			new BX.easing({
				duration: nextSection ? 800 : 600,
				start: {height: objHeightOrig, scrollTop: windowScrollTop},
				finish: {height: objHeight, scrollTop: scrollTo},
				transition: BX.easing.makeEaseOut(BX.easing.transitions.quad),
				step: function(state){
					node.style.height = state.height + "px";
					if (nextSection)
						window.scrollTo(0, state.scrollTop);
				},
				complete: function(){
					node.style.height = '';
				}
			}).animate();

			this.checkBlockErrors(node);
		},

		/**
		 * Showing active data in certain block node
		 */
		show: function(node)
		{
			if (!node || !node.id || this.activeSectionId == node.id)
				return;

			this.activeSectionId = node.id;
			BX.removeClass(node, 'bx-step-error bx-step-warning');

			switch (node.id)
			{
				case this.authBlockNode.id:
					this.authBlockNode.style.display = '';
					BX.addClass(this.authBlockNode, 'bx-active');
					break;
				case this.basketBlockNode.id:
					this.editActiveBasketBlock(true);
					this.alignBasketColumns();
					break;
				case this.regionBlockNode.id:
					this.editActiveRegionBlock(true);
					break;
				case this.deliveryBlockNode.id:
					this.editActiveDeliveryBlock(true);
					break;
				case this.paySystemBlockNode.id:
					this.editActivePaySystemBlock(true);
					break;
				case this.pickUpBlockNode.id:
					this.editActivePickUpBlock(true);
					break;
				case this.propsBlockNode.id:
					this.editActivePropsBlock(true);
					break;
			}

			if (node.getAttribute('data-visited') === 'false')
			{
				this.showBlockErrors(node);
				this.notifyAboutWarnings(node);
			}

			node.setAttribute('data-visited', 'true');
			BX.addClass(node, 'bx-selected');
			BX.removeClass(node, 'bx-step-completed');
		},

		showByClick: function(event)
		{
			var target = event.target || event.srcElement,
				showNode = BX.findParent(target, {className: "bx-active"}),
				fadeNode = BX(this.activeSectionId),
				scrollTop = BX.GetWindowScrollPos().scrollTop;

			if (!showNode || BX.hasClass(showNode, 'bx-selected'))
				return BX.PreventDefault(event);

			this.reachGoal('edit', showNode);

			fadeNode && this.fade(fadeNode);
			this.show(showNode);
			// Motor-Force customization: clicking "изменить" on Delivery should show virtual offers.
			try
			{
				if (showNode && showNode.id === this.deliveryBlockNode.id)
				{
					setTimeout(function(){
						try {
							if (BX && BX.saleOrderAjax && BX.saleOrderAjax.__mfEdost && typeof BX.saleOrderAjax.__mfEdost.onEnterDelivery === 'function')
							{
								BX.saleOrderAjax.__mfEdost.onEnterDelivery();
							}
						} catch(e2) {}
					}, 30);
				}
			}
			catch (e)
			{
				// ignore
			}

			setTimeout(BX.delegate(function(){
				if (BX.pos(showNode).top < scrollTop)
					this.animateScrollTo(showNode, 300);
			}, this), 320);

			return BX.PreventDefault(event);
		},

		/**
		 * Checks each active block from top to bottom for errors (showing first block with errors or last block)
		 */
		showActualBlock: function()
		{
			var allSections = this.orderBlockNode.querySelectorAll('.bx-soa-section.bx-active'),
				i = 0;

			while (allSections[i])
			{
				if (allSections[i].id === this.regionBlockNode.id)
					this.isValidRegionBlock();

				if (allSections[i].id === this.propsBlockNode.id)
					this.isValidPropertiesBlock();

				if (!this.checkBlockErrors(allSections[i]) || !this.checkPreload(allSections[i]))
				{
					if (this.activeSectionId !== allSections[i].id)
					{
						BX(this.activeSectionId) && this.fade(BX(this.activeSectionId));
						this.show(allSections[i]);
					}

					break;
				}

				BX.addClass(allSections[i], 'bx-step-completed');
				allSections[i].setAttribute('data-visited', 'true');
				i++;
			}
		},

		/**
		 * Returns footer node with navigation buttons
		 */
		getBlockFooter: function(node)
		{
			var sections = this.orderBlockNode.querySelectorAll('.bx-soa-section.bx-active'),
				firstSection = sections[0],
				lastSection = sections[sections.length - 1],
				currentSection = BX.findParent(node, {className: "bx-soa-section"}),
				isLastNode = false,
				buttons = [];

			if (currentSection && currentSection.id.indexOf(firstSection.id) == '-1')
			{
				buttons.push(
					BX.create('button', {
						props: {
							href: 'javascript:void(0)',
							className: 'btn btn-outline-secondary pl-3 pr-3'
						},
						html: this.params.MESS_BACK,
						events: {
							click: BX.proxy(this.clickPrevAction, this)
						}
					})
				);
			}

			if (currentSection && currentSection.id.indexOf(lastSection.id) != '-1')
				isLastNode = true;

			if (!isLastNode)
			{
				buttons.push(
					BX.create('button', {
						props: {href: 'javascript:void(0)', className: 'pull-right btn btn-primary pl-3 pr-3'},
						html: this.params.MESS_FURTHER,
						events: {click: BX.proxy(this.clickNextAction, this)}
					})
				);
			}

			node.appendChild(
				BX.create('DIV', {
					props: {className: 'row bx-soa-more'},
					children: [
						BX.create('DIV', {
							props: {className: 'bx-soa-more-btn col'},
							children: buttons
						})
					]
				})
			);
		},

		getNewContainer: function(notFluid)
		{
			return BX.create('DIV', {props: {className: 'bx-soa-section-content' + (!!notFluid ? '' : ' container-fluid')}});
		},

		/**
		 * Showing/hiding order save buttons
		 */
		switchOrderSaveButtons: function(state)
		{
			var orderSaveNode = this.orderSaveBlockNode,
				totalButton = this.totalBlockNode.querySelector('.bx-soa-cart-total-button-container'),
				mobileButton = this.mobileTotalBlockNode.querySelector('.bx-soa-cart-total-button-container'),
				lastState = this.orderSaveBlockNode.style.display == '';

			if (lastState != state)
			{
				if (state)
				{
					orderSaveNode.style.opacity = 0;
					orderSaveNode.style.display = '';
					if (totalButton)
					{
						totalButton.style.opacity = 0;
						totalButton.style.display = '';
					}
					if (mobileButton)
					{
						mobileButton.style.opacity = 0;
						mobileButton.style.display = '';
					}

					new BX.easing({
						duration: 500,
						start: {opacity: 0},
						finish: {opacity: 100},
						transition: BX.easing.transitions.linear,
						step: function(state){
							orderSaveNode.style.opacity = state.opacity / 100;
							if (totalButton)
								totalButton.style.opacity = state.opacity / 100;
							if (mobileButton)
								mobileButton.style.opacity = state.opacity / 100;
						},
						complete: function(){
							orderSaveNode.removeAttribute('style');
							totalButton && totalButton.removeAttribute('style');
							mobileButton && mobileButton.removeAttribute('style');
						}
					}).animate();
				}
				else
				{
					orderSaveNode.style.display = 'none';
					if (totalButton)
						totalButton.setAttribute('style', 'display: none !important');
					if (mobileButton)
						mobileButton.setAttribute('style', 'display: none !important');
				}
			}
		},

		/**
		 * Returns true if current section or next sections had already visited
		 */
		shouldBeSectionVisible: function(sections, currentPosition)
		{
			var state = false, editStepNode;

			if (!sections || !sections.length)
				return state;

			for (; currentPosition < sections.length; currentPosition++)
			{
				if (sections[currentPosition].getAttribute('data-visited') == 'true')
				{
					state = true;
					break;
				}

				if (!this.firstLoad)
				{
					editStepNode = sections[currentPosition].querySelector('.bx-soa-editstep');
					if (editStepNode && editStepNode.style.display !== 'none')
					{
						state = true;
						break;
					}
				}
			}

			return state;
		},

		/**
		 * Showing/hiding blocks content if user authorized/unauthorized
		 */
		changeVisibleContent: function()
		{
			var sections = this.orderBlockNode.querySelectorAll('.bx-soa-section.bx-active'),
				i, state;

			var orderDataLoaded = !!this.result.IS_AUTHORIZED && this.params.USE_PRELOAD === 'Y' && this.result.LAST_ORDER_DATA.FAIL !== true,
				skipFlag = true;

			for (i = 0; i < sections.length; i++)
			{
				state = this.firstLoad && orderDataLoaded;
				state = state || this.shouldBeSectionVisible(sections, i);

				this.changeVisibleSection(sections[i], state);

				if (this.firstLoad && skipFlag)
				{
					if (
						state
						&& sections[i + 1]
						&& this.checkBlockErrors(sections[i])
						&& (
							(orderDataLoaded && this.checkPreload(sections[i]))
							|| (!orderDataLoaded && this.shouldSkipSection(sections[i]))
						)
					)
					{
						this.fade(sections[i]);
						this.markSectionAsCompleted(sections[i]);
						this.show(sections[i + 1]);
					}
					else
					{
						skipFlag = false;
					}
				}
			}

			if (
				(!this.result.IS_AUTHORIZED || typeof this.result.LAST_ORDER_DATA.FAIL !== 'undefined')
				&& this.params.SHOW_ORDER_BUTTON === 'final_step'
			)
			{
				this.switchOrderSaveButtons(this.shouldBeSectionVisible(sections, sections.length - 1));
			}
		},

		changeVisibleSection: function(section, state)
		{
			var titleNode, content, editStep;

			if (section.id !== this.basketBlockNode.id)
			{
				content = section.querySelector('.bx-soa-section-content');
				if (content)
					content.style.display = state ? '' : 'none';
			}

			editStep = section.querySelector('.bx-soa-editstep');
			if (editStep)
				editStep.style.display = state ? '' : 'none';

			titleNode = section.querySelector('.bx-soa-section-title-container');
			if (titleNode && !state)
				BX.unbindAll(titleNode);
		},

		clearHiddenBlocks: function()
		{
			BX.remove(BX.lastChild(this.authHiddenBlockNode));
			BX.remove(BX.lastChild(this.basketHiddenBlockNode));
			BX.remove(BX.lastChild(this.regionHiddenBlockNode));
			BX.remove(BX.lastChild(this.paySystemHiddenBlockNode));
			BX.remove(BX.lastChild(this.deliveryHiddenBlockNode));
			BX.remove(BX.lastChild(this.pickUpHiddenBlockNode));
			BX.remove(BX.lastChild(this.propsHiddenBlockNode));
		},

		/**
		 * Edit order block nodes with this.result/this.params data
		 */
		editOrder: function()
		{
			if (!this.orderBlockNode || !this.result)
				return;

			if (this.result.DELIVERY.length > 0)
			{
				BX.addClass(this.deliveryBlockNode, 'bx-active');
				this.deliveryBlockNode.removeAttribute('style');
			}
			else
			{
				BX.removeClass(this.deliveryBlockNode, 'bx-active');
				this.deliveryBlockNode.style.display = 'none';
			}

			this.orderSaveBlockNode.style.display = this.result.SHOW_AUTH ? 'none' : '';
			this.mobileTotalBlockNode.style.display = this.result.SHOW_AUTH ? 'none' : '';

			this.checkPickUpShow();

			var sections = this.orderBlockNode.querySelectorAll('.bx-soa-section.bx-active'), i;
			for (i in sections)
			{
				if (sections.hasOwnProperty(i))
				{
					this.editSection(sections[i]);
				}
			}

			this.editTotalBlock();
			this.totalBlockFixFont();

			this.showErrors(this.result.ERROR, false);
			this.showWarnings();
		},

		/**
		 * Edit certain block node
		 */
		editSection: function(section)
		{
			if (!section || !section.id)
				return;

			if (this.result.SHOW_AUTH && section.id != this.authBlockNode.id && section.id != this.basketBlockNode.id)
				section.style.display = 'none';
			else if (section.id != this.pickUpBlockNode.id)
				section.style.display = '';

			var active = section.id == this.activeSectionId,
				titleNode = section.querySelector('.bx-soa-section-title-container'),
				editButton, errorContainer;

			BX.unbindAll(titleNode);
			if (this.result.SHOW_AUTH)
			{
				BX.bind(titleNode, 'click', BX.delegate(function(){
					this.animateScrollTo(this.authBlockNode);
					this.addAnimationEffect(this.authBlockNode, 'bx-step-good');
				}, this));
			}
			else
			{
				BX.bind(titleNode, 'click', BX.proxy(this.showByClick, this));
				editButton = titleNode.querySelector('.bx-soa-editstep');
				editButton && BX.bind(editButton, 'click', BX.proxy(this.showByClick, this));
			}

			errorContainer = section.querySelector('.alert.alert-danger');
			this.hasErrorSection[section.id] = errorContainer && errorContainer.style.display != 'none';

			switch (section.id)
			{
				case this.authBlockNode.id:
					this.editAuthBlock();
					break;
				case this.basketBlockNode.id:
					this.editBasketBlock(active);
					break;
				case this.regionBlockNode.id:
					this.editRegionBlock(active);
					break;
				case this.paySystemBlockNode.id:
					this.editPaySystemBlock(active);
					break;
				case this.deliveryBlockNode.id:
					this.editDeliveryBlock(active);
					break;
				case this.pickUpBlockNode.id:
					this.editPickUpBlock(active);
					break;
				case this.propsBlockNode.id:
					this.editPropsBlock(active);
					break;
			}

			if (active)
				section.setAttribute('data-visited', 'true');
		},

		editAuthBlock: function()
		{
			if (!this.authBlockNode)
				return;

			var authContent = this.authBlockNode.querySelector('.bx-soa-section-content'),
				regContent, okMessageNode;

			if (BX.hasClass(authContent, 'reg'))
			{
				regContent = authContent;
				authContent = BX.firstChild(this.authHiddenBlockNode);
			}
			else
				regContent = BX.firstChild(this.authHiddenBlockNode);

			BX.cleanNode(authContent);
			BX.cleanNode(regContent);

			if (this.result.SHOW_AUTH)
			{
				this.getErrorContainer(authContent);
				this.editAuthorizeForm(authContent);
				this.editSocialContent(authContent);
				this.getAuthReference(authContent);

				this.getErrorContainer(regContent);
				this.editRegistrationForm(regContent);
				this.getAuthReference(regContent);
			}
			else
			{
				BX.onCustomEvent('OnBasketChange');
				this.closeAuthBlock();
			}

			if (this.result.OK_MESSAGE && this.result.OK_MESSAGE.length)
			{
				this.toggleAuthForm({target: this.authBlockNode.querySelector('input[type=submit]')});
				okMessageNode = BX.create('DIV', {
					props: {className: 'alert alert-success'},
					text: this.result.OK_MESSAGE.join()
				});
				this.result.OK_MESSAGE = '';
				BX.prepend(okMessageNode, this.authBlockNode.querySelector('.bx-soa-section-content'));
			}
		},

		editAuthorizeForm: function(authContent)
		{
			var login, password, remember, button, authFormNode;

			login = this.createAuthFormInputContainer(
				BX.message('STOF_LOGIN'),
				BX.create('INPUT', {
					attrs: {'data-next': 'USER_PASSWORD'},
					props: {
						className: "form-control",
						name: 'USER_LOGIN',
						type: 'text',
						value: this.result.AUTH.USER_LOGIN,
						maxlength: "30"
					},
					events: {keypress: BX.proxy(this.checkKeyPress, this)}
				})
			);

			password = this.createAuthFormInputContainer(
				BX.message('STOF_PASSWORD'),
				BX.create('INPUT', {
					attrs: {'data-send': true},
					props: {
						className: "form-control",
						name: 'USER_PASSWORD',
						type: 'password',
						value: '',
						maxlength: "30"
					},
					events: {keypress: BX.proxy(this.checkKeyPress, this)}
				})
			);

			remember = BX.create('DIV', {
				props: {className: 'form-check mb-3'},
				children: [
					BX.create('INPUT', {
						props: {
							className: "form-check-input",
							id: "authRememberCheckbox",
							type: 'checkbox',
							name: 'USER_REMEMBER',
							value: 'Y'
						}
					}),
					BX.create('LABEL', {
						attrs: {
							className: 'form-check-label',
							for: "authRememberCheckbox"
						},
						text: BX.message('STOF_REMEMBER')
					})
				]
			});

			button = BX.create('DIV', {
				children: [
					BX.create('INPUT', {
						props: {
							id: 'do_authorize',
							type: 'hidden',
							name: 'do_authorize',
							value: 'N'
						}
					}),
					BX.create('INPUT', {
						props: {
							type: 'submit',
							className: 'btn btn-lg btn-primary',
							value: BX.message('STOF_ENTER')
						},
						events: {
							click: BX.delegate(function(e){
								BX('do_authorize').value = 'Y';
								this.sendRequest('showAuthForm');
								return BX.PreventDefault(e);
							}, this)
						}
					}),
					BX.create('A', {
						props: {
							className: "btn btn-link",
							href: this.params.PATH_TO_AUTH + '?forgot_password=yes&back_url=' + encodeURIComponent(document.location.href)
						},
						text: BX.message('STOF_FORGET_PASSWORD')
					})
				]
			});

			authFormNode = BX.create('DIV', {
				props: {className: 'bx-authform'},
				children: [
					BX.create('H3', {props: {className: 'bx-title'}, text: BX.message('STOF_AUTH_REQUEST')}),
					login,
					password,
					remember,
					button
				]
			});

			authContent.appendChild(BX.create('DIV', {props: {className: 'col-md-6'}, children: [authFormNode]}));
		},

		createAuthFormInputContainer: function(labelText, inputNode, required)
		{
			if (required)
				labelText += '<span class="bx-authform-starrequired">*</span>';

			var labelHtml = BX.create('LABEL', {
				children: [
					labelText
				]
			});

			return BX.create('DIV', {
				props: {className: 'form-group mb-3'},
				children: [
					labelHtml,
					inputNode
				]
			});
		},

		activatePhoneAuth: function()
		{
			if (!this.result.SMS_AUTH)
				return;

			new BX.PhoneAuth({
				containerId: 'bx_register_resend',
				errorContainerId: 'bx_register_error',
				interval: 60,
				data: {
					signedData: this.result.SMS_AUTH.SIGNED_DATA
				},
				onError: function(response)
				{
					var errorDiv = BX('bx_register_error');
					var errorNode = BX.findChildByClassName(errorDiv, 'errortext');
					errorNode.innerHTML = '';

					for (var i = 0; i < response.errors.length; i++)
					{
						errorNode.innerHTML = errorNode.innerHTML + BX.util.htmlspecialchars(response.errors[i].message) + '<br>';
					}

					errorDiv.style.display = '';
				}
			});
		},

		editRegistrationForm: function(authContent)
		{
			if (!this.result.AUTH)
				return;

			var authFormNodes = [];
			var showSmsConfirm = this.result.SMS_AUTH && this.result.SMS_AUTH.TYPE === 'OK';

			if (showSmsConfirm)
			{
				authFormNodes.push(BX.create('DIV', {
					props: {className: 'alert alert-success'},
					text: BX.message('STOF_REG_SMS_REQUEST')
				}));
				authFormNodes.push(BX.create('INPUT', {
					props: {
						type: 'hidden',
						name: 'SIGNED_DATA',
						value: this.result.SMS_AUTH.SIGNED_DATA || ''
					}
				}));
				authFormNodes.push(this.createAuthFormInputContainer(
					BX.message('STOF_SMS_CODE'),
					BX.create('INPUT', {
						attrs: {'data-send': true},
						props: {
							name: 'SMS_CODE',
							type: 'text',
							size: 40,
							value: ''
						},
						events: {keypress: BX.proxy(this.checkKeyPress, this)}
					}),
					true
				));
				authFormNodes.push(BX.create('DIV', {
					props: {className: 'bx-authform-formgroup-container'},
					children: [
						BX.create('INPUT', {
							props: {
								name: 'code_submit_button',
								type: 'submit',
								className: 'btn btn-lg btn-default',
								value: BX.message('STOF_SEND')
							},
							events: {
								click: BX.delegate(function(e)
								{
									this.sendRequest('confirmSmsCode');
									return BX.PreventDefault(e);
								}, this)
							}
						})
					]
				}));
				authFormNodes.push(BX.create('DIV', {
					props: {className: 'bx-authform-formgroup-container'},
					children: [
						BX.create('DIV', {
							props: {id: 'bx_register_error'},
							style: {display: 'none'}
						}),
						BX.create('DIV', {
							props: {id: 'bx_register_resend'}
						})
					]
				}));
			}
			else
			{
				authFormNodes.push(BX.create('H3', {
					props: {className: 'bx-title'},
					text: BX.message('STOF_REG_REQUEST')
				}));
				authFormNodes.push(this.createAuthFormInputContainer(
					BX.message('STOF_NAME'),
					BX.create('INPUT', {
						attrs: {'data-next': 'NEW_LAST_NAME'},
						props: {
							name: 'NEW_NAME',
							type: 'text',
							size: 40,
							value: this.result.AUTH.NEW_NAME || ''
						},
						events: {keypress: BX.proxy(this.checkKeyPress, this)}
					}),
					true
				));
				authFormNodes.push(this.createAuthFormInputContainer(
					BX.message('STOF_LASTNAME'),
					BX.create('INPUT', {
						attrs: {'data-next': 'NEW_EMAIL'},
						props: {
							name: 'NEW_LAST_NAME',
							type: 'text',
							size: 40,
							value: this.result.AUTH.NEW_LAST_NAME || ''
						},
						events: {keypress: BX.proxy(this.checkKeyPress, this)}
					}),
					true
				));
				authFormNodes.push(this.createAuthFormInputContainer(
					BX.message('STOF_EMAIL'),
					BX.create('INPUT', {
						attrs: {'data-next': 'PHONE_NUMBER'},
						props: {
							name: 'NEW_EMAIL',
							type: 'text',
							size: 40,
							value: this.result.AUTH.NEW_EMAIL || ''
						},
						events: {keypress: BX.proxy(this.checkKeyPress, this)}
					}),
					this.result.AUTH.new_user_email_required == 'Y'
				));

				if (this.result.AUTH.new_user_phone_auth === 'Y')
				{
					authFormNodes.push(this.createAuthFormInputContainer(
						BX.message('STOF_PHONE'),
						BX.create('INPUT', {
							attrs: {'data-next': 'captcha_word'},
							props: {
								name: 'PHONE_NUMBER',
								type: 'text',
								size: 40,
								value: this.result.AUTH.PHONE_NUMBER || ''
							},
							events: {keypress: BX.proxy(this.checkKeyPress, this)}
						}),
						this.result.AUTH.new_user_phone_required === 'Y'
					));
				}

				if (this.authGenerateUser)
				{
					authFormNodes.push(
						BX.create('LABEL', {
							props: {for: 'NEW_GENERATE_N'},
							children: [
								BX.create('INPUT', {
									attrs: {checked: !this.authGenerateUser},
									props: {
										id: 'NEW_GENERATE_N',
										type: 'radio',
										name: 'NEW_GENERATE',
										value: 'N'
									}
								}),
								BX.message('STOF_MY_PASSWORD')
							],
							events: {
								change: BX.delegate(function(){
									var generated = this.authBlockNode.querySelector('.generated');
									generated.style.display = '';
									this.authGenerateUser = false;
								}, this)
							}
						})
					);
					authFormNodes.push(BX.create('BR'));
					authFormNodes.push(
						BX.create('LABEL', {
							props: {for: 'NEW_GENERATE_Y'},
							children: [
								BX.create('INPUT', {
									attrs: {checked: this.authGenerateUser},
									props: {
										id: 'NEW_GENERATE_Y',
										type: 'radio',
										name: 'NEW_GENERATE',
										value: 'Y'
									}
								}),
								BX.message('STOF_SYS_PASSWORD')
							],
							events: {
								change: BX.delegate(function(){
									var generated = this.authBlockNode.querySelector('.generated');
									generated.style.display = 'none';
									this.authGenerateUser = true;
								}, this)
							}
						})
					);
				}

				authFormNodes.push(
					BX.create('DIV', {
						props: {className: 'generated'},
						style: {display: this.authGenerateUser ? 'none' : ''},
						children: [
							this.createAuthFormInputContainer(
								BX.message('STOF_LOGIN'),
								BX.create('INPUT', {
									props: {
										name: 'NEW_LOGIN',
										type: 'text',
										size: 30,
										value: this.result.AUTH.NEW_LOGIN || ''
									},
									events: {
										keypress: BX.proxy(this.checkKeyPress, this)
									}
								}),
								true
							),
							this.createAuthFormInputContainer(
								BX.message('STOF_PASSWORD'),
								BX.create('INPUT', {
									props: {
										name: 'NEW_PASSWORD',
										type: 'password',
										size: 30
									},
									events: {
										keypress: BX.proxy(this.checkKeyPress, this)
									}
								}),
								true
							),
							this.createAuthFormInputContainer(
								BX.message('STOF_RE_PASSWORD'),
								BX.create('INPUT', {
									props: {
										name: 'NEW_PASSWORD_CONFIRM',
										type: 'password',
										size: 30
									},
									events: {
										keypress: BX.proxy(this.checkKeyPress, this)
									}
								}),
								true
							)
						]
					})
				);

				if (this.result.AUTH.captcha_registration == 'Y')
				{
					authFormNodes.push(BX.create('DIV', {
						props: {className: 'bx-authform-formgroup-container'},
						children: [
							BX.create('DIV', {
								props: {className: 'bx-authform-label-container'},
								children: [
									BX.create('SPAN', {props: {className: 'bx-authform-starrequired'}, text: '*'}),
									BX.message('CAPTCHA_REGF_PROMT'),
									BX.create('DIV', {
										props: {className: 'bx-captcha'},
										children: [
											BX.create('INPUT', {
												props: {
													name: 'captcha_sid',
													type: 'hidden',
													value: this.result.AUTH.capCode || ''
												}
											}),
											BX.create('IMG', {
												props: {
													src: '/bitrix/tools/captcha.php?captcha_sid=' + this.result.AUTH.capCode,
													alt: ''
												}
											})
										]
									})
								]
							}),
							BX.create('DIV', {
								props: {className: 'bx-authform-input-container'},
								children: [
									BX.create('INPUT', {
										attrs: {'data-send': true},
										props: {
											name: 'captcha_word',
											type: 'text',
											size: '30',
											maxlength: '50',
											value: ''
										},
										events: {keypress: BX.proxy(this.checkKeyPress, this)}
									})
								]
							})
						]
					}));
				}
				authFormNodes.push(
					BX.create('DIV', {
						props: {className: 'bx-authform-formgroup-container'},
						children: [
							BX.create('INPUT', {
								props: {
									id: 'do_register',
									name: 'do_register',
									type: 'hidden',
									value: 'N'
								}
							}),
							BX.create('INPUT', {
								props: {
									type: 'submit',
									className: 'btn btn-lg btn-default',
									value: BX.message('STOF_REGISTER')
								},
								events: {
									click: BX.delegate(function(e){
										BX('do_register').value = 'Y';
										this.sendRequest('showAuthForm');
										return BX.PreventDefault(e);
									}, this)
								}
							}),
							BX.create('A', {
								props: {className: 'btn btn-link', href: ''},
								text: BX.message('STOF_DO_AUTHORIZE'),
								events: {
									click: BX.delegate(function(e){
										this.toggleAuthForm(e);
										return BX.PreventDefault(e);
									}, this)
								}
							})
						]
					})
				);
			}

			authContent.appendChild(
				BX.create('DIV', {
					props: {className: 'col-md-12'},
					children: [BX.create('DIV', {props: {className: 'bx-authform'}, children: authFormNodes})]
				})
			);

			if (showSmsConfirm)
			{
				this.activatePhoneAuth();
			}
		},

		editSocialContent: function(authContent)
		{
			if (!BX('bx-soa-soc-auth-services'))
				return;

			var nodes = [];

			if (this.socServiceHiddenNode === false)
			{
				var socServiceHiddenNode = BX('bx-soa-soc-auth-services').querySelector('.bx-authform-social');

				if (BX.type.isDomNode(socServiceHiddenNode))
				{
					this.socServiceHiddenNode = socServiceHiddenNode.innerHTML;
					BX.remove(socServiceHiddenNode);
				}
			}

			if (this.socServiceHiddenNode)
			{
				nodes.push(BX.create('DIV', {
					props: {className: 'bx-authform-social'},
					html: '<h3 class="bx-title">' + BX.message('SOA_DO_SOC_SERV') + '</h3>' + this.socServiceHiddenNode
				}));
				nodes.push(BX.create('hr', {props: {className: 'bxe-light'}}));
			}

			if (this.result.AUTH.new_user_registration === 'Y')
			{
				nodes.push(BX.create('DIV', {
					props: {className: 'bx-soa-reg-block'},
					children: [
						BX.create('P', {html: this.params.MESS_REGISTRATION_REFERENCE}),
						BX.create('A', {
							props: {className: 'btn btn-primary btn-lg'},
							text: BX.message('STOF_DO_REGISTER'),
							events: {
								click: BX.delegate(function(e){
									this.toggleAuthForm(e);
									return BX.PreventDefault(e);
								}, this)
							}
						})
					]
				}));
			}

			authContent.appendChild(BX.create('DIV', {props: {className: 'col-md-6'}, children: nodes}));
		},

		getAuthReference: function(authContent)
		{
			authContent.appendChild(
				BX.create('DIV', {
					props: {className: 'row'},
					children: [
						BX.create('DIV', {
							props: {className: 'bx-soa-reference col'},
							children: [
								this.params.MESS_AUTH_REFERENCE_1,
								BX.create('BR'),
								this.params.MESS_AUTH_REFERENCE_2,
								BX.create('BR'),
								this.params.MESS_AUTH_REFERENCE_3
							]
						})
					]
				})
			);
		},

		toggleAuthForm: function(event)
		{
			if (!event)
				return;

			var target = event.target || event.srcElement,
				section = BX.findParent(target, {className: 'bx-soa-section'}),
				container = BX.findParent(target, {className: 'bx-soa-section-content'}),
				insertContainer = BX.firstChild(this.authHiddenBlockNode);

			new BX.easing({
				duration: 100,
				start: {opacity: 100},
				finish: {opacity: 0},
				transition: BX.easing.makeEaseOut(BX.easing.transitions.quad),
				step: function(state){
					container.style.opacity = state.opacity / 100;
				}
			}).animate();

			this.authHiddenBlockNode.appendChild(container);
			BX.cleanNode(section);
			section.appendChild(
				BX.create('DIV', {
					props: {className: 'bx-soa-section-title-container'},
					children: [
						BX.create('div', {
							attrs: {'data-entity': 'section-title'},
							props: {className: 'bx-soa-section-title'},
							html: BX.hasClass(insertContainer, 'reg') ? this.params.MESS_REG_BLOCK_NAME : this.params.MESS_AUTH_BLOCK_NAME
						})
					]
				})
			);
			insertContainer.style.opacity = 0;
			section.appendChild(insertContainer);

			setTimeout(function(){
				new BX.easing({
					duration: 100,
					start: {opacity: 0},
					finish: {opacity: 100},
					transition: BX.easing.makeEaseOut(BX.easing.transitions.quart),
					step: function(state){
						insertContainer.style.opacity = state.opacity / 100;
					},
					complete: function() {
						insertContainer.style.height = '';
						insertContainer.style.opacity = '';
					}
				}).animate();
			}, 110);

			this.animateScrollTo(section);
		},

		alignBasketColumns: function()
		{
			if (!this.basketBlockNode)
				return;

			var i = 0, k, columns = 0, columnNodes,
				windowSize = BX.GetWindowInnerSize(),
				basketRows, percent;

			if (windowSize.innerWidth > 580 && windowSize.innerWidth < 992)
			{
				basketRows = this.basketBlockNode.querySelectorAll('.bx-soa-basket-info');
				percent = 100;

				if (basketRows.length)
				{
					columnNodes = basketRows[0].querySelectorAll('.bx-soa-item-properties');

					if (columnNodes.length && columnNodes[0].style.width != '')
						return;

					columns = columnNodes.length;
					if (columns > 0)
					{
						columns = columns > 4 ? 4 : columns;
						percent = parseInt(percent / columns);
						for (; i < basketRows.length; i++)
						{
							columnNodes = basketRows[i].querySelectorAll('.bx-soa-item-properties')
							for (k = 0; k < columnNodes.length; k++)
							{
								columnNodes[k].style.width = percent + '%';
							}
						}
					}
				}
			}
			else
			{
				columnNodes = this.basketBlockNode.querySelectorAll('.bx-soa-item-properties');

				if (columnNodes.length && columnNodes[0].style.width == '')
					return;

				for (; i < columnNodes.length; i++)
				{
					columnNodes[i].style.width = '';
				}
			}
		},

		editBasketBlock: function(active)
		{
			if (!this.basketBlockNode || !this.basketHiddenBlockNode || !this.result.GRID)
				return;

			BX.remove(BX.lastChild(this.basketBlockNode));
			BX.remove(BX.lastChild(this.basketHiddenBlockNode));

			this.editActiveBasketBlock(active);
			this.editFadeBasketBlock(active);

			this.initialized.basket = true;
		},

		editActiveBasketBlock: function(activeNodeMode)
		{
			var node = !!activeNodeMode ? this.basketBlockNode : this.basketHiddenBlockNode,
				basketContent, basketTable;

			if (this.initialized.basket)
			{
				this.basketHiddenBlockNode.appendChild(BX.lastChild(node));
				node.appendChild(BX.firstChild(this.basketHiddenBlockNode));
			}
			else
			{
				basketContent = node.querySelector('.bx-soa-section-content');
				basketTable = BX.create('DIV', {props: {className: 'bx-soa-item-table'}});

				if (!basketContent)
				{
					basketContent = this.getNewContainer();
					node.appendChild(basketContent);
				}
				else
				{
					BX.cleanNode(basketContent);
				}

				this.editBasketItems(basketTable, true);

				basketContent.appendChild(
					BX.create('DIV', {
						props: {className: 'bx-soa-table-fade'},
						children: [
							BX.create('DIV', {
								style: {overflowX: 'auto', overflowY: 'hidden'},
								children: [basketTable]
							})
						]
					})
				);

				if (this.params.SHOW_COUPONS_BASKET === 'Y')
				{
					this.editCoupons(basketContent);
				}

				this.getBlockFooter(basketContent);

				BX.bind(
					basketContent.querySelector('div.bx-soa-table-fade').firstChild,
					'scroll',
					BX.proxy(this.basketBlockScrollCheckEvent, this)
				);
			}

			this.alignBasketColumns();
		},

		editFadeBasketBlock: function(activeNodeMode)
		{
			var node = !!activeNodeMode ? this.basketHiddenBlockNode : this.basketBlockNode,
				newContent, basketTable;

			if (this.initialized.basket)
			{
				this.basketHiddenBlockNode.appendChild(node.querySelector('.bx-soa-section-content'));
				this.basketBlockNode.appendChild(BX.firstChild(this.basketHiddenBlockNode));
			}
			else
			{
				newContent = this.getNewContainer();
				basketTable = BX.create('DIV', {props: {className: 'bx-soa-item-table'}});

				this.editBasketItems(basketTable, false);

				newContent.appendChild(
					BX.create('DIV', {
						props: {className: 'bx-soa-table-fade'},
						children: [
							BX.create('DIV', {
								style: {overflowX: 'auto', overflowY: 'hidden'},
								children: [basketTable]
							})
						]
					})
				);

				if (this.params.SHOW_COUPONS_BASKET === 'Y')
				{
					this.editCouponsFade(newContent);
				}

				node.appendChild(newContent);
				this.alignBasketColumns();
				this.basketBlockScrollCheck();

				BX.bind(
					this.basketBlockNode.querySelector('div.bx-soa-table-fade').firstChild,
					'scroll',
					BX.proxy(this.basketBlockScrollCheckEvent, this)
				);
			}

			this.alignBasketColumns();
		},

		editBasketItems: function(basketItemsNode, active)
		{
			if (!this.result.GRID.ROWS)
				return;

			var index = 0, i;

			if (this.params.SHOW_BASKET_HEADERS === 'Y')
			{
				this.editBasketItemsHeader(basketItemsNode);
			}

			for (i in this.result.GRID.ROWS)
			{
				if (this.result.GRID.ROWS.hasOwnProperty(i))
				{
					this.createBasketItem(basketItemsNode, this.result.GRID.ROWS[i], index++, !!active);
				}
			}
		},

		editBasketItemsHeader: function(basketItemsNode)
		{
			if (!basketItemsNode)
				return;

			var headers = [
					BX.create('DIV', {
						props: {className: 'bx-soa-item-td'},
						style: {paddingBottom: '5px'},
						children: [
							BX.create('DIV', {
								props: {className: 'bx-soa-item-td-title'},
								text: BX.message('SOA_SUM_NAME')
							})
						]
					})
				],
				toRight = false, column, basketColumnIndex = 0, i;

			for (i = 0; i < this.result.GRID.HEADERS.length; i++)
			{
				column = this.result.GRID.HEADERS[i];

				if (column.id === 'NAME' || column.id === 'PREVIEW_PICTURE' || column.id === 'PROPS' || column.id === 'NOTES')
					continue;

				if (column.id === 'DETAIL_PICTURE' && !this.options.showPreviewPicInBasket)
					continue;

				toRight = BX.util.in_array(column.id, ["QUANTITY", "PRICE_FORMATED", "DISCOUNT_PRICE_PERCENT_FORMATED", "SUM"]);
				headers.push(
					BX.create('DIV', {
						props: {className: 'bx-soa-item-td bx-soa-item-properties' + (toRight ? ' bx-text-right' : '')},
						style: {paddingBottom: '5px'},
						children: [
							BX.create('DIV', {
								props: {className: 'bx-soa-item-td-title'},
								text: column.name
							})
						]
					})
				);

				++basketColumnIndex;
				if (basketColumnIndex == 4 && this.result.GRID.HEADERS[i + 1])
				{
					headers.push(BX.create('DIV', {props: {className: 'bx-soa-item-nth-4p1'}}));
					basketColumnIndex = 0;
				}
			}

			basketItemsNode.appendChild(
				BX.create('DIV', {
					props: {className: 'bx-soa-item-tr d-none d-md-table-row'},
					children: headers
				})
			);
		},

		createBasketItem: function(basketItemsNode, item, index, active)
		{
			var mainColumns = [],
				otherColumns = [],
				hiddenColumns = [],
				currentColumn, basketColumnIndex = 0,
				i, tr, cols;

			if (this.options.showPreviewPicInBasket || this.options.showDetailPicInBasket)
				mainColumns.push(this.createBasketItemImg(item.data));

			mainColumns.push(this.createBasketItemContent(item.data));

			for (i = 0; i < this.result.GRID.HEADERS.length; i++)
			{
				currentColumn = this.result.GRID.HEADERS[i];

				if (currentColumn.id === 'NAME' || currentColumn.id === 'PREVIEW_PICTURE' || currentColumn.id === 'PROPS' || currentColumn.id === 'NOTES')
					continue;

				if (currentColumn.id === 'DETAIL_PICTURE' && !this.options.showPreviewPicInBasket)
					continue;

				otherColumns.push(this.createBasketItemColumn(currentColumn, item, active));

				++basketColumnIndex;
				if (basketColumnIndex == 4 && this.result.GRID.HEADERS[i + 1])
				{
					otherColumns.push(BX.create('DIV', {props: {className: 'bx-soa-item-nth-4p1'}}));
					basketColumnIndex = 0;
				}
			}

			if (active)
			{
				for (i = 0; i < this.result.GRID.HEADERS_HIDDEN.length; i++)
				{
					tr = this.createBasketItemHiddenColumn(this.result.GRID.HEADERS_HIDDEN[i], item);
					if (BX.type.isArray(tr))
						hiddenColumns = hiddenColumns.concat(tr);
					else if (tr)
						hiddenColumns.push(tr);
				}
			}

			cols = [
				BX.create('DIV', {
					props: {className: 'bx-soa-item-td'},
					style: {minWidth: '255px'},
					children: [
						BX.create('DIV', {
							props: {className: 'bx-soa-item-block'},
							children: mainColumns
						})
					]
				})
			].concat(otherColumns);

			basketItemsNode.appendChild(
				BX.create('DIV', {
					props: {className: 'bx-soa-item-tr bx-soa-basket-info' + (index == 0 ? ' bx-soa-item-tr-first' : '')},
					children: cols
				})
			);

			if (hiddenColumns.length)
			{
				basketItemsNode.appendChild(
					BX.create('DIV', {
						props: {className: 'bx-soa-item-tr bx-soa-item-info-container'},
						children: [
							BX.create('DIV', {
								props: {className: 'bx-soa-item-td'},
								children: [
									BX.create('A', {
										props: {href: '', className: 'bx-soa-info-shower'},
										html: this.params.MESS_ADDITIONAL_PROPS,
										events: {
											click: BX.proxy(this.showAdditionalProperties, this)
										}
									}),
									BX.create('DIV', {
										props: {className: 'bx-soa-item-info-block'},
										children: [
											BX.create('TABLE', {
												props: {className: 'bx-soa-info-block'},
												children: hiddenColumns
											})
										]
									})
								]
							})
						]
					})
				);
			}
		},

		showAdditionalProperties: function(event)
		{
			var target = event.target || event.srcElement,
				infoContainer = target.nextSibling,
				parentContainer = BX.findParent(target, {className: 'bx-soa-item-tr bx-soa-item-info-container'}),
				parentHeight = parentContainer.offsetHeight;

			if (BX.hasClass(infoContainer, 'bx-active'))
			{
				new BX.easing({
					duration: 300,
					start: {opacity: 100, height: parentHeight},
					finish: {opacity: 0, height: 35},
					transition: BX.easing.makeEaseOut(BX.easing.transitions.quad),
					step: function(state){
						infoContainer.style.opacity = state.opacity / 100;
						infoContainer.style.height = state.height + 'px';
						parentContainer.style.height = state.height + 'px';
					},
					complete: function(){
						BX.removeClass(infoContainer, 'bx-active');
						infoContainer.removeAttribute("style");
						parentContainer.removeAttribute("style");
					}
				}).animate();
			}
			else
			{
				infoContainer.style.opacity = 0;
				BX.addClass(infoContainer, 'bx-active');
				var height = infoContainer.offsetHeight + parentHeight;
				BX.removeClass(infoContainer, 'bx-active');
				infoContainer.style.paddingTop = '10px';

				new BX.easing({
					duration: 300,
					start: {opacity: 0, height: parentHeight},
					finish: {opacity: 100, height: height},
					transition: BX.easing.makeEaseOut(BX.easing.transitions.quad),
					step: function(state){
						infoContainer.style.opacity = state.opacity / 100;
						infoContainer.style.height = state.height + 'px';
						parentContainer.style.height = state.height + 'px';
					},
					complete: function(){
						BX.addClass(infoContainer, 'bx-active');
						infoContainer.removeAttribute("style");
					}
				}).animate();
			}

			return BX.PreventDefault(event);
		},

		createBasketItemImg: function(data)
		{
			if (!data)
				return;

			var logoNode, logotype;

			logoNode = BX.create('DIV', {props: {className: 'bx-soa-item-imgcontainer'}});

			if (data.PREVIEW_PICTURE_SRC && data.PREVIEW_PICTURE_SRC.length)
				logotype = this.getImageSources(data, 'PREVIEW_PICTURE');
			else if (data.DETAIL_PICTURE_SRC && data.DETAIL_PICTURE_SRC.length)
				logotype = this.getImageSources(data, 'DETAIL_PICTURE');

			if (logotype && logotype.src_2x)
			{
				logoNode.setAttribute('style',
					'background-image: url("' + logotype.src_1x + '");' +
					'background-image: -webkit-image-set(url("' + logotype.src_1x + '") 1x, url("' + logotype.src_2x + '") 2x)'
				);
			}
			else
			{
				logotype = logotype && logotype.src_1x || this.defaultBasketItemLogo;
				logoNode.setAttribute('style', 'background-image: url("' + logotype + '");');
			}

			if (this.params.HIDE_DETAIL_PAGE_URL !== 'Y' && data.DETAIL_PAGE_URL && data.DETAIL_PAGE_URL.length)
			{
				logoNode = BX.create('A', {
					props: {href: data.DETAIL_PAGE_URL},
					children: [logoNode]
				});
			}

			return BX.create('DIV', {
				props: {className: 'bx-soa-item-img-block'},
				children: [logoNode]
			});
		},

		createBasketItemContent: function(data)
		{
			var itemName = data.NAME || '',
				titleHtml = this.htmlspecialcharsEx(itemName),
				props = data.PROPS || [],
				propsNodes = [];

			if (this.params.HIDE_DETAIL_PAGE_URL !== 'Y' && data.DETAIL_PAGE_URL && data.DETAIL_PAGE_URL.length)
			{
				titleHtml = '<a href="' + data.DETAIL_PAGE_URL + '">' + titleHtml + '</a>';
			}

			if (this.options.showPropsInBasket && props.length)
			{
				for (var i in props)
				{
					if (props.hasOwnProperty(i))
					{
						var propCode = (props[i].CODE || props[i].NAME || ''),
							name = props[i].NAME || '',
							value = props[i].VALUE || '';

						// Hide internal basket properties used for store selection.
						if (
							propCode === 'MF_STORE_ID'
							|| propCode === 'MF_STORE_TITLE'
							|| propCode === 'MF_STORE_CODE'
							|| propCode.indexOf('MF_STORE_') === 0
						)
						{
							continue;
						}

						propsNodes.push(
							BX.create('DIV', {
								props: {className: 'bx-soa-item-td-title'},
								style: {textAlign: 'left'},
								text: name
							})
						);
						propsNodes.push(
							BX.create('DIV', {
								props: {className: 'bx-soa-item-td-text'},
								style: {textAlign: 'left'},
								text: value
							})
						);
					}
				}
			}

			return BX.create('DIV', {
				props: {className: 'bx-soa-item-content'},
				children: propsNodes.length ? [
					BX.create('DIV', {props: {className: 'bx-soa-item-title'}, html: titleHtml}),
					BX.create('DIV', {props: {className: 'bx-scu-container'}, children: propsNodes})
				] : [
					BX.create('DIV', {props: {className: 'bx-soa-item-title'}, html: titleHtml})
				]
			});
		},

		createBasketItemColumn: function(column, allData, active)
		{
			if (!column || !allData)
				return;

			var data = allData.columns[column.id] ? allData.columns : allData.data,
				toRight = BX.util.in_array(column.id, ["QUANTITY", "PRICE_FORMATED", "DISCOUNT_PRICE_PERCENT_FORMATED", "SUM"]),
				textNode = BX.create('DIV', {props: {className: 'bx-soa-item-td-text'}}),
				logotype, img;

			if (column.id === 'PRICE_FORMATED')
			{
				textNode.appendChild(BX.create('STRONG', {props: {className: 'bx-price'}, html: data.PRICE_FORMATED}));
				if (parseFloat(data.DISCOUNT_PRICE) > 0)
				{
					textNode.appendChild(BX.create('BR'));
					textNode.appendChild(BX.create('STRONG', {
						props: {className: 'bx-price-old'},
						html: data.BASE_PRICE_FORMATED
					}));
				}

				if (this.options.showPriceNotesInBasket && active)
				{
					textNode.appendChild(BX.create('BR'));
					textNode.appendChild(BX.create('SMALL', {text: data.NOTES}));
				}
			}
			else if (column.id === 'SUM')
			{
				textNode.appendChild(BX.create('STRONG', {props: {className: 'bx-price all'}, html: data.SUM}));
				if (parseFloat(data.DISCOUNT_PRICE) > 0)
				{
					textNode.appendChild(BX.create('BR'));
					textNode.appendChild(BX.create('STRONG', {
						props: {className: 'bx-price-old'},
						html: data.SUM_BASE_FORMATED
					}));
				}
			}
			else if (column.id === 'DISCOUNT')
			{
				textNode.appendChild(BX.create('STRONG', {props: {className: 'bx-price'}, text: data.DISCOUNT_PRICE_PERCENT_FORMATED}));
			}
			else if (column.id === 'DETAIL_PICTURE')
			{
				logotype = this.getImageSources(allData.data, column.id),
				img = BX.create('IMG', {props: {src: logotype && logotype.src_1x || this.defaultBasketItemLogo}});

				if (logotype && logotype.src_1x && logotype.src_orig)
				{
					BX.bind(img, 'click', BX.delegate(function(e){this.popupShow(e, logotype.src_orig);}, this));
				}

				textNode.appendChild(img);
			}
			else if (BX.util.in_array(column.id, ["QUANTITY", "WEIGHT_FORMATED", "DISCOUNT_PRICE_PERCENT_FORMATED"]))
			{
				textNode.appendChild(BX.create('SPAN', {html: data[column.id]}));
			}
			else if (column.id === 'PREVIEW_TEXT')
			{
				if (data['PREVIEW_TEXT_TYPE'] === 'html')
				{
					textNode.appendChild(BX.create('SPAN', {html: data['PREVIEW_TEXT'] || ''}));
				}
				else
				{
					textNode.appendChild(BX.create('SPAN', {text: data['PREVIEW_TEXT'] || ''}));
				}
			}
			else
			{
				var columnData = data[column.id], val = [];
				if (BX.type.isArray(columnData))
				{
					for (var i in columnData)
					{
						if (columnData.hasOwnProperty(i))
						{
							if (columnData[i].type == 'image')
								val.push(this.getImageContainer(columnData[i].value, columnData[i].source));
							else if (columnData[i].type == 'linked')
							{
								textNode.appendChild(BX.create('SPAN', {html: columnData[i].value_format}));
								textNode.appendChild(BX.create('BR'));
							}
							else if (columnData[i].value)
							{
								textNode.appendChild(BX.create('SPAN', {html: columnData[i].value}));
								textNode.appendChild(BX.create('BR'));
							}
						}
					}

					if (val.length)
					{
						textNode.appendChild(
							BX.create('DIV', {
								props: {className: 'bx-scu-list'},
								children: [BX.create('UL', {props: {className: 'bx-scu-itemlist'}, children: val})]
							})
						);
					}
				}
				else if (columnData)
				{
					textNode.appendChild(BX.create('SPAN', {html: BX.util.htmlspecialchars(columnData)}));
				}
			}

			return BX.create('DIV', {
				props: {className: 'bx-soa-item-td bx-soa-item-properties' + (toRight ? ' bx-text-right' : '')},
				children: [
					BX.create('DIV', {
						props: {className: 'bx-soa-item-td-title d-none d-md-block d-lg-none'},
						text: column.name
					}),
					textNode
				]
			});
		},

		createBasketItemHiddenColumn: function(column, allData)
		{
			if (!column || !allData)
				return;

			var data = allData.columns[column.id] ? allData.columns : allData.data,
				textNode = BX.create('TD', {props: {className: 'bx-soa-info-text'}}),
				logotype, img, i;

			if (column.id === 'PROPS')
			{
				var propsNodes = [], props = allData.data.PROPS;
				if (props && props.length)
				{
					for (i in props)
					{
						if (props.hasOwnProperty(i))
						{
							var propCode = (props[i].CODE || props[i].NAME || ''),
								name = props[i].NAME || '',
								value = props[i].VALUE || '';

							// Hide internal basket properties used for store selection.
							if (
								propCode === 'MF_STORE_ID'
								|| propCode === 'MF_STORE_TITLE'
								|| propCode === 'MF_STORE_CODE'
								|| propCode.indexOf('MF_STORE_') === 0
							)
							{
								continue;
							}

							if (value.length == 0)
								continue;

							propsNodes.push(
								BX.create('TR', {
									props: {className: 'bx-soa-info-line'},
									children: [
										BX.create('TD', {props: {className: 'bx-soa-info-title'}, text: name + ':'}),
										BX.create('TD', {props: {className: 'bx-soa-info-text'}, html: BX.util.htmlspecialchars(value)})
									]
								})
							);
						}
					}

					return propsNodes;
				}
				else return;
			}
			else if (column.id === 'PRICE_FORMATED')
			{
				textNode.appendChild(BX.create('STRONG', {props: {className: 'bx-price'}, html: data.PRICE_FORMATED}));
				if (parseFloat(data.DISCOUNT_PRICE) > 0)
				{
					textNode.appendChild(BX.create('BR'));
					textNode.appendChild(BX.create('STRONG', {
						props: {className: 'bx-price-old'},
						html: data.BASE_PRICE_FORMATED
					}));
				}
			}
			else if (column.id === 'SUM')
				textNode.appendChild(BX.create('STRONG', {props: {className: 'bx-price all'}, text: data.SUM}));
			else if (column.id === 'DISCOUNT')
				textNode.appendChild(BX.create('STRONG', {props: {className: 'bx-price'}, text: data.DISCOUNT_PRICE_PERCENT_FORMATED}));
			else if (column.id === 'DETAIL_PICTURE' || column.id === 'PREVIEW_PICTURE')
			{
				logotype = this.getImageSources(allData.data, column.id),
				img = BX.create('IMG', {props: {src: logotype && logotype.src_1x || this.defaultBasketItemLogo}, style: {maxWidth: '50%'}});

				if (logotype && logotype.src_1x && logotype.src_orig)
				{
					BX.bind(img, 'click', BX.delegate(function(e){this.popupShow(e, logotype.src_orig);}, this));
				}

				textNode.appendChild(img);
			}
			else if (BX.util.in_array(column.id, ["QUANTITY", "WEIGHT_FORMATED", "DISCOUNT_PRICE_PERCENT_FORMATED"]))
			{
				textNode.appendChild(BX.create('SPAN', {html: data[column.id]}));
			}
			else if (column.id === 'PREVIEW_TEXT')
			{
				if (data['PREVIEW_TEXT_TYPE'] === 'html')
				{
					textNode.appendChild(BX.create('SPAN', {html: data['PREVIEW_TEXT'] || ''}));
				}
				else
				{
					textNode.appendChild(BX.create('SPAN', {text: data['PREVIEW_TEXT'] || ''}));
				}
			}
			else
			{
				var columnData = data[column.id], val = [];
				if (BX.type.isArray(columnData))
				{
					for (i in columnData)
					{
						if (columnData.hasOwnProperty(i))
						{
							if (columnData[i].type == 'image')
								val.push(this.getImageContainer(columnData[i].value, columnData[i].source));
							else if (columnData[i].type == 'linked')
							{
								textNode.appendChild(BX.create('SPAN', {html: columnData[i].value_format}));
								textNode.appendChild(BX.create('BR'));
							}
							else if (columnData[i].value)
							{
								textNode.appendChild(BX.create('SPAN', {html: columnData[i].value}));
								textNode.appendChild(BX.create('BR'));
							}
							else return;
						}
					}

					if (val.length)
					{
						textNode.appendChild(
							BX.create('DIV', {
								props: {className: 'bx-scu-list'},
								children: [BX.create('UL', {props: {className: 'bx-scu-itemlist'}, children: val})]
							})
						);
					}

				}
				else if (columnData)
				{
					textNode.appendChild(BX.create('SPAN', {html: BX.util.htmlspecialchars(columnData)}));
				}
				else
				{
					return;
				}
			}

			return BX.create('TR', {
				props: {className: 'bx-soa-info-line'},
				children: [
					BX.create('TD', {
						props: {className: 'bx-soa-info-title'},
						text: column.name + ':'
					}),
					textNode
				]
			});
		},

		popupShow: function(e, url, source)
		{
			if (this.popup)
				this.popup.destroy();

			var that = this;
			this.popup = new BX.PopupWindow('bx-soa-image-popup', null, {
				lightShadow: true,
				offsetTop: 0,
				offsetLeft: 0,
				closeIcon: {top: '3px', right: '10px'},
				autoHide: true,
				bindOptions: {position: "bottom"},
				closeByEsc: true,
				zIndex: 100,
				events: {
					onPopupShow: function() {
						BX.create("IMG", {
							props: {src: source || url},
							events: {
								load: function() {
									var content = BX('bx-soa-image-popup-content');
									if (content)
									{
										var windowSize = BX.GetWindowInnerSize(),
											ratio = this.isMobile ? 0.5 : 0.9,
											contentHeight, contentWidth;

										BX.cleanNode(content);
										content.appendChild(this);

										contentHeight = content.offsetHeight;
										contentWidth = content.offsetWidth;

										if (contentHeight > windowSize.innerHeight * ratio)
										{
											content.style.height = windowSize.innerHeight * ratio + 'px';
											content.style.width = contentWidth * (windowSize.innerHeight * ratio / contentHeight) + 'px';
											contentHeight = content.offsetHeight;
											contentWidth = content.offsetWidth;
										}

										if (contentWidth > windowSize.innerWidth * ratio)
										{
											content.style.width = windowSize.innerWidth * ratio + 'px';
											content.style.height = contentHeight * (windowSize.innerWidth * ratio / contentWidth) + 'px';
										}

										content.style.height = content.offsetHeight + 'px';
										content.style.width = content.offsetWidth + 'px';

										that.popup.adjustPosition();
									}
								}
							}
						});
					},
					onPopupClose: function() {
						this.destroy();
					}
				},
				content: BX.create('DIV', {
					props: {id: 'bx-soa-image-popup-content'},
					children: [BX.create('IMG', {props: {src: this.templateFolder + "/images/loader.gif"}})]
				})
			});
			this.popup.show();
		},

		getImageContainer: function(link, source)
		{
			return BX.create('LI', {
				props: {className: 'bx-img-item'},
				children: [
					BX.create('DIV', {
						props: {className: 'bx-scu-itemColorBlock'},
						children: [
							BX.create('DIV', {
								props: {className: 'bx-img-itemColor'},
								style: {backgroundImage: 'url("' + link + '")'}
							})
						],
						events: {
							click: BX.delegate(function(e){this.popupShow(e, link, source)}, this)
						}
					})
				]
			});
		},

		editCoupons: function(basketItemsNode)
		{
			var couponsList = this.getCouponsList(true),
				couponsLabel = this.getCouponsLabel(true),
				couponsBlock = BX.create('DIV', {
					props: {className: 'bx-soa-coupon-block'},
					children: [
						BX.create('DIV', {
							props: {className: 'bx-soa-coupon-input'},
							children: [
								BX.create('INPUT', {
									props: {
										className: 'form-control bx-ios-fix',
										type: 'text'
									},
									events: {
										change: BX.delegate(function(event){
											var newCoupon = BX.getEventTarget(event);
											if (newCoupon && newCoupon.value)
											{
												this.sendRequest('enterCoupon', newCoupon.value);
												newCoupon.value = '';
											}
										}, this)
									}
								})
							]
						}),
						BX.create('SPAN', {props: {className: 'bx-soa-coupon-item'}, children: couponsList})
					]
				});

			basketItemsNode.appendChild(
				BX.create('DIV', {
					props: {className: 'bx-soa-coupon'},
					children: [
						couponsLabel,
						couponsBlock
					]
				})
			);
		},

		editCouponsFade: function(basketItemsNode)
		{
			if (this.result.COUPON_LIST.length < 1)
				return;

			var couponsList = this.getCouponsList(false),
				couponsLabel, couponsBlock;

			if (couponsList.length)
			{
				couponsLabel = this.getCouponsLabel(false);
				couponsBlock = BX.create('DIV', {
					props: {className: 'bx-soa-coupon-block'},
					children: [
						BX.create('DIV', {
							props: {className: 'bx-soa-coupon-list'},
							children: [
								BX.create('DIV', {
									props: {className: 'bx-soa-coupon-item'},
									children: [couponsLabel].concat(couponsList)
								})
							]
						})
					]
				});

				basketItemsNode.appendChild(
					BX.create('DIV', {
						props: {className: 'bx-soa-coupon bx-soa-coupon-item-fixed'},
						children: [couponsBlock]
					})
				);
			}
		},

		getCouponsList: function(active)
		{
			var couponsList = [], i;

			for (i = 0; i < this.result.COUPON_LIST.length; i++)
			{
				if (active || (!active && this.result.COUPON_LIST[i].JS_STATUS == 'APPLIED'))
				{
					couponsList.push(this.getCouponNode({
						text: this.result.COUPON_LIST[i].COUPON,
						desc: this.result.COUPON_LIST[i].JS_CHECK_CODE,
						status: this.result.COUPON_LIST[i].JS_STATUS
					}, active));
				}
			}

			return couponsList;
		},

		getCouponNode: function(coupon, active)
		{
			var couponName = BX.util.htmlspecialchars(coupon.text) || '',
				couponDesc = coupon.desc && coupon.desc.length
					? coupon.desc.charAt(0).toUpperCase() + coupon.desc.slice(1)
					: BX.message('SOA_NOT_FOUND'),
				couponStatus = coupon.status || 'BAD',
				couponItem, tooltip;

			switch (couponStatus.toUpperCase())
			{
				case 'ENTERED': couponItem = 'used'; tooltip = 'warning'; break;
				case 'BAD': couponItem = tooltip = 'danger'; break;
				default: couponItem = tooltip  = 'success';
			}

			return BX.create('STRONG', {
				attrs: {
					'data-coupon': couponName,
					className: 'bx-soa-coupon-item-' + couponItem
				},
				children: active ? [
					couponName || '',
					BX.create('SPAN', {
						props: {className: 'bx-soa-coupon-remove'},
						events: {
							click: BX.delegate(function(e){
								var target = e.target || e.srcElement,
									coupon = BX.findParent(target, {tagName: 'STRONG'});

								if (coupon && coupon.getAttribute('data-coupon'))
								{
									this.sendRequest('removeCoupon', coupon.getAttribute('data-coupon'))
								}
							}, this)
						}
					}),
					BX.create('SPAN', {
						props: {
							className: 'bx-soa-tooltip bx-soa-tooltip-coupon bx-soa-tooltip-' + tooltip + ' tooltip top'
						},
						children: [
							BX.create('SPAN', {props: {className: 'tooltip-arrow'}}),
							BX.create('SPAN', {props: {className: 'tooltip-inner'}, text: couponDesc})
						]
					})
				] : [couponName]
			});
		},

		getCouponsLabel: function(active)
		{
			return BX.create('DIV', {
				props: {className: 'bx-soa-coupon-label'},
				children: active
					? [BX.create('LABEL', {html: this.params.MESS_USE_COUPON + ':'})]
					: [this.params.MESS_COUPON + ':']
			});
		},

		addCoupon: function(coupon)
		{
			var couponListNodes = this.orderBlockNode.querySelectorAll('.bx-soa-coupon:not(.bx-soa-coupon-item-fixed) .bx-soa-coupon-item');

			for (var i = 0; i < couponListNodes.length; i++)
			{
				if (couponListNodes[i].querySelector('[data-coupon="' + BX.util.htmlspecialchars(coupon) + '"]'))
					break;

				couponListNodes[i].appendChild(this.getCouponNode({text: coupon}, true, 'bx-soa-coupon-item-danger'));
			}
		},

		removeCoupon: function(coupon)
		{
			var couponNodes = this.orderBlockNode.querySelectorAll('[data-coupon="' + BX.util.htmlspecialchars(coupon) + '"]'), i;

			for (i in couponNodes)
			{
				if (couponNodes.hasOwnProperty(i))
				{
					BX.remove(couponNodes[i]);
				}
			}
		},

		editRegionBlock: function(active)
		{
			// Motor-Force customization:
			// - remove "Region" step entirely
			// - location + zip are rendered in Delivery block
			// - person type selector UI is removed (we keep a hidden PERSON_TYPE input elsewhere)
			if (this.regionBlockNode)
			{
				this.regionBlockNode.style.display = 'none';
			}
			this.initialized.region = true;
		},

		editActiveRegionBlock: function(activeNodeMode)
		{
			// Disabled. (Location + ZIP are rendered in Delivery block.)
		},

		editFadeRegionBlock: function()
		{
			var regionContent = this.regionBlockNode.querySelector('.bx-soa-section-content'), newContent;

			if (this.initialized.region)
			{
				this.regionHiddenBlockNode.appendChild(regionContent);
			}
			else
			{
				this.editActiveRegionBlock(false);
				BX.remove(BX.lastChild(this.regionBlockNode));
			}

			newContent = this.getNewContainer(true);
			this.regionBlockNode.appendChild(newContent);
			this.editFadeRegionContent(newContent);
		},

		editFadeRegionContent: function(node)
		{
			if (!node || !this.locationsInitialized)
				return;

			var selectedPersonType = this.getSelectedPersonType(),
				errorNode = this.regionHiddenBlockNode.querySelector('.alert.alert-danger'),
				addedHtml = '', props = [], locationProperty,
				input, zipValue = '', zipProperty,
				fadeParamName, i, k, locationString, validRegionErrors;

			BX.cleanNode(node);

			if (errorNode)
				node.appendChild(errorNode.cloneNode(true));

			if (selectedPersonType && selectedPersonType.NAME && this.result.PERSON_TYPE.length > 1)
			{
				addedHtml += '<strong>' + this.params.MESS_PERSON_TYPE + ':</strong> '
					+ BX.util.htmlspecialchars(selectedPersonType.NAME) + '<br>';
			}

			if (selectedPersonType)
			{
				fadeParamName = 'PROPS_FADE_LIST_' + selectedPersonType.ID;
				props = this.params[fadeParamName] || [];
			}

			for (i in this.result.ORDER_PROP.properties)
			{
				if (this.result.ORDER_PROP.properties.hasOwnProperty(i))
				{
					if (this.result.ORDER_PROP.properties[i].IS_LOCATION == 'Y'
						&& this.result.ORDER_PROP.properties[i].ID == this.deliveryLocationInfo.loc)
					{
						locationProperty = this.result.ORDER_PROP.properties[i];
					}
					else if (this.result.ORDER_PROP.properties[i].IS_ZIP == 'Y'
						&& this.result.ORDER_PROP.properties[i].ID == this.deliveryLocationInfo.zip)
					{
						zipProperty = this.result.ORDER_PROP.properties[i];
						for (k = 0; k < props.length; k++)
						{
							if (props[k] == zipProperty.ID)
							{
								input = BX('zipProperty');
								zipValue = input && input.value && input.value.length ? input.value : BX.message('SOA_NOT_SPECIFIED');
								break;
							}
						}
					}
				}
			}

			locationString = this.getLocationString(this.regionHiddenBlockNode);
			if (locationProperty && locationString.length)
				addedHtml += '<strong>' + BX.util.htmlspecialchars(locationProperty.NAME) + ':</strong> '
					+ BX.util.htmlspecialchars(locationString) + '<br>';

			if (zipProperty && zipValue.length)
				addedHtml += '<strong>' + BX.util.htmlspecialchars(zipProperty.NAME) + ':</strong> '
					+ BX.util.htmlspecialchars(zipValue);

			node.innerHTML += addedHtml;

			if (this.regionBlockNode.getAttribute('data-visited') == 'true')
			{
				validRegionErrors = this.isValidRegionBlock();

				if (validRegionErrors.length)
				{
					// Motor-Force customization: region step disabled; show in Delivery block.
					BX.addClass(this.deliveryBlockNode, 'bx-step-error');
					this.showError(this.deliveryBlockNode, validRegionErrors);
				}
				else
					BX.removeClass(this.deliveryBlockNode, 'bx-step-error');
			}

			BX.bind(node.querySelector('.alert.alert-danger'), 'click', BX.proxy(this.showByClick, this));
			BX.bind(node.querySelector('.alert.alert-warning'), 'click', BX.proxy(this.showByClick, this));
		},

		getSelectedPersonType: function()
		{
			var personTypeInput, currentPersonType, personTypeId, i,
				personTypeLength = this.result.PERSON_TYPE.length;

			if (personTypeLength == 1)
			{
				personTypeInput = this.regionBlockNode.querySelector('input[type=hidden][name=PERSON_TYPE]');
				if (!personTypeInput)
					personTypeInput = this.regionHiddenBlockNode.querySelector('input[type=hidden][name=PERSON_TYPE]');
			}
			else if (personTypeLength == 2)
			{
				personTypeInput = this.regionBlockNode.querySelector('input[type=radio][name=PERSON_TYPE]:checked');
				if (!personTypeInput)
					personTypeInput = this.regionHiddenBlockNode.querySelector('input[type=radio][name=PERSON_TYPE]:checked');
			}
			else
			{
				personTypeInput = this.regionBlockNode.querySelector('select[name=PERSON_TYPE] > option:checked');
				if (!personTypeInput)
					personTypeInput = this.regionHiddenBlockNode.querySelector('select[name=PERSON_TYPE] > option:checked');
			}

			if (personTypeInput)
			{
				personTypeId = personTypeInput.value;

				for (i in this.result.PERSON_TYPE)
				{
					if (this.result.PERSON_TYPE[i].ID == personTypeId)
					{
						currentPersonType = this.result.PERSON_TYPE[i];
						break;
					}
				}
			}

			return currentPersonType;
		},

		getDeliveryLocationInput: function(node)
		{
			var currentProperty, locationId, altId, location, k, altProperty,
				labelHtml, currentLocation, insertedLoc,
				labelTextHtml, label, input, altNode;

			for (k in this.result.ORDER_PROP.properties)
			{
				if (this.result.ORDER_PROP.properties.hasOwnProperty(k))
				{
					currentProperty = this.result.ORDER_PROP.properties[k];
					if (currentProperty.IS_LOCATION == 'Y')
					{
						locationId = currentProperty.ID;
						altId = parseInt(currentProperty.INPUT_FIELD_LOCATION);
						break;
					}
				}
			}

			location = this.locations[locationId];
			if (location && location[0] && location[0].output)
			{
				this.regionBlockNotEmpty = true;

				labelHtml = '<label class="bx-soa-custom-label" for="soa-property-' + parseInt(locationId) + '">'
					+ (currentProperty.REQUIRED == 'Y' ? '<span class="bx-authform-starrequired">*</span> ' : '')
					+ BX.util.htmlspecialchars(currentProperty.NAME)
					+ (currentProperty.DESCRIPTION.length ? ' <small>(' + BX.util.htmlspecialchars(currentProperty.DESCRIPTION) + ')</small>' : '')
					+ '</label>';

				currentLocation = location[0].output;
				insertedLoc = BX.create('DIV', {
					attrs: {'data-property-id-row': locationId},
					props: {className: 'form-group bx-soa-location-input-container'},
					style: {visibility: 'hidden'},
					html:  labelHtml + currentLocation.HTML
				});
				node.appendChild(insertedLoc);
				node.appendChild(BX.create('INPUT', {
					props: {
						type: 'hidden',
						name: 'RECENT_DELIVERY_VALUE',
						value: location[0].lastValue
					}
				}));

				for (k in currentLocation.SCRIPT)
					if (currentLocation.SCRIPT.hasOwnProperty(k))
						BX.evalGlobal(currentLocation.SCRIPT[k].JS);
			}

			if (location && location[0] && location[0].showAlt && altId > 0)
			{
				for (k in this.result.ORDER_PROP.properties)
				{
					if (parseInt(this.result.ORDER_PROP.properties[k].ID) == altId)
					{
						altProperty = this.result.ORDER_PROP.properties[k];
						break;
					}
				}
			}

			if (altProperty)
			{
				altNode = BX.create('DIV', {
					attrs: {'data-property-id-row': altProperty.ID},
					props: {className: "form-group bx-soa-location-input-container"}
				});

				labelTextHtml = altProperty.REQUIRED == 'Y' ? '<span class="bx-authform-starrequired">*</span> ' : '';
				labelTextHtml += BX.util.htmlspecialchars(altProperty.NAME);

				label = BX.create('LABEL', {
					attrs: {for: 'altProperty'},
					props: {className: 'bx-soa-custom-label'},
					html: labelTextHtml
				});

				input = BX.create('INPUT', {
					props: {
						id: 'altProperty',
						type: 'text',
						placeholder: altProperty.DESCRIPTION,
						autocomplete: 'city',
						className: 'form-control bx-soa-customer-input bx-ios-fix',
						name: 'ORDER_PROP_' + altProperty.ID,
						value: altProperty.VALUE
					}
				});

				altNode.appendChild(label);
				altNode.appendChild(input);
				node.appendChild(altNode);

				this.bindValidation(altProperty.ID, altNode);
			}

			this.getZipLocationInput(node);

			// Motor-Force customization: hide "region" reference text (region step removed).
		},

		getLocationString: function(node)
		{
			if (!node)
				return '';

			var locationInputNode = node.querySelector('.bx-ui-sls-route'),
				locationString = '',
				locationSteps, i, altLoc;

			if (locationInputNode && locationInputNode.value && locationInputNode.value.length)
				locationString = locationInputNode.value;
			else
			{
				locationSteps = node.querySelectorAll('.bx-ui-combobox-fake.bx-combobox-fake-as-input');
				for (i = locationSteps.length; i--;)
				{
					if (locationSteps[i].innerHTML.indexOf('...') >= 0)
						continue;

					if (locationSteps[i].innerHTML.indexOf('---') >= 0)
					{
						altLoc = BX('altProperty');
						if (altLoc && altLoc.value.length)
							locationString += altLoc.value;

						continue;
					}

					if (locationString.length)
						locationString += ', ';

					locationString += locationSteps[i].innerHTML;
				}

				if (locationString.length == 0)
					locationString = BX.message('SOA_NOT_SPECIFIED');
			}

			return locationString;
		},

		getZipLocationInput: function(node)
		{
			var zipProperty, i, propsItemNode, labelTextHtml, label, input;

			for (i in this.result.ORDER_PROP.properties)
			{
				if (this.result.ORDER_PROP.properties.hasOwnProperty(i) && this.result.ORDER_PROP.properties[i].IS_ZIP == 'Y')
				{
					zipProperty = this.result.ORDER_PROP.properties[i];
					break;
				}
			}

			if (zipProperty)
			{
				this.regionBlockNotEmpty = true;

				propsItemNode = BX.create('DIV', {props: {className: "form-group bx-soa-location-input-container"}});
				propsItemNode.setAttribute('data-property-id-row', zipProperty.ID);

				labelTextHtml = zipProperty.REQUIRED == 'Y' ? '<span class="bx-authform-starrequired">*</span> ' : '';
				labelTextHtml += BX.util.htmlspecialchars(zipProperty.NAME);

				label = BX.create('LABEL', {
					attrs: {'for': 'zipProperty'},
					props: {className: 'bx-soa-custom-label'},
					html: labelTextHtml
				});
				input = BX.create('INPUT', {
					props: {
						id: 'zipProperty',
						type: 'text',
						placeholder: zipProperty.DESCRIPTION,
						autocomplete: 'zip',
						className: 'form-control bx-soa-customer-input bx-ios-fix',
						name: 'ORDER_PROP_' + zipProperty.ID,
						value: zipProperty.VALUE
					}
				});

				propsItemNode.appendChild(label);
				propsItemNode.appendChild(input);
				node.appendChild(propsItemNode);
				node.appendChild(
					BX.create('input', {
						props: {
							id: 'ZIP_PROPERTY_CHANGED',
							name: 'ZIP_PROPERTY_CHANGED',
							type: 'hidden',
							value: this.result.ZIP_PROPERTY_CHANGED || 'N'
						}
					})
				);

				this.bindValidation(zipProperty.ID, propsItemNode);
			}
		},

		getPersonTypeSortedArray: function(objPersonType)
		{
			var personTypes = [], k;

			for (k in objPersonType)
			{
				if (objPersonType.hasOwnProperty(k))
				{
					personTypes.push(objPersonType[k]);
				}
			}

			return personTypes.sort(function(a, b){return parseInt(a.SORT) - parseInt(b.SORT)});
		},

		getPersonTypeControl: function(node)
		{
			if (!this.result.PERSON_TYPE)
				return;

			this.result.PERSON_TYPE = this.getPersonTypeSortedArray(this.result.PERSON_TYPE);

			// Motor-Force customization:
			// Hide payer type selector UI. Keep a hidden PERSON_TYPE value so Bitrix logic stays intact.
			var personTypesCount = this.result.PERSON_TYPE.length,
				selectedId = null,
				i;

			for (i in this.result.PERSON_TYPE)
			{
				if (this.result.PERSON_TYPE.hasOwnProperty(i) && this.result.PERSON_TYPE[i].CHECKED == 'Y')
				{
					selectedId = this.result.PERSON_TYPE[i].ID;
					break;
				}
			}
			if (!selectedId && personTypesCount > 0)
			{
				selectedId = this.result.PERSON_TYPE[0].ID;
			}
			if (selectedId)
			{
				node.appendChild(BX.create('INPUT', {props: {type: 'hidden', name: 'PERSON_TYPE', value: selectedId}}));
				// Important: also send PERSON_TYPE_OLD so Bitrix doesn't treat each AJAX refresh
				// as "person type changed" and doesn't overwrite LOCATION from the saved profile (e.g., Moscow).
				node.appendChild(BX.create('INPUT', {props: {type: 'hidden', name: 'PERSON_TYPE_OLD', value: selectedId}}));
			}
		},

		getProfilesControl: function(node)
		{
			var profilesLength = BX.util.object_keys(this.result.USER_PROFILES).length,
				i, label, options = [],
				profileChangeInput, input;

			if (profilesLength)
			{
				if (
					this.params.ALLOW_USER_PROFILES === 'Y'
					&& (profilesLength > 1 || this.params.ALLOW_NEW_PROFILE === 'Y')
				)
				{
					this.regionBlockNotEmpty = true;

					label = BX.create('LABEL', {props: {className: 'bx-soa-custom-label'}, html: this.params.MESS_SELECT_PROFILE});

					for (i in this.result.USER_PROFILES)
					{
						if (this.result.USER_PROFILES.hasOwnProperty(i))
						{
							options.unshift(
								BX.create('OPTION', {
									props: {
										value: this.result.USER_PROFILES[i].ID,
										selected: this.result.USER_PROFILES[i].CHECKED === 'Y'
									},
									html: this.result.USER_PROFILES[i].NAME
								})
							);
						}
					}

					if (this.params.ALLOW_NEW_PROFILE === 'Y')
					{
						options.unshift(BX.create('OPTION', {props: {value: 0}, text: BX.message('SOA_PROP_NEW_PROFILE')}));
					}

					profileChangeInput = BX.create('INPUT', {
						props: {
							type: 'hidden',
							value: 'N',
							id: 'profile_change',
							name: 'profile_change'
						}
					});
					input = BX.create('SELECT', {
						props: {className: 'form-control', name: 'PROFILE_ID'},
						children: options,
						events:{
							change: BX.delegate(function(){
								BX('profile_change').value = 'Y';
								this.sendRequest();
							}, this)
						}
					});

					node.appendChild(
						BX.create('DIV', {
							props: {className: 'form-group bx-soa-location-input-container'},
							children: [label, profileChangeInput, input]
						})
					);
				}
				else
				{
					for (i in this.result.USER_PROFILES)
					{
						if (
							this.result.USER_PROFILES.hasOwnProperty(i)
							&& this.result.USER_PROFILES[i].CHECKED === 'Y'
						)
						{
							node.appendChild(
								BX.create('INPUT', {
									props: {
										name: 'PROFILE_ID',
										type: 'hidden',
										value: this.result.USER_PROFILES[i].ID}
								})
							);
						}
					}
				}
			}
		},

		editPaySystemBlock: function(active)
		{
			if (!this.paySystemBlockNode || !this.paySystemHiddenBlockNode || !this.result.PAY_SYSTEM)
				return;

			if (active)
				this.editActivePaySystemBlock(true);
			else
				this.editFadePaySystemBlock();

			this.initialized.paySystem = true;
		},

		editActivePaySystemBlock: function(activeNodeMode)
		{
			var node = activeNodeMode ? this.paySystemBlockNode : this.paySystemHiddenBlockNode,
				paySystemContent, paySystemNode;

			if (this.initialized.paySystem)
			{
				BX.remove(BX.lastChild(node));
				node.appendChild(BX.firstChild(this.paySystemHiddenBlockNode));
			}
			else
			{
				paySystemContent = node.querySelector('.bx-soa-section-content');
				if (!paySystemContent)
				{
					paySystemContent = this.getNewContainer();
					node.appendChild(paySystemContent);
				}
				else
					BX.cleanNode(paySystemContent);

				this.getErrorContainer(paySystemContent);
				paySystemNode = BX.create('DIV', {props: {className: 'bx-soa-pp row'}});
				this.editPaySystemItems(paySystemNode);
				paySystemContent.appendChild(paySystemNode);
				this.editPaySystemInfo(paySystemNode);

				if (this.params.SHOW_COUPONS_PAY_SYSTEM == 'Y')
					this.editCoupons(paySystemContent);

				this.getBlockFooter(paySystemContent);
			}
		},

		editFadePaySystemBlock: function()
		{
			var paySystemContent = this.paySystemBlockNode.querySelector('.bx-soa-section-content'), newContent;

			if (this.initialized.paySystem)
			{
				this.paySystemHiddenBlockNode.appendChild(paySystemContent);
			}
			else
			{
				this.editActivePaySystemBlock(false);
				BX.remove(BX.lastChild(this.paySystemBlockNode));
			}

			newContent = this.getNewContainer(true);
			this.paySystemBlockNode.appendChild(newContent);

			this.editFadePaySystemContent(newContent);

			if (this.params.SHOW_COUPONS_PAY_SYSTEM == 'Y')
				this.editCouponsFade(newContent);
		},

		editPaySystemItems: function(paySystemNode)
		{
			if (!this.result.PAY_SYSTEM || this.result.PAY_SYSTEM.length <= 0)
				return;

			var paySystemItemsContainer = BX.create('DIV', {props: {className: 'order-md-1 order-2 col-md-7 bx-soa-pp-item-container'}}),
				paySystemItemsContainerRow = BX.create('DIV', {props: {className: 'row'}}),
				paySystemItemNode, i;

			for (i = 0; i < this.paySystemPagination.currentPage.length; i++)
			{
				paySystemItemNode = this.createPaySystemItem(this.paySystemPagination.currentPage[i]);
				paySystemItemsContainerRow.appendChild(paySystemItemNode);
			}
			paySystemItemsContainer.appendChild(paySystemItemsContainerRow);

			if (this.paySystemPagination.show)
				this.showPagination('paySystem', paySystemItemsContainer);

			paySystemNode.appendChild(paySystemItemsContainer);
		},

		createPaySystemItem: function(item)
		{
			var checked = item.CHECKED == 'Y',
				logotype, logoNode,
				paySystemId = parseInt(item.ID),
				title, label, itemNode;

			logoNode = BX.create('DIV', {props: {className: 'bx-soa-pp-company-image'}});
			logotype = this.getImageSources(item, 'PSA_LOGOTIP');
			if (logotype && logotype.src_2x)
			{
				logoNode.setAttribute('style',
					'background-image: url("' + logotype.src_1x + '");' +
					'background-image: -webkit-image-set(url("' + logotype.src_1x + '") 1x, url("' + logotype.src_2x + '") 2x)'
				);
			}
			else
			{
				logotype = logotype && logotype.src_1x || this.defaultPaySystemLogo;
				logoNode.setAttribute('style', 'background-image: url("' + logotype + '");');
			}
			label = BX.create('DIV', {
				props: {className: 'bx-soa-pp-company-graf-container'},
				children: [
					BX.create('INPUT', {
						props: {
							id: 'ID_PAY_SYSTEM_ID_' + paySystemId,
							name: 'PAY_SYSTEM_ID',
							type: 'checkbox',
							className: 'bx-soa-pp-company-checkbox',
							value: paySystemId,
							checked: checked
						}
					}),
					logoNode
				]
			});

			if (this.params.SHOW_PAY_SYSTEM_LIST_NAMES == 'Y')
			{
				title = BX.create('DIV', {props: {className: 'bx-soa-pp-company-smalltitle'}, text: item.NAME});
			}

			itemNode = BX.create('DIV', {
				props: {className: 'bx-soa-pp-company col-6'},
				children: [label, title],
				events: {
					click: BX.proxy(this.selectPaySystem, this)
				}
			});

			if (checked)
				BX.addClass(itemNode, 'bx-selected');

			return itemNode;
		},

		editPaySystemInfo: function(paySystemNode)
		{
			if (!this.result.PAY_SYSTEM || (this.result.PAY_SYSTEM.length == 0 && this.result.PAY_FROM_ACCOUNT != 'Y'))
				return;

			var paySystemInfoContainer = BX.create('DIV', {
					props: {
						className: (this.result.PAY_SYSTEM.length == 0 ? 'col-12 mb-3' : 'col-md-5 mb-lg-0') + ' col-12 mb-3 order-md-2 order-1 bx-soa-pp-desc-container'
					}
				}),
				innerPs, extPs, delimiter, currentPaySystem,
				logotype, logoNode, subTitle, label, title, price;

			BX.cleanNode(paySystemInfoContainer);

			if (this.result.PAY_FROM_ACCOUNT == 'Y')
				innerPs = this.getInnerPaySystem(paySystemInfoContainer);

			currentPaySystem = this.getSelectedPaySystem();
			if (currentPaySystem)
			{
				logoNode = BX.create('DIV', {props: {className: 'bx-soa-pp-company-image'}});
				logotype = this.getImageSources(currentPaySystem, 'PSA_LOGOTIP');
				if (logotype && logotype.src_2x)
				{
					logoNode.setAttribute('style',
						'background-image: url("' + logotype.src_1x + '");' +
						'background-image: -webkit-image-set(url("' + logotype.src_1x + '") 1x, url("' + logotype.src_2x + '") 2x)'
					);
				}
				else
				{
					logotype = logotype && logotype.src_1x || this.defaultPaySystemLogo;
					logoNode.setAttribute('style', 'background-image: url("' + logotype + '");');
				}

				if (this.params.SHOW_PAY_SYSTEM_INFO_NAME == 'Y')
				{
					subTitle = BX.create('DIV', {
						props: {className: 'bx-soa-pp-company-subTitle'},
						text: currentPaySystem.NAME
					});
				}

				label = BX.create('DIV', {
					props: {className: 'bx-soa-pp-company-logo'},
					children: [
						BX.create('DIV', {
							props: {className: 'bx-soa-pp-company-graf-container'},
							children: [logoNode]
						})
					]
				});

				title = BX.create('DIV', {
					props: {className: 'bx-soa-pp-company-block'},
					children: [BX.create('DIV', {props: {className: 'bx-soa-pp-company-desc'}, html: currentPaySystem.DESCRIPTION})]
				});

				if (currentPaySystem.PRICE && parseFloat(currentPaySystem.PRICE) > 0)
				{
					price = BX.create('UL', {
						props: {className: 'bx-soa-pp-list'},
						children: [
							BX.create('LI', {
								children: [
									BX.create('DIV', {props: {className: 'bx-soa-pp-list-termin'}, html: this.params.MESS_PRICE + ':'}),
									BX.create('DIV', {props: {className: 'bx-soa-pp-list-description'}, text: '~' + currentPaySystem.PRICE_FORMATTED})
								]
							})
						]
					});
				}

				extPs = BX.create('DIV', {children: [subTitle, label, title, price]});
			}

			if (innerPs && extPs)
				delimiter = BX.create('HR', {props: {className: 'bxe-light'}});

			paySystemInfoContainer.appendChild(
				BX.create('DIV', {
					props: {className: 'bx-soa-pp-company'},
					children: [innerPs, delimiter, extPs]
				})
			);
			paySystemNode.appendChild(paySystemInfoContainer);
		},

		getInnerPaySystem: function()
		{
			if (!this.result.CURRENT_BUDGET_FORMATED || !this.result.PAY_CURRENT_ACCOUNT || !this.result.INNER_PAY_SYSTEM)
				return;

			var accountOnly = this.params.ONLY_FULL_PAY_FROM_ACCOUNT && (this.params.ONLY_FULL_PAY_FROM_ACCOUNT == 'Y'),
				isSelected = this.result.PAY_CURRENT_ACCOUNT && (this.result.PAY_CURRENT_ACCOUNT == 'Y'),
				paySystem = this.result.INNER_PAY_SYSTEM,
				logotype, logoNode,subTitle, label, title, hiddenInput, htmlString, innerPsDesc;

			if (this.params.SHOW_PAY_SYSTEM_INFO_NAME == 'Y')
			{
				subTitle = BX.create('DIV', {
					props: {className: 'bx-soa-pp-company-subTitle'},
					text: paySystem.NAME
				});
			}

			logoNode = BX.create('DIV', {props: {className: 'bx-soa-pp-company-image'}});
			logotype = this.getImageSources(paySystem, 'LOGOTIP');
			if (logotype && logotype.src_2x)
			{
				logoNode.setAttribute('style',
					'background-image: url("' + logotype.src_1x + '");' +
					'background-image: -webkit-image-set(url("' + logotype.src_1x + '") 1x, url("' + logotype.src_2x + '") 2x)'
				);
			}
			else
			{
				logotype = logotype && logotype.src_1x || this.defaultPaySystemLogo;
				logoNode.setAttribute('style', 'background-image: url("' + logotype + '");');
			}

			label = BX.create('DIV', {
				props: {className: 'bx-soa-pp-company-logo'},
				children: [
					BX.create('DIV', {
						props: {className: 'bx-soa-pp-company-graf-container'},
						children: [
							BX.create('INPUT', {
								props: {
									type: 'checkbox',
									className: 'bx-soa-pp-company-checkbox',
									name: 'PAY_CURRENT_ACCOUNT',
									value: 'Y',
									checked: isSelected
								}
							}),
							logoNode
						],
						events: {
							click: BX.proxy(this.selectPaySystem, this)
						}
					})
				]
			});

			if (paySystem.DESCRIPTION && paySystem.DESCRIPTION.length)
			{
				title = BX.create('DIV', {
					props: {className: 'bx-soa-pp-company-block'},
					children: [BX.create('DIV', {props: {className: 'bx-soa-pp-company-desc'}, html: paySystem.DESCRIPTION})]
				});
			}

			hiddenInput = BX.create('INPUT', {
				props: {
					type: 'hidden',
					name: 'PAY_CURRENT_ACCOUNT',
					value: 'N'
				}
			});

			htmlString = this.params.MESS_INNER_PS_BALANCE + ' <b class="wsnw">' + this.result.CURRENT_BUDGET_FORMATED
				+ '</b><br>' + (accountOnly ? BX.message('SOA_PAY_ACCOUNT3') : '');
			innerPsDesc = BX.create('DIV', {props: {className: 'bx-soa-pp-company-desc'}, html: htmlString});

			return BX.create('DIV', {
				props: {className: 'bx-soa-pp-inner-ps' + (isSelected ? ' bx-selected' : '')},
				children: [hiddenInput, subTitle, label, title, innerPsDesc]
			});
		},

		editFadePaySystemContent: function(node)
		{
			var selectedPaySystem = this.getSelectedPaySystem(),
				errorNode = this.paySystemHiddenBlockNode.querySelector('div.alert.alert-danger'),
				warningNode = this.paySystemHiddenBlockNode.querySelector('div.alert.alert-warning.alert-show'),
				addedHtml = '', logotype, imgSrc;

			if (errorNode)
				node.appendChild(
					BX.create("div", {
						props: { className: "col-12" },
						children: [
							errorNode.cloneNode(true)
						]
					})
				);
			else
				this.getErrorContainer(node);

			if (warningNode && warningNode.innerHTML)
				node.appendChild(warningNode.cloneNode(true));

			if (this.isSelectedInnerPayment())
			{
				logotype = this.getImageSources(this.result.INNER_PAY_SYSTEM, 'LOGOTIP');
				imgSrc = logotype && logotype.src_1x || this.defaultPaySystemLogo;

				addedHtml += '<div class="bx-soa-pp-company-selected">';
				addedHtml += '<img src="' + imgSrc + '" style="height:18px;" alt="">';
				addedHtml += '<strong>' + this.result.INNER_PAY_SYSTEM.NAME + '</strong><br>';
				addedHtml += '</div>';
			}

			if (selectedPaySystem && selectedPaySystem.NAME)
			{
				logotype = this.getImageSources(selectedPaySystem, 'PSA_LOGOTIP');
				imgSrc = logotype && logotype.src_1x || this.defaultPaySystemLogo;

				addedHtml += '<div class="bx-soa-pp-company-selected">';
				addedHtml += '<img src="' + imgSrc + '" style="height:18px;" alt="">';
				addedHtml += '<strong>' + BX.util.htmlspecialchars(selectedPaySystem.NAME) + '</strong>';
				addedHtml += '</div>';
			}

			if (!addedHtml.length)
				addedHtml = '<strong>' + BX.message('SOA_PS_SELECT_ERROR') + '</strong>';

			node.innerHTML += addedHtml;

			node.appendChild(BX.create('DIV', {style: {clear: 'both'}}));
			BX.bind(node.querySelector('.alert.alert-danger'), 'click', BX.proxy(this.showByClick, this));
			BX.bind(node.querySelector('.alert.alert-warning'), 'click', BX.proxy(this.showByClick, this));
		},

		getSelectedPaySystem: function()
		{
			var paySystemCheckbox = this.paySystemBlockNode.querySelector('input[type=checkbox][name=PAY_SYSTEM_ID]:checked'),
				currentPaySystem = null, paySystemId, i;

			if (!paySystemCheckbox)
				paySystemCheckbox = this.paySystemHiddenBlockNode.querySelector('input[type=checkbox][name=PAY_SYSTEM_ID]:checked');

			if (!paySystemCheckbox)
				paySystemCheckbox = this.paySystemHiddenBlockNode.querySelector('input[type=hidden][name=PAY_SYSTEM_ID]');

			if (paySystemCheckbox)
			{
				paySystemId = paySystemCheckbox.value;

				for (i = 0; i < this.result.PAY_SYSTEM.length; i++)
				{
					if (this.result.PAY_SYSTEM[i].ID == paySystemId)
					{
						currentPaySystem = this.result.PAY_SYSTEM[i];
						break;
					}
				}
			}

			return currentPaySystem;
		},

		isSelectedInnerPayment: function()
		{
			var innerPaySystemCheckbox = this.paySystemBlockNode.querySelector('input[type=checkbox][name=PAY_CURRENT_ACCOUNT]');

			if (!innerPaySystemCheckbox)
				innerPaySystemCheckbox = this.paySystemHiddenBlockNode.querySelector('input[type=checkbox][name=PAY_CURRENT_ACCOUNT]');

			return innerPaySystemCheckbox && innerPaySystemCheckbox.checked;
		},

		selectPaySystem: function(event)
		{
			if (!this.orderBlockNode || !event)
				return;

			var target = event.target || event.srcElement,
				innerPaySystemSection = this.paySystemBlockNode.querySelector('div.bx-soa-pp-inner-ps'),
				innerPaySystemCheckbox = this.paySystemBlockNode.querySelector('input[type=checkbox][name=PAY_CURRENT_ACCOUNT]'),
				fullPayFromInnerPaySystem = this.result.TOTAL && parseFloat(this.result.TOTAL.ORDER_TOTAL_LEFT_TO_PAY) === 0;

			var innerPsAction = BX.hasClass(target, 'bx-soa-pp-inner-ps') ? target : BX.findParent(target, {className: 'bx-soa-pp-inner-ps'}),
				actionSection = BX.hasClass(target, 'bx-soa-pp-company') ? target : BX.findParent(target, {className: 'bx-soa-pp-company'}),
				actionInput, selectedSection;

			if (innerPsAction)
			{
				if (target.nodeName == 'INPUT')
					innerPaySystemCheckbox.checked = !innerPaySystemCheckbox.checked;

				if (innerPaySystemCheckbox.checked)
				{
					BX.removeClass(innerPaySystemSection, 'bx-selected');
					innerPaySystemCheckbox.checked = false;
				}
				else
				{
					BX.addClass(innerPaySystemSection, 'bx-selected');
					innerPaySystemCheckbox.checked = true;
				}
			}
			else if (actionSection)
			{
				if (BX.hasClass(actionSection, 'bx-selected'))
					return BX.PreventDefault(event);

				if (innerPaySystemCheckbox && innerPaySystemCheckbox.checked && fullPayFromInnerPaySystem)
				{
					BX.addClass(actionSection, 'bx-selected');
					actionInput = actionSection.querySelector('input[type=checkbox]');
					actionInput.checked = true;
					BX.removeClass(innerPaySystemSection, 'bx-selected');
					innerPaySystemCheckbox.checked = false;
				}
				else
				{
					selectedSection = this.paySystemBlockNode.querySelector('.bx-soa-pp-company.bx-selected');
					BX.addClass(actionSection, 'bx-selected');
					actionInput = actionSection.querySelector('input[type=checkbox]');
					actionInput.checked = true;

					if (selectedSection)
					{
						BX.removeClass(selectedSection, 'bx-selected');
						selectedSection.querySelector('input[type=checkbox]').checked = false;
					}
				}
			}

			this.sendRequest();
		},

		editDeliveryBlock: function(active)
		{
			if (!this.deliveryBlockNode || !this.deliveryHiddenBlockNode || !this.result.DELIVERY)
				return;

			if (active)
				this.editActiveDeliveryBlock(true);
			else
				this.editFadeDeliveryBlock();

			this.checkPickUpShow();

			this.initialized.delivery = true;
		},

		editActiveDeliveryBlock: function(activeNodeMode)
		{
			var node = activeNodeMode ? this.deliveryBlockNode : this.deliveryHiddenBlockNode,
				deliveryContent, deliveryNode, locationNode, locationNodeCol;

			if (this.initialized.delivery)
			{
				BX.remove(BX.lastChild(node));
				node.appendChild(BX.firstChild(this.deliveryHiddenBlockNode));
			}
			else
			{
				deliveryContent = node.querySelector('.bx-soa-section-content');
				if (!deliveryContent)
				{
					deliveryContent = this.getNewContainer();
					node.appendChild(deliveryContent);
				}
				else
					BX.cleanNode(deliveryContent);

				this.getErrorContainer(deliveryContent);

				// Motor-Force customization: location + zip belong to Delivery block.
				locationNode = BX.create('DIV', {props: {className: 'bx_soa_location row'}});
				locationNodeCol = BX.create('DIV', {props: {className: 'col'}});
				this.getPersonTypeControl(locationNodeCol);
				this.getDeliveryLocationInput(locationNodeCol);
				locationNode.appendChild(locationNodeCol);
				deliveryContent.appendChild(locationNode);

				deliveryNode = BX.create('DIV', {props: {className: 'bx-soa-pp row'}});
				this.editDeliveryItems(deliveryNode);
				deliveryContent.appendChild(deliveryNode);
				this.editDeliveryInfo(deliveryNode);

				if (this.params.SHOW_COUPONS_DELIVERY == 'Y')
					this.editCoupons(deliveryContent);

				this.getBlockFooter(deliveryContent);
			}
		},

		editDeliveryItems: function(deliveryNode)
		{
			if (!this.result.DELIVERY || this.result.DELIVERY.length <= 0)
				return;

			var deliveryItemsContainer = BX.create('DIV', {props: {className: 'order-md-1 order-2 col-md-7 bx-soa-pp-item-container'}}),
				deliveryItemsContainerRow = BX.create('DIV', {props: {className: 'row'}}),
				deliveryItemNode, k;

			for (k = 0; k < this.deliveryPagination.currentPage.length; k++)
			{
				deliveryItemNode = this.createDeliveryItem(this.deliveryPagination.currentPage[k]);
				deliveryItemsContainerRow.appendChild(deliveryItemNode);
			}
			deliveryItemsContainer.appendChild(deliveryItemsContainerRow);

			if (this.deliveryPagination.show)
				this.showPagination('delivery', deliveryItemsContainer);

			deliveryNode.appendChild(deliveryItemsContainer);
		},

		editDeliveryInfo: function(deliveryNode)
		{
			if (!this.result.DELIVERY)
				return;

			var deliveryInfoContainer = BX.create('DIV', {props: {className: 'col-md-5 mb-lg-0 col-12 mb-3 order-md-2 order-1 bx-soa-pp-desc-container'}}),
				currentDelivery, logotype, name, logoNode,
				subTitle, label, title, price, period,
				clear, infoList, extraServices, extraServicesNode;

			BX.cleanNode(deliveryInfoContainer);
			currentDelivery = this.getSelectedDelivery();

			logoNode = BX.create('DIV', {props: {className: 'bx-soa-pp-company-image'}});
			logotype = this.getImageSources(currentDelivery, 'LOGOTIP');
			if (logotype && logotype.src_2x)
			{
				logoNode.setAttribute('style',
					'background-image: url("' + logotype.src_1x + '");' +
					'background-image: -webkit-image-set(url("' + logotype.src_1x + '") 1x, url("' + logotype.src_2x + '") 2x)'
				);
			}
			else
			{
				logotype = logotype && logotype.src_1x || this.defaultDeliveryLogo;
				logoNode.setAttribute('style', 'background-image: url("' + logotype + '");');
			}

			name = this.params.SHOW_DELIVERY_PARENT_NAMES != 'N' ? currentDelivery.NAME : currentDelivery.OWN_NAME;

			if (this.params.SHOW_DELIVERY_INFO_NAME == 'Y')
				subTitle = BX.create('DIV', {props: {className: 'bx-soa-pp-company-subTitle'}, text: name});

			label = BX.create('DIV', {
				props: {className: 'bx-soa-pp-company-logo'},
				children: [
					BX.create('DIV', {
						props: {className: 'bx-soa-pp-company-graf-container'},
						children: [logoNode]
					})
				]
			});
			title = BX.create('DIV', {
				props: {className: 'bx-soa-pp-company-block'},
				children: [
					BX.create('DIV', {props: {className: 'bx-soa-pp-company-desc'}, html: currentDelivery.DESCRIPTION}),
					currentDelivery.CALCULATE_DESCRIPTION
						? BX.create('DIV', {props: {className: 'bx-soa-pp-company-desc'}, html: currentDelivery.CALCULATE_DESCRIPTION})
						: null
				]
			});

			if (currentDelivery.PRICE >= 0)
			{
				price = BX.create('LI', {
					children: [
						BX.create('DIV', {
							props: {className: 'bx-soa-pp-list-termin'},
							html: this.params.MESS_PRICE + ':'
						}),
						BX.create('DIV', {
							props: {className: 'bx-soa-pp-list-description'},
							children: this.getDeliveryPriceNodes(currentDelivery)
						})
					]
				});
			}

			if (currentDelivery.PERIOD_TEXT && currentDelivery.PERIOD_TEXT.length)
			{
				period = BX.create('LI', {
					children: [
						BX.create('DIV', {props: {className: 'bx-soa-pp-list-termin'}, html: this.params.MESS_PERIOD + ':'}),
						BX.create('DIV', {props: {className: 'bx-soa-pp-list-description'}, html: currentDelivery.PERIOD_TEXT})
					]
				});
			}

			clear = BX.create('DIV', {style: {clear: 'both'}});
			infoList = BX.create('UL', {props: {className: 'bx-soa-pp-list'}, children: [price, period]});
			extraServices = this.getDeliveryExtraServices(currentDelivery);

			if (extraServices.length)
			{
				extraServicesNode = BX.create('DIV', {
					props: {className: 'bx-soa-pp-company-block'},
					children: extraServices
				});
			}

			deliveryInfoContainer.appendChild(
				BX.create('DIV', {
					props: {className: 'bx-soa-pp-company'},
					children: [subTitle, label, title, clear, extraServicesNode, infoList]
				})
			);
			deliveryNode.appendChild(deliveryInfoContainer);

			if (this.params.DELIVERY_NO_AJAX != 'Y')
				this.deliveryCachedInfo[currentDelivery.ID] = currentDelivery;
		},

		getDeliveryPriceNodes: function(delivery)
		{
			var priceNodesArray;

			if (typeof delivery.DELIVERY_DISCOUNT_PRICE !== 'undefined'
				&& parseFloat(delivery.DELIVERY_DISCOUNT_PRICE) != parseFloat(delivery.PRICE))
			{
				if (parseFloat(delivery.DELIVERY_DISCOUNT_PRICE) > parseFloat(delivery.PRICE))
					priceNodesArray = [delivery.DELIVERY_DISCOUNT_PRICE_FORMATED];
				else
					priceNodesArray = [
						delivery.DELIVERY_DISCOUNT_PRICE_FORMATED,
						BX.create('BR'),
						BX.create('SPAN', {props: {className: 'bx-price-old'}, html: delivery.PRICE_FORMATED})
					];
			}
			else
			{
				priceNodesArray = [delivery.PRICE_FORMATED];
			}

			return priceNodesArray;
		},

		getDeliveryExtraServices: function(delivery)
		{
			var extraServices = [], brake = false,
				i, currentService, serviceNode, serviceName, input;

			for (i in delivery.EXTRA_SERVICES)
			{
				if (!delivery.EXTRA_SERVICES.hasOwnProperty(i))
					continue;

				currentService = delivery.EXTRA_SERVICES[i];

				if (!currentService.canUserEditValue)
					continue;

				if (currentService.editControl.indexOf('this.checked') == -1)
				{
					serviceName = BX.create('LABEL', {
						html: BX.util.htmlspecialchars(currentService.name)
						+ (currentService.price ? ' (' + currentService.priceFormatted + ')' : '')
					});

					if (i == 0)
						brake = true;

					serviceNode = BX.create('DIV', {
						props: {className: 'form-group bx-soa-pp-field'},
						html: currentService.editControl
						+ (currentService.description && currentService.description.length
							? '<div class="bx-soa-service-small">' + BX.util.htmlspecialchars(currentService.description) + '</div>'
							: '')
					});

					BX.prepend(serviceName, serviceNode);
					input = serviceNode.querySelector('input[type=text]');
					if (!input)
						input = serviceNode.querySelector('select');

					if (input)
						BX.addClass(input, 'form-control');
				}
				else
				{
					serviceNode = BX.create('DIV', {
						props: {className: 'checkbox'},
						children: [
							BX.create('LABEL', {
								html: currentService.editControl + BX.util.htmlspecialchars(currentService.name)
								+ (currentService.price ? ' (' + currentService.priceFormatted + ')' : '')
								+ (currentService.description && currentService.description.length
									? '<div class="bx-soa-service-small">' + BX.util.htmlspecialchars(currentService.description) + '</div>'
									: '')
							})
						]
					});
				}

				extraServices.push(serviceNode);
			}

			brake && extraServices.unshift(BX.create('BR'));

			return extraServices;
		},

		editFadeDeliveryBlock: function()
		{
			var deliveryContent = this.deliveryBlockNode.querySelector('.bx-soa-section-content'), newContent;

			if (this.initialized.delivery)
			{
				this.deliveryHiddenBlockNode.appendChild(deliveryContent);
			}
			else
			{
				this.editActiveDeliveryBlock(false);
				BX.remove(BX.lastChild(this.deliveryBlockNode));
			}

			newContent = this.getNewContainer(true);
			this.deliveryBlockNode.appendChild(newContent);

			this.editFadeDeliveryContent(newContent);

			if (this.params.SHOW_COUPONS_DELIVERY == 'Y')
				this.editCouponsFade(newContent);
		},

		createDeliveryItem: function(item)
		{
			var checked = item.CHECKED == 'Y',
				deliveryId = parseInt(item.ID),
				labelNodes = [
					BX.create('INPUT', {
						props: {
							id: 'ID_DELIVERY_ID_' + deliveryId,
							name: 'DELIVERY_ID',
							type: 'checkbox',
							className: 'bx-soa-pp-company-checkbox',
							value: deliveryId,
							checked: checked
						}
					})
				],
				deliveryCached = this.deliveryCachedInfo[deliveryId],
				logotype, label, title, itemNode, logoNode;

			logoNode = BX.create('DIV', {props: {className: 'bx-soa-pp-company-image'}});
			logotype = this.getImageSources(item, 'LOGOTIP');
			if (logotype && logotype.src_2x)
			{
				logoNode.setAttribute('style',
					'background-image: url("' + logotype.src_1x + '");' +
					'background-image: -webkit-image-set(url("' + logotype.src_1x + '") 1x, url("' + logotype.src_2x + '") 2x)'
				);
			}
			else
			{
				logotype = logotype && logotype.src_1x || this.defaultDeliveryLogo;
				logoNode.setAttribute('style', 'background-image: url("' + logotype + '");');
			}
			labelNodes.push(logoNode);

			if (item.PRICE >= 0 || typeof item.DELIVERY_DISCOUNT_PRICE !== 'undefined')
			{
				labelNodes.push(
					BX.create('DIV', {
						props: {className: 'bx-soa-pp-delivery-cost'},
						html: typeof item.DELIVERY_DISCOUNT_PRICE !== 'undefined'
							? item.DELIVERY_DISCOUNT_PRICE_FORMATED
							: item.PRICE_FORMATED})
				);
			}
			else if (deliveryCached && (deliveryCached.PRICE >= 0 || typeof deliveryCached.DELIVERY_DISCOUNT_PRICE !== 'undefined'))
			{
				labelNodes.push(
					BX.create('DIV', {
						props: {className: 'bx-soa-pp-delivery-cost'},
						html: typeof deliveryCached.DELIVERY_DISCOUNT_PRICE !== 'undefined'
							? deliveryCached.DELIVERY_DISCOUNT_PRICE_FORMATED
							: deliveryCached.PRICE_FORMATED})
				);
			}

			label = BX.create('DIV', {
				props: {
					className: 'bx-soa-pp-company-graf-container'
					+ (item.CALCULATE_ERRORS || deliveryCached && deliveryCached.CALCULATE_ERRORS ? ' bx-bd-waring' : '')},
				children: labelNodes
			});

			if (this.params.SHOW_DELIVERY_LIST_NAMES == 'Y')
			{
				title = BX.create('DIV', {
					props: {className: 'bx-soa-pp-company-smalltitle'},
					text: this.params.SHOW_DELIVERY_PARENT_NAMES != 'N' ? item.NAME : item.OWN_NAME
				});
			}

			itemNode = BX.create('DIV', {
				props: {className: 'bx-soa-pp-company col-6'},
				children: [label, title],
				events: {click: BX.proxy(this.selectDelivery, this)}
			});
			checked && BX.addClass(itemNode, 'bx-selected');

			if (checked && this.result.LAST_ORDER_DATA.PICK_UP)
				this.lastSelectedDelivery = deliveryId;

			return itemNode;
		},

		editFadeDeliveryContent: function(node)
		{
			var selectedDelivery = this.getSelectedDelivery(),
				name = this.params.SHOW_DELIVERY_PARENT_NAMES != 'N' ? selectedDelivery.NAME : selectedDelivery.OWN_NAME,
				errorNode = this.deliveryHiddenBlockNode.querySelector('div.alert.alert-danger'),
				warningNode = this.deliveryHiddenBlockNode.querySelector('div.alert.alert-warning.alert-show'),
				extraService, logotype, imgSrc, arNodes, i;

			if (errorNode && errorNode.innerHTML)
				node.appendChild(errorNode.cloneNode(true));
			else
				this.getErrorContainer(node);

			if (warningNode && warningNode.innerHTML)
				node.appendChild(warningNode.cloneNode(true));

			if (selectedDelivery && selectedDelivery.NAME)
			{
				logotype = this.getImageSources(selectedDelivery, 'LOGOTIP');
				imgSrc = logotype && logotype.src_1x || this.defaultDeliveryLogo;
				arNodes = [
					BX.create('IMG', {props: {src: imgSrc, alt: ''}, style: {height: '18px'}}),
					BX.create('STRONG', {text: name})
				];

				if (this.params.DELIVERY_FADE_EXTRA_SERVICES == 'Y' && BX.util.object_keys(selectedDelivery.EXTRA_SERVICES).length)
				{
					arNodes.push(BX.create('BR'));

					for (i in selectedDelivery.EXTRA_SERVICES)
					{
						if (selectedDelivery.EXTRA_SERVICES.hasOwnProperty(i))
						{
							extraService = selectedDelivery.EXTRA_SERVICES[i];
							if (extraService.value && extraService.value != 'N' && extraService.canUserEditValue)
							{
								arNodes.push(BX.create('BR'));
								arNodes.push(BX.create('STRONG', {text: extraService.name + ': '}));
								arNodes.push(extraService.viewControl);
							}
						}
					}
				}

				node.appendChild(
					BX.create('DIV', {
						props: {className: "row"},
						children: [
							BX.create('DIV', {
								props: {className: 'col-sm-9 bx-soa-pp-company-selected'},
								children: arNodes
							}),
							BX.create('DIV', {
								props: {className: 'col-sm bx-soa-pp-price'},
								children: this.getDeliveryPriceNodes(selectedDelivery)
							})
						]
					})
				);
			}
			else
				node.appendChild(BX.create('STRONG', {text: BX.message('SOA_DELIVERY_SELECT_ERROR')}));

			node.appendChild(BX.create('DIV', {style: {clear: 'both'}}));
			BX.bind(node.querySelector('.alert.alert-danger'), 'click', BX.proxy(this.showByClick, this));
			BX.bind(node.querySelector('.alert.alert-warning'), 'click', BX.proxy(this.showByClick, this));
		},

		selectDelivery: function(event)
		{
			if (!this.orderBlockNode)
				return;

			var target = event.target || event.srcElement,
				actionSection =  BX.hasClass(target, 'bx-soa-pp-company') ? target : BX.findParent(target, {className: 'bx-soa-pp-company'}),
				selectedSection = this.deliveryBlockNode.querySelector('.bx-soa-pp-company.bx-selected'),
				actionInput, selectedInput;

			if (BX.hasClass(actionSection, 'bx-selected'))
				return BX.PreventDefault(event);

			if (actionSection)
			{
				actionInput = actionSection.querySelector('input[type=checkbox]');
				BX.addClass(actionSection, 'bx-selected');
				actionInput.checked = true;
			}
			if (selectedSection)
			{
				selectedInput = selectedSection.querySelector('input[type=checkbox]');
				BX.removeClass(selectedSection, 'bx-selected');
				selectedInput.checked = false;
			}

			this.sendRequest();
		},

		getSelectedDelivery: function()
		{
			var deliveryCheckbox = this.deliveryBlockNode.querySelector('input[type=checkbox][name=DELIVERY_ID]:checked'),
				currentDelivery = false,
				deliveryId, i;

			if (!deliveryCheckbox)
				deliveryCheckbox = this.deliveryHiddenBlockNode.querySelector('input[type=checkbox][name=DELIVERY_ID]:checked');

			if (!deliveryCheckbox)
				deliveryCheckbox = this.deliveryHiddenBlockNode.querySelector('input[type=hidden][name=DELIVERY_ID]');

			if (deliveryCheckbox)
			{
				deliveryId = deliveryCheckbox.value;

				for (i in this.result.DELIVERY)
				{
					if (this.result.DELIVERY[i].ID == deliveryId)
					{
						currentDelivery = this.result.DELIVERY[i];
						break;
					}
				}
			}

			return currentDelivery;
		},

		activatePickUp: function(deliveryName)
		{
			if (!this.pickUpBlockNode || !this.pickUpHiddenBlockNode)
				return;

			this.pickUpBlockNode.style.display = '';
			this.pickUpBlockNode.querySelector('[data-entity="section-title"]').innerHTML =
				'<span class="bx-soa-section-title-count"></span>' + BX.util.htmlspecialchars(deliveryName);

			if (BX.hasClass(this.pickUpBlockNode, 'bx-active'))
				return;

			BX.addClass(this.pickUpBlockNode, 'bx-active');
			this.pickUpBlockNode.style.display = '';
		},

		deactivatePickUp: function()
		{
			if (!this.pickUpBlockNode || !this.pickUpHiddenBlockNode)
				return;

			if (!BX.hasClass(this.pickUpBlockNode, 'bx-active'))
				return;

			BX.removeClass(this.pickUpBlockNode, 'bx-active');
			this.pickUpBlockNode.style.display = 'none';
		},

		editPickUpBlock: function(active)
		{
			if (!this.pickUpBlockNode || !this.pickUpHiddenBlockNode || !BX.hasClass(this.pickUpBlockNode, 'bx-active') || !this.result.DELIVERY)
				return;

			this.initialized.pickup = false;

			if (active)
				this.editActivePickUpBlock(true);
			else
				this.editFadePickUpBlock();

			this.initialized.pickup = true;
		},

		editActivePickUpBlock: function(activeNodeMode)
		{
			var node = activeNodeMode ? this.pickUpBlockNode : this.pickUpHiddenBlockNode,
				pickUpContent, pickUpContentCol;

			if (this.initialized.pickup)
			{
				BX.remove(BX.lastChild(node));
				node.appendChild(BX.firstChild(this.pickUpHiddenBlockNode));

				if (
					this.params.SHOW_NEAREST_PICKUP === 'Y'
					&& this.maps
					&& !this.maps.maxWaitTimeExpired
				)
				{
					this.maps.maxWaitTimeExpired = true;
					this.initPickUpPagination();
					this.editPickUpList(true);
					this.pickUpFinalAction();
				}

				if (this.maps && !this.pickUpMapFocused)
				{
					this.pickUpMapFocused = true;
					setTimeout(BX.proxy(this.maps.pickUpMapFocusWaiter, this.maps), 200);
				}
			}
			else
			{
				pickUpContent = node.querySelector('.bx-soa-section-content');
				if (!pickUpContent)
				{
					pickUpContent = this.getNewContainer();
					node.appendChild(pickUpContent);
				}
				BX.cleanNode(pickUpContent);

				pickUpContentCol = BX.create('DIV', {props: {className: 'col'}});
				this.editPickUpMap(pickUpContentCol);
				this.editPickUpLoader(pickUpContentCol);

				pickUpContent.appendChild(
					BX.create('DIV', {
						props: {className: 'bx_soa_pickup row'},
						children: [pickUpContentCol]
					})
				);

				if (this.params.SHOW_PICKUP_MAP != 'Y' || this.params.SHOW_NEAREST_PICKUP != 'Y')
				{
					this.initPickUpPagination();
					this.editPickUpList(true);
					this.pickUpFinalAction();
				}

				this.getBlockFooter(pickUpContent);
			}
		},

		editFadePickUpBlock: function()
		{
			var pickUpContent = this.pickUpBlockNode.querySelector('.bx-soa-section-content'), newContent;

			if (this.initialized.pickup)
			{
				this.pickUpHiddenBlockNode.appendChild(pickUpContent);
			}
			else
			{
				this.editActivePickUpBlock(false);
				BX.remove(BX.lastChild(this.pickUpBlockNode));
			}

			newContent = this.getNewContainer();
			this.pickUpBlockNode.appendChild(newContent);

			this.editFadePickUpContent(newContent);
		},

		editFadePickUpContent: function(pickUpContainer)
		{
			var selectedPickUp = this.getSelectedPickUp(), html = '', logotype, imgSrc;

			if (selectedPickUp)
			{
				if (this.params.SHOW_STORES_IMAGES == 'Y')
				{
					logotype = this.getImageSources(selectedPickUp, 'IMAGE_ID');
					imgSrc = logotype.src_1x || this.defaultStoreLogo;

					html += '<img src="' + imgSrc + '" class="bx-soa-pickup-preview-img">';
				}

				html += '<strong>' + BX.util.htmlspecialchars(selectedPickUp.TITLE) + '</strong>';
				if (selectedPickUp.ADDRESS)
					html += '<br><strong>' + BX.message('SOA_PICKUP_ADDRESS') + ':</strong> ' + BX.util.htmlspecialchars(selectedPickUp.ADDRESS);

				if (selectedPickUp.PHONE)
					html += '<br><strong>' + BX.message('SOA_PICKUP_PHONE') + ':</strong> ' + BX.util.htmlspecialchars(selectedPickUp.PHONE);

				if (selectedPickUp.SCHEDULE)
					html += '<br><strong>' + BX.message('SOA_PICKUP_WORK') + ':</strong> ' + BX.util.htmlspecialchars(selectedPickUp.SCHEDULE);

				if (selectedPickUp.DESCRIPTION)
					html += '<br><strong>' + BX.message('SOA_PICKUP_DESC') + ':</strong> ' + BX.util.htmlspecialchars(selectedPickUp.DESCRIPTION);

				pickUpContainer.innerHTML = html;

				if (this.params.SHOW_STORES_IMAGES == 'Y')
				{
					BX.bind(pickUpContainer.querySelector('.bx-soa-pickup-preview-img'), 'click', BX.delegate(function(e){
						this.popupShow(e, logotype && logotype.src_orig || imgSrc);
					}, this));
				}
			}
		},

		getPickUpInfoArray: function(storeIds)
		{
			if (!storeIds || storeIds.length <= 0)
				return [];

			var arr = [], i;

			for (i = 0; i < storeIds.length; i++)
				if (this.result.STORE_LIST[storeIds[i]])
					arr.push(this.result.STORE_LIST[storeIds[i]]);

			return arr;
		},

		getSelectedPickUp: function()
		{
			var pickUpInput = BX('BUYER_STORE'),
				currentPickUp, pickUpId,
				allStoresList = this.result.STORE_LIST,
				stores, i;

			if (pickUpInput)
			{
				pickUpId = pickUpInput.value;
				currentPickUp = allStoresList[pickUpId];

				if (!currentPickUp)
				{
					stores = this.getSelectedDelivery().STORE;
					if (stores)
					{
						for (i in stores)
						{
							if (stores.hasOwnProperty(i))
							{
								currentPickUp = allStoresList[stores[i]];
								pickUpInput.setAttribute('value', stores[i]);
								break;
							}
						}
					}
				}
			}

			return currentPickUp;
		},

		/**
		 * Checking delivery for pick ups. Displaying/hiding pick up block node.
		 */
		checkPickUpShow: function()
		{
			var currentDelivery = this.getSelectedDelivery(), name, stores;

			if (currentDelivery && currentDelivery.STORE && currentDelivery.STORE.length)
				stores = this.getPickUpInfoArray(currentDelivery.STORE);

			if (stores && stores.length)
			{
				name = this.params.SHOW_DELIVERY_PARENT_NAMES != 'N' ? currentDelivery.NAME : currentDelivery.OWN_NAME;
				currentDelivery.STORE_MAIN = currentDelivery.STORE;
				this.activatePickUp(name);
				this.editSection(this.pickUpBlockNode);
			}
			else
			{
				this.deactivatePickUp();
			}
		},

		geoLocationSuccessCallback: function(result)
		{
			var activeStores,
				currentDelivery = this.getSelectedDelivery();

			if (currentDelivery && currentDelivery.STORE)
			{
				activeStores = this.getPickUpInfoArray(currentDelivery.STORE);
			}

			if (activeStores && activeStores.length >= this.options.pickUpMap.minToShowNearestBlock)
			{
				this.editPickUpRecommendList(result.geoObjects.get(0));
			}

			this.initPickUpPagination();
			this.editPickUpList(true);
			this.pickUpFinalAction();
		},

		geoLocationFailCallback: function()
		{
			this.initPickUpPagination();
			this.editPickUpList(true);
			this.pickUpFinalAction();
		},

		initMaps: function()
		{
			this.maps = BX.Sale.OrderAjaxComponent.Maps.init(this);
			if (this.maps)
			{
				this.mapsReady = true;
				this.resizeMapContainers();

				if (this.params.SHOW_PICKUP_MAP === 'Y' && BX('pickUpMap'))
				{
					var currentDelivery = this.getSelectedDelivery();
					if (currentDelivery && currentDelivery.STORE && currentDelivery.STORE.length)
					{
						var activeStores = this.getPickUpInfoArray(currentDelivery.STORE);
					}

					if (activeStores && activeStores.length)
					{
						var selected = this.getSelectedPickUp();
						this.maps.initializePickUpMap(selected);

						if (this.params.SHOW_NEAREST_PICKUP === 'Y')
						{
							this.maps.showNearestPickups(BX.proxy(this.geoLocationSuccessCallback, this), BX.proxy(this.geoLocationFailCallback, this));
						}

						this.maps.buildBalloons(activeStores);
					}
				}

				if (this.params.SHOW_MAP_IN_PROPS === 'Y' && BX('propsMap'))
				{
					var propsMapData = this.getPropertyMapData();
					this.maps.initializePropsMap(propsMapData);
				}
			}
		},

		getPropertyMapData: function()
		{
			var currentProperty, locationId, k;
			var data = this.options.propertyMap.defaultMapPosition;

			for (k in this.result.ORDER_PROP.properties)
			{
				if (this.result.ORDER_PROP.properties.hasOwnProperty(k))
				{
					currentProperty = this.result.ORDER_PROP.properties[k];
					if (currentProperty.IS_LOCATION == 'Y')
					{
						locationId = currentProperty.ID;
						break;
					}
				}
			}

			if (this.locations[locationId] && this.locations[locationId][0] && this.locations[locationId][0].coordinates)
			{
				currentProperty = this.locations[locationId][0].coordinates;

				var long = parseFloat(currentProperty.LONGITUDE),
					lat = parseFloat(currentProperty.LATITUDE);

				if (!isNaN(long) && !isNaN(lat) && long != 0 && lat != 0)
				{
					data.lon = long;
					data.lat = lat;
				}
			}

			return data;
		},

		resizeMapContainers: function()
		{
			var pickUpMapContainer = BX('pickUpMap'),
				propertyMapContainer = BX('propsMap'),
				resizeBy = this.propsBlockNode,
				width, height;

			if (resizeBy && (pickUpMapContainer || propertyMapContainer))
			{
				width = resizeBy.clientWidth;
				height = parseInt(width / 16 * 9);

				if (this.params.SHOW_PICKUP_MAP === 'Y' && pickUpMapContainer)
				{
					pickUpMapContainer.style.height = height + 'px';
				}

				if (this.params.SHOW_MAP_IN_PROPS === 'Y' && propertyMapContainer)
				{
					propertyMapContainer.style.height = height + 'px';
				}
			}
		},

		editPickUpMap: function(pickUpContent)
		{
			pickUpContent.appendChild(BX.create('DIV', {
				props: {id: 'pickUpMap'},
				style: {width: '100%', marginBottom: '10px'}
			}));
		},

		editPickUpLoader: function(pickUpContent)
		{
			pickUpContent.appendChild(
				BX.create('DIV', {
					props: {id: 'pickUpLoader', className: 'text-center'},
					children: [BX.create('IMG', {props: {src: this.templateFolder + '/images/loader.gif'}})]
				})
			);
		},

		editPickUpList: function(isNew)
		{
			if (!this.pickUpPagination.currentPage || !this.pickUpPagination.currentPage.length)
				return;

			BX.remove(BX('pickUpLoader'));

			var pickUpList = BX.create('DIV', {props: {className: 'bx-soa-pickup-list main'}}),
				buyerStoreInput = BX('BUYER_STORE'),
				selectedStore,
				container, i, found = false,
				recommendList, selectedDelivery, currentStore, storeNode;

			if (buyerStoreInput)
				selectedStore = buyerStoreInput.value;

			recommendList = this.pickUpBlockNode.querySelector('.bx-soa-pickup-list.recommend');
			if (!recommendList)
				recommendList = this.pickUpHiddenBlockNode.querySelector('.bx-soa-pickup-list.recommend');

			if (!recommendList || !recommendList.querySelector('.bx-soa-pickup-list-item.bx-selected'))
			{
				selectedDelivery = this.getSelectedDelivery();
				if (selectedDelivery && selectedDelivery.STORE)
				{
					for (i = 0; i < selectedDelivery.STORE.length; i++)
						if (selectedDelivery.STORE[i] == selectedStore)
							found = true;
				}
			}
			else
				found = true;

			for (i = 0; i < this.pickUpPagination.currentPage.length; i++)
			{
				currentStore = this.pickUpPagination.currentPage[i];

				if (currentStore.ID == selectedStore || parseInt(selectedStore) == 0 || !found)
				{
					selectedStore = buyerStoreInput.value = currentStore.ID;
					found = true;
				}

				storeNode = this.createPickUpItem(currentStore, {selected: currentStore.ID == selectedStore});
				pickUpList.appendChild(storeNode);
			}

			if (!!isNew)
			{
				container = this.pickUpHiddenBlockNode.querySelector('.bx_soa_pickup>.col');
				if (!container)
					container = this.pickUpBlockNode.querySelector('.bx_soa_pickup>.col');

				container.appendChild(
					BX.create('DIV', {
						props: {className: 'bx-soa-pickup-subTitle'},
						html: this.params.MESS_PICKUP_LIST
					})
				);
				container.appendChild(pickUpList);
			}
			else
			{
				container = this.pickUpBlockNode.querySelector('.bx-soa-pickup-list.main');
				BX.insertAfter(pickUpList, container);
				BX.remove(container);
			}

			this.pickUpPagination.show && this.showPagination('pickUp', pickUpList);
		},

		pickUpFinalAction: function()
		{
			var selectedDelivery = this.getSelectedDelivery(),
				deliveryChanged;

			if (selectedDelivery)
			{
				deliveryChanged = this.lastSelectedDelivery !== parseInt(selectedDelivery.ID);
				this.lastSelectedDelivery = parseInt(selectedDelivery.ID);
			}

			if (deliveryChanged && this.pickUpBlockNode.id !== this.activeSectionId)
			{
				if (this.pickUpBlockNode.id !== this.activeSectionId)
				{
					this.editFadePickUpContent(BX.lastChild(this.pickUpBlockNode));
				}

				BX.removeClass(this.pickUpBlockNode, 'bx-step-completed');
			}

			this.maps && this.maps.pickUpFinalAction();
		},

		getStoreInfoHtml: function(currentStore)
		{
			var html = '';

			if (currentStore.ADDRESS)
				html += BX.message('SOA_PICKUP_ADDRESS') + ': ' + BX.util.htmlspecialchars(currentStore.ADDRESS) + '<br>';

			if (currentStore.PHONE)
				html += BX.message('SOA_PICKUP_PHONE') + ': ' + BX.util.htmlspecialchars(currentStore.PHONE) + '<br>';

			if (currentStore.SCHEDULE)
				html += BX.message('SOA_PICKUP_WORK') + ': ' + BX.util.htmlspecialchars(currentStore.SCHEDULE) + '<br>';

			if (currentStore.DESCRIPTION)
				html += BX.message('SOA_PICKUP_DESC') + ': ' + BX.util.htmlspecialchars(currentStore.DESCRIPTION) + '<br>';

			return html;
		},

		createPickUpItem: function(currentStore, options)
		{
			options = options || {};

			var imgClassName = 'bx-soa-pickup-l-item-detail',
				buttonClassName = 'bx-soa-pickup-l-item-btn',
				logoNode, logotype, html, storeNode, imgSrc;

			if (this.params.SHOW_STORES_IMAGES === 'Y')
			{
				logotype = this.getImageSources(currentStore, 'IMAGE_ID');
				imgSrc = logotype && logotype.src_1x || this.defaultStoreLogo;
				logoNode = BX.create('IMG', {
					props: {
						src: imgSrc,
						className: 'bx-soa-pickup-l-item-img'
					},
					events: {
						click: BX.delegate(function(e){
							this.popupShow(e, logotype && logotype.src_orig || imgSrc);
						}, this)
					}
				});
			}
			else
			{
				imgClassName += ' no-image';
				buttonClassName += ' no-image';
			}

			html = this.getStoreInfoHtml(currentStore);
			storeNode = BX.create('DIV', {
				props: {className: 'bx-soa-pickup-list-item', id: 'store-' + currentStore.ID},
				children: [
					BX.create('DIV', {
						props: {className: 'bx-soa-pickup-l-item-adress'},
						children: options.distance ? [
							BX.util.htmlspecialchars(currentStore.ADDRESS),
							' ( ~' + options.distance + ' ' + BX.message('SOA_DISTANCE_KM') + ' ) '
						] : [BX.util.htmlspecialchars(currentStore.ADDRESS)]
					}),
					BX.create('DIV', {
						props: {className: imgClassName},
						children: [
							logoNode,
							BX.create('DIV', {props: {className: 'bx-soa-pickup-l-item-name'}, text: currentStore.TITLE}),
							BX.create('DIV', {props: {className: 'bx-soa-pickup-l-item-desc'}, html: html})
						]
					}),
					BX.create('DIV', {
						props: {className: buttonClassName},
						children: [
							BX.create('A', {
								props: {href: '', className: 'btn btn-sm btn-primary'},
								html: this.params.MESS_SELECT_PICKUP,
								events: {
									click: BX.delegate(function(event){
										this.selectStore(event);
										this.clickNextAction(event)
									}, this)
								}
							})
						]
					})
				],
				events: {
					click: BX.proxy(this.selectStore, this)
				}
			});

			if (options.selected)
				BX.addClass(storeNode, 'bx-selected');

			return storeNode;
		},

		editPickUpRecommendList: function(geoLocation)
		{
			if (!this.maps || !this.maps.canUseRecommendList() || !geoLocation)
			{
				return;
			}

			BX.remove(BX('pickUpLoader'));

			var recommendList = BX.create('DIV', {props: {className: 'bx-soa-pickup-list recommend'}}),
				buyerStoreInput = BX('BUYER_STORE'),
				selectedDelivery = this.getSelectedDelivery();

			var i, currentStore, currentStoreId, distance, storeNode, container;

			var recommendedStoreIds = this.maps.getRecommendedStoreIds(geoLocation);
			for (i = 0; i < recommendedStoreIds.length; i++)
			{
				currentStoreId = recommendedStoreIds[i];
				currentStore = this.getPickUpInfoArray([currentStoreId])[0];

				if (i === 0 && parseInt(selectedDelivery.ID) !== this.lastSelectedDelivery)
				{
					buyerStoreInput.value = parseInt(currentStoreId);
				}

				distance = this.maps.getDistance(geoLocation, currentStoreId);
				storeNode = this.createPickUpItem(currentStore, {
					selected: buyerStoreInput.value === currentStoreId,
					distance: distance
				});
				recommendList.appendChild(storeNode);

				if (selectedDelivery.STORE_MAIN)
				{
					selectedDelivery.STORE_MAIN.splice(selectedDelivery.STORE_MAIN.indexOf(currentStoreId), 1);
				}
			}

			container = this.pickUpHiddenBlockNode.querySelector('.bx_soa_pickup>.col');
			if (!container)
			{
				container = this.pickUpBlockNode.querySelector('.bx_soa_pickup>.col');
			}

			container.appendChild(
				BX.create('DIV', {
					props: {className: 'bx-soa-pickup-subTitle'},
					html: this.params.MESS_NEAREST_PICKUP_LIST
				})
			);
			container.appendChild(recommendList);
		},

		selectStore: function(event)
		{
			var storeItem,
				storeInput = BX('BUYER_STORE'),
				selectedPickUp, storeItemId, i, k, page,
				target, h1, h2;

			if (BX.type.isString(event))
			{
				storeItem = BX('store-' + event);
				if (!storeItem)
				{
					for (i = 0; i < this.pickUpPagination.pages.length; i++)
					{
						page = this.pickUpPagination.pages[i];
						for (k = 0; k < page.length; k++)
						{
							if (page[k].ID == event)
							{
								this.showPickUpItemsPage(++i);
								break;
							}
						}
					}
					storeItem = BX('store-' + event);
				}
			}
			else
			{
				target = event.target || event.srcElement;
				storeItem = BX.hasClass(target, 'bx-soa-pickup-list-item')
					? target
					: BX.findParent(target, {className: 'bx-soa-pickup-list-item'});
			}

			if (storeItem && storeInput)
			{
				if (BX.hasClass(storeItem, 'bx-selected'))
					return;

				selectedPickUp = this.pickUpBlockNode.querySelector('.bx-selected');
				storeItemId = storeItem.id.substr('store-'.length);

				BX.removeClass(selectedPickUp, 'bx-selected');

				h1 = storeItem.clientHeight;
				storeItem.style.overflow = 'hidden';
				BX.addClass(storeItem, 'bx-selected');
				h2 = storeItem.clientHeight;
				storeItem.style.height = h1 + 'px';

				new BX.easing({
					duration: 300,
					start: {height: h1, opacity: 0},
					finish: {height: h2, opacity: 100},
					transition: BX.easing.transitions.quad,
					step: function(state){
						storeItem.style.height = state.height + "px";
					},
					complete: function(){
						storeItem.removeAttribute('style');
					}
				}).animate();

				storeInput.setAttribute('value', storeItemId);
				this.maps && this.maps.selectBalloon(storeItemId);
			}
		},

		getDeliverySortedArray: function(objDelivery)
		{
			var deliveries = [],
				problemDeliveries = [],
				sortFunc = function(a, b){
					var sort = parseInt(a.SORT) - parseInt(b.SORT);
					if (sort === 0)
					{
						return a.OWN_NAME.toLowerCase() > b.OWN_NAME.toLowerCase()
							? 1
							: (a.OWN_NAME.toLowerCase() < b.OWN_NAME.toLowerCase() ? -1 : 0);
					}
					else
					{
						return sort;
					}
				},
				k;

			for (k in objDelivery)
			{
				if (objDelivery.hasOwnProperty(k))
				{
					if (this.params.SHOW_NOT_CALCULATED_DELIVERIES === 'L' && objDelivery[k].CALCULATE_ERRORS)
					{
						problemDeliveries.push(objDelivery[k]);
					}
					else
					{
						deliveries.push(objDelivery[k]);
					}
				}
			}

			deliveries.sort(sortFunc);
			problemDeliveries.sort(sortFunc);

			return deliveries.concat(problemDeliveries);
		},

		editPropsBlock: function(active)
		{
			if (!this.propsBlockNode || !this.propsHiddenBlockNode || !this.result.ORDER_PROP)
				return;

			if (active)
				this.editActivePropsBlock(true);
			else
				this.editFadePropsBlock();

			this.initialized.props = true;
		},

		editActivePropsBlock: function(activeNodeMode)
		{
			var node = activeNodeMode ? this.propsBlockNode : this.propsHiddenBlockNode,
				propsContent, propsNode, selectedDelivery, showPropMap = false, i, validationErrors;

			if (this.initialized.props)
			{
				BX.remove(BX.lastChild(node));
				node.appendChild(BX.firstChild(this.propsHiddenBlockNode));
				this.maps && setTimeout(BX.proxy(this.maps.propsMapFocusWaiter, this.maps), 200);
			}
			else
			{
				propsContent = node.querySelector('.bx-soa-section-content');
				if (!propsContent)
				{
					propsContent = this.getNewContainer();
					node.appendChild(propsContent);
				}
				else
					BX.cleanNode(propsContent);

				this.getErrorContainer(propsContent);

				propsNode = BX.create('DIV', {props: {className: 'row'}});
				selectedDelivery = this.getSelectedDelivery();

				if (
					selectedDelivery && this.params.SHOW_MAP_IN_PROPS === 'Y'
					&& this.params.SHOW_MAP_FOR_DELIVERIES && this.params.SHOW_MAP_FOR_DELIVERIES.length
				)
				{
					for (i = 0; i < this.params.SHOW_MAP_FOR_DELIVERIES.length; i++)
					{
						if (parseInt(selectedDelivery.ID) === parseInt(this.params.SHOW_MAP_FOR_DELIVERIES[i]))
						{
							showPropMap = true;
							break;
						}
					}
				}

				this.editPropsItems(propsNode);
				showPropMap && this.editPropsMap(propsNode);

				if (this.params.HIDE_ORDER_DESCRIPTION !== 'Y')
				{
					this.editPropsComment(propsNode);
				}

				propsContent.appendChild(propsNode);
				this.getBlockFooter(propsContent);

				if (this.propsBlockNode.getAttribute('data-visited') === 'true')
				{
					validationErrors = this.isValidPropertiesBlock(true);
					if (validationErrors.length)
						BX.addClass(this.propsBlockNode, 'bx-step-error');
					else
						BX.removeClass(this.propsBlockNode, 'bx-step-error');
				}
			}
		},

		editFadePropsBlock: function()
		{
			var propsContent = this.propsBlockNode.querySelector('.bx-soa-section-content'), newContent;

			if (this.initialized.props)
			{
				this.propsHiddenBlockNode.appendChild(propsContent);
			}
			else
			{
				this.editActivePropsBlock(false);
				BX.remove(BX.lastChild(this.propsBlockNode));
			}

			newContent = this.getNewContainer();
			this.propsBlockNode.appendChild(newContent);

			this.editFadePropsContent(newContent);
		},

		editFadePropsContent: function(node)
		{
			if (!node || !this.locationsInitialized)
				return;

			var errorNode = this.propsHiddenBlockNode.querySelector('.alert'),
				personType = this.getSelectedPersonType(),
				fadeParamName, props,
				group, property, groupIterator, propsIterator, i, validPropsErrors;

			BX.cleanNode(node);

			if (errorNode)
				node.appendChild(errorNode.cloneNode(true));

			if (personType)
			{
				fadeParamName = 'PROPS_FADE_LIST_' + personType.ID;
				props = this.params[fadeParamName];
			}

			if (!props || props.length === 0)
			{
				node.innerHTML += '<strong>' + BX.message('SOA_ORDER_PROPS') + '</strong>';
			}
			else
			{
				groupIterator = this.fadedPropertyCollection.getGroupIterator();
				while (group = groupIterator())
				{
					propsIterator = group.getIterator();
					while (property = propsIterator())
					{
						for (i = 0; i < props.length; i++)
							if (props[i] == property.getId() && property.getSettings()['IS_ZIP'] != 'Y')
								this.getPropertyRowNode(property, node, true);
					}
				}
			}

			if (this.propsBlockNode.getAttribute('data-visited') === 'true')
			{
				validPropsErrors = this.isValidPropertiesBlock();
				if (validPropsErrors.length)
					this.showError(this.propsBlockNode, validPropsErrors);
			}

			BX.bind(node.querySelector('.alert.alert-danger'), 'click', BX.proxy(this.showByClick, this));
			BX.bind(node.querySelector('.alert.alert-warning'), 'click', BX.proxy(this.showByClick, this));
		},

		editPropsItems: function(propsNode)
		{
			if (!this.result.ORDER_PROP || !this.propertyCollection)
				return;

			var propsItemsContainer = BX.create('DIV', {props: {className: 'col-sm-12 bx-soa-customer'}}),
				group, property, groupIterator = this.propertyCollection.getGroupIterator(), propsIterator;

			if (!propsItemsContainer)
				propsItemsContainer = this.propsBlockNode.querySelector('.col-sm-12.bx-soa-customer');

			while (group = groupIterator())
			{
				propsIterator =  group.getIterator();
				while (property = propsIterator())
				{
					if (
						this.deliveryLocationInfo.loc == property.getId()
						|| this.deliveryLocationInfo.zip == property.getId()
						|| this.deliveryLocationInfo.city == property.getId()
					)
						continue;

					this.getPropertyRowNode(property, propsItemsContainer, false);
				}
			}

			propsNode.appendChild(propsItemsContainer);
		},

		getPropertyRowNode: function(property, propsItemsContainer, disabled)
		{
			var propsItemNode = BX.create('DIV'),
				textHtml = '',
				propertyType = property.getType() || '',
				propertyDesc = property.getDescription() || '',
				label;

			if (disabled)
			{
				propsItemNode.innerHTML = '<strong>' + BX.util.htmlspecialchars(property.getName()) + ':</strong> ';
			}
			else
			{
				BX.addClass(propsItemNode, "form-group bx-soa-customer-field");

				if (property.isRequired())
					textHtml += '<span class="bx-authform-starrequired">*</span> ';

				textHtml += BX.util.htmlspecialchars(property.getName());
				if (propertyDesc.length && propertyType != 'STRING' && propertyType != 'NUMBER' && propertyType != 'DATE')
					textHtml += ' <small>(' + BX.util.htmlspecialchars(propertyDesc) + ')</small>';

				label = BX.create('LABEL', {
					attrs: {'for': 'soa-property-' + property.getId()},
					props: {className: 'bx-soa-custom-label'},
					html: textHtml
				});
				propsItemNode.setAttribute('data-property-id-row', property.getId());
				propsItemNode.appendChild(label);
			}

			switch (propertyType)
			{
				case 'LOCATION':
					this.insertLocationProperty(property, propsItemNode, disabled);
					break;
				case 'DATE':
					this.insertDateProperty(property, propsItemNode, disabled);
					break;
				case 'FILE':
					this.insertFileProperty(property, propsItemNode, disabled);
					break;
				case 'STRING':
					this.insertStringProperty(property, propsItemNode, disabled);
					break;
				case 'ENUM':
					this.insertEnumProperty(property, propsItemNode, disabled);
					break;
				case 'Y/N':
					this.insertYNProperty(property, propsItemNode, disabled);
					break;
				case 'NUMBER':
					this.insertNumberProperty(property, propsItemNode, disabled);
			}

			propsItemsContainer.appendChild(propsItemNode);
		},

		insertLocationProperty: function(property, propsItemNode, disabled)
		{
			var propRow, propNodes, locationString, currentLocation, insertedLoc, propContainer, i, k, values = [];

			if (property.getId() in this.locations)
			{
				if (disabled)
				{
					propRow = this.propsHiddenBlockNode.querySelector('[data-property-id-row="' + property.getId() + '"]');
					if (propRow)
					{
						propNodes = propRow.querySelectorAll('div.bx-soa-loc');
						for (i = 0; i < propNodes.length; i++)
						{
							locationString = this.getLocationString(propNodes[i]);
							values.push(locationString.length ? BX.util.htmlspecialchars(locationString) : BX.message('SOA_NOT_SELECTED'));
						}
					}
					propsItemNode.innerHTML += values.join('<br>');
				}
				else
				{
					propContainer = BX.create('DIV', {props: {className: 'soa-property-container'}});
					propRow = this.locations[property.getId()];
					for (i = 0; i < propRow.length; i ++)
					{
						currentLocation = propRow[i] ? propRow[i].output : {};
						insertedLoc = BX.create('DIV', {props: {className: 'bx-soa-loc'}, html: currentLocation.HTML});

						if (property.isMultiple())
							insertedLoc.style.marginBottom = this.locationsTemplate == 'search' ? '5px' : '20px';

						propContainer.appendChild(insertedLoc);

						for (k in currentLocation.SCRIPT)
						{
							if (currentLocation.SCRIPT.hasOwnProperty(k))
								BX.evalGlobal(currentLocation.SCRIPT[k].JS);
						}
					}

					if (property.isMultiple())
					{
						propContainer.appendChild(
							BX.create('DIV', {
								attrs: {'data-prop-id': property.getId()},
								props: {className: 'btn btn-sm btn-primary'},
								text: BX.message('ADD_DEFAULT'),
								events: {
									click: BX.proxy(this.addLocationProperty, this)
								}
							})
						);
					}

					propsItemNode.appendChild(propContainer);
				}
			}
		},

		addLocationProperty: function(e)
		{
			var target = e.target || e.srcElement,
				propId = target.getAttribute('data-prop-id'),
				lastProp = BX.previousSibling(target),
				insertedLoc, k, input, index = 0,
				prefix = 'sls-',
				randomStr = BX.util.getRandomString(5);

			if (BX.hasClass(lastProp, 'bx-soa-loc'))
			{
				if (this.locationsTemplate == 'search')
				{
					input = lastProp.querySelector('input[type=text][class=dropdown-field]');
					if (input)
						index = parseInt(input.name.substring(input.name.indexOf('[') + 1, input.name.indexOf(']'))) + 1;
				}
				else
				{
					input = lastProp.querySelectorAll('input[type=hidden]');
					if (input.length)
					{
						input = input[input.length - 1];
						index = parseInt(input.name.substring(input.name.indexOf('[') + 1, input.name.indexOf(']'))) + 1;
					}
				}
			}

			if (this.cleanLocations[propId])
			{
				insertedLoc = BX.create('DIV', {
					props: {className: 'bx-soa-loc'},
					style: {marginBottom: this.locationsTemplate == 'search' ? '5px' : '20px'},
					html: this.cleanLocations[propId].HTML.split('#key#').join(index).replace(/sls-\d{5}/g, prefix + randomStr)
				});
				target.parentNode.insertBefore(insertedLoc, target);

				BX.saleOrderAjax.addPropertyDesc({
					id: propId + '_' + index,
					attributes: {
						id: propId + '_' + index,
						type: 'LOCATION',
						valueSource: 'form'
					}
				});


				for (k in this.cleanLocations[propId].SCRIPT)
					if (this.cleanLocations[propId].SCRIPT.hasOwnProperty(k))
						BX.evalGlobal(this.cleanLocations[propId].SCRIPT[k].JS.split('_key__').join('_' + index).replace(/sls-\d{5}/g, prefix + randomStr));

				BX.saleOrderAjax.initDeferredControl();
			}
		},

		insertDateProperty: function(property, propsItemNode, disabled)
		{
			var prop, dateInputs, values, i,
				propContainer, inputText;

			if (disabled)
			{
				prop = this.propsHiddenBlockNode.querySelector('div[data-property-id-row="' + property.getId() + '"]');
				if (prop)
				{
					values = [];
					dateInputs = prop.querySelectorAll('input[type=text]');

					for (i = 0; i < dateInputs.length; i++)
						if (dateInputs[i].value && dateInputs[i].value.length)
							values.push(dateInputs[i].value);

					propsItemNode.innerHTML += this.valuesToString(values);
				}
			}
			else
			{
				propContainer = BX.create('DIV', {props: {className: 'soa-property-container'}});
				property.appendTo(propContainer);
				propsItemNode.appendChild(propContainer);
				inputText = propContainer.querySelectorAll('input[type=text]');

				for (i = 0; i < inputText.length; i++)
					this.alterDateProperty(property.getSettings(), inputText[i]);

				this.alterProperty(property.getSettings(), propContainer);
				this.bindValidation(property.getId(), propContainer);
			}
		},

		insertFileProperty: function(property, propsItemNode, disabled)
		{
			var prop, fileLinks, values, i, html,
				saved, propContainer;

			if (disabled)
			{
				prop = this.propsHiddenBlockNode.querySelector('div[data-property-id-row="' + property.getId() + '"]');
				if (prop)
				{
					values = [];
					fileLinks = prop.querySelectorAll('a');

					for (i = 0; i < fileLinks.length; i++)
					{
						html = fileLinks[i].innerHTML;
						if (html.length)
							values.push(html);
					}

					propsItemNode.innerHTML += this.valuesToString(values);
				}
			}
			else
			{
				saved = this.savedFilesBlockNode.querySelector('div[data-property-id-row="' + property.getId() + '"]');
				if (saved)
					propContainer = saved.querySelector('div.soa-property-container');

				if (propContainer)
					propsItemNode.appendChild(propContainer);
				else
				{
					propContainer = BX.create('DIV', {props: {className: 'soa-property-container'}});
					property.appendTo(propContainer);
					propsItemNode.appendChild(propContainer);
					this.alterProperty(property.getSettings(), propContainer);
				}
			}
		},

		insertStringProperty: function(property, propsItemNode, disabled)
		{
			var prop, inputs, values, i, propContainer;

			if (disabled)
			{
				prop = this.propsHiddenBlockNode.querySelector('div[data-property-id-row="' + property.getId() + '"]');
				if (prop)
				{
					values = [];
					inputs = prop.querySelectorAll('input[type=text]');
					if (inputs.length == 0)
						inputs = prop.querySelectorAll('textarea');

					if (inputs.length)
					{
						for (i = 0; i < inputs.length; i++)
						{
							if (inputs[i].value.length)
								values.push(inputs[i].value);
						}
					}

					propsItemNode.innerHTML += this.valuesToString(values);
				}
			}
			else
			{
				propContainer = BX.create('DIV', {props: {className: 'soa-property-container'}});
				property.appendTo(propContainer);
				propsItemNode.appendChild(propContainer);
				this.alterProperty(property.getSettings(), propContainer);
				this.bindValidation(property.getId(), propContainer);
			}
		},

		insertEnumProperty: function(property, propsItemNode, disabled)
		{
			var prop, inputs, values, i, propContainer;

			if (disabled)
			{
				prop = this.propsHiddenBlockNode.querySelector('div[data-property-id-row="' + property.getId() + '"]');
				if (prop)
				{
					values = [];
					inputs = prop.querySelectorAll('input[type=radio]');
					if (inputs.length)
					{
						for (i = 0; i < inputs.length; i++)
						{
							if (inputs[i].checked)
								values.push(inputs[i].nextSibling.nodeValue);
						}
					}
					inputs = prop.querySelectorAll('option');
					if (inputs.length)
					{
						for (i = 0; i < inputs.length; i++)
						{
							if (inputs[i].selected)
								values.push(inputs[i].innerHTML);
						}
					}

					propsItemNode.innerHTML += this.valuesToString(values);
				}
			}
			else
			{
				propContainer = BX.create('DIV', {props: {className: 'soa-property-container'}});
				property.appendTo(propContainer);
				propsItemNode.appendChild(propContainer);
				this.bindValidation(property.getId(), propContainer);
			}
		},

		insertYNProperty: function(property, propsItemNode, disabled)
		{
			var prop, inputs, values, i, propContainer;

			if (disabled)
			{
				prop = this.propsHiddenBlockNode.querySelector('div[data-property-id-row="' + property.getId() + '"]');
				if (prop)
				{
					values = [];
					inputs = prop.querySelectorAll('input[type=checkbox]');

					for (i = 0; i < inputs.length; i+=2)
						values.push(inputs[i].checked ? BX.message('SOA_YES') : BX.message('SOA_NO'));

					propsItemNode.innerHTML += this.valuesToString(values);
				}
			}
			else
			{
				propContainer = BX.create('DIV', {props: {className: 'soa-property-container'}});
				property.appendTo(propContainer);
				propsItemNode.appendChild(propContainer);
				this.alterProperty(property.getSettings(), propContainer);
				this.bindValidation(property.getId(), propContainer);
			}
		},

		insertNumberProperty: function(property, propsItemNode, disabled)
		{
			var prop, inputs, values, i, propContainer;

			if (disabled)
			{
				prop = this.propsHiddenBlockNode.querySelector('div[data-property-id-row="' + property.getId() + '"]');
				if (prop)
				{
					values = [];
					inputs = prop.querySelectorAll('input[type=text]');

					for (i = 0; i < inputs.length; i++)
						if (inputs[i].value.length)
							values.push(inputs[i].value);

					propsItemNode.innerHTML += this.valuesToString(values);
				}
			}
			else
			{
				propContainer = BX.create('DIV', {props: {className: 'soa-property-container'}});
				property.appendTo(propContainer);
				propsItemNode.appendChild(propContainer);
				this.alterProperty(property.getSettings(), propContainer);
				this.bindValidation(property.getId(), propContainer);
			}
		},

		valuesToString: function(values)
		{
			var str = values.join(', ');

			return str.length ? BX.util.htmlspecialchars(str) : BX.message('SOA_NOT_SELECTED');
		},

		alterProperty: function(settings, propContainer)
		{
			var divs = BX.findChildren(propContainer, {tagName: 'DIV'}),
				i, textNode, inputs, del, add,
				fileInputs, accepts, fileTitles;

			if (divs && divs.length)
			{
				for (i = 0; i < divs.length; i++)
				{
					if (BX.hasClass(divs[i], 'bx-no-alter-margin'))
						continue;

					divs[i].style.margin = '5px 0';
				}
			}

			textNode = propContainer.querySelector('input[type=text]');
			if (!textNode)
				textNode = propContainer.querySelector('textarea');

			if (textNode)
			{
				textNode.id = 'soa-property-' + settings.ID;
				if (settings.IS_ADDRESS == 'Y')
					textNode.setAttribute('autocomplete', 'address');
				if (settings.IS_EMAIL == 'Y')
					textNode.setAttribute('autocomplete', 'email');
				if (settings.IS_PAYER == 'Y')
					textNode.setAttribute('autocomplete', 'name');
				if (settings.IS_PHONE == 'Y')
					textNode.setAttribute('autocomplete', 'tel');

				if (settings.PATTERN && settings.PATTERN.length)
				{
					textNode.removeAttribute('pattern');
				}
			}

			inputs = propContainer.querySelectorAll('input[type=text]');
			for (i = 0; i < inputs.length; i++)
			{
				inputs[i].placeholder = settings.DESCRIPTION;
				BX.addClass(inputs[i], 'form-control bx-soa-customer-input bx-ios-fix');
			}

			inputs = propContainer.querySelectorAll('select');
			for (i = 0; i < inputs.length; i++)
				BX.addClass(inputs[i], 'form-control');

			inputs = propContainer.querySelectorAll('textarea');
			for (i = 0; i < inputs.length; i++)
			{
				inputs[i].placeholder = settings.DESCRIPTION;
				BX.addClass(inputs[i], 'form-control bx-ios-fix');
			}

			del = propContainer.querySelectorAll('label');
			for (i = 0; i < del.length; i++)
				BX.remove(del[i]);

			if (settings.TYPE == 'FILE')
			{
				if (settings.ACCEPT && settings.ACCEPT.length)
				{
					fileInputs = propContainer.querySelectorAll('input[type=file]');
					accepts = this.getFileAccepts(settings.ACCEPT);
					for (i = 0; i < fileInputs.length; i++)
						fileInputs[i].setAttribute('accept', accepts);
				}

				fileTitles = propContainer.querySelectorAll('a');
				for (i = 0; i < fileTitles.length; i++)
				{
					BX.bind(fileTitles[i], 'click', function(e){
						var target = e.target || e.srcElement,
							fileInput = target && target.nextSibling && target.nextSibling.nextSibling;

						if (fileInput)
							BX.fireEvent(fileInput, 'change');
					});
				}
			}

			add = propContainer.querySelectorAll('input[type=button]');
			for (i = 0; i < add.length; i++)
			{
				BX.addClass(add[i], 'btn btn-primary btn-sm');

				if (settings.MULTIPLE == 'Y' && i == add.length - 1)
					continue;

				if (settings.TYPE == 'FILE')
				{
					BX.prepend(add[i], add[i].parentNode);
					add[i].style.marginRight = '10px';
				}
			}

			if (add.length)
			{
				add = add[add.length - 1];
				BX.bind(add, 'click', BX.delegate(function(e){
					var target = e.target || e.srcElement,
						targetContainer = BX.findParent(target, {tagName: 'div', className: 'soa-property-container'}),
						del = targetContainer.querySelector('label'),
						add = targetContainer.querySelectorAll('input[type=button]'),
						textInputs = targetContainer.querySelectorAll('input[type=text]'),
						textAreas = targetContainer.querySelectorAll('textarea'),
						divs = BX.findChildren(targetContainer, {tagName: 'DIV'});

					var i, fileTitles, fileInputs, accepts;

					if (divs && divs.length)
					{
						for (i = 0; i < divs.length; i++)
						{
							divs[i].style.margin = '5px 0';
						}
					}

					this.bindValidation(settings.ID, targetContainer);

					if (add.length && add[add.length - 2])
					{
						BX.prepend(add[add.length - 2], add[add.length - 2].parentNode);
						add[add.length - 2].style.marginRight = '10px';
						BX.addClass(add[add.length - 2], 'btn btn-primary btn-sm');
					}

					del && BX.remove(del);
					if (textInputs.length)
					{
						textInputs[textInputs.length - 1].placeholder = settings.DESCRIPTION;
						BX.addClass(textInputs[textInputs.length - 1], 'form-control bx-soa-customer-input bx-ios-fix');
						if (settings.TYPE == 'DATE')
							this.alterDateProperty(settings, textInputs[textInputs.length - 1]);

						if (settings.PATTERN && settings.PATTERN.length)
							textInputs[textInputs.length - 1].removeAttribute('pattern');
					}

					if (textAreas.length)
					{
						textAreas[textAreas.length - 1].placeholder = settings.DESCRIPTION;
						BX.addClass(textAreas[textAreas.length - 1], 'form-control bx-ios-fix');
					}

					if (settings.TYPE == 'FILE')
					{
						if (settings.ACCEPT && settings.ACCEPT.length)
						{
							fileInputs = propContainer.querySelectorAll('input[type=file]');
							accepts = this.getFileAccepts(settings.ACCEPT);
							for (i = 0; i < fileInputs.length; i++)
								fileInputs[i].setAttribute('accept', accepts);
						}

						fileTitles = targetContainer.querySelectorAll('a');
						BX.bind(fileTitles[fileTitles.length - 1], 'click', function(e){
							var target = e.target || e.srcElement,
								fileInput = target && target.nextSibling && target.nextSibling.nextSibling;

							if (fileInput)
								setTimeout(function(){BX.fireEvent(fileInput, 'change');}, 10);
						});
					}
				}, this));
			}
		},

		alterDateProperty: function(settings, inputText)
		{
			var parentNode = BX.findParent(inputText, {tagName: 'DIV'}),
				appendNode;

			BX.addClass(parentNode, 'input-group');
			appendNode = BX.create('DIV', {
				props: {className: 'input-group-append bx-no-alter-margin'},
				html: '<span class="input-group-text"><i class="fa fa-calendar"></i></span>'
			});
			BX.insertAfter(appendNode, inputText);
			BX.remove(parentNode.querySelector('input[type=button]'));

			BX.bind(appendNode, 'click', BX.delegate(function(e){
				var target = e.target || e.srcElement,
					parentNode = BX.findParent(target, {tagName: 'DIV', className: 'input-group'});

				BX.calendar({
					node: parentNode.querySelector('.input-group-append'),
					field: parentNode.querySelector('input[type=text]').name,
					form: '',
					bTime: settings.TIME === 'Y',
					bHideTime: false
				});
			}, this));
		},

		isValidForm: function()
		{
			if (!this.options.propertyValidation)
				return true;

			var regionErrors = this.isValidRegionBlock(),
				propsErrors = this.isValidPropertiesBlock(),
				navigated = false, tooltips, i;

			if (regionErrors.length)
			{
				navigated = true;
				// Motor-Force customization: location+zip are inside Delivery block.
				this.animateScrollTo(this.deliveryBlockNode, 800, 50);
			}

			if (propsErrors.length && !navigated)
			{
				if (this.activeSectionId == this.propsBlockNode.id)
				{
					tooltips = this.propsBlockNode.querySelectorAll('div.tooltip');
					for (i = 0; i < tooltips.length; i++)
					{
						if (tooltips[i].getAttribute('data-state') == 'opened')
						{
							this.animateScrollTo(BX.findParent(tooltips[i], {className: 'form-group bx-soa-customer-field'}), 800, 50);
							break;
						}
					}
				}
				else
					this.animateScrollTo(this.propsBlockNode, 800, 50);
			}

			if (regionErrors.length)
			{
				this.showError(this.deliveryBlockNode, regionErrors);
				BX.addClass(this.deliveryBlockNode, 'bx-step-error');
			}

			if (propsErrors.length)
			{
				if (this.activeSectionId !== this.propsBlockNode.id)
					this.showError(this.propsBlockNode, propsErrors);

				BX.addClass(this.propsBlockNode, 'bx-step-error');
			}

			return !(regionErrors.length + propsErrors.length);
		},

		isValidRegionBlock: function()
		{
			if (!this.options.propertyValidation)
				return [];

			var regionProps = this.orderBlockNode.querySelectorAll('.bx-soa-location-input-container[data-property-id-row]'),
				regionErrors = [],
				id, arProperty, data, i;

			for (i = 0; i < regionProps.length; i++)
			{
				id = regionProps[i].getAttribute('data-property-id-row');
				arProperty = this.validation.properties[id];
				data = this.getValidationData(arProperty, regionProps[i]);

				regionErrors = regionErrors.concat(this.isValidProperty(data, true));
			}

			return regionErrors;
		},

		isValidPropertiesBlock: function(excludeLocation)
		{
			if (!this.options.propertyValidation)
				return [];

			var props = this.orderBlockNode.querySelectorAll('.bx-soa-customer-field[data-property-id-row]'),
				propsErrors = [],
				id, propContainer, arProperty, data, i;

			for (i = 0; i < props.length; i++)
			{
				id = props[i].getAttribute('data-property-id-row');

				if (!!excludeLocation && this.locations[id])
					continue;

				propContainer = props[i].querySelector('.soa-property-container');
				if (propContainer)
				{
					arProperty = this.validation.properties[id];
					data = this.getValidationData(arProperty, propContainer);
					propsErrors = propsErrors.concat(this.isValidProperty(data, true));
				}
			}

			return propsErrors;
		},

		isValidProperty: function(data, fieldName)
		{
			var propErrors = [], inputErrors, i;

			if (!data || !data.inputs)
				return propErrors;

			for (i = 0; i < data.inputs.length; i++)
			{
				inputErrors = data.func(data.inputs[i], !!fieldName);
				if (inputErrors.length)
					propErrors[i] = inputErrors.join('<br>');
			}

			this.showValidationResult(data.inputs, propErrors);

			return propErrors;
		},

		bindValidation: function(id, propContainer)
		{
			if (!this.validation.properties || !this.validation.properties[id])
				return;

			var arProperty = this.validation.properties[id],
				data = this.getValidationData(arProperty, propContainer),
				i, k;

			if (data && data.inputs && data.action)
			{
				for (i = 0; i < data.inputs.length; i++)
				{
					if (BX.type.isElementNode(data.inputs[i]))
						BX.bind(data.inputs[i], data.action, BX.delegate(function(){
							this.isValidProperty(data);
						}, this));
					else
						for (k = 0; k < data.inputs[i].length; k++)
							BX.bind(data.inputs[i][k], data.action, BX.delegate(function(){
								this.isValidProperty(data);
							}, this));
				}
			}
		},

		getValidationData: function(arProperty, propContainer)
		{
			if (!arProperty || !propContainer)
				return;

			var data = {}, inputs;

			switch (arProperty.TYPE)
			{
				case 'STRING':
					data.action = 'change';
					data.func = BX.delegate(function(input, fieldName){
						return this.validateString(input, arProperty, fieldName);
					}, this);

					inputs = propContainer.querySelectorAll('input[type=text]');
					if (inputs.length)
					{
						data.inputs = inputs;
						break;
					}
					inputs = propContainer.querySelectorAll('textarea');
					if (inputs.length)
						data.inputs = inputs;
					break;
				case 'LOCATION':
					data.func = BX.delegate(function(input, fieldName){
						return this.validateLocation(input, arProperty, fieldName);
					}, this);

					inputs = propContainer.querySelectorAll('input.bx-ui-sls-fake[type=text]');
					if (inputs.length)
					{
						data.inputs = inputs;
						data.action = 'keyup';
						break;
					}
					inputs = propContainer.querySelectorAll('div.bx-ui-slst-pool');
					if (inputs.length)
					{
						data.inputs = inputs;
					}
					break;
				case 'Y/N':
					data.inputs = propContainer.querySelectorAll('input[type=checkbox]');
					data.action = 'change';
					data.func = BX.delegate(function(input, fieldName){
						return this.validateCheckbox(input, arProperty, fieldName);
					}, this);
					break;
				case 'NUMBER':
					data.inputs = propContainer.querySelectorAll('input[type=text]');
					data.action = 'blur';
					data.func = BX.delegate(function(input, fieldName){
						return this.validateNumber(input, arProperty, fieldName);
					}, this);
					break;
				case 'ENUM':
					inputs = propContainer.querySelectorAll('input[type=radio]');
					if (!inputs.length)
						inputs = propContainer.querySelectorAll('input[type=checkbox]');

					if (inputs.length)
					{
						data.inputs = [inputs];
						data.action = 'change';
						data.func = BX.delegate(function(input, fieldName){
							return this.validateEnum(input, arProperty, fieldName);
						}, this);
						break;
					}

					inputs = propContainer.querySelectorAll('option');
					if (inputs.length)
					{
						data.inputs = [inputs];
						data.action = 'click';
						data.func = BX.delegate(function(input, fieldName){
							return this.validateSelect(input, arProperty, fieldName);
						}, this);
					}
					break;
				case 'FILE':
					data.inputs = propContainer.querySelectorAll('input[type=file]');
					data.action = 'change';
					data.func = BX.delegate(function(input, fieldName){
						return this.validateFile(input, arProperty, fieldName);
					}, this);
					break;
				case 'DATE':
					data.inputs = propContainer.querySelectorAll('input[type=text]');
					data.action = 'change';
					data.func = BX.delegate(function(input, fieldName){
						return this.validateDate(input, arProperty, fieldName);
					}, this);
					break;
			}

			return data;
		},

		showErrorTooltip: function(tooltipId, targetNode, text)
		{
			if (!tooltipId || !targetNode || !text)
				return;

			var tooltip = BX('tooltip-' + tooltipId),
				tooltipInner, quickLocation;

			text = this.uniqueText(text, '<br>');

			if (tooltip)
			{
				tooltipInner = tooltip.querySelector('div.tooltip-inner');
			}
			else
			{
				tooltipInner = BX.create('DIV', {props: {className: 'tooltip-inner'}});
				tooltip = BX.create('DIV', {
					props: {
						id: 'tooltip-' + tooltipId,
						className: 'bx-soa-tooltip bx-soa-tooltip-static bx-soa-tooltip-danger tooltip top'
					},
					children: [
						BX.create('DIV', {props: {className: 'tooltip-arrow'}}),
						tooltipInner
					]
				});

				quickLocation = targetNode.parentNode.querySelector('div.quick-locations');
				if (quickLocation)
					targetNode = quickLocation;

				BX.insertAfter(tooltip, targetNode);
			}

			tooltipInner.innerHTML = text;

			if (tooltip.getAttribute('data-state') != 'opened')
			{
				tooltip.setAttribute('data-state', 'opened');
				tooltip.style.opacity = 0;
				tooltip.style.display = 'block';

				new BX.easing({
					duration: 150,
					start: {opacity: 0},
					finish: {opacity: 100},
					transition: BX.easing.transitions.quad,
					step: function(state){
						tooltip.style.opacity = state.opacity / 100;
					}
				}).animate();
			}
		},

		closeErrorTooltip: function(tooltipId)
		{
			var tooltip = BX('tooltip-' + tooltipId);
			if (tooltip)
			{
				tooltip.setAttribute('data-state', 'closed');

				new BX.easing({
					duration: 150,
					start: {opacity: 100},
					finish: {opacity: 0},
					transition: BX.easing.transitions.quad,
					step: function(state){
						tooltip.style.opacity = state.opacity / 100;
					},
					complete: function(){
						tooltip.style.display = 'none';
					}
				}).animate();
			}
		},

		showValidationResult: function(inputs, errors)
		{
			if (!inputs || !inputs.length || !errors)
				return;

			var input0 = BX.type.isElementNode(inputs[0]) ? inputs[0] : inputs[0][0],
				formGroup = BX.findParent(input0, {tagName: 'DIV', className: 'form-group'}),
				label = formGroup.querySelector('label'),
				tooltipId, inputDiv, i;

			if (label)
				tooltipId = label.getAttribute('for');

			for (i = 0; i < inputs.length; i++)
			{
				inputDiv = BX.findParent(inputs[i], {tagName: 'DIV', className: 'form-group'});
				if (errors[i] && errors[i].length)
					BX.addClass(inputDiv, 'has-error');
				else
					BX.removeClass(inputDiv, 'has-error');
			}

			if (errors.length)
				this.showErrorTooltip(tooltipId, label, errors.join('<br>'));
			else
				this.closeErrorTooltip(tooltipId);
		},

		validateString: function(input, arProperty, fieldName)
		{
			if (!input || !arProperty)
			{
				return [];
			}

			var value = input.value,
				errors = [],
				name = BX.util.htmlspecialchars(arProperty.NAME),
				re;

			if (arProperty.MULTIPLE === 'Y')
			{
				return errors;
			}

			if (arProperty.REQUIRED === 'Y' && value.length === 0)
			{
				errors.push(
					fieldName
						? BX.Loc.getMessage(
							'SOA_REQUIRED_FIELD_WITH_NAME',
							{
								'#NAME#': name,
							},
						)
						: BX.Loc.getMessage('SOA_REQUIRED_FIELD'),
				);
			}

			if (value.length)
			{
				if (arProperty.MINLENGTH && arProperty.MINLENGTH > value.length)
				{
					errors.push(BX.Loc.getMessage(
						'SOA_MIN_LENGTH_FIELD_WITH_NAME',
						{
							'#NAME#': name,
							'#LENGTH#': arProperty.MINLENGTH,
						},
					));
				}

				if (arProperty.MAXLENGTH && arProperty.MAXLENGTH < value.length)
				{
					errors.push(BX.Loc.getMessage(
						'SOA_MAX_LENGTH_FIELD_WITH_NAME',
						{
							'#NAME#': name,
							'#LENGTH#': arProperty.MAXLENGTH,
						},
					));
				}

				if (arProperty.IS_EMAIL === 'Y')
				{
					input.value = value = BX.util.trim(value);
					if (value.length)
					{
						re = /^(([^<>()[\]\.,;:\s@\"]+(\.[^<>()[\]\.,;:\s@\"]+)*)|(\".+\"))@(([^<>()[\]\.,;:\s@\"]+\.)+[^<>()[\]\.,;:\s@\"]{2,})$/i;
						if (!re.test(value))
						{
							errors.push(BX.Loc.getMessage('SOA_INVALID_EMAIL'));
						}
					}
				}

				if (value.length > 0 && arProperty.PATTERN && arProperty.PATTERN.length)
				{
					re = new RegExp(arProperty.PATTERN);
					if (!re.test(value))
					{
						errors.push(
							fieldName
								? BX.Loc.getMessage(
									'SOA_INVALID_PATTERN_FIELD_WITH_NAME',
									{
										'#NAME#': name,
									},
								)
								: BX.Loc.getMessage('SOA_INVALID_PATTERN_FIELD'),
						);
					}
				}
			}

			return errors;
		},

		validateLocation: function(input, arProperty, fieldName)
		{
			if (!input || !arProperty)
			{
				return [];
			}

			var parent = BX.findParent(input, { tagName: 'DIV', className: 'form-group' }),
				value = this.getLocationString(parent),
				errors = [];

			if (arProperty.MULTIPLE === 'Y' && arProperty.IS_LOCATION !== 'Y')
			{
				return errors;
			}

			if (arProperty.REQUIRED === 'Y' && (value.length === 0 || value === BX.Loc.getMessage('SOA_NOT_SPECIFIED')))
			{
				errors.push(
					fieldName
						? BX.Loc.getMessage(
							'SOA_REQUIRED_FIELD_WITH_NAME',
							{
								'#NAME#': BX.util.htmlspecialchars(arProperty.NAME),
							},
						)
						: BX.Loc.getMessage('SOA_REQUIRED_FIELD'),
				);
			}

			return errors;
		},

		validateCheckbox: function(input, arProperty, fieldName)
		{
			if (!input || !arProperty)
			{
				return [];
			}

			var errors = [];

			if (arProperty.MULTIPLE === 'Y')
			{
				return errors;
			}

			if (arProperty.REQUIRED === 'Y' && !input.checked)
			{
				errors.push(
					fieldName
						? BX.Loc.getMessage(
							'SOA_REQUIRED_FIELD_WITH_NAME',
							{
								'#NAME#': BX.util.htmlspecialchars(arProperty.NAME),
							},
						)
						: BX.Loc.getMessage('SOA_REQUIRED_FIELD'),
				);
			}

			return errors;
		},

		validateNumber: function(input, arProperty, fieldName)
		{
			if (!input || !arProperty)
			{
				return [];
			}

			var value = input.value,
				errors = [],
				name = BX.util.htmlspecialchars(arProperty.NAME);

			if (arProperty.MULTIPLE === 'Y')
			{
				return errors;
			}

			if (arProperty.REQUIRED === 'Y' && value.length === 0)
			{
				errors.push(
					fieldName
						? BX.Loc.getMessage(
							'SOA_REQUIRED_FIELD_WITH_NAME',
							{
								'#NAME#': name,
							},
						)
						: BX.Loc.getMessage('SOA_REQUIRED_FIELD'),
				);
			}

			if (value.length)
			{
				if (!/[0-9]|\./.test(value))
				{
					errors.push(
						fieldName
							? BX.Loc.getMessage(
								'SOA_NOT_NUMERIC_FIELD_WITH_TITLE',
								{
									'#NAME#': name,
								},
							)
							: BX.Loc.getMessage('SOA_NOT_NUMERIC_FIELD'),
					);
				}

				if (arProperty.MIN && parseFloat(arProperty.MIN) > parseFloat(value))
				{
					errors.push(BX.Loc.getMessage(
						'SOA_MIN_VALUE_FIELD_WITH_NAME',
						{
							'#NAME#': name,
							'#VALUE#': parseFloat(arProperty.MIN),
						},
					));
				}

				if (arProperty.MAX && parseFloat(arProperty.MAX) < parseFloat(value))
				{
					errors.push(BX.Loc.getMessage(
						'SOA_MAX_VALUE_FIELD_WITH_NAME',
						{
							'#NAME#': name,
							'#VALUE#': parseFloat(arProperty.MAX),
						},
					));
				}

				if (arProperty.STEP && parseFloat(arProperty.STEP) > 0)
				{
					var num = Math.abs(parseFloat(value)
						- (arProperty.MIN && parseFloat(arProperty.MIN) > 0 ? parseFloat(arProperty.MIN) : 0));
					var del = (num / parseFloat(arProperty.STEP)).toPrecision(12);
					if (del !== parseInt(del))
					{
						errors.push(
							fieldName
								? BX.Loc.getMessage(
									'SOA_NUM_STEP_FIELD_WITH_NAME',
									{
										'#NAME#': name,
										'#STEP#': arProperty.STEP,
									},
								)
								: BX.Loc.getMessage(
									'SOA_NUM_STEP_FIELD',
									{
										'#STEP#': arProperty.STEP,
									},
								),
						);
					}
				}
			}

			return errors;
		},

		validateEnum: function(inputs, arProperty, fieldName)
		{
			if (!inputs || !arProperty)
			{
				return [];
			}

			var values = [],
				errors = [];

			if (arProperty.MULTIPLE === 'Y')
			{
				return errors;
			}

			for (var i = 0; i < inputs.length; i++)
			{
				if (inputs[i].checked || inputs[i].selected)
				{
					values.push(i);
				}
			}

			if (arProperty.REQUIRED === 'Y' && values.length === 0)
			{
				errors.push(
					fieldName
						? BX.Loc.getMessage(
							'SOA_REQUIRED_FIELD_WITH_NAME',
							{
								'#NAME#': BX.util.htmlspecialchars(arProperty.NAME),
							},
						)
						: BX.Loc.getMessage('SOA_REQUIRED_FIELD'),
				);
			}

			return errors;
		},

		validateSelect: function(inputs, arProperty, fieldName)
		{
			if (!inputs || !arProperty)
			{
				return [];
			}

			var values = [],
				errors = [];

			if (arProperty.MULTIPLE === 'Y')
			{
				return errors;
			}

			for (var i = 0; i < inputs.length; i++)
			{
				if (inputs[i].selected)
				{
					values.push(i);
				}
			}

			if (arProperty.REQUIRED === 'Y' && values.length === 0)
			{
				errors.push(
					fieldName
						? BX.Loc.getMessage(
							'SOA_REQUIRED_FIELD_WITH_NAME',
							{
								'#NAME#': BX.util.htmlspecialchars(arProperty.NAME),
							},
						)
						: BX.Loc.getMessage('SOA_REQUIRED_FIELD'),
				);
			}

			return errors;
		},

		validateFile: function(inputs, arProperty, fieldName)
		{
			if (!inputs || !arProperty)
			{
				return [];
			}

			var errors = [],
				files = inputs.files || [],
				defaultValue = inputs.previousSibling.value;

			if (arProperty.MULTIPLE === 'Y')
			{
				return errors;
			}

			if (
				arProperty.REQUIRED === 'Y' && files.length === 0 && defaultValue === ''
				&& (!arProperty.DEFAULT_VALUE || !arProperty.DEFAULT_VALUE.length)
			)
			{
				errors.push(
					fieldName
						? BX.Loc.getMessage(
							'SOA_REQUIRED_FIELD_WITH_NAME',
							{
								'#NAME#': BX.util.htmlspecialchars(arProperty.NAME),
							},
						)
						: BX.Loc.getMessage('SOA_REQUIRED_FIELD'),
				);
			}
			else
			{
				for (var file of files)
				{
					var fileName = BX.util.htmlspecialchars(file.name);
					var splittedName = file.name.split('.');
					var fileExtension = splittedName.length > 1 ? splittedName[splittedName.length - 1].toLowerCase() : '';

					if (arProperty.ACCEPT.length > 0 && (fileExtension.length === 0 || arProperty.ACCEPT.indexOf(fileExtension) === '-1'))
					{
						errors.push(BX.Loc.getMessage(
							'SOA_BAD_EXTENSION_NAME_AND_ACCEPT',
							{
								'#NAME#': fileName,
								'#ACCEPT#': BX.util.htmlspecialchars(arProperty.ACCEPT),
							},
						));
					}

					if (file.size > parseInt(arProperty.MAXSIZE))
					{
						errors.push(BX.Loc.getMessage(
							'SOA_MAX_SIZE_NAME_AND_SIZE',
							{
								'#NAME#': fileName,
								'#SIZE#': this.getSizeString(arProperty.MAXSIZE, 1),
							},
						));
					}
				}
			}

			return errors;
		},

		validateDate: function(input, arProperty, fieldName)
		{
			if (!input || !arProperty)
			{
				return [];
			}

			var value = input.value,
				errors = [];

			if (arProperty.MULTIPLE === 'Y')
			{
				return errors;
			}

			if (arProperty.REQUIRED === 'Y' && value.length === 0)
			{
				errors.push(
					fieldName
						? BX.Loc.getMessage(
							'SOA_REQUIRED_FIELD_WITH_NAME',
							{
								'#NAME#': BX.util.htmlspecialchars(arProperty.NAME),
							},
						)
						: BX.Loc.getMessage('SOA_REQUIRED_FIELD'),
				);
			}

			return errors;
		},

		editPropsMap: function(propsNode)
		{
			var propsMapContainer = BX.create('DIV', {props: {className: 'col-sm-12'}, style: {marginBottom: '10px'}}),
				map = BX.create('DIV', {props: {id: 'propsMap'}, style: {width: '100%'}});

			propsMapContainer.appendChild(map);
			propsNode.appendChild(propsMapContainer);
		},

		editPropsComment: function(propsNode)
		{
			var propsCommentContainer, label, input, div;

			propsCommentContainer = BX.create('DIV', {props: {className: 'col-sm-12'}});
			label = BX.create('LABEL', {
				attrs: {for: 'orderDescription'},
				props: {className: 'bx-soa-customer-label'},
				html: this.params.MESS_ORDER_DESC
			});
			input = BX.create('TEXTAREA', {
				props: {
					id: 'orderDescription',
					cols: '4',
					className: 'form-control bx-soa-customer-textarea bx-ios-fix',
					name: 'ORDER_DESCRIPTION'
				},
				text: this.result.ORDER_DESCRIPTION ? this.result.ORDER_DESCRIPTION : ''
			});
			div = BX.create('DIV', {
				props: {className: 'form-group bx-soa-customer-field'},
				children: [label, input]
			});

			propsCommentContainer.appendChild(div);
			propsNode.appendChild(propsCommentContainer);
		},

		editTotalBlock: function()
		{
			if (!this.totalInfoBlockNode || !this.result.TOTAL)
				return;

			var total = this.result.TOTAL,
				priceHtml, params = {},
				discText, valFormatted, i,
				curDelivery, deliveryError, deliveryValue,
				showOrderButton = this.params.SHOW_TOTAL_ORDER_BUTTON === 'Y';

			BX.cleanNode(this.totalInfoBlockNode);

			if (parseFloat(total.ORDER_PRICE) === 0)
			{
				priceHtml = this.params.MESS_PRICE_FREE;
				params.free = true;
			}
			else
			{
				priceHtml = total.ORDER_PRICE_FORMATED;
			}

			if (this.options.showPriceWithoutDiscount)
			{
				priceHtml += '<br><span class="bx-price-old">' + total.PRICE_WITHOUT_DISCOUNT + '</span>';
			}

			this.totalInfoBlockNode.appendChild(this.createTotalUnit(BX.message('SOA_SUM_SUMMARY'), priceHtml, params));

			if (this.options.showOrderWeight)
			{
				this.totalInfoBlockNode.appendChild(this.createTotalUnit(BX.message('SOA_SUM_WEIGHT_SUM'), total.ORDER_WEIGHT_FORMATED));
			}

			if (this.options.showTaxList)
			{
				for (i = 0; i < total.TAX_LIST.length; i++)
				{
					valFormatted = total.TAX_LIST[i].VALUE_MONEY_FORMATED || '';
					this.totalInfoBlockNode.appendChild(
						this.createTotalUnit(
							total.TAX_LIST[i].NAME + (!!total.TAX_LIST[i].VALUE_FORMATED ? ' ' + total.TAX_LIST[i].VALUE_FORMATED : '') + ':',
							valFormatted
						)
					);
				}
			}

			params = {};
			curDelivery = this.getSelectedDelivery();
			deliveryError = curDelivery && curDelivery.CALCULATE_ERRORS && curDelivery.CALCULATE_ERRORS.length;

			if (deliveryError)
			{
				deliveryValue = BX.message('SOA_NOT_CALCULATED');
				params.error = deliveryError;
			}
			else
			{
				if (parseFloat(total.DELIVERY_PRICE) === 0)
				{
					deliveryValue = this.params.MESS_PRICE_FREE;
					params.free = true;
				}
				else
				{
					deliveryValue = total.DELIVERY_PRICE_FORMATED;
				}

				if (
					curDelivery && typeof curDelivery.DELIVERY_DISCOUNT_PRICE !== 'undefined'
					&& parseFloat(curDelivery.PRICE) > parseFloat(curDelivery.DELIVERY_DISCOUNT_PRICE)
				)
				{
					deliveryValue += '<br><span class="bx-price-old">' + curDelivery.PRICE_FORMATED + '</span>';
				}
			}

			// Motor-Force customization:
			// Virtual delivery tariffs (display-only). Delivery must show:
			// - "Не выбрано" until user selects a tariff
			// - selected tariff price after selection
			// but MUST NOT affect order totals (Bitrix delivery price remains 0).
			try
			{
				var f = BX('bx-soa-order-form');
				if (f && (f.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]') || f.querySelector('#mf-edost-box')))
				{
					var tidEl = f.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]');
					var priceEl = f.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_PRICE"]');
					var tid = tidEl ? BX.util.trim(String(tidEl.value || '')) : '';
					var price = priceEl ? BX.util.trim(String(priceEl.value || '')) : '';

					params = {}; // override free/error styling
					if (!tid)
					{
						deliveryValue = 'Не выбрано';
					}
					else
					{
						deliveryValue = price !== '' ? (price + ' ₽') : 'Не выбрано';
					}
				}
			}
			catch (e)
			{
				// ignore
			}

			if (this.result.DELIVERY.length)
			{
				this.totalInfoBlockNode.appendChild(this.createTotalUnit(BX.message('SOA_SUM_DELIVERY'), deliveryValue, params));
			}

			if (this.options.showDiscountPrice)
			{
				discText = this.params.MESS_ECONOMY;
				if (total.DISCOUNT_PERCENT_FORMATED && parseFloat(total.DISCOUNT_PERCENT_FORMATED) > 0)
					discText += total.DISCOUNT_PERCENT_FORMATED;

				this.totalInfoBlockNode.appendChild(this.createTotalUnit(discText + ':', total.DISCOUNT_PRICE_FORMATED, {highlighted: true}));
			}

			if (this.options.showPayedFromInnerBudget)
			{
				this.totalInfoBlockNode.appendChild(this.createTotalUnit(BX.message('SOA_SUM_IT'), total.ORDER_TOTAL_PRICE_FORMATED));
				this.totalInfoBlockNode.appendChild(this.createTotalUnit(BX.message('SOA_SUM_PAYED'), total.PAYED_FROM_ACCOUNT_FORMATED));
				this.totalInfoBlockNode.appendChild(this.createTotalUnit(BX.message('SOA_SUM_LEFT_TO_PAY'), total.ORDER_TOTAL_LEFT_TO_PAY_FORMATED, {total: true}));
			}
			else
			{
				this.totalInfoBlockNode.appendChild(this.createTotalUnit(BX.message('SOA_SUM_IT'), total.ORDER_TOTAL_PRICE_FORMATED, {total: true}));
			}

			if (parseFloat(total.PAY_SYSTEM_PRICE) >= 0 && this.result.DELIVERY.length)
			{
				this.totalInfoBlockNode.appendChild(this.createTotalUnit(BX.message('SOA_PAYSYSTEM_PRICE'), '~' + total.PAY_SYSTEM_PRICE_FORMATTED));
			}

			if (!this.result.SHOW_AUTH)
			{
				this.totalInfoBlockNode.appendChild(
					BX.create('DIV', {
						props: {className: 'bx-soa-cart-total-button-container' + (!showOrderButton ? ' d-block d-sm-none' : '')},
						children: [
							BX.create('A', {
								props: {
									href: 'javascript:void(0)',
									className: 'btn btn-primary btn-lg btn-order-save'
								},
								html: this.params.MESS_ORDER,
								events: {
									click: BX.proxy(this.clickOrderSaveAction, this)
								}
							})

						]
					})
				);
			}

			this.editMobileTotalBlock();
		},

		editMobileTotalBlock: function()
		{
			if (this.result.SHOW_AUTH)
				BX.removeClass(this.mobileTotalBlockNode, 'd-block d-sm-none');
			else
				BX.addClass(this.mobileTotalBlockNode, 'd-block d-sm-none');

			BX.cleanNode(this.mobileTotalBlockNode);
			this.mobileTotalBlockNode.appendChild(this.totalInfoBlockNode.cloneNode(true));
			BX.bind(this.mobileTotalBlockNode.querySelector('a.bx-soa-price-not-calc'), 'click', BX.delegate(function(){
				this.animateScrollTo(this.deliveryBlockNode);
			}, this));
			BX.bind(this.mobileTotalBlockNode.querySelector('a.btn-order-save'), 'click', BX.proxy(this.clickOrderSaveAction, this));
		},

		createTotalUnit: function(name, value, params)
		{
			var totalValue, className = 'bx-soa-cart-total-line';

			name = name || '';
			value = value || '';
			params = params || {};

			if (params.error)
			{
				totalValue = [BX.create('A', {
					props: {className: 'bx-soa-price-not-calc'},
					html: value,
					events: {
						click: BX.delegate(function(){
							this.animateScrollTo(this.deliveryBlockNode);
						}, this)
					}
				})];
			}
			else if (params.free)
			{
				totalValue = [BX.create('SPAN', {
					props: {className: 'bx-soa-price-free'},
					html: value
				})];
			}
			else
			{
				totalValue = [value];
			}

			if (params.total)
			{
				className += ' bx-soa-cart-total-line-total';
			}

			if (params.highlighted)
			{
				className += ' bx-soa-cart-total-line-highlighted';
			}

			return BX.create('DIV', {
				props: {className: className},
				children: [
					BX.create('SPAN', {props: {className: 'bx-soa-cart-t'}, text: name}),
					BX.create('SPAN', {
						props: {
							className: 'bx-soa-cart-d' + (!!params.total && this.options.totalPriceChanged ? ' bx-soa-changeCostSign' : '')
						},
						children: totalValue
					})
				]
			});
		},

		basketBlockScrollCheckEvent: function(e)
		{
			var target = e.target || e.srcElement,
				scrollLeft = target.scrollLeft,
				scrollRight = target.scrollWidth - (scrollLeft + target.clientWidth),
				parent = target.parentNode;

			if (scrollLeft == 0)
				BX.removeClass(parent, 'bx-soa-table-fade-left');
			else
				BX.addClass(parent, 'bx-soa-table-fade-left');

			if (scrollRight == 0)
				BX.removeClass(parent, 'bx-soa-table-fade-right');
			else
				BX.addClass(parent, 'bx-soa-table-fade-right');
		},

		basketBlockScrollCheck: function()
		{
			var scrollableNodes = this.orderBlockNode.querySelectorAll('div.bx-soa-table-fade'),
				parentNode, parentWidth, tableNode, tableWidth,
				i, scrollNode, scrollLeft, scrollRight, scrollable = false;

			for (i = 0; i < scrollableNodes.length; i++)
			{
				parentNode = scrollableNodes[i];
				tableNode = parentNode.querySelector('div.bx-soa-item-table');
				parentWidth = parentNode.clientWidth;
				tableWidth = tableNode.clientWidth || 0;
				scrollable = scrollable || tableWidth > parentWidth;

				if (scrollable)
				{
					scrollNode = BX.firstChild(parentNode);
					scrollLeft = scrollNode.scrollLeft;
					scrollRight = scrollNode.scrollWidth - (scrollLeft + scrollNode.clientWidth);

					if (scrollLeft == 0)
						BX.removeClass(parentNode, 'bx-soa-table-fade-left');
					else
						BX.addClass(parentNode, 'bx-soa-table-fade-left');

					if (scrollRight == 0)
						BX.removeClass(parentNode, 'bx-soa-table-fade-right');
					else
						BX.addClass(parentNode, 'bx-soa-table-fade-right');

					if (scrollLeft == 0 && scrollRight == 0)
						BX.addClass(parentNode, 'bx-soa-table-fade-right');
				}
				else
					BX.removeClass(parentNode, 'bx-soa-table-fade-left bx-soa-table-fade-right');
			}
		},

		totalBlockScrollCheck: function()
		{
			if (!this.totalInfoBlockNode || !this.totalGhostBlockNode)
				return;

			var scrollTop = BX.GetWindowScrollPos().scrollTop,
				ghostTop = BX.pos(this.totalGhostBlockNode).top,
				ghostBottom = BX.pos(this.orderBlockNode).bottom,
				width;

			if (ghostBottom - this.totalBlockNode.offsetHeight < scrollTop + 20)
				BX.addClass(this.totalInfoBlockNode, 'bx-soa-cart-total-bottom');
			else
				BX.removeClass(this.totalInfoBlockNode, 'bx-soa-cart-total-bottom');

			if (scrollTop > ghostTop && !BX.hasClass(this.totalInfoBlockNode, 'bx-soa-cart-total-fixed'))
			{
				width = this.totalInfoBlockNode.offsetWidth;
				BX.addClass(this.totalInfoBlockNode, 'bx-soa-cart-total-fixed');
				this.totalGhostBlockNode.style.paddingTop = this.totalInfoBlockNode.offsetHeight + 'px';
				this.totalInfoBlockNode.style.width = width + 'px';
			}
			else if (scrollTop < ghostTop && BX.hasClass(this.totalInfoBlockNode, 'bx-soa-cart-total-fixed'))
			{
				BX.removeClass(this.totalInfoBlockNode, 'bx-soa-cart-total-fixed');
				this.totalGhostBlockNode.style.paddingTop = 0;
				this.totalInfoBlockNode.style.width = '';
			}
		},

		totalBlockResizeCheck: function()
		{
			if (!this.totalInfoBlockNode || !this.totalGhostBlockNode)
				return;

			if (BX.hasClass(this.totalInfoBlockNode, 'bx-soa-cart-total-fixed'))
				this.totalInfoBlockNode.style.width = this.totalGhostBlockNode.offsetWidth + 'px';
		},

		totalBlockFixFont: function()
		{
			var totalNode = this.totalInfoBlockNode.querySelector('.bx-soa-cart-total-line.bx-soa-cart-total-line-total'),
				buttonNode, target, objList = [];

			if (totalNode)
			{
				target = BX.lastChild(totalNode);
				objList.push({
					node: target,
					maxFontSize: 28,
					smallestValue: false,
					scaleBy: target.parentNode
				});
			}

			if (this.params.SHOW_TOTAL_ORDER_BUTTON == 'Y')
			{
				buttonNode = this.totalInfoBlockNode.querySelector('.bx-soa-cart-total-button-container');
				if (buttonNode)
				{
					target = BX.lastChild(buttonNode);
					objList.push({
						node: target,
						maxFontSize: 18,
						smallestValue: false
					});
				}
			}

			if (objList.length)
				BX.FixFontSize.init({objList: objList, onAdaptiveResize: true});
		},

		setAnalyticsDataLayer: function(action, id)
		{
			if (!this.params.DATA_LAYER_NAME)
				return;

			var info, i;
			var products = [],
				dataVariant, item;

			for (i in this.result.GRID.ROWS)
			{
				if (this.result.GRID.ROWS.hasOwnProperty(i))
				{
					item = this.result.GRID.ROWS[i];
					dataVariant = [];

					for (i = 0; i < item.data.PROPS.length; i++)
					{
						dataVariant.push(item.data.PROPS[i].VALUE);
					}

					products.push({
						'id': item.data.PRODUCT_ID,
						'name': item.data.NAME,
						'price': item.data.PRICE,
						'brand': (item.data[this.params.BRAND_PROPERTY + '_VALUE'] || '').split(', ').join('/'),
						'variant': dataVariant.join('/'),
						'quantity': item.data.QUANTITY
					});
				}
			}

			switch (action)
			{
				case 'checkout':
					info = {
						'event': 'checkout',
						'ecommerce': {
							'checkout': {
								'products': products
							}
						}
					};
					break;
				case 'purchase':
					info = {
						'event': 'purchase',
						'ecommerce': {
							'purchase': {
								'actionField': {
									'id': id,
									'revenue': this.result.TOTAL.ORDER_TOTAL_PRICE,
									'tax': this.result.TOTAL.TAX_PRICE,
									'shipping': this.result.TOTAL.DELIVERY_PRICE
								},
								'products': products
							}
						}
					};
					break;
			}

			window[this.params.DATA_LAYER_NAME] = window[this.params.DATA_LAYER_NAME] || [];
			window[this.params.DATA_LAYER_NAME].push(info);
		},

		isOrderSaveAllowed: function()
		{
			return this.orderSaveAllowed === true;
		},

		allowOrderSave: function()
		{
			this.orderSaveAllowed = true;
		},

		disallowOrderSave: function()
		{
			this.orderSaveAllowed = false;
		},

		initUserConsent: function()
		{
			BX.ready(BX.delegate(function(){
				var control = BX.UserConsent && BX.UserConsent.load(this.orderBlockNode);
				if (control)
				{
					BX.addCustomEvent(control, BX.UserConsent.events.save, BX.proxy(this.doSaveAction, this));
					BX.addCustomEvent(control, BX.UserConsent.events.refused, BX.proxy(this.disallowOrderSave, this));
				}
			}, this));
		}
	};
})();
/* End */
;
; /* Start:"a:4:{s:4:"full";s:102:"/bitrix/components/bitrix/sale.location.selector.steps/templates/.default/script.min.js?17712652647752";s:6:"source";s:83:"/bitrix/components/bitrix/sale.location.selector.steps/templates/.default/script.js";s:3:"min";s:87:"/bitrix/components/bitrix/sale.location.selector.steps/templates/.default/script.min.js";s:3:"map";s:87:"/bitrix/components/bitrix/sale.location.selector.steps/templates/.default/script.map.js";}"*/
BX.namespace("BX.Sale.component.location.selector");if(typeof BX.Sale.component.location.selector.steps=="undefined"&&typeof BX.ui!="undefined"&&typeof BX.ui.widget!="undefined"){BX.Sale.component.location.selector.steps=function(e,t){this.parentConstruct(BX.Sale.component.location.selector.steps,e);BX.merge(this,{opts:{bindEvents:{"after-select-item":function(e){if(typeof this.opts.callback=="string"&&this.opts.callback.length>0&&this.opts.callback in window)window[this.opts.callback].apply(this,[e,this])}},disableKeyboardInput:false,dontShowNextChoice:false,pseudoValues:[],provideLinkBy:"id",requestParamsInject:false},vars:{cache:{nodesByCode:{}}},sys:{code:"slst"},flags:{skipAfterSelectItemEventOnce:false}});this.handleInitStack(t,BX.Sale.component.location.selector.steps,e)};BX.extend(BX.Sale.component.location.selector.steps,BX.ui.chainedSelectors);BX.merge(BX.Sale.component.location.selector.steps.prototype,{init:function(){this.pushFuncStack("buildUpDOM",BX.Sale.component.location.selector.steps);this.pushFuncStack("bindEvents",BX.Sale.component.location.selector.steps)},buildUpDOM:function(){},bindEvents:function(){var e=this,t=this.opts;if(t.disableKeyboardInput){this.bindEvent("after-control-placed",function(e){var t=e.getControl();BX.unbindAll(t.ctrls.toggle);BX.bind(t.ctrls.scope,"click",function(e){t.toggleDropDown()})})}BX.bindDelegate(this.getControl("quick-locations",true),"click",{tag:"a"},function(){e.setValueByLocationId(BX.data(this,"id"))})},setValueByLocationId:function(e){BX.Sale.component.location.selector.steps.superclass.setValue.apply(this,[e])},setValueByLocationIds:function(e){if(!e.PARENT_ID)return;this.flags.skipAfterSelectItemEventOnce=true;this.setValueByLocationId(e.PARENT_ID);this.bindEvent("after-control-placed",function(t){var s=t.getControl();if(s.vars.value!=false)return;if(e.IDS)this.opts.requestParamsInject={filter:{"=ID":e.IDS}};s.tryDisplayPage("toggle")})},setValueByLocationCode:function(e){var t=this.vars;if(e==null||e==false||typeof e=="undefined"||e.toString().length==0){this.displayRoute([]);this.setValueVariable("");this.setTargetValue("");this.fireEvent("after-clear-selection");return}this.fireEvent("before-set-value",[e]);var s=new BX.deferred;var i=this;s.done(BX.proxy(function(s){this.displayRoute(s);var i=t.cache.nodesByCode[e].VALUE;t.value=i;this.setTargetValue(this.checkCanSelectItem(i)?i:this.getLastValidValue())},this));s.fail(function(e){if(e=="notfound"){i.displayRoute([]);i.setValueVariable("");i.setTargetValue("");i.showError({errors:[i.opts.messages.nothingFound],type:"server-logic",options:{}})}});this.hideError();this.getRouteToNodeByCode(e,s)},setValue:function(e){if(this.opts.provideLinkBy=="id")BX.Sale.component.location.selector.steps.superclass.setValue.apply(this,[e]);else this.setValueByLocationCode(e)},setTargetValue:function(e){this.setTargetInputValue(this.opts.provideLinkBy=="code"?e?this.vars.cache.nodes[e].CODE:"":e);if(!this.flags.skipAfterSelectItemEventOnce)this.fireEvent("after-select-item",[e]);else this.flags.skipAfterSelectItemEventOnce=false},getValue:function(){if(this.opts.provideLinkBy=="id")return this.vars.value===false?"":this.vars.value;else{return this.vars.value?this.vars.cache.nodes[this.vars.value].CODE:""}},getNodeByLocationId:function(e){return this.vars.cache.nodes[e]},getSelectedPath:function(){var e=this.vars,t=[];if(typeof e.value=="undefined"||e.value==false||e.value=="")return t;if(typeof e.cache.nodes[e.value]!="undefined"){var s=e.cache.nodes[e.value];while(typeof s!="undefined"){var i=BX.clone(s);var n=i.PARENT_VALUE;delete i.PATH;delete i.PARENT_VALUE;delete i.IS_PARENT;if(typeof i.TYPE_ID!="undefined"&&typeof this.opts.types!="undefined")i.TYPE=this.opts.types[i.TYPE_ID].CODE;t.push(i);if(typeof n=="undefined"||typeof e.cache.nodes[n]=="undefined")break;else s=e.cache.nodes[n]}}return t},setInitialValue:function(){if(this.opts.selectedItem!==false)this.setValueByLocationId(this.opts.selectedItem);else if(this.ctrls.inputs.origin.value.length>0){if(this.opts.provideLinkBy=="id")this.setValueByLocationId(this.ctrls.inputs.origin.value);else this.setValueByLocationCode(this.ctrls.inputs.origin.value)}},getRouteToNodeByCode:function(e,t){var s=this.vars,i=this;if(typeof e!="undefined"&&e!==false&&e.toString().length>0){var n=[];if(typeof s.cache.nodesByCode[e]!="undefined")n=this.getRouteToNodeFromCache(s.cache.nodesByCode[e].VALUE);if(n.length==0){i.downloadBundle({request:{CODE:e},callbacks:{onLoad:function(o){for(var a in o){if(typeof s.cache.links[a]=="undefined")s.cache.incomplete[a]=true}i.fillCache(o,true);n=[];if(typeof s.cache.nodesByCode[e]!="undefined")n=this.getRouteToNodeFromCache(s.cache.nodesByCode[e].VALUE);if(n.length==0)t.reject("notfound");else t.resolve(n)},onError:function(){t.reject("internal")}},options:{}})}else t.resolve(n)}else t.resolve([])},addItem2Cache:function(e){this.vars.cache.nodes[e.VALUE]=e;this.vars.cache.nodesByCode[e.CODE]=e},controlChangeActions:function(e,t){var s=this,i=this.opts,n=this.vars,o=this.ctrls;this.hideError();if(t.length==0){s.truncateStack(e);n.value=s.getLastValidValue();s.setTargetValue(n.value);this.fireEvent("after-select-real-value")}else if(BX.util.in_array(t,i.pseudoValues)){s.truncateStack(e);s.setTargetValue(s.getLastValidValue());this.fireEvent("after-select-item",[t]);this.fireEvent("after-select-pseudo-value")}else{var a=n.cache.nodes[t];if(typeof a=="undefined")throw new Error("Selected node not found in the cache");s.truncateStack(e);if(i.dontShowNextChoice){if(a.IS_UNCHOOSABLE)s.appendControl(t)}else{if(typeof n.cache.links[t]!="undefined"||a.IS_PARENT)s.appendControl(t)}if(s.checkCanSelectItem(t)){n.value=t;s.setTargetValue(t);this.fireEvent("after-select-real-value")}}},refineRequest:function(e){var t={};var s={VALUE:"ID",DISPLAY:"NAME.NAME",1:"TYPE_ID",2:"CODE"};var i={};if(typeof e["PARENT_VALUE"]!="undefined"){t["=PARENT_ID"]=e.PARENT_VALUE;s["10"]="IS_PARENT"}if(typeof e["VALUE"]!="undefined"){t["=ID"]=e.VALUE;i["1"]="PATH"}if(BX.type.isNotEmptyString(e["CODE"])){t["=CODE"]=e.CODE;i["1"]="PATH"}if(BX.type.isNotEmptyString(this.opts.query.BEHAVIOUR.LANGUAGE_ID))t["=NAME.LANGUAGE_ID"]=this.opts.query.BEHAVIOUR.LANGUAGE_ID;if(BX.type.isNotEmptyString(this.opts.query.FILTER.SITE_ID)){if(typeof this.vars.cache.nodes[e.PARENT_VALUE]=="undefined"||this.vars.cache.nodes[e.PARENT_VALUE].IS_UNCHOOSABLE)t["=SITE_ID"]=this.opts.query.FILTER.SITE_ID}var n={select:s,filter:t,additionals:i,version:"2"};if(this.opts.requestParamsInject){for(var o in this.opts.requestParamsInject){if(this.opts.requestParamsInject.hasOwnProperty(o)){if(n[o]==undefined)n[o]={};for(var a in this.opts.requestParamsInject[o]){if(this.opts.requestParamsInject[o].hasOwnProperty(a)){if(n[o][a]!=undefined){var r=n[o][a];n[o][a]=[];n[o][a].push(r)}else{n[o][a]=[]}for(var l in this.opts.requestParamsInject[o][a])if(this.opts.requestParamsInject[o][a].hasOwnProperty(l))n[o][a].push(this.opts.requestParamsInject[o][a][l])}}}}}return n},refineResponce:function(e,t){if(e.length==0)return e;if(typeof t.PARENT_VALUE!="undefined"){var s={};s[t.PARENT_VALUE]=e["ITEMS"];e=s}else if(typeof t.VALUE!="undefined"||typeof t.CODE!="undefined"){var i={};if(typeof e.ITEMS[0]!="undefined"&&typeof e.ETC.PATH_ITEMS!="undefined"){var n=0;for(var o=e.ITEMS[0]["PATH"].length-1;o>=0;o--){var a=e.ITEMS[0]["PATH"][o];var r=e.ETC.PATH_ITEMS[a];r.IS_PARENT=true;i[n]=[r];n=r.VALUE}i[n]=[e.ITEMS[0]]}e=i}return e},showError:function(e){if(e.type!="server-logic")e.errors=[this.opts.messages.error];this.ctrls.errorMessage.innerHTML='<p><font class="errortext">'+BX.util.htmlspecialchars(e.errors.join(", "))+"</font></p>";BX.show(this.ctrls.errorMessage);BX.debug(e)}})}
/* End */
;
; /* Start:"a:4:{s:4:"full";s:104:"/bitrix/components/bitrix/main.userconsent.request/templates/.default/user_consent.min.js?17712652647945";s:6:"source";s:85:"/bitrix/components/bitrix/main.userconsent.request/templates/.default/user_consent.js";s:3:"min";s:89:"/bitrix/components/bitrix/main.userconsent.request/templates/.default/user_consent.min.js";s:3:"map";s:89:"/bitrix/components/bitrix/main.userconsent.request/templates/.default/user_consent.map.js";}"*/
(function(){function t(t){this.caller=t.caller;this.formNode=t.formNode;this.controlNode=t.controlNode;this.inputNode=t.inputNode;this.config=t.config}t.prototype={};BX.UserConsent={msg:{title:"MAIN_USER_CONSENT_REQUEST_TITLE",btnAccept:"MAIN_USER_CONSENT_REQUEST_BTN_ACCEPT",btnReject:"MAIN_USER_CONSENT_REQUEST_BTN_REJECT",loading:"MAIN_USER_CONSENT_REQUEST_LOADING",errTextLoad:"MAIN_USER_CONSENT_REQUEST_ERR_TEXT_LOAD"},events:{save:"main-user-consent-request-save",refused:"main-user-consent-request-refused",accepted:"main-user-consent-request-accepted"},current:null,autoSave:false,isFormSubmitted:false,isConsentSaved:false,attributeControl:"data-bx-user-consent",load:function(t){var e=this.find(t)[0];if(!e){return null}this.bind(e);return e},loadAll:function(t,e){this.find(t,e).forEach(this.bind,this)},loadFromForms:function(){var t=document.getElementsByTagName("FORM");t=BX.convert.nodeListToArray(t);t.forEach(this.loadAll,this)},find:function(t){if(!t){return[]}var e=t.querySelectorAll("["+this.attributeControl+"]");e=BX.convert.nodeListToArray(e);return e.map(this.createItem.bind(this,t)).filter((function(t){return!!t}))},bind:function(t){if(t.config.submitEventName){BX.addCustomEvent(t.config.submitEventName,this.onSubmit.bind(this,t))}else if(t.formNode){BX.bind(t.formNode,"submit",this.onSubmit.bind(this,t))}BX.bind(t.controlNode,"click",this.onClick.bind(this,t))},createItem:function(e,n){var i=n.querySelector('input[type="checkbox"]');if(!i){return}try{var s=JSON.parse(n.getAttribute(this.attributeControl));var o={formNode:null,controlNode:n,inputNode:i,config:s};if(e.tagName=="FORM"){o.formNode=e}else{o.formNode=BX.findParent(i,{tagName:"FORM"})}o.caller=this;return new t(o)}catch(t){return null}},onClick:function(t,e){if(t.config.url){return}this.requestForItem(t);e.preventDefault()},onSubmit:function(t,e){this.isFormSubmitted=true;if(this.check(t)){return true}else{if(e){e.preventDefault()}return false}},check:function(t){if(t.inputNode.checked){this.saveConsent(t);return true}this.requestForItem(t);return false},requestForItem:function(t){this.setCurrent(t);this.requestConsent(t.config.id,{sec:t.config.sec,replace:t.config.replace},this.onAccepted,this.onRefused)},setCurrent:function(t){this.current=t;this.autoSave=t.config.autoSave;this.actionRequestUrl=t.config.actionUrl},onAccepted:function(){if(!this.current){return}var t=this.current;this.saveConsent(this.current,(function(){BX.onCustomEvent(t,this.events.accepted,[]);BX.onCustomEvent(this,this.events.accepted,[t]);this.isConsentSaved=true;if(this.isFormSubmitted&&t.formNode&&!t.config.submitEventName){BX.submit(t.formNode)}}));this.current.inputNode.checked=true;this.current=null},onRefused:function(){BX.onCustomEvent(this.current,this.events.refused,[]);BX.onCustomEvent(this,this.events.refused,[this.current]);this.current.inputNode.checked=false;this.current=null;this.isFormSubmitted=false},initPopup:function(){if(this.popup){return}this.popup={}},popup:{isInit:false,caller:null,nodes:{container:null,shadow:null,head:null,loader:null,content:null,textarea:null,buttonAccept:null,buttonReject:null},onAccept:function(){this.hide();BX.onCustomEvent(this,"accept",[])},onReject:function(){this.hide();BX.onCustomEvent(this,"reject",[])},init:function(){if(this.isInit){return true}var t=document.querySelector("div[data-bx-template]");if(!t){return false}var e=document.createElement("DIV");e.innerHTML=t.innerHTML;e=e.children[0];if(!e){return false}document.body.insertBefore(e,document.body.children[0]);this.isInit=true;this.nodes.container=e;this.nodes.shadow=this.nodes.container.querySelector("[data-bx-shadow]");this.nodes.head=this.nodes.container.querySelector("[data-bx-head]");this.nodes.loader=this.nodes.container.querySelector("[data-bx-loader]");this.nodes.content=this.nodes.container.querySelector("[data-bx-content]");this.nodes.textarea=this.nodes.container.querySelector("[data-bx-textarea]");this.nodes.link=this.nodes.container.querySelector("[data-bx-link]");this.nodes.linkA=this.nodes.link?this.nodes.link.querySelector("a"):null;this.nodes.buttonAccept=this.nodes.container.querySelector("[data-bx-btn-accept]");this.nodes.buttonReject=this.nodes.container.querySelector("[data-bx-btn-reject]");this.nodes.buttonAccept.textContent=BX.message(this.caller.msg.btnAccept);this.nodes.buttonReject.textContent=BX.message(this.caller.msg.btnReject);BX.bind(this.nodes.buttonAccept,"click",this.onAccept.bind(this));BX.bind(this.nodes.buttonReject,"click",this.onReject.bind(this));return true},setTitle:function(t){if(!this.nodes.head){return}this.nodes.head.innerHTML=t},setContent:function(t){if(!this.nodes.textarea){return}this.nodes.textarea.innerHTML=t;this.nodes.link.style.display="none";this.nodes.textarea.style.display=""},setUrl:function(t){if(!this.nodes.link){return}this.nodes.linkA.textContent=t;this.nodes.linkA.href=t;this.nodes.link.style.display="";this.nodes.textarea.style.display="none"},show:function(t){if(typeof t=="boolean"){this.nodes.loader.style.display=!t?"":"none";this.nodes.content.style.display=t?"":"none"}this.nodes.container.style.display=""},hide:function(){this.nodes.container.style.display="none"}},cache:{list:[],stringifyKey:function(t){return BX.type.isString(t)?t:JSON.stringify({key:t})},set:function(t,e){var n=this.get(t);if(n){n.data=e}else{this.list.push({key:this.stringifyKey(t),data:e})}},getData:function(t){var e=this.get(t);return e?e.data:null},get:function(t){t=this.stringifyKey(t);var e=this.list.filter((function(e){return e.key==t}));return e.length>0?e[0]:null},has:function(t){return!!this.get(t)}},requestConsent:function(t,e,n,i){e=e||{};e.id=t;var s=this.cache.stringifyKey(e);if(!this.popup.isInit){this.popup.caller=this;if(!this.popup.init()){return}BX.addCustomEvent(this.popup,"accept",n.bind(this));BX.addCustomEvent(this.popup,"reject",i.bind(this))}if(this.current&&this.current.config.text){this.cache.set(s,this.current.config.text)}if(this.current&&this.current.config.url){this.setTextToPopup("",this.current.config.url)}else if(this.cache.has(s)){this.setTextToPopup(this.cache.getData(s))}else{this.popup.setTitle(BX.message(this.msg.loading));this.popup.show(false);BX.ajax.runAction("main.agreement.get",{data:e}).then((t=>{this.cache.set(s,t.data.content.html||"");this.setTextToPopup(this.cache.getData(s))})).catch((()=>{this.popup.hide();alert(BX.message(this.msg.errTextLoad))}))}},setTextToPopup:function(t,e){var n="";var i=t.indexOf("\n");var s=t.indexOf(".");i=i<s?i:s;if(i>=0&&i<=100){n=t.substr(0,i).trim();n=n.split(".").map(Function.prototype.call,String.prototype.trim).filter(String)[0]}this.popup.setTitle(n?n:BX.message(this.msg.title));if(e){this.popup.setUrl(e)}else{this.popup.setContent(t)}this.popup.show(true)},saveConsent:function(t,e){this.setCurrent(t);var n={id:t.config.id,sec:t.config.sec,url:window.location.href};if(t.config.originId){var i=t.config.originId;if(t.formNode&&i.indexOf("%")>=0){var s=t.formNode.querySelectorAll('input[type="text"], input[type="hidden"]');s=BX.convert.nodeListToArray(s);s.forEach((function(t){if(!t.name){return}i=i.replace("%"+t.name+"%",t.value?t.value:"")}))}n.originId=i}if(t.config.originatorId){n.originatorId=t.config.originatorId}BX.onCustomEvent(t,this.events.save,[n]);BX.onCustomEvent(this,this.events.save,[t,n]);if(this.isConsentSaved||!t.config.autoSave){if(e){e.apply(this,[])}}else{this.sendActionRequest("saveConsent",n,e,e)}},sendActionRequest:function(t,e,n,i){n=n||null;i=i||null;e.action=t;e.sessid=BX.bitrix_sessid();e.action=t;BX.ajax({url:this.actionRequestUrl,method:"POST",data:e,timeout:10,dataType:"json",processData:true,onsuccess:BX.proxy((function(t){t=t||{};if(t.error){i.apply(this,[t])}else if(n){n.apply(this,[t])}}),this),onfailure:BX.proxy((function(){var t={error:true,text:""};if(i){i.apply(this,[t])}}),this)})}};BX.ready((function(){BX.UserConsent.loadFromForms()}))})();
/* End */
;
; /* Start:"a:4:{s:4:"full";s:106:"/bitrix/components/bitrix/sale.order.ajax/templates/bootstrap_v4/scripts/yandex_maps.min.js?17712652645892";s:6:"source";s:87:"/bitrix/components/bitrix/sale.order.ajax/templates/bootstrap_v4/scripts/yandex_maps.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
BX.namespace("BX.Sale.OrderAjaxComponent.Maps");(function(){"use strict";BX.Sale.OrderAjaxComponent.Maps={init:function(t){this.context=t||{};this.pickUpOptions=this.context.options.pickUpMap;this.propsOptions=this.context.options.propertyMap;this.maxWaitTimeExpired=false;return this},initializePickUpMap:function(t){if(!ymaps)return;this.pickUpMap=new ymaps.Map("pickUpMap",{center:!!t?[t.GPS_N,t.GPS_S]:[this.pickUpOptions.defaultMapPosition.lat,this.pickUpOptions.defaultMapPosition.lon],zoom:this.pickUpOptions.defaultMapPosition.zoom});this.pickUpMap.behaviors.disable("scrollZoom");this.pickUpMap.events.add("click",BX.delegate(function(){if(this.pickUpMap.balloon.isOpen()){this.pickUpMap.balloon.close()}},this))},pickUpMapFocusWaiter:function(){if(this.pickUpMap&&this.pickUpMap.geoObjects){this.setPickUpMapFocus()}else{setTimeout(BX.proxy(this.pickUpMapFocusWaiter,this),100)}},setPickUpMapFocus:function(){var t,e,i;t=this.pickUpMap.geoObjects.getBounds();if(t&&t.length){e=t[1][0]-t[0][0];i=t[1][1]-t[0][1];t[0][0]-=e/10;t[0][1]-=i/10;t[1][0]+=e/10;t[1][1]+=i/10;this.pickUpMap.setBounds(t,{checkZoomRange:true})}},showNearestPickups:function(t,e){if(!ymaps)return;var i=this.pickUpOptions.secureGeoLocation&&BX.browser.IsChrome()&&!this.context.isHttps?"yandex":"auto";var s=this.pickUpOptions.geoLocationMaxTime||5e3;ymaps.geolocation.get({provider:i,timeOut:s}).then(BX.delegate(function(e){if(!this.maxWaitTimeExpired){this.maxWaitTimeExpired=true;e.geoObjects.options.set("preset","islands#darkGreenCircleDotIcon");this.pickUpMap.geoObjects.add(e.geoObjects);t(e)}},this),BX.delegate(function(){if(!this.maxWaitTimeExpired){this.maxWaitTimeExpired=true;e()}},this))},buildBalloons:function(t){if(!ymaps)return;var e=this;this.pickUpPointsJSON=[];for(var i=0;i<t.length;i++){var s=this.getStoreInfoHtml(t[i]);this.pickUpPointsJSON.push({type:"Feature",geometry:{type:"Point",coordinates:[t[i].GPS_N,t[i].GPS_S]},properties:{storeId:t[i].ID}});var o=new ymaps.Placemark([t[i].GPS_N,t[i].GPS_S],{hintContent:BX.util.htmlspecialchars(t[i].TITLE)+"<br />"+BX.util.htmlspecialchars(t[i].ADDRESS),storeTitle:t[i].TITLE,storeBody:s,id:t[i].ID,text:this.context.params.MESS_SELECT_PICKUP},{balloonContentLayout:ymaps.templateLayoutFactory.createClass("<h3>{{ properties.storeTitle }}</h3>"+"{{ properties.storeBody|raw }}"+'<br /><a class="btn btn-sm btn-primary" data-store="{{ properties.id }}">{{ properties.text }}</a>',{build:function(){this.constructor.superclass.build.call(this);var t=document.querySelector("a[data-store]");if(t)BX.bind(t,"click",this.selectStoreByClick)},clear:function(){var t=document.querySelector("a[data-store]");if(t)BX.unbind(t,"click",this.selectStoreByClick);this.constructor.superclass.clear.call(this)},selectStoreByClick:function(t){var i=t.target||t.srcElement;if(e.pickUpMap.container.isFullscreen()){e.pickUpMap.container.exitFullscreen()}e.context.selectStore(i.getAttribute("data-store"));e.context.clickNextAction(t);e.pickUpMap.balloon.close()}})});if(BX("BUYER_STORE").value===t[i].ID){o.options.set("preset","islands#redDotIcon")}this.pickUpMap.geoObjects.add(o)}},selectBalloon:function(t){if(this.pickUpMap&&this.pickUpMap.geoObjects){this.pickUpMap.geoObjects.each(BX.delegate(function(e){if(e.properties.get("id")){e.options.unset("preset")}if(e.properties.get("id")===t){e.options.set({preset:"islands#redDotIcon"});this.pickUpMap.panTo([e.geometry.getCoordinates()])}},this))}},pickUpFinalAction:function(){if(this.pickUpMap&&this.pickUpMap.geoObjects){var t=BX("BUYER_STORE");this.pickUpMap.geoObjects.each(function(e){if(e.properties.get("id")===t.value){e.options.set({preset:"islands#redDotIcon"})}else if(parseInt(e.properties.get("id"))>0){e.options.unset("preset")}})}},initializePropsMap:function(t){if(!ymaps)return;this.propsMap=new ymaps.Map("propsMap",{center:[t.lat,t.lon],zoom:t.zoom});this.propsMap.behaviors.disable("scrollZoom");this.propsMap.events.add("click",BX.delegate(function(t){var e=t.get("coords"),i;if(this.propsMap.geoObjects.getLength()===0){i=new ymaps.Placemark([e[0],e[1]],{},{draggable:true,preset:"islands#redDotIcon"});i.events.add(["parentchange","geometrychange"],function(){var t=BX("orderDescription"),e=i.geometry.getCoordinates(),s,o,p,a;if(t){s=t.value.indexOf(BX.message("SOA_MAP_COORDS")+":");if(s===-1){t.value=BX.message("SOA_MAP_COORDS")+": "+e[0]+", "+e[1]+"\r\n"+t.value}else{a=BX.message("SOA_MAP_COORDS")+": "+e[0]+", "+e[1];o=t.value.substring(0,s);p=t.value.substring(s+a.length);t.value=o+a+p}}});this.propsMap.geoObjects.add(i)}else{this.propsMap.geoObjects.get(0).geometry.setCoordinates([e[0],e[1]])}},this))},canUseRecommendList:function(){return this.pickUpPointsJSON&&this.pickUpPointsJSON.length},getRecommendedStoreIds:function(t){if(!t)return[];var e=[];var i=this.pickUpPointsJSON.length<this.pickUpOptions.nearestPickUpsToShow?this.pickUpPointsJSON.length:this.pickUpOptions.nearestPickUpsToShow;this.storeQueryResult={};for(var s=0;s<i;s++){var o=ymaps.geoQuery({type:"FeatureCollection",features:this.pickUpPointsJSON});var p=o.getClosestTo(t);var a=p.properties.get("storeId");this.storeQueryResult[a]=p;e.push(a);this.pickUpPointsJSON.splice(o.indexOf(p),1)}return e},getDistance:function(t,e){if(!t||!e)return false;var i=this.storeQueryResult[e];var s=ymaps.coordSystem.geo.getDistance(t.geometry.getCoordinates(),i.geometry.getCoordinates());s=Math.round(s/100)/10;return s},propsMapFocusWaiter:function(){},getStoreInfoHtml:function(t){var e="";if(t.ADDRESS)e+=BX.message("SOA_PICKUP_ADDRESS")+": "+BX.util.htmlspecialchars(t.ADDRESS)+"<br />";if(t.PHONE)e+=BX.message("SOA_PICKUP_PHONE")+": "+BX.util.htmlspecialchars(t.PHONE)+"<br />";if(t.SCHEDULE)e+=BX.message("SOA_PICKUP_WORK")+": "+BX.util.htmlspecialchars(t.SCHEDULE)+"<br />";if(t.DESCRIPTION)e+=BX.message("SOA_PICKUP_DESC")+": "+BX.util.htmlspecialchars(t.DESCRIPTION)+"<br />";return e}}})();
/* End */
;; /* /bitrix/components/bitrix/sale.location.selector.search/templates/.default/script.min.js?17712652647796*/
; /* /bitrix/components/bitrix/sale.order.ajax/templates/bootstrap_v4/script.js?177378867636496*/
; /* /bitrix/components/bitrix/sale.order.ajax/templates/bootstrap_v4/order_ajax.js?1773788614242586*/
; /* /bitrix/components/bitrix/sale.location.selector.steps/templates/.default/script.min.js?17712652647752*/
; /* /bitrix/components/bitrix/main.userconsent.request/templates/.default/user_consent.min.js?17712652647945*/
; /* /bitrix/components/bitrix/sale.order.ajax/templates/bootstrap_v4/scripts/yandex_maps.min.js?17712652645892*/

//# sourceMappingURL=page_5db6630611fdc411094aff7ab7a0d727.map.js