<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)
{
	die();
}

/** @var CBitrixPersonalOrderListComponent $component */
/** @var array $arParams */
/** @var array $arResult */

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;

Asset::getInstance()->addJs("/bitrix/components/bitrix/sale.order.payment.change/templates/bootstrap_v4/script.js");
Asset::getInstance()->addCss("/bitrix/components/bitrix/sale.order.payment.change/templates/bootstrap_v4/style.css");
CJSCore::Init(array('clipboard', 'fx'));

Loc::loadMessages(__FILE__);

$mfFormatMoney = static function($value, $currency = 'RUB'): string
{
	$value = (float)$value;
	if (function_exists('SaleFormatCurrency'))
	{
		return (string)SaleFormatCurrency($value, (string)$currency);
	}

	return number_format($value, 2, '.', ' ') . ' ' . $currency;
};

/** Убирает HTML/сущности из строк валюты Bitrix (&#8381;, &nbsp;), чтобы не дублировать экранирование в шаблоне. */
$mfSanitizeMoneyDisplay = static function(string $s): string
{
	$s = trim($s);
	if ($s === '')
	{
		return '';
	}
	$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$s = strip_tags($s);
	$s = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $s);

	return trim(preg_replace('/\s+/u', ' ', $s));
};

$mfExtractBasketArticle = static function(array $item): string
{
	foreach (['ARTICLE', 'ARTNUMBER'] as $key)
	{
		$value = trim((string)($item[$key] ?? ''));
		if ($value !== '')
		{
			return $value;
		}
	}

	$props = $item['PROPS'] ?? [];
	if (is_array($props))
	{
		foreach ($props as $prop)
		{
			$code = trim((string)($prop['CODE'] ?? ''));
			$name = trim((string)($prop['NAME'] ?? ''));
			$value = trim((string)($prop['VALUE'] ?? ''));
			if ($value === '')
			{
				continue;
			}
			if ($code === 'CML2_ARTICLE' || $code === 'ARTICLE' || mb_stripos($name, 'артикул') !== false)
			{
				return $value;
			}
		}
	}

	$productId = (int)($item['PRODUCT_ID'] ?? 0);
	if ($productId > 0 && function_exists('mf_catalog_brand_article_by_product_id'))
	{
		$m = mf_catalog_brand_article_by_product_id($productId);
		$a = trim((string)($m['article'] ?? ''));
		if ($a !== '')
		{
			return $a;
		}
	}

	return '';
};

$mfBasketPriceText = static function(array $item, array $order) use ($mfFormatMoney, $mfSanitizeMoneyDisplay): string
{
	$currency = (string)($order['ORDER']['CURRENCY'] ?? 'RUB');
	if (isset($item['PRICE']) && is_numeric($item['PRICE']))
	{
		return $mfSanitizeMoneyDisplay($mfFormatMoney((float)$item['PRICE'], $currency));
	}

	foreach (['PRICE_FORMATED', 'FORMATED_PRICE', 'PRICE_FORMATTED'] as $key)
	{
		$value = trim((string)($item[$key] ?? ''));
		if ($value !== '')
		{
			return $mfSanitizeMoneyDisplay($value);
		}
	}

	return '';
};

$mfBasketSumText = static function(array $item, array $order) use ($mfFormatMoney, $mfSanitizeMoneyDisplay): string
{
	$currency = (string)($order['ORDER']['CURRENCY'] ?? 'RUB');
	$price = isset($item['PRICE']) && is_numeric($item['PRICE']) ? (float)$item['PRICE'] : 0.0;
	$quantity = isset($item['QUANTITY']) && is_numeric($item['QUANTITY']) ? (float)$item['QUANTITY'] : 0.0;
	if ($price > 0 && $quantity > 0)
	{
		return $mfSanitizeMoneyDisplay($mfFormatMoney($price * $quantity, $currency));
	}

	foreach (['SUM_FORMATED', 'FORMATED_SUM', 'SUM_FORMATTED'] as $key)
	{
		$value = trim((string)($item[$key] ?? ''));
		if ($value !== '')
		{
			return $mfSanitizeMoneyDisplay($value);
		}
	}

	return '';
};

