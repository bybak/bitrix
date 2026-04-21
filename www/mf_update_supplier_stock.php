<?php
/**
 * Обновление остатков (и опционально цены) от поставщика в отдельный склад Bitrix.
 *
 * Запуск (внутри контейнера bitrix_php):
 *   php /var/www/html/mf_update_supplier_stock.php --dry-run --warehouse-code=YAMAHA --file=/var/www/html/supplier_stock.csv
 *   php /var/www/html/mf_update_supplier_stock.php --apply   --warehouse-code=YAMAHA --warehouse-title="Yamaha (поставщик)" --file=/var/www/html/supplier_stock.csv
 *
 * Back-compat:
 *   --supplier=SupplierA (используется как warehouse-code и дефолтное имя)
 *
 * CSV формат (рекомендуется):
 *   Бренд;Артикул;Название;Остаток;Цена
 *
 * Колонка "Название/Наименование" — опциональна: используется только для записи в "ненайденные товары"
 * (mf_stock_import_missing), чтобы потом проще создавать товары-заглушки в админке.
 *
 * Важно про цену:
 * - Для каждого поставщика создаётся свой тип цены (CATALOG_GROUP) и обновляется туда.
 * - BASE цена (которая показывается на сайте, т.к. компоненты настроены на PRICE_CODE=BASE)
 *   пересчитывается как минимальная цена среди поставщиков, у которых есть остаток > 0.
 *
 * Опции:
 *   --iblock-id=4
 *   --warehouse-code=UniqueCode
 *   --warehouse-title=Human name (optional; потом можно отредактировать в админке)
 *   --supplier=NameOrCode (deprecated)
 *   --file=/path/file.csv
 *   --encoding=cp1251|utf-8
 *   --apply / --dry-run
 *   --price=Y|N        (по умолчанию N)
 *   --recalc-base=Y|N  (по умолчанию Y, если --price=Y)
 *   --save-missing=Y|N (по умолчанию Y) — писать ли "ненайденные" в HL mf_stock_import_missing
 *   --brand-dict=Y|N   (по умолчанию N) — каноникализация бренда через mf_brand_dict.php (ускоряет матчинг/уменьшает notFound)
 *   --ensure-indexes=Y|N (по умолчанию N) — попытаться создать полезные индексы (выполнять один раз, может блокировать таблицы)
 *   --fast-product-update=Y|N (по умолчанию Y) — откладывать обновление QUANTITY/AVAILABLE и записывать пачкой в конце
 */

$_SERVER["DOCUMENT_ROOT"] = __DIR__;
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Catalog\StoreTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Highloadblock\HighloadBlockTable;

Loader::includeModule("iblock");
Loader::includeModule("catalog");
Loader::includeModule("highloadblock");

// Тот же PRODUCT_ID, что и у импорта внешних прайсов (родитель SKU → первый оффер).
$mfEpLib = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_external_price_lib.php';
if (is_file($mfEpLib))
{
	require_once $mfEpLib;
}

// Ensure progress output is visible in CLI (no buffering).
while (ob_get_level() > 0)
{
	@ob_end_flush();
}
@ob_implicit_flush(true);

function arg(string $name): ?string
{
	foreach ($_SERVER['argv'] as $a)
	{
		if (strpos($a, $name.'=') === 0)
		{
			return substr($a, strlen($name) + 1);
		}
	}
	return null;
}
function flag(string $name): bool { return in_array($name, $_SERVER['argv'], true); }
function out(string $s): void
{
	echo $s . PHP_EOL;
	if (function_exists('flush')) flush();
}

function mf_toUtf8($v, string $fromEncoding): string
{
	$s = (string)($v ?? '');
	if ($s === '') return '';

	// If it already looks like valid UTF-8, keep it (helps when user passes wrong --encoding).
	if (function_exists('mb_check_encoding') && mb_check_encoding($s, 'UTF-8'))
	{
		return $s;
	}

	$from = strtoupper(trim($fromEncoding));
	if ($from === '' || $from === 'UTF8') $from = 'UTF-8';

	// Prefer iconv with //IGNORE to avoid fatal conversion errors.
	if (function_exists('iconv'))
	{
		$converted = @iconv($from, 'UTF-8//IGNORE', $s);
		if (is_string($converted) && $converted !== '')
		{
			return $converted;
		}
	}

	if (function_exists('mb_convert_encoding'))
	{
		$converted = mb_convert_encoding($s, 'UTF-8', $from);
		return is_string($converted) ? $converted : $s;
	}

	return $s;
}

function mf_bool(string $v, string $default = 'N'): bool
{
	$v = strtoupper(trim($v));
	if ($v === '') $v = strtoupper(trim($default));
	return ($v === 'Y' || $v === '1' || $v === 'YES' || $v === 'TRUE');
}

/**
 * Run log table (MySQL) for mf_update_supplier_stock.php.
 * We keep this as a plain DB table (not HL) to simplify writes from CLI.
 */
function mf_supplier_stock_log_conn(): ?\Bitrix\Main\DB\Connection
{
	if (!class_exists(Application::class))
	{
		return null;
	}
	try
	{
		return Application::getConnection();
	}
	catch (Throwable $e)
	{
		return null;
	}
}

function mf_supplier_stock_log_ensure_table(): bool
{
	$conn = mf_supplier_stock_log_conn();
	if (!$conn) return false;

	// Works on MySQL. If using another DB driver, silently disable logging.
	$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
	if ($driver !== '' && stripos($driver, 'mysql') === false)
	{
		return false;
	}

	$sql = "CREATE TABLE IF NOT EXISTS mf_supplier_stock_run_log (
		ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		UF_STARTED_AT DATETIME NOT NULL,
		UF_FINISHED_AT DATETIME NULL,
		UF_DURATION_MS INT UNSIGNED NULL,
		UF_STATUS VARCHAR(16) NOT NULL DEFAULT 'running',

		UF_WAREHOUSE_CODE VARCHAR(64) NOT NULL,
		UF_WAREHOUSE_TITLE VARCHAR(255) NULL,
		UF_STORE_ID INT NULL,
		UF_STORE_XML_ID VARCHAR(128) NULL,

		UF_INPUT_FILE VARCHAR(512) NOT NULL,
		UF_FILE_SIZE BIGINT NULL,
		UF_FILE_MTIME DATETIME NULL,
		UF_ENCODING VARCHAR(32) NULL,

		UF_MODE VARCHAR(16) NOT NULL,
		UF_PRICE_UPDATE CHAR(1) NOT NULL DEFAULT 'N',
		UF_RECALC_BASE CHAR(1) NOT NULL DEFAULT 'N',
		UF_SYNC_MISSING CHAR(1) NOT NULL DEFAULT 'Y',

		UF_TOTAL INT UNSIGNED NOT NULL DEFAULT 0,
		UF_UPDATED INT UNSIGNED NOT NULL DEFAULT 0,
		UF_NOT_FOUND INT UNSIGNED NOT NULL DEFAULT 0,
		UF_ZEROED INT UNSIGNED NOT NULL DEFAULT 0,
		UF_ERRORS INT UNSIGNED NOT NULL DEFAULT 0,

		UF_ERROR_ITEMS MEDIUMTEXT NULL,
		UF_NOT_FOUND_ITEMS MEDIUMTEXT NULL,

		UF_PHP_SAPI VARCHAR(64) NULL,
		UF_HOST VARCHAR(255) NULL,
		UF_PID INT NULL,
		UF_MEMORY_PEAK_MB DOUBLE NULL,
		UF_NOTE VARCHAR(255) NULL,

		PRIMARY KEY (ID),
		KEY IX_STARTED_AT (UF_STARTED_AT),
		KEY IX_WAREHOUSE_CODE (UF_WAREHOUSE_CODE),
		KEY IX_STORE_XML_ID (UF_STORE_XML_ID),
		KEY IX_STATUS (UF_STATUS)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

	try
	{
		$conn->queryExecute($sql);
		return true;
	}
	catch (Throwable $e)
	{
		return false;
	}
}

