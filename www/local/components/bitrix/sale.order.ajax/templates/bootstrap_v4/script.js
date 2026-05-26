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

		this.controls.scope = BX('bx-soa-order');

		// Bitrix BX(ready) на checkout иногда не срабатывает (кеш/composite) — eDost не инициализируется.
		var runInitDeferred = function(){
			try {
				ctx.initDeferredControl();
			} catch (eInit) {}
			ctx.BXCallAllowed = true; // unlock form refresher
		};

		if (typeof document !== 'undefined' && document.readyState !== 'loading' && this.controls.scope)
			runInitDeferred();
		else
			BX(runInitDeferred);

		setTimeout(function(){
			if (typeof ctx.__mfEdost !== 'object' || typeof ctx.__mfEdost.ensureFields !== 'function')
				runInitDeferred();
		}, 0);

		try
		{
			if (BX.addCustomEvent)
			{
				BX.addCustomEvent(window, 'mf-checkout-order-refreshed', function()
				{
					try
					{
						// Пока пользователь вводит адрес — не перерисовываем поля и не запускаем тяжёлую инициализацию.
						if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.isUserEditingAddress === 'function'
							&& ctx.__mfBuyerAddress.isUserEditingAddress())
						{
							try {
								if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.syncOrderPropInputsByCode === 'function')
								{
									var mfbLight = ctx.__mfBuyerAddress;
									var mfCodesLight = ['DELIVERY_LOCATION_TEXT', 'DELIVERY_ADDRESS', 'DELIVERY_ZIP'], cli, cinpL, cvL;
									for (cli = 0; cli < mfCodesLight.length; cli++)
									{
										cinpL = mfbLight.getInputByCode(mfCodesLight[cli]);
										if (cinpL && cinpL !== false)
										{
											cvL = String(cinpL.value || '');
											if (BX.type.isNotEmptyString(cvL))
												mfbLight.syncOrderPropInputsByCode(mfCodesLight[cli], cvL);
										}
									}
								}
								var mfELight = (typeof BX !== 'undefined' && BX.saleOrderAjax && BX.saleOrderAjax.__mfEdost) ? BX.saleOrderAjax.__mfEdost : null;
								if (mfELight && typeof mfELight.ensureFields === 'function')
									mfELight.ensureFields();
							} catch(eLightRef) {}
							return;
						}
						if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.isCheckoutFieldFocused === 'function'
							&& ctx.__mfBuyerAddress.isCheckoutFieldFocused())
						{
							return;
						}

						var wrap = document.getElementById('mf-nominatim-wrap');
						var inp = wrap ? wrap.querySelector('input[type="text"]') : null;
						if (inp && ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.syncNominatimInputValue === 'function')
							ctx.__mfBuyerAddress.syncNominatimInputValue();
						else if (inp && ctx.__mfNominatimDisplayLine && !String(inp.value || '').trim())
							inp.value = String(ctx.__mfNominatimDisplayLine);
						var jForm = BX('bx-soa-order-form');
						if (jForm && ctx.__mfNominatimJsonLast && !String((jForm.querySelector('input[name="MF_NOMINATIM_JSON"]') || {}).value || '').trim())
						{
							var jEl = jForm.querySelector('input[name="MF_NOMINATIM_JSON"]');
							if (jEl)
								jEl.value = String(ctx.__mfNominatimJsonLast);
						}
						if (jForm && ctx.__mfEdostCityLast && !String((jForm.querySelector('input[name="MF_EDOST_TO_CITY"]') || {}).value || '').trim())
						{
							var cEl = jForm.querySelector('input[name="MF_EDOST_TO_CITY"]');
							if (cEl)
								cEl.value = String(ctx.__mfEdostCityLast);
						}
						if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.getInputByCode === 'function')
						{
							var lip = ctx.__mfBuyerAddress.getInputByCode('DELIVERY_LOCATION_TEXT');
							if (lip && lip !== false && ctx.__mfNominatimDisplayLine && !String(lip.value || '').trim())
								lip.value = String(ctx.__mfNominatimDisplayLine);
							try {
								if (ctx.__mfBuyerAddress.syncOrderPropInputsByCode)
								{
									var lipB = ctx.__mfBuyerAddress.getInputByCode('DELIVERY_LOCATION_TEXT');
									var vLoc = (lipB && lipB !== false && String(lipB.value || '').trim()) ? String(lipB.value).trim() : String(ctx.__mfNominatimDisplayLine || '').trim();
									if (vLoc)
										ctx.__mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_LOCATION_TEXT', vLoc);
								}
							} catch(eLipH) {}
						}
						window.MF_CHECKOUT_GEO = window.MF_CHECKOUT_GEO || {};
						if (ctx.__mfNominatimJsonLast)
							window.MF_CHECKOUT_GEO.nominatimJson = String(ctx.__mfNominatimJsonLast);
						if (ctx.__mfEdostCityLast)
							window.MF_CHECKOUT_GEO.edostCity = String(ctx.__mfEdostCityLast);
						var mfE = (typeof BX !== 'undefined' && BX.saleOrderAjax && BX.saleOrderAjax.__mfEdost) ? BX.saleOrderAjax.__mfEdost : null;
						if (mfE && typeof mfE.ensureFields === 'function')
							mfE.ensureFields();
						if (mfE && typeof mfE.restoreLastOrderPreload === 'function')
							mfE.restoreLastOrderPreload();
						if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.syncDeliveryGeoToBuyerFields === 'function')
							ctx.__mfBuyerAddress.syncDeliveryGeoToBuyerFields({forceZip: true});
						if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.sync === 'function')
							ctx.__mfBuyerAddress.sync();
					}
					catch (eMfRef) {}
				});
			}
		}
		catch (eRegEv) {}

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
						if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.syncOrderPropInputsByCode === 'function')
						{
							var mfb = ctx.__mfBuyerAddress;
							var mfcodes = ['DELIVERY_LOCATION_TEXT', 'DELIVERY_ADDRESS', 'DELIVERY_ZIP'], ci, cinp, cv;
							for (ci = 0; ci < mfcodes.length; ci++)
							{
								cinp = mfb.getInputByCode(mfcodes[ci]);
								if (cinp && cinp !== false)
								{
									cv = String(cinp.value || '');
									if (BX.type.isNotEmptyString(cv))
										mfb.syncOrderPropInputsByCode(mfcodes[ci], cv);
								}
							}
						}
					} catch(eMfOrd) {}

					try {
						if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.sync === 'function'
							&& !(typeof ctx.__mfBuyerAddress.isUserEditingAddress === 'function'
								&& ctx.__mfBuyerAddress.isUserEditingAddress()))
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
			mfEdost._managerDeliveryFallback = false;
			mfEdost._edostCalculated = false;
			mfEdost.initPickupOffer = function(){
				var mc = mfEdost.getMfCheckout();
				var p = mc && mc.PICKUP ? mc.PICKUP : null;
				mfEdost.PICKUP_ID = p && p.ID ? String(p.ID) : 'pickup';
				mfEdost.PICKUP_OFFER = {
					id: mfEdost.PICKUP_ID,
					company: p && p.COMPANY ? String(p.COMPANY) : 'Самовывоз',
					name: p && p.NAME ? String(p.NAME) : 'Санкт-Петербург, ул. Салова, д. 57, к. 1 Литера Ч',
					price: p && p.PRICE != null && p.PRICE !== '' ? String(p.PRICE) : '0'
				};
				mfEdost.PICKUP_UI_LABEL = p && p.LABEL
					? String(p.LABEL)
					: ('Самовывоз (' + mfEdost.PICKUP_OFFER.name + ') — стоимость 0 ₽');
				mfEdost.PICKUP_LOCATION_TEXT = p && p.LOCATION_TEXT
					? String(p.LOCATION_TEXT)
					: 'Санкт-Петербург';
			};
			mfEdost.isPickupId = function(id){
				return String(id || '') === String(mfEdost.PICKUP_ID || 'pickup');
			};
			mfEdost.isPickupSelected = function(){
				return mfEdost.isPickupMode();
			};
			mfEdost.formatTariffPriceText = function(price){
				price = String(price == null ? '' : price).trim();
				if (price === '0' || price === '0.0' || price === '0,0')
					return '0 ₽';
				if (price !== '')
					return price + ' ₽';
				return 'При получении';
			};
			mfEdost.syncPickupAddressVisibility = function(){
				// Адрес и расчёт eDost остаются доступны при самовывозе — не скрываем поля.
				try {
					var del = BX('bx-soa-delivery');
					if (del)
						BX.removeClass(del, 'mf-pickup-selected');
				} catch(e) {}
			};
			mfEdost.applyPickupOrderProps = function(){
				// Тариф самовывоза уже в MF_EDOST_*; видимый адрес не перезаписываем — пользователь может считать доставку.
				try {
					mfEdost.ensureBitrixLocationPropertyFilled({sendRefresh: false});
				} catch(ePickupProps) {}
			};
			mfEdost.getUiSelectedTariffId = function(){
				var form = BX('bx-soa-order-form');
				var selectedId = '';
				try {
					if (form)
					{
						var idEl0 = form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]');
						selectedId = idEl0 ? String(idEl0.value || '') : '';
					}
				} catch(eSelId) {}
				if (!selectedId && mfEdost.selectedId)
					selectedId = String(mfEdost.selectedId);
				return selectedId;
			};
			mfEdost.abortEdostFetch = function(){
				mfEdost._fetchGen = (mfEdost._fetchGen || 0) + 1;
				mfEdost._inFlight = false;
			};
			mfEdost.renderPickupOnlyList = function(){
				mfEdost.ensureFields();
				var root = BX('bx-soa-order') || document;
				var list = root && root.querySelector ? root.querySelector('#mf-edost-list') : null;
				if (!list)
					return;

				BX.cleanNode(list);
				var selectedId = mfEdost.getUiSelectedTariffId();
				mfEdost.renderPickupOption(list, selectedId);
				try { mfEdost.syncSelectedRadio(); } catch(eSyncPu) {}
				try { mfEdost.updateGate(mfEdost.getEffectiveLocationCode()); } catch(eGatePu) {}
			};
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

			// Редактируемый UI (радио, адрес, eDost) живёт в активном шаге или в #bx-soa-delivery-hidden,
			// но не в свёрнутом summary #bx-soa-delivery — иначе после «Далее» радио дублируются в summary.
			mfEdost.getDeliveryEditRoot = function(){
				var del = BX('bx-soa-delivery');
				var delHidden = BX('bx-soa-delivery-hidden');
				try {
					if (del && del.classList && del.classList.contains('bx-selected'))
						return del;
					if (delHidden && delHidden.querySelector('.bx-soa-section-content'))
						return delHidden;
				} catch(eRoot) {}
				return del || delHidden;
			};

			mfEdost.getDeliveryEditContent = function(){
				var root = mfEdost.getDeliveryEditRoot();
				return root && root.querySelector ? root.querySelector('.bx-soa-section-content') : null;
			};

			mfEdost.cleanupFadeDeliveryUi = function(){
				try {
					var del = BX('bx-soa-delivery');
					if (!del || (del.classList && del.classList.contains('bx-selected')))
						return;
					var fadeContent = del.querySelector('.bx-soa-section-content');
					if (!fadeContent)
						return;
					var stray = fadeContent.querySelector('#mf-delivery-mode, #mf-nominatim-wrap, #mf-edost-box, #mf-edost-note, #mf-edost-warning');
					while (stray)
					{
						BX.remove(stray);
						stray = fadeContent.querySelector('#mf-delivery-mode, #mf-nominatim-wrap, #mf-edost-box, #mf-edost-note, #mf-edost-warning');
					}
				} catch(eCleanup) {}
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
						var hiddenList = ctx.controls.scope
							? ctx.controls.scope.querySelectorAll('input[type="hidden"][name="ORDER_PROP_' + pid + '"]')
							: null;
						if (hiddenList && hiddenList.length)
						{
							for (var hi = 0; hi < hiddenList.length; hi++)
							{
								var hEl = hiddenList[hi];
								if (hEl.getAttribute && hEl.getAttribute('data-mf-bitrix-fallback-loc') === 'Y')
									continue;
								if (BX.type.isNotEmptyString(String(hEl.value || '')))
									return String(hEl.value || '');
							}
						}
					}
				} catch(e) {}
				return '';
			};

			mfEdost.getMfCheckout = function(){
				var out = {};
				try {
					if (window.BX && BX.Sale && BX.Sale.OrderAjaxComponent && BX.Sale.OrderAjaxComponent.result
						&& BX.Sale.OrderAjaxComponent.result.MF_CHECKOUT)
					{
						out = BX.Sale.OrderAjaxComponent.result.MF_CHECKOUT;
					}
				} catch(e) {}
				try {
					if (typeof window !== 'undefined' && window.MF_CHECKOUT_BOOT)
					{
						var boot = window.MF_CHECKOUT_BOOT;
						var bootKeys = ['FALLBACK_LOCATION_CODE', 'LAST_ORDER_EDOST', 'LAST_ORDER_NOMINATIM_JSON', 'LAST_ORDER_EDOST_TO_CITY', 'LAST_ORDER_DELIVERY_MODE', 'DELIVERY_MODE', 'PICKUP'];
						for (var bi = 0; bi < bootKeys.length; bi++)
						{
							var bk = bootKeys[bi];
							if (!out[bk] && boot[bk])
								out[bk] = boot[bk];
						}
					}
				} catch(eBoot) {}
				return out;
			};

			mfEdost.initPickupOffer();

			mfEdost.DELIVERY_MODE_PICKUP = 'pickup';
			mfEdost.DELIVERY_MODE_DELIVERY = 'delivery';

			mfEdost.resolveInitialDeliveryMode = function(){
				var form = BX('bx-soa-order-form');
				try {
					var hid = form ? form.querySelector('input[name="MF_DELIVERY_MODE"]') : null;
					var fromHid = hid ? String(hid.value || '').trim() : '';
					if (fromHid === mfEdost.DELIVERY_MODE_PICKUP || fromHid === mfEdost.DELIVERY_MODE_DELIVERY)
						return fromHid;
				} catch(eH) {}
				var mc = mfEdost.getMfCheckout();
				var fromMc = mc && mc.DELIVERY_MODE ? String(mc.DELIVERY_MODE).trim() : '';
				if (fromMc === mfEdost.DELIVERY_MODE_PICKUP || fromMc === mfEdost.DELIVERY_MODE_DELIVERY)
					return fromMc;
				var fromLast = mc && mc.LAST_ORDER_DELIVERY_MODE ? String(mc.LAST_ORDER_DELIVERY_MODE).trim() : '';
				if (fromLast === mfEdost.DELIVERY_MODE_PICKUP || fromLast === mfEdost.DELIVERY_MODE_DELIVERY)
					return fromLast;
				return mfEdost.DELIVERY_MODE_PICKUP;
			};

			mfEdost.getDeliveryMode = function(){
				var form = BX('bx-soa-order-form');
				var el = form ? form.querySelector('input[name="MF_DELIVERY_MODE"]') : null;
				var v = el ? String(el.value || '').trim() : '';
				if (v === mfEdost.DELIVERY_MODE_PICKUP || v === mfEdost.DELIVERY_MODE_DELIVERY)
					return v;
				return mfEdost.resolveInitialDeliveryMode();
			};

			mfEdost.isPickupMode = function(){
				return mfEdost.getDeliveryMode() === mfEdost.DELIVERY_MODE_PICKUP;
			};

			mfEdost.isDeliveryAddressProperty = function(arProperty){
				if (!arProperty)
					return false;

				var code = String(arProperty.CODE || '').toUpperCase();
				var name = String(arProperty.NAME || '').trim();
				var type = String(arProperty.TYPE || '').toUpperCase();

				if (type === 'LOCATION' || arProperty.IS_LOCATION === 'Y')
					return true;
				if (code === 'DELIVERY_LOCATION_TEXT' || code === 'DELIVERY_ADDRESS' || code === 'DELIVERY_ZIP')
					return true;
				if (code === 'ADDRESS' || code === 'FULL_ADDRESS' || code === 'DELIVERY_ADDR' || code === 'CITY' || code === 'ZIP')
					return true;
				if (name === 'Адрес доставки')
					return true;
				if (arProperty.IS_ADDRESS === 'Y' || arProperty.IS_ADDRESS === true)
					return true;
				if (arProperty.IS_ZIP === 'Y' || arProperty.IS_ZIP === true)
					return true;

				return false;
			};

			mfEdost.syncPickupTariffFields = function(){
				var pickup = mfEdost.PICKUP_OFFER || {};
				mfEdost.selected = pickup;
				mfEdost.selectedId = String(pickup.id || 'pickup');
				var form = BX('bx-soa-order-form');
				if (!form)
					return;
				var setHidden = function(name, val){
					var el = form.querySelector('input[type="hidden"][name="' + name + '"]');
					if (el)
						el.value = String(val != null ? val : '');
				};
				setHidden('MF_EDOST_TARIF_ID', pickup.id || 'pickup');
				setHidden('MF_EDOST_TARIF_COMPANY', pickup.company || 'Самовывоз');
				setHidden('MF_EDOST_TARIF_NAME', pickup.name || '');
				setHidden('MF_EDOST_TARIF_PRICE', pickup.price != null ? pickup.price : '0');
				try { mfEdost.clearManagerDeliveryFallback(); } catch(eClr) {}
				try {
					if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.syncMotorForcePickupAddress === 'function')
						ctx.__mfBuyerAddress.syncMotorForcePickupAddress();
				} catch(ePickupAddr) {}
			};

			mfEdost.applyDeliveryModeClasses = function(mode){
				var del = BX('bx-soa-delivery');
				if (!del)
					return;
				BX.removeClass(del, 'mf-delivery-mode-pickup');
				BX.removeClass(del, 'mf-delivery-mode-delivery');
				BX.addClass(del, mode === mfEdost.DELIVERY_MODE_PICKUP ? 'mf-delivery-mode-pickup' : 'mf-delivery-mode-delivery');
			};

			mfEdost.ensureDeliveryPanel = function(){
				var content = mfEdost.getDeliveryEditContent();
				if (!content)
					return null;
				var modeWrap = content.querySelector('#mf-delivery-mode');
				if (!modeWrap)
					return null;

				var panel = modeWrap.querySelector('#mf-delivery-panel');
				if (!panel)
				{
					panel = BX.create('DIV', {attrs: {id: 'mf-delivery-panel', className: 'mf-delivery-panel'}});
					modeWrap.appendChild(panel);
				}
				return panel;
			};

			mfEdost.mountDeliveryUi = function(){
				try {
					var panel = mfEdost.ensureDeliveryPanel();
					if (!panel)
						return;

					var del = BX('bx-soa-delivery');
					var content = del ? del.querySelector('.bx-soa-section-content') : null;
					if (!content)
						return;

					var pick = function(id){
						return panel.querySelector(id) || content.querySelector(id);
					};
					var note = pick('#mf-edost-note');
					var warn = pick('#mf-edost-warning');
					var nom = pick('#mf-nominatim-wrap');
					var box = pick('#mf-edost-box');

					[note, warn, nom, box].forEach(function(el){
						if (el)
							panel.appendChild(el);
					});
				} catch(eMount) {}
			};

			mfEdost.syncDeliveryModeRadios = function(mode){
				var form = BX('bx-soa-order-form');
				if (!form)
					return;
				var radios = form.querySelectorAll('input[name="MF_DELIVERY_MODE_UI"]');
				for (var ri = 0; ri < radios.length; ri++)
					radios[ri].checked = (String(radios[ri].value) === mode);
			};

			mfEdost.clearDeliveryAddressUi = function(){
				try {
					ctx.__mfNominatimActive = false;
					ctx.__mfNominatimDisplayLine = '';
					ctx.__mfNominatimJsonLast = '';
					ctx.__mfEdostCityLast = '';
					ctx.__mfDeliveryLocationLine = '';
					ctx.__mfDeliveryZipLast = '';
					window.MF_CHECKOUT_GEO = window.MF_CHECKOUT_GEO || {};
					window.MF_CHECKOUT_GEO.nominatimJson = '';
					window.MF_CHECKOUT_GEO.edostCity = '';
				} catch(eCtxClr) {}

				try {
					var form = BX('bx-soa-order-form');
					if (form)
					{
						var jEl = form.querySelector('input[name="MF_NOMINATIM_JSON"]');
						var cEl = form.querySelector('input[name="MF_EDOST_TO_CITY"]');
						if (jEl)
							jEl.value = '';
						if (cEl)
							cEl.value = '';
					}
				} catch(eFormClr) {}

				try {
					var wrap = document.getElementById('mf-nominatim-wrap');
					var inp = wrap ? wrap.querySelector('input[type="text"]') : null;
					if (inp)
						inp.value = '';
					var list = document.getElementById('mf-nominatim-list');
					if (list)
					{
						list.style.display = 'none';
						BX.cleanNode(list);
					}
					var err = document.getElementById('mf-nominatim-err');
					if (err)
					{
						err.style.display = 'none';
						err.textContent = '';
					}
				} catch(eUiClr) {}

				try {
					mfEdost.offers = [];
					mfEdost._edostCalculated = false;
					mfEdost._lastKey = '';
					mfEdost.clearSelection();
					if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.clearPickupSyncedBuyerFields === 'function')
						ctx.__mfBuyerAddress.clearPickupSyncedBuyerFields({forceStreet: true});
					var listEl = document.querySelector('#mf-edost-list');
					if (listEl)
						BX.cleanNode(listEl);
					var selEl = document.querySelector('#mf-edost-selected');
					if (selEl)
						selEl.textContent = '';
					var warnEl = document.querySelector('#mf-edost-warning');
					if (warnEl)
					{
						warnEl.style.display = 'none';
						warnEl.textContent = '';
					}
				} catch(eOffersClr) {}
			};

			mfEdost.setDeliveryMode = function(mode, options){
				options = options || {};
				var prevMode = mfEdost.getDeliveryMode();
				mode = (mode === mfEdost.DELIVERY_MODE_DELIVERY)
					? mfEdost.DELIVERY_MODE_DELIVERY
					: mfEdost.DELIVERY_MODE_PICKUP;

				var form = BX('bx-soa-order-form');
				if (!form)
					return;

				var hid = form.querySelector('input[name="MF_DELIVERY_MODE"]');
				if (!hid)
				{
					hid = BX.create('INPUT', {props: {type: 'hidden', name: 'MF_DELIVERY_MODE', value: mode}});
					form.appendChild(hid);
				}
				hid.value = mode;

				mfEdost.applyDeliveryModeClasses(mode);
				mfEdost.syncDeliveryModeRadios(mode);
				mfEdost.mountDeliveryUi();

				if (mode === mfEdost.DELIVERY_MODE_PICKUP)
				{
					mfEdost.abortEdostFetch();
					mfEdost.clearDeliveryAddressUi();
					mfEdost.syncPickupTariffFields();
				}
				else if (options.clearPickupTariff !== false)
				{
					var idEl = form.querySelector('input[name="MF_EDOST_TARIF_ID"]');
					if (idEl && mfEdost.isPickupId(idEl.value))
						mfEdost.clearSelection();
				}

				if (mode === mfEdost.DELIVERY_MODE_DELIVERY && prevMode === mfEdost.DELIVERY_MODE_PICKUP)
				{
					try {
						if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.clearPickupSyncedBuyerFields === 'function')
							ctx.__mfBuyerAddress.clearPickupSyncedBuyerFields({forceStreet: true});
					} catch(eClrBuyerFromPickup) {}
				}

				try { mfEdost.updateGate(mfEdost.getEffectiveLocationCode()); } catch(eGate) {}
				try { mfEdost.applyDeliverySummary(); } catch(eSum) {}
				try { mfEdost.applyTotalDeliveryLine(); } catch(eTot) {}

				if (mode === mfEdost.DELIVERY_MODE_DELIVERY && options.fetch !== false)
				{
					if (mfEdost.hasUserDeliveryDestination())
					{
						var loc = mfEdost.getEffectiveLocationCode();
						var zipDigits = '';
						try {
							if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.getInputByCode === 'function')
							{
								var zInp = ctx.__mfBuyerAddress.getInputByCode('DELIVERY_ZIP');
								if (zInp && zInp !== false)
									zipDigits = String(zInp.value || '').replace(/\D+/g, '');
							}
						} catch(eZip) {}
						mfEdost.fetchOffers(loc, zipDigits, {allowInactive: true});
					}
					else
					{
						mfEdost.renderOffers([]);
						try { mfEdost.updateGate(''); } catch(eGateEmpty) {}
					}
				}
			};

			mfEdost.ensureDeliveryModeUi = function(){
				mfEdost.cleanupFadeDeliveryUi();

				var content = mfEdost.getDeliveryEditContent();
				if (!content)
					return;
				var form = BX('bx-soa-order-form');
				if (!form)
					return;

				if (!form.querySelector('input[name="MF_DELIVERY_MODE"]'))
				{
					form.appendChild(BX.create('INPUT', {
						props: {type: 'hidden', name: 'MF_DELIVERY_MODE', value: mfEdost.resolveInitialDeliveryMode()}
					}));
				}

				if (content.querySelector('#mf-delivery-mode'))
				{
					mfEdost.applyDeliveryModeClasses(mfEdost.getDeliveryMode());
					mfEdost.syncDeliveryModeRadios(mfEdost.getDeliveryMode());
					mfEdost.mountDeliveryUi();
					return;
				}

				var pickupLabel = String(
					mfEdost.PICKUP_UI_LABEL
					|| ('Самовывоз (' + String((mfEdost.PICKUP_OFFER && mfEdost.PICKUP_OFFER.name) || '') + ') — стоимость 0 ₽')
				);
				var wrap = BX.create('DIV', {attrs: {id: 'mf-delivery-mode', className: 'mf-delivery-mode'}});

				var makeRow = function(value, labelText){
					var row = BX.create('DIV', {props: {className: 'mf-delivery-mode__option'}});
					var radio = BX.create('INPUT', {props: {type: 'radio', name: 'MF_DELIVERY_MODE_UI', value: value}});
					var label = BX.create('SPAN', {text: ' ' + labelText});
					var selectMode = function(){
						try { radio.checked = true; } catch(eChk) {}
						mfEdost.setDeliveryMode(value);
					};
					BX.bind(radio, 'change', function(){
						if (radio.checked)
							selectMode();
					});
					BX.bind(row, 'click', function(e){
						if (e.target === radio)
							return;
						selectMode();
					});
					row.appendChild(radio);
					row.appendChild(label);
					return row;
				};

				wrap.appendChild(makeRow(mfEdost.DELIVERY_MODE_PICKUP, pickupLabel));
				wrap.appendChild(makeRow(mfEdost.DELIVERY_MODE_DELIVERY, 'Доставка'));
				wrap.appendChild(BX.create('DIV', {attrs: {id: 'mf-delivery-panel', className: 'mf-delivery-panel'}}));
				content.insertBefore(wrap, content.firstChild);

				if (!mfEdost._deliveryModeInited)
				{
					mfEdost._deliveryModeInited = true;
					mfEdost.setDeliveryMode(mfEdost.resolveInitialDeliveryMode(), {fetch: false, clearPickupTariff: false});
				}
			};

			mfEdost.getFallbackLocationCode = function(){
				var fromResult = String(mfEdost.getMfCheckout().FALLBACK_LOCATION_CODE || '').trim();
				if (BX.type.isNotEmptyString(fromResult))
					return fromResult;
				try {
					if (typeof window !== 'undefined' && window.MF_CHECKOUT_BOOT && window.MF_CHECKOUT_BOOT.FALLBACK_LOCATION_CODE)
						return String(window.MF_CHECKOUT_BOOT.FALLBACK_LOCATION_CODE || '').trim();
				} catch(eB) {}
				return '';
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
				return String(mfEdost.getCurrentLocationCode() || '').trim();
			};

			// Заполняет обязательное ORDER_PROP местоположения (IS_LOCATION): eDost может считаться по
			// FALLBACK / MF_EDOST_TO_CITY в JS, а поле Bitrix остаётся пустым → «Заполните Местоположение».
			mfEdost.applyBitrixOrderLocationCode = function(code, options){
				options = options || {};
				var sendRefresh = options.sendRefresh !== false;
				code = String(code || '').trim();
				if (!BX.type.isNotEmptyString(code))
					return false;
				var applied = false;
				try
				{
					for (var pidLoc in ctx.properties)
					{
						if (!ctx.properties.hasOwnProperty(pidLoc))
							continue;
						if (ctx.properties[pidLoc].type !== 'LOCATION')
							continue;
						try { delete ctx.properties[pidLoc].row; } catch(eDel) {}
						var rowLoc = null;
						try { rowLoc = ctx.getRowByPropId(pidLoc); } catch(e0) {}
						if (!rowLoc && ctx.controls.scope)
							rowLoc = ctx.controls.scope.querySelector('[data-property-id-row="' + pidLoc + '"]');
						if (!rowLoc)
							rowLoc = document.querySelector('#bx-soa-order [data-property-id-row="' + pidLoc + '"]');
						if (!rowLoc)
							continue;
						var hList = rowLoc.querySelectorAll('input[type="hidden"][name="ORDER_PROP_' + pidLoc + '"]');
						if (hList && hList.length)
						{
							for (var hi = 0; hi < hList.length; hi++)
							{
								var h = hList[hi];
								try { h.disabled = false; } catch(eD) {}
								h.value = code;
								try { h.removeAttribute('data-mf-bitrix-fallback-loc'); } catch(eR) {}
							}
						}
						var ctrl = ctx.properties[pidLoc].control;
						if (ctrl && typeof ctrl.setValueByLocationCode === 'function')
						{
							ctx.__mfSuppressLocationRefresh = true;
							try { ctrl.setValueByLocationCode(code); } catch(eC) {}
							setTimeout(function(){
								try { ctx.__mfSuppressLocationRefresh = false; } catch(eSup) {}
							}, 700);
						}
						ctx.__mfLocationTouched = true;
						applied = true;
						break;
					}
				}
				catch (eA) {}
				if (applied && sendRefresh && window.BX && BX.Sale && BX.Sale.OrderAjaxComponent && typeof BX.Sale.OrderAjaxComponent.sendRequest === 'function')
				{
					setTimeout(function(){
						try {
							BX.Sale.OrderAjaxComponent.sendRequest();
						} catch(eSend) {}
					}, 350);
				}
				return applied;
			};

			mfEdost.ensureBitrixLocationPropertyFilled = function(options){
				options = options || {};
				try
				{
					var cur = String(mfEdost.getCurrentLocationCode() || '').trim();
					if (cur !== '')
						return true;
					var fb = String(mfEdost.getFallbackLocationCode() || '').trim();
					if (fb === '')
						return false;
					return !!mfEdost.applyBitrixOrderLocationCode(fb, options);
				}
				catch (eE) {}
				return false;
			};

			// JSON выбора Nominatim (mf_edost_offers.php считает to_city и без Bitrix LOCATION_CODE).
			mfEdost.peekNominatimJson = function(){
				try {
					var form = BX('bx-soa-order-form');
					var j = form ? form.querySelector('input[name="MF_NOMINATIM_JSON"]') : null;
					var v = j ? String(j.value || '').trim() : '';
					if (v !== '')
						return v;
					if (ctx.__mfNominatimJsonLast && String(ctx.__mfNominatimJsonLast).trim() !== '')
						return String(ctx.__mfNominatimJsonLast).trim();
					if (window.MF_CHECKOUT_GEO && window.MF_CHECKOUT_GEO.nominatimJson)
						return String(window.MF_CHECKOUT_GEO.nominatimJson).trim();
				} catch(eNj) {}
				return '';
			};

			// Есть ли данные для расчёта eDost помимо кода местоположения Bitrix (см. mf_edost_offers.php).
			mfEdost.hasDestinationSignal = function(){
				if (BX.type.isNotEmptyString(String(mfEdost.getCurrentLocationCode() || '')))
					return true;
				if (BX.type.isNotEmptyString(String(mfEdost.getEdostCity() || '')))
					return true;
				if (BX.type.isNotEmptyString(mfEdost.peekNominatimJson()))
					return true;
				try {
					var mc = mfEdost.getMfCheckout();
					if (mc && mc.LAST_ORDER_NOMINATIM_JSON && String(mc.LAST_ORDER_NOMINATIM_JSON).trim() !== '')
						return true;
					if (mc && mc.LAST_ORDER_EDOST_TO_CITY && String(mc.LAST_ORDER_EDOST_TO_CITY).trim() !== '')
						return true;
				} catch(eMc) {}
				return false;
			};

			// Только явный адрес доставки (Nominatim / прошлый заказ), без дефолта Bitrix «Санкт-Петербург».
			mfEdost.hasUserDeliveryDestination = function(){
				if (BX.type.isNotEmptyString(mfEdost.peekNominatimJson()))
					return true;
				if (BX.type.isNotEmptyString(String(mfEdost.getEdostCity() || '')))
					return true;
				try {
					if (ctx.__mfNominatimActive && ctx.__mfNominatimDisplayLine && String(ctx.__mfNominatimDisplayLine).trim() !== '')
						return true;
				} catch(eDisp) {}
				try {
					var mc = mfEdost.getMfCheckout();
					if (mc && mc.LAST_ORDER_NOMINATIM_JSON && String(mc.LAST_ORDER_NOMINATIM_JSON).trim() !== '')
						return true;
					if (mc && mc.LAST_ORDER_EDOST_TO_CITY && String(mc.LAST_ORDER_EDOST_TO_CITY).trim() !== '')
						return true;
				} catch(eMc2) {}
				return false;
			};

			mfEdost.pickOfferForPreload = function(offers){
				if (!offers || !offers.length)
					return null;
				try {
					var mc = mfEdost.getMfCheckout();
					var wantId = mc && mc.LAST_ORDER_EDOST ? String(mc.LAST_ORDER_EDOST.ID || '') : '';
					if (wantId)
					{
						for (var i = 0; i < offers.length; i++)
						{
							if (String(offers[i].id) === wantId)
								return offers[i];
						}
					}
				} catch(ePick) {}
				return mfEdost.isAuthorized() ? offers[0] : null;
			};

			mfEdost.restoreLastOrderPreload = function(){
				try {
					if (mfEdost._lastOrderPreloadApplied)
						return;
					var mc = mfEdost.getMfCheckout();
					if (!mc)
						return;

					if (mc.LAST_ORDER_DELIVERY_MODE)
					{
						mfEdost.setDeliveryMode(String(mc.LAST_ORDER_DELIVERY_MODE), {fetch: false, clearPickupTariff: false});
					}

					var nom = String(mc.LAST_ORDER_NOMINATIM_JSON || '').trim();
					var city = String(mc.LAST_ORDER_EDOST_TO_CITY || '').trim();
					if (nom)
					{
						ctx.__mfNominatimJsonLast = nom;
					}
					if (city)
					{
						ctx.__mfEdostCityLast = city;
					}
					mfEdost.ensureFields();

					var edost = mc.LAST_ORDER_EDOST;
					if (edost && edost.ID && !mfEdost.isPickupMode())
					{
						mfEdost.setSelected({
							id: String(edost.ID),
							company: String(edost.COMPANY || ''),
							name: String(edost.NAME || ''),
							price: (edost.PRICE != null && edost.PRICE !== '') ? edost.PRICE : ''
						});
					}
					try {
						var locLine = '';
						if (nom)
						{
							var parsedNom = JSON.parse(nom);
							if (parsedNom && parsedNom.display_name)
								locLine = String(parsedNom.display_name).trim();
						}
						if (!locLine && ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.getPropValueByCode === 'function')
							locLine = String(ctx.__mfBuyerAddress.getPropValueByCode('DELIVERY_LOCATION_TEXT') || '').trim();
						if (locLine && ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.isGenericLocationDefault === 'function'
							&& ctx.__mfBuyerAddress.isGenericLocationDefault(locLine))
						{
							locLine = '';
						}
						if (locLine)
						{
							ctx.__mfNominatimDisplayLine = locLine;
							var wrapR = document.getElementById('mf-nominatim-wrap');
							var inpR = wrapR ? wrapR.querySelector('input[type="text"]') : null;
							if (inpR)
								inpR.value = locLine;
							if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.getInputByCode === 'function')
							{
								var locInputR = ctx.__mfBuyerAddress.getInputByCode('DELIVERY_LOCATION_TEXT');
								if (locInputR && locInputR !== false)
								{
									locInputR.value = locLine;
									try {
										if (typeof ctx.__mfBuyerAddress.syncOrderPropInputsByCode === 'function')
											ctx.__mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_LOCATION_TEXT', locLine);
									} catch(eSyncLoc) {}
								}
							}
						}
					} catch(eNomLine) {}
					mfEdost._lastOrderPreloadApplied = true;
					mfEdost.deferApplyDeliverySummary();
				} catch(eRestore) {}
			};

			mfEdost.injectStyles = function(){
				try {
					if (document.getElementById('mf-edost-style'))
						return;

					var st = document.createElement('style');
					st.id = 'mf-edost-style';
					st.type = 'text/css';
					st.appendChild(document.createTextNode(
						'#bx-soa-delivery .bx-soa-pp-item-container,' +
						'#bx-soa-delivery .bx-soa-pp-company,' +
						'#bx-soa-delivery .bx-soa-pp-desc-container,' +
						'#bx-soa-delivery .bx-soa-pp-list,' +
						'#bx-soa-delivery .bx-soa-pp{display:none !important;}' +
						'#bx-soa-delivery:not(.bx-selected) #mf-delivery-panel{display:none !important;}' +
						'#bx-soa-delivery:not(.bx-selected) #mf-delivery-mode{display:none !important;}' +
						'#bx-soa-delivery:not(.bx-selected) #mf-nominatim-wrap{display:none !important;}' +
						'#bx-soa-delivery:not(.bx-selected) #mf-edost-box{display:none !important;}' +
						'#bx-soa-delivery .bx-soa-more-btn button[disabled]{pointer-events:none;opacity:.55;}'
					));
					(document.head || document.documentElement).appendChild(st);
				} catch(e) {}
			};

			mfEdost.ensureFields = function(){
				mfEdost.injectStyles();
				mfEdost.cleanupFadeDeliveryUi();

				var form = BX('bx-soa-order-form');
				if (!form) return;

				try { mfEdost.ensureDeliveryModeUi(); } catch(eModeUi) {}

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
				ensureHidden('MF_EDOST_MANAGER_FALLBACK');
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

				try {
					var mc0 = mfEdost.getMfCheckout();
					if (mc0 && String(mc0.MANAGER_DELIVERY_POST || '') === 'Y')
					{
						var mfbIn = form.querySelector('input[name="MF_EDOST_MANAGER_FALLBACK"]');
						if (mfbIn)
							mfbIn.value = 'Y';
						var idChk0 = form.querySelector('input[name="MF_EDOST_TARIF_ID"]');
						var idVal0 = idChk0 ? String(idChk0.value || '').trim() : '';
						if (!BX.type.isNotEmptyString(idVal0))
							mfEdost._managerDeliveryFallback = true;
					}
				} catch(eMpost) {}

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
				var content = mfEdost.getDeliveryEditContent();
				if (!content) return;

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
										mfEdost.cleanupFadeDeliveryUi();
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

				var panel = mfEdost.ensureDeliveryPanel();
				if (!panel)
					return;

				if (!panel.querySelector('#mf-edost-note'))
				{
					panel.appendChild(BX.create('DIV', {
						attrs: {id: 'mf-edost-note', className: 'mf-edost-note'},
						text: 'Расчёт доставки ориентировочный. Доставка оплачивается при получении. Финальная стоимость будет подтверждена менеджером после уточнения деталей.'
					}));
				}
				if (!panel.querySelector('#mf-edost-warning'))
				{
					panel.appendChild(BX.create('DIV', {
						attrs: {id: 'mf-edost-warning', className: 'mf-edost-warning'},
						style: {display: 'none'}
					}));
				}

				var box = panel.querySelector('#mf-edost-box');
				if (!box)
				{
					box = BX.create('DIV', {attrs: {id: 'mf-edost-box', className: 'mf-edost-box'}});
					box.appendChild(BX.create('DIV', {attrs: {id: 'mf-edost-list', className: 'mf-edost-list'}}));
					box.appendChild(BX.create('DIV', {attrs: {id: 'mf-edost-selected', className: 'mf-edost-selected'}}));
					panel.appendChild(box);
				}
				else
				{
					if (!box.querySelector('#mf-edost-list'))
						box.appendChild(BX.create('DIV', {attrs: {id: 'mf-edost-list', className: 'mf-edost-list'}}));
					if (!box.querySelector('#mf-edost-selected'))
						box.appendChild(BX.create('DIV', {attrs: {id: 'mf-edost-selected', className: 'mf-edost-selected'}}));
				}

				mfEdost.mountDeliveryUi();
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
				var hasLoc = mfEdost.isPickupMode()
					? true
					: mfEdost.hasUserDeliveryDestination();
				var canProceedDelivery = mfEdost.isPickupMode()
					|| (hasLoc && (hasTariff || mfEdost._managerDeliveryFallback));
				var nextBtn = del.querySelector('.bx-soa-more-btn button.pull-right.btn.btn-primary, .bx-soa-more-btn button.btn.btn-primary');
				if (nextBtn)
				{
					if (!canProceedDelivery)
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
					if (mfEdost.isPickupMode())
					{
						warn.style.display = 'none';
						warn.textContent = '';
					}
					else if (!hasLoc)
					{
						warn.style.display = '';
						warn.className = 'alert alert-warning';
						warn.textContent = 'Выберите местоположение, чтобы увидеть варианты доставки.';
					}
					else if (!hasTariff && mfEdost._managerDeliveryFallback)
					{
						warn.style.display = '';
						warn.className = 'alert alert-info';
						warn.textContent = 'По выбранному адресу нет готовых тарифов доставки. Можно продолжить: в заказе доставка указана как 0 ₽, итоговую стоимость рассчитает менеджер.';
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
				try {
					if (offer && offer.id)
						mfEdost.clearManagerDeliveryFallback();
				} catch(eClr) {}
				mfEdost.selected = offer || null;
				try {
					mfEdost.ensureBitrixLocationPropertyFilled({sendRefresh: false});
				} catch(eLocFill) {}
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
						sel.textContent = 'Выбрано: ' + (cEl.value ? (cEl.value + ' — ') : '') + nEl.value + ' — ' + mfEdost.formatTariffPriceText(pEl.value);
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
				try { mfEdost.syncPickupAddressVisibility(); } catch(ePickupUi) {}
				try { mfEdost.applyDeliverySummary(); } catch(e) {}
				try { mfEdost.applyTotalDeliveryLine(); } catch(e2) {}
				try { mfEdost.deferApplyDeliverySummary(); } catch(eDeferred) {}
			};

			mfEdost.clearManagerDeliveryFallback = function(){
				mfEdost._managerDeliveryFallback = false;
				try {
					var form = BX('bx-soa-order-form');
					var m = form ? form.querySelector('input[name="MF_EDOST_MANAGER_FALLBACK"]') : null;
					if (m)
						m.value = '';
				} catch(eM) {}
			};

			mfEdost.applyManagerDeliveryFallback = function(){
				try {
					mfEdost._managerDeliveryFallback = true;
					mfEdost.ensureFields();
					var form = BX('bx-soa-order-form');
					var m = form ? form.querySelector('input[name="MF_EDOST_MANAGER_FALLBACK"]') : null;
					if (m)
						m.value = 'Y';
					mfEdost.clearSelection();
				} catch(eA) {}
				try { mfEdost.updateGate(mfEdost.getEffectiveLocationCode()); } catch(eG) {}
				try { mfEdost.applyTotalDeliveryLine(); } catch(eT) {}
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
				try { mfEdost.syncPickupAddressVisibility(); } catch(ePickupClr) {}
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
				var priceText = mfEdost.formatTariffPriceText(price);

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

					var text = '—';
					if (mfEdost._managerDeliveryFallback)
					{
						text = '0 ₽ (уточняется менеджером)';
					}
					else if (tid)
					{
						text = mfEdost.formatTariffPriceText(price);
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
				if (mfEdost._summaryDebounceTimer)
					clearTimeout(mfEdost._summaryDebounceTimer);
				mfEdost._summaryDebounceTimer = setTimeout(function(){
					mfEdost._summaryDebounceTimer = null;
					try { mfEdost.applyDeliverySummary(); } catch(e1) {}
					try { mfEdost.applyTotalDeliveryLine(); } catch(e2) {}
					try { mfEdost.syncSelectedRadio(); } catch(e3) {}
				}, 180);
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

					try {
						ctx.__mfNomAnchorRetries = 0;
						if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.installNominatim === 'function')
							ctx.__mfBuyerAddress.installNominatim(ctx);
						if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.syncBitrixLocationVisibility === 'function')
							ctx.__mfBuyerAddress.syncBitrixLocationVisibility(ctx);
					} catch(eNomMount) {}

					mfEdost.ensureFields();
					mfEdost.hideBitrixDeliveryCards();

					if (mfEdost.isPickupMode())
					{
						mfEdost.syncPickupTariffFields();
						mfEdost.updateGate(mfEdost.getEffectiveLocationCode());
						mfEdost.applyDeliverySummary();
						return;
					}

					var fetchOpts = force ? { allowInactive: true } : {};
					if (mfEdost.hasUserDeliveryDestination())
					{
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
						mfEdost.fetchOffers(loc, zipDigits, fetchOpts);
					}
					else
					{
						mfEdost.renderOffers([]);
					}

					mfEdost.syncSelectedRadio();
					mfEdost.updateGate(mfEdost.getEffectiveLocationCode());
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

				var row = BX.create('DIV', {props: {className: 'mf-edost-tariff mf-edost-tariff--custom'}});
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

			mfEdost.shouldShowCustomOption = function(){
				return !mfEdost.isPickupMode() && !!mfEdost._edostCalculated;
			};

			mfEdost.appendCustomOptionIfReady = function(list, selectedId){
				if (mfEdost.shouldShowCustomOption())
					mfEdost.renderCustomOption(list, selectedId);
			};

			mfEdost.renderPickupOption = function(list, selectedId){
				if (!list)
					return;

				var pickup = mfEdost.PICKUP_OFFER || {
					id: 'pickup',
					company: 'Самовывоз',
					name: 'Санкт-Петербург, ул. Салова, д. 57, к. 1 Литера Ч',
					price: '0'
				};
				var row = BX.create('DIV', {style: {padding: '10px', border: '1px solid #e5e5e5', borderRadius: '6px', marginBottom: '8px', cursor: 'pointer'}});
				var radio = BX.create('INPUT', {props: {type: 'radio', name: 'MF_EDOST_TARIF_UI', value: pickup.id}});
				if (selectedId === pickup.id)
				{
					radio.checked = true;
				}
				var label = BX.create('SPAN', {text: ' ' + String(mfEdost.PICKUP_UI_LABEL || ('Самовывоз (' + pickup.name + ') — стоимость 0 ₽'))});
				var selectPickup = function(){
					radio.checked = true;
					mfEdost.setSelected(pickup);
				};

				BX.bind(radio, 'change', function(){
					if (radio.checked)
						selectPickup();
				});
				BX.bind(row, 'click', function(e){
					if (e.target === radio)
						return;
					selectPickup();
				});

				row.appendChild(radio);
				row.appendChild(label);
				list.appendChild(row);
			};

			mfEdost.renderOffers = function(offers){
				mfEdost.ensureFields();
				var root = BX('bx-soa-order') || document;
				var list = root && root.querySelector ? root.querySelector('#mf-edost-list') : null;
				if (!list)
					return;

				if (mfEdost.isPickupMode())
					return;

				BX.cleanNode(list);
				offers = offers && offers.length ? offers : [];

				var selectedId = mfEdost.getUiSelectedTariffId();

				if (!offers.length)
				{
					try {
						var locEmpty = mfEdost.getEffectiveLocationCode();
						mfEdost.updateGate(locEmpty);

						if (!mfEdost.hasUserDeliveryDestination() || !mfEdost._edostCalculated)
						{
							return;
						}
					} catch(e) {}

					list.appendChild(BX.create('DIV', {
						props: {className: 'mf-edost-tariff mf-edost-tariff--empty'},
						text: 'Нет доступных способов доставки для выбранного адреса.'
					}));
					mfEdost.appendCustomOptionIfReady(list, selectedId);
					return;
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

						var row = BX.create('DIV', {props: {className: 'mf-edost-tariff'}});
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

				mfEdost.appendCustomOptionIfReady(list, selectedId);

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

				if (mfEdost.isPickupMode())
					return;

				if (!mfEdost.hasUserDeliveryDestination())
				{
					mfEdost.ensureFields();
					mfEdost.renderOffers([]);
					return;
				}

				// Normally we fetch/render only in the open Delivery step,
				// but for authorized users we also allow background prefill.
				if (!deliveryActive && !allowInactive)
					return;

				locationCode = String(locationCode || '');
				zipDigits = String(zipDigits || '');
				var edostCity = mfEdost.getEdostCity();
				var nomJsonPeek = mfEdost.peekNominatimJson();
				if (!BX || !BX.ajax || !BX.type)
					return;
				// Сервер mf_edost_offers.php принимает только mf_edost_to_city / mf_nominatim_json без location_code;
				// ранний return по пустому locationCode блокировал расчёт после выбора адреса без FALLBACK в Bitrix.
				if (!BX.type.isNotEmptyString(locationCode) && !BX.type.isNotEmptyString(edostCity) && !BX.type.isNotEmptyString(nomJsonPeek))
					return;

				var key = (locationCode || '__geo__') + '|' + zipDigits + '|' + edostCity;
				// If form was re-rendered, we may need to re-render offers even without refetch.
				if (!mfEdost._inFlight && mfEdost._lastKey === key && mfEdost._edostCalculated)
				{
					mfEdost.ensureFields();
					mfEdost.renderOffers(mfEdost.offers || []);
					mfEdost.hideBitrixDeliveryCards();
					try {
						var formCached = BX('bx-soa-order-form');
						var idElCached = formCached ? formCached.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]') : null;
						var hasSelectedCached = !!(idElCached && BX.type.isNotEmptyString(String(idElCached.value || '')));
						if (!hasSelectedCached && !mfEdost.isPickupMode())
						{
							var pickCached = mfEdost.pickOfferForPreload(mfEdost.offers);
							if (pickCached)
								mfEdost.setSelected(pickCached);
						}
					} catch(eCached) {}
					return;
				}
				if (mfEdost._inFlight)
					return;

				// New destination: require user to re-select eDost tariff.
				if (mfEdost._lastKey !== '' && mfEdost._lastKey !== key)
				{
					try { mfEdost.clearManagerDeliveryFallback(); } catch(eK) {}
					mfEdost._edostCalculated = false;
					if (!mfEdost.isPickupMode())
						mfEdost.clearSelection();
				}

				mfEdost._inFlight = true;
				mfEdost._lastKey = key;
				var fetchGen = mfEdost._fetchGen || 0;

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
						if (fetchGen !== (mfEdost._fetchGen || 0))
							return;
						mfEdost._edostCalculated = true;
						if (resp && resp.ok && resp.offers && resp.offers.length)
						{
							try { mfEdost.clearManagerDeliveryFallback(); } catch(eM0) {}
							mfEdost.offers = resp.offers;
							mfEdost.ensureFields();
							if (mfEdost.isPickupMode())
							{
								mfEdost.hideBitrixDeliveryCards();
								return;
							}
							mfEdost.renderOffers(resp.offers);
							try {
								if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.syncNominatimInputValue === 'function')
									ctx.__mfBuyerAddress.syncNominatimInputValue();
							} catch(eNomAfterOffers) {}
							try {
								var form = BX('bx-soa-order-form');
								var idEl = form ? form.querySelector('input[type="hidden"][name="MF_EDOST_TARIF_ID"]') : null;
								var hasSelectedTariff = !!(idEl && BX.type.isNotEmptyString(String(idEl.value || '')));
								if (!hasSelectedTariff && !mfEdost.isPickupMode())
								{
									var pickOffer = mfEdost.pickOfferForPreload(resp.offers);
									if (pickOffer)
										mfEdost.setSelected(pickOffer);
								}
							} catch(eAuto) {}
				try {
					mfEdost.ensureBitrixLocationPropertyFilled({sendRefresh: false});
				} catch(eLocOffers) {}
							mfEdost.hideBitrixDeliveryCards();
						}
						else
						{
							mfEdost.offers = [];
							mfEdost.ensureFields();
							try {
								var warn = BX('bx-soa-delivery') ? BX('bx-soa-delivery').querySelector('#mf-edost-warning') : null;
								if (resp && resp.ok && (!resp.offers || !resp.offers.length))
								{
									try { mfEdost.applyManagerDeliveryFallback(); } catch(eMf) {}
								}
								else
								{
									try { mfEdost.clearManagerDeliveryFallback(); } catch(eM1) {}
								}
								if (warn && resp && !resp.ok)
								{
									warn.style.display = '';
									warn.className = 'alert alert-warning';
									var detail = String(resp.error || 'нет ответа');
									if (resp.message)
										detail += ' ' + String(resp.message);
									warn.textContent = 'Не удалось получить тарифы eDost: ' + detail + '. Обновите страницу или проверьте адрес.';
								}
							} catch(eWn) {}
							if (mfEdost.isPickupMode())
							{
								mfEdost.hideBitrixDeliveryCards();
								return;
							}
							mfEdost.renderOffers([]);
							mfEdost.hideBitrixDeliveryCards();
						}
					},
					onfailure: function(){
						mfEdost._inFlight = false;
						if (fetchGen !== (mfEdost._fetchGen || 0))
							return;
						mfEdost._edostCalculated = true;
						mfEdost.offers = [];
						try { mfEdost.clearManagerDeliveryFallback(); } catch(eF) {}
						mfEdost.ensureFields();
						if (mfEdost.isPickupMode())
						{
							mfEdost.hideBitrixDeliveryCards();
							return;
						}
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

		// Сразу после объявления mfEdost: hidden MF_EDOST_* в форме (для shouldSkipSection / валидации «Далее»).
		try {
			mfEdost.ensureFields();
		} catch (eEarlyEns) {}

		var mfBuyerAddress = ctx.__mfBuyerAddress || (ctx.__mfBuyerAddress = {});
		mfBuyerAddress.isCheckoutFieldFocused = function(){
			try {
				var el = document.activeElement;
				if (!el || !el.tagName)
					return false;
				var tag = String(el.tagName).toUpperCase();
				if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT')
					return false;
				var form = BX('bx-soa-order-form');
				return !!(form && form.contains && form.contains(el));
			} catch(eFocus) {}
			return false;
		};
		mfBuyerAddress.getPropByCode = function(code){
			try {
				var result = window.BX && BX.Sale && BX.Sale.OrderAjaxComponent ? BX.Sale.OrderAjaxComponent.result : null;
				var props = result && result.ORDER_PROP && result.ORDER_PROP.properties ? result.ORDER_PROP.properties : [];
				var want = String(code || '').toUpperCase();
				for (var i = 0; i < props.length; i++)
				{
					if (String(props[i].CODE || '').toUpperCase() === want)
						return props[i];
				}
			} catch(e) {}
			return null;
		};
		mfBuyerAddress.getPropValueByCode = function(code){
			var prop = mfBuyerAddress.getPropByCode(code);
			if (prop)
			{
				if (prop.VALUE != null && String(prop.VALUE).trim() !== '')
					return String(prop.VALUE).trim();
				if (prop.VALUE_FORMATTED != null && String(prop.VALUE_FORMATTED).trim() !== '')
					return String(prop.VALUE_FORMATTED).trim();
			}
			try {
				var inp = mfBuyerAddress.getInputByCode(code);
				if (inp && inp !== false && String(inp.value || '').trim() !== '')
					return String(inp.value).trim();
				var p = mfBuyerAddress.getPropByCode(code);
				if (p && p.ID)
				{
					var form = BX('bx-soa-order-form');
					if (form)
					{
						var hid = form.querySelector('input[type="hidden"][name="ORDER_PROP_' + p.ID + '"]');
						if (hid && String(hid.value || '').trim() !== '')
							return String(hid.value).trim();
					}
				}
			} catch(eVal) {}
			return '';
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

		mfBuyerAddress.syncOrderPropInputsByCode = function(code, valueStr){
			try {
				var prop = mfBuyerAddress.getPropByCode(code);
				if (!prop || !prop.ID)
					return;
				var form = BX('bx-soa-order-form');
				if (!form)
					return;
				var sel = 'input[name="ORDER_PROP_' + prop.ID + '"], textarea[name="ORDER_PROP_' + prop.ID + '"]';
				var nodes = form.querySelectorAll(sel);
				var v = String(valueStr != null ? valueStr : '');
				for (var ni = 0; ni < nodes.length; ni++)
				{
					try { nodes[ni].disabled = false; } catch(ed) {}
					nodes[ni].value = v;
				}
			} catch(eSync) {}
		};

		mfBuyerAddress.syncOrderPropInputsByPropId = function(propId, valueStr){
			try {
				propId = parseInt(propId, 10);
				if (!propId)
					return;
				var form = BX('bx-soa-order-form');
				if (!form)
					return;
				var sel = 'input[name="ORDER_PROP_' + propId + '"], textarea[name="ORDER_PROP_' + propId + '"]';
				var nodes = form.querySelectorAll(sel);
				var v = String(valueStr != null ? valueStr : '');
				for (var ni = 0; ni < nodes.length; ni++)
				{
					try { nodes[ni].disabled = false; } catch(ed) {}
					nodes[ni].value = v;
				}
			} catch(eSyncId) {}
		};

		mfBuyerAddress.isLegacyDeliveryAddressProperty = function(arProperty){
			if (!arProperty)
				return false;
			var code = String(arProperty.CODE || '').toUpperCase();
			var name = String(arProperty.NAME || '').trim();
			if (code === 'DELIVERY_LOCATION_TEXT' || code === 'DELIVERY_ADDRESS' || code === 'DELIVERY_ZIP')
				return false;
			return name === 'Адрес доставки'
				|| code === 'ADDRESS'
				|| code === 'FULL_ADDRESS'
				|| code === 'DELIVERY_ADDR';
		};

		mfBuyerAddress.buildCombinedDeliveryAddressLine = function(){
			var city = String(mfBuyerAddress.getPropValueByCode('DELIVERY_LOCATION_TEXT') || '').trim();
			var street = String(mfBuyerAddress.getPropValueByCode('DELIVERY_ADDRESS') || '').trim();
			var zip = String(mfBuyerAddress.getPropValueByCode('DELIVERY_ZIP') || '').trim();
			var parts = [];
			if (city)
				parts.push(city);
			if (street)
				parts.push(street);
			var combined = parts.join(', ');
			if (zip)
				combined = combined ? (combined + ', ' + zip) : zip;
			return combined;
		};

		mfBuyerAddress.syncLegacyDeliveryAddressProperty = function(){
			try {
				var combined = mfBuyerAddress.buildCombinedDeliveryAddressLine();
				if (!combined)
					return false;

				var result = window.BX && BX.Sale && BX.Sale.OrderAjaxComponent ? BX.Sale.OrderAjaxComponent.result : null;
				var props = result && result.ORDER_PROP && result.ORDER_PROP.properties ? result.ORDER_PROP.properties : [];
				for (var i = 0; i < props.length; i++)
				{
					if (!props[i] || !props[i].ID)
						continue;
					if (!mfBuyerAddress.isLegacyDeliveryAddressProperty(props[i]))
						continue;
					mfBuyerAddress.syncOrderPropInputsByPropId(props[i].ID, combined);
				}
				return true;
			} catch(eLegacySync) {}
			return false;
		};

		mfBuyerAddress.getPickupDefaultAddresses = function(){
			var mfE = BX.saleOrderAjax && BX.saleOrderAjax.__mfEdost;
			var pickup = (mfE && mfE.PICKUP_OFFER) ? mfE.PICKUP_OFFER : {};
			var locText = String((mfE && mfE.PICKUP_LOCATION_TEXT) || 'Санкт-Петербург').trim();
			var fullAddr = String(pickup.name || 'Санкт-Петербург, ул. Салова, д. 57, к. 1 Литера Ч').trim();
			return {
				loc: locText,
				full: fullAddr,
				combined: fullAddr || locText
			};
		};

		mfBuyerAddress.isPickupDefaultAddress = function(text){
			text = String(text || '').trim();
			if (text === '')
				return false;
			var defs = mfBuyerAddress.getPickupDefaultAddresses();
			return text === defs.full || text === defs.combined || text === defs.loc;
		};

		mfBuyerAddress.clearPickupSyncedBuyerFields = function(options){
			options = options || {};
			var forceStreet = !!options.forceStreet;
			var defs = mfBuyerAddress.getPickupDefaultAddresses();

			var shouldClear = function(val){
				val = String(val || '').trim();
				if (val === '')
					return false;
				return forceStreet || mfBuyerAddress.isPickupDefaultAddress(val);
			};

			var clearByCode = function(code, always){
				var inp = mfBuyerAddress.getInputByCode(code);
				var val = mfBuyerAddress.getPropValueByCode(code);
				var cur = (inp && inp !== false) ? String(inp.value || '').trim() : String(val || '').trim();
				if (!always && !shouldClear(cur) && !shouldClear(val))
					return;
				mfBuyerAddress.syncOrderPropInputsByCode(code, '');
				if (inp && inp !== false)
					inp.value = '';
			};

			clearByCode('DELIVERY_ADDRESS', forceStreet);
			clearByCode('DELIVERY_LOCATION_TEXT', false);

			try {
				var mfE = BX.saleOrderAjax && BX.saleOrderAjax.__mfEdost;
				var result = window.BX && BX.Sale && BX.Sale.OrderAjaxComponent ? BX.Sale.OrderAjaxComponent.result : null;
				var props = result && result.ORDER_PROP && result.ORDER_PROP.properties ? result.ORDER_PROP.properties : [];
				for (var i = 0; i < props.length; i++)
				{
					if (!props[i] || !props[i].ID)
						continue;
					if (mfE && typeof mfE.isDeliveryAddressProperty === 'function' && !mfE.isDeliveryAddressProperty(props[i]))
						continue;
					var codeUp = String(props[i].CODE || '').toUpperCase();
					if (codeUp === 'DELIVERY_LOCATION_TEXT' || codeUp === 'DELIVERY_ADDRESS' || codeUp === 'DELIVERY_ZIP')
						continue;
					var propVal = mfBuyerAddress.getPropValueByCode(String(props[i].CODE || ''));
					if (!shouldClear(propVal))
						continue;
					mfBuyerAddress.syncOrderPropInputsByPropId(props[i].ID, '');
				}
			} catch(eLegacyClr) {}
		};

		mfBuyerAddress.syncMotorForcePickupAddress = function(){
			try {
				var mfE = BX.saleOrderAjax && BX.saleOrderAjax.__mfEdost;
				if (!mfE || typeof mfE.isPickupMode !== 'function' || !mfE.isPickupMode())
					return;

				var pickup = mfE.PICKUP_OFFER || {};
				var locText = String(mfE.PICKUP_LOCATION_TEXT || 'Санкт-Петербург').trim();
				var fullAddr = String(pickup.name || 'Санкт-Петербург, ул. Салова, д. 57, к. 1 Литера Ч').trim();
				var combined = fullAddr || locText;

				mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_LOCATION_TEXT', locText);
				mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_ADDRESS', fullAddr);

				var result = window.BX && BX.Sale && BX.Sale.OrderAjaxComponent ? BX.Sale.OrderAjaxComponent.result : null;
				var props = result && result.ORDER_PROP && result.ORDER_PROP.properties ? result.ORDER_PROP.properties : [];
				for (var i = 0; i < props.length; i++)
				{
					if (!props[i] || !props[i].ID)
						continue;
					if (!mfE.isDeliveryAddressProperty(props[i]))
						continue;
					var code = String(props[i].CODE || '').toUpperCase();
					if (code === 'DELIVERY_LOCATION_TEXT' || code === 'DELIVERY_ADDRESS' || code === 'DELIVERY_ZIP')
						continue;
					if (String(props[i].TYPE || '').toUpperCase() === 'LOCATION' || props[i].IS_LOCATION === 'Y')
						continue;
					mfBuyerAddress.syncOrderPropInputsByPropId(props[i].ID, combined);
				}
			} catch(ePickupSync) {}
		};

		// ПВЗ (СДЭК и т.п.): «Улица, дом» не заполняют вручную — isValidForm() идёт до AJAX, onBeforeSendRequest не успевает.
		mfBuyerAddress.syncPickupFromSelectedStore = function(){
			try {
				var comp = window.BX && BX.Sale && BX.Sale.OrderAjaxComponent ? BX.Sale.OrderAjaxComponent : null;
				if (!comp || typeof comp.getSelectedDelivery !== 'function' || typeof comp.getSelectedPickUp !== 'function')
					return;
				var del = comp.getSelectedDelivery();
				if (!del || !del.STORE || !Array.isArray(del.STORE) || !del.STORE.length)
					return;
				var pickup = comp.getSelectedPickUp();
				if (!pickup)
					return;
				var title = String(pickup.TITLE || '').trim();
				var addr = String(pickup.ADDRESS || '').trim();
				var line = '';
				if (title && addr)
					line = title + ', ' + addr;
				else
					line = title || addr;
				if (!line)
					return;
				var streetInp = mfBuyerAddress.getInputByCode('DELIVERY_ADDRESS');
				var streetVal = streetInp && streetInp !== false ? String(streetInp.value || '').trim() : '';
				if (!streetVal)
					mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_ADDRESS', line);
				var locInp = mfBuyerAddress.getInputByCode('DELIVERY_LOCATION_TEXT');
				var locVal = locInp && locInp !== false ? String(locInp.value || '').trim() : '';
				if (!locVal)
				{
					var locGuess = addr;
					if (locGuess.indexOf(',') !== -1)
						locGuess = String(locGuess.split(',')[0]).trim();
					if (!locGuess && title)
						locGuess = title;
					if (locGuess)
						mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_LOCATION_TEXT', locGuess);
				}
			} catch (ePvz) {}
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

		// Только явно сохранённый адрес (профиль / прошлый заказ), без дефолта Bitrix «Москва, Центр…».
		mfBuyerAddress.formatLocationLineFromAddr = function(addr, displayName){
			if (!addr || typeof addr !== 'object')
				return String(displayName || '').trim();
			var parts = [];
			if (addr.state)
				parts.push(String(addr.state).trim());
			if (addr.region && addr.region !== addr.state)
				parts.push(String(addr.region).trim());
			var city = addr.city || addr.town || addr.village || addr.locality || addr.hamlet || addr.municipality || addr.name;
			if (city)
				parts.push(String(city).trim());
			var out = [];
			for (var pi = 0; pi < parts.length; pi++)
			{
				if (pi === 0 || parts[pi] !== parts[pi - 1])
					out.push(parts[pi]);
			}
			return out.length ? out.join(', ') : String(displayName || '').trim();
		};

		mfBuyerAddress.formatStreetLineFromAddr = function(addr){
			if (!addr || typeof addr !== 'object')
				return '';
			var p = [];
			if (addr.road)
				p.push(String(addr.road).trim());
			if (addr.house_number)
				p.push(String(addr.house_number).trim());
			if (addr.building)
				p.push('к. ' + String(addr.building).trim());
			if (addr.flat)
				p.push('кв. ' + String(addr.flat).trim());
			return p.join(', ');
		};

		mfBuyerAddress.getDeliveryGeoFromSelection = function(){
			try {
				var raw = '';
				if (typeof mfEdost.peekNominatimJson === 'function')
					raw = String(mfEdost.peekNominatimJson() || '').trim();
				if (!raw && ctx.__mfNominatimJsonLast)
					raw = String(ctx.__mfNominatimJsonLast).trim();
				if (!raw && window.MF_CHECKOUT_GEO && window.MF_CHECKOUT_GEO.nominatimJson)
					raw = String(window.MF_CHECKOUT_GEO.nominatimJson).trim();

				var it = null;
				if (raw)
				{
					it = typeof raw === 'object' ? raw : JSON.parse(raw);
				}

				var addr = it && it.address ? it.address : {};
				var displayName = it && it.display_name ? String(it.display_name).trim() : '';
				var locationLine = mfBuyerAddress.formatLocationLineFromAddr(addr, displayName);
				if (!locationLine && ctx.__mfDeliveryLocationLine)
					locationLine = String(ctx.__mfDeliveryLocationLine).trim();
				var streetLine = mfBuyerAddress.formatStreetLineFromAddr(addr);
				var zip = '';
				if (addr && addr.postcode)
					zip = String(addr.postcode).replace(/\D+/g, '');
				if (!zip && ctx.__mfDeliveryZipLast)
					zip = String(ctx.__mfDeliveryZipLast).replace(/\D+/g, '');

				if (!locationLine && !zip && !streetLine && !displayName)
					return null;

				return {
					locationLine: locationLine,
					streetLine: streetLine,
					zip: zip,
					displayLine: displayName
				};
			} catch(eGeo) {}
			return null;
		};

		mfBuyerAddress.isUserEditingAddress = function(){
			try {
				if (ctx.__mfNominatimTyping || ctx.__mfBuyerAddressEditing)
					return true;
				var active = document.activeElement;
				if (!active || typeof active.tagName === 'undefined')
					return false;
				var wrapNom = document.getElementById('mf-nominatim-wrap');
				if (wrapNom && wrapNom.contains && wrapNom.contains(active))
					return true;
				var addrCodes = ['DELIVERY_ADDRESS', 'DELIVERY_ZIP', 'DELIVERY_LOCATION_TEXT'];
				for (var aci = 0; aci < addrCodes.length; aci++)
				{
					var addrInp = mfBuyerAddress.getInputByCode(addrCodes[aci]);
					if (addrInp && addrInp !== false && addrInp === active)
						return true;
				}
			} catch(eEditAddr) {}
			return false;
		};

		mfBuyerAddress.bindAddressFieldGuards = function(){
			try {
				var guardCodes = ['DELIVERY_ADDRESS', 'DELIVERY_ZIP', 'DELIVERY_LOCATION_TEXT'];
				for (var gci = 0; gci < guardCodes.length; gci++)
				{
					(function(codeGuard){
						var gInp = mfBuyerAddress.getInputByCode(codeGuard);
						if (!gInp || gInp === false || gInp._mfAddressGuardBound)
							return;
						gInp._mfAddressGuardBound = true;
						BX.bind(gInp, 'focus', function(){
							ctx.__mfBuyerAddressEditing = true;
						});
						BX.bind(gInp, 'blur', function(){
							setTimeout(function(){
								if (!mfBuyerAddress.isUserEditingAddress())
									ctx.__mfBuyerAddressEditing = false;
							}, 180);
						});
					})(guardCodes[gci]);
				}
			} catch(eBindGuard) {}
		};

		mfBuyerAddress.syncDeliveryGeoToBuyerFields = function(options){
			options = options || {};
			try {
				var activeEl = null;
				try { activeEl = document.activeElement; } catch(eActEl) {}
				var mfE = BX.saleOrderAjax && BX.saleOrderAjax.__mfEdost;
				if (!mfE || typeof mfE.isPickupMode !== 'function' || mfE.isPickupMode())
					return false;
				if (typeof mfE.hasUserDeliveryDestination === 'function' && !mfE.hasUserDeliveryDestination())
					return false;

				var geo = mfBuyerAddress.getDeliveryGeoFromSelection();
				if (!geo)
					return false;

				if (geo.locationLine)
					ctx.__mfDeliveryLocationLine = geo.locationLine;
				if (geo.zip)
					ctx.__mfDeliveryZipLast = geo.zip;

				var locationInput = mfBuyerAddress.getInputByCode('DELIVERY_LOCATION_TEXT');
				if (locationInput && locationInput !== false && geo.locationLine && locationInput !== activeEl)
				{
					locationInput.value = geo.locationLine;
					mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_LOCATION_TEXT', geo.locationLine);
					try {
						locationInput.removeAttribute('readonly');
						locationInput.readOnly = false;
					} catch(eRo) {}
				}

				var zipInput = mfBuyerAddress.getInputByCode('DELIVERY_ZIP');
				if (zipInput && zipInput !== false && geo.zip && zipInput !== activeEl)
				{
					if (options.forceZip || !ctx.__mfBuyerZipChangedManually || String(zipInput.value || '').trim() === '')
					{
						zipInput.value = geo.zip;
						mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_ZIP', geo.zip);
					}
				}

				if (options.syncStreet !== false)
				{
					var streetInput = mfBuyerAddress.getInputByCode('DELIVERY_ADDRESS');
					if (streetInput && streetInput !== false && geo.streetLine && streetInput !== activeEl
						&& !mfBuyerAddress.isPickupDefaultAddress(geo.streetLine))
					{
						var curStreet = String(streetInput.value || '').trim();
						if (curStreet === '' || options.forceStreet)
						{
							streetInput.value = geo.streetLine;
							mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_ADDRESS', geo.streetLine);
						}
					}
				}

				return true;
			} catch(eSyncGeo) {}
			return false;
		};

		mfBuyerAddress.getSavedDeliveryLocationText = function(){
			var prop = mfBuyerAddress.getPropByCode('DELIVERY_LOCATION_TEXT');
			if (!prop)
				return '';
			if (prop.VALUE != null && String(prop.VALUE).trim() !== '')
				return String(prop.VALUE).trim();
			if (prop.VALUE_FORMATTED != null && String(prop.VALUE_FORMATTED).trim() !== '')
				return String(prop.VALUE_FORMATTED).trim();
			return '';
		};

		mfBuyerAddress.getLocationPrefill = function(){
			try {
				var mc = mfEdost.getMfCheckout();
				var nomPre = mc && mc.LAST_ORDER_NOMINATIM_JSON ? String(mc.LAST_ORDER_NOMINATIM_JSON).trim() : '';
				if (nomPre)
				{
					var parsedPre = JSON.parse(nomPre);
					if (parsedPre && parsedPre.display_name)
						return String(parsedPre.display_name).trim();
				}
			} catch(eNomPre) {}
			return mfBuyerAddress.getSavedDeliveryLocationText();
		};

		// Для видимого поля Nominatim — только реальный выбор пользователя / Nominatim JSON, не дефолт Bitrix «Санкт-Петербург».
		mfBuyerAddress.getNominatimInputPrefill = function(){
			try {
				if (ctx.__mfNominatimDisplayLine && String(ctx.__mfNominatimDisplayLine).trim() !== '')
				{
					var dispLine = String(ctx.__mfNominatimDisplayLine).trim();
					if (!mfBuyerAddress.isGenericLocationDefault(dispLine))
						return dispLine;
				}
			} catch(eDisp) {}
			try {
				var form = BX('bx-soa-order-form');
				var jEl = form ? form.querySelector('input[name="MF_NOMINATIM_JSON"]') : null;
				var j = jEl ? String(jEl.value || '').trim() : '';
				if (!j && ctx.__mfNominatimJsonLast)
					j = String(ctx.__mfNominatimJsonLast).trim();
				if (j)
				{
					var parsed = JSON.parse(j);
					if (parsed && parsed.display_name)
						return String(parsed.display_name).trim();
				}
			} catch(eJson) {}
			try {
				var mc = mfEdost.getMfCheckout();
				var nomPre = mc && mc.LAST_ORDER_NOMINATIM_JSON ? String(mc.LAST_ORDER_NOMINATIM_JSON).trim() : '';
				if (nomPre)
				{
					var parsedPre = JSON.parse(nomPre);
					if (parsedPre && parsedPre.display_name)
						return String(parsedPre.display_name).trim();
				}
			} catch(eLast) {}
			return '';
		};

		mfBuyerAddress.isGenericLocationDefault = function(text){
			text = String(text || '').trim();
			if (text === '')
				return true;
			var fbCity = String(mfEdost.PICKUP_LOCATION_TEXT || 'Санкт-Петербург').trim();
			if (text === fbCity || text === 'Санкт-Петербург' || text === 'Москва')
				return true;
			try {
				var bitrixDefault = String(mfBuyerAddress.getLocationText() || '').trim();
				if (bitrixDefault && text === bitrixDefault)
					return true;
			} catch(eBitrix) {}
			return false;
		};

		mfBuyerAddress.syncNominatimInputValue = function(){
			try {
				if (mfBuyerAddress.isCheckoutFieldFocused())
					return;

				var wrapNom = document.getElementById('mf-nominatim-wrap');
				if (!wrapNom)
					return;
				var nomInp = wrapNom.querySelector('input[type="text"]');
				if (!nomInp)
					return;

				var pre = mfBuyerAddress.getNominatimInputPrefill();
				var cur = String(nomInp.value || '').trim();

				// Не перебивать, пока пользователь набирает запрос и ещё не выбрал адрес.
				if ((document.activeElement === nomInp || ctx.__mfNominatimTyping || (wrapNom.contains && wrapNom.contains(document.activeElement)))
					&& !pre && !ctx.__mfNominatimActive)
					return;

				if (pre)
				{
					nomInp.value = pre;
					return;
				}
				if (ctx.__mfNominatimActive && cur)
					return;
				if (!ctx.__mfNominatimActive)
					nomInp.value = '';
			} catch(eNomSync) {}
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
					var name = String(props[i].NAME || '').trim();
					var row = props[i].ID ? ctx.getRowByPropId(props[i].ID) : null;
					if (!row)
						continue;
					if (name === 'Адрес доставки')
					{
						BX.addClass(row, 'mf-checkout-hide-legacy-address');
						BX.hide(row);
						continue;
					}
					if ((code === 'ADDRESS' || code === 'FULL_ADDRESS' || code === 'DELIVERY_ADDR')
						&& parseInt(props[i].ID, 10) !== parseInt(customAddressProp.ID, 10)
						&& parseInt(props[i].ID, 10) !== parseInt(customLocationProp.ID, 10))
					{
						BX.addClass(row, 'mf-checkout-hide-legacy-address');
						BX.hide(row);
						continue;
					}
					if ((code === 'ZIP' || code === 'CITY')
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
					{
						BX.addClass(rowLoc, 'mf-checkout-hide-location');
						mfBuyerAddress.disableFocusOnRow(rowLoc);
					}
				}
			} catch(eHloc) {}
		};

		mfBuyerAddress.disableFocusOnRow = function(row){
			try {
				if (!row || !row.querySelectorAll)
					return;
				var nodes = row.querySelectorAll('input, select, textarea, button, [tabindex]');
				for (var i = 0; i < nodes.length; i++)
				{
					nodes[i].setAttribute('tabindex', '-1');
					nodes[i].setAttribute('aria-hidden', 'true');
				}
			} catch(eDis) {}
		};

		mfBuyerAddress.showBitrixLocationSelector = function(ctx){
			try {
				for (var pidSh in ctx.properties)
				{
					if (!ctx.properties.hasOwnProperty(pidSh))
						continue;
					if (ctx.properties[pidSh].type !== 'LOCATION')
						continue;
					var rowSh = ctx.getRowByPropId(pidSh);
					if (rowSh)
						BX.removeClass(rowSh, 'mf-checkout-hide-location');
				}
			} catch(eShloc) {}
		};

		// Скрываем Bitrix-«Местоположение» только если реально показан блок Photon/Nominatim;
		// иначе пользователь не видит ни селектора, ни ошибки валидации (класс .mf-checkout-hide-location переживает locationsCompletion()).
		mfBuyerAddress.syncBitrixLocationVisibility = function(ctx){
			try {
				if (document.getElementById('mf-nominatim-wrap'))
					mfBuyerAddress.hideBitrixLocationSelector(ctx);
				else
					mfBuyerAddress.showBitrixLocationSelector(ctx);
			} catch(eSynLoc) {}
		};

		mfBuyerAddress.sync = function(){
			try {
				mfBuyerAddress.bindAddressFieldGuards();

				if (typeof mfBuyerAddress.isUserEditingAddress === 'function' && mfBuyerAddress.isUserEditingAddress())
				{
					try { mfBuyerAddress.hideLegacyRows(); } catch(eHideLegacy) {}
					try { mfBuyerAddress.syncBitrixLocationVisibility(ctx); } catch(eVisEdit) {}
					return;
				}
				if (mfBuyerAddress.isCheckoutFieldFocused())
					return;

				var mfEsync = BX.saleOrderAjax && BX.saleOrderAjax.__mfEdost;
				var isDeliveryMode = mfEsync && typeof mfEsync.isPickupMode === 'function' && !mfEsync.isPickupMode();
				var activeElSync = null;
				try { activeElSync = document.activeElement; } catch(eActSync) {}
				if (isDeliveryMode)
				{
					var streetProbe = mfBuyerAddress.getInputByCode('DELIVERY_ADDRESS');
					var streetEmpty = !streetProbe || streetProbe === false || String(streetProbe.value || '').trim() === '';
					mfBuyerAddress.syncDeliveryGeoToBuyerFields({
						syncStreet: streetEmpty,
						forceStreet: streetEmpty
					});
				}

				var locationInput = mfBuyerAddress.getInputByCode('DELIVERY_LOCATION_TEXT');
				var streetInput = mfBuyerAddress.getInputByCode('DELIVERY_ADDRESS');
				var zipInput = mfBuyerAddress.getInputByCode('DELIVERY_ZIP');
				var locationText = mfBuyerAddress.getLocationPrefill();

				if (locationInput && locationInput !== false)
				{
					var isDeliveryModeLoc = isDeliveryMode;
					var hasGeoLoc = isDeliveryModeLoc && String(locationInput.value || '').trim() !== ''
						&& !mfBuyerAddress.isGenericLocationDefault(String(locationInput.value || '').trim());
					if (!ctx.__mfNominatimActive && !hasGeoLoc && locationInput !== activeElSync)
					{
						if (locationText)
						{
							locationInput.value = locationText;
							mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_LOCATION_TEXT', locationText);
						}
						else
						{
							var bitrixDefault = String(mfBuyerAddress.getLocationText() || '').trim();
							if (bitrixDefault && String(locationInput.value || '').trim() === bitrixDefault)
							{
								locationInput.value = '';
								mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_LOCATION_TEXT', '');
							}
							else if (isDeliveryModeLoc && mfBuyerAddress.isPickupDefaultAddress(String(locationInput.value || '')))
							{
								locationInput.value = '';
								mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_LOCATION_TEXT', '');
							}
						}
						try {
							locationInput.removeAttribute('readonly');
							locationInput.readOnly = false;
							locationInput.style.backgroundColor = '';
						} catch(eRo2) {}
					}
				}

				if (streetInput && streetInput !== false)
				{
					var isDeliveryModeStreet = isDeliveryMode;
					var streetVal = mfBuyerAddress.getPropValueByCode('DELIVERY_ADDRESS');
					if (isDeliveryMode && mfBuyerAddress.isPickupDefaultAddress(streetVal))
						streetVal = '';
					if (streetVal !== '' && streetInput !== activeElSync)
					{
						streetInput.value = streetVal;
						mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_ADDRESS', streetVal);
					}
					else if (isDeliveryMode && mfBuyerAddress.isPickupDefaultAddress(String(streetInput.value || ''))
						&& streetInput !== activeElSync)
					{
						streetInput.value = '';
						mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_ADDRESS', '');
					}
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

					if (isDeliveryMode)
					{
						var geoZip = mfBuyerAddress.getDeliveryGeoFromSelection();
						if (geoZip && geoZip.zip && zipInput !== activeElSync
							&& (!ctx.__mfBuyerZipChangedManually || String(zipInput.value || '').trim() === ''))
						{
							zipInput.value = geoZip.zip;
							mfBuyerAddress.syncOrderPropInputsByCode('DELIVERY_ZIP', geoZip.zip);
						}
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
				mfBuyerAddress.syncLegacyDeliveryAddressProperty();
				mfBuyerAddress.syncBitrixLocationVisibility(ctx);

				try {
					mfBuyerAddress.syncNominatimInputValue();
				} catch(eNomPre) {}
			} catch(e) {}
		};

		mfBuyerAddress.findDeliveryLocationAnchor = function(ctx){
			var findLocCol = function(root){
				if (!root || !root.querySelector)
					return null;

				var dcont = root.querySelector('.bx-soa-section-content');
				var locCol = dcont ? dcont.querySelector('.bx_soa_location .col') : null;

				return (BX.type.isElementNode(locCol) && locCol.parentNode) ? locCol : null;
			};

			var delActive = BX('bx-soa-delivery');
			var delHidden = BX('bx-soa-delivery-hidden');
			var anchorRow = null;

			if (delActive && delActive.classList.contains('bx-selected'))
				anchorRow = findLocCol(delActive);
			if (!anchorRow)
				anchorRow = findLocCol(delHidden);
			if (!anchorRow)
				anchorRow = findLocCol(delActive);

			if (!anchorRow)
			{
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
			}

			if (!anchorRow)
			{
				var locPropFallback = mfBuyerAddress.getPropByCode('DELIVERY_LOCATION_TEXT');
				if (locPropFallback && locPropFallback.ID)
					anchorRow = ctx.getRowByPropId(locPropFallback.ID);
			}

			return (anchorRow && anchorRow.parentNode) ? anchorRow : null;
		};

		mfBuyerAddress.dedupeNominatimWrap = function(){
			try {
				var wraps = document.querySelectorAll('#mf-nominatim-wrap');
				for (var wi = 1; wi < wraps.length; wi++)
					BX.remove(wraps[wi]);
			} catch(eDedupe) {}
		};

		mfBuyerAddress.installNominatim = function(ctx){
			mfBuyerAddress.dedupeNominatimWrap();
			if (document.getElementById('mf-nominatim-wrap'))
			{
				try { mfEdost.mountDeliveryUi(); } catch(eMountExist) {}
				try { mfBuyerAddress.syncNominatimInputValue(); } catch(eNomExist) {}
				return;
			}

			if (ctx.__mfNominatimInstalling)
				return;
			ctx.__mfNominatimInstalling = true;

			var searchUrl = String(mfEdost.getMfCheckout().NOMINATIM_SEARCH_URL || '');
			if (!searchUrl)
			{
				ctx.__mfNominatimInstalling = false;
				return;
			}

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

			// Вставляем в панель «Доставка» перед списком тарифов eDost.
			var panel = mfEdost.ensureDeliveryPanel && mfEdost.ensureDeliveryPanel();
			var insertBeforeEl = null;
			if (panel)
				insertBeforeEl = panel.querySelector('#mf-edost-box') || panel.querySelector('#mf-edost-list');

			var anchorRow = mfBuyerAddress.findDeliveryLocationAnchor(ctx);
			if (!insertBeforeEl && !anchorRow)
			{
				ctx.__mfNominatimInstalling = false;
				var retries = ctx.__mfNomAnchorRetries = (ctx.__mfNomAnchorRetries || 0) + 1;
				if (retries <= 12 && !document.getElementById('mf-nominatim-wrap'))
				{
					var retryGen = ctx.__mfNomInstallGen = (ctx.__mfNomInstallGen || 0) + 1;
					setTimeout(function(){
						if (retryGen !== (ctx.__mfNomInstallGen || 0))
							return;
						try { mfBuyerAddress.installNominatim(ctx); } catch (eR) {}
					}, retries * 200);
				}
				return;
			}
			ctx.__mfNomAnchorRetries = 0;

			var wrap = BX.create('DIV', {attrs: {id: 'mf-nominatim-wrap', className: 'mf-nominatim-wrap'}});
			var inp = BX.create('INPUT', {
				props: {type: 'text', autocomplete: 'street-address', placeholder: 'Введите адрес', className: 'form-control'},
				attrs: {class: 'form-control mf-nominatim-wrap__input', inputmode: 'search'}
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
					zIndex: '50',
					boxShadow: '0 8px 24px rgba(0,0,0,.12)'
				}
			});
			wrap.appendChild(inp);
			wrap.appendChild(errBox);
			wrap.appendChild(list);
			if (panel && insertBeforeEl)
				panel.insertBefore(wrap, insertBeforeEl);
			else if (anchorRow && anchorRow.parentNode)
				anchorRow.parentNode.insertBefore(wrap, anchorRow);
			else
			{
				ctx.__mfNominatimInstalling = false;
				return;
			}

			ctx.__mfNominatimInstalling = false;
			try { mfEdost.mountDeliveryUi(); } catch(eMountNom) {}
			try { mfBuyerAddress.syncNominatimInputValue(); } catch(eNomInit) {}
			try {
				mfBuyerAddress.hideBitrixLocationSelector(ctx);
			} catch(eHAfterNom) {}

			try {
				if (!document.getElementById('mf-nominatim-overflow-fix'))
				{
					var st = document.createElement('style');
					st.id = 'mf-nominatim-overflow-fix';
					st.type = 'text/css';
					st.appendChild(document.createTextNode(
						'#bx-soa-properties .bx-soa-section-content{overflow:visible !important;}' +
						'#bx-soa-region .bx-soa-section-content{overflow:visible !important;}' +
						'#bx-soa-delivery .bx-soa-section-content{overflow:visible !important;}' +
						'#mf-nominatim-wrap{overflow:visible !important;position:relative;z-index:10;}' +
						'#mf-nominatim-list{z-index:50;}'
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
										ctx.__mfNominatimDisplayLine = String(it.display_name || cityRu || '');
									} catch(eDisp) {}
									try {
										inp.value = String(it.display_name || cityRu || '');
									} catch(eInp) {}
									try {
										ctx.__mfDeliveryLocationLine = mfBuyerAddress.formatLocationLineFromAddr(addr, it.display_name);
										if (addr.postcode)
											ctx.__mfDeliveryZipLast = String(addr.postcode).replace(/\D+/g, '');
									} catch(eStoreGeo) {}
									try {
										mfBuyerAddress.syncDeliveryGeoToBuyerFields({forceZip: true, forceStreet: true});
									} catch(eSyncGeoPick) {}
									var zipDigits = String(ctx.__mfDeliveryZipLast || '').replace(/\D+/g, '');

									var fb = mfEdost.getFallbackLocationCode();
									if (BX.type.isNotEmptyString(fb))
										mfEdost.applyBitrixOrderLocationCode(fb, {sendRefresh: false});

									var runFetch = function(){
										if (mfEdost.isPickupMode())
											return;
										var locForFetch = '';
										try {
											locForFetch = String(mfEdost.getCurrentLocationCode() || '');
										} catch(eG) {}
										if (mfEdost.hasUserDeliveryDestination())
										{
											mfEdost.fetchOffers(locForFetch, zipDigits, {allowInactive: true});
										}
										try {
											mfEdost.updateGate(mfEdost.getEffectiveLocationCode());
										} catch(eUg) {}
									};
									setTimeout(runFetch, 50);
									setTimeout(function(){
										try {
											if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.syncNominatimInputValue === 'function')
												ctx.__mfBuyerAddress.syncNominatimInputValue();
										} catch(eNomAfterPick) {}
									}, 300);
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
				ctx.__mfNominatimTyping = true;
				var q = inp.value;
				if (timer)
					clearTimeout(timer);
				timer = setTimeout(function(){ runSearch(q); }, 400);
			});
			BX.bind(inp, 'focus', function(){
				ctx.__mfNominatimTyping = true;
				try {
					inp.removeAttribute('readonly');
					inp.readOnly = false;
				} catch(eF) {}
			});
			BX.bind(inp, 'blur', function(){
				setTimeout(function(){
					if (document.activeElement !== inp
						&& !(typeof mfBuyerAddress.isUserEditingAddress === 'function' && mfBuyerAddress.isUserEditingAddress()))
						ctx.__mfNominatimTyping = false;
				}, 250);
			});
			var closeNominatimList = function(ev){
				try {
					if (!wrap || !list)
						return;
					if (ev && ev.target && wrap.contains(ev.target))
						return;
					if (document.activeElement && wrap.contains(document.activeElement))
						return;
					list.style.display = 'none';
				} catch(e) {}
			};
			BX.bind(document, 'mousedown', closeNominatimList);
			BX.bind(document, 'touchstart', closeNominatimList);

			try {
				if (!ctx.__mfNominatimActive && inp)
					mfBuyerAddress.syncNominatimInputValue();
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
									if (ctx.__mfSuppressLocationRefresh) return;
									if (
										ctx.__mfNominatimActive
										|| (ctx.__mfNominatimJsonLast && mfEdost.getDeliveryMode() === mfEdost.DELIVERY_MODE_DELIVERY)
									)
										return;
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
										if (!(typeof mfBuyerAddress.isUserEditingAddress === 'function'
											&& mfBuyerAddress.isUserEditingAddress()))
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
			if (!mfBuyerAddress.isCheckoutFieldFocused())
				mfBuyerAddress.sync();
		} catch(eBuyer) {}

		try {
			mfBuyerAddress.installNominatim(ctx);
			mfBuyerAddress.dedupeNominatimWrap();
		} catch(eNom) {}

		try {
			mfBuyerAddress.installConfirmChannelHints(ctx);
		} catch(eCc) {}

		try {
			mfEdost.restoreLastOrderPreload();
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
			var zipInit = '';
			try {
				if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.getInputByCode === 'function')
				{
					var zInp0 = ctx.__mfBuyerAddress.getInputByCode('DELIVERY_ZIP');
					if (zInp0 && zInp0 !== false)
						zipInit = String(zInp0.value || '').replace(/\D+/g, '');
				}
			} catch(eZip0) {}

			if (!mfEdost.isPickupMode())
			{
				if (mfEdost.hasUserDeliveryDestination())
				{
					mfEdost.fetchOffers(locInit, zipInit, {allowInactive: true});
				}
				else if (mfEdost.isDeliveryActive && mfEdost.isDeliveryActive())
				{
					mfEdost.renderOffers([]);
				}
			}
			else
			{
				mfEdost.syncPickupTariffFields();
			}

			if (!mfEdost.hasUserDeliveryDestination())
			{
				try { mfEdost.clearManagerDeliveryFallback(); } catch(eMf0) {}
				var mcSkip = mfEdost.getMfCheckout();
				if (!mfEdost.isPickupMode() && !(mcSkip && mcSkip.LAST_ORDER_EDOST && mcSkip.LAST_ORDER_EDOST.ID))
				{
					mfEdost.clearSelection();
				}
			}
			mfEdost.updateGate(locInit);
			mfEdost.syncPickupAddressVisibility();
			mfEdost.applyDeliverySummary();
			mfEdost.applyTotalDeliveryLine();
			try { mfEdost.installSummaryObserver(); } catch(eObserver) {}
			// force: иначе при гонке с bx-selected onEnterDelivery может выйти до ensureFields/fetch.
			try { mfEdost.onEnterDelivery(true); } catch(e) {}
		} catch(e) {}

		//set location initialized flag and refresh region & property actual content
		if (BX.Sale.OrderAjaxComponent)
			BX.Sale.OrderAjaxComponent.locationsCompletion();

		try {
			if (ctx.__mfBuyerAddress && typeof ctx.__mfBuyerAddress.syncBitrixLocationVisibility === 'function')
				ctx.__mfBuyerAddress.syncBitrixLocationVisibility(ctx);
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
			var input = row.querySelector('textarea');
			if(BX.type.isElementNode(input)){
				this.properties[propId].input = input;
				return input;
			}
			input = row.querySelector('input[type="text"], input[type="tel"], input[type="email"], input[type="number"]');
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
		if (this.__mfSuppressLocationRefresh)
			return;

		var propId = false;
		for(var k in this.properties){
			if(typeof this.properties[k].control != 'undefined' && this.properties[k].control == control){
				propId = k;
				break;
			}
		}

		try {
			if (propId !== false && this.properties[propId] && this.properties[propId].type === 'LOCATION'
				&& this.__mfBuyerAddress && typeof this.__mfBuyerAddress.isUserEditingAddress === 'function'
				&& this.__mfBuyerAddress.isUserEditingAddress())
				return;
		} catch(eLocEdit) {}

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