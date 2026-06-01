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

	if (!function_exists('mf_ep_display_price_for_store'))
	{
		throw new RuntimeException('Pricing functions not loaded');
	}

	$price = function_exists('mf_ep_basket_unit_price_with_fallback')
		? mf_ep_basket_unit_price_with_fallback($productId, $storeId, $qty)
		: mf_ep_display_price_for_store($productId, $storeId, $qty);
	if ($price === null || $price <= 0)
	{
		throw new RuntimeException('No price for store');
	}

	$fUserId = \Bitrix\Sale\Fuser::getId(true);
	$siteId = defined('SITE_ID') ? (string)SITE_ID : 's1';

	$basket = \Bitrix\Sale\Basket::loadItemsForFUser($fUserId, $siteId);
	$item = $basket->getExistsItem('catalog', $productId);
	$productIdentity = function_exists('mf_basket_product_identity')
		? mf_basket_product_identity($productId)
		: null;

	$basketFields = [
		'PRODUCT_ID' => $productId,
		'QUANTITY' => $qty,
		'CURRENCY' => 'RUB',
		'LID' => $siteId,
		'CUSTOM_PRICE' => 'Y',
		'PRICE' => $price,
		'BASE_PRICE' => $price,
	];
	if (is_array($productIdentity))
	{
		if ($productIdentity['NAME'] !== '')
		{
			$basketFields['NAME'] = $productIdentity['NAME'];
		}
		if ($productIdentity['PRODUCT_XML_ID'] !== '')
		{
			$basketFields['PRODUCT_XML_ID'] = $productIdentity['PRODUCT_XML_ID'];
		}
		if ($productIdentity['CATALOG_XML_ID'] !== '')
		{
			$basketFields['CATALOG_XML_ID'] = $productIdentity['CATALOG_XML_ID'];
		}
	}

	if (!$item)
	{
		$item = $basket->createItem('catalog', $productId);
		$item->setFields($basketFields);
	}
	else
	{
		$item->setField('QUANTITY', $item->getQuantity() + $qty);
		$item->setField('CUSTOM_PRICE', 'Y');
		$item->setField('PRICE', $price);
		$item->setField('BASE_PRICE', $price);
		$item->setField('CURRENCY', 'RUB');
		if (is_array($productIdentity))
		{
			if ($productIdentity['NAME'] !== '')
			{
				$item->setField('NAME', $productIdentity['NAME']);
			}
			if ($productIdentity['PRODUCT_XML_ID'] !== '')
			{
				$item->setField('PRODUCT_XML_ID', $productIdentity['PRODUCT_XML_ID']);
			}
			if ($productIdentity['CATALOG_XML_ID'] !== '')
			{
				$item->setField('CATALOG_XML_ID', $productIdentity['CATALOG_XML_ID']);
			}
		}
	}

	// Attach store info as basket properties for order visibility.
	if (function_exists('mf_store_row'))
	{
		$s = mf_store_row($storeId);
		$idsCsv = function_exists('mf_basket_merged_store_ids_for_item')
			? mf_basket_merged_store_ids_for_item($item, $storeId)
			: (string)$storeId;

		$props = [
			'MF_STORE_ID' => (string)$storeId,
			'MF_STORE_IDS' => $idsCsv,
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

	if (function_exists('mf_basket_apply_1c_sync_fields'))
	{
		mf_basket_apply_1c_sync_fields($item);
	}

	$r = $basket->save();
	if (!$r->isSuccess())
	{
		throw new RuntimeException(implode('; ', $r->getErrorMessages()));
	}

	// Return actual basket quantity for live header counter update.
	$basketCount = 0.0;
	foreach ($basket as $bi)
	{
		try { $basketCount += (float)$bi->getQuantity(); } catch (Throwable $e) {}
	}

	echo json_encode(['ok' => true, 'basket_count' => (int)round($basketCount)], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