function mf_supplier_stock_log_quote(\Bitrix\Main\DB\Connection $conn, $value): string
{
	if ($value === null)
	{
		return 'NULL';
	}
	$h = $conn->getSqlHelper();
	return "'" . $h->forSql((string)$value) . "'";
}

function mf_supplier_stock_log_col(string $name): string
{
	// We only use fixed UF_* column names from this script.
	$name = str_replace('`', '', trim($name));
	return '`' . $name . '`';
}

function mf_supplier_stock_log_insert(array $fields): int
{
	$conn = mf_supplier_stock_log_conn();
	if (!$conn) return 0;

	$cols = [];
	$vals = [];
	foreach ($fields as $k => $v)
	{
		$k = trim((string)$k);
		if ($k === '') continue;
		$cols[] = mf_supplier_stock_log_col($k);
		$vals[] = mf_supplier_stock_log_quote($conn, $v);
	}
	if (empty($cols)) return 0;

	$sql = "INSERT INTO mf_supplier_stock_run_log (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
	$conn->queryExecute($sql);

	$r = $conn->query("SELECT LAST_INSERT_ID() AS ID")->fetch();
	return (int)($r['ID'] ?? 0);
}

function mf_supplier_stock_log_update(int $id, array $fields): void
{
	$id = (int)$id;
	if ($id <= 0) return;

	$conn = mf_supplier_stock_log_conn();
	if (!$conn) return;

	$sets = [];
	foreach ($fields as $k => $v)
	{
		$k = trim((string)$k);
		if ($k === '') continue;
		$sets[] = mf_supplier_stock_log_col($k) . '=' . mf_supplier_stock_log_quote($conn, $v);
	}
	if (empty($sets)) return;

	$conn->queryExecute("UPDATE mf_supplier_stock_run_log SET " . implode(', ', $sets) . " WHERE ID=" . (int)$id . " LIMIT 1");
}

function mf_sql_dt(?float $ts = null): string
{
	if ($ts === null) $ts = microtime(true);
	return date('Y-m-d H:i:s', (int)$ts);
}

