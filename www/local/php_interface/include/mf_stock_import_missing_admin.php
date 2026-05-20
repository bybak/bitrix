<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Catalog\StoreTable;

if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true)
{
	die('Admin only');
}

global $APPLICATION, $USER;

if (!is_object($USER) || !$USER->IsAdmin())
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Недостаточно прав.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

if (!class_exists(Application::class))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Bitrix\\Main\\Application недоступен.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

function mf_miss_escape(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mf_miss_table_exists(): bool
{
	try
	{
		$conn = Application::getConnection();
		$driver = method_exists($conn, 'getType') ? (string)$conn->getType() : '';
		if ($driver !== '' && stripos($driver, 'mysql') === false)
		{
			return false;
		}
		$r = $conn->query("SHOW TABLES LIKE 'mf_stock_import_missing'")->fetch();
		return (bool)$r;
	}
	catch (\Throwable $e)
	{
		return false;
	}
}

if (!mf_miss_table_exists())
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	$APPLICATION->SetTitle('Ненайденные товары (импорт складов)');
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Таблица не найдена: mf_stock_import_missing.']);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

$iblockId = 4;

// Reuse stub creation helpers (brand/article normalization, unique CODE generator).
$mfAnalogsLib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_import_analogs_lib.php';
if (is_file($mfAnalogsLib) && !function_exists('mf_analogs_generate_unique_code'))
{
	require_once $mfAnalogsLib;
}

function mf_miss_ensure_section(int $iblockId, string $name, int $parentId = 0): int
{
	$iblockId = (int)$iblockId;
	$parentId = (int)$parentId;
	$name = trim($name);
	if ($iblockId <= 0 || $name === '' || !class_exists(\CIBlockSection::class))
	{
		return 0;
	}

	$codeBase = $name;
	if (function_exists('mf_analogs_norm_brand'))
	{
		$bn = mf_analogs_norm_brand($name);
		if ($bn !== '')
		{
			$codeBase = $bn;
		}
	}

	$code = class_exists('CUtil')
		? (string)\CUtil::translit($codeBase, 'ru', [
			'change_case' => 'L',
			'replace_space' => '-',
			'replace_other' => '-',
			'delete_repeat_replace' => true,
			'use_google' => false,
		])
		: strtolower(preg_replace('~[^a-z0-9]+~i', '-', $codeBase) ?? $codeBase);
	$code = trim((string)$code, '-');
	if ($code === '')
	{
		$code = 'misc';
	}

	$filter = [
		'IBLOCK_ID' => $iblockId,
		'IBLOCK_SECTION_ID' => ($parentId > 0 ? $parentId : false),
		'=CODE' => $code,
	];
	$exist = \CIBlockSection::GetList(['ID' => 'ASC'], $filter, false, ['ID'], ['nTopCount' => 1])->Fetch();
	if ($exist && (int)$exist['ID'] > 0)
	{
		return (int)$exist['ID'];
	}

	$sec = new \CIBlockSection();
	$newId = (int)$sec->Add([
		'IBLOCK_ID' => $iblockId,
		'ACTIVE' => 'Y',
		'NAME' => $name,
		'CODE' => $code,
		'IBLOCK_SECTION_ID' => ($parentId > 0 ? $parentId : false),
	]);
	return $newId > 0 ? $newId : 0;
}

function mf_miss_find_product_id_by_uniq_key(int $iblockId, string $uniqKey): ?int
{
	$uniqKey = trim($uniqKey);
	if ($uniqKey === '' || !class_exists(\CIBlockElement::class))
	{
		return null;
	}
	$r = \CIBlockElement::GetList(
		[],
		[
			'=IBLOCK_ID' => $iblockId,
			'=ACTIVE' => 'Y',
			'=PROPERTY_MF_UNIQ_KEY' => $uniqKey,
		],
		false,
		['nTopCount' => 1],
		['ID']
	)->Fetch();
	return ($r && (int)$r['ID'] > 0) ? (int)$r['ID'] : null;
}

function mf_miss_has_column(\Bitrix\Main\DB\Connection $conn, string $col): bool
{
	static $cache = [];
	$col = trim($col);
	if ($col === '') return false;
	if (array_key_exists($col, $cache)) return (bool)$cache[$col];
	try
	{
		$r = $conn->query("SHOW COLUMNS FROM mf_stock_import_missing LIKE '" . $conn->getSqlHelper()->forSql($col) . "'")->fetch();
		$cache[$col] = (bool)$r;
		return (bool)$cache[$col];
	}
	catch (\Throwable $e)
	{
		$cache[$col] = false;
		return false;
	}
}

function mf_miss_find_store_row_by_xml_id(string $xmlId): ?array
{
	$xmlId = trim($xmlId);
	if ($xmlId === '' || !class_exists(Loader::class) || !Loader::includeModule('catalog'))
	{
		return null;
	}
	try
	{
		$row = StoreTable::getList([
			'filter' => ['=XML_ID' => $xmlId],
			'select' => ['ID', 'XML_ID', 'TITLE'],
			'limit' => 1,
		])->fetch();
		return $row ?: null;
	}
	catch (\Throwable $e)
	{
		return null;
	}
}

function mf_miss_get_store_markup_pct(int $storeId): float
{
	$storeId = (int)$storeId;
	if ($storeId <= 0)
	{
		return 0.0;
	}
	global $USER_FIELD_MANAGER;
	if (!is_object($USER_FIELD_MANAGER) || !class_exists(StoreTable::class))
	{
		return 0.0;
	}

	try
	{
		$ufs = $USER_FIELD_MANAGER->GetUserFields(StoreTable::getUfId(), $storeId);
		$v = $ufs['UF_MF_MARKUP_PCT']['VALUE'] ?? 0;
		if (is_array($v)) $v = reset($v);
		$v = str_replace(',', '.', (string)$v);
		$pct = (float)$v;
		return is_finite($pct) ? $pct : 0.0;
	}
	catch (\Throwable $e)
	{
		return 0.0;
	}
}

function mf_miss_apply_markup(float $rawPrice, float $pct): float
{
	if ($rawPrice <= 0) return 0.0;
	if ($pct == 0.0) return function_exists('mf_round_price') ? mf_round_price($rawPrice) : (float)ceil($rawPrice);
	return function_exists('mf_round_price') ? mf_round_price($rawPrice * (1.0 + ($pct / 100.0))) : (float)ceil($rawPrice * (1.0 + ($pct / 100.0)));
}

function mf_miss_get_or_create_price_group_id(string $storeXmlId, string $titleFallback): int
{
	$storeXmlId = mb_strtoupper(trim($storeXmlId));
	if ($storeXmlId === '' || !class_exists('CCatalogGroup'))
	{
		return 0;
	}

	$rs = \CCatalogGroup::GetList([], ['=NAME' => $storeXmlId], false, false, ['ID', 'NAME']);
	if ($r = $rs->Fetch())
	{
		return (int)$r['ID'];
	}

	$cg = new \CCatalogGroup();
	$id = $cg->Add([
		'NAME' => $storeXmlId,
		'BASE' => 'N',
		'SORT' => 2000,
		'USER_GROUP' => [2], // all users
		'USER_GROUP_BUY' => [2],
		'LANG' => [
			'ru' => ['NAME' => $titleFallback],
			'en' => ['NAME' => $titleFallback],
		],
	]);
	return $id ? (int)$id : 0;
}

function mf_miss_upsert_store_amount(int $productId, int $storeId, float $amount): void
{
	if ($productId <= 0 || $storeId <= 0 || !class_exists('CCatalogStoreProduct'))
	{
		return;
	}
	$amount = max(0.0, (float)$amount);

	$rs = \CCatalogStoreProduct::GetList(
		[],
		['PRODUCT_ID' => $productId, 'STORE_ID' => $storeId],
		false,
		false,
		['ID']
	);
	if ($row = $rs->Fetch())
	{
		\CCatalogStoreProduct::Update((int)$row['ID'], ['AMOUNT' => $amount]);
	}
	else
	{
		\CCatalogStoreProduct::Add(['PRODUCT_ID' => $productId, 'STORE_ID' => $storeId, 'AMOUNT' => $amount]);
	}
}

function mf_miss_upsert_price(int $productId, int $priceGroupId, float $price, string $currency = 'RUB'): void
{
	if ($productId <= 0 || $priceGroupId <= 0 || $price <= 0 || !class_exists('CPrice'))
	{
		return;
	}
	$currency = $currency !== '' ? $currency : 'RUB';

	$rs = \CPrice::GetList(
		[],
		['PRODUCT_ID' => $productId, 'CATALOG_GROUP_ID' => $priceGroupId],
		false,
		false,
		['ID']
	);
	if ($p = $rs->Fetch())
	{
		\CPrice::Update((int)$p['ID'], ['PRICE' => $price, 'CURRENCY' => $currency]);
	}
	else
	{
		\CPrice::Add(['PRODUCT_ID' => $productId, 'CATALOG_GROUP_ID' => $priceGroupId, 'PRICE' => $price, 'CURRENCY' => $currency]);
	}
}

function mf_miss_upsert_catalog_product(int $productId, float $qty): void
{
	if ($productId <= 0 || !class_exists('CCatalogProduct'))
	{
		return;
	}
	$qty = max(0.0, (float)$qty);
	$fields = [
		'ID' => $productId,
		'QUANTITY' => $qty,
		'AVAILABLE' => ($qty > 0 ? 'Y' : 'N'),
	];

	$existing = \CCatalogProduct::GetByID($productId);
	if (is_array($existing) && (int)($existing['ID'] ?? 0) > 0)
	{
		\CCatalogProduct::Update($productId, $fields);
	}
	else
	{
		\CCatalogProduct::Add($fields);
	}
}

$sTableID = 'tbl_mf_stock_import_missing';
$oSort = new \CAdminSorting($sTableID, 'UF_LAST_SEEN', 'desc');
$lAdmin = new \CAdminList($sTableID, $oSort);

// Filters
$filterFields = [
	'find_warehouse',
	'find_brand_sel',
	'find_brand',
];
$lAdmin->InitFilter($filterFields);

$find_warehouse = trim((string)($find_warehouse ?? ''));
$find_brand_sel = trim((string)($find_brand_sel ?? ''));
$find_brand = trim((string)($find_brand ?? ''));

$conn = Application::getConnection();
$h = $conn->getSqlHelper();

// Bitrix admin list filter stores values in session and (without set_filter=Y) can ignore
// explicit query params. We prefer explicit query params when present to make the UI
// deterministic (and to support dependent dropdowns).
if (array_key_exists('find_warehouse', $_GET))
{
	$find_warehouse = trim((string)$_GET['find_warehouse']);
}
if (array_key_exists('find_brand_sel', $_GET))
{
	$find_brand_sel = trim((string)$_GET['find_brand_sel']);
}
if (array_key_exists('find_brand', $_GET))
{
	$find_brand = trim((string)$_GET['find_brand']);
}

$adminNotice = null;
$hasNameCol = mf_miss_has_column($conn, 'UF_NAME');

// AJAX: dependent dropdown for brands by warehouse (without page reload).
// Bitrix admin list often reloads only the table via mode=list; filter controls stay unchanged.
// So we provide a lightweight endpoint to refresh the brand <select> options.
$req = Application::getInstance()->getContext()->getRequest();
if ((string)$req->getQuery('mf_ajax') === 'brands')
{
	$wh = trim((string)$req->getQuery('warehouse'));
	$items = [];
	$truncated = false;
	try
	{
		$brandWhere = '';
		if ($wh !== '')
		{
			$brandWhere = "WHERE UF_WAREHOUSE_XML_ID='" . $h->forSql($wh) . "'";
		}

		$limitBrands = 2000;
		$rs = $conn->query("
			SELECT DISTINCT UF_BRAND
			FROM mf_stock_import_missing
			$brandWhere
			ORDER BY UF_BRAND ASC
			LIMIT " . (int)$limitBrands . "
		");
		while ($r = $rs->fetch())
		{
			$b = trim((string)($r['UF_BRAND'] ?? ''));
			if ($b === '') continue;
			$items[] = $b;
		}
		// We can't cheaply know real total here; just indicate possible truncation
		// when we hit the limit.
		if (count($items) >= $limitBrands)
		{
			$truncated = true;
		}
	}
	catch (\Throwable $e)
	{
		// ignore, return empty list
	}

	while (ob_get_level() > 0)
	{
		@ob_end_clean();
	}
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode(
		['ok' => true, 'items' => $items, 'truncated' => $truncated],
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);
	die();
}

// Handle list actions (delete, move/create).
if (($arID = $lAdmin->GroupAction()) !== false)
{
	$action = (string)$lAdmin->GetAction();
	if ($lAdmin->IsGroupActionToAll())
	{
		$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Действие "ко всем" не поддержано на этой странице. Выдели нужные строки галочками.'];
	}
	else
	{
		$ids = array_values(array_filter(array_map('intval', (array)$arID), static fn($v) => $v > 0));

		if (empty($ids))
		{
			$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не выбраны строки.'];
		}
		elseif ($action === 'delete')
		{
			$in = implode(',', $ids);
			$conn->queryExecute("DELETE FROM mf_stock_import_missing WHERE ID IN ({$in})");
			$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Удалено строк: ' . count($ids)];
		}
		elseif ($action === 'to_misc')
		{
			if (!Loader::includeModule('iblock'))
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Модуль iblock не подключен.'];
			}
			elseif (!Loader::includeModule('catalog'))
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Модуль catalog не подключен.'];
			}
			elseif (!function_exists('mf_analogs_generate_unique_code'))
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не найдены функции создания товара (mf_import_analogs_lib.php).'];
			}
			else
			{
				$miscSectionId = mf_miss_ensure_section($iblockId, 'Разное', 0);
				if ($miscSectionId <= 0)
				{
					$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не удалось создать/найти раздел "Разное".'];
				}
				else
				{
					$created = 0;
					$skipped = 0;
					$failed = 0;
					$uniqKeysToCleanup = [];

					foreach ($ids as $id)
					{
						$r = $conn->query("SELECT * FROM mf_stock_import_missing WHERE ID=" . (int)$id)->fetch();
						if (!$r)
						{
							$failed++;
							continue;
						}

						$brand = trim((string)($r['UF_BRAND'] ?? ''));
						$article = trim((string)($r['UF_ARTICLE'] ?? ''));
						$nameFromCsv = trim((string)($r['UF_NAME'] ?? ''));
						$uniqKey = trim((string)($r['UF_UNIQ_KEY'] ?? ''));
						$qty = (float)($r['UF_QTY'] ?? 0);
						$rawPrice = (float)($r['UF_PRICE'] ?? 0);
						$warehouseXml = trim((string)($r['UF_WAREHOUSE_XML_ID'] ?? ''));
						if ($brand === '' || $article === '')
						{
							$failed++;
							continue;
						}

						$storeRow = ($warehouseXml !== '' ? mf_miss_find_store_row_by_xml_id($warehouseXml) : null);
						$storeId = (int)($storeRow['ID'] ?? 0);
						$storeTitle = (string)($storeRow['TITLE'] ?? $warehouseXml);
						$markupPct = $storeId > 0 ? mf_miss_get_store_markup_pct($storeId) : 0.0;
						$computedBasePrice = $rawPrice > 0 ? mf_miss_apply_markup($rawPrice, $markupPct) : 0.0;

						$existingId = ($uniqKey !== '' ? mf_miss_find_product_id_by_uniq_key($iblockId, $uniqKey) : null);
						if ($existingId && $existingId > 0)
						{
							// Update stock/price on existing product as well.
							if ($storeId > 0)
							{
								mf_miss_upsert_store_amount($existingId, $storeId, $qty);
								$gid = mf_miss_get_or_create_price_group_id($warehouseXml, $storeTitle !== '' ? $storeTitle : $warehouseXml);
								if ($gid > 0 && $rawPrice > 0)
								{
									mf_miss_upsert_price($existingId, $gid, $rawPrice, 'RUB');
								}
							}
							mf_miss_upsert_catalog_product($existingId, $qty);
							if ($computedBasePrice > 0 && class_exists('CPrice'))
							{
								\CPrice::SetBasePrice($existingId, $computedBasePrice, 'RUB');
							}

							$skipped++;
							if ($uniqKey !== '') $uniqKeysToCleanup[] = $uniqKey;
							continue;
						}

						$brandSectionId = mf_miss_ensure_section($iblockId, $brand, $miscSectionId);
						if ($brandSectionId <= 0)
						{
							$failed++;
							continue;
						}

						$brandNorm = function_exists('mf_analogs_norm_brand') ? mf_analogs_norm_brand($brand) : mb_strtoupper($brand);
						$articleNorm = function_exists('mf_analogs_norm_article') ? mf_analogs_norm_article($article) : mb_strtoupper($article);

						$name = ($nameFromCsv !== '' ? $nameFromCsv : ($brand . ' ' . $article));
						$codeBase = ($brandNorm !== '' && $articleNorm !== '') ? ($brandNorm . $articleNorm) : $name;
						$code = mf_analogs_generate_unique_code($iblockId, $codeBase);

						$el = new \CIBlockElement();
						$newId = (int)$el->Add([
							'IBLOCK_ID' => $iblockId,
							'ACTIVE' => 'Y',
							'NAME' => $name,
							'CODE' => $code,
							'IBLOCK_SECTION_ID' => $brandSectionId,
						]);
						if ($newId <= 0)
						{
							$failed++;
							continue;
						}

						$props = [
							'MF_BRAND' => $brand,
							'CML2_ARTICLE' => $article,
							'MF_BRAND_NORM' => $brandNorm,
							'MF_ARTICLE_NORM' => $articleNorm,
							'MF_SHOW_IN_CATALOG' => 'Y',
						];
						if ($uniqKey !== '')
						{
							$props['MF_UNIQ_KEY'] = $uniqKey;
							$uniqKeysToCleanup[] = $uniqKey;
						}
						\CIBlockElement::SetPropertyValuesEx($newId, $iblockId, $props);

						// Stock/price
						if ($storeId > 0)
						{
							mf_miss_upsert_store_amount($newId, $storeId, $qty);
							$gid = mf_miss_get_or_create_price_group_id($warehouseXml, $storeTitle !== '' ? $storeTitle : $warehouseXml);
							if ($gid > 0 && $rawPrice > 0)
							{
								// Store price group keeps RAW price (same as import script).
								mf_miss_upsert_price($newId, $gid, $rawPrice, 'RUB');
							}
						}
						// Catalog product QUANTITY: for convenience set to this warehouse qty.
						mf_miss_upsert_catalog_product($newId, $qty);
						// Base price: computed from raw + store markup (if store is known).
						if ($computedBasePrice > 0 && class_exists('CPrice'))
						{
							\CPrice::SetBasePrice($newId, $computedBasePrice, 'RUB');
						}

						try
						{
							if (Loader::includeModule('search'))
							{
								\CIBlockElement::UpdateSearch($newId, true);
							}
						}
						catch (\Throwable $e)
						{
							// ignore
						}

						$created++;
					}

					// Cleanup: remove missing rows for processed uniq keys (so next imports don't show them again).
					$uniqKeysToCleanup = array_values(array_unique(array_filter($uniqKeysToCleanup, static fn($v) => $v !== '')));
					if (!empty($uniqKeysToCleanup))
					{
						$esc = array_map(static fn($v) => $h->forSql((string)$v), $uniqKeysToCleanup);
						$in = implode("','", $esc);
						$conn->queryExecute("DELETE FROM mf_stock_import_missing WHERE UF_UNIQ_KEY IN ('{$in}')");
					}
					else
					{
						$in = implode(',', $ids);
						$conn->queryExecute("DELETE FROM mf_stock_import_missing WHERE ID IN ({$in})");
					}

					$adminNotice = [
						'TYPE' => 'OK',
						'MESSAGE' => 'Готово. Создано товаров: ' . $created . ', уже существовали: ' . $skipped . ', ошибок: ' . $failed,
					];
				}
			}
		}
	}
}

