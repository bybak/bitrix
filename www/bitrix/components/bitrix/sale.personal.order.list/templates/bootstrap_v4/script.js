BX.namespace('BX.Sale.PersonalOrderComponent');

(function() {
	function mfResolveAjaxReloadLink(target)
	{
		if (!target)
		{
			return null;
		}
		if (target.nodeType === 1 && target.tagName === 'A' && BX.hasClass(target, 'ajax_reload'))
		{
			return target;
		}
		return BX.findParent(target, { tag: 'A', class: 'ajax_reload' });
	}

	function mfOpenPaymentInstructionsModal(html)
	{
		var overlay = BX.create('DIV', {
			attrs: {
				className: 'mf-order-pay-modal-overlay',
				role: 'dialog',
				'aria-modal': 'true'
			},
			style: {
				position: 'fixed',
				top: '0',
				left: '0',
				right: '0',
				bottom: '0',
				zIndex: '12000',
				background: 'rgba(17, 24, 39, 0.55)',
				display: 'flex',
				alignItems: 'center',
				justifyContent: 'center',
				padding: '16px',
				boxSizing: 'border-box'
			},
			events: {
				click: function(ev) {
					if (ev.target === overlay)
					{
						close();
					}
				}
			}
		});

		var panel = BX.create('DIV', {
			style: {
				background: '#fff',
				borderRadius: '16px',
				maxWidth: '640px',
				width: '100%',
				maxHeight: '85vh',
				overflow: 'auto',
				position: 'relative',
				boxShadow: '0 24px 60px rgba(12, 18, 40, 0.18)'
			},
			events: {
				click: function(ev) {
					ev.stopPropagation();
				}
			}
		});

		var closeBtn = BX.create('BUTTON', {
			props: { type: 'button' },
			attrs: { 'aria-label': 'Закрыть' },
			text: '\u00D7',
			style: {
				border: 'none',
				background: 'transparent',
				fontSize: '28px',
				lineHeight: '1',
				cursor: 'pointer',
				padding: '0 4px',
				color: 'rgba(17,24,39,.55)'
			}
		});

		var header = BX.create('DIV', {
			style: {
				display: 'flex',
				alignItems: 'center',
				justifyContent: 'space-between',
				gap: '12px',
				padding: '16px 18px',
				borderBottom: '1px solid rgba(17,24,39,.10)'
			},
			children: [
				BX.create('DIV', { text: 'Оплата', style: { fontWeight: '900', fontSize: '1.05rem', color: 'rgba(17,24,39,.92)' } }),
				closeBtn
			]
		});

		var body = BX.create('DIV', {
			style: { padding: '18px', color: 'rgba(17,24,39,.92)' }
		});
		body.innerHTML = html;

		var close = function()
		{
			BX.unbind(document, 'keydown', onKey);
			if (overlay && overlay.parentNode)
			{
				BX.remove(overlay);
			}
		};

		var onKey = function(e) {
			if (e.keyCode === 27)
			{
				close();
			}
		};

		BX.bind(closeBtn, 'click', close);
		BX.bind(document, 'keydown', onKey);

		panel.appendChild(header);
		panel.appendChild(body);
		overlay.appendChild(panel);
		document.body.appendChild(overlay);
	}

	BX.Sale.PersonalOrderComponent.PersonalOrderList = {
		init : function(params)
		{
			var rowWrapper = document.getElementsByClassName('sale-order-list-inner-row');

			params.paymentList = params.paymentList || {};
			params.url = params.url || "";
			params.templateName = params.templateName || "";
			params.returnUrl = params.returnUrl || "";

			Array.prototype.forEach.call(rowWrapper, function(wrapper)
			{
				var shipmentTrackingId = wrapper.getElementsByClassName('sale-order-list-shipment-id');
				if (shipmentTrackingId[0])
				{
					Array.prototype.forEach.call(shipmentTrackingId, function(blockId)
					{
						var clipboard = blockId.parentNode.getElementsByClassName('sale-order-list-shipment-id-icon')[0];
						if (clipboard)
						{
							BX.clipboard.bindCopyClick(clipboard, {text : blockId.innerHTML});
						}
					});
				}

				BX.bindDelegate(wrapper, 'click', { 'class': 'ajax_reload' }, BX.proxy(function(event)
				{
					var payLink = mfResolveAjaxReloadLink(event.target);
					if (!payLink || !payLink.href)
					{
						return;
					}

					var block = wrapper.getElementsByClassName('sale-order-list-inner-row-body')[0];
					var template = wrapper.getElementsByClassName('sale-order-list-inner-row-template')[0];
					var cancelPaymentLink = template ? template.getElementsByClassName('sale-order-list-cancel-payment')[0] : null;

					var useModal = BX.hasClass(payLink, 'mf-order-pay-instructions-modal');

					BX.ajax(
						{
							method: 'POST',
							dataType: 'html',
							url: payLink.href,
							data:
							{
								sessid: BX.bitrix_sessid(),
								RETURN_URL: params.returnUrl
							},
							onsuccess: BX.proxy(function(result)
							{
								if (useModal)
								{
									mfOpenPaymentInstructionsModal(result);
									return;
								}

								var resultDiv = document.createElement('div');
								resultDiv.innerHTML = result;
								template.insertBefore(resultDiv, cancelPaymentLink);
								block.style.display = 'none';
								template.style.display = 'block';

								BX.bind(cancelPaymentLink, 'click', function()
								{
									block.style.display = 'block';
									template.style.display = 'none';
									resultDiv.remove();
								},this);

							},this),
							onfailure: BX.proxy(function()
							{
								return this;
							}, this)
						}, this
					);
					event.preventDefault();
				}, this));

				var isChangingLoaded = false;
				BX.bindDelegate(wrapper, 'click', { 'class': 'sale-order-list-change-payment' }, BX.proxy(function(event)
				{
					if (isChangingLoaded)
						return;
					isChangingLoaded = true;
					event.preventDefault();

					var block = wrapper.getElementsByClassName('sale-order-list-inner-row-body')[0];
					var template = wrapper.getElementsByClassName('sale-order-list-inner-row-template')[0];
					var cancelPaymentLink = template.getElementsByClassName('sale-order-list-cancel-payment')[0];

					BX.ajax(
						{
							method: 'POST',
							dataType: 'html',
							url: params.url,
							data:
							{
								sessid: BX.bitrix_sessid(),
								orderData: params.paymentList[event.target.id],
								templateName : params.templateName
							},
							onsuccess: BX.proxy(function(result)
							{
								var resultDiv = BX.create("div",{
									props: {className: "row"},
									children: [result]
								});

								template.insertBefore(resultDiv, cancelPaymentLink);
								event.target.style.display = 'none';
								block.parentNode.removeChild(block);
								template.style.display = 'block';
								BX.bind(cancelPaymentLink, 'click', function()
								{
									window.location.reload();
								},this);

							},this),
							onfailure: BX.proxy(function()
							{
								isChangingLoaded = false;
								return this;
							}, this)
						}, this
					);

				}, this));
			});
		}
	};
})();