function mf_try_ensure_indexes(bool $apply): void
{
	if (!$apply) return;

	$conn = mf_supplier_stock_log_conn();
	if (!$conn) return;

	// MySQL-only.
	$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
	if ($driver !== '' && stripos($driver, 'mysql') === false) return;

	// 1) Ensure unique pair (PRODUCT_ID, STORE_ID) in b_catalog_store_product
	// This prevents accidental duplicates and speeds up lookups.
	try
	{
		$idx = $conn->query("SHOW INDEX FROM b_catalog_store_product WHERE Key_name='UX_MF_STORE_PRODUCT_PAIR'")->fetch();
		if (!$idx)
		{
			// Only create if there are no duplicates (safe guard).
			$dup = $conn->query("
				SELECT 1
				FROM b_catalog_store_product
				GROUP BY PRODUCT_ID, STORE_ID
				HAVING COUNT(*) > 1
				LIMIT 1
			")->fetch();
			if (!$dup)
			{
				$conn->queryExecute("ALTER TABLE b_catalog_store_product ADD UNIQUE KEY UX_MF_STORE_PRODUCT_PAIR (PRODUCT_ID, STORE_ID)");
			}
		}
	}
	catch (Throwable $e)
	{
		// ignore (best-effort)
	}

	// 2) Speed up mf_stock_import_missing upserts:
	// add unique index (UF_WAREHOUSE_XML_ID, UF_UNIQ_KEY) with prefix lengths (TEXT columns).
	try
	{
		$idx = $conn->query("SHOW INDEX FROM mf_stock_import_missing WHERE Key_name='UX_MF_MISSING_WAREHOUSE_UNIQ'")->fetch();
		if (!$idx)
		{
			$conn->queryExecute("ALTER TABLE mf_stock_import_missing ADD UNIQUE KEY UX_MF_MISSING_WAREHOUSE_UNIQ (UF_WAREHOUSE_XML_ID(64), UF_UNIQ_KEY(128))");
		}
	}
	catch (Throwable $e)
	{
		// ignore
	}
}

function mf_pct(float $done, float $total): float
{
	if ($total <= 0) return 0.0;
	$p = ($done / $total) * 100.0;
	if ($p < 0) $p = 0.0;
	if ($p > 100) $p = 100.0;
	return $p;
}

function mf_progress(string $label, float $pct, string $extra = ''): void
{
	static $lastLen = 0;
	$line = sprintf("%s: %6.2f%%", $label, $pct);
	if ($extra !== '') $line .= "  " . $extra;

	$pad = max(0, $lastLen - strlen($line));
	echo "\r" . $line . str_repeat(' ', $pad);
	$lastLen = strlen($line);
	if (function_exists('flush')) flush();
}

function mf_progressDone(): void
{
	echo PHP_EOL;
	if (function_exists('flush')) flush();
}

function mf_fmt_eta(int $sec): string
{
	if ($sec < 0) $sec = 0;
	$h = intdiv($sec, 3600);
	$m = intdiv($sec % 3600, 60);
	$s = $sec % 60;
	if ($h > 0) return sprintf('%dh%02dm%02ds', $h, $m, $s);
	return sprintf('%dm%02ds', $m, $s);
}

function normalizeArticle(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = preg_replace('~[^A-Z0-9]+~', '', $s) ?? '';
	return $s;
}

function normalizeBrand(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = str_replace('Ё', 'Е', $s);
	$s = preg_replace('~[^A-ZА-Я0-9]+~u', '', $s) ?? '';
	return $s;
}

function makeUniqKey(string $articleNorm, string $brandNorm): string
{
	$articleNorm = trim($articleNorm);
	$brandNorm = trim($brandNorm);
	if ($brandNorm === '') $brandNorm = 'UNKNOWNBRAND';
	return $articleNorm . '_' . $brandNorm;
}

function mf_iblock4_property_id(string $code): int
{
	static $cache = null;
	if (!is_array($cache))
	{
		$cache = [];
	}
	$code = trim($code);
	if ($code === '') return 0;
	if (isset($cache[$code])) return (int)$cache[$code];

	$conn = mf_supplier_stock_log_conn();
	if (!$conn)
	{
		$cache[$code] = 0;
		return 0;
	}

	try
	{
		$h = $conn->getSqlHelper();
		$codeSql = $h->forSql($code);
		$r = $conn->query("SELECT ID FROM b_iblock_property WHERE IBLOCK_ID=4 AND CODE='{$codeSql}' LIMIT 1")->fetch();
		$id = (int)($r['ID'] ?? 0);
		$cache[$code] = $id;
		return $id;
	}
	catch (Throwable $e)
	{
		$cache[$code] = 0;
		return 0;
	}
}

function findCanonicalProductIdByUniqKey(int $iblockId, string $uniqKey): ?int
{
	$iblockId = (int)$iblockId;
	$uniqKey = trim((string)$uniqKey);
	if ($iblockId <= 0 || $uniqKey === '') return null;

	$conn = mf_supplier_stock_log_conn();
	if (!$conn) return null;

	$propUniq = mf_iblock4_property_id('MF_UNIQ_KEY');
	$propRedir = mf_iblock4_property_id('MF_IS_REDIRECT');
	if ($propUniq <= 0 || $propRedir <= 0) return null;

	try
	{
		$h = $conn->getSqlHelper();
		$keySql = $h->forSql($uniqKey);
		$sql = "
SELECT e.ID
FROM b_iblock_element e
INNER JOIN b_iblock_element_property pkey
  ON pkey.IBLOCK_ELEMENT_ID = e.ID
 AND pkey.IBLOCK_PROPERTY_ID = {$propUniq}
 AND pkey.VALUE = '{$keySql}'
LEFT JOIN b_iblock_element_property pred
  ON pred.IBLOCK_ELEMENT_ID = e.ID
 AND pred.IBLOCK_PROPERTY_ID = {$propRedir}
WHERE e.IBLOCK_ID = {$iblockId}
  AND (pred.VALUE IS NULL OR pred.VALUE <> 'Y')
LIMIT 1
";
		$r = $conn->query($sql)->fetch();
		$id = (int)($r['ID'] ?? 0);
		return $id > 0 ? $id : null;
	}
	catch (Throwable $e)
	{
		return null;
	}
}

function findStoreIdByXmlId(string $xmlId): ?int
{
	$xmlId = trim($xmlId);
	if ($xmlId === '') return null;

	$row = StoreTable::getList([
		'filter' => ['=XML_ID' => $xmlId],
		'select' => ['ID'],
		'limit' => 1,
	])->fetch();
	return $row ? (int)$row['ID'] : null;
}

function findStoreRowByCodeOrXml(string $code): ?array
{
	$code = trim($code);
	if ($code === '') return null;

	// Try CODE first (that's what admin "Символьный код" sets).
	$row = StoreTable::getList([
		'filter' => ['=CODE' => $code],
		'select' => ['ID', 'CODE', 'XML_ID', 'TITLE', 'ADDRESS'],
		'limit' => 1,
	])->fetch();
	if ($row) return $row;

	// Fallback: some stores may have XML_ID filled but CODE empty.
	$row = StoreTable::getList([
		'filter' => ['=XML_ID' => $code],
		'select' => ['ID', 'CODE', 'XML_ID', 'TITLE', 'ADDRESS'],
		'limit' => 1,
	])->fetch();
	return $row ?: null;
}

function getStoreMarkupPct(int $storeId): float
{
	static $cache = [];

	$storeId = (int)$storeId;
	if ($storeId <= 0) return 0.0;
	if (array_key_exists($storeId, $cache)) return (float)$cache[$storeId];

	$pct = 0.0;
	global $USER_FIELD_MANAGER;
	if (is_object($USER_FIELD_MANAGER))
	{
		$ufs = $USER_FIELD_MANAGER->GetUserFields(StoreTable::getUfId(), $storeId);
		$v = $ufs['UF_MF_MARKUP_PCT']['VALUE'] ?? 0;
		if (is_array($v)) $v = reset($v);
		$v = (string)$v;
		$v = str_replace(',', '.', $v);
		$pct = (float)$v;
		if (!is_finite($pct)) $pct = 0.0;
	}

	$cache[$storeId] = $pct;
	return $pct;
}

function applyMarkup(float $price, float $pct): float
{
	if ($price <= 0) return $price;
	if ($pct == 0.0) return $price;
	return round($price * (1.0 + ($pct / 100.0)), 2);
}

function sanitizeStoreCode(string $s): string
{
	$s = mb_strtoupper(trim($s));
	$s = preg_replace('~[^A-Z0-9_]+~', '_', $s) ?? $s;
	$s = preg_replace('~_+~', '_', (string)$s) ?? $s;
	$s = trim((string)$s, '_');
	return $s !== '' ? $s : 'WAREHOUSE';
}

function getOrCreateStoreByCode(string $warehouseCode, string $warehouseTitle, bool $create): array
{
	$warehouseCode = trim($warehouseCode);
	$code = sanitizeStoreCode($warehouseCode);
	$xmlId = 'SUPPLIER_' . $code;

	$existingId = findStoreIdByXmlId($xmlId);
	if ($existingId) return [$existingId, $xmlId];

	// If store already exists in admin with CODE=$code or XML_ID=$code, reuse it.
	$existing = findStoreRowByCodeOrXml($code);
	if ($existing)
	{
		$storeId = (int)$existing['ID'];
		$currentXmlId = trim((string)($existing['XML_ID'] ?? ''));
		if ($storeId > 0 && $create && $currentXmlId !== $xmlId)
		{
			// Normalize mapping key so imports are stable.
			$upd = StoreTable::update($storeId, ['XML_ID' => $xmlId]);
			if (!$upd->isSuccess())
			{
				throw new RuntimeException("Не удалось обновить XML_ID склада (ID=$storeId) на '$xmlId': " . implode('; ', $upd->getErrorMessages()));
			}
		}
		return [$storeId, $xmlId];
	}

	if (!$create)
	{
		return [0, $xmlId];
	}

	$title = trim($warehouseTitle) !== '' ? trim($warehouseTitle) : $warehouseCode;
	$res = StoreTable::add([
		'TITLE' => $title,
		'ACTIVE' => 'Y',
		'ADDRESS' => 'Склад импорта: ' . ($title !== '' ? $title : $warehouseCode),
		'DESCRIPTION' => 'Автосозданный склад для импорта остатков (' . $warehouseCode . ')',
		'XML_ID' => $xmlId,
		'CODE' => $code,
	]);
	if (!$res->isSuccess())
	{
		throw new RuntimeException("Не удалось создать склад '$warehouseCode': " . implode('; ', $res->getErrorMessages()));
	}
	return [(int)$res->getId(), $xmlId];
}

function getOrCreatePriceGroupIdByStoreXmlId(string $storeXmlId, string $titleFallback, bool $create): int
{
	$name = mb_strtoupper(trim($storeXmlId));
	if ($name === '') throw new RuntimeException('Пустой storeXmlId');

	if (function_exists('mf_catalog_group_id_by_store_xml_candidates'))
	{
		$found = mf_catalog_group_id_by_store_xml_candidates($storeXmlId);
		if ($found > 0)
		{
			return $found;
		}
	}
	else
	{
		$rs = CCatalogGroup::GetList([], ['=NAME' => $name], false, false, ['ID', 'NAME']);
		if ($r = $rs->Fetch())
		{
			return (int)$r['ID'];
		}
	}

	if (!$create)
	{
		return 0;
	}

	$cg = new CCatalogGroup();
	$id = $cg->Add([
		'NAME' => $name,
		'BASE' => 'N',
		'SORT' => 2000,
		'USER_GROUP' => [2], // all users
		'USER_GROUP_BUY' => [2],
		'LANG' => [
			'ru' => ['NAME' => $titleFallback],
			'en' => ['NAME' => $titleFallback],
		],
	]);
	if (!$id)
	{
		throw new RuntimeException("Не удалось создать тип цены '$name': " . $cg->LAST_ERROR);
	}
	return (int)$id;
}

function getSupplierStoreToPriceGroupMap(bool $create): array
{
	$map = []; // storeId => priceGroupId
	$rs = CCatalogStore::GetList(['ID' => 'ASC'], ['%XML_ID' => 'SUPPLIER_'], false, false, ['ID', 'XML_ID', 'TITLE']);
	while ($s = $rs->Fetch())
	{
		$storeId = (int)$s['ID'];
		$xmlId = (string)($s['XML_ID'] ?? '');
		if ($storeId <= 0 || $xmlId === '') continue;
		$gid = getOrCreatePriceGroupIdByStoreXmlId($xmlId, (string)($s['TITLE'] ?? $xmlId), $create);
		if ($gid > 0)
		{
			$map[$storeId] = $gid;
		}
	}
	return $map;
}

function findCanonicalProductIdByNorm(int $iblockId, string $norm): ?int
{
	$r = CIBlockElement::GetList(
		[],
		[
			'IBLOCK_ID' => $iblockId,
			'=PROPERTY_MF_ARTICLE_NORM' => $norm,
			'!PROPERTY_MF_IS_REDIRECT' => 'Y',
		],
		false,
		false,
		['ID']
	)->Fetch();
	return $r ? (int)$r['ID'] : null;
}

function findCanonicalProductIdByArticleBrand(int $iblockId, string $articleNorm, string $brandRaw, string $brandNorm): ?int
{
	$articleNorm = trim($articleNorm);
	$brandRaw = trim($brandRaw);
	$brandNorm = trim($brandNorm);
	if ($articleNorm === '') return null;

	$filter = [
		'IBLOCK_ID' => $iblockId,
		'=PROPERTY_MF_ARTICLE_NORM' => $articleNorm,
		'!PROPERTY_MF_IS_REDIRECT' => 'Y',
	];

	// If brand is provided in CSV, do NOT fallback to article-only.
	$brandProvided = ($brandNorm !== '' || $brandRaw !== '');

	// Fast path: exact match by MF_UNIQ_KEY (indexable) instead of LIKE searches.
	if ($brandProvided && $brandNorm !== '')
	{
		$uniqKey = makeUniqKey($articleNorm, $brandNorm);
		$fast = findCanonicalProductIdByUniqKey($iblockId, $uniqKey);
		if ($fast && $fast > 0) return $fast;
	}

	// 1) Try brand_norm contains brandNorm (handles existing "грязные" brand_norm that still include YAMAHA...)
	if ($brandNorm !== '')
	{
		$r = CIBlockElement::GetList([], $filter + ['%PROPERTY_MF_BRAND_NORM' => $brandNorm], false, false, ['ID'])->Fetch();
		if ($r) return (int)$r['ID'];
	}

	// 2) Try brand text contains raw brand (handles MF_BRAND like "Yamaha ...")
	if ($brandRaw !== '')
	{
		$r = CIBlockElement::GetList([], $filter + ['%PROPERTY_MF_BRAND' => $brandRaw], false, false, ['ID'])->Fetch();
		if ($r) return (int)$r['ID'];
	}

	if ($brandProvided)
	{
		return null;
	}

	// No brand column → legacy behavior: by article only
	return findCanonicalProductIdByNorm($iblockId, $articleNorm);
}

function upsertPrice(int $productId, int $priceGroupId, float $price): void
{
	if ($price <= 0) return;

	$rs = CPrice::GetList(
		[],
		['PRODUCT_ID' => $productId, 'CATALOG_GROUP_ID' => $priceGroupId],
		false,
		false,
		['ID', 'PRICE', 'CURRENCY']
	);
	if ($p = $rs->Fetch())
	{
		CPrice::Update((int)$p['ID'], ['PRICE' => $price, 'CURRENCY' => 'RUB']);
	}
	else
	{
		CPrice::Add(['PRODUCT_ID' => $productId, 'CATALOG_GROUP_ID' => $priceGroupId, 'PRICE' => $price, 'CURRENCY' => 'RUB']);
	}
}

function upsertStoreAmount(int $productId, int $storeId, float $amount): void
{
	$rs = CCatalogStoreProduct::GetList(
		[],
		['PRODUCT_ID' => $productId, 'STORE_ID' => $storeId],
		false,
		false,
		['ID', 'AMOUNT']
	);
	if ($row = $rs->Fetch())
	{
		CCatalogStoreProduct::Update((int)$row['ID'], ['AMOUNT' => $amount]);
	}
	else
	{
		CCatalogStoreProduct::Add(['PRODUCT_ID' => $productId, 'STORE_ID' => $storeId, 'AMOUNT' => $amount]);
	}
}

function sumAllStoresAmount(int $productId): float
{
	$sum = 0.0;
	$rs = CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $productId], false, false, ['AMOUNT']);
	while ($r = $rs->Fetch())
	{
		$sum += (float)$r['AMOUNT'];
	}
	return $sum;
}

