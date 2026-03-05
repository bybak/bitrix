<?php
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_NO_ACCELERATOR_RESET', true);

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

header('Content-Type: application/json; charset=UTF-8');

try
{
	if (!class_exists(\Bitrix\Main\Loader::class))
	{
		throw new RuntimeException('No loader');
	}
	if (!\Bitrix\Main\Loader::includeModule('sale') || !\Bitrix\Main\Loader::includeModule('catalog'))
	{
		throw new RuntimeException('Modules not available');
	}

	$productId = (int)($_REQUEST['productId'] ?? 0);
	$storeId = (int)($_REQUEST['storeId'] ?? 0);
	$qty = (float)($_REQUEST['qty'] ?? 1);
	if ($qty <= 0) $qty = 1;

	if ($productId <= 0 || $storeId <= 0)
	{
		throw new RuntimeException('Bad params');
	}

	if (!function_exists('mf_calc_store_price'))
	{
		throw new RuntimeException('Pricing functions not loaded');
	}

	$price = mf_calc_store_price($productId, $storeId);
	if ($price === null || $price <= 0)
	{
		throw new RuntimeException('No price for store');
	}
	if (function_exists('mf_user_is_wholesale') && mf_user_is_wholesale())
	{
		$price = round((float)$price * 0.9, 2);
	}

	$fUserId = \Bitrix\Sale\Fuser::getId(true);
	$siteId = defined('SITE_ID') ? (string)SITE_ID : 's1';

	$basket = \Bitrix\Sale\Basket::loadItemsForFUser($fUserId, $siteId);
	$item = $basket->getExistsItem('catalog', $productId);
	if (!$item)
	{
		$item = $basket->createItem('catalog', $productId);
		$item->setFields([
			'PRODUCT_ID' => $productId,
			'QUANTITY' => $qty,
			'CURRENCY' => 'RUB',
			'LID' => $siteId,
			'CUSTOM_PRICE' => 'Y',
			'PRICE' => $price,
			'BASE_PRICE' => $price,
		]);
	}
	else
	{
		$item->setField('QUANTITY', $item->getQuantity() + $qty);
		$item->setField('CUSTOM_PRICE', 'Y');
		$item->setField('PRICE', $price);
		$item->setField('BASE_PRICE', $price);
		$item->setField('CURRENCY', 'RUB');
	}

	// Attach store info as basket properties for order visibility.
	if (function_exists('mf_store_row'))
	{
		$s = mf_store_row($storeId);
		$props = [
			'MF_STORE_ID' => (string)$storeId,
		];
		if ($s)
		{
			$props['MF_STORE_TITLE'] = (string)($s['TITLE'] ?? '');
			$props['MF_STORE_CODE'] = (string)($s['CODE'] ?? '');
		}
		if (function_exists('mf_basket_set_props'))
		{
			mf_basket_set_props($item, $props);
		}
	}

	$r = $basket->save();
	if (!$r->isSuccess())
	{
		throw new RuntimeException(implode('; ', $r->getErrorMessages()));
	}

	echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

