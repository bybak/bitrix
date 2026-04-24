<?php
declare(strict_types=1);

define('STOP_STATISTICS', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Application;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Fuser;

header('Content-Type: application/json; charset=UTF-8');

try
{
	if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale'))
	{
		throw new RuntimeException('Sale module not available');
	}

	$request = Application::getInstance()->getContext()->getRequest();
	$siteId = defined('SITE_ID') ? (string)SITE_ID : 's1';
	if ($siteId === '')
	{
		$siteId = 's1';
	}

	$ids = [];
	$rawIds = $request->getPost('basketItemIds');
	if (is_array($rawIds))
	{
		foreach ($rawIds as $id)
		{
			$id = (int)$id;
			if ($id > 0) $ids[$id] = true;
		}
	}
	elseif ($rawIds !== null)
	{
		$id = (int)$rawIds;
		if ($id > 0) $ids[$id] = true;
	}
	$ids = array_keys($ids);

	$fUserId = Fuser::getId(true);
	$basket = Basket::loadItemsForFUser($fUserId, $siteId);

	$result = [];
	foreach ($basket as $item)
	{
		/** @var \Bitrix\Sale\BasketItemBase $item */
		$basketItemId = (int)$item->getId();
		if (!empty($ids) && !in_array($basketItemId, $ids, true))
		{
			continue;
		}

		$productId = (int)$item->getProductId();
		$qty = (float)$item->getQuantity();
		$currentStoreId = (int)(function_exists('mf_basket_get_prop') ? (mf_basket_get_prop($item, 'MF_STORE_ID') ?? 0) : 0);
		if ($currentStoreId <= 0 && function_exists('mf_min_price_from_available_stores'))
		{
			[, $currentStoreId] = mf_min_price_from_available_stores($productId);
			$currentStoreId = (int)$currentStoreId;
		}

		$options = function_exists('mf_product_available_stores_for_qty')
			? mf_product_available_stores_for_qty($productId, $qty)
			: [];

		$currentStore = null;
		foreach ($options as $opt)
		{
			if ((int)($opt['store_id'] ?? 0) === $currentStoreId)
			{
				$currentStore = $opt;
				break;
			}
		}
		if ($currentStore === null && $currentStoreId > 0 && function_exists('mf_store_row'))
		{
			$s = mf_store_row($currentStoreId);
			$currentStore = [
				'store_id' => $currentStoreId,
				'title' => (string)($s['TITLE'] ?? ('Склад #' . $currentStoreId)),
				'code' => (string)($s['CODE'] ?? ''),
				'xml_id' => (string)($s['XML_ID'] ?? ''),
				'amount' => 0.0,
				'price' => 0.0,
				'price_fmt' => '',
				'delivery_term' => function_exists('mf_store_delivery_term') ? mf_store_delivery_term($currentStoreId) : 'Срок уточнит менеджер',
			];
		}

		$spbTop = function_exists('mf_store_delivery_spb_ui')
			? mf_store_delivery_spb_ui($currentStoreId, $productId)
			: ['ok' => true, 'title' => ''];

		$result[(string)$basketItemId] = [
			'basket_item_id' => $basketItemId,
			'product_id' => $productId,
			'quantity' => $qty,
			'current_store_id' => $currentStoreId,
			'current_store_title' => is_array($currentStore) ? (string)($currentStore['title'] ?? '') : '',
			'delivery_term' => is_array($currentStore) ? (string)($currentStore['delivery_term'] ?? 'Срок уточнит менеджер') : 'Срок уточнит менеджер',
			'delivery_spb_ok' => !empty($spbTop['ok']),
			'delivery_spb_title' => (string)($spbTop['title'] ?? ''),
			'options' => array_values($options),
			'can_switch' => count($options) > 1,
		];
	}

	echo json_encode([
		'ok' => true,
		'items' => $result,
	], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
	http_response_code(400);
	echo json_encode([
		'ok' => false,
		'error' => $e->getMessage(),
	], JSON_UNESCAPED_UNICODE);
}