function setBasePrice(int $productId, float $price): void
{
	if ($price <= 0) return;
	CPrice::SetBasePrice($productId, $price, 'RUB');
}

function recalcBasePriceFromAvailableStores(int $productId, array $storeToGroup): ?float
{
	if (function_exists('mf_calc_store_price'))
	{
		$min = null;
		$rsS = CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $productId], false, false, ['STORE_ID', 'AMOUNT']);
		while ($sp = $rsS->Fetch())
		{
			$storeId = (int)$sp['STORE_ID'];
			$amount = (float)$sp['AMOUNT'];
			if ($amount <= 0)
			{
				continue;
			}
			if (!isset($storeToGroup[$storeId]))
			{
				continue;
			}
			$c = mf_calc_store_price($productId, $storeId);
			if ($c === null || $c <= 0)
			{
				continue;
			}
			if ($min === null || $c < $min)
			{
				$min = $c;
			}
		}

		return $min;
	}

	$prices = [];
	$rsP = CPrice::GetList([], ['PRODUCT_ID' => $productId], false, false, ['CATALOG_GROUP_ID', 'PRICE']);
	while ($p = $rsP->Fetch())
	{
		$gid = (int)$p['CATALOG_GROUP_ID'];
		$prices[$gid] = (float)$p['PRICE'];
	}

	$min = null;
	$rsS = CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $productId], false, false, ['STORE_ID', 'AMOUNT']);
	while ($sp = $rsS->Fetch())
	{
		$storeId = (int)$sp['STORE_ID'];
		$amount = (float)$sp['AMOUNT'];
		if ($amount <= 0) continue;
		if (!isset($storeToGroup[$storeId])) continue;
		$gid = (int)$storeToGroup[$storeId];
		$raw = (float)($prices[$gid] ?? 0);
		if ($raw <= 0) continue;

		$markupPct = getStoreMarkupPct($storeId);
		$computed = applyMarkup($raw, $markupPct);
		if ($computed <= 0) continue;
		if ($min === null || $computed < $min) $min = $computed;
	}

	return $min;
}

/**
 * Batch recalc helper: compute min BASE price for multiple products using SQL joins.
 * This is much faster than calling CPrice/CCatalogStoreProduct per row.
 *
 * Returns map: productId => minComputedPrice
 */
function recalcBasePricesSql(array $productIds, array $storeToGroup): array
{
	$out = [];
	if (empty($productIds) || empty($storeToGroup)) return $out;

	if (function_exists('mf_calc_store_price'))
	{
		foreach ($productIds as $pid)
		{
			$pid = (int)$pid;
			if ($pid <= 0)
			{
				continue;
			}
			$min = null;
			$rsS = CCatalogStoreProduct::GetList(
				[],
				['PRODUCT_ID' => $pid, '>AMOUNT' => 0],
				false,
				false,
				['STORE_ID']
			);
			while ($sp = $rsS->Fetch())
			{
				$storeId = (int)$sp['STORE_ID'];
				if (!isset($storeToGroup[$storeId]))
				{
					continue;
				}
				$c = mf_calc_store_price($pid, $storeId);
				if ($c === null || $c <= 0)
				{
					continue;
				}
				if ($min === null || $c < $min)
				{
					$min = $c;
				}
			}
			if ($min !== null)
			{
				$out[$pid] = $min;
			}
		}

		return $out;
	}

	$conn = mf_supplier_stock_log_conn(); // Application::getConnection()
	if (!$conn) return $out;

	$parts = [];
	foreach ($storeToGroup as $storeId => $groupId)
	{
		$storeId = (int)$storeId;
		$groupId = (int)$groupId;
		if ($storeId <= 0 || $groupId <= 0) continue;
		$markup = (float)getStoreMarkupPct($storeId);
		if (!is_finite($markup)) $markup = 0.0;
		$markupSql = str_replace(',', '.', (string)round($markup, 6));
		$parts[] = "SELECT {$storeId} AS STORE_ID, {$groupId} AS GID, {$markupSql} AS MARKUP";
	}
	if (empty($parts)) return $out;
	$mapSql = '(' . implode(' UNION ALL ', $parts) . ')';

	$ids = [];
	foreach ($productIds as $pid)
	{
		$pid = (int)$pid;
		if ($pid > 0) $ids[$pid] = true;
	}
	$ids = array_keys($ids);
	if (empty($ids)) return $out;

	$chunkSize = 800;
	for ($i = 0; $i < count($ids); $i += $chunkSize)
	{
		$chunk = array_slice($ids, $i, $chunkSize);
		$in = implode(',', array_map('intval', $chunk));
		if ($in === '') continue;

		$sql = "
SELECT
  sp.PRODUCT_ID AS PID,
  MIN(ROUND(p.PRICE * (1 + (m.MARKUP / 100)), 2)) AS MIN_PRICE
FROM b_catalog_store_product sp
INNER JOIN {$mapSql} m ON m.STORE_ID = sp.STORE_ID
INNER JOIN b_catalog_price p ON p.PRODUCT_ID = sp.PRODUCT_ID AND p.CATALOG_GROUP_ID = m.GID
WHERE sp.PRODUCT_ID IN ({$in})
  AND sp.AMOUNT > 0
  AND p.PRICE > 0
GROUP BY sp.PRODUCT_ID
";

		$rs = $conn->query($sql);
		while ($r = $rs->fetch())
		{
			$pid = (int)($r['PID'] ?? $r['pid'] ?? 0);
			$min = (float)($r['MIN_PRICE'] ?? $r['min_price'] ?? 0);
			if ($pid > 0 && $min > 0)
			{
				$out[$pid] = $min;
			}
		}
	}

	return $out;
}