$mfBasketPropByCodes = static function(array $item, array $codes): string
{
	$props = $item['PROPS'] ?? [];
	if (!is_array($props))
	{
		return '';
	}
	foreach ($codes as $code)
	{
		$code = mb_strtoupper(trim((string)$code));
		if ($code === '')
		{
			continue;
		}
		foreach ($props as $prop)
		{
			$pCode = mb_strtoupper(trim((string)($prop['CODE'] ?? '')));
			if ($pCode !== $code)
			{
				continue;
			}
			$v = trim((string)($prop['VALUE'] ?? ''));
			if ($v !== '')
			{
				return $v;
			}
		}
	}

	return '';
};

$mfBasketManufacturer = static function(array $item) use ($mfBasketPropByCodes): string
{
	$v = $mfBasketPropByCodes($item, ['MF_BRAND', 'MF_BRAND_NORM', 'CML2_MANUFACTURER', 'BRAND', 'MANUFACTURER']);
	if ($v !== '')
	{
		return $v;
	}
	$productId = (int)($item['PRODUCT_ID'] ?? 0);
	if ($productId > 0 && function_exists('mf_catalog_brand_article_by_product_id'))
	{
		$m = mf_catalog_brand_article_by_product_id($productId);
		$b = trim((string)($m['brand'] ?? ''));
		if ($b !== '')
		{
			return $b;
		}
	}

	return '—';
};

$mfBasketStoreTitle = static function(array $item) use ($mfBasketPropByCodes): string
{
	$v = $mfBasketPropByCodes($item, ['MF_STORE_TITLE']);
	return $v !== '' ? $v : '—';
};

$mfBasketStoreTerm = static function(array $item) use ($mfBasketPropByCodes): string
{
	$raw = $mfBasketPropByCodes($item, ['MF_STORE_ID']);
	$storeId = (int)trim((string)$raw);
	if ($storeId <= 0)
	{
		return '—';
	}
	if (function_exists('mf_store_delivery_term'))
	{
		return mf_store_delivery_term($storeId);
	}

	return '—';
};

$mfBasketStoreId = static function(array $item) use ($mfBasketPropByCodes): int
{
	$raw = $mfBasketPropByCodes($item, ['MF_STORE_ID']);

	return (int)trim((string)$raw);
};