$where = [];
if ($find_warehouse !== '')
{
	$where[] = "UF_WAREHOUSE_XML_ID='" . $h->forSql($find_warehouse) . "'";
}
if ($find_brand_sel !== '')
{
	$where[] = "UF_BRAND='" . $h->forSql($find_brand_sel) . "'";
}
elseif ($find_brand !== '')
{
	// substring match (can be heavy). If warehouse is set, it's usually fine.
	$where[] = "UF_BRAND LIKE '%" . $h->forSql($find_brand) . "%'";
}
$whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$by = strtoupper((string)$oSort->getField());
$order = strtoupper((string)$oSort->getOrder());
$allowedSort = ['ID', 'UF_LAST_SEEN', 'UF_FIRST_SEEN', 'UF_BRAND', 'UF_ARTICLE', 'UF_QTY', 'UF_PRICE'];
if ($hasNameCol)
{
	$allowedSort[] = 'UF_NAME';
}
if (!in_array($by, $allowedSort, true))
{
	$by = 'UF_LAST_SEEN';
}
if (!in_array($order, ['ASC', 'DESC'], true))
{
	$order = 'DESC';
}

// Keep it fast: show only last N rows.
$limit = 500;
$cols = [
	'ID',
	'UF_WAREHOUSE_XML_ID',
	'UF_WAREHOUSE_TITLE',
	'UF_BRAND',
	'UF_ARTICLE',
];
if ($hasNameCol)
{
	$cols[] = 'UF_NAME';
}
$cols = array_merge($cols, [
	'UF_QTY',
	'UF_PRICE',
	'UF_LAST_SEEN',
	'UF_FIRST_SEEN',
	'UF_UNIQ_KEY',
]);
$sql = "SELECT\n\t" . implode(",\n\t", $cols) . "\nFROM mf_stock_import_missing\n$whereSql\nORDER BY $by $order, ID DESC\nLIMIT $limit";

