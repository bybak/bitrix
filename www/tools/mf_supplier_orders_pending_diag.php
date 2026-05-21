<?php

declare(strict_types=1);

/**
 * Диагностика «ожидаем поступление» (заказы поставщику → поиск / карточка).
 *
 *   php www/tools/mf_supplier_orders_pending_diag.php --id=12345
 *   php www/tools/mf_supplier_orders_pending_diag.php --article=506152509 --brand="Ski-Doo"
 *   php www/tools/mf_supplier_orders_pending_diag.php --article=506152509
 *
 * Docker:
 *   docker exec bitrix_php php /var/www/html/tools/mf_supplier_orders_pending_diag.php --article=506152509
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$supplierLib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_supplier_orders_lib.php';
if (!is_file($supplierLib))
{
	fwrite(STDERR, "mf_supplier_orders_lib.php not found at {$supplierLib}\n");
	exit(1);
}
require_once $supplierLib;

$epLib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_external_price_lib.php';
if (is_file($epLib))
{
	require_once $epLib;
}

$cardLib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_product_search_card.php';
if (is_file($cardLib))
{
	require_once $cardLib;
}

$productId = 0;
$article = '';
$brand = '';

foreach ($argv as $i => $arg)
{
	if ($i === 0)
	{
		continue;
	}
	$s = (string)$arg;
	if (preg_match('~^--id=(\d+)$~', $s, $m))
	{
		$productId = (int)$m[1];
		continue;
	}
	if (preg_match('~^--article=(.+)$~', $s, $m))
	{
		$article = trim($m[1]);
		continue;
	}
	if (preg_match('~^--brand=(.+)$~', $s, $m))
	{
		$brand = trim($m[1]);
		continue;
	}
	if (ctype_digit($s) && $productId <= 0 && $article === '')
	{
		$productId = (int)$s;
	}
}

$echoSection = static function (string $title): void {
	echo "\n=== {$title} ===\n";
};

$echoKv = static function (string $key, $value): void {
	if (is_array($value) || is_object($value))
	{
		$value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
	echo $key . ': ' . (string)$value . "\n";
};

echo "mf_supplier_orders_pending_diag\n";
echo 'DOCUMENT_ROOT: ' . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo 'PHP: ' . PHP_VERSION . "\n";
echo 'Server date (PHP today): ' . (new DateTimeImmutable('today'))->format('Y-m-d H:i:s') . "\n";

$echoSection('Libraries / functions');
$echoKv('mf_supplier_orders_lib', $supplierLib);
$echoKv('mf_supplier_orders_pending_arrival_for_product', function_exists('mf_supplier_orders_pending_arrival_for_product') ? 'yes' : 'no');
$echoKv('mf_supplier_orders_internal_store_id', function_exists('mf_supplier_orders_internal_store_id') ? 'yes' : 'no');
$echoKv('mf_product_search_card_stores', function_exists('mf_product_search_card_stores') ? 'yes' : 'no');
$echoKv('mf_ep_find_product', function_exists('mf_ep_find_product') ? 'yes' : 'no');

$conn = \Bitrix\Main\Application::getConnection();
$helper = $conn->getSqlHelper();
$tablesOk = $conn->isTableExists('mf_supplier_order_line') && $conn->isTableExists('mf_supplier_order');
$echoKv('tables mf_supplier_order*', $tablesOk ? 'yes' : 'NO — run sync / ensure_schema');

try
{
	$curDateRow = $conn->query('SELECT CURDATE() AS `D`, NOW() AS `N`')->fetch();
	if (is_array($curDateRow))
	{
		$echoKv('MySQL CURDATE', (string)($curDateRow['D'] ?? ''));
		$echoKv('MySQL NOW', (string)($curDateRow['N'] ?? ''));
	}
}
catch (\Throwable $e)
{
	$echoKv('MySQL date error', $e->getMessage());
}

if (function_exists('mf_supplier_orders_null_receipt_max_order_age_days'))
{
	$echoKv('null_receipt_max_order_age_days', mf_supplier_orders_null_receipt_max_order_age_days());
}

$iblockId = function_exists('mf_supplier_orders_catalog_iblock_id')
	? mf_supplier_orders_catalog_iblock_id()
	: 0;
$echoKv('catalog iblock_id', $iblockId);

$echoSection('Resolve product');
if ($productId <= 0 && $article !== '' && function_exists('mf_supplier_orders_match_product_id') && $iblockId > 0)
{
	$match = mf_supplier_orders_match_product_id($iblockId, $article, $brand);
	$echoKv('match by article+brand', json_encode($match, JSON_UNESCAPED_UNICODE));
	$pid = (int)($match['product_id'] ?? 0);
	if ($pid > 0)
	{
		$productId = $pid;
	}
}

if ($productId <= 0 && $article !== '' && $tablesOk)
{
	$echoSection('Order lines by ARTICLE (any PRODUCT_ID)');
	try
	{
		$artSql = $helper->forSql(mb_substr($article, 0, 250));
		$rs = $conn->query(
			'SELECT l.`ID`, l.`ORDER_ID`, l.`ARTICLE`, l.`BRAND`, l.`PRODUCT_ID`, l.`MATCH_STATUS`, l.`QTY`, l.`RECEIPT_DATE`, o.`DOC_DATE`, o.`DOC_NO`
			FROM ' . $helper->quote('mf_supplier_order_line') . ' l
			INNER JOIN ' . $helper->quote('mf_supplier_order') . ' o ON o.`ID` = l.`ORDER_ID`
			WHERE l.`ARTICLE` = \'' . $artSql . '\'
			ORDER BY l.`ID` DESC
			LIMIT 20'
		);
		$foundPid = 0;
		while ($row = $rs->fetch())
		{
			if (!is_array($row))
			{
				continue;
			}
			echo sprintf(
				"  line#%s order#%s doc=%s product_id=%s match=%s qty=%s receipt=%s doc_date=%s\n",
				(string)($row['ID'] ?? ''),
				(string)($row['ORDER_ID'] ?? ''),
				(string)($row['DOC_NO'] ?? ''),
				(string)($row['PRODUCT_ID'] ?? ''),
				(string)($row['MATCH_STATUS'] ?? ''),
				(string)($row['QTY'] ?? ''),
				(string)($row['RECEIPT_DATE'] ?? ''),
				(string)($row['DOC_DATE'] ?? '')
			);
			$pidRow = (int)($row['PRODUCT_ID'] ?? 0);
			if ($foundPid <= 0 && $pidRow > 0 && (string)($row['MATCH_STATUS'] ?? '') === 'matched')
			{
				$foundPid = $pidRow;
			}
		}
		if ($foundPid > 0 && $productId <= 0)
		{
			$productId = $foundPid;
			$echoKv('resolved PRODUCT_ID from matched line', $productId);
		}
	}
	catch (\Throwable $e)
	{
		$echoKv('article query error', $e->getMessage());
	}
}

if ($productId <= 0)
{
	fwrite(STDERR, "\nUsage: --id=PRODUCT_ID | --article=ART [--brand=BRAND]\n");
	exit(1);
}

$echoKv('PRODUCT_ID', $productId);

$cluster = function_exists('mf_catalog_product_cluster_ids')
	? mf_catalog_product_cluster_ids($productId)
	: [$productId];
$echoKv('cluster_ids', $cluster);

$intSid = function_exists('mf_supplier_orders_internal_store_id')
	? mf_supplier_orders_internal_store_id()
	: 0;
$echoKv('internal_store_id', $intSid);
if ($intSid > 0 && function_exists('mf_store_row'))
{
	$sr = mf_store_row($intSid);
	$echoKv('internal_store', is_array($sr)
		? trim((string)($sr['TITLE'] ?? '')) . ' CODE=' . (string)($sr['CODE'] ?? '') . ' XML_ID=' . (string)($sr['XML_ID'] ?? '')
		: '(mf_store_row missing)');
}

$echoSection('Store amounts (catalog)');
if (function_exists('mf_catalog_product_store_amounts'))
{
	foreach (mf_catalog_product_store_amounts($productId) as $sid => $amt)
	{
		$title = 'store #' . $sid;
		if (function_exists('mf_store_row'))
		{
			$s = mf_store_row((int)$sid);
			if (is_array($s))
			{
				$title = trim((string)($s['TITLE'] ?? $title));
			}
		}
		$ext = function_exists('mf_ep_store_is_external_warehouse') && mf_ep_store_is_external_warehouse((int)$sid) ? ' [external]' : '';
		echo sprintf("  %s (id=%d): %s%s\n", $title, (int)$sid, (string)round((float)$amt, 3), $ext);
	}
}
else
{
	echo "  mf_catalog_product_store_amounts missing\n";
}

$clusterAmt = ($intSid > 0 && function_exists('mf_supplier_orders_cluster_amount_on_store'))
	? mf_supplier_orders_cluster_amount_on_store($productId, $intSid)
	: null;
$echoKv('cluster_amount_on_internal', $clusterAmt !== null ? round((float)$clusterAmt, 3) : 'n/a');

$echoSection('Pending arrival API');
$pending = function_exists('mf_supplier_orders_pending_arrival_for_product')
	? mf_supplier_orders_pending_arrival_for_product($productId)
	: null;
if ($pending === null)
{
	echo "  mf_supplier_orders_pending_arrival_for_product → NULL (витрина не покажет «ожидаем поступление»)\n";
}
else
{
	foreach ($pending as $k => $v)
	{
		$echoKv('  ' . $k, $v);
	}
}

$echoSection('Search card stores (mf_product_search_card_stores)');
if (function_exists('mf_product_search_card_stores'))
{
	$stores = mf_product_search_card_stores($productId);
	if ($stores === [])
	{
		echo "  (empty array — нет строк складов в поиске)\n";
	}
	foreach ($stores as $row)
	{
		$pend = trim((string)($row['pending_supplier_display'] ?? ''));
		echo sprintf(
			"  store_id=%s amount=%s pending=%s price=%s\n",
			(string)($row['store_id'] ?? ''),
			(string)round((float)($row['amount'] ?? 0), 3),
			$pend !== '' ? $pend : '—',
			(string)($row['price_fmt'] ?? '—')
		);
	}
}
else
{
	echo "  mf_product_search_card_stores missing\n";
}

if ($tablesOk)
{
	$echoSection('SQL: lines for PRODUCT_ID cluster (matched + pending filter as on site)');
	$ids = [];
	foreach ($cluster as $cid)
	{
		$cid = (int)$cid;
		if ($cid > 0)
		{
			$ids[$cid] = true;
		}
	}
	if ($ids !== [])
	{
		$inSql = implode(',', array_map('intval', array_keys($ids)));
		$maxAge = function_exists('mf_supplier_orders_null_receipt_max_order_age_days')
			? mf_supplier_orders_null_receipt_max_order_age_days()
			: 365;
		try
		{
			$rs = $conn->query(
				'SELECT l.`ID`, l.`ORDER_ID`, l.`PRODUCT_ID`, l.`MATCH_STATUS`, l.`QTY`, l.`RECEIPT_DATE`, o.`DOC_DATE`, o.`DOC_NO`,
					CASE
						WHEN l.`RECEIPT_DATE` IS NOT NULL AND l.`RECEIPT_DATE` >= CURDATE() THEN \'receipt_ok\'
						WHEN l.`RECEIPT_DATE` IS NULL AND o.`DOC_DATE` >= DATE_SUB(CURDATE(), INTERVAL ' . (int)$maxAge . ' DAY) THEN \'null_receipt_ok\'
						ELSE \'filtered_out\'
					END AS `SITE_FILTER`
				FROM ' . $helper->quote('mf_supplier_order_line') . ' l
				INNER JOIN ' . $helper->quote('mf_supplier_order') . ' o ON o.`ID` = l.`ORDER_ID`
				WHERE l.`PRODUCT_ID` IN (' . $inSql . ')
				ORDER BY l.`ID` DESC
				LIMIT 30'
			);
			while ($row = $rs->fetch())
			{
				if (!is_array($row))
				{
					continue;
				}
				echo sprintf(
					"  line#%s product=%s match=%s qty=%s receipt=%s doc_date=%s filter=%s doc=%s\n",
					(string)($row['ID'] ?? ''),
					(string)($row['PRODUCT_ID'] ?? ''),
					(string)($row['MATCH_STATUS'] ?? ''),
					(string)($row['QTY'] ?? ''),
					(string)($row['RECEIPT_DATE'] ?? 'NULL'),
					(string)($row['DOC_DATE'] ?? ''),
					(string)($row['SITE_FILTER'] ?? ''),
					(string)($row['DOC_NO'] ?? '')
				);
			}
			$sumRow = $conn->query(
				'SELECT COALESCE(SUM(l.`QTY`), 0) AS `SQ`
				FROM ' . $helper->quote('mf_supplier_order_line') . ' l
				INNER JOIN ' . $helper->quote('mf_supplier_order') . ' o ON o.`ID` = l.`ORDER_ID`
				WHERE l.`PRODUCT_ID` IN (' . $inSql . ")
				  AND l.`MATCH_STATUS` = 'matched'
				  AND (
					(l.`RECEIPT_DATE` IS NOT NULL AND l.`RECEIPT_DATE` >= CURDATE())
					OR (l.`RECEIPT_DATE` IS NULL AND o.`DOC_DATE` >= DATE_SUB(CURDATE(), INTERVAL " . (int)$maxAge . ' DAY))
				  )'
			)->fetch();
			$echoKv('SUM QTY (site query)', is_array($sumRow) ? ($sumRow['SQ'] ?? '') : '');
		}
		catch (\Throwable $e)
		{
			$echoKv('SQL error', $e->getMessage());
		}
	}
}

$echoSection('Verdict');
$reasons = [];
if (!$tablesOk)
{
	$reasons[] = 'нет таблиц mf_supplier_order* — синк не выполнялся';
}
if ($intSid <= 0)
{
	$reasons[] = 'не найден склад MOTOR_FORCE_INTERNAL (internal_store_id=0)';
}
if ($clusterAmt !== null && (float)$clusterAmt > 1e-9)
{
	$reasons[] = 'на внутреннем складе остаток > 0 — блок «ожидаем поступление» скрыт';
}
if ($pending === null)
{
	$reasons[] = 'pending_arrival_for_product вернул null (нет matched строк / даты в прошлом / qty=0)';
}
else
{
	$reasons[] = 'OK: витрина должна показывать «ожидаем поступление» при остатке 0 на internal';
}

foreach ($reasons as $r)
{
	echo '  • ' . $r . "\n";
}

echo "\n";
