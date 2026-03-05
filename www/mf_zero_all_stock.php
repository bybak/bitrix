<?php
/**
 * Полное обнуление остатков по каталогу:
 * - обнуляет AMOUNT в b_catalog_store_product для товаров инфоблока
 * - выставляет QUANTITY=0 и AVAILABLE=N в b_catalog_product
 *
 * После этого товары будут становиться "в наличии" только когда их завезут импортом в конкретный склад.
 *
 * Запуск (внутри контейнера bitrix_php):
 *   php /var/www/html/mf_zero_all_stock.php --dry-run
 *   php /var/www/html/mf_zero_all_stock.php --apply
 *
 * Опции:
 *   --iblock-id=4
 *   --include-inactive=Y|N (по умолчанию Y)  - учитывать неактивные элементы тоже
 *   --apply / --dry-run
 */

$_SERVER["DOCUMENT_ROOT"] = __DIR__;
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Application;

Loader::includeModule("iblock");
Loader::includeModule("catalog");

while (ob_get_level() > 0) { @ob_end_flush(); }
@ob_implicit_flush(true);

function arg(string $name): ?string
{
	foreach ($_SERVER['argv'] as $a)
	{
		if (strpos($a, $name . '=') === 0)
		{
			return substr($a, strlen($name) + 1);
		}
	}
	return null;
}

function flag(string $name): bool
{
	return in_array($name, $_SERVER['argv'], true);
}

function out(string $s): void
{
	echo $s . PHP_EOL;
	if (function_exists('flush')) flush();
}

function dbScalar(\Bitrix\Main\DB\Connection $c, string $sql): string
{
	$r = $c->query($sql);
	$row = $r ? $r->fetch() : null;
	if (!$row) return '';
	$v = reset($row);
	return $v === false ? '' : (string)$v;
}

try
{
	$iblockId = (int)(arg('--iblock-id') ?: 4);
	$includeInactive = (arg('--include-inactive') ?: 'Y') === 'Y';
	$apply = flag('--apply');
	$dry = flag('--dry-run') || !$apply;

	out("=== MF ZERO ALL STOCK ===");
	out("IBLOCK_ID: $iblockId");
	out("INCLUDE_INACTIVE: " . ($includeInactive ? 'Y' : 'N'));
	out("MODE: " . ($apply ? 'APPLY' : 'DRY-RUN'));

	$activeCond = $includeInactive ? '1=1' : "e.ACTIVE='Y'";

	$conn = Application::getConnection();

	$cntStore = (int)dbScalar(
		$conn,
		"SELECT COUNT(*) FROM b_catalog_store_product sp
		 INNER JOIN b_iblock_element e ON e.ID=sp.PRODUCT_ID AND e.IBLOCK_ID={$iblockId}
		 WHERE {$activeCond} AND sp.AMOUNT<>0"
	);

	$cntProd = (int)dbScalar(
		$conn,
		"SELECT COUNT(*) FROM b_catalog_product cp
		 INNER JOIN b_iblock_element e ON e.ID=cp.ID AND e.IBLOCK_ID={$iblockId}
		 WHERE {$activeCond}
		   AND (cp.QUANTITY<>0 OR cp.AVAILABLE<>'N' OR cp.QUANTITY_TRACE<>'Y' OR cp.CAN_BUY_ZERO<>'N')"
	);

	out("TO_ZERO store_amount_rows: $cntStore");
	out("TO_FIX  catalog_product_rows: $cntProd");

	if ($dry)
	{
		out("DRY-RUN: ничего не меняю.");
		return;
	}

	$conn->queryExecute(
		"UPDATE b_catalog_store_product sp
		 INNER JOIN b_iblock_element e ON e.ID=sp.PRODUCT_ID AND e.IBLOCK_ID={$iblockId}
		 SET sp.AMOUNT=0
		 WHERE {$activeCond} AND sp.AMOUNT<>0"
	);
	$aff1 = (int)dbScalar($conn, "SELECT ROW_COUNT()");

	$conn->queryExecute(
		"UPDATE b_catalog_product cp
		 INNER JOIN b_iblock_element e ON e.ID=cp.ID AND e.IBLOCK_ID={$iblockId}
		 SET cp.QUANTITY=0,
		     cp.AVAILABLE='N',
		     cp.QUANTITY_TRACE='Y',
		     cp.CAN_BUY_ZERO='N'
		 WHERE {$activeCond}
		   AND (cp.QUANTITY<>0 OR cp.AVAILABLE<>'N' OR cp.QUANTITY_TRACE<>'Y' OR cp.CAN_BUY_ZERO<>'N')"
	);
	$aff2 = (int)dbScalar($conn, "SELECT ROW_COUNT()");

	out("DONE store_amount_zeroed: $aff1");
	out("DONE catalog_product_fixed: $aff2");
}
catch (Throwable $e)
{
	out("ОШИБКА: " . $e->getMessage());
	fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
	exit(1);
}

