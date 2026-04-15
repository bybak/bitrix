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

					// If location was manually touched, reset profile selection only for guests.
					// For authorized users this clears buyer profile values in the "Покупатель" block.
					try {
						var isAuthorized = !!(
							window.BX
							&& BX.Sale
							&& BX.Sale.OrderAjaxComponent
							&& BX.Sale.OrderAjaxComponent.result
							&& BX.Sale.OrderAjaxComponent.result.IS_AUTHORIZED
						);
						if (ctx.__mfLocationTouched && !isAuthorized)
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

					try {
						if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.sync === 'function')
						{
							ctx.__mfBuyerAddress.sync();
						}
					} catch(e2) {}
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
			mfEdost.isAuthorized = function(){
				try {
					return !!(
						window.BX
						&& BX.Sale
						&& BX.Sale.OrderAjaxComponent
						&& BX.Sale.OrderAjaxComponent.result
						&& BX.Sale.OrderAjaxComponent.result.IS_AUTHORIZED
					);
				} catch(e) {}
				return false;
			};
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
						if (ctx.properties[pid].control && typeof ctx.properties[pid].control.getValue === 'function')
						{
							var v = String(ctx.properties[pid].control.getValue() || '');
							if (BX.type.isNotEmptyString(v))
								return v;
						}
						var hidden = ctx.controls.scope
							? ctx.controls.scope.querySelector('input[type="hidden"][name="ORDER_PROP_' + pid + '"]')
							: null;
						if (hidden && BX.type.isNotEmptyString(String(hidden.value || '')))
							return String(hidden.value || '');
					}
				} catch(e) {}
				return '';
			};

			mfEdost.getMfCheckout = function(){
				try {
					return (window.BX && BX.Sale && BX.Sale.OrderAjaxComponent && BX.Sale.OrderAjaxComponent.result
						&& BX.Sale.OrderAjaxComponent.result.MF_CHECKOUT) ? BX.Sale.OrderAjaxComponent.result.MF_CHECKOUT : {};
				} catch(e) {}
				return {};
			};

			mfEdost.getFallbackLocationCode = function(){
				return String(mfEdost.getMfCheckout().FALLBACK_LOCATION_CODE || '');
			};

			mfEdost.getEdostCity = function(){
				var form = BX('bx-soa-order-form');
				var el = form ? form.querySelector('input[type="hidden"][name="MF_EDOST_TO_CITY"]') : null;
				var v = el ? String(el.value || '').trim() : '';
				if (v !== '')
					return v;
				try {
					if (ctx.__mfEdostCityLast && String(ctx.__mfEdostCityLast).trim() !== '')
						return String(ctx.__mfEdostCityLast).trim();
				} catch(eC) {}
				try {
					if (window.MF_CHECKOUT_GEO && window.MF_CHECKOUT_GEO.edostCity)
						return String(window.MF_CHECKOUT_GEO.edostCity).trim();
				} catch(eW) {}
				return '';
			};

			mfEdost.getEffectiveLocationCode = function(){
				var loc = mfEdost.getCurrentLocationCode();
				if (BX.type.isNotEmptyString(loc))
				{
					return loc;
				}
				var fb = mfEdost.getFallbackLocationCode();
				// Резервный CODE (напр. СПб): нужен для mf_edost_offers.php, даже пока нет города из Photon —
				// иначе location_code пустой и тарифы не запрашиваются.
				if (BX.type.isNotEmptyString(fb))
				{
					return fb;
				}
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
				var jNom = ensureHidden('MF_NOMINATIM_JSON');
				var cToCity = ensureHidden('MF_EDOST_TO_CITY');
				try {
					if (ctx.__mfNominatimJsonLast && String(jNom.value || '').trim() === '')
						jNom.value = ctx.__mfNominatimJsonLast;
					if (ctx.__mfEdostCityLast && String(cToCity.value || '').trim() === '')
						cToCity.value = ctx.__mfEdostCityLast;
					if (window.MF_CHECKOUT_GEO)
					{
						if (String(jNom.value || '').trim() === '' && window.MF_CHECKOUT_GEO.nominatimJson)
							jNom.value = String(window.MF_CHECKOUT_GEO.nominatimJson);
						if (String(cToCity.value || '').trim() === '' && window.MF_CHECKOUT_GEO.edostCity)
							cToCity.value = String(window.MF_CHECKOUT_GEO.edostCity);
					}
				} catch(eMf) {}

				// Restore selection after Bitrix re-renders the form.
				try {
					if (mfEdost.selectedId && (!idEl.value || String(idEl.value) === ''))
					{
						idEl.value = String(mfEdost.selectedId);
						cEl.value = mfEdost.selected && mfEdost.selected.company ? String(mfEdost.selected.company) : '';
						nEl.value = mfEdost.selected && mfEdost.selected.name ? String(mfEdost.selected.name) : '';
						pEl.value = mfEdost.selected && typeof mfEdost.selected.price !== 'undefined' ? String(mfEdost.selected.price) : '';
					}
					else if (!idEl.value && window.BX && BX.Sale && BX.Sale.OrderAjaxComponent && BX.Sale.OrderAjaxComponent.result && BX.Sale.OrderAjaxComponent.result.MF_EDOST_DEFAULT)
					{
						var d = BX.Sale.OrderAjaxComponent.result.MF_EDOST_DEFAULT;
						idEl.value = String(d.id || '');
						cEl.value = String(d.company || '');
						nEl.value = String(d.name || d.company || '');
						pEl.value = String(d.price || '');
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

				// Patch Bitrix collapsed delivery summary builder once.
				// Bitrix recreates the collapsed "Доставка" summary from its technical delivery
				// ("Стандартный / 0 ₽"), so we need to re-apply selected virtual eDost data
				// immediately after that render point.
				try {
					if (!mfEdost._deliveryCollapsePatched)
					{
						mfEdost._deliveryCollapsePatched = true;
						var patchCollapseSummary = function(){
							try {
								if (
									!window.BX
									|| !BX.Sale
									|| !BX.Sale.OrderAjaxComponent
									|| typeof BX.Sale.OrderAjaxComponent.editFadeDeliveryContent !== 'function'
								)
								{
									return false;
								}

								if (BX.Sale.OrderAjaxComponent.editFadeDeliveryContent._mfEdostPatched)
								{
									return true;
								}

								var originalEditFadeDeliveryContent = BX.Sale.OrderAjaxComponent.editFadeDeliveryContent;
								var wrappedEditFadeDeliveryContent = function(){
									var result = originalEditFadeDeliveryContent.apply(this, arguments);
									try {
										mfEdost.deferApplyDeliverySummary();
									} catch(ePatch) {}
									return result;
								};
								wrappedEditFadeDeliveryContent._mfEdostPatched = true;
								BX.Sale.OrderAjaxComponent.editFadeDeliveryContent = wrappedEditFadeDeliveryContent;
								return true;
							} catch(ePatchWrap) {}
							return false;
						};

						if (!patchCollapseSummary())
						{
							setTimeout(patchCollapseSummary, 100);
							setTimeout(patchCollapseSummary, 400);
							setTimeout(patchCollapseSummary, 1000);
						}
					}
				} catch(e) {}

				// Контейнер тарифов создаём всегда (если блок доставки в DOM). Скрытие неактивного шага — в #mf-edost-style
				// (#bx-soa-delivery:not(.bx-selected) #mf-edost-box). Раньше здесь был return при !isDeliveryActive() —
				// из‑за этого #mf-edost-list не создавался, renderOffers выходил по if (!list) и тарифы eDost не показывались.

				var box = content.querySelector('#mf-edost-box');
				if (!box)
				{
					box = BX.create('DIV', {attrs: {id: 'mf-edost-box'}, style: {margin: '10px 0'}});
					var note = BX.create('DIV', {text: 'Расчёт доставки ориентировочный. Доставка оплачивается при получении. Финальная стоимость будет подтверждена менеджером после уточнения деталей.', style: {fontSize: '12px', opacity: '0.75', marginBottom: '10px'}});
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
				var eff = mfEdost.getEffectiveLocationCode();
				var hasLoc = BX.type.isNotEmptyString(String(eff || ''));

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
				pEl.value = (offer && typeof offer.price !== 'undefined' && offer.price !== null) ? String(offer.price) : '';

				var root = BX('bx-soa-order') || document;
				var sel = root && root.querySelector ? root.querySelector('#mf-edost-selected') : null;
				if (sel)
				{
					if (idEl.value)
					{
						sel.textContent = 'Выбрано: ' + (cEl.value ? (cEl.value + ' — ') : '') + nEl.value + ' — ' + (pEl.value !== '' ? (pEl.value + ' ₽') : 'оплата при получении');
					}
					else
					{
						sel.textContent = '';
					}
				}

				try {
					mfEdost.updateGate(mfEdost.getEffectiveLocationCode());
				} catch(e) {}

				try { mfEdost.syncSelectedRadio(); } catch(eSync) {}
				try { mfEdost.applyDeliverySummary(); } catch(e) {}
				try { mfEdost.applyTotalDeliveryLine(); } catch(e2) {}
				try { mfEdost.deferApplyDeliverySummary(); } catch(eDeferred) {}
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
				var priceText = price !== '' ? (price + ' ₽') : 'При получении';

				var blocks = [];
				try { BX('bx-soa-delivery') && blocks.push(BX('bx-soa-delivery')); } catch(e) {}
				try { BX('bx-soa-delivery-hidden') && blocks.push(BX('bx-soa-delivery-hidden')); } catch(e2) {}

				for (var i = 0; i < blocks.length; i++)
				{
					var del = blocks[i];
					if (!del || !del.querySelectorAll) continue;

					var nameBoxes = del.querySelectorAll('.bx-soa-pp-company-selected');
					for (var j = 0; j < nameBoxes.length; j++)
					{
						BX.cleanNode(nameBoxes[j]);
						nameBoxes[j].appendChild(BX.create('STRONG', {text: text}));
					}

					var priceBoxes = del.querySelectorAll('.bx-soa-pp-price');
					for (var k = 0; k < priceBoxes.length; k++)
					{
						priceBoxes[k].textContent = priceText;
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
						text = price !== '' ? (price + ' ₽') : 'При получении';
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

			mfEdost.deferApplyDeliverySummary = function(){
				var delays = [0, 30, 120, 300];
				for (var i = 0; i < delays.length; i++)
				{
					(function(delay){
						setTimeout(function(){
							try { mfEdost.applyDeliverySummary(); } catch(e1) {}
							try { mfEdost.applyTotalDeliveryLine(); } catch(e2) {}
							try { mfEdost.syncSelectedRadio(); } catch(e3) {}
						}, delay);
					})(delays[i]);
				}
			};

			mfEdost.installSummaryObserver = function(){
				try {
					if (mfEdost._summaryObserver)
						return;

					var deliveryBlock = BX('bx-soa-delivery');
					if (!deliveryBlock || typeof MutationObserver === 'undefined')
						return;

					mfEdost._summaryObserver = new MutationObserver(function(){
						try {
							mfEdost.deferApplyDeliverySummary();
						} catch(e1) {}
					});

					mfEdost._summaryObserver.observe(deliveryBlock, {
						childList: true,
						subtree: true
					});
				} catch(e) {}
			};

			mfEdost.onEnterDelivery = function(force){
				try {
					// После editActiveDeliveryBlock Bitrix может ещё не успеть повесить bx-selected на шаг «Доставка»;
					// без force onEnterDelivery выходит раньше времени и не восстанавливает #mf-edost-box / список тарифов.
					if (!force && !mfEdost.isDeliveryActive())
						return;
					mfEdost.ensureFields();
					mfEdost.hideBitrixDeliveryCards();

					var loc = mfEdost.getEffectiveLocationCode();
					var zipDigits = '';
					try {
						if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.getInputByCode === 'function')
						{
							var zInp = ctx.__mfBuyerAddress.getInputByCode('DELIVERY_ZIP');
							if (zInp && zInp !== false)
								zipDigits = String(zInp.value || '').replace(/\D+/g, '');
						}
					} catch(eZ) {}
					var fetchOpts = force ? { allowInactive: true } : {};
					// Re-render cached offers for current location (fetchOffers already does a cheap re-render
					// when key matches and offers are cached).
					if (BX.type.isNotEmptyString(loc))
					{
						mfEdost.fetchOffers(loc, zipDigits, fetchOpts);
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

			mfEdost.renderCustomOption = function(list, selectedId){
				if (!list) return;

				var form = BX('bx-soa-order-form');
				var companyEl = form ? form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_COMPANY"]') : null;
				var nameEl = form ? form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_NAME"]') : null;
				var currentCustomText = '';
				if (selectedId === 'custom')
				{
					currentCustomText = String((nameEl && nameEl.value) || '');
					if (String((companyEl && companyEl.value) || '') === 'Свой вариант' && currentCustomText.indexOf('Свой вариант') === 0)
					{
						currentCustomText = currentCustomText.replace(/^Свой вариант\s*[-:]\s*/i, '');
					}
				}

				var row = BX.create('DIV', {style: {padding: '10px', border: '1px solid #e5e5e5', borderRadius: '6px', marginBottom: '8px'}});
				var radio = BX.create('INPUT', {props: {type: 'radio', name: 'MF_EDOST_TARIF_UI', value: 'custom'}});
				if (selectedId === 'custom')
				{
					radio.checked = true;
				}
				var label = BX.create('SPAN', {text: ' Свой вариант'});
				var input = BX.create('INPUT', {
					props: {
						type: 'text',
						placeholder: 'Укажите предпочитаемый способ доставки',
						value: currentCustomText
					},
					style: {
						display: 'block',
						width: '100%',
						marginTop: '8px',
						padding: '8px 10px',
						border: '1px solid #d9d9d9',
						borderRadius: '4px'
					}
				});

				var applyCustom = function(){
					var text = BX.util.trim(String(input.value || ''));
					if (!text)
					{
						if (radio.checked)
						{
							mfEdost.clearSelection();
						}
						return;
					}

					radio.checked = true;
					mfEdost.setSelected({
						id: 'custom',
						company: 'Свой вариант',
						name: text,
						price: ''
					});
				};

				BX.bind(radio, 'change', function(){
					if (radio.checked)
					{
						applyCustom();
						try { input.focus(); } catch(e) {}
					}
				});
				BX.bind(input, 'input', applyCustom);
				BX.bind(row, 'click', function(e){
					if (e.target === input)
						return;
					radio.checked = true;
					applyCustom();
					try { input.focus(); } catch(e2) {}
				});

				row.appendChild(radio);
				row.appendChild(label);
				row.appendChild(input);
				list.appendChild(row);
			};

			mfEdost.renderOffers = function(offers){
				mfEdost.ensureFields();
				var root = BX('bx-soa-order') || document;
				var list = root && root.querySelector ? root.querySelector('#mf-edost-list') : null;
				if (!list)
					return;

				BX.cleanNode(list);
				offers = offers && offers.length ? offers : [];

				if (!offers.length)
				{
					try {
						var locEmpty = mfEdost.getEffectiveLocationCode();
						mfEdost.updateGate(locEmpty);

						// If location is not chosen yet, don't show "no delivery options" message.
						// The warning above ("Выберите местоположение...") is enough.
						if (!BX.type.isNotEmptyString(String(locEmpty || '')))
						{
							return;
						}
					} catch(e) {}

					list.appendChild(BX.create('DIV', {text: 'Нет доступных способов доставки для выбранного адреса.', style: {opacity: '0.8', marginBottom: '8px'}}));
					mfEdost.renderCustomOption(list, selectedId);
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

				mfEdost.renderCustomOption(list, selectedId);

				// If selection exists, ensure it is checked after re-render.
				try { mfEdost.syncSelectedRadio(); } catch(e) {}

				try {
					mfEdost.updateGate(mfEdost.getEffectiveLocationCode());
				} catch(e) {}
			};

			mfEdost.fetchOffers = function(locationCode, zipDigits, options){
				options = options || {};
				var allowInactive = !!options.allowInactive;
				var deliveryActive = mfEdost.isDeliveryActive();

				// Normally we fetch/render only in the open Delivery step,
				// but for authorized users we also allow background prefill.
				if (!deliveryActive && !allowInactive)
					return;

				locationCode = String(locationCode || '');
				zipDigits = String(zipDigits || '');
				var edostCity = mfEdost.getEdostCity();
				if (!BX || !BX.ajax || !BX.type || !BX.type.isNotEmptyString(locationCode))
					return;

				var key = locationCode + '|' + zipDigits + '|' + edostCity;
				// If form was re-rendered, we may need to re-render offers even without refetch.
				if (!mfEdost._inFlight && mfEdost._lastKey === key && mfEdost.offers && mfEdost.offers.length)
				{
					mfEdost.ensureFields();
					mfEdost.renderOffers(mfEdost.offers);
					mfEdost.hideBitrixDeliveryCards();
					try {
						var formCached = BX('bx-soa-order-form');
						var idElCached = formCached ? formCached.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]') : null;
						var hasSelectedCached = !!(idElCached && BX.type.isNotEmptyString(String(idElCached.value || '')));
						if (!hasSelectedCached && mfEdost.isAuthorized())
						{
							mfEdost.setSelected(mfEdost.offers[0]);
						}
					} catch(eCached) {}
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

				var nomJson = '';
				try {
					var formNj = BX('bx-soa-order-form');
					var jElNj = formNj ? formNj.querySelector('input[name="MF_NOMINATIM_JSON"]') : null;
					nomJson = jElNj ? String(jElNj.value || '') : '';
					if (!nomJson && ctx.__mfNominatimJsonLast)
						nomJson = String(ctx.__mfNominatimJsonLast);
					if (!nomJson && window.MF_CHECKOUT_GEO && window.MF_CHECKOUT_GEO.nominatimJson)
						nomJson = String(window.MF_CHECKOUT_GEO.nominatimJson);
				} catch(eNj) {}

				BX.ajax({
					url: '/ajax/mf_edost_offers.php',
					method: 'POST',
					dataType: 'json',
					data: {
						sessid: BX.bitrix_sessid(),
						location_code: locationCode,
						zip: zipDigits,
						mf_edost_to_city: edostCity,
						mf_nominatim_json: nomJson
					},
					onsuccess: function(resp){
						mfEdost._inFlight = false;
						if (resp && resp.ok && resp.offers && resp.offers.length)
						{
							mfEdost.offers = resp.offers;
							mfEdost.ensureFields();
							mfEdost.renderOffers(resp.offers);
							try {
								var form = BX('bx-soa-order-form');
								var idEl = form ? form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]') : null;
								var hasSelectedTariff = !!(idEl && BX.type.isNotEmptyString(String(idEl.value || '')));
								if (!hasSelectedTariff && mfEdost.isAuthorized())
								{
									mfEdost.setSelected(resp.offers[0]);
								}
							} catch(eAuto) {}
							mfEdost.hideBitrixDeliveryCards();
						}
						else
						{
							mfEdost.offers = [];
							mfEdost.ensureFields();
							try {
								var warn = BX('bx-soa-delivery') ? BX('bx-soa-delivery').querySelector('#mf-edost-warning') : null;
								if (warn && resp && !resp.ok)
								{
									warn.style.display = '';
									warn.className = 'alert alert-warning';
									var detail = String(resp.error || 'нет ответа');
									if (resp.message)
										detail += ' ' + String(resp.message);
									warn.textContent = 'Не удалось получить тарифы eDost: ' + detail + '. Обновите страницу или проверьте адрес.';
								}
								else if (warn && resp && resp.ok && (!resp.offers || !resp.offers.length))
								{
									warn.style.display = '';
									warn.className = 'alert alert-warning';
									warn.textContent = 'eDost не вернул тарифов для «' + String(resp.to_city || '') + '». Проверьте логин/пароль eDost, ограничения по направлению или вес товаров в корзине.';
								}
							} catch(eWn) {}
							mfEdost.renderOffers([]);
							mfEdost.hideBitrixDeliveryCards();
						}
					},
					onfailure: function(){
						mfEdost._inFlight = false;
						mfEdost.offers = [];
						mfEdost.ensureFields();
						mfEdost.renderOffers([]);
						mfEdost.hideBitrixDeliveryCards();
					}
				});
			};

			try {
				if (typeof BX !== 'undefined' && BX.saleOrderAjax)
					BX.saleOrderAjax.__mfEdost = mfEdost;
			} catch(ePub) {}
		}

		var mfBuyerAddress = ctx.__mfBuyerAddress || (ctx.__mfBuyerAddress = {});
		mfBuyerAddress.getPropByCode = function(code){
			try {
				var result = window.BX && BX.Sale && BX.Sale.OrderAjaxComponent ? BX.Sale.OrderAjaxComponent.result : null;
				var props = result && result.ORDER_PROP && result.ORDER_PROP.properties ? result.ORDER_PROP.properties : [];
				for (var i = 0; i < props.length; i++)
				{
					if (String(props[i].CODE || '') === String(code))
						return props[i];
				}
			} catch(e) {}
			return null;
		};
		mfBuyerAddress.getInputByCode = function(code){
			var prop = mfBuyerAddress.getPropByCode(code);
			if (!prop || !prop.ID)
				return false;
			try {
				return ctx.getInputByPropId(prop.ID);
			} catch(e) {}
			return false;
		};

		// Подсказки способа связи (datalist): пользователь может ввести произвольный текст.
		mfBuyerAddress.installConfirmChannelHints = function(ctx){
			var prop = mfBuyerAddress.getPropByCode('MF_CONFIRM_CHANNEL');
			if (!prop || !prop.ID)
				return;
			var inp = ctx.getInputByPropId(prop.ID);
			if (!inp || inp === false)
				return;
			var listId = 'mf-order-confirm-channel-datalist';
			if (!document.getElementById(listId))
			{
				var dl = document.createElement('datalist');
				dl.id = listId;
				var presets = [
					'Телефон: ',
					'Email: ',
					'WhatsApp: ',
					'Viber: ',
					'Telegram: ',
					'Max: '
				];
				for (var i = 0; i < presets.length; i++)
				{
					var o = document.createElement('option');
					o.value = presets[i];
					dl.appendChild(o);
				}
				(document.body || document.documentElement).appendChild(dl);
			}
			inp.setAttribute('list', listId);
			try {
				if (!inp.getAttribute('placeholder'))
					inp.setAttribute('placeholder', 'Например: Telegram @username, WhatsApp +7…');
			} catch(ePh) {}
		};

		mfBuyerAddress.getLocationText = function(){
			var locationText = '';
			try {
				var result = window.BX && BX.Sale && BX.Sale.OrderAjaxComponent ? BX.Sale.OrderAjaxComponent.result : null;
				var props = result && result.ORDER_PROP && result.ORDER_PROP.properties ? result.ORDER_PROP.properties : [];
				var locProp = null;
				for (var i = 0; i < props.length; i++)
				{
					if ((props[i].IS_LOCATION || 'N') === 'Y')
					{
						locProp = props[i];
						break;
					}
				}
				if (locProp && locProp.ID)
				{
					var locRow = ctx.getRowByPropId(locProp.ID);
					if (locRow && BX.Sale && BX.Sale.OrderAjaxComponent && typeof BX.Sale.OrderAjaxComponent.getLocationString === 'function')
					{
						locationText = String(BX.Sale.OrderAjaxComponent.getLocationString(locRow) || '');
					}
				}
			} catch(e) {}
			if (locationText === BX.message('SOA_NOT_SPECIFIED'))
				locationText = '';
			return locationText;
		};
		mfBuyerAddress.hideLegacyRows = function(){
			var customLocationProp = mfBuyerAddress.getPropByCode('DELIVERY_LOCATION_TEXT');
			var customAddressProp = mfBuyerAddress.getPropByCode('DELIVERY_ADDRESS');
			var customZipProp = mfBuyerAddress.getPropByCode('DELIVERY_ZIP');
			if (!customLocationProp || !customAddressProp)
				return;

			try {
				var result = window.BX && BX.Sale && BX.Sale.OrderAjaxComponent ? BX.Sale.OrderAjaxComponent.result : null;
				var props = result && result.ORDER_PROP && result.ORDER_PROP.properties ? result.ORDER_PROP.properties : [];
				for (var i = 0; i < props.length; i++)
				{
					var code = String(props[i].CODE || '');
					var row = props[i].ID ? ctx.getRowByPropId(props[i].ID) : null;
					if (!row)
						continue;
					if ((code === 'ADDRESS' || code === 'ZIP' || code === 'CITY')
						&& (!customZipProp || parseInt(props[i].ID, 10) !== parseInt(customZipProp.ID, 10))
						&& parseInt(props[i].ID, 10) !== parseInt(customAddressProp.ID, 10)
						&& parseInt(props[i].ID, 10) !== parseInt(customLocationProp.ID, 10))
					{
						BX.hide(row);
					}
				}
			} catch(e) {}
		};

		// Bitrix locationsCompletion() делает locationNode.removeAttribute('style') — снимает BX.hide.
		// Скрываем справочное «Местоположение» классом + !important (Photon заменяет ввод).
		mfBuyerAddress.hideBitrixLocationSelector = function(ctx){
			try {
				var stId = 'mf-checkout-hide-location-css';
				if (!document.getElementById(stId))
				{
					var st = document.createElement('style');
					st.id = stId;
					st.type = 'text/css';
					st.appendChild(document.createTextNode(
						'.mf-checkout-hide-location{' +
						'position:absolute!important;left:-9999px!important;top:0!important;' +
						'width:400px!important;min-height:120px!important;overflow:visible!important;' +
						'opacity:0!important;pointer-events:none!important;z-index:-1!important;' +
						'}'
					));
					(document.head || document.documentElement).appendChild(st);
				}
				for (var pidLoc in ctx.properties)
				{
					if (!ctx.properties.hasOwnProperty(pidLoc))
						continue;
					if (ctx.properties[pidLoc].type !== 'LOCATION')
						continue;
					var rowLoc = ctx.getRowByPropId(pidLoc);
					if (rowLoc)
						BX.addClass(rowLoc, 'mf-checkout-hide-location');
				}
			} catch(eHloc) {}
		};

		mfBuyerAddress.sync = function(){
			try {
				var locationInput = mfBuyerAddress.getInputByCode('DELIVERY_LOCATION_TEXT');
				var streetInput = mfBuyerAddress.getInputByCode('DELIVERY_ADDRESS');
				var zipInput = mfBuyerAddress.getInputByCode('DELIVERY_ZIP');
				var locationText = mfBuyerAddress.getLocationText();

				if (locationInput && locationInput !== false)
				{
					if (!ctx.__mfNominatimActive)
					{
						locationInput.value = locationText;
						locationInput.readOnly = true;
						locationInput.setAttribute('readonly', 'readonly');
						locationInput.style.backgroundColor = '#f8f9fa';
					}
				}

				if (streetInput && streetInput !== false)
				{
					streetInput.setAttribute('placeholder', 'Улица, дом, квартира');
				}

				if (zipInput && zipInput !== false)
				{
					if (!zipInput._mfBuyerZipBound)
					{
						zipInput._mfBuyerZipBound = true;
						BX.bind(zipInput, 'input', function(){
							ctx.__mfBuyerZipChangedManually = true;
						});
					}

					var result = window.BX && BX.Sale && BX.Sale.OrderAjaxComponent ? BX.Sale.OrderAjaxComponent.result : null;
					var props = result && result.ORDER_PROP && result.ORDER_PROP.properties ? result.ORDER_PROP.properties : [];
					var zipSourceProp = null;
					for (var i = 0; i < props.length; i++)
					{
						if ((props[i].IS_ZIP || 'N') === 'Y' && String(props[i].CODE || '') !== 'DELIVERY_ZIP')
						{
							zipSourceProp = props[i];
							break;
						}
					}
					if (!ctx.__mfNominatimActive && zipSourceProp && zipSourceProp.ID)
					{
						var zipSourceInput = ctx.getInputByPropId(zipSourceProp.ID);
						var zipSourceValue = zipSourceInput && zipSourceInput !== false ? String(zipSourceInput.value || '') : '';
						if (zipSourceValue !== '' && (!ctx.__mfBuyerZipChangedManually || String(zipInput.value || '') === ''))
						{
							zipInput.value = zipSourceValue;
						}
					}
				}

				mfBuyerAddress.hideLegacyRows();
				mfBuyerAddress.hideBitrixLocationSelector(ctx);

				try {
					var wrapNom = document.getElementById('mf-nominatim-wrap');
					if (wrapNom && !ctx.__mfNominatimActive)
					{
						var nomInp = wrapNom.querySelector('input[type="text"]');
						if (nomInp && !String(nomInp.value || '').trim())
						{
							var pre = '';
							if (locationInput && locationInput !== false && BX.type.isNotEmptyString(String(locationInput.value || '')))
								pre = String(locationInput.value || '').trim();
							if (!pre)
								pre = String(mfBuyerAddress.getLocationText() || '').trim();
							if (pre)
								nomInp.value = pre;
						}
					}
				} catch(eNomPre) {}
			} catch(e) {}
		};

		mfBuyerAddress.installNominatim = function(ctx){
			mfBuyerAddress.hideBitrixLocationSelector(ctx);
			if (document.getElementById('mf-nominatim-wrap'))
				return;
			var searchUrl = String(mfEdost.getMfCheckout().NOMINATIM_SEARCH_URL || '');
			if (!searchUrl)
				return;

			var formatLocationLine = function(addr, displayName){
				if (!addr || typeof addr !== 'object')
					return displayName || '';
				var parts = [];
				if (addr.state)
					parts.push(addr.state);
				if (addr.region && addr.region !== addr.state)
					parts.push(addr.region);
				var city = addr.city || addr.town || addr.village || addr.locality || addr.hamlet || addr.municipality || addr.name;
				if (city)
					parts.push(city);
				return parts.length ? parts.join(', ') : (displayName || '');
			};

			var formatStreetLine = function(addr){
				if (!addr || typeof addr !== 'object')
					return '';
				var p = [];
				if (addr.road)
					p.push(addr.road);
				if (addr.house_number)
					p.push(addr.house_number);
				if (addr.building)
					p.push('к. ' + addr.building);
				if (addr.flat)
					p.push('кв. ' + addr.flat);
				return p.join(', ');
			};

			var extractEdostCity = function(addr, displayName){
				if (addr && typeof addr === 'object')
				{
					var v = addr.city || addr.town || addr.village || addr.locality || addr.hamlet || addr.municipality
						|| addr.name || addr.city_district || addr.county || addr.state_district || '';
					v = String(v).trim();
					if (v !== '')
						return v;
				}
				if (displayName)
				{
					var parts = String(displayName).split(',');
					if (parts.length)
						return String(parts[0]).trim();
				}
				return '';
			};

			// eDost ожидает русское название населённого пункта; Photon иногда даёт латиницу в display_name при том, что в полях — кириллица.
			var pickCityRuForEdost = function(it, addr, displayName){
				var base = extractEdostCity(addr, displayName);
				if (base && /[\u0400-\u04FF]/.test(base))
					return base.trim();
				var blob = [
					addr && addr.name,
					addr && addr.city,
					addr && addr.town,
					addr && addr.locality,
					addr && addr.village,
					displayName,
					it && it.display_name
				].filter(function(x){ return x && String(x).trim() !== ''; }).join(', ');
				var m = blob.match(/[\u0400-\u04FF][^,;]*/);
				if (m)
					return String(m[0]).replace(/\s+$/,'').trim();
				return base ? base.trim() : '';
			};

			var applyFallbackBitrixLocation = function(fallbackCode){
				if (!BX.type.isNotEmptyString(fallbackCode))
					return;
				try
				{
					for (var pidLoc in ctx.properties)
					{
						if (!ctx.properties.hasOwnProperty(pidLoc))
							continue;
						if (ctx.properties[pidLoc].type !== 'LOCATION')
							continue;
						var rowLoc = ctx.getRowByPropId(pidLoc);
						var hidden = rowLoc ? rowLoc.querySelector('input[type="hidden"][name="ORDER_PROP_' + pidLoc + '"]') : null;
						if (hidden)
							hidden.value = fallbackCode;
						var ctrl = ctx.properties[pidLoc].control;
						if (ctrl && typeof ctrl.setValueByLocationCode === 'function')
							ctrl.setValueByLocationCode(fallbackCode);
						ctx.__mfLocationTouched = true;
						break;
					}
					if (window.BX && BX.Sale && BX.Sale.OrderAjaxComponent && typeof BX.Sale.OrderAjaxComponent.sendRequest === 'function')
					{
						setTimeout(function(){
							try {
								BX.Sale.OrderAjaxComponent.sendRequest();
							} catch(eSend) {}
						}, 450);
					}
				} catch(eFb) {}
			};

			// Вставляем ПЕРЕД полем Bitrix «Местоположение», иначе пользователь ищет Майами во внутреннем справочнике (там часто нет зарубежных городов).
			var anchorRow = null;
			try
			{
				for (var pidAnchor in ctx.properties)
				{
					if (!ctx.properties.hasOwnProperty(pidAnchor))
						continue;
					if (ctx.properties[pidAnchor].type === 'LOCATION')
					{
						anchorRow = ctx.getRowByPropId(pidAnchor);
						break;
					}
				}
			} catch(eAnch) {}
			if (!anchorRow)
			{
				var locPropFallback = mfBuyerAddress.getPropByCode('DELIVERY_LOCATION_TEXT');
				if (!locPropFallback || !locPropFallback.ID)
					return;
				anchorRow = ctx.getRowByPropId(locPropFallback.ID);
			}
			if (!anchorRow || !anchorRow.parentNode || anchorRow.parentNode.querySelector('#mf-nominatim-wrap'))
				return;

			var wrap = BX.create('DIV', {attrs: {id: 'mf-nominatim-wrap'}, style: {marginBottom: '14px', padding: '12px', background: '#f8f9fa', borderRadius: '8px', border: '1px solid #e8e8e8', position: 'relative', zIndex: '1200'}});
			var lbl = BX.create('DIV', {text: 'Адрес доставки', style: {fontWeight: '600', marginBottom: '6px', fontSize: '14px'}});
			var hint = BX.create('DIV', {
				text: 'Начните вводить город, улицу или страну (Майами, Berlin, Санкт-Петербург…).',
				style: {fontSize: '12px', opacity: '0.9', marginBottom: '8px', lineHeight: '1.45'}
			});
			var inp = BX.create('INPUT', {
				props: {type: 'text', autocomplete: 'off', placeholder: 'Например: Miami, Майами, Berlin, Санкт-Петербург Невский'},
				style: {width: '100%', padding: '8px 10px', border: '1px solid #d9d9d9', borderRadius: '4px', boxSizing: 'border-box', background: '#fff'}
			});
			var errBox = BX.create('DIV', {attrs: {id: 'mf-nominatim-err'}, style: {display: 'none', color: '#842029', fontSize: '12px', marginTop: '6px'}});
			var list = BX.create('DIV', {
				attrs: {id: 'mf-nominatim-list', className: 'mf-nominatim-list'},
				style: {
					display: 'none',
					position: 'absolute',
					left: '0',
					right: '0',
					top: '100%',
					marginTop: '4px',
					border: '1px solid #e5e5e5',
					borderRadius: '4px',
					maxHeight: '240px',
					overflowY: 'auto',
					background: '#fff',
					zIndex: '1300',
					boxShadow: '0 8px 24px rgba(0,0,0,.12)'
				}
			});
			wrap.appendChild(lbl);
			wrap.appendChild(hint);
			wrap.appendChild(inp);
			wrap.appendChild(errBox);
			wrap.appendChild(list);
			anchorRow.parentNode.insertBefore(wrap, anchorRow);

			try {
				if (!document.getElementById('mf-nominatim-overflow-fix'))
				{
					var st = document.createElement('style');
					st.id = 'mf-nominatim-overflow-fix';
					st.type = 'text/css';
					st.appendChild(document.createTextNode(
						'#bx-soa-properties .bx-soa-section-content{overflow:visible !important;}' +
						'#bx-soa-region .bx-soa-section-content{overflow:visible !important;}' +
						'#mf-nominatim-wrap{overflow:visible !important;}'
					));
					(document.head || document.documentElement).appendChild(st);
				}
			} catch(eSt) {}

			var timer = null;
			var showNominatimErr = function(msg){
				if (!errBox)
					return;
				if (!msg)
				{
					errBox.style.display = 'none';
					errBox.textContent = '';
					return;
				}
				errBox.style.display = '';
				errBox.textContent = msg;
			};
			var parseNominatimJson = function(raw){
				if (raw === null || raw === undefined)
					return null;
				if (typeof raw === 'object')
					return raw;
				if (typeof raw === 'string')
				{
					try {
						return JSON.parse(raw);
					} catch (eP) {
						return null;
					}
				}
				return null;
			};
			var runSearch = function(q){
				q = String(q || '').trim();
				if (q.length < 2)
				{
					list.style.display = 'none';
					BX.cleanNode(list);
					showNominatimErr('');
					return;
				}
				showNominatimErr('');
				// Важно: BX.ajax с method GET не приклеивает data к URL (в отличие от BX.ajax.get),
				// из‑за этого sessid не уходил на сервер → «bad sessid». POST — корректно.
				BX.ajax({
					url: searchUrl,
					method: 'POST',
					dataType: 'json',
					data: {sessid: BX.bitrix_sessid(), q: q},
					onsuccess: function(raw){
						var resp = parseNominatimJson(raw);
						BX.cleanNode(list);
						if (!resp)
						{
							list.style.display = 'none';
							showNominatimErr('Не удалось разобрать ответ сервера.');
							return;
						}
						if (!resp.ok)
						{
							list.style.display = 'none';
							showNominatimErr(String(resp.message || resp.error || 'Ошибка поиска адреса'));
							return;
						}
						if (!resp.items || !resp.items.length)
						{
							list.style.display = 'none';
							showNominatimErr('Ничего не найдено — уточните запрос.');
							return;
						}
						showNominatimErr('');
						list.style.display = '';
						for (var i = 0; i < resp.items.length; i++)
						{
							(function(it){
								var line = BX.create('DIV', {
									style: {padding: '8px 10px', cursor: 'pointer', borderBottom: '1px solid #f0f0f0', fontSize: '13px'},
									text: it.display_name || ''
								});
								BX.bind(line, 'click', function(ev){
									try {
										if (ev && ev.stopPropagation)
											ev.stopPropagation();
									} catch(eStop) {}
									list.style.display = 'none';
									showNominatimErr('');
									ctx.__mfNominatimActive = true;
									mfEdost.ensureFields();
									var form = BX('bx-soa-order-form');
									var jEl = form ? form.querySelector('input[name="MF_NOMINATIM_JSON"]') : null;
									var cEl = form ? form.querySelector('input[name="MF_EDOST_TO_CITY"]') : null;
									try {
										if (jEl)
											jEl.value = JSON.stringify(it);
									} catch(eJ) {}
									var addr = it.address || {};
									var cityRu = pickCityRuForEdost(it, addr, it.display_name);
									if (!cityRu && it.display_name)
										cityRu = String(it.display_name).split(',')[0].trim();
									if (!cityRu && addr && addr.name)
										cityRu = String(addr.name).trim();
									try {
										ctx.__mfNominatimJsonLast = JSON.stringify(it);
										ctx.__mfEdostCityLast = cityRu;
										window.MF_CHECKOUT_GEO = window.MF_CHECKOUT_GEO || {};
										window.MF_CHECKOUT_GEO.nominatimJson = ctx.__mfNominatimJsonLast;
										window.MF_CHECKOUT_GEO.edostCity = cityRu;
									} catch(eCtx0) {}
									if (cEl)
										cEl.value = cityRu;
									try {
										inp.value = String(it.display_name || cityRu || '');
									} catch(eInp) {}
									var locInput = mfBuyerAddress.getInputByCode('DELIVERY_LOCATION_TEXT');
									if (locInput && locInput !== false)
									{
										locInput.value = formatLocationLine(addr, it.display_name);
										locInput.readOnly = true;
									}
									var streetInput = mfBuyerAddress.getInputByCode('DELIVERY_ADDRESS');
									if (streetInput && streetInput !== false)
										streetInput.value = formatStreetLine(addr);
									var zipInput = mfBuyerAddress.getInputByCode('DELIVERY_ZIP');
									var zipDigits = '';
									if (addr.postcode)
										zipDigits = String(addr.postcode).replace(/\D+/g, '');
									if (zipInput && zipInput !== false && zipDigits)
										zipInput.value = zipDigits;

									var fb = mfEdost.getFallbackLocationCode();
									if (BX.type.isNotEmptyString(fb))
										applyFallbackBitrixLocation(fb);

									var runFetch = function(){
										var locForFetch = '';
										try {
											locForFetch = String(mfEdost.getEffectiveLocationCode() || '');
										} catch(eG) {}
										if (!BX.type.isNotEmptyString(locForFetch) && BX.type.isNotEmptyString(fb))
											locForFetch = fb;
										if (BX.type.isNotEmptyString(locForFetch))
										{
											// Пока открыт не шаг «Доставка», иначе fetchOffers сразу выходил и тарифы не запрашивались.
											mfEdost.fetchOffers(locForFetch, zipDigits, {allowInactive: true});
										}
										try {
											mfEdost.updateGate(mfEdost.getEffectiveLocationCode());
										} catch(eUg) {}
									};
									setTimeout(runFetch, 50);
								});
								list.appendChild(line);
							})(resp.items[i]);
						}
					},
					onfailure: function(){
						BX.cleanNode(list);
						list.style.display = 'none';
						showNominatimErr('Запрос не выполнен. Проверьте сеть или обновите страницу.');
					}
				});
			};

			BX.bind(inp, 'input', function(){
				var q = inp.value;
				if (timer)
					clearTimeout(timer);
				timer = setTimeout(function(){ runSearch(q); }, 400);
			});
			BX.bind(document, 'click', function(ev){
				try {
					if (!wrap.contains(ev.target))
						list.style.display = 'none';
				} catch(e) {}
			});

			try {
				if (!ctx.__mfNominatimActive && inp)
				{
					var preFill = '';
					var lip0 = mfBuyerAddress.getInputByCode('DELIVERY_LOCATION_TEXT');
					if (lip0 && lip0 !== false && BX.type.isNotEmptyString(String(lip0.value || '')))
						preFill = String(lip0.value || '').trim();
					if (!preFill)
						preFill = String(mfBuyerAddress.getLocationText() || '').trim();
					if (preFill)
						inp.value = preFill;
				}
			} catch(ePreFill) {}
		};

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

					// Motor-Force customization:
					// For authorized users we can already have LOCATION code in hidden ORDER_PROP_* input
					// from the selected profile, but the visual selector may still render empty after
					// our custom checkout block rearrangements. In that case, restore UI selection
					// explicitly from the hidden field value.
					try {
						var hiddenLocationInput = this.controls.scope.querySelector('input[type="hidden"][name="ORDER_PROP_' + k + '"]');
						var hiddenLocationValue = hiddenLocationInput ? String(hiddenLocationInput.value || '') : '';
						var currentControlValue = '';
						try { currentControlValue = String(control.getValue ? (control.getValue() || '') : ''); } catch(e0) {}
						if (hiddenLocationValue !== '' && currentControlValue === '')
						{
							setTimeout(function(controlRef, locationCode){
								try {
									if (controlRef && typeof controlRef.setValueByLocationCode === 'function')
									{
										controlRef.setValueByLocationCode(locationCode);
									}
								} catch(e1) {}
							}, 0, control, hiddenLocationValue);

							// Motor-Force customization:
							// On first load for authorized users, restore eDost tariffs immediately
							// from the profile location instead of relying only on selector events.
							setTimeout(function(locationCode){
								try {
									mfEdost.ensureFields();
									mfEdost.fetchOffers(locationCode, '', {allowInactive: mfEdost.isAuthorized()});
									mfEdost.deferApplyDeliverySummary();
								} catch(e2) {}
							}, 120, hiddenLocationValue);

							setTimeout(function(locationCode){
								try {
									mfEdost.fetchOffers(locationCode, '', {allowInactive: mfEdost.isAuthorized()});
									mfEdost.deferApplyDeliverySummary();
								} catch(e3) {}
							}, 400, hiddenLocationValue);
						}
					} catch(e) {}

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
									ctx.__mfNominatimActive = false;
									try {
										var form0 = BX('bx-soa-order-form');
										if (form0)
										{
											var jEl0 = form0.querySelector('input[name="MF_NOMINATIM_JSON"]');
											var cEl0 = form0.querySelector('input[name="MF_EDOST_TO_CITY"]');
											if (jEl0)
												jEl0.value = '';
											if (cEl0)
												cEl0.value = '';
										}
									} catch(eN0) {}
									setTimeout(function(){
										// Refresh virtual eDost offers for this destination.
										var locVal = '';
										try { locVal = control.getValue ? control.getValue() : ''; } catch(e2) {}
										mfEdost.ensureFields();
										mfEdost.fetchOffers(locVal, '', {allowInactive: mfEdost.isAuthorized()});
										mfEdost.updateGate(locVal);
										mfBuyerAddress.sync();
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
			mfBuyerAddress.sync();
		} catch(eBuyer) {}

		try {
			mfBuyerAddress.installNominatim(ctx);
		} catch(eNom) {}

		try {
			mfBuyerAddress.installConfirmChannelHints(ctx);
		} catch(eCc) {}

		try {
			// Force empty location on first load only for guests.
			// Authorized users should keep location restored from their selected profile.
			var mfIsAuthorized = mfEdost.isAuthorized();
			if (!mfIsAuthorized && !this.__mfEdostLocClearedOnce)
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
			var locInit = mfEdost.getEffectiveLocationCode();
			// For authorized users, prefill eDost tariff in background even if Delivery is collapsed.
			if (mfEdost.isDeliveryActive && (mfEdost.isDeliveryActive() || mfEdost.isAuthorized()))
			{
				mfEdost.fetchOffers(locInit, '', {allowInactive: mfEdost.isAuthorized()});
			}
			if (!BX.type.isNotEmptyString(String(locInit || '')))
			{
				mfEdost.clearSelection();
			}
			mfEdost.updateGate(locInit);
			mfEdost.applyDeliverySummary();
			mfEdost.applyTotalDeliveryLine();
			try { mfEdost.installSummaryObserver(); } catch(eObserver) {}
			// If Delivery step is currently open, ensure offers are shown and selection is restored.
			try { mfEdost.onEnterDelivery(); } catch(e) {}
		} catch(e) {}

		//set location initialized flag and refresh region & property actual content
		if (BX.Sale.OrderAjaxComponent)
			BX.Sale.OrderAjaxComponent.locationsCompletion();

		try {
			if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.hideBitrixLocationSelector === 'function')
				ctx.__mfBuyerAddress.hideBitrixLocationSelector(ctx);
		} catch(eLocFin) {}
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