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