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
	if (!function_exists('check_bitrix_sessid') || !check_bitrix_sessid())
	{
		throw new RuntimeException('Сессия истекла. Обновите страницу.');
	}
	if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('sale') || !\Bitrix\Main\Loader::includeModule('catalog'))
	{
		throw new RuntimeException('Required modules not available');
	}

	$request = Application::getInstance()->getContext()->getRequest();
	$basketItemId = (int)$request->getPost('basket_item_id');
	$storeId = (int)$request->getPost('store_id');
	if ($basketItemId <= 0 || $storeId <= 0)
	{
		throw new RuntimeException('Bad params');
	}

	$siteId = defined('SITE_ID') ? (string)SITE_ID : 's1';
	if ($siteId === '')
	{
		$siteId = 's1';
	}

	$fUserId = Fuser::getId(true);
	$basket = Basket::loadItemsForFUser($fUserId, $siteId);

	$targetItem = null;
	foreach ($basket as $item)
	{
		if ((int)$item->getId() === $basketItemId)
		{
			$targetItem = $item;
			break;
		}
	}
	if (!$targetItem instanceof \Bitrix\Sale\BasketItemBase)
	{
		throw new RuntimeException('Basket item not found');
	}

	$productId = (int)$targetItem->getProductId();
	$qty = (float)$targetItem->getQuantity();
	$options = function_exists('mf_product_available_stores_for_qty')
		? mf_product_available_stores_for_qty($productId, $qty)
		: [];

	$selected = null;
	foreach ($options as $opt)
	{
		if ((int)($opt['store_id'] ?? 0) === $storeId)
		{
			$selected = $opt;
			break;
		}
	}
	if (!is_array($selected))
	{
		throw new RuntimeException('Выбранный склад недоступен для этого товара.');
	}

	$props = [
		'MF_STORE_ID' => (string)$storeId,
		'MF_STORE_TITLE' => (string)($selected['title'] ?? ''),
		'MF_STORE_CODE' => (string)($selected['code'] ?? ''),
	];
	if (function_exists('mf_basket_set_props'))
	{
		mf_basket_set_props($targetItem, $props);
	}
	if (function_exists('mf_assign_store_and_price_to_basket_item'))
	{
		mf_assign_store_and_price_to_basket_item($targetItem);
	}

	$r = $basket->save();
	if (!$r->isSuccess())
	{
		throw new RuntimeException(implode('; ', $r->getErrorMessages()));
	}

	echo json_encode([
		'ok' => true,
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