function ensureMissingHl(bool $create): ?array
{
	// Highload-block for "ненайденные товары" (для дальнейшей обработки в админке).
	$table = 'mf_stock_import_missing';
	$hl = HighloadBlockTable::getList([
		'filter' => ['=TABLE_NAME' => $table],
		'select' => ['ID', 'NAME', 'TABLE_NAME'],
		'limit' => 1,
	])->fetch();

	if (!$hl && !$create) return null;

	if (!$hl)
	{
		$res = HighloadBlockTable::add([
			'NAME' => 'MfStockImportMissing',
			'TABLE_NAME' => $table,
		]);
		if (!$res->isSuccess())
		{
			throw new RuntimeException("Не удалось создать HL-блок для ненайденных товаров: " . implode('; ', $res->getErrorMessages()));
		}
		$hl = ['ID' => (int)$res->getId(), 'NAME' => 'MfStockImportMissing', 'TABLE_NAME' => $table];
	}

	$hlId = (int)$hl['ID'];
	$entityId = 'HLBLOCK_' . $hlId;
	$ut = new CUserTypeEntity();

	$ensureUf = static function (string $fieldName, string $userTypeId, array $labels, int $sort) use ($ut, $entityId): void {
		$exists = CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName])->Fetch();
		if ($exists) return;
		$id = $ut->Add([
			'ENTITY_ID' => $entityId,
			'FIELD_NAME' => $fieldName,
			'USER_TYPE_ID' => $userTypeId,
			'XML_ID' => $fieldName,
			'SORT' => $sort,
			'MULTIPLE' => 'N',
			'MANDATORY' => 'N',
			'SHOW_FILTER' => 'I',
			'SHOW_IN_LIST' => 'Y',
			'EDIT_IN_LIST' => 'Y',
			'IS_SEARCHABLE' => 'Y',
			'EDIT_FORM_LABEL' => $labels,
			'LIST_COLUMN_LABEL' => $labels,
			'LIST_FILTER_LABEL' => $labels,
		]);
		if (!$id)
		{
			throw new RuntimeException("Не удалось создать UF поле $fieldName для HL-блока (ненайденные товары): " . $ut->LAST_ERROR);
		}
	};

	$ensureUf('UF_WAREHOUSE_XML_ID', 'string', ['ru' => 'XML_ID склада', 'en' => 'Warehouse XML_ID'], 100);
	$ensureUf('UF_WAREHOUSE_TITLE', 'string', ['ru' => 'Название склада', 'en' => 'Warehouse title'], 110);
	$ensureUf('UF_UNIQ_KEY', 'string', ['ru' => 'Ключ (бренд+артикул)', 'en' => 'Key (brand+article)'], 120);
	$ensureUf('UF_BRAND', 'string', ['ru' => 'Бренд', 'en' => 'Brand'], 130);
	$ensureUf('UF_ARTICLE', 'string', ['ru' => 'Артикул', 'en' => 'Article'], 140);
	$ensureUf('UF_NAME', 'string', ['ru' => 'Название товара (из CSV)', 'en' => 'Product name (from CSV)'], 145);
	$ensureUf('UF_QTY', 'double', ['ru' => 'Остаток', 'en' => 'Stock'], 150);
	$ensureUf('UF_PRICE', 'double', ['ru' => 'Цена', 'en' => 'Price'], 160);
	$ensureUf('UF_LAST_SEEN', 'datetime', ['ru' => 'Последнее появление', 'en' => 'Last seen'], 170);
	$ensureUf('UF_FIRST_SEEN', 'datetime', ['ru' => 'Первое появление', 'en' => 'First seen'], 180);

	$entity = HighloadBlockTable::compileEntity($hl);
	return ['DATA_CLASS' => $entity->getDataClass()];
}

function upsertMissing(
	?array $hl,
	string $warehouseXmlId,
	string $warehouseTitle,
	string $uniqKey,
	string $brand,
	string $article,
	float $qty,
	float $price,
	string $name = ''
): void
{
	if (!$hl) return;
	$dataClass = $hl['DATA_CLASS'];
	$now = new DateTime();

	$filter = ['=UF_WAREHOUSE_XML_ID' => $warehouseXmlId, '=UF_UNIQ_KEY' => $uniqKey];
	$existing = $dataClass::getList(['filter' => $filter, 'select' => ['ID', 'UF_FIRST_SEEN'], 'limit' => 1])->fetch();

	$fields = [
		'UF_WAREHOUSE_XML_ID' => $warehouseXmlId,
		'UF_WAREHOUSE_TITLE' => $warehouseTitle,
		'UF_UNIQ_KEY' => $uniqKey,
		'UF_BRAND' => $brand,
		'UF_ARTICLE' => $article,
		'UF_NAME' => $name,
		'UF_QTY' => $qty,
		'UF_PRICE' => $price,
		'UF_LAST_SEEN' => $now,
	];

	if ($existing)
	{
		$dataClass::update((int)$existing['ID'], $fields);
	}
	else
	{
		$fields['UF_FIRST_SEEN'] = $now;
		$dataClass::add($fields);
	}
}

function deleteMissing(?array $hl, string $warehouseXmlId, string $uniqKey): void
{
	if (!$hl) return;
	$dataClass = $hl['DATA_CLASS'];
	$existing = $dataClass::getList([
		'filter' => ['=UF_WAREHOUSE_XML_ID' => $warehouseXmlId, '=UF_UNIQ_KEY' => $uniqKey],
		'select' => ['ID'],
		'limit' => 1,
	])->fetch();
	if ($existing)
	{
		$dataClass::delete((int)$existing['ID']);
	}
}

function mf_missing_bulk_upsert(\Bitrix\Main\DB\Connection $conn, array $rows): void
{
	if (empty($rows)) return;
	$h = $conn->getSqlHelper();
	$now = date('Y-m-d H:i:s');
	$vals = [];
	foreach ($rows as $r)
	{
		$vals[] = '('
			. "'" . $h->forSql((string)$r['UF_WAREHOUSE_XML_ID']) . "',"
			. "'" . $h->forSql((string)$r['UF_UNIQ_KEY']) . "',"
			. "'" . $h->forSql((string)($r['UF_WAREHOUSE_TITLE'] ?? '')) . "',"
			. "'" . $h->forSql((string)($r['UF_BRAND'] ?? '')) . "',"
			. "'" . $h->forSql((string)($r['UF_ARTICLE'] ?? '')) . "',"
			. "'" . $h->forSql((string)($r['UF_NAME'] ?? '')) . "',"
			. (float)($r['UF_QTY'] ?? 0) . ','
			. (float)($r['UF_PRICE'] ?? 0) . ','
			. "'" . $h->forSql($now) . "',"
			. "'" . $h->forSql($now) . "'"
			. ')';
	}

	$sql = "INSERT INTO mf_stock_import_missing
		(UF_WAREHOUSE_XML_ID, UF_UNIQ_KEY, UF_WAREHOUSE_TITLE, UF_BRAND, UF_ARTICLE, UF_NAME, UF_QTY, UF_PRICE, UF_LAST_SEEN, UF_FIRST_SEEN)
		VALUES " . implode(',', $vals) . "
		ON DUPLICATE KEY UPDATE
			UF_WAREHOUSE_TITLE = VALUES(UF_WAREHOUSE_TITLE),
			UF_BRAND = VALUES(UF_BRAND),
			UF_ARTICLE = VALUES(UF_ARTICLE),
			UF_NAME = VALUES(UF_NAME),
			UF_QTY = VALUES(UF_QTY),
			UF_PRICE = VALUES(UF_PRICE),
			UF_LAST_SEEN = VALUES(UF_LAST_SEEN)";

	$conn->queryExecute($sql);
}