$rows = [];
try
{
	$res = $conn->query($sql);
	while ($r = $res->fetch())
	{
		$rows[] = $r;
	}
}
catch (\Throwable $e)
{
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка чтения: ' . mf_miss_escape($e->getMessage())]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
	return;
}

// Warehouses dropdown from stores + fallback from table
$warehouses = [];
if (class_exists(Loader::class) && Loader::includeModule('catalog'))
{
	$rs = StoreTable::getList([
		'filter' => ['%XML_ID' => 'SUPPLIER_'],
		'select' => ['XML_ID', 'TITLE'],
		'order' => ['TITLE' => 'ASC'],
	]);
	while ($s = $rs->fetch())
	{
		$xml = trim((string)($s['XML_ID'] ?? ''));
		if ($xml === '') continue;
		$warehouses[$xml] = (string)($s['TITLE'] ?? $xml);
	}
}
try
{
	$rs = $conn->query("SELECT DISTINCT UF_WAREHOUSE_XML_ID, UF_WAREHOUSE_TITLE FROM mf_stock_import_missing ORDER BY UF_WAREHOUSE_TITLE ASC LIMIT 3000");
	while ($r = $rs->fetch())
	{
		$xml = trim((string)($r['UF_WAREHOUSE_XML_ID'] ?? ''));
		if ($xml === '') continue;
		if (!isset($warehouses[$xml]))
		{
			$warehouses[$xml] = (string)($r['UF_WAREHOUSE_TITLE'] ?? $xml);
		}
	}
}
catch (\Throwable $e)
{
	// ignore
}

