<?php
define('STOP_STATISTICS', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

header('Content-Type: application/json; charset=UTF-8');

try
{
	if (!class_exists(\Bitrix\Main\Loader::class))
	{
		throw new RuntimeException('No loader');
	}
	if (!\Bitrix\Main\Loader::includeModule('sale'))
	{
		throw new RuntimeException('Sale module not available');
	}

	$siteId = defined('SITE_ID') ? (string)SITE_ID : '';
	if ($siteId === '')
	{
		$siteId = 's1';
	}

	$fUserId = \Bitrix\Sale\Fuser::getId(true);
	$basket = \Bitrix\Sale\Basket::loadItemsForFUser($fUserId, $siteId);

	// Optional filter by product IDs.
	$filterIds = [];
	if (isset($_REQUEST['productIds']))
	{
		$raw = $_REQUEST['productIds'];
		if (is_array($raw))
		{
			foreach ($raw as $v)
			{
				$id = (int)$v;
				if ($id > 0) $filterIds[$id] = true;
			}
		}
		else
		{
			$id = (int)$raw;
			if ($id > 0) $filterIds[$id] = true;
		}
	}

	$products = [];
	$basketCount = 0.0;
	foreach ($basket as $bi)
	{
		$pid = 0;
		$qty = 0.0;
		try { $pid = (int)$bi->getProductId(); } catch (Throwable $e) { $pid = 0; }
		try { $qty = (float)$bi->getQuantity(); } catch (Throwable $e) { $qty = 0.0; }
		if ($pid <= 0 || $qty <= 0) continue;

		$basketCount += $qty;

		if (!empty($filterIds) && !isset($filterIds[$pid]))
		{
			continue;
		}
		if (!isset($products[$pid])) $products[$pid] = 0.0;
		$products[$pid] += $qty;
	}

	$outProducts = [];
	foreach ($products as $pid => $qty)
	{
		$outProducts[(string)$pid] = (int)round((float)$qty);
	}

	echo json_encode([
		'ok' => true,
		'basket_count' => (int)round($basketCount),
		'products' => $outProducts,
	], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