function mf_missing_bulk_delete(\Bitrix\Main\DB\Connection $conn, string $warehouseXmlId, array $uniqKeys): void
{
	$warehouseXmlId = trim($warehouseXmlId);
	if ($warehouseXmlId === '' || empty($uniqKeys)) return;
	$h = $conn->getSqlHelper();
	$wx = "'" . $h->forSql($warehouseXmlId) . "'";

	$keys = [];
	foreach ($uniqKeys as $k)
	{
		$k = trim((string)$k);
		if ($k === '') continue;
		$keys[$k] = true;
	}
	$keys = array_keys($keys);
	if (empty($keys)) return;

	$chunk = 400;
	for ($i = 0; $i < count($keys); $i += $chunk)
	{
		$part = array_slice($keys, $i, $chunk);
		$in = implode(',', array_map(static fn($s) => "'" . $h->forSql((string)$s) . "'", $part));
		if ($in === '') continue;
		$conn->queryExecute("DELETE FROM mf_stock_import_missing WHERE UF_WAREHOUSE_XML_ID={$wx} AND UF_UNIQ_KEY IN ({$in})");
	}
}

function mf_fast_update_catalog_product_qty(\Bitrix\Main\DB\Connection $conn, array $productIdToQty): void
{
	if (empty($productIdToQty)) return;
	$ids = [];
	foreach ($productIdToQty as $pid => $qty)
	{
		$pid = (int)$pid;
		if ($pid > 0) $ids[$pid] = true;
	}
	$ids = array_keys($ids);
	if (empty($ids)) return;

	$chunk = 300;
	for ($i = 0; $i < count($ids); $i += $chunk)
	{
		$part = array_slice($ids, $i, $chunk);
		$caseQty = "CASE ID ";
		$caseAvail = "CASE ID ";
		foreach ($part as $pid)
		{
			$q = (float)($productIdToQty[$pid] ?? 0);
			if ($q < 0) $q = 0;
			$caseQty .= " WHEN {$pid} THEN {$q} ";
			$caseAvail .= " WHEN {$pid} THEN " . ($q > 0 ? "'Y'" : "'N'") . " ";
		}
		$caseQty .= " ELSE QUANTITY END";
		$caseAvail .= " ELSE AVAILABLE END";
		$in = implode(',', array_map('intval', $part));
		if ($in === '') continue;

		$conn->queryExecute("
			UPDATE b_catalog_product
			SET
				QUANTITY = {$caseQty},
				AVAILABLE = {$caseAvail},
				QUANTITY_TRACE = 'Y',
				CAN_BUY_ZERO = 'N'
			WHERE ID IN ({$in})
		");
	}
}

try
{
	$scriptStartTs = microtime(true);
	$runStartedAtSql = mf_sql_dt($scriptStartTs);

	$iblockId = (int)(arg('--iblock-id') ?: 4);
	$warehouseCode = arg('--warehouse-code') ?: '';
	$warehouseTitle = arg('--warehouse-title') ?: '';
	$supplier = arg('--supplier') ?: '';
	$file = arg('--file') ?: '';
	$encoding = arg('--encoding') ?: 'cp1251';
	$apply = flag('--apply');
	$dry = flag('--dry-run') || !$apply;
	$usePrice = mf_bool((string)(arg('--price') ?: ''), 'N');
	$recalcBase = mf_bool((string)(arg('--recalc-base') ?: ''), 'Y');
	$syncMissing = mf_bool((string)(arg('--sync-missing') ?: ''), 'Y');
	$saveMissing = mf_bool((string)(arg('--save-missing') ?: ''), 'Y');
	$useBrandDict = mf_bool((string)(arg('--brand-dict') ?: ''), 'N');
	$ensureIndexes = mf_bool((string)(arg('--ensure-indexes') ?: ''), 'N');
	$fastProductUpdate = mf_bool((string)(arg('--fast-product-update') ?: ''), 'Y');

	// --- Run log (best-effort; script must work even if DB log fails) ---
	$runLogId = 0;
	$runLogEnabled = false;

	if ($warehouseCode === '' && $supplier !== '')
	{
		$warehouseCode = $supplier;
		if ($warehouseTitle === '') $warehouseTitle = $supplier;
	}
	if ($warehouseCode === '') throw new RuntimeException("Укажи --warehouse-code=CODE (или --supplier=NAME)");
	if ($file === '') throw new RuntimeException("Укажи --file=/path/file.csv");
	if (!file_exists($file)) throw new RuntimeException("Файл не найден: $file");

	if (mf_supplier_stock_log_ensure_table())
	{
		$runLogEnabled = true;
		try
		{
			$fileSize = (int)@filesize($file);
			$fileMtime = (int)@filemtime($file);
			$runLogId = mf_supplier_stock_log_insert([
				'UF_STARTED_AT' => $runStartedAtSql,
				'UF_STATUS' => 'running',
				'UF_WAREHOUSE_CODE' => $warehouseCode,
				'UF_WAREHOUSE_TITLE' => ($warehouseTitle !== '' ? $warehouseTitle : null),
				'UF_STORE_ID' => null,
				'UF_STORE_XML_ID' => null,
				'UF_INPUT_FILE' => $file,
				'UF_FILE_SIZE' => ($fileSize > 0 ? $fileSize : null),
				'UF_FILE_MTIME' => ($fileMtime > 0 ? date('Y-m-d H:i:s', $fileMtime) : null),
				'UF_ENCODING' => $encoding,
				'UF_MODE' => ($apply ? 'APPLY' : 'DRY-RUN'),
				'UF_PRICE_UPDATE' => ($usePrice ? 'Y' : 'N'),
				'UF_RECALC_BASE' => (($usePrice && $recalcBase) ? 'Y' : 'N'),
				'UF_SYNC_MISSING' => ($syncMissing ? 'Y' : 'N'),
				'UF_PHP_SAPI' => (string)php_sapi_name(),
				'UF_HOST' => (string)gethostname(),
				'UF_PID' => (int)getmypid(),
			]);
		}
		catch (Throwable $e)
		{
			$runLogEnabled = false;
			$runLogId = 0;
		}
	}

	out("=== MF SUPPLIER STOCK UPDATE ===");
	out("IBLOCK_ID: $iblockId");
	out("WAREHOUSE_CODE: $warehouseCode");
	if ($warehouseTitle !== '') out("WAREHOUSE_TITLE: $warehouseTitle");
	out("FILE: $file");
	out("ENCODING: $encoding");
	out("MODE: " . ($apply ? 'APPLY' : 'DRY-RUN'));
	out("PRICE_UPDATE: " . ($usePrice ? 'Y' : 'N'));
	out("RECALC_BASE: " . (($usePrice && $recalcBase) ? 'Y' : 'N'));
	out("SYNC_MISSING: " . ($syncMissing ? 'Y' : 'N'));
	out("SAVE_MISSING: " . ($saveMissing ? 'Y' : 'N'));
	out("BRAND_DICT: " . ($useBrandDict ? 'Y' : 'N'));
	out("ENSURE_INDEXES: " . ($ensureIndexes ? 'Y' : 'N'));
	out("FAST_PRODUCT_UPDATE: " . ($fastProductUpdate ? 'Y' : 'N'));

	if ($ensureIndexes)
	{
		mf_try_ensure_indexes($apply);
	}

	[$storeId, $storeXmlId] = getOrCreateStoreByCode($warehouseCode, $warehouseTitle, $apply);
	if ($storeId <= 0)
	{
		out("STORE_ID: отсутствует (dry-run). При --apply будет создан склад SUPPLIER_... по warehouse-code.");
	}
	else
	{
		out("STORE_ID: $storeId");
		out("STORE_XML_ID: $storeXmlId");
	}
	if ($runLogEnabled && $runLogId > 0)
	{
		mf_supplier_stock_log_update($runLogId, [
			'UF_STORE_ID' => ($storeId > 0 ? $storeId : null),
			'UF_STORE_XML_ID' => ($storeXmlId !== '' ? $storeXmlId : null),
		]);
	}

	$storeMarkupPct = ($storeId > 0) ? getStoreMarkupPct($storeId) : 0.0;
	if ($usePrice)
	{
		out("STORE_MARKUP_PCT: " . $storeMarkupPct);
	}

	// IMPORTANT: in dry-run we must not create new price groups.
	$storeToGroup = $usePrice ? getSupplierStoreToPriceGroupMap($apply) : [];
	$priceGroupId = ($usePrice && $storeId > 0) ? (int)($storeToGroup[$storeId] ?? 0) : 0;
	if ($usePrice && $priceGroupId <= 0)
	{
		if ($dry)
		{
			out("PRICE_GROUP_ID: отсутствует (dry-run). При --apply будет создан тип цены под XML_ID склада поставщика.");
		}
		else
		{
			throw new RuntimeException("Не удалось определить тип цены для склада поставщика (storeId=$storeId)");
		}
	}
	if ($usePrice && $priceGroupId > 0)
	{
		out("PRICE_GROUP_ID(for supplier): $priceGroupId");
	}

	$h = fopen($file, 'r');
	if (!$h) throw new RuntimeException("Не удалось открыть CSV");
	$fileSize = (int)@filesize($file);

	$headers = fgetcsv($h, 0, ';');
	if (!$headers) throw new RuntimeException("Пустой CSV");
	$headers = array_map(static fn($v) => mf_toUtf8($v, $encoding), $headers);

	$idxBrand = null;
	$idxArt = null;
	$idxName = null;
	$idxQty = null;
	$idxPrice = null;
	foreach ($headers as $i => $hdr)
	{
		$h2 = mb_strtolower(trim($hdr));
		if (in_array($h2, ['бренд', 'brand', 'производитель', 'manufacturer', 'vendor'], true)) $idxBrand = $i;
		if (in_array($h2, ['артикул', 'article', 'sku'], true)) $idxArt = $i;
		if (in_array($h2, ['название', 'наименование', 'товар', 'product', 'name', 'title'], true)) $idxName = $i;
		if (in_array($h2, ['остаток', 'количество', 'кол-во', 'qty', 'stock'], true)) $idxQty = $i;
		if (in_array($h2, ['цена', 'price', 'стоимость'], true)) $idxPrice = $i;
	}
	if ($idxArt === null || $idxQty === null)
	{
		throw new RuntimeException("Не найдены колонки Артикул/Остаток");
	}

	$missingHl = ($saveMissing ? ensureMissingHl($apply) : null);
	$total = 0;
	$updated = 0;
	$notFound = 0;
	$errors = 0;
	$zeroedMissing = 0;
	$seenProducts = [];
	$touchedProducts = [];
	$recalcBaseProducts = [];
	$errorItems = [];
	$errorItemsSet = [];
	$notFoundItems = [];
	$notFoundItemsSet = [];
	$progressEvery = 200;
	$lastProgressAt = microtime(true);
	$loopStartTs = microtime(true);

	// Prepare optional brand dictionary once (do NOT require inside the loop).
	$brandDictReady = false;
	if ($useBrandDict && is_file(__DIR__ . '/mf_brand_dict.php'))
	{
		require_once __DIR__ . '/mf_brand_dict.php';
		$brandDictReady = function_exists('mf_brand_find');
	}

	$missingBuf = [];
	$missingBufMax = 500;
	$missingDeleteBuf = [];
	$missingDeleteBufMax = 800;
	$conn = mf_supplier_stock_log_conn();

	while (($row = fgetcsv($h, 0, ';')) !== false)
	{
		$total++;
		$row = array_map(static fn($v) => mf_toUtf8($v, $encoding), $row);
		$brandRaw = $idxBrand !== null ? trim((string)($row[$idxBrand] ?? '')) : '';
		// allow comments in demo CSV
		if ($brandRaw !== '' && str_starts_with($brandRaw, '#')) continue;
		$nameRaw = $idxName !== null ? trim((string)($row[$idxName] ?? '')) : '';
		$artRaw = trim((string)($row[$idxArt] ?? ''));
		if ($artRaw !== '' && str_starts_with($artRaw, '#')) continue;
		if ($artRaw === '') continue;

		$articleNorm = normalizeArticle($artRaw);
		if ($articleNorm === '') continue;
		if ($useBrandDict && $brandDictReady && $brandRaw !== '')
		{
			$canon = (string)mf_brand_find($brandRaw, true);
			if ($canon !== '') $brandRaw = $canon;
		}
		$brandNorm = $brandRaw !== '' ? normalizeBrand($brandRaw) : '';
		$uniqKey = makeUniqKey($articleNorm, $brandNorm);

		$qty = (float)str_replace(',', '.', (string)($row[$idxQty] ?? '0'));
		if ($qty < 0) $qty = 0;

		$priceRaw = null;
		if ($usePrice && $idxPrice !== null)
		{
			$priceRaw = (float)str_replace(',', '.', (string)($row[$idxPrice] ?? '0'));
		}
		// IMPORTANT: store price group keeps RAW import price (no markup here).
		$price = ($priceRaw !== null) ? (float)$priceRaw : null;

		$productId = findCanonicalProductIdByArticleBrand($iblockId, $articleNorm, $brandRaw, $brandNorm);
		if ($productId && function_exists('mf_ep_resolve_catalog_trade_product_id'))
		{
			$resolved = mf_ep_resolve_catalog_trade_product_id((int)$productId);
			if ($resolved > 0)
			{
				$productId = $resolved;
			}
		}
		if (!$productId)
		{
			$notFound++;
			$keyNF = $brandRaw . ';' . $artRaw;
			if (!isset($notFoundItemsSet[$keyNF]))
			{
				$notFoundItemsSet[$keyNF] = true;
				if (count($notFoundItems) < 5000) $notFoundItems[] = $keyNF;
			}
			if ($saveMissing && !$dry && $storeXmlId !== '' && $conn)
			{
				$missingBuf[] = [
					'UF_WAREHOUSE_XML_ID' => $storeXmlId,
					'UF_WAREHOUSE_TITLE' => ($warehouseTitle !== '' ? $warehouseTitle : $warehouseCode),
					'UF_UNIQ_KEY' => $uniqKey,
					'UF_BRAND' => $brandRaw,
					'UF_ARTICLE' => $artRaw,
					'UF_NAME' => $nameRaw,
					'UF_QTY' => $qty,
					'UF_PRICE' => ($priceRaw !== null ? (float)$priceRaw : 0.0),
				];
				if (count($missingBuf) >= $missingBufMax)
				{
					mf_missing_bulk_upsert($conn, $missingBuf);
					$missingBuf = [];
				}
			}
			continue;
		}

		$seenProducts[$productId] = true;

		if ($dry)
		{
			$updated++;
			continue;
		}

		try
		{
			upsertStoreAmount($productId, $storeId, $qty);
			$touchedProducts[$productId] = true;
			if ($saveMissing && $storeXmlId !== '' && $conn)
			{
				$missingDeleteBuf[] = $uniqKey;
				if (count($missingDeleteBuf) >= $missingDeleteBufMax)
				{
					mf_missing_bulk_delete($conn, $storeXmlId, $missingDeleteBuf);
					$missingDeleteBuf = [];
				}
			}
			if ($price !== null && $price > 0 && $usePrice)
			{
				upsertPrice($productId, $priceGroupId, $price);
			}
			// Defer BASE recalculation until the end (much faster on large files).
			if ($usePrice && $recalcBase)
			{
				$recalcBaseProducts[$productId] = true;
			}
			$updated++;
		}
		catch (Throwable $e)
		{
			$errors++;
			$keyErr = $brandRaw . ';' . $artRaw;
			if (!isset($errorItemsSet[$keyErr]))
			{
				$errorItemsSet[$keyErr] = true;
				if (count($errorItems) < 5000) $errorItems[] = $keyErr;
			}
		}

		$now = microtime(true);
		if (($total % $progressEvery) === 0 || ($now - $lastProgressAt) >= 0.5)
		{
			$pct = 0.0;
			$extra = "rows=$total updated=$updated notFound=$notFound errors=$errors";
			if ($fileSize > 0)
			{
				$pos = (int)ftell($h);
				$pct = mf_pct((float)$pos, (float)$fileSize);

				$elapsed = max(0.001, $now - $loopStartTs);
				$bytesPerSec = $pos / $elapsed;
				$rowsPerSec = $total / $elapsed;
				$eta = null;
				if ($bytesPerSec > 1)
				{
					$eta = (int)round(((float)max(0, $fileSize - $pos)) / $bytesPerSec);
				}

				$extra .= sprintf(" speed=%.0f r/s", $rowsPerSec);
				if ($eta !== null)
				{
					$extra .= " eta=" . mf_fmt_eta($eta);
				}
			}
			mf_progress('SUPPLIER', $pct, $extra);
			$lastProgressAt = $now;
		}
	}
	fclose($h);
	mf_progressDone();

	// Flush missing buffers
	if ($conn && !empty($missingBuf))
	{
		mf_missing_bulk_upsert($conn, $missingBuf);
		$missingBuf = [];
	}
	if ($conn && $saveMissing && $storeXmlId !== '' && !empty($missingDeleteBuf))
	{
		mf_missing_bulk_delete($conn, $storeXmlId, $missingDeleteBuf);
		$missingDeleteBuf = [];
	}

	// If a product is missing in the supplier file, treat it as out of stock on this warehouse.
	// This prevents stale quantities from keeping items "in stock".
	if ($apply && !$dry && $syncMissing && $storeId > 0)
	{
		$rsStale = CCatalogStoreProduct::GetList(
			[],
			['STORE_ID' => $storeId, '>AMOUNT' => 0],
			false,
			false,
			['ID', 'PRODUCT_ID', 'AMOUNT']
		);
		while ($sp = $rsStale->Fetch())
		{
			$pid = (int)$sp['PRODUCT_ID'];
			if ($pid <= 0) continue;
			if (isset($seenProducts[$pid])) continue;

			try
			{
				CCatalogStoreProduct::Update((int)$sp['ID'], ['AMOUNT' => 0]);
				$touchedProducts[$pid] = true;
				if ($usePrice && $recalcBase)
				{
					$recalcBaseProducts[$pid] = true;
				}
				$zeroedMissing++;
			}
			catch (Throwable $e)
			{
				$errors++;
			}
		}
	}

	// Batch update QUANTITY/AVAILABLE after all store updates.
	if ($apply && !$dry && $fastProductUpdate && !empty($touchedProducts))
	{
		$ids = array_keys($touchedProducts);
		$totalQty = count($ids);
		out("RECALC_QTY_POST: products=$totalQty");

		$qtyMap = array_fill_keys($ids, 0.0);
		if ($conn)
		{
			$chunk = 800;
			for ($i = 0; $i < count($ids); $i += $chunk)
			{
				$part = array_slice($ids, $i, $chunk);
				$in = implode(',', array_map('intval', $part));
				if ($in === '') continue;
				$rs = $conn->query("
					SELECT PRODUCT_ID, SUM(AMOUNT) AS QTY
					FROM b_catalog_store_product
					WHERE PRODUCT_ID IN ({$in})
					GROUP BY PRODUCT_ID
				");
				while ($r = $rs->fetch())
				{
					$pid = (int)($r['PRODUCT_ID'] ?? 0);
					$q = (float)($r['QTY'] ?? 0);
					if ($pid > 0) $qtyMap[$pid] = max(0.0, $q);
				}
			}

			mf_fast_update_catalog_product_qty($conn, $qtyMap);
		}
	}

	// Recalculate BASE price once, after all updates (and sync-missing zeroing) are done.
	// This is dramatically faster than recalculating per CSV row on large suppliers.
	if ($apply && !$dry && $usePrice && $recalcBase && !empty($recalcBaseProducts))
	{
		$recalcIds = array_keys($recalcBaseProducts);
		$done = 0;
		$totalRecalc = count($recalcIds);
		out("RECALC_BASE_POST: products=$totalRecalc");

		$minMap = [];
		try
		{
			$minMap = recalcBasePricesSql($recalcIds, $storeToGroup);
		}
		catch (Throwable $e)
		{
			$minMap = [];
		}

		if (!empty($minMap))
		{
			foreach ($minMap as $pid => $min)
			{
				setBasePrice((int)$pid, (float)$min);
				$done++;
				if (($done % 500) === 0)
				{
					mf_progress('BASE', mf_pct((float)$done, (float)$totalRecalc), "done=$done of $totalRecalc");
				}
			}
			mf_progressDone();
		}
		else
		{
			// Fallback: slower per-product recalc (should not happen on MySQL).
			foreach ($recalcIds as $pid)
			{
				$min = recalcBasePriceFromAvailableStores((int)$pid, $storeToGroup);
				if ($min !== null)
				{
					setBasePrice((int)$pid, (float)$min);
				}
				$done++;
				if (($done % 200) === 0)
				{
					mf_progress('BASE', mf_pct((float)$done, (float)$totalRecalc), "done=$done of $totalRecalc (fallback)");
				}
			}
			mf_progressDone();
		}
	}

	out("DONE total=$total updated=$updated notFound=$notFound zeroedMissing=$zeroedMissing errors=$errors");

	// finalize run log
	if ($runLogEnabled && $runLogId > 0)
	{
		$durationMs = (int)round((microtime(true) - $scriptStartTs) * 1000.0);
		$memPeakMb = memory_get_peak_usage(true) / 1024 / 1024;
		mf_supplier_stock_log_update($runLogId, [
			'UF_FINISHED_AT' => mf_sql_dt(),
			'UF_DURATION_MS' => $durationMs,
			'UF_STATUS' => 'ok',
			'UF_TOTAL' => (int)$total,
			'UF_UPDATED' => (int)$updated,
			'UF_NOT_FOUND' => (int)$notFound,
			'UF_ZEROED' => (int)$zeroedMissing,
			'UF_ERRORS' => (int)$errors,
			'UF_ERROR_ITEMS' => (!empty($errorItems) ? implode("\n", $errorItems) : null),
			'UF_NOT_FOUND_ITEMS' => (!empty($notFoundItems) ? implode("\n", $notFoundItems) : null),
			'UF_MEMORY_PEAK_MB' => (float)$memPeakMb,
		]);
	}
}
catch (Throwable $e)
{
	// Try to persist a failed run log (if logging was enabled and run already inserted).
	if (isset($runLogEnabled, $runLogId) && $runLogEnabled && (int)$runLogId > 0)
	{
		$durationMs = isset($scriptStartTs) ? (int)round((microtime(true) - (float)$scriptStartTs) * 1000.0) : null;
		$memPeakMb = memory_get_peak_usage(true) / 1024 / 1024;
		mf_supplier_stock_log_update((int)$runLogId, [
			'UF_FINISHED_AT' => mf_sql_dt(),
			'UF_DURATION_MS' => $durationMs,
			'UF_STATUS' => 'failed',
			'UF_NOTE' => mb_substr((string)$e->getMessage(), 0, 250),
			'UF_MEMORY_PEAK_MB' => (float)$memPeakMb,
		]);
	}

	out("ОШИБКА: " . $e->getMessage());
	fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
	exit(1);
}