// Brands dropdown (scoped by warehouse when selected)
$brands = [];
try
{
	$brandWhere = '';
	if ($find_warehouse !== '')
	{
		$brandWhere = "WHERE UF_WAREHOUSE_XML_ID='" . $h->forSql($find_warehouse) . "'";
	}
	$rs = $conn->query("
		SELECT DISTINCT UF_BRAND
		FROM mf_stock_import_missing
		$brandWhere
		ORDER BY UF_BRAND ASC
		LIMIT 2000
	");
	while ($r = $rs->fetch())
	{
		$b = trim((string)($r['UF_BRAND'] ?? ''));
		if ($b === '') continue;
		$brands[$b] = $b;
	}
}
catch (\Throwable $e)
{
	// ignore
}

$headers = [
	['id' => 'ID', 'content' => 'ID', 'default' => true, 'sort' => 'ID'],
	['id' => 'UF_WAREHOUSE_XML_ID', 'content' => 'Warehouse', 'default' => true],
	['id' => 'UF_BRAND', 'content' => 'Brand', 'default' => true, 'sort' => 'UF_BRAND'],
	['id' => 'UF_ARTICLE', 'content' => 'Article', 'default' => true, 'sort' => 'UF_ARTICLE'],
];
if ($hasNameCol)
{
	$headers[] = ['id' => 'UF_NAME', 'content' => 'Name', 'default' => true, 'sort' => 'UF_NAME'];
}
$headers = array_merge($headers, [
	['id' => 'UF_QTY', 'content' => 'Qty', 'default' => true, 'sort' => 'UF_QTY'],
	['id' => 'UF_PRICE', 'content' => 'Price', 'default' => true, 'sort' => 'UF_PRICE'],
	['id' => 'UF_LAST_SEEN', 'content' => 'Last seen', 'default' => true, 'sort' => 'UF_LAST_SEEN'],
	['id' => 'UF_FIRST_SEEN', 'content' => 'First seen', 'default' => false, 'sort' => 'UF_FIRST_SEEN'],
	['id' => 'UF_UNIQ_KEY', 'content' => 'Key', 'default' => false],
]);
$lAdmin->AddHeaders($headers);

foreach ($rows as $r)
{
	$id = (int)($r['ID'] ?? 0);
	if ($id <= 0) continue;

	$row = &$lAdmin->AddRow((string)$id, $r);
	$row->AddViewField('ID', (string)$id);
	$row->AddViewField('UF_WAREHOUSE_XML_ID', mf_miss_escape((string)($r['UF_WAREHOUSE_XML_ID'] ?? '')));
	$row->AddViewField('UF_BRAND', mf_miss_escape((string)($r['UF_BRAND'] ?? '')));
	$row->AddViewField('UF_ARTICLE', mf_miss_escape((string)($r['UF_ARTICLE'] ?? '')));
	if ($hasNameCol)
	{
		$row->AddViewField('UF_NAME', mf_miss_escape((string)($r['UF_NAME'] ?? '')));
	}

	$actions = [
		[
			'ICON' => 'delete',
			'TEXT' => 'Удалить',
			'ACTION' => "if(confirm('Удалить запись?')) " . $lAdmin->ActionDoGroup($id, 'delete'),
		],
		[
			'ICON' => 'edit',
			'TEXT' => 'Перенести в "Разное"',
			'ACTION' => "if(confirm('Создать товар в разделе Разное / Бренд и убрать из списка ненайденных?')) " . $lAdmin->ActionDoGroup($id, 'to_misc'),
		],
	];
	$row->AddActions($actions);
}

$lAdmin->AddGroupActionTable([
	'delete' => 'Удалить',
	'to_misc' => 'Перенести в "Разное" (создать товары)',
]);

$filter = new \CAdminFilter($sTableID . '_filter', [
	'Склад',
	'Бренд (select)',
	'Бренд содержит',
]);

$lAdmin->CheckListMode();

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('Ненайденные товары (импорт складов)');

?>
<div style="max-width: 1200px;">
	<?php if (is_array($adminNotice)): ?>
		<?php \CAdminMessage::ShowMessage($adminNotice); ?>
	<?php endif; ?>

	<div style="margin: 8px 0 12px 0; color:#666;">
		Показываем последние <?= (int)$limit ?> строк (фильтры могут сузить выборку).
	</div>

	<form name="find_form" method="get" action="<?= mf_miss_escape($APPLICATION->GetCurPage()) ?>">
		<input type="hidden" name="lang" value="<?= mf_miss_escape((string)LANGUAGE_ID) ?>">
		<input type="hidden" name="set_filter" value="">
		<?php $filter->Begin(); ?>
		<tr>
			<td>Склад:</td>
			<td>
				<select name="find_warehouse" id="mf_find_warehouse" style="min-width:420px;">
					<option value="" <?= ($find_warehouse === '' ? 'selected' : '') ?>>— все —</option>
					<?php foreach ($warehouses as $xml => $title): ?>
						<option value="<?= mf_miss_escape((string)$xml) ?>" <?= ($find_warehouse === (string)$xml ? 'selected' : '') ?>>
							<?= mf_miss_escape((string)$title) ?> (<?= mf_miss_escape((string)$xml) ?>)
						</option>
					<?php endforeach; ?>
				</select>
				<div style="margin-top:4px;color:#888;">
					Выбери склад — список брендов обновится автоматически.
				</div>
			</td>
		</tr>
		<tr>
			<td>Бренд (select):</td>
			<td>
				<select name="find_brand_sel" id="mf_find_brand_sel" style="min-width:420px;">
					<option value="" <?= ($find_brand_sel === '' ? 'selected' : '') ?>>—</option>
					<?php foreach ($brands as $b): ?>
						<option value="<?= mf_miss_escape((string)$b) ?>" <?= ($find_brand_sel === (string)$b ? 'selected' : '') ?>>
							<?= mf_miss_escape((string)$b) ?>
						</option>
					<?php endforeach; ?>
				</select>
				<div id="mf_brand_status" style="margin-top:4px;color:#888;"></div>
				<?php if ($find_warehouse === '' && count($brands) >= 2000): ?>
					<div style="margin-top:4px;color:#888;">Показаны первые 2000 брендов. Для полного списка выбери склад.</div>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<td>Бренд содержит:</td>
			<td><input type="text" name="find_brand" size="40" value="<?= mf_miss_escape($find_brand) ?>"></td>
		</tr>
		<?php
		$filter->Buttons(['table_id' => $sTableID, 'url' => $APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID, 'form' => 'find_form']);
		$filter->End();
		?>
	</form>
	<script>
	(function () {
		var wh = document.getElementById('mf_find_warehouse');
		var br = document.getElementById('mf_find_brand_sel');
		var st = document.getElementById('mf_brand_status');
		if (!wh || !br) return;

		function setStatus(msg) {
			if (!st) return;
			st.textContent = msg || '';
		}

		function setBrandLoading() {
			br.innerHTML = '';
			var o = document.createElement('option');
			o.value = '';
			o.textContent = 'Загрузка...';
			br.appendChild(o);
		}

		function setBrandOptions(items, truncated) {
			br.innerHTML = '';
			var opt0 = document.createElement('option');
			opt0.value = '';
			opt0.textContent = '—';
			br.appendChild(opt0);
			for (var i = 0; i < items.length; i++) {
				var o = document.createElement('option');
				o.value = items[i];
				o.textContent = items[i];
				br.appendChild(o);
			}
			// reset selection after warehouse change
			br.value = '';
			if (truncated && wh.value === '') {
				// keep UX hint minimal; full hint is already in markup for initial load
			}
		}

		function loadBrandsForWarehouse(warehouseXml) {
			br.disabled = true;
			setBrandLoading();
			setStatus('Загружаю бренды для склада: ' + (warehouseXml || '—'));
			var sess = (window.BX && typeof BX.bitrix_sessid === 'function') ? BX.bitrix_sessid() : '';
			var url = '<?=mf_miss_escape($APPLICATION->GetCurPage())?>'
				+ '?lang=<?=mf_miss_escape((string)LANGUAGE_ID)?>'
				+ '&mf_ajax=brands'
				+ '&warehouse=' + encodeURIComponent(warehouseXml || '')
				+ (sess ? ('&sessid=' + encodeURIComponent(sess)) : '');
			var done = function (data) {
				try {
					if (typeof data === 'string') {
						try { data = JSON.parse(data); } catch (e) { data = null; }
					}
					if (data && data.items && Array.isArray(data.items)) {
						setBrandOptions(data.items, !!data.truncated);
						setStatus('Брендов: ' + data.items.length + (data.truncated ? ' (ограничено)' : ''));
					} else {
						// If we didn't get JSON (e.g. session timeout -> HTML), keep a safe empty list.
						setBrandOptions([], false);
						setStatus('Не удалось получить список брендов (ответ не JSON).');
					}
				} finally {
					br.disabled = false;
				}
			};

			if (window.BX && BX.ajax) {
				BX.ajax({
					url: url,
					method: 'GET',
					onsuccess: function (data) { done(data); },
					onfailure: function () { done(null); }
				});
				return;
			}

			fetch(url, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (data) { done(data); })
				.catch(function () { done(null); });
		}

		wh.addEventListener('change', function (e) {
			// only on user interaction to avoid Bitrix restoring filter causing loops
			if (e && ('isTrusted' in e) && e.isTrusted === false) return;
			loadBrandsForWarehouse(wh.value);
		});
	})();
	</script>

	<?php
	$lAdmin->DisplayList();
	?>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

