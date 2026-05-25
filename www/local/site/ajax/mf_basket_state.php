<?php
define('STOP_STATISTICS', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

$mfAjaxFile = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_ajax.php';
if (is_file($mfAjaxFile))
{
	require_once $mfAjaxFile;
}

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
	if (function_exists('mf_ajax_session_release'))
	{
		mf_ajax_session_release();
	}

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

		if (!isset($products[$pid]))
		{
			$products[$pid] = ['qty' => 0.0, 'store_id' => 0, 'store_map' => []];
		}
		$products[$pid]['qty'] += $qty;

		if (function_exists('mf_basket_get_prop'))
		{
			$multi = mf_basket_get_prop($bi, 'MF_STORE_IDS');
			if ($multi !== null && trim((string)$multi) !== '')
			{
				foreach (explode(',', (string)$multi) as $p)
				{
					$x = (int)trim((string)$p);
					if ($x > 0)
					{
						$products[$pid]['store_map'][$x] = true;
					}
				}
			}
			$sv = mf_basket_get_prop($bi, 'MF_STORE_ID');
			if ($sv !== null && trim((string)$sv) !== '')
			{
				$x = (int)$sv;
				if ($x > 0)
				{
					$products[$pid]['store_map'][$x] = true;
					$products[$pid]['store_id'] = $x;
				}
			}
		}
	}

	$outProducts = [];
	foreach ($products as $pid => $row)
	{
		$storeMap = $row['store_map'] ?? [];
		$storeIds = array_keys($storeMap);
		sort($storeIds, SORT_NUMERIC);
		$outProducts[(string)$pid] = [
			'qty' => (int)round((float)($row['qty'] ?? 0)),
			'store_id' => (int)($row['store_id'] ?? 0),
			'store_ids' => $storeIds,
		];
	}

	echo json_encode([
		'ok' => true,
		'basket_count' => (int)round($basketCount),
		// qty; store_id — последний MF_STORE_ID (оформление); store_ids — все склады, с которых добавляли
		'products' => $outProducts,
	], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