$mfBasketProductName = static function(array $item): string
{
	if (function_exists('mf_basket_item_display_name'))
	{
		return mf_basket_item_display_name($item);
	}

	return trim(html_entity_decode(strip_tags((string)($item['NAME~'] ?? $item['NAME'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
};

/** Полная строка выбранной eDost-доставки из комментария к заказу (до tarif_id). */
$mfParseEdostFromComments = static function(string $comments): ?array
{
	if ($comments === '')
	{
		return null;
	}
	if (!preg_match('/Доставка\s*\(eDost[^:]*:\s*(.+?)\s*\(tarif_id=([^)]+)\)/us', $comments, $matches))
	{
		return null;
	}
	$inner = trim((string)($matches[1] ?? ''));
	$tarifId = trim((string)($matches[2] ?? ''));
	if ($inner === '')
	{
		return null;
	}
	$display = str_replace(' — ', ' - ', $inner);

	return [
		'full' => $display,
		'tarif_id' => $tarifId,
	];
};

if (!empty($arResult['ERRORS']['FATAL']))
{
	foreach($arResult['ERRORS']['FATAL'] as $code => $error)
	{
		if ($code !== $component::E_NOT_AUTHORIZED)
			ShowError($error);
	}
	$component = $this->__component;
	if ($arParams['AUTH_FORM_IN_TEMPLATE'] && isset($arResult['ERRORS']['FATAL'][$component::E_NOT_AUTHORIZED]))
	{
		?>
		<div class="mf-personal-auth-gate">
			<div class="alert alert-info mf-auth-alert"><?=$arResult['ERRORS']['FATAL'][$component::E_NOT_AUTHORIZED]?></div>
			<?$APPLICATION->AuthForm('', false, false, 'N', false);?>
		</div>
		<?
	}

}
else
{
	$filterHistory = ($_REQUEST['filter_history'] ?? '');
	$filterShowCanceled = ($_REQUEST["show_canceled"] ?? '');

	if (!empty($arResult['ERRORS']['NONFATAL']))
	{
		foreach($arResult['ERRORS']['NONFATAL'] as $error)
		{
			ShowError($error);
		}
	}
	$mfStatusLabels = is_array($arResult['MF_STATUS_LABELS'] ?? null)
		? $arResult['MF_STATUS_LABELS']
		: (function_exists('mf_order_custom_status_labels') ? mf_order_custom_status_labels() : []);
	$mfStatusFilters = is_array($arResult['MF_STATUS_FILTERS'] ?? null)
		? $arResult['MF_STATUS_FILTERS']
		: (function_exists('mf_order_custom_status_active_filters') ? mf_order_custom_status_active_filters() : ['order' => '', 'payment' => '', 'shipment' => '']);
	$mfFilterClearKeys = ['mf_order_status', 'mf_payment_status', 'mf_shipment_status', 'filter_history', 'filter_status', 'show_all', 'show_canceled', 'del_filter'];
	$mfFilterResetUrl = $APPLICATION->GetCurPageParam('show_all=Y', $mfFilterClearKeys, false);
	?>
	<form class="mf-order-filters mb-4" method="get" action="<?=htmlspecialcharsbx($APPLICATION->GetCurPage())?>">
		<input type="hidden" name="show_all" value="Y">
		<div class="mf-order-filters-grid">
			<div class="mf-order-filters-field">
				<label class="mf-order-filters-label" for="mf_order_status">Статус заказа</label>
				<select class="mf-order-filters-select custom-select" id="mf_order_status" name="mf_order_status">
					<option value="">Все</option>
					<?php foreach (($mfStatusLabels['order'] ?? []) as $code => $label): ?>
						<option value="<?=htmlspecialcharsbx($code)?>"<?=($mfStatusFilters['order'] === $code ? ' selected' : '')?>><?=htmlspecialcharsbx($label)?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mf-order-filters-field">
				<label class="mf-order-filters-label" for="mf_payment_status">Статус оплаты</label>
				<select class="mf-order-filters-select custom-select" id="mf_payment_status" name="mf_payment_status">
					<option value="">Все</option>
					<?php foreach (($mfStatusLabels['payment'] ?? []) as $code => $label): ?>
						<option value="<?=htmlspecialcharsbx($code)?>"<?=($mfStatusFilters['payment'] === $code ? ' selected' : '')?>><?=htmlspecialcharsbx($label)?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mf-order-filters-field">
				<label class="mf-order-filters-label" for="mf_shipment_status">Статус доставки</label>
				<select class="mf-order-filters-select custom-select" id="mf_shipment_status" name="mf_shipment_status">
					<option value="">Все</option>
					<?php foreach (($mfStatusLabels['shipment'] ?? []) as $code => $label): ?>
						<option value="<?=htmlspecialcharsbx($code)?>"<?=($mfStatusFilters['shipment'] === $code ? ' selected' : '')?>><?=htmlspecialcharsbx($label)?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mf-order-filters-actions">
				<button type="submit" class="btn btn-primary mf-order-filters-submit">Применить</button>
				<?php if (!empty($arResult['MF_STATUS_FILTERS_ACTIVE'])): ?>
					<a class="mf-order-filters-reset" href="<?=htmlspecialcharsbx($mfFilterResetUrl)?>">Сбросить</a>
				<?php endif; ?>
			</div>
		</div>
	</form>
	<?
	if (empty($arResult['ORDERS']))
	{
		if (!empty($arResult['MF_STATUS_FILTERS_ACTIVE']))
		{
			?>
			<h3>Заказы с выбранными статусами не найдены</h3>
			<?
		}
		elseif ($filterHistory === 'Y')
		{
			if ($filterShowCanceled === 'Y')
			{
				?>
				<h3><?= Loc::getMessage('SPOL_TPL_EMPTY_CANCELED_ORDER')?></h3>
				<?
			}
			else
			{
				?>
				<h3><?= Loc::getMessage('SPOL_TPL_EMPTY_HISTORY_ORDER_LIST')?></h3>
				<?
			}
		}
		else
		{
			?>
			<h3><?= Loc::getMessage('SPOL_TPL_EMPTY_ORDER_LIST')?></h3>
			<?
		}
	}
	if ($filterHistory !== 'Y')
	{
		$paymentChangeData = array();

		foreach ($arResult['ORDERS'] as $key => $order)
		{
			$basketCount = count($order['BASKET_ITEMS']);
			$basketLabel = Loc::getMessage('SPOL_TPL_GOODS');
			$count = $basketCount % 10;
			if ($count == '1')
			{
				$basketLabel = Loc::getMessage('SPOL_TPL_GOOD');
			}
			elseif ($count >= '2' && $count <= '4')
			{
				$basketLabel = Loc::getMessage('SPOL_TPL_TWO_GOODS');
			}

			$mfCustomStatus = is_array($order['MF_CUSTOM_STATUS'] ?? null) ? $order['MF_CUSTOM_STATUS'] : null;
			$mfOrderStatusDisplay = function_exists('mf_order_custom_status_display_for_list')
				? mf_order_custom_status_display_for_list($mfCustomStatus, 'order')
				: ['text' => '—', 'badge_class' => 'mf-order-badge_status', 'has_status' => false];
			$mfPaymentStatusDisplay = function_exists('mf_order_custom_status_display_payment_for_list')
				? mf_order_custom_status_display_payment_for_list($mfCustomStatus, $order['PAYMENT'] ?? [])
				: (function_exists('mf_order_custom_status_display_for_list')
					? mf_order_custom_status_display_for_list($mfCustomStatus, 'payment')
					: ['text' => '—', 'badge_class' => 'mf-order-badge mf-order-badge_status', 'has_status' => false]);
			$mfShipmentStatusDisplay = function_exists('mf_order_custom_status_display_for_list')
				? mf_order_custom_status_display_for_list($mfCustomStatus, 'shipment')
				: ['text' => '—', 'badge_class' => 'mf-order-badge mf-order-badge_status', 'has_status' => false];

			$orderStatusName = htmlspecialcharsbx((string)$mfOrderStatusDisplay['text']);
			$orderStatusBadgeClass = (string)$mfOrderStatusDisplay['badge_class'];
			$paymentStatusText = (string)$mfPaymentStatusDisplay['text'];
			$paymentStatusClass = (string)$mfPaymentStatusDisplay['badge_class'];
			$deliveryStatusText = (string)$mfShipmentStatusDisplay['text'];
			$deliveryStatusClass = (string)$mfShipmentStatusDisplay['badge_class'];
			$mfIsCancelled = function_exists('mf_order_custom_status_is_cancelled')
				&& mf_order_custom_status_is_cancelled($mfCustomStatus);
			$mfCancelReasonText = $mfIsCancelled
				? trim((string)($mfCustomStatus['CANCEL_REASON'] ?? ''))
				: '';

			$primaryPayment = null;
			foreach ($order['PAYMENT'] as $paymentRow)
			{
				$primaryPayment = $paymentRow;
				break;
			}
			$paymentMethodName = trim((string)($primaryPayment['PAY_SYSTEM_NAME'] ?? 'Не указан'));

			$primaryShipment = null;
			foreach ($order['SHIPMENT'] as $shipmentRow)
			{
				if (!empty($shipmentRow))
				{
					$primaryShipment = $shipmentRow;
					break;
				}
			}
			$orderComments = (string)($order['ORDER']['COMMENTS'] ?? '');
			$edostDelivery = $mfParseEdostFromComments($orderComments);

			$deliveryServiceFull = 'Не указана';
			$showEdostDeliveryNote = false;
			if ($primaryShipment)
			{

				if ($edostDelivery && !empty($edostDelivery['full']))
				{
					$deliveryServiceFull = (string)$edostDelivery['full'];
					$edostTarifId = (string)($edostDelivery['tarif_id'] ?? '');
					if (
						$edostTarifId !== 'pickup'
						&& !(function_exists('mf_checkout_is_pickup_tariff') && mf_checkout_is_pickup_tariff($edostTarifId))
					)
					{
						$showEdostDeliveryNote = true;
					}
				}
				else
				{
					$deliveryName = trim((string)($primaryShipment['DELIVERY_NAME'] ?? ''));
					if ($deliveryName !== '')
					{
						$deliveryServiceFull = $deliveryName;
					}
					elseif (!empty($primaryShipment['DELIVERY_ID']) && !empty($arResult['INFO']['DELIVERY'][$primaryShipment['DELIVERY_ID']]['NAME']))
					{
						$deliveryServiceFull = (string)$arResult['INFO']['DELIVERY'][$primaryShipment['DELIVERY_ID']]['NAME'];
					}
					$tn = trim((string)($primaryShipment['TRACKING_NUMBER'] ?? ''));
					if ($tn !== '' && $deliveryServiceFull !== 'Не указана')
					{
						$deliveryServiceFull .= ' — ' . $tn;
					}
				}
			}

			foreach ($order['PAYMENT'] as $paymentRow)
			{
				if ($order['ORDER']['LOCK_CHANGE_PAYSYSTEM'] !== 'Y')
				{
					$paymentChangeData[$paymentRow['ACCOUNT_NUMBER']] = array(
						"order" => htmlspecialcharsbx($order['ORDER']['ACCOUNT_NUMBER']),
						"payment" => htmlspecialcharsbx($paymentRow['ACCOUNT_NUMBER']),
						"allow_inner" => $arParams['ALLOW_INNER'],
						"refresh_prices" => $arParams['REFRESH_PRICES'],
						"path_to_payment" => $arParams['PATH_TO_PAYMENT'],
						"only_inner_full" => $arParams['ONLY_INNER_FULL'],
						"return_url" => $arResult['RETURN_URL'],
					);
				}
			}
			?>
			<article class="mf-order-card mb-5">
				<div class="row mx-0 sale-order-list-title-container mf-order-card-header">
					<div class="col-12 py-3">
						<div class="mf-order-card-top">
							<div class="mf-order-card-top-left">
								<div class="mf-order-card-title">
									<span class="mf-order-card-title-main">
										<?=Loc::getMessage('SPOL_TPL_ORDER')?>
										<?=Loc::getMessage('SPOL_TPL_NUMBER_SIGN').htmlspecialcharsbx($order['ORDER']['ACCOUNT_NUMBER_DISPLAY'] ?? $order['ORDER']['ACCOUNT_NUMBER'])?>
										<span class="mf-order-card-title-sep">|</span>
										<?=Loc::getMessage('SPOL_TPL_FROM_DATE')?> <?=$order['ORDER']['DATE_INSERT_FORMATED']?>
									</span>
									<?php if ($orderStatusName !== ''): ?>
										<span class="mf-order-badge <?=$orderStatusBadgeClass?>"><?=$orderStatusName?></span>
									<?php endif; ?>
								</div>
							</div>
							<div class="mf-order-card-badges">
								<?php if ($order['ORDER']['CAN_CANCEL'] !== 'N'): ?>
									<a class="sale-order-list-cancel-link mf-order-badge mf-order-card-cancel-link" href="<?=htmlspecialcharsbx($order["ORDER"]["URL_TO_CANCEL"])?>"><?=Loc::getMessage('SPOL_TPL_CANCEL_ORDER')?></a>
								<?php endif; ?>
							</div>
						</div>
						<?php if ($mfIsCancelled): ?>
							<div class="mf-order-card-cancel-reason">
								<div class="mf-order-card-cancel-reason-label">Причина отмены</div>
								<div class="mf-order-card-cancel-reason-value"><?=htmlspecialcharsbx($mfCancelReasonText !== '' ? $mfCancelReasonText : '—')?></div>
							</div>
						<?php endif; ?>
						<div class="mf-order-card-grid">
							<div class="mf-order-card-cell mf-order-card-cell--payment">
								<div class="mf-order-card-payment-headline">
									<div class="mf-order-card-label">Оплата</div>
									<div class="mf-order-card-payment-buttons">
										<?php foreach ($order['PAYMENT'] as $payment): ?>
											<?php
											$mfHideJurInvoicePayUi = function_exists('mf_checkout_should_hide_order_online_pay_ui')
												&& mf_checkout_should_hide_order_online_pay_ui(
													(int)($order['ORDER']['PERSON_TYPE_ID'] ?? 0),
													$payment
												);
											$mfCanPayOnline = !$mfHideJurInvoicePayUi
												&& ($payment['PAID'] === 'N' && $payment['IS_CASH'] !== 'Y' && $payment['ACTION_FILE'] !== 'cash');
											if (!$mfCanPayOnline)
											{
												continue;
											}
											$mfPaySystemName = trim((string)($payment['PAY_SYSTEM_NAME'] ?? ''));
											$mfPayInstructionsInModal = ($mfPaySystemName !== '' && mb_stripos($mfPaySystemName, 'Перевод с карты на карту') !== false);
											?>
											<div class="row mb-0 sale-order-list-inner-row mf-order-card-payment-row">
												<div class="col sale-order-list-inner-row-body">
													<div class="row align-items-center justify-content-end">
														<div class="col-sm-auto sale-order-list-button-container">
															<?php if ($order['ORDER']['IS_ALLOW_PAY'] == 'N'): ?>
																<a class="btn btn-primary disabled"><?=Loc::getMessage('SPOL_TPL_PAY')?></a>
															<?php elseif ($payment['NEW_WINDOW'] === 'Y'): ?>
																<a class="btn btn-primary" target="_blank" href="<?=htmlspecialcharsbx($payment['PSA_ACTION_FILE'])?>"><?=Loc::getMessage('SPOL_TPL_PAY')?></a>
															<?php else: ?>
																<a class="btn btn-primary ajax_reload<?= $mfPayInstructionsInModal ? ' mf-order-pay-instructions-modal' : '' ?>" href="<?=htmlspecialcharsbx($payment['PSA_ACTION_FILE'])?>"><?=Loc::getMessage('SPOL_TPL_PAY')?></a>
															<?php endif; ?>
														</div>
													</div>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
								<div class="mf-order-card-value"><?=htmlspecialcharsbx($paymentMethodName)?></div>
								<div class="mf-order-card-meta">
									<span class="<?=$paymentStatusClass?> mf-order-card-status-text"><?=htmlspecialcharsbx($paymentStatusText)?></span>
								</div>
								<?php foreach ($order['PAYMENT'] as $payment): ?>
									<div class="mf-order-card-payment-sum">
										<?= Loc::getMessage(
											'SPOL_TPL_SUM_TO_PAID_MSGVER_1',
											[
												'[text_span]' => '<span class="sale-order-list-payment-element">',
												'[/text_span]' => '</span>',
												'[sum_span]' => '<span class="sale-order-list-payment-number">',
												'#SUM#' => $payment['FORMATED_SUM'],
												'[/sum_span]' => '</span>',
											],
										) ?>
									</div>
									<?php if ($order['ORDER']['IS_ALLOW_PAY'] == 'N' && $payment['PAID'] !== 'Y'): ?>
										<div class="sale-order-list-status-restricted-message-block mt-2">
											<span class="sale-order-list-status-restricted-message"><?=Loc::getMessage('SOPL_TPL_RESTRICTED_PAID_MESSAGE')?></span>
										</div>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
							<div class="mf-order-card-cell">
								<div class="mf-order-card-label">Доставка</div>
								<div class="mf-order-card-value">
									<?php if ($deliveryStatusClass !== ''): ?>
										<span class="<?=$deliveryStatusClass?> mf-order-card-status-text"><?=htmlspecialcharsbx($deliveryStatusText)?></span>
									<?php else: ?>
										<?=htmlspecialcharsbx($deliveryStatusText)?>
									<?php endif; ?>
								</div>
								<div class="mf-order-card-meta">
									<div>Служба доставки: <strong><?=htmlspecialcharsbx($deliveryServiceFull)?></strong></div>
									<?php if ($showEdostDeliveryNote): ?>
										<div class="mf-order-delivery-note">Стоимость доставки предварительная. Точная сумма будет рассчитана после упаковки заказа и оплачивается при получении.</div>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="row mx-0">
					<div class="col sale-order-list-inner-container mf-order-card-body">
					<?php if (!empty($order['BASKET_ITEMS']) && is_array($order['BASKET_ITEMS'])): ?>
						<div class="mf-order-items-table-wrap mf-order-items-table-wrap_scroll">
							<table class="table table-borderless mb-0 mf-order-items-table mf-order-items-table_cols">
								<thead>
									<tr>
										<th>Производитель</th>
										<th>Артикул</th>
										<th>Наименование</th>
										<th>Склад</th>
										<th>Срок</th>
										<th class="text-center">Доставка</th>
										<th>Кол-во</th>
										<th>Цена</th>
										<th>Сумма</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($order['BASKET_ITEMS'] as $basketItem): ?>
										<?php
										$basketArticle = $mfExtractBasketArticle($basketItem);
										$basketPriceText = $mfBasketPriceText($basketItem, $order);
										$basketSumText = $mfBasketSumText($basketItem, $order);
										$basketQuantity = trim((string)($basketItem['QUANTITY'] ?? ''));
										$basketBrand = $mfBasketManufacturer($basketItem);
										$basketStore = $mfBasketStoreTitle($basketItem);
										$basketTerm = $mfBasketStoreTerm($basketItem);
										$basketStoreId = $mfBasketStoreId($basketItem);
										$basketName = $mfBasketProductName($basketItem);
										?>
										<tr>
											<td><?=htmlspecialcharsbx($basketBrand)?></td>
											<td>
												<div class="mf-order-item-article"><?=htmlspecialcharsbx($basketArticle !== '' ? $basketArticle : '—')?></div>
											</td>
											<td>
												<div class="mf-order-item-name"><?=htmlspecialcharsbx($basketName !== '' ? $basketName : '—')?></div>
											</td>
											<td><?=htmlspecialcharsbx($basketStore)?></td>
											<td><?=htmlspecialcharsbx($basketTerm)?></td>
											<td class="text-center mf-order-items-table__spb"><?php
											if ($basketStoreId > 0 && function_exists('mf_store_delivery_spb_icon_html'))
											{
												$mfBasketProductId = (int)($basketItem['PRODUCT_ID'] ?? 0);
												echo mf_store_delivery_spb_icon_html($basketStoreId, $mfBasketProductId);
											}
											else
											{
												echo '—';
											}
											?></td>
											<td><?=htmlspecialcharsbx($basketQuantity !== '' ? $basketQuantity : '—')?></td>
											<td><?=htmlspecialcharsbx($basketPriceText !== '' ? $basketPriceText : '—')?></td>
											<td><strong><?=htmlspecialcharsbx($basketSumText !== '' ? $basketSumText : '—')?></strong></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>

					</div>
				</div>
			</article>
			<?
		}
	}
	else
	{
		if ($filterShowCanceled === 'Y' && !empty($arResult['ORDERS']))
		{
			?>
			<div class="row mb-3">
				<div class="col">
					<h2><?= Loc::getMessage('SPOL_TPL_ORDERS_CANCELED_HEADER') ?></h2>
				</div>
			</div>
			<?
		}

		foreach ($arResult['ORDERS'] as $key => $order)
		{
			?>
			<div class="row sale-order-list-accomplished-title-container">
				<h3 class="g-font-size-20 mb-1 mt-1 col-sm">
					<?= Loc::getMessage('SPOL_TPL_ORDER') ?>
					<?= Loc::getMessage('SPOL_TPL_NUMBER_SIGN') ?>
					<?= htmlspecialcharsbx($order['ORDER']['ACCOUNT_NUMBER_DISPLAY'] ?? $order['ORDER']['ACCOUNT_NUMBER'])?>
					<?= Loc::getMessage('SPOL_TPL_FROM_DATE') ?>
					<span class="text-nowrap"><?= $order['ORDER']['DATE_INSERT'] ?>,</span>
					<?= count($order['BASKET_ITEMS']); ?>
					<?
					$count = mb_substr(count($order['BASKET_ITEMS']), -1);
					if ($count == '1')
					{
						echo Loc::getMessage('SPOL_TPL_GOOD');
					}
					elseif ($count >= '2' || $count <= '4')
					{
						echo Loc::getMessage('SPOL_TPL_TWO_GOODS');
					}
					else
					{
						echo Loc::getMessage('SPOL_TPL_GOODS');
					}
					?>
					<?= Loc::getMessage('SPOL_TPL_SUMOF') ?>
					<span class="text-nowrap"><?= $order['ORDER']['FORMATED_PRICE'] ?></span>
				</h3>
				<div class="col-sm-auto">
					<?
					if ($filterShowCanceled !== 'Y')
					{
						?>
						<span class="sale-order-list-accomplished-date">
									<?= Loc::getMessage('SPOL_TPL_ORDER_FINISHED')?>
								</span>
						<?
					}
					else
					{
						?>
						<span class="sale-order-list-accomplished-date canceled-order">
									<?= Loc::getMessage('SPOL_TPL_ORDER_CANCELED')?>
								</span>
						<?
					}
					?>
					<span class="sale-order-list-accomplished-date"><?= $order['ORDER']['DATE_STATUS_FORMATED'] ?></span>
				</div>
			</div>
			<div class="row mb-5">
				<div class="col pt-3 sale-order-list-inner-container">
					<div class="row pb-3 sale-order-list-inner-row">
						<div class="col-auto col-auto sale-order-list-about-container">
							<a class="g-font-size-15 sale-order-list-about-link" href="<?=htmlspecialcharsbx($order["ORDER"]["URL_TO_DETAIL"])?>"><?=Loc::getMessage('SPOL_TPL_MORE_ON_ORDER')?></a>
						</div>
						<div class="col"></div>
						<div class="col-auto sale-order-list-repeat-container">
							<a class="g-font-size-15 sale-order-list-cancel-link" href="<?=htmlspecialcharsbx($order["ORDER"]["URL_TO_COPY"])?>"><?=Loc::getMessage('SPOL_TPL_REPEAT_ORDER')?></a>
						</div>
					</div>
				</div>
			</div>
			<?
		}
	}

	echo $arResult["NAV_STRING"];

	if ($filterHistory !== 'Y')
	{
		$javascriptParams = array(
			"url" => CUtil::JSEscape($this->__component->GetPath().'/ajax.php'),
			"templateFolder" => CUtil::JSEscape($templateFolder),
			"templateName" => $this->__component->GetTemplateName(),
			"paymentList" => $paymentChangeData,
			"returnUrl" => CUtil::JSEscape($arResult["RETURN_URL"]),
		);
		$javascriptParams = CUtil::PhpToJSObject($javascriptParams);
		?>
		<script>
			BX.Sale.PersonalOrderComponent.PersonalOrderList.init(<?=$javascriptParams?>);
		</script>
		<?
	}
}
