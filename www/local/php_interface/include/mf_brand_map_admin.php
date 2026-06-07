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

$brandDict = $_SERVER['DOCUMENT_ROOT'] . '/mf_brand_dict.php';
if (!is_file($brandDict))
{
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Не найден файл словаря брендов: ' . $brandDict]);
	require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';

	return;
}
require_once $brandDict;

/** POST: «Не импортировать» вместо канона. */
const MF_BM_MAP_SKIP = '__MF_SKIP__';

/** Приоритет ручных сопоставлений (выше встроенных сидов в mf_brand_dict). */
const MF_BM_MANUAL_ALIAS_SORT = 400;

/** Приоритет сопоставлений из списка кандидатов импорта. */
const MF_BM_CANDIDATE_ALIAS_SORT = 100;

/** Сколько последних прайсов разбирать для кандидатов «прайс». */
const MF_BM_PRICE_JOBS_LIMIT = 5;

Loader::includeModule('highloadblock');
Loader::includeModule('iblock');
Loader::includeModule('catalog');

function mf_bm_escape(string $s): string
{
	return function_exists('htmlspecialcharsbx')
		? (string)htmlspecialcharsbx($s)
		: htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mf_bm_format_dt($value): string
{
	if ($value === null || $value === '')
	{
		return '—';
	}
	if (is_object($value) && method_exists($value, 'format'))
	{
		return (string)$value->format('Y-m-d H:i');
	}

	return (string)$value;
}

function mf_bm_table_exists(\Bitrix\Main\DB\Connection $conn, string $table): bool
{
	try
	{
		$r = $conn->query("SHOW TABLES LIKE '" . $conn->getSqlHelper()->forSql($table) . "'")->fetch();

		return (bool)$r;
	}
	catch (\Throwable $e)
	{
		return false;
	}
}

/**
 * @param list<string> $norms
 * @return array<string, true>
 */
function mf_bm_batch_import_skip_by_norm(array $norms): array
{
	$norms = array_values(array_unique(array_filter(array_map('trim', $norms), static fn($s) => $s !== '')));
	if ($norms === [] || !function_exists('mf_brand_import_skip_ensure_table') || !mf_brand_import_skip_ensure_table())
	{
		return [];
	}
	$out = [];
	$conn = Application::getConnection();
	$h = $conn->getSqlHelper();
	$chunk = 400;
	for ($i = 0; $i < count($norms); $i += $chunk)
	{
		$part = array_slice($norms, $i, $chunk);
		$in = implode(',', array_map(static fn($n) => "'" . $h->forSql($n) . "'", $part));
		if ($in === '')
		{
			continue;
		}
		try
		{
			$rs = $conn->query("SELECT UF_ALIAS_NORM FROM mf_brand_import_skip WHERE UF_ACTIVE='Y' AND UF_ALIAS_NORM IN ({$in})");
			while ($r = $rs->fetch())
			{
				$k = trim((string)($r['UF_ALIAS_NORM'] ?? ''));
				if ($k !== '')
				{
					$out[$k] = true;
				}
			}
		}
		catch (\Throwable $e)
		{
			continue;
		}
	}

	return $out;
}

/**
 * @param list<string> $norms
 * @return array<string, string>
 */
function mf_bm_batch_alias_canonical_by_norm(array $norms, ?array $hl): array
{
	$norms = array_values(array_unique(array_filter(array_map('trim', $norms), static fn($s) => $s !== '')));
	if ($norms === [] || !$hl || empty($hl['DATA_CLASS']))
	{
		return [];
	}
	$dc = $hl['DATA_CLASS'];
	$out = [];
	$chunk = 400;
	for ($i = 0; $i < count($norms); $i += $chunk)
	{
		$part = array_slice($norms, $i, $chunk);
		if ($part === [])
		{
			continue;
		}
		try
		{
			$rs = $dc::getList([
				'filter' => [
					'@UF_ALIAS_NORM' => $part,
					'=UF_ACTIVE' => 1,
				],
				'select' => ['UF_ALIAS_NORM', 'UF_CANONICAL', 'UF_SORT', 'ID'],
				'order' => ['UF_SORT' => 'DESC', 'ID' => 'DESC'],
			]);
			while ($r = $rs->fetch())
			{
				$n = trim((string)($r['UF_ALIAS_NORM'] ?? ''));
				if ($n === '' || array_key_exists($n, $out))
				{
					continue;
				}
				$out[$n] = trim((string)($r['UF_CANONICAL'] ?? ''));
			}
		}
		catch (\Throwable $e)
		{
			continue;
		}
	}

	return $out;
}

/**
 * @return array<string, string> xml_id => title
 */
function mf_bm_load_warehouses(\Bitrix\Main\DB\Connection $conn): array
{
	$warehouses = [];
	try
	{
		$rs = StoreTable::getList([
			'filter' => ['%XML_ID' => 'SUPPLIER_'],
			'select' => ['XML_ID', 'TITLE'],
			'order' => ['TITLE' => 'ASC'],
		]);
		while ($s = $rs->fetch())
		{
			$xml = trim((string)($s['XML_ID'] ?? ''));
			if ($xml === '')
			{
				continue;
			}
			$warehouses[$xml] = (string)($s['TITLE'] ?? $xml);
		}
	}
	catch (\Throwable $e)
	{
		// ignore
	}
	if (!mf_bm_table_exists($conn, 'mf_stock_import_missing'))
	{
		return $warehouses;
	}
	try
	{
		$rs = $conn->query('SELECT DISTINCT UF_WAREHOUSE_XML_ID, UF_WAREHOUSE_TITLE FROM mf_stock_import_missing ORDER BY UF_WAREHOUSE_TITLE ASC LIMIT 3000');
		while ($r = $rs->fetch())
		{
			$xml = trim((string)($r['UF_WAREHOUSE_XML_ID'] ?? ''));
			if ($xml === '')
			{
				continue;
			}
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

	return $warehouses;
}

/**
 * @return list<array{BRAND: string, CNT: int, LAST_SEEN: string}>
 */
function mf_bm_load_stock_candidate_rows(\Bitrix\Main\DB\Connection $conn, string $warehouse, string $findBrand): array
{
	if (!mf_bm_table_exists($conn, 'mf_stock_import_missing'))
	{
		return [];
	}
	$h = $conn->getSqlHelper();
	$where = 'WHERE UF_BRAND IS NOT NULL AND TRIM(UF_BRAND) <> \'\'';
	if ($warehouse !== '')
	{
		$where .= " AND UF_WAREHOUSE_XML_ID='" . $h->forSql($warehouse) . "'";
	}
	if ($findBrand !== '')
	{
		$where .= " AND UF_BRAND LIKE '%" . $h->forSql($findBrand) . "%'";
	}
	$out = [];
	try
	{
		$rs = $conn->query("
			SELECT UF_BRAND AS BRAND, COUNT(*) AS CNT, MAX(UF_LAST_SEEN) AS LAST_SEEN
			FROM mf_stock_import_missing
			{$where}
			GROUP BY UF_BRAND
			ORDER BY BRAND ASC
		");
		while ($r = $rs->fetch())
		{
			$b = trim((string)($r['BRAND'] ?? ''));
			if ($b === '')
			{
				continue;
			}
			$out[] = [
				'BRAND' => $b,
				'CNT' => (int)($r['CNT'] ?? 0),
				'LAST_SEEN' => (string)($r['LAST_SEEN'] ?? ''),
			];
		}
	}
	catch (\Throwable $e)
	{
		return [];
	}

	return $out;
}

/**
 * @return array{manufacturer?: int, article?: int}
 */
function mf_bm_parse_csv_header_map(array $headerCells): array
{
	$map = [];
	foreach ($headerCells as $i => $cell)
	{
		$h = mb_strtolower(trim((string)$cell));
		$h = preg_replace('~\s+~u', ' ', $h) ?? $h;
		if ($h === '')
		{
			continue;
		}
		if (preg_match('~(производитель|бренд|vendor)~u', $h))
		{
			$map['manufacturer'] = (int)$i;
		}
		elseif (preg_match('~(артикул|артик)~u', $h))
		{
			$map['article'] = (int)$i;
		}
	}

	return $map;
}

function mf_bm_detect_csv_delimiter(string $headerLine): string
{
	$headerLine = preg_replace('~^\xEF\xBB\xBF~', '', $headerLine) ?? $headerLine;
	if (mb_strpos($headerLine, ';') !== false)
	{
		return ';';
	}
	if (mb_strpos($headerLine, "\t") !== false)
	{
		return "\t";
	}

	return ',';
}

/**
 * @return list<array{BRAND: string, CNT: int, LAST_SEEN: string}>
 */
function mf_bm_load_price_candidate_rows(\Bitrix\Main\DB\Connection $conn, string $findBrand): array
{
	if (!mf_bm_table_exists($conn, 'mf_external_price_import_job'))
	{
		return [];
	}
	$brands = [];
	try
	{
		$rs = $conn->query('
			SELECT UF_FILE_PATH, UF_FINISHED_AT
			FROM mf_external_price_import_job
			WHERE UF_STATUS = \'done\'
			  AND UF_FILE_PATH IS NOT NULL AND TRIM(UF_FILE_PATH) <> \'\'
			ORDER BY UF_FINISHED_AT DESC
			LIMIT ' . (int)MF_BM_PRICE_JOBS_LIMIT . '
		');
		while ($job = $rs->fetch())
		{
			$path = trim((string)($job['UF_FILE_PATH'] ?? ''));
			$lastSeen = (string)($job['UF_FINISHED_AT'] ?? '');
			if ($path === '' || !is_file($path) || !is_readable($path))
			{
				continue;
			}
			$raw = (string)file_get_contents($path);
			if ($raw === '')
			{
				continue;
			}
			if (!mb_check_encoding($raw, 'UTF-8'))
			{
				$raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
			}
			$lines = preg_split('~\R~u', $raw) ?: [];
			if (count($lines) < 2)
			{
				continue;
			}
			$delim = mf_bm_detect_csv_delimiter((string)$lines[0]);
			$header = str_getcsv(preg_replace('~^\xEF\xBB\xBF~', '', (string)$lines[0]) ?? (string)$lines[0], $delim);
			$hmap = mf_bm_parse_csv_header_map($header);
			if (!isset($hmap['manufacturer']))
			{
				continue;
			}
			$lineLimit = 25000;
			for ($li = 1; $li < count($lines) && $li <= $lineLimit; $li++)
			{
				$line = trim((string)$lines[$li]);
				if ($line === '')
				{
					continue;
				}
				$cells = str_getcsv($line, $delim);
				$manufacturer = trim((string)($cells[$hmap['manufacturer']] ?? ''));
				if ($manufacturer === '')
				{
					continue;
				}
				if ($findBrand !== '' && mb_stripos($manufacturer, $findBrand) === false)
				{
					continue;
				}
				$key = function_exists('mf_brand_norm') ? mf_brand_norm($manufacturer) : mb_strtolower($manufacturer);
				if ($key === '')
				{
					continue;
				}
				if (!isset($brands[$key]))
				{
					$brands[$key] = [
						'BRAND' => $manufacturer,
						'CNT' => 0,
						'LAST_SEEN' => $lastSeen,
					];
				}
				$brands[$key]['CNT']++;
				if ($lastSeen !== '' && ($brands[$key]['LAST_SEEN'] === '' || $lastSeen > $brands[$key]['LAST_SEEN']))
				{
					$brands[$key]['LAST_SEEN'] = $lastSeen;
				}
			}
		}
	}
	catch (\Throwable $e)
	{
		return [];
	}
	$out = array_values($brands);
	usort($out, static fn(array $a, array $b): int => strnatcasecmp((string)$a['BRAND'], (string)$b['BRAND']));

	return $out;
}

/**
 * @param list<array{BRAND: string, CNT: int, LAST_SEEN: string}> $stockRows
 * @param list<array{BRAND: string, CNT: int, LAST_SEEN: string}> $priceRows
 * @return list<array<string, mixed>>
 */
function mf_bm_build_candidates(
	array $stockRows,
	array $priceRows,
	bool $onlyUnmapped
): array
{
	$merged = [];
	$addRow = static function (array $row, string $source) use (&$merged): void {
		$b = trim((string)($row['BRAND'] ?? ''));
		if ($b === '' || !function_exists('mf_brand_norm'))
		{
			return;
		}
		$n = mf_brand_norm($b);
		if ($n === '')
		{
			return;
		}
		if (!isset($merged[$n]))
		{
			$merged[$n] = [
				'BRAND' => $b,
				'NORM' => $n,
				'SOURCES' => [],
				'CNT_STOCK' => 0,
				'CNT_PRICE' => 0,
				'LAST_SEEN' => (string)($row['LAST_SEEN'] ?? ''),
			];
		}
		if (!in_array($source, $merged[$n]['SOURCES'], true))
		{
			$merged[$n]['SOURCES'][] = $source;
		}
		if ($source === 'stock')
		{
			$merged[$n]['CNT_STOCK'] += (int)($row['CNT'] ?? 0);
		}
		else
		{
			$merged[$n]['CNT_PRICE'] += (int)($row['CNT'] ?? 0);
		}
		$ls = (string)($row['LAST_SEEN'] ?? '');
		if ($ls !== '' && ($merged[$n]['LAST_SEEN'] === '' || $ls > $merged[$n]['LAST_SEEN']))
		{
			$merged[$n]['LAST_SEEN'] = $ls;
		}
	};

	foreach ($stockRows as $row)
	{
		$addRow($row, 'stock');
	}
	foreach ($priceRows as $row)
	{
		$addRow($row, 'price');
	}

	$norms = array_keys($merged);
	$skipSet = mf_bm_batch_import_skip_by_norm($norms);
	$hlForBatch = null;
	try
	{
		$hlForBatch = mf_brand_hl_ensure(false);
	}
	catch (\Throwable $e)
	{
		$hlForBatch = null;
	}
	$canonByNorm = mf_bm_batch_alias_canonical_by_norm($norms, $hlForBatch);

	$out = [];
	foreach ($merged as $n => $item)
	{
		$isSkip = isset($skipSet[$n]);
		$canon = $isSkip ? '' : (string)($canonByNorm[$n] ?? '');
		$resolved = (!$isSkip && function_exists('mf_brand_find')) ? mf_brand_find((string)$item['BRAND'], false) : '';
		if ($resolved !== '' && $canon === '')
		{
			$canon = $resolved;
		}
		$row = $item;
		$row['CANON'] = $canon;
		$row['IS_SKIP'] = $isSkip;
		$row['CNT'] = (int)$row['CNT_STOCK'] + (int)$row['CNT_PRICE'];
		if ($onlyUnmapped && ($isSkip || $canon !== ''))
		{
			continue;
		}
		$out[] = $row;
	}
	usort($out, static fn(array $a, array $b): int => strnatcasecmp((string)$a['BRAND'], (string)$b['BRAND']));

	return $out;
}

function mf_bm_resolve_store_id_by_warehouse_xml(string $warehouseXml): int
{
	$warehouseXml = trim($warehouseXml);
	if ($warehouseXml === '')
	{
		return 0;
	}
	$candidates = [$warehouseXml];
	if (stripos($warehouseXml, 'SUPPLIER_') !== 0)
	{
		$candidates[] = 'SUPPLIER_' . $warehouseXml;
	}
	try
	{
		foreach (array_values(array_unique($candidates)) as $xml)
		{
			$row = StoreTable::getList([
				'filter' => ['=XML_ID' => $xml],
				'select' => ['ID'],
				'limit' => 1,
			])->fetch();
			if ($row && (int)($row['ID'] ?? 0) > 0)
			{
				return (int)$row['ID'];
			}
		}
		$code = preg_replace('~^SUPPLIER_~i', '', $warehouseXml) ?? $warehouseXml;
		if ($code !== '')
		{
			$row = StoreTable::getList([
				'filter' => ['=CODE' => $code],
				'select' => ['ID'],
				'limit' => 1,
			])->fetch();
			if ($row && (int)($row['ID'] ?? 0) > 0)
			{
				return (int)$row['ID'];
			}
		}
	}
	catch (\Throwable $e)
	{
		return 0;
	}

	return 0;
}

/**
 * @param list<string> $brands
 * @return array{norms: array<string, true>, raws: array<string, true>}
 */
function mf_bm_ensure_cud_lib(): void
{
	if (function_exists('mf_cud_iblock_property_meta'))
	{
		return;
	}
	$cudLib = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/mf_catalog_uniq_duplicates_lib.php';
	if (!is_file($cudLib))
	{
		$cudLib = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/include/mf_catalog_uniq_duplicates_lib.php';
	}
	if (is_file($cudLib))
	{
		require_once $cudLib;
	}
}

function mf_bm_catalog_iblock_id(): int
{
	mf_bm_ensure_cud_lib();
	if (function_exists('mf_cud_catalog_iblock_id'))
	{
		return mf_cud_catalog_iblock_id();
	}

	return 4;
}

function mf_bm_brand_keys_from_list(array $brands): array
{
	$norms = [];
	$raws = [];
	foreach ($brands as $b)
	{
		$b = trim((string)$b);
		if ($b === '')
		{
			continue;
		}
		$raws[mb_strtolower($b)] = true;
		if (function_exists('mf_brand_norm'))
		{
			$n = mf_brand_norm($b);
			if ($n !== '')
			{
				$norms[$n] = true;
			}
		}
	}

	return ['norms' => $norms, 'raws' => $raws];
}

/**
 * @return array{offers_iblock_id: int, link_prop_id: int, offers_version: int}
 */
function mf_bm_catalog_sku_binding(int $catalogIblockId): array
{
	$binding = [
		'offers_iblock_id' => 0,
		'link_prop_id' => 0,
		'offers_version' => 1,
	];
	$catalogIblockId = (int)$catalogIblockId;
	if ($catalogIblockId <= 0 || !class_exists(\CCatalogSKU::class))
	{
		return $binding;
	}
	$sku = \CCatalogSKU::GetInfoByProductIBlock($catalogIblockId);
	if (is_array($sku))
	{
		$binding['offers_iblock_id'] = (int)($sku['IBLOCK_ID'] ?? 0);
		$binding['link_prop_id'] = (int)($sku['SKU_PROPERTY_ID'] ?? 0);
		$binding['offers_version'] = (int)($sku['VERSION'] ?? 1);
	}
	if ($binding['offers_iblock_id'] > 0 && $binding['link_prop_id'] > 0)
	{
		return $binding;
	}
	if (function_exists('mf_cud_catalog_iblock_ids'))
	{
		foreach (mf_cud_catalog_iblock_ids() as $iblockId)
		{
			$iblockId = (int)$iblockId;
			if ($iblockId <= 0 || $iblockId === $catalogIblockId)
			{
				continue;
			}
			$sku = \CCatalogSKU::GetInfoByOfferIBlock($iblockId);
			if (!is_array($sku) || (int)($sku['PRODUCT_IBLOCK_ID'] ?? 0) !== $catalogIblockId)
			{
				continue;
			}
			$binding['offers_iblock_id'] = $iblockId;
			$binding['link_prop_id'] = (int)($sku['SKU_PROPERTY_ID'] ?? 0);
			$binding['offers_version'] = (int)($sku['VERSION'] ?? 1);
			break;
		}
	}

	return $binding;
}

/**
 * @param list<int> $catalogProductOrElementIds
 * @return array<int, int> productOrElementId => elementId
 */
function mf_bm_map_product_ids_to_element_ids(
	\Bitrix\Main\DB\Connection $conn,
	array $catalogProductOrElementIds
): array
{
	$ids = array_values(array_unique(array_filter(array_map('intval', $catalogProductOrElementIds), static fn(int $id): bool => $id > 0)));
	if ($ids === [])
	{
		return [];
	}
	$map = [];
	foreach ($ids as $id)
	{
		$map[$id] = $id;
	}
	foreach (array_chunk($ids, 800) as $chunk)
	{
		$in = implode(',', $chunk);
		if ($in === '')
		{
			continue;
		}
		try
		{
			$rs = $conn->query("
				SELECT ID, ELEMENT_ID
				FROM b_catalog_product
				WHERE ID IN ({$in}) OR ELEMENT_ID IN ({$in})
			");
			while ($row = $rs->fetch())
			{
				$cpId = (int)($row['ID'] ?? 0);
				$elId = (int)($row['ELEMENT_ID'] ?? 0);
				if ($cpId > 0 && $elId > 0)
				{
					$map[$cpId] = $elId;
					$map[$elId] = $elId;
				}
			}
		}
		catch (\Throwable $e)
		{
			continue;
		}
	}

	return $map;
}

/**
 * @param list<int> $offerElementIds
 * @return array<int, int> offerElementId => parentCatalogElementId
 */
function mf_bm_batch_offer_parent_map(
	\Bitrix\Main\DB\Connection $conn,
	array $offerElementIds,
	int $offersIblockId,
	int $linkPropId,
	int $offersVersion
): array
{
	$offerElementIds = array_values(array_filter(array_map('intval', $offerElementIds), static fn(int $id): bool => $id > 0));
	if ($offerElementIds === [] || $offersIblockId <= 0 || $linkPropId <= 0)
	{
		return [];
	}

	$parentByOffer = [];
	$collect = static function (array $rows) use (&$parentByOffer): void {
		foreach ($rows as $offerId => $parentId)
		{
			$offerId = (int)$offerId;
			$parentId = (int)$parentId;
			if ($offerId > 0 && $parentId > 0)
			{
				$parentByOffer[$offerId] = $parentId;
			}
		}
	};

	foreach (array_chunk($offerElementIds, 500) as $chunk)
	{
		$in = implode(',', $chunk);
		if ($in === '')
		{
			continue;
		}
		try
		{
			if ($offersVersion === 2)
			{
				$rs = $conn->query("
					SELECT s.IBLOCK_ELEMENT_ID AS OFFER_ID, s.PROPERTY_{$linkPropId} AS PID
					FROM b_iblock_element_prop_s{$offersIblockId} s
					WHERE s.IBLOCK_ELEMENT_ID IN ({$in})
					  AND s.PROPERTY_{$linkPropId} > 0
				");
				$chunkMap = [];
				while ($row = $rs->fetch())
				{
					$chunkMap[(int)($row['OFFER_ID'] ?? 0)] = (int)($row['PID'] ?? 0);
				}
				$collect($chunkMap);

				$rsM = $conn->query("
					SELECT m.IBLOCK_ELEMENT_ID AS OFFER_ID, m.VALUE AS PID
					FROM b_iblock_element_prop_m{$offersIblockId} m
					WHERE m.IBLOCK_PROPERTY_ID = {$linkPropId}
					  AND m.IBLOCK_ELEMENT_ID IN ({$in})
					  AND m.VALUE > 0
				");
				$chunkMap = [];
				while ($row = $rsM->fetch())
				{
					$chunkMap[(int)($row['OFFER_ID'] ?? 0)] = (int)($row['PID'] ?? 0);
				}
				$collect($chunkMap);
			}
			else
			{
				$rs = $conn->query("
					SELECT p.IBLOCK_ELEMENT_ID AS OFFER_ID, p.VALUE AS PID
					FROM b_iblock_element_property p
					WHERE p.IBLOCK_PROPERTY_ID = {$linkPropId}
					  AND p.IBLOCK_ELEMENT_ID IN ({$in})
					  AND p.VALUE > 0
				");
				$chunkMap = [];
				while ($row = $rs->fetch())
				{
					$chunkMap[(int)($row['OFFER_ID'] ?? 0)] = (int)($row['PID'] ?? 0);
				}
				$collect($chunkMap);
			}
		}
		catch (\Throwable $e)
		{
			continue;
		}
	}

	return $parentByOffer;
}

/**
 * @param list<int> $offerElementIds
 * @return list<int>
 */
function mf_bm_batch_parent_ids_for_offers(
	\Bitrix\Main\DB\Connection $conn,
	array $offerElementIds,
	int $offersIblockId,
	int $linkPropId,
	int $offersVersion
): array
{
	return array_values(array_unique(mf_bm_batch_offer_parent_map(
		$conn,
		$offerElementIds,
		$offersIblockId,
		$linkPropId,
		$offersVersion
	)));
}

/**
 * @param list<int> $storeProductIds
 * @return list<int>
 */
function mf_bm_resolve_store_products_to_catalog_parents(
	\Bitrix\Main\DB\Connection $conn,
	array $storeProductIds,
	int $catalogIblockId
): array
{
	$storeProductIds = array_values(array_unique(array_filter(array_map('intval', $storeProductIds), static fn(int $id): bool => $id > 0)));
	if ($storeProductIds === [])
	{
		return [];
	}

	$productToElement = mf_bm_map_product_ids_to_element_ids($conn, $storeProductIds);
	$elementIds = array_values(array_unique(array_filter($productToElement, static fn(int $id): bool => $id > 0)));
	if ($elementIds === [])
	{
		return [];
	}

	$binding = mf_bm_catalog_sku_binding($catalogIblockId);
	$offersIblockId = (int)$binding['offers_iblock_id'];
	$linkPropId = (int)$binding['link_prop_id'];
	$offersVersion = (int)$binding['offers_version'];

	$iblockByElementId = [];
	foreach (array_chunk($elementIds, 800) as $chunk)
	{
		$in = implode(',', $chunk);
		if ($in === '')
		{
			continue;
		}
		try
		{
			$rsIb = $conn->query("SELECT ID, IBLOCK_ID FROM b_iblock_element WHERE ID IN ({$in})");
			while ($row = $rsIb->fetch())
			{
				$eid = (int)($row['ID'] ?? 0);
				if ($eid > 0)
				{
					$iblockByElementId[$eid] = (int)($row['IBLOCK_ID'] ?? 0);
				}
			}
		}
		catch (\Throwable $e)
		{
			continue;
		}
	}

	$detectedOffersIblockId = 0;
	if ($offersIblockId <= 0)
	{
		$ibCounts = [];
		foreach ($iblockByElementId as $ib)
		{
			$ib = (int)$ib;
			if ($ib > 0 && $ib !== $catalogIblockId)
			{
				$ibCounts[$ib] = ($ibCounts[$ib] ?? 0) + 1;
			}
		}
		if ($ibCounts !== [])
		{
			arsort($ibCounts);
			$detectedOffersIblockId = (int)array_key_first($ibCounts);
			if (class_exists(\CCatalogSKU::class))
			{
				$sku = \CCatalogSKU::GetInfoByOfferIBlock($detectedOffersIblockId);
				if (is_array($sku) && (int)($sku['PRODUCT_IBLOCK_ID'] ?? 0) === $catalogIblockId)
				{
					$offersIblockId = $detectedOffersIblockId;
					$linkPropId = (int)($sku['SKU_PROPERTY_ID'] ?? 0);
					$offersVersion = (int)($sku['VERSION'] ?? 1);
				}
			}
		}
	}

	$catalogElementIds = [];
	$offerElementIds = [];
	$unresolvedElementIds = [];
	foreach ($elementIds as $eid)
	{
		$ib = (int)($iblockByElementId[$eid] ?? 0);
		if ($ib === $catalogIblockId)
		{
			$catalogElementIds[$eid] = true;
		}
		elseif ($offersIblockId > 0 && $ib === $offersIblockId)
		{
			$offerElementIds[] = $eid;
		}
		else
		{
			$unresolvedElementIds[] = $eid;
		}
	}

	if ($offerElementIds !== [] && $offersIblockId > 0 && $linkPropId > 0)
	{
		foreach (mf_bm_batch_offer_parent_map($conn, $offerElementIds, $offersIblockId, $linkPropId, $offersVersion) as $parentId)
		{
			$catalogElementIds[$parentId] = true;
		}
	}

	if ($unresolvedElementIds !== [])
	{
		$unresolvedOfferMap = [];
		if ($offersIblockId > 0 && $linkPropId > 0)
		{
			$unresolvedOfferMap = mf_bm_batch_offer_parent_map(
				$conn,
				$unresolvedElementIds,
				$offersIblockId,
				$linkPropId,
				$offersVersion
			);
		}
		foreach ($unresolvedElementIds as $eid)
		{
			if (isset($unresolvedOfferMap[$eid]))
			{
				$catalogElementIds[(int)$unresolvedOfferMap[$eid]] = true;
				continue;
			}
			if (class_exists(\CCatalogSKU::class))
			{
				$ib = (int)($iblockByElementId[$eid] ?? 0);
				$info = \CCatalogSKU::GetProductInfo($eid, $ib);
				if (is_array($info) && (int)($info['ID'] ?? 0) > 0)
				{
					$catalogElementIds[(int)$info['ID']] = true;
					continue;
				}
			}
			if ((int)($iblockByElementId[$eid] ?? 0) === $catalogIblockId)
			{
				$catalogElementIds[$eid] = true;
			}
		}
	}

	return array_keys($catalogElementIds);
}

/**
 * @return list<int>
 */
function mf_bm_store_product_ids_at_store(\Bitrix\Main\DB\Connection $conn, int $storeId): array
{
	$storeId = (int)$storeId;
	if ($storeId <= 0)
	{
		return [];
	}
	$ids = [];
	try
	{
		$rs = $conn->query("
			SELECT DISTINCT sp.PRODUCT_ID AS PID
			FROM b_catalog_store_product sp
			WHERE sp.STORE_ID = {$storeId}
			  AND sp.PRODUCT_ID > 0
			LIMIT 100000
		");
		while ($r = $rs->fetch())
		{
			$pid = (int)($r['PID'] ?? $r['pid'] ?? 0);
			if ($pid > 0)
			{
				$ids[] = $pid;
			}
		}
	}
	catch (\Throwable $e)
	{
		return [];
	}

	return $ids;
}

/**
 * ID элементов каталога (родителей), привязанных к остаткам на складе.
 *
 * @return list<int>
 */
function mf_bm_collect_catalog_element_ids_at_store(
	\Bitrix\Main\DB\Connection $conn,
	int $storeId,
	int $catalogIblockId = 0
): array
{
	static $cache = [];

	$storeId = (int)$storeId;
	if ($storeId <= 0)
	{
		return [];
	}
	if ($catalogIblockId <= 0)
	{
		$catalogIblockId = mf_bm_catalog_iblock_id();
	}
	$catalogIblockId = (int)$catalogIblockId;
	if ($catalogIblockId <= 0)
	{
		return [];
	}

	$cacheKey = $storeId . ':' . $catalogIblockId;
	if (isset($cache[$cacheKey]))
	{
		return $cache[$cacheKey];
	}

	mf_bm_ensure_cud_lib();

	$cache[$cacheKey] = mf_bm_resolve_store_products_to_catalog_parents(
		$conn,
		mf_bm_store_product_ids_at_store($conn, $storeId),
		$catalogIblockId
	);

	return $cache[$cacheKey];
}

/**
 * @param list<int> $elementIds
 * @return list<string>
 */
function mf_bm_property_is_list(int $propertyId): bool
{
	$propertyId = (int)$propertyId;
	if ($propertyId <= 0)
	{
		return false;
	}
	$row = \CIBlockProperty::GetByID($propertyId)->Fetch();

	return is_array($row) && (string)($row['PROPERTY_TYPE'] ?? '') === 'L';
}

function mf_bm_sql_brand_strings_for_elements(
	\Bitrix\Main\DB\Connection $conn,
	int $iblockId,
	array $elementIds
): array
{
	$iblockId = (int)$iblockId;
	$elementIds = array_values(array_filter(array_map('intval', $elementIds), static fn(int $id): bool => $id > 0));
	if ($iblockId <= 0 || $elementIds === [])
	{
		return [];
	}
	mf_bm_ensure_cud_lib();
	if (!function_exists('mf_cud_iblock_property_meta'))
	{
		return [];
	}
	$meta = mf_cud_iblock_property_meta($iblockId);
	$brandPid = (int)($meta['brand_prop_id'] ?? 0);
	$brandNormPid = (int)($meta['brand_norm_prop_id'] ?? 0);
	$version = (int)($meta['version'] ?? 1);
	if ($brandPid <= 0 && $brandNormPid <= 0)
	{
		return [];
	}

	$brands = [];
	$pids = [];
	if ($brandPid > 0)
	{
		$pids[] = $brandPid;
	}
	if ($brandNormPid > 0 && $brandNormPid !== $brandPid)
	{
		$pids[] = $brandNormPid;
	}

	foreach (array_chunk($elementIds, 500) as $chunk)
	{
		$in = implode(',', $chunk);
		foreach ($pids as $pid)
		{
			try
			{
				$isList = mf_bm_property_is_list($pid);
				if ($version === 2)
				{
					if ($isList)
					{
						$sql = "
							SELECT DISTINCT COALESCE(NULLIF(TRIM(en.VALUE), ''), TRIM(s.PROPERTY_{$pid})) AS BRAND
							FROM b_iblock_element_prop_s{$iblockId} s
							LEFT JOIN b_iblock_property_enum en
								ON en.PROPERTY_ID = {$pid}
								AND en.ID = TRIM(s.PROPERTY_{$pid})
							WHERE s.IBLOCK_ELEMENT_ID IN ({$in})
							  AND TRIM(COALESCE(s.PROPERTY_{$pid}, '')) <> ''
						";
					}
					else
					{
						$sql = "
							SELECT DISTINCT TRIM(s.PROPERTY_{$pid}) AS BRAND
							FROM b_iblock_element_prop_s{$iblockId} s
							WHERE s.IBLOCK_ELEMENT_ID IN ({$in})
							  AND TRIM(COALESCE(s.PROPERTY_{$pid}, '')) <> ''
						";
					}
				}
				else
				{
					if ($isList)
					{
						$sql = "
							SELECT DISTINCT COALESCE(NULLIF(TRIM(en.VALUE), ''), TRIM(p.VALUE)) AS BRAND
							FROM b_iblock_element_property p
							LEFT JOIN b_iblock_property_enum en
								ON en.PROPERTY_ID = {$pid}
								AND en.ID = p.VALUE
							WHERE p.IBLOCK_PROPERTY_ID = {$pid}
							  AND p.IBLOCK_ELEMENT_ID IN ({$in})
							  AND p.VALUE IS NOT NULL
							  AND TRIM(p.VALUE) <> ''
						";
					}
					else
					{
						$sql = "
							SELECT DISTINCT TRIM(p.VALUE) AS BRAND
							FROM b_iblock_element_property p
							WHERE p.IBLOCK_PROPERTY_ID = {$pid}
							  AND p.IBLOCK_ELEMENT_ID IN ({$in})
							  AND p.VALUE IS NOT NULL
							  AND TRIM(p.VALUE) <> ''
						";
					}
				}
				$rs = $conn->query($sql);
				while ($row = $rs->fetch())
				{
					$b = trim((string)($row['BRAND'] ?? ''));
					if ($b !== '')
					{
						$brands[$b] = true;
					}
				}
			}
			catch (\Throwable $e)
			{
				continue;
			}
		}
	}

	return array_keys($brands);
}

/**
 * @param list<int> $elementIds
 * @return list<string>
 */
function mf_bm_load_brand_strings_for_catalog_elements(
	\Bitrix\Main\DB\Connection $conn,
	int $catalogIblockId,
	array $elementIds
): array
{
	$catalogIblockId = (int)$catalogIblockId;
	if ($catalogIblockId <= 0 || $elementIds === [])
	{
		return [];
	}

	$brands = [];
	foreach (mf_bm_sql_brand_strings_for_elements($conn, $catalogIblockId, $elementIds) as $b)
	{
		$brands[$b] = true;
	}

	if (!class_exists(\CIBlockElement::class))
	{
		return array_keys($brands);
	}

	foreach (array_chunk($elementIds, 200) as $chunk)
	{
		$rs = \CIBlockElement::GetList(
			[],
			['IBLOCK_ID' => $catalogIblockId, 'ID' => $chunk],
			false,
			false,
			['ID', 'PROPERTY_MF_BRAND', 'PROPERTY_MF_BRAND_NORM']
		);
		while ($row = $rs->GetNext())
		{
			if (!is_array($row))
			{
				continue;
			}
			foreach (['PROPERTY_MF_BRAND_VALUE', 'PROPERTY_MF_BRAND_NORM_VALUE'] as $field)
			{
				$val = $row[$field] ?? '';
				if (is_array($val))
				{
					$val = reset($val);
				}
				$b = trim((string)$val);
				if ($b !== '')
				{
					$brands[$b] = true;
				}
			}
		}
	}

	return array_keys($brands);
}

/**
 * @param list<int> $storeProductIds
 * @return list<string>
 */
function mf_bm_load_brand_strings_for_store_product_ids(array $storeProductIds): array
{
	$storeProductIds = array_values(array_unique(array_filter(array_map('intval', $storeProductIds), static fn(int $id): bool => $id > 0)));
	if ($storeProductIds === [])
	{
		return [];
	}
	if (!function_exists('mf_catalog_brand_article_by_product_id'))
	{
		return [];
	}

	$brands = [];
	foreach ($storeProductIds as $productId)
	{
		$info = mf_catalog_brand_article_by_product_id($productId);
		$b = trim((string)($info['brand'] ?? ''));
		if ($b !== '')
		{
			$brands[$b] = true;
		}
	}

	return array_keys($brands);
}

/**
 * Бренды (MF_BRAND / MF_BRAND_NORM) товаров с записью остатка на складе.
 *
 * @return array{norms: array<string, true>, raws: array<string, true>}|null null = без фильтра по складу
 */
function mf_bm_load_warehouse_catalog_brand_keys(\Bitrix\Main\DB\Connection $conn, string $warehouse): ?array
{
	$warehouse = trim($warehouse);
	if ($warehouse === '')
	{
		return null;
	}
	$storeId = mf_bm_resolve_store_id_by_warehouse_xml($warehouse);
	if ($storeId <= 0)
	{
		return ['norms' => [], 'raws' => []];
	}

	$storeProductIds = mf_bm_store_product_ids_at_store($conn, $storeId);
	if ($storeProductIds === [])
	{
		return ['norms' => [], 'raws' => []];
	}

	$brands = mf_bm_load_brand_strings_for_store_product_ids($storeProductIds);

	// Запасной путь: SQL / GetList по родителям каталога (если init-хелпер недоступен или не дал ни одного бренда).
	if ($brands === [])
	{
		$iblockId = mf_bm_catalog_iblock_id();
		$elementIds = mf_bm_collect_catalog_element_ids_at_store($conn, $storeId, $iblockId);
		if ($elementIds !== [])
		{
			$brands = mf_bm_load_brand_strings_for_catalog_elements($conn, $iblockId, $elementIds);
		}
	}

	return mf_bm_brand_keys_from_list($brands);
}

/**
 * @return list<string>
 */
function mf_bm_load_warehouse_catalog_brand_strings(\Bitrix\Main\DB\Connection $conn, string $warehouse): array
{
	$warehouse = trim($warehouse);
	if ($warehouse === '')
	{
		return [];
	}
	$storeId = mf_bm_resolve_store_id_by_warehouse_xml($warehouse);
	if ($storeId <= 0)
	{
		return [];
	}

	$brands = mf_bm_load_brand_strings_for_store_product_ids(mf_bm_store_product_ids_at_store($conn, $storeId));
	if ($brands === [])
	{
		$iblockId = mf_bm_catalog_iblock_id();
		$elementIds = mf_bm_collect_catalog_element_ids_at_store($conn, $storeId, $iblockId);
		if ($elementIds !== [])
		{
			$brands = mf_bm_load_brand_strings_for_catalog_elements($conn, $iblockId, $elementIds);
		}
	}

	$uniq = [];
	foreach ($brands as $b)
	{
		$b = trim((string)$b);
		if ($b !== '')
		{
			$uniq[$b] = true;
		}
	}
	$out = array_keys($uniq);
	natcasesort($out);

	return array_values($out);
}

function mf_bm_is_catalog_brand_covered_by_hl(string $catalogBrand, array $hlRows): bool
{
	$catalogBrand = trim($catalogBrand);
	if ($catalogBrand === '')
	{
		return true;
	}

	$keys = mf_bm_brand_keys_from_list([$catalogBrand]);
	foreach ($hlRows as $row)
	{
		if (!is_array($row))
		{
			continue;
		}
		if (mf_bm_alias_matches_catalog_brands($row, $keys))
		{
			return true;
		}
	}

	if (function_exists('mf_brand_find') && mf_brand_find($catalogBrand, false) !== '')
	{
		return true;
	}

	return false;
}

/**
 * Бренды MF_BRAND на складе без записи в mf_brand_alias.
 *
 * @param list<string> $warehouseBrands
 * @param list<array<string, mixed>> $hlRowsForCoverage
 * @return list<array<string, mixed>>
 */
function mf_bm_build_virtual_warehouse_brand_rows(array $warehouseBrands, array $hlRowsForCoverage, string $find): array
{
	$find = mb_strtolower(trim($find));
	$out = [];
	foreach ($warehouseBrands as $brand)
	{
		$brand = trim((string)$brand);
		if ($brand === '' || mf_bm_is_catalog_brand_covered_by_hl($brand, $hlRowsForCoverage))
		{
			continue;
		}
		if ($find !== '' && mb_strpos(mb_strtolower($brand), $find) === false)
		{
			continue;
		}
		$out[] = [
			'ID' => 0,
			'IS_VIRTUAL' => true,
			'UF_ALIAS' => $brand,
			'UF_ALIAS_NORM' => function_exists('mf_brand_norm') ? mf_brand_norm($brand) : '',
			'UF_CANONICAL' => '',
			'UF_CANONICAL_NORM' => '',
			'UF_SORT' => 0,
			'UF_ACTIVE' => true,
			'UF_UPDATED_AT' => null,
		];
	}

	return $out;
}

function mf_bm_row_matches_warehouse_brands(string $alias, string $aliasNorm, ?array $warehouseBrandKeys): bool
{
	if ($warehouseBrandKeys === null)
	{
		return true;
	}
	$alias = trim($alias);
	$aliasNorm = trim($aliasNorm);
	if ($aliasNorm === '' && $alias !== '' && function_exists('mf_brand_norm'))
	{
		$aliasNorm = mf_brand_norm($alias);
	}
	if ($aliasNorm !== '' && isset($warehouseBrandKeys['norms'][$aliasNorm]))
	{
		return true;
	}
	if ($alias !== '')
	{
		$rawKey = mb_strtolower($alias);
		if (isset($warehouseBrandKeys['raws'][$rawKey]))
		{
			return true;
		}
		if (function_exists('mf_brand_norm'))
		{
			$n = mf_brand_norm($alias);
			if ($n !== '' && isset($warehouseBrandKeys['norms'][$n]))
			{
				return true;
			}
		}
	}

	return false;
}

function mf_bm_alias_matches_catalog_brands(array $row, ?array $catalogBrandKeys): bool
{
	if ($catalogBrandKeys === null)
	{
		return true;
	}

	if (mf_bm_row_matches_warehouse_brands(
		(string)($row['UF_CANONICAL'] ?? ''),
		(string)($row['UF_CANONICAL_NORM'] ?? ''),
		$catalogBrandKeys
	))
	{
		return true;
	}
	if (mf_bm_row_matches_warehouse_brands(
		(string)($row['UF_ALIAS'] ?? ''),
		(string)($row['UF_ALIAS_NORM'] ?? ''),
		$catalogBrandKeys
	))
	{
		return true;
	}

	$alias = trim((string)($row['UF_ALIAS'] ?? ''));
	if ($alias !== '' && function_exists('mf_brand_find'))
	{
		$resolved = mf_brand_find($alias, false);
		if ($resolved !== '' && mf_bm_row_matches_warehouse_brands($resolved, '', $catalogBrandKeys))
		{
			return true;
		}
	}

	$canon = trim((string)($row['UF_CANONICAL'] ?? ''));
	if ($canon !== '' && function_exists('mf_brand_find'))
	{
		$resolvedCanon = mf_brand_find($canon, false);
		if ($resolvedCanon !== '' && mf_bm_row_matches_warehouse_brands($resolvedCanon, '', $catalogBrandKeys))
		{
			return true;
		}
	}

	return false;
}

/**
 * @return list<array<string, mixed>>
 */
function mf_bm_load_alias_rows(bool $activeOnly, string $find, ?array $catalogBrandKeys = null): array
{
	$rows = [];
	$find = mb_strtolower(trim($find));
	try
	{
		$hl = mf_brand_hl_ensure(false);
		if (!$hl || empty($hl['DATA_CLASS']))
		{
			return [];
		}
		$filter = [];
		if ($activeOnly)
		{
			$filter['=UF_ACTIVE'] = 1;
		}
		$rs = $hl['DATA_CLASS']::getList([
			'filter' => $filter,
			'select' => [
				'ID',
				'UF_ALIAS',
				'UF_ALIAS_NORM',
				'UF_CANONICAL',
				'UF_CANONICAL_NORM',
				'UF_SORT',
				'UF_ACTIVE',
				'UF_UPDATED_AT',
			],
			'order' => ['UF_SORT' => 'DESC', 'UF_ALIAS' => 'ASC', 'ID' => 'ASC'],
		]);
		while ($r = $rs->fetch())
		{
			if (!mf_bm_alias_matches_catalog_brands($r, $catalogBrandKeys))
			{
				continue;
			}
			if ($find !== '')
			{
				$hay = mb_strtolower(
					trim((string)($r['UF_ALIAS'] ?? '')) . ' '
					. trim((string)($r['UF_CANONICAL'] ?? '')) . ' '
					. trim((string)($r['UF_ALIAS_NORM'] ?? '')) . ' '
					. trim((string)($r['UF_CANONICAL_NORM'] ?? ''))
				);
				if (mb_strpos($hay, $find) === false)
				{
					continue;
				}
			}
			$rows[] = $r;
		}
	}
	catch (\Throwable $e)
	{
		return [];
	}

	return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function mf_bm_load_import_skip_rows(string $find, ?array $catalogBrandKeys = null): array
{
	if (!function_exists('mf_brand_import_skip_ensure_table') || !mf_brand_import_skip_ensure_table())
	{
		return [];
	}
	$find = mb_strtolower(trim($find));
	$out = [];
	try
	{
		$conn = Application::getConnection();
		$rs = $conn->query("
			SELECT ID, UF_ALIAS_RAW, UF_ALIAS_NORM, UF_ACTIVE, UF_UPDATED_AT
			FROM mf_brand_import_skip
			WHERE UF_ACTIVE = 'Y'
			ORDER BY UF_ALIAS_RAW ASC, ID ASC
		");
		while ($r = $rs->fetch())
		{
			if ($catalogBrandKeys !== null
				&& !mf_bm_row_matches_warehouse_brands(
					(string)($r['UF_ALIAS_RAW'] ?? ''),
					(string)($r['UF_ALIAS_NORM'] ?? ''),
					$catalogBrandKeys
				))
			{
				continue;
			}
			if ($find !== '')
			{
				$hay = mb_strtolower(
					trim((string)($r['UF_ALIAS_RAW'] ?? '')) . ' '
					. trim((string)($r['UF_ALIAS_NORM'] ?? ''))
				);
				if (mb_strpos($hay, $find) === false)
				{
					continue;
				}
			}
			$out[] = $r;
		}
	}
	catch (\Throwable $e)
	{
		return [];
	}

	return $out;
}

/**
 * @return list<string>
 */
function mf_bm_catalog_brand_choices(): array
{
	if (!function_exists('mf_ce_load_brand_choices'))
	{
		require_once __DIR__ . '/mf_ce_brand_choices_inc.php';
	}
	$out = [];
	foreach (mf_ce_load_brand_choices(4, true, ['MF_BRAND']) as $b)
	{
		$b = trim((string)$b);
		if ($b !== '')
		{
			$out[$b] = true;
		}
	}
	try
	{
		$hl = mf_brand_hl_ensure(false);
		if ($hl && !empty($hl['DATA_CLASS']))
		{
			$rs = $hl['DATA_CLASS']::getList([
				'filter' => ['=UF_ACTIVE' => 1],
				'select' => ['UF_CANONICAL'],
				'limit' => 5000,
			]);
			while ($r = $rs->fetch())
			{
				$b = trim((string)($r['UF_CANONICAL'] ?? ''));
				if ($b !== '')
				{
					$out[$b] = true;
				}
			}
		}
	}
	catch (\Throwable $e)
	{
		// ignore
	}
	$keys = array_keys($out);
	natcasesort($keys);

	return array_values($keys);
}

/**
 * HTML <option> для выбора канона (каталог + текущее значение, если его нет в списке).
 */
function mf_bm_canon_options_html(array $catalogBrands, string $selected = ''): string
{
	$selected = trim($selected);
	$html = '<option value="">— выберите —</option>';
	$skipSel = ($selected === MF_BM_MAP_SKIP) ? ' selected' : '';
	$html .= '<option value="' . mf_bm_escape(MF_BM_MAP_SKIP) . '" title="Не импортировать строки с этим брендом"' . $skipSel . '>— не импортировать —</option>';
	$inList = false;
	foreach ($catalogBrands as $bCh)
	{
		$bCh = (string)$bCh;
		if ($bCh === '')
		{
			continue;
		}
		if ($selected !== '' && $selected === $bCh)
		{
			$inList = true;
		}
		$esc = mf_bm_escape($bCh);
		$selAttr = ($selected !== '' && $selected === $bCh) ? ' selected' : '';
		$html .= '<option value="' . $esc . '" title="' . $esc . '"' . $selAttr . '>' . $esc . '</option>';
	}
	if ($selected !== '' && $selected !== MF_BM_MAP_SKIP && !$inList)
	{
		$esc = mf_bm_escape($selected);
		$html .= '<option value="' . $esc . '" title="' . $esc . '" selected>' . $esc . ' (не в каталоге)</option>';
	}

	return $html;
}

/**
 * Переводит сопоставление HL в «не импортировать» (удаляет запись HL, пишет skip).
 *
 * @return string|null сообщение об ошибке или null при успехе
 */
function mf_bm_convert_alias_to_skip(int $id, string $alias): ?string
{
	$row = mf_bm_fetch_alias_by_id($id);
	if ($row === null)
	{
		return 'Запись #' . $id . ' не найдена.';
	}

	$alias = trim($alias);
	if ($alias === '')
	{
		return 'Укажите текст бренда (алиас).';
	}

	if (!function_exists('mf_brand_import_skip_set'))
	{
		return 'Функция mf_brand_import_skip_set недоступна.';
	}

	try
	{
		$hl = mf_brand_hl_ensure(false);
		if ($hl && !empty($hl['DATA_CLASS']))
		{
			$hl['DATA_CLASS']::delete($id);
		}
		mf_brand_import_skip_set($alias, true);
	}
	catch (\Throwable $e)
	{
		return 'Ошибка: ' . $e->getMessage();
	}

	return null;
}

/**
 * @return array<string, mixed>|null
 */
function mf_bm_fetch_alias_by_id(int $id): ?array
{
	if ($id <= 0)
	{
		return null;
	}
	try
	{
		$hl = mf_brand_hl_ensure(false);
		if (!$hl || empty($hl['DATA_CLASS']))
		{
			return null;
		}
		$row = $hl['DATA_CLASS']::getList([
			'filter' => ['=ID' => $id],
			'select' => [
				'ID',
				'UF_ALIAS',
				'UF_ALIAS_NORM',
				'UF_CANONICAL',
				'UF_CANONICAL_NORM',
				'UF_SORT',
				'UF_ACTIVE',
			],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? $row : null;
	}
	catch (\Throwable $e)
	{
		return null;
	}
}

/**
 * @return string|null сообщение об ошибке или null при успехе
 */
function mf_bm_update_alias_by_id(int $id, string $alias, string $canon, bool $active): ?string
{
	$row = mf_bm_fetch_alias_by_id($id);
	if ($row === null)
	{
		return 'Запись #' . $id . ' не найдена.';
	}

	$alias = trim($alias);
	$canon = trim($canon);
	if ($alias === '' || $canon === '')
	{
		return 'Заполните алиас и канон.';
	}

	$aliasNorm = mf_brand_norm($alias);
	$canonNorm = mf_brand_norm($canon);
	if ($aliasNorm === '' || $canonNorm === '')
	{
		return 'Не удалось нормализовать алиас или канон.';
	}

	try
	{
		$hl = mf_brand_hl_ensure(false);
		if (!$hl || empty($hl['DATA_CLASS']))
		{
			return 'HL mf_brand_alias недоступен.';
		}
		$dc = $hl['DATA_CLASS'];
		$dup = $dc::getList([
			'filter' => ['=UF_ALIAS_NORM' => $aliasNorm],
			'select' => ['ID'],
			'limit' => 2,
		]);
		while ($d = $dup->fetch())
		{
			if ((int)($d['ID'] ?? 0) !== $id)
			{
				return 'Алиас «' . $alias . '» уже сопоставлен в записи #' . (int)$d['ID'] . '.';
			}
		}

		if (function_exists('mf_brand_import_skip_set'))
		{
			mf_brand_import_skip_set($alias, false);
		}

		$dc::update($id, [
			'UF_ALIAS' => $alias,
			'UF_ALIAS_NORM' => $aliasNorm,
			'UF_CANONICAL' => $canon,
			'UF_CANONICAL_NORM' => $canonNorm,
			'UF_ACTIVE' => $active ? 1 : 0,
			'UF_SORT' => (int)($row['UF_SORT'] ?? 0),
			'UF_UPDATED_AT' => new \Bitrix\Main\Type\DateTime(),
		]);
		mf_brand_aliases_reset_cache();
	}
	catch (\Throwable $e)
	{
		return 'Ошибка сохранения: ' . $e->getMessage();
	}

	return null;
}

/**
 * @return string|null сообщение об ошибке или null при успехе
 */
function mf_bm_update_skip_by_id(int $id, string $aliasRaw): ?string
{
	if (!mf_brand_import_skip_ensure_table())
	{
		return 'Таблица mf_brand_import_skip недоступна.';
	}

	$aliasRaw = trim($aliasRaw);
	if ($aliasRaw === '')
	{
		return 'Укажите текст бренда.';
	}

	$aliasNorm = mf_brand_norm($aliasRaw);
	if ($aliasNorm === '')
	{
		return 'Не удалось нормализовать бренд.';
	}

	try
	{
		$conn = Application::getConnection();
		$h = $conn->getSqlHelper();
		$cur = $conn->query('SELECT ID FROM mf_brand_import_skip WHERE ID=' . $id . ' LIMIT 1')->fetch();
		if (!$cur)
		{
			return 'Запись пропуска #' . $id . ' не найдена.';
		}
		$dup = $conn->query(
			"SELECT ID FROM mf_brand_import_skip WHERE UF_ALIAS_NORM='"
				. $h->forSql($aliasNorm) . "' AND ID<>" . $id . " LIMIT 1"
		)->fetch();
		if ($dup)
		{
			return 'Бренд «' . $aliasRaw . '» уже есть в записи #' . (int)$dup['ID'] . '.';
		}
		$now = date('Y-m-d H:i:s');
		$conn->queryExecute(
			"UPDATE mf_brand_import_skip SET UF_ALIAS_RAW='"
				. $h->forSql($aliasRaw) . "', UF_ALIAS_NORM='"
				. $h->forSql($aliasNorm) . "', UF_ACTIVE='Y', UF_UPDATED_AT='"
				. $h->forSql($now) . "' WHERE ID=" . $id
		);
	}
	catch (\Throwable $e)
	{
		return 'Ошибка сохранения: ' . $e->getMessage();
	}

	return null;
}

$adminNotice = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && check_bitrix_sessid())
{
	$action = trim((string)($_POST['bm_action'] ?? ''));

	if ($action === 'save_candidates')
	{
		$map = isset($_POST['map']) && is_array($_POST['map']) ? $_POST['map'] : [];
		$mapCustom = isset($_POST['map_custom']) && is_array($_POST['map_custom']) ? $_POST['map_custom'] : [];
		$mapAliases = array_values(array_unique(array_merge(array_keys($map), array_keys($mapCustom))));
		$saved = 0;
		$skipped = 0;
		$hl = mf_brand_hl_ensure(true);
		foreach ($mapAliases as $mapAliasKey)
		{
			$alias = trim((string)$mapAliasKey);
			$fromSelect = trim((string)($map[$mapAliasKey] ?? ''));
			$customCanon = trim((string)($mapCustom[$mapAliasKey] ?? ''));
			if ($customCanon !== '')
			{
				$canonical = $customCanon;
			}
			elseif ($fromSelect === MF_BM_MAP_SKIP)
			{
				$canonical = MF_BM_MAP_SKIP;
			}
			else
			{
				$canonical = $fromSelect;
			}
			if ($alias === '' || $canonical === '')
			{
				$skipped++;
				continue;
			}
			if ($canonical === MF_BM_MAP_SKIP)
			{
				if (function_exists('mf_brand_import_skip_set'))
				{
					mf_brand_import_skip_set($alias, true);
				}
				$saved++;
				continue;
			}
			mf_brand_register_alias($hl, $canonical, $alias, true, MF_BM_CANDIDATE_ALIAS_SORT);
			$saved++;
		}
		$adminNotice = [
			'TYPE' => 'OK',
			'MESSAGE' => 'Сохранено из списка кандидатов: ' . $saved . ', без изменений: ' . $skipped . '.',
		];
	}
	elseif ($action === 'add')
	{
		$alias = trim((string)($_POST['add_alias'] ?? ''));
		$mode = trim((string)($_POST['add_mode'] ?? 'map'));
		$canonSelect = trim((string)($_POST['add_canon'] ?? ''));
		$canonCustom = trim((string)($_POST['add_canon_custom'] ?? ''));

		if ($alias === '')
		{
			$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Укажите текст бренда (как в прайсе/остатках).'];
		}
		elseif ($mode === 'skip')
		{
			if (function_exists('mf_brand_import_skip_set'))
			{
				mf_brand_import_skip_set($alias, true);
				$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Бренд «' . $alias . '» помечен: не импортировать.'];
			}
			else
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Функция mf_brand_import_skip_set недоступна.'];
			}
		}
		else
		{
			$canon = $canonCustom !== '' ? $canonCustom : $canonSelect;
			if ($canon === MF_BM_MAP_SKIP)
			{
				if (function_exists('mf_brand_import_skip_set'))
				{
					mf_brand_import_skip_set($alias, true);
					$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Бренд «' . $alias . '» помечен: не импортировать.'];
				}
				else
				{
					$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Функция mf_brand_import_skip_set недоступна.'];
				}
			}
			elseif ($canon === '')
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Укажите канонический бренд (из списка или своим текстом).'];
			}
			else
			{
				$hl = mf_brand_hl_ensure(true);
				if (!$hl)
				{
					$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не удалось открыть HL mf_brand_alias.'];
				}
				else
				{
					mf_brand_register_alias($hl, $canon, $alias, true, MF_BM_MANUAL_ALIAS_SORT);
					$adminNotice = [
						'TYPE' => 'OK',
						'MESSAGE' => 'Сохранено: «' . $alias . '» → «' . $canon . '» (приоритет ' . MF_BM_MANUAL_ALIAS_SORT . ').',
					];
				}
			}
		}
	}
	elseif ($action === 'update_alias')
	{
		$editId = (int)($_POST['edit_id'] ?? 0);
		$alias = trim((string)($_POST['edit_alias'] ?? ''));
		$canon = trim((string)($_POST['edit_canon'] ?? ''));
		$active = (string)($_POST['edit_active'] ?? '') === 'Y';

		if ($canon === MF_BM_MAP_SKIP)
		{
			$err = mf_bm_convert_alias_to_skip($editId, $alias);
			if ($err !== null)
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => $err];
			}
			else
			{
				$adminNotice = [
					'TYPE' => 'OK',
					'MESSAGE' => 'Бренд «' . $alias . '» перенесён в «не импортировать» (сопоставление #' . $editId . ' снято).',
				];
			}
		}
		else
		{
			$err = mf_bm_update_alias_by_id($editId, $alias, $canon, $active);
			if ($err !== null)
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => $err];
			}
			else
			{
				$adminNotice = [
					'TYPE' => 'OK',
					'MESSAGE' => 'Сопоставление #' . $editId . ' обновлено: «' . $alias . '» → «' . $canon . '».',
				];
			}
		}
	}
	elseif ($action === 'update_skip')
	{
		$editId = (int)($_POST['edit_id'] ?? 0);
		$aliasRaw = trim((string)($_POST['edit_skip_alias'] ?? ''));

		$err = mf_bm_update_skip_by_id($editId, $aliasRaw);
		if ($err !== null)
		{
			$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => $err];
		}
		else
		{
			$adminNotice = [
				'TYPE' => 'OK',
				'MESSAGE' => 'Запись пропуска #' . $editId . ' обновлена: «' . $aliasRaw . '».',
			];
		}
	}
	elseif ($action === 'delete_alias')
	{
		$delId = (int)($_POST['delete_id'] ?? 0);
		if ($delId <= 0)
		{
			$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не передан ID записи.'];
		}
		else
		{
			try
			{
				$hl = mf_brand_hl_ensure(false);
				if ($hl && !empty($hl['DATA_CLASS']))
				{
					$hl['DATA_CLASS']::delete($delId);
					mf_brand_aliases_reset_cache();
					$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Сопоставление #' . $delId . ' удалено.'];
				}
			}
			catch (\Throwable $e)
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка удаления: ' . $e->getMessage()];
			}
		}
	}
	elseif ($action === 'delete_skip')
	{
		$delId = (int)($_POST['delete_id'] ?? 0);
		if ($delId <= 0)
		{
			$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Не передан ID записи.'];
		}
		elseif (!mf_brand_import_skip_ensure_table())
		{
			$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Таблица mf_brand_import_skip недоступна.'];
		}
		else
		{
			try
			{
				Application::getConnection()->queryExecute(
					'DELETE FROM mf_brand_import_skip WHERE ID=' . $delId
				);
				$adminNotice = ['TYPE' => 'OK', 'MESSAGE' => 'Запись пропуска #' . $delId . ' удалена.'];
			}
			catch (\Throwable $e)
			{
				$adminNotice = ['TYPE' => 'ERROR', 'MESSAGE' => 'Ошибка удаления: ' . $e->getMessage()];
			}
		}
	}
}

$catalogBrands = mf_bm_catalog_brand_choices();
$catalogBrandsLookup = array_fill_keys($catalogBrands, true);

$conn = Application::getConnection();
$warehouses = mf_bm_load_warehouses($conn);

$findWarehouse = trim((string)($_REQUEST['find_warehouse'] ?? ''));
$findBrandCand = trim((string)($_REQUEST['find_brand'] ?? ''));
// Снятый чекбокс не уходит в запрос — без явного N считаем «вкл» только при первом заходе.
$onlyUnmapped = array_key_exists('bm_only_unmapped', $_REQUEST)
	? (string)$_REQUEST['bm_only_unmapped'] === 'Y'
	: true;
$candPerPage = 50;
$candPage = max(1, (int)($_REQUEST['cand_page'] ?? 1));

$stockCandidateRows = mf_bm_load_stock_candidate_rows($conn, $findWarehouse, $findBrandCand);
$priceCandidateRows = mf_bm_load_price_candidate_rows($conn, $findBrandCand);
$candidates = mf_bm_build_candidates($stockCandidateRows, $priceCandidateRows, $onlyUnmapped);
$candTotal = count($candidates);
$candStockRaw = count($stockCandidateRows);
$candPriceRaw = count($priceCandidateRows);
$candStockShown = 0;
$candPriceShown = 0;
foreach ($candidates as $candRow)
{
	$sources = (array)($candRow['SOURCES'] ?? []);
	if (in_array('stock', $sources, true))
	{
		$candStockShown++;
	}
	if (in_array('price', $sources, true))
	{
		$candPriceShown++;
	}
}
$candPages = max(1, (int)ceil($candTotal / $candPerPage));
if ($candPage > $candPages)
{
	$candPage = $candPages;
}

$activeOnly = array_key_exists('active_only', $_REQUEST)
	? (string)$_REQUEST['active_only'] === 'Y'
	: true;
$find = trim((string)($_REQUEST['find'] ?? ''));
$perPage = max(10, min(200, (int)($_REQUEST['per_page'] ?? 50)));
$page = max(1, (int)($_REQUEST['page'] ?? 1));

$warehouseCatalogBrandKeys = mf_bm_load_warehouse_catalog_brand_keys($conn, $findWarehouse);
$warehouseCatalogBrandCount = is_array($warehouseCatalogBrandKeys)
	? count($warehouseCatalogBrandKeys['raws'])
	: 0;
$warehouseStoreProductCount = 0;
$warehouseStoreProductIdCount = 0;
$warehouseElementCount = 0;
$warehouseFilterRelaxed = false;
if ($findWarehouse !== '')
{
	$whStoreId = mf_bm_resolve_store_id_by_warehouse_xml($findWarehouse);
	if ($whStoreId > 0)
	{
		try
		{
			$cntRow = $conn->query(
				'SELECT COUNT(*) AS CNT FROM b_catalog_store_product WHERE STORE_ID=' . (int)$whStoreId
			)->fetch();
			$warehouseStoreProductCount = (int)($cntRow['CNT'] ?? 0);
		}
		catch (\Throwable $e)
		{
			$warehouseStoreProductCount = 0;
		}
		$warehouseStoreProductIdCount = count(mf_bm_store_product_ids_at_store($conn, $whStoreId));
		$warehouseElementCount = count(mf_bm_collect_catalog_element_ids_at_store($conn, $whStoreId));
	}
}
// Пустой набор брендов при ненулевых остатках = не режем справочник (иначе всегда 0 строк).
$aliasWarehouseKeys = $warehouseCatalogBrandKeys;
$skipWarehouseKeys = $warehouseCatalogBrandKeys;
if (
	$findWarehouse !== ''
	&& is_array($warehouseCatalogBrandKeys)
	&& $warehouseCatalogBrandCount === 0
	&& ($warehouseStoreProductCount > 0 || $warehouseElementCount > 0)
)
{
	$aliasWarehouseKeys = null;
	$skipWarehouseKeys = null;
	$warehouseFilterRelaxed = true;
}
$aliasRows = mf_bm_load_alias_rows($activeOnly, $find, $aliasWarehouseKeys);
$warehouseBrandStrings = [];
$aliasVirtualRows = [];
if ($findWarehouse !== '' && !$warehouseFilterRelaxed)
{
	$warehouseBrandStrings = mf_bm_load_warehouse_catalog_brand_strings($conn, $findWarehouse);
	$hlRowsForCoverage = mf_bm_load_alias_rows($activeOnly, '', null);
	$aliasVirtualRows = mf_bm_build_virtual_warehouse_brand_rows($warehouseBrandStrings, $hlRowsForCoverage, $find);
}
$warehouseBrandOnStoreCount = $warehouseBrandStrings !== []
	? count($warehouseBrandStrings)
	: $warehouseCatalogBrandCount;
$aliasVirtualCount = count($aliasVirtualRows);
$aliasHlRowCount = count($aliasRows);
$warehouseBrandsWithHlCount = max(0, $warehouseBrandOnStoreCount - $aliasVirtualCount);
$aliasDisplayRows = array_merge($aliasRows, $aliasVirtualRows);
usort(
	$aliasDisplayRows,
	static function (array $a, array $b): int {
		$aa = trim((string)($a['UF_ALIAS'] ?? ''));
		$bb = trim((string)($b['UF_ALIAS'] ?? ''));
		if ($aa === '' && $bb === '')
		{
			return ((int)($a['ID'] ?? 0)) <=> ((int)($b['ID'] ?? 0));
		}
		if ($aa === '')
		{
			return 1;
		}
		if ($bb === '')
		{
			return -1;
		}

		return strnatcasecmp($aa, $bb);
	}
);
$skipRows = mf_bm_load_import_skip_rows($find, $skipWarehouseKeys);
$warehouseFilterTitle = ($findWarehouse !== '' && isset($warehouses[$findWarehouse]))
	? (string)$warehouses[$findWarehouse]
	: ($findWarehouse !== '' ? $findWarehouse : '');

$aliasTotal = count($aliasDisplayRows);
$aliasPages = max(1, (int)ceil($aliasTotal / $perPage));
if ($page > $aliasPages)
{
	$page = $aliasPages;
}
$aliasSlice = array_slice($aliasDisplayRows, ($page - 1) * $perPage, $perPage);
$aliasVirtualOnPage = 0;
foreach ($aliasSlice as $aliasRowCheck)
{
	if (!empty($aliasRowCheck['IS_VIRTUAL']))
	{
		$aliasVirtualOnPage++;
	}
}

$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$curPage = (string)($APPLICATION->GetCurPage() ?? 'mf_brand_map.php');
$baseUrl = $curPage . '?lang=' . rawurlencode($lang);

$navRemove = ['page'];
$candNavRemove = ['cand_page'];
$baseParams = [
	'lang' => $lang,
	'active_only' => $activeOnly ? 'Y' : 'N',
	'find' => $find,
	'per_page' => (string)$perPage,
	'find_warehouse' => $findWarehouse,
	'find_brand' => $findBrandCand,
	'bm_only_unmapped' => $onlyUnmapped ? 'Y' : 'N',
	'cand_page' => (string)$candPage,
];

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetTitle('Сопоставление брендов');

?>
<style>
.mf-bss { max-width: 1280px; font-family: var(--ui-font-family-primary, "Helvetica Neue", Helvetica, Arial, sans-serif); color: #333; }
.mf-bss__lead { margin: 0 0 20px 0; padding: 14px 16px; background: linear-gradient(135deg, #f0f4f8 0%, #e8eef5 100%); border: 1px solid #d5dde8; border-radius: 8px; font-size: 13px; line-height: 1.55; color: #4a5568; }
.mf-bss__lead strong { color: #1a202c; }
.mf-bss__lead code { background: rgba(255,255,255,.7); padding: 1px 5px; border-radius: 3px; font-size: 12px; }
.mf-bss__cards { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
.mf-bss__card { flex: 1 1 140px; min-width: 120px; padding: 16px 18px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 1px 3px rgba(15,23,42,.06); }
.mf-bss__card-label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #718096; margin-bottom: 6px; }
.mf-bss__card-value { font-size: 28px; font-weight: 600; line-height: 1.1; color: #2d3748; }
.mf-bss__card-value--sm { font-size: 22px; }
.mf-bss__card-hint { font-size: 11px; color: #a0aec0; margin-top: 6px; }
.mf-bss__filters { margin-bottom: 20px; padding: 16px 18px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
.mf-bss__filters-title { font-size: 14px; font-weight: 600; margin: 0 0 14px 0; color: #2d3748; }
.mf-bss__filters-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px 20px; }
.mf-bss__field label { display: block; font-size: 12px; color: #4a5568; margin-bottom: 5px; }
.mf-bss__field input[type="text"], .mf-bss__field select {
	padding: 7px 10px;
	border: 1px solid #cbd5e0;
	border-radius: 6px;
	font-size: 13px;
	box-sizing: border-box;
}
.mf-bss__field input[type="text"] { min-width: 200px; }
.mf-bss__field select { min-width: 120px; width: auto; max-width: none; }
/* Bitrix .adm-workarea select { height:27px } — режет текст по вертикали */
.mf-bss__field select,
.mf-bss__field select option {
	line-height: 1.45;
}
.mf-bss__field select {
	height: auto !important;
	min-height: 2.35em;
	padding: 8px 28px 8px 10px !important;
}
.mf-bss__field select option {
	padding: 6px 10px;
	min-height: 1.75em;
}
.mf-bss__field--canon { flex: 1 1 280px; max-width: 520px; min-width: 240px; }
.mf-bss__field--canon select {
	width: 100%;
	min-width: 240px;
	max-width: 520px;
}
.mf-bss__field input[type="text"]:focus, .mf-bss__field select:focus { border-color: #4299e1; outline: none; box-shadow: 0 0 0 2px rgba(66,153,225,.2); }
.mf-bss__checks { display: flex; flex-direction: column; gap: 8px; padding-bottom: 2px; }
.mf-bss__check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #4a5568; cursor: pointer; user-select: none; }
.mf-bss__check input { margin: 0; }
.mf-bss__actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.mf-bss__reset { font-size: 13px; color: #718096; text-decoration: none; }
.mf-bss__reset:hover { color: #2b6cb0; text-decoration: underline; }
.mf-bss__section-title { font-size: 15px; font-weight: 600; margin: 0 0 12px 0; color: #2d3748; }
.mf-bss__section { margin-bottom: 16px; }
.mf-bss__section-toggle {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	margin: 0;
	padding: 12px 16px;
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 10px;
	cursor: pointer;
	text-align: left;
	font-size: 15px;
	font-weight: 600;
	color: #2d3748;
	box-shadow: 0 1px 3px rgba(15,23,42,.05);
}
.mf-bss__section-toggle:hover { background: #f7fafc; border-color: #cbd5e0; }
.mf-bss__section.is-open > .mf-bss__section-toggle {
	border-radius: 10px 10px 0 0;
	border-bottom-color: #edf2f7;
	box-shadow: none;
}
.mf-bss__section-body {
	border: 1px solid #e2e8f0;
	border-top: none;
	border-radius: 0 0 10px 10px;
	padding: 14px 16px 16px;
	background: #fff;
	box-shadow: 0 1px 3px rgba(15,23,42,.05);
}
.mf-bss__section:not(.is-open) > .mf-bss__section-body { display: none; }
.mf-bss__section-subtitle { font-size: 12px; font-weight: 400; color: #718096; }
.mf-bss__section-badge {
	font-size: 12px;
	font-weight: 600;
	color: #4a5568;
	background: #edf2f7;
	padding: 2px 8px;
	border-radius: 999px;
	font-variant-numeric: tabular-nums;
}
.mf-bss__section-chevron { margin-left: auto; color: #a0aec0; font-size: 11px; line-height: 1; }
.mf-bss__section-body .mf-bss__add,
.mf-bss__section-body .mf-bss__filters {
	margin-bottom: 0;
	padding: 0;
	border: none;
	border-radius: 0;
	box-shadow: none;
	background: transparent;
}
.mf-bss__section-body .mf-bss__filters-title { display: none; }
.mf-bss__section-body .mf-bss__table-wrap { margin-bottom: 0; }
.mf-bss__section-body .mf-bss__cards { margin-bottom: 12px; }
.mf-bss__section-body .mf-bss__pager { margin-top: 12px; margin-bottom: 0; }
.mf-bss__table-wrap { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(15,23,42,.06); margin-bottom: 24px; }
.mf-bss__table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mf-bss__table thead { background: #f7fafc; border-bottom: 1px solid #e2e8f0; }
.mf-bss__table th { padding: 11px 14px; text-align: left; font-weight: 600; font-size: 12px; color: #4a5568; text-transform: uppercase; letter-spacing: .03em; }
.mf-bss__table th.mf-bss__th-num { width: 52px; text-align: center; }
.mf-bss__table th.mf-bss__th-arrow { width: 36px; text-align: center; }
.mf-bss__table th.mf-bss__th-meta { width: 100px; text-align: center; }
.mf-bss__table th.mf-bss__th-date { width: 130px; text-align: right; }
.mf-bss__table td { padding: 10px 14px; border-bottom: 1px solid #edf2f7; vertical-align: middle; }
.mf-bss__table tbody tr:last-child td { border-bottom: none; }
.mf-bss__table tbody tr:hover { background: #f7fafc; }
.mf-bss__table tbody tr:nth-child(even) { background: #fafbfc; }
.mf-bss__table tbody tr:nth-child(even):hover { background: #f1f5f9; }
.mf-bss__num { text-align: center; color: #a0aec0; font-size: 12px; font-variant-numeric: tabular-nums; }
.mf-bss__alias { font-weight: 500; color: #4a5568; }
.mf-bss__canon { font-weight: 600; color: #2d3748; }
.mf-bss__arrow { text-align: center; color: #a0aec0; font-size: 16px; }
.mf-bss__meta { text-align: center; font-variant-numeric: tabular-nums; color: #4a5568; }
.mf-bss__date { text-align: right; color: #718096; font-size: 12px; white-space: nowrap; }
.mf-bss__badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.mf-bss__badge--ok { background: #c6f6d5; color: #22543d; }
.mf-bss__badge--off { background: #fed7d7; color: #742a2a; }
.mf-bss__badge--skip { background: #feebc8; color: #7b341e; }
.mf-bss__badge--stock { background: #bee3f8; color: #2c5282; }
.mf-bss__badge--price { background: #e9d8fd; color: #553c9a; }
.mf-bss__sources { display: flex; flex-wrap: wrap; gap: 4px; }
.mf-bss__field--warehouse select { min-width: 280px; max-width: 420px; }
.mf-bss__empty { padding: 32px 20px; text-align: center; color: #718096; font-size: 14px; }
.mf-bss__pager { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 14px; }
.mf-bss__pager-btn { display: inline-block; padding: 6px 12px; border: 1px solid #cbd5e0; border-radius: 6px; background: #fff; color: #2b6cb0; text-decoration: none; font-size: 13px; }
.mf-bss__pager-btn:hover { background: #ebf8ff; border-color: #90cdf4; }
.mf-bss__pager-btn--cur { background: #4299e1; border-color: #4299e1; color: #fff; pointer-events: none; }
.mf-bss__footer { margin-top: 8px; padding-top: 14px; font-size: 12px; color: #718096; line-height: 1.6; }
.mf-bss__footer a { color: #2b6cb0; text-decoration: none; }
.mf-bss__footer a:hover { text-decoration: underline; }
.mf-bss__add { margin-bottom: 20px; padding: 16px 18px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
.mf-bss__add-hint { font-size: 12px; color: #718096; margin: 0 0 12px 0; line-height: 1.45; }
.mf-bss__add-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px 18px; }
.mf-bss__field--wide input[type="text"] { min-width: 260px; }
.mf-bss__th-actions { width: 150px; text-align: center; }
.mf-bss__actions-cell { text-align: center; white-space: nowrap; }
.mf-bss__row-actions { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; align-items: center; }
.mf-bss__table--map { table-layout: fixed; }
.mf-bss__th-alias { width: 36%; }
.mf-bss__th-canon { width: 30%; }
.mf-bss__th-more { width: 44px; text-align: center; }
.mf-bss__inp-cell { min-width: 120px; }
.mf-bss__inp-cell--alias input[type="text"] {
	width: 100%;
	min-width: 160px;
	max-width: none;
	padding: 6px 8px;
	font-size: 13px;
}
.mf-bss__inp-cell input[type="text"] {
	width: 100%;
	min-width: 100px;
	padding: 6px 8px;
	font-size: 13px;
}
.mf-bss__inp-cell--canon select {
	width: 100%;
	min-width: 160px;
	max-width: none;
}
.mf-bss__btn-more {
	font-size: 11px;
	line-height: 1;
	color: #4a5568;
	background: #edf2f7;
	border: 1px solid #cbd5e0;
	border-radius: 6px;
	padding: 5px 8px;
	cursor: pointer;
	vertical-align: middle;
}
.mf-bss__btn-more:hover { background: #e2e8f0; color: #2d3748; }
.mf-bss__btn-more[aria-expanded="true"] { background: #ebf8ff; border-color: #90cdf4; color: #2b6cb0; }
.mf-bss__details-row td {
	background: #f7fafc;
	padding: 8px 14px 10px 52px;
	font-size: 12px;
	color: #718096;
	border-bottom: 1px solid #edf2f7;
}
.mf-bss__details-row[hidden] { display: none; }
.mf-bss__details-kv { display: flex; flex-wrap: wrap; gap: 8px 20px; }
.mf-bss__details-kv strong { color: #4a5568; font-weight: 600; }
.mf-bss__check-inline {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-size: 12px;
	color: #4a5568;
	cursor: pointer;
	white-space: nowrap;
}
.mf-bss__check-inline input { margin: 0; }
.mf-bss__btn-save { font-size: 12px; color: #2b6cb0; background: #ebf8ff; border: 1px solid #90cdf4; border-radius: 6px; padding: 4px 10px; cursor: pointer; }
.mf-bss__btn-save:hover { background: #bee3f8; }
.mf-bss__btn-del { font-size: 12px; color: #c53030; background: transparent; border: 1px solid #feb2b2; border-radius: 6px; padding: 4px 10px; cursor: pointer; }
.mf-bss__btn-del:hover { background: #fff5f5; }
.mf-bss__sort-readonly { color: #a0aec0; font-size: 12px; }
.adm-workarea .mf-bss select {
	height: auto !important;
	min-height: 2.35em !important;
	line-height: 1.45 !important;
	padding-top: 8px !important;
	padding-bottom: 8px !important;
}
.adm-workarea .mf-bss__field--canon select {
	max-width: 520px !important;
	width: 100% !important;
}
</style>

<div class="mf-bss">
	<div class="mf-bss__lead">
		Справочник <strong>алиас → канон</strong> из HL <code>mf_brand_alias</code>: как бренд приходит в прайсе/остатках и во что он переводится при импорте.
		Фильтр <strong>«Склад»</strong>: <strong>кандидаты</strong> — из <code>mf_stock_import_missing</code>.
		<strong>Сопоставления</strong> при выборе склада — все <code>MF_BRAND</code> товаров на складе: записи HL и бренды <em>без</em> записи в справочнике (по умолчанию «не менять»).
		Чтобы увидеть весь справочник — выберите «— все склады —».
		Кандидаты — бренды из <code>mf_stock_import_missing</code> и последних внешних прайсов.
		Отдельно — бренды из <code>mf_brand_import_skip</code>, которые <strong>не импортируются</strong>.
	</div>

	<?php if (is_array($adminNotice)): ?>
		<?php \CAdminMessage::ShowMessage($adminNotice); ?>
	<?php endif; ?>
	<?php if ($warehouseFilterRelaxed): ?>
		<?php \CAdminMessage::ShowMessage([
			'TYPE' => 'OK',
			'MESSAGE' => 'На складе есть остатки ('
				. (int)$warehouseStoreProductCount . ' поз., '
				. (int)$warehouseStoreProductIdCount . ' PRODUCT_ID'
				. ($warehouseElementCount > 0 ? ', товаров каталога ' . (int)$warehouseElementCount : ', товары каталога не определены')
				. '), но бренды MF_BRAND не удалось прочитать — показан <b>полный</b> справочник сопоставлений без фильтра.',
		]); ?>
	<?php elseif ($warehouseFilterTitle !== '' && $warehouseCatalogBrandCount > 0 && $aliasTotal === 0): ?>
		<?php \CAdminMessage::ShowMessage([
			'TYPE' => 'ERROR',
			'MESSAGE' => 'На складе найдено ' . (int)$warehouseCatalogBrandCount
				. ' бренд. в каталоге, но в HL <code>mf_brand_alias</code> нет сопоставлений с такими канонами/алиасами. Добавьте через «Кандидаты» или форму «Добавить запись».',
		]); ?>
	<?php endif; ?>

	<section class="mf-bss__section js-mf-bm-section" data-section-id="add" data-default-open="0">
		<button type="button" class="mf-bss__section-toggle js-mf-bm-section-toggle" aria-expanded="false" aria-controls="mf_bm_section_add">
			<span>Добавить запись</span>
			<span class="mf-bss__section-chevron js-mf-bm-section-chevron" aria-hidden="true">▶</span>
		</button>
		<div class="mf-bss__section-body js-mf-bm-section-body" id="mf_bm_section_add">
	<form method="post" action="<?= mf_bm_escape($curPage) ?>" class="mf-bss__add">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
		<input type="hidden" name="bm_action" value="add">
		<input type="hidden" name="active_only" value="<?= $activeOnly ? 'Y' : 'N' ?>">
		<input type="hidden" name="find" value="<?= mf_bm_escape($find) ?>">
		<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
		<input type="hidden" name="page" value="<?= (int)$page ?>">
		<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($findWarehouse) ?>">
		<input type="hidden" name="find_brand" value="<?= mf_bm_escape($findBrandCand) ?>">
		<input type="hidden" name="bm_only_unmapped" value="<?= $onlyUnmapped ? 'Y' : 'N' ?>">
		<input type="hidden" name="cand_page" value="<?= (int)$candPage ?>">
		<p class="mf-bss__add-hint">
			Текст бренда — <b>как в файле поставщика</b>. Выберите канон из списка или укажите свой («Свой канон» в приоритете, если заполнен).
			Приоритет новых сопоставлений: <?= (int)MF_BM_MANUAL_ALIAS_SORT ?>.
		</p>
		<div class="mf-bss__add-row">
			<div class="mf-bss__field mf-bss__field--wide">
				<label for="mf_bm_add_alias">Бренд в файле (алиас)</label>
				<input type="text" name="add_alias" id="mf_bm_add_alias" value="" placeholder="например Ski-Doo" required>
			</div>
			<div class="mf-bss__field">
				<label for="mf_bm_add_mode">Действие</label>
				<select name="add_mode" id="mf_bm_add_mode">
					<option value="map">Сопоставить с каноном</option>
					<option value="skip">Не импортировать</option>
				</select>
			</div>
			<div class="mf-bss__field mf-bss__field--canon">
				<label for="mf_bm_add_canon">Канон (из каталога)</label>
				<select name="add_canon" id="mf_bm_add_canon" title="Канонический бренд из MF_BRAND">
					<?= mf_bm_canon_options_html($catalogBrands) ?>
				</select>
			</div>
			<div class="mf-bss__field mf-bss__field--wide">
				<label for="mf_bm_add_canon_custom">Свой канон (необязательно)</label>
				<input type="text" name="add_canon_custom" id="mf_bm_add_canon_custom" value="" placeholder="если нет в списке">
			</div>
			<div class="mf-bss__actions">
				<input type="submit" class="adm-btn-save" value="Сохранить">
			</div>
		</div>
	</form>
		</div>
	</section>
	<script>
	(function () {
		var sel = document.getElementById('mf_bm_add_canon');
		if (!sel) { return; }
		function syncTitle() {
			var opt = sel.options[sel.selectedIndex];
			sel.title = opt ? (opt.text || opt.value || '') : '';
		}
		sel.addEventListener('change', syncTitle);
		syncTitle();
	})();
	</script>

	<select id="mf_bm_canon_options_tpl" class="mf-bss__canon-tpl" hidden aria-hidden="true" tabindex="-1">
		<?= mf_bm_canon_options_html($catalogBrands) ?>
	</select>

	<section class="mf-bss__section js-mf-bm-section is-open" data-section-id="filters" data-default-open="1">
		<button type="button" class="mf-bss__section-toggle js-mf-bm-section-toggle" aria-expanded="true" aria-controls="mf_bm_section_filters">
			<span>Фильтры</span>
			<span class="mf-bss__section-chevron js-mf-bm-section-chevron" aria-hidden="true">▼</span>
		</button>
		<div class="mf-bss__section-body js-mf-bm-section-body" id="mf_bm_section_filters">
	<form method="get" action="<?= mf_bm_escape($curPage) ?>" class="mf-bss__filters">
		<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
		<input type="hidden" name="bm_filters" value="1">
		<div class="mf-bss__filters-row">
			<div class="mf-bss__field mf-bss__field--warehouse">
				<label for="mf_bm_find_warehouse">Склад</label>
				<select name="find_warehouse" id="mf_bm_find_warehouse">
					<option value="" <?= $findWarehouse === '' ? 'selected' : '' ?>>— все склады —</option>
					<?php foreach ($warehouses as $xml => $title): ?>
						<option value="<?= mf_bm_escape((string)$xml) ?>" <?= $findWarehouse === (string)$xml ? 'selected' : '' ?>>
							<?= mf_bm_escape((string)$title) ?> (<?= mf_bm_escape((string)$xml) ?>)
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mf-bss__field">
				<label for="mf_bm_find_brand">Бренд в импорте (подстрока)</label>
				<input type="text" id="mf_bm_find_brand" name="find_brand" value="<?= mf_bm_escape($findBrandCand) ?>" placeholder="например BRP">
			</div>
			<div class="mf-bss__checks">
				<label class="mf-bss__check" title="Только для секции «Кандидаты из импорта»">
					<input type="hidden" name="bm_only_unmapped" value="N">
					<input type="checkbox" name="bm_only_unmapped" value="Y" <?= $onlyUnmapped ? 'checked' : '' ?>>
					Кандидаты: без сопоставления
				</label>
				<label class="mf-bss__check" title="Только для секции «Сопоставления» (HL mf_brand_alias)">
					<input type="hidden" name="active_only" value="N">
					<input type="checkbox" name="active_only" value="Y" <?= $activeOnly ? 'checked' : '' ?>>
					Сопоставления: только активные
				</label>
			</div>
			<div class="mf-bss__field">
				<label for="mf_bm_find">Поиск в справочнике</label>
				<input type="text" id="mf_bm_find" name="find" value="<?= mf_bm_escape($find) ?>" placeholder="алиас или канон">
			</div>
			<div class="mf-bss__field">
				<label for="mf_bm_per_page">Сопоставлений на стр.</label>
				<select name="per_page" id="mf_bm_per_page">
					<?php foreach ([25, 50, 100, 200] as $pp): ?>
						<option value="<?= (int)$pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= (int)$pp ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mf-bss__actions">
				<input type="submit" class="adm-btn-save" value="Показать">
				<a class="mf-bss__reset" href="<?= mf_bm_escape($baseUrl) ?>">Сбросить</a>
			</div>
		</div>
	</form>
		</div>
	</section>

	<section class="mf-bss__section js-mf-bm-section is-open" data-section-id="candidates" data-default-open="1">
		<button type="button" class="mf-bss__section-toggle js-mf-bm-section-toggle" aria-expanded="true" aria-controls="mf_bm_section_candidates">
			<span>Кандидаты из импорта</span>
			<span class="mf-bss__section-badge"><?= number_format($candTotal, 0, '', ' ') ?></span>
			<span class="mf-bss__section-chevron js-mf-bm-section-chevron" aria-hidden="true">▼</span>
		</button>
		<div class="mf-bss__section-body js-mf-bm-section-body" id="mf_bm_section_candidates">
	<div class="mf-bss__cards">
		<div class="mf-bss__card">
			<div class="mf-bss__card-label">В списке</div>
			<div class="mf-bss__card-value mf-bss__card-value--sm"><?= number_format($candTotal, 0, '', ' ') ?></div>
			<?php if ($candPages > 1): ?>
				<div class="mf-bss__card-hint">стр. <?= (int)$candPage ?> из <?= (int)$candPages ?></div>
			<?php elseif ($onlyUnmapped): ?>
				<div class="mf-bss__card-hint">без сопоставления</div>
			<?php endif; ?>
		</div>
		<div class="mf-bss__card">
			<div class="mf-bss__card-label">Остатки</div>
			<div class="mf-bss__card-value mf-bss__card-value--sm"><?= number_format($candStockShown, 0, '', ' ') ?></div>
			<div class="mf-bss__card-hint">
				<?php if ($candStockRaw > $candStockShown): ?>
					в ненайденных: <?= number_format($candStockRaw, 0, '', ' ') ?>,
					скрыто <?= number_format($candStockRaw - $candStockShown, 0, '', ' ') ?> (уже сопоставлены)
				<?php else: ?>
					mf_stock_import_missing
				<?php endif; ?>
			</div>
		</div>
		<div class="mf-bss__card">
			<div class="mf-bss__card-label">Прайс</div>
			<div class="mf-bss__card-value mf-bss__card-value--sm"><?= number_format($candPriceShown, 0, '', ' ') ?></div>
			<div class="mf-bss__card-hint">
				<?php if ($candPriceRaw > $candPriceShown): ?>
					в файлах: <?= number_format($candPriceRaw, 0, '', ' ') ?>,
					скрыто <?= number_format($candPriceRaw - $candPriceShown, 0, '', ' ') ?> (уже сопоставлены)
				<?php else: ?>
					последние <?= (int)MF_BM_PRICE_JOBS_LIMIT ?> импорта
				<?php endif; ?>
			</div>
		</div>
	</div>

	<form method="post" action="<?= mf_bm_escape($curPage) ?>" class="mf-bss__table-wrap" id="mf_bm_candidates_form">
		<?= bitrix_sessid_post() ?>
		<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
		<input type="hidden" name="bm_action" value="save_candidates">
		<input type="hidden" name="active_only" value="<?= $activeOnly ? 'Y' : 'N' ?>">
		<input type="hidden" name="find" value="<?= mf_bm_escape($find) ?>">
		<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
		<input type="hidden" name="page" value="<?= (int)$page ?>">
		<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($findWarehouse) ?>">
		<input type="hidden" name="find_brand" value="<?= mf_bm_escape($findBrandCand) ?>">
		<input type="hidden" name="bm_only_unmapped" value="<?= $onlyUnmapped ? 'Y' : 'N' ?>">
		<input type="hidden" name="cand_page" value="<?= (int)$candPage ?>">
		<?php if ($candTotal === 0): ?>
			<div class="mf-bss__empty">
				Нет кандидатов по выбранным фильтрам.
				<?php if (!mf_bm_table_exists($conn, 'mf_stock_import_missing')): ?>
					<br/>Таблица <code>mf_stock_import_missing</code> ещё не создана.
				<?php endif; ?>
			</div>
		<?php else: ?>
			<table class="mf-bss__table mf-bss__table--map">
				<thead>
				<tr>
					<th class="mf-bss__th-num">#</th>
					<th class="mf-bss__th-alias">Бренд в файле</th>
					<th class="mf-bss__th-meta">Кол-во</th>
					<th class="mf-bss__th-date">Последнее</th>
					<th class="mf-bss__th-meta">Сейчас</th>
					<th class="mf-bss__th-canon">Сопоставить</th>
				</tr>
				</thead>
				<tbody>
				<?php
				$candRowNum = ($candPage - 1) * $candPerPage;
				foreach ($candidates as $candIdx => $cand):
					$candInPage = $candIdx >= ($candPage - 1) * $candPerPage && $candIdx < $candPage * $candPerPage;
					if (!$candInPage)
					{
						continue;
					}
					$candRowNum++;
					$alias = (string)($cand['BRAND'] ?? '');
					$canon = (string)($cand['CANON'] ?? '');
					$isSkip = !empty($cand['IS_SKIP']);
					$bmPrefCatalog = '';
					if (!$isSkip && $canon !== '' && isset($catalogBrandsLookup[$canon]))
					{
						$bmPrefCatalog = $canon;
					}
					?>
					<tr>
						<td class="mf-bss__num"><?= $candRowNum ?></td>
						<td class="mf-bss__alias"><?= mf_bm_escape($alias) ?></td>
						<td class="mf-bss__meta"><?= (int)($cand['CNT'] ?? 0) ?></td>
						<td class="mf-bss__date"><?= mf_bm_escape(mf_bm_format_dt($cand['LAST_SEEN'] ?? null)) ?></td>
						<td class="mf-bss__meta">
							<?php if ($isSkip): ?>
								<span class="mf-bss__badge mf-bss__badge--skip">пропуск</span>
							<?php elseif ($canon !== ''): ?>
								<span class="mf-bss__canon"><?= mf_bm_escape($canon) ?></span>
							<?php else: ?>
								—
							<?php endif; ?>
						</td>
						<td class="mf-bss__inp-cell mf-bss__inp-cell--canon">
							<?php
							$candSel = $isSkip ? MF_BM_MAP_SKIP : $bmPrefCatalog;
							?>
							<select name="map[<?= mf_bm_escape($alias) ?>]" class="js-mf-bm-cand-canon" title="Выберите канон или «не импортировать»">
								<option value="">— не менять —</option>
								<option value="<?= mf_bm_escape(MF_BM_MAP_SKIP) ?>" <?= $candSel === MF_BM_MAP_SKIP ? 'selected' : '' ?>>— не импортировать —</option>
								<?php foreach ($catalogBrands as $bCh): ?>
									<?php
									$bCh = (string)$bCh;
									if ($bCh === '')
									{
										continue;
									}
									$esc = mf_bm_escape($bCh);
									$selAttr = ($candSel !== '' && $candSel === $bCh) ? ' selected' : '';
									?>
									<option value="<?= $esc ?>" title="<?= $esc ?>"<?= $selAttr ?>><?= $esc ?></option>
								<?php endforeach; ?>
								<?php if ($canon !== '' && $canon !== MF_BM_MAP_SKIP && !isset($catalogBrandsLookup[$canon])): ?>
									<option value="<?= mf_bm_escape($canon) ?>" selected><?= mf_bm_escape($canon) ?> (текущий)</option>
								<?php endif; ?>
							</select>
							<?php if ($canon !== '' && !isset($catalogBrandsLookup[$canon]) && !$isSkip): ?>
								<div style="font-size:11px;color:#718096;margin-top:4px;">текущий: <?= mf_bm_escape($canon) ?></div>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div style="padding:12px 14px;border-top:1px solid #edf2f7;">
				<input type="submit" class="adm-btn-save" value="Сохранить выбранные сопоставления">
			</div>
		<?php endif; ?>
	</form>

	<?php if ($candPages > 1): ?>
		<div class="mf-bss__pager">
			<?php
			$clo = max(1, $candPage - 3);
			$chi = min($candPages, $candPage + 3);
			if ($candPage > 1):
				$params = $baseParams;
				$params['cand_page'] = (string)($candPage - 1);
				$params['page'] = (string)$page;
				$url = htmlspecialcharsbx($APPLICATION->GetCurPageParam(http_build_query($params), $candNavRemove));
				?>
				<a class="mf-bss__pager-btn" href="<?= $url ?>">← Назад</a>
			<?php endif;
			for ($cp = $clo; $cp <= $chi; $cp++):
				$params = $baseParams;
				$params['cand_page'] = (string)$cp;
				$params['page'] = (string)$page;
				$url = htmlspecialcharsbx($APPLICATION->GetCurPageParam(http_build_query($params), $candNavRemove));
				$cls = $cp === $candPage ? 'mf-bss__pager-btn mf-bss__pager-btn--cur' : 'mf-bss__pager-btn';
				?>
				<a class="<?= $cls ?>" href="<?= $url ?>"><?= (int)$cp ?></a>
			<?php endfor;
			if ($candPage < $candPages):
				$params = $baseParams;
				$params['cand_page'] = (string)($candPage + 1);
				$params['page'] = (string)$page;
				$url = htmlspecialcharsbx($APPLICATION->GetCurPageParam(http_build_query($params), $candNavRemove));
				?>
				<a class="mf-bss__pager-btn" href="<?= $url ?>">Вперёд →</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
		</div>
	</section>

	<section class="mf-bss__section js-mf-bm-section is-open" data-section-id="mappings" data-default-open="1">
		<button type="button" class="mf-bss__section-toggle js-mf-bm-section-toggle" aria-expanded="true" aria-controls="mf_bm_section_mappings">
			<span>
				Сопоставления
				<?php if ($warehouseFilterTitle !== ''): ?>
					<span class="mf-bss__section-subtitle"> — <?= mf_bm_escape($warehouseFilterTitle) ?></span>
				<?php endif; ?>
			</span>
			<span class="mf-bss__section-badge"><?= number_format($aliasTotal, 0, '', ' ') ?></span>
			<span class="mf-bss__section-chevron js-mf-bm-section-chevron" aria-hidden="true">▼</span>
		</button>
		<div class="mf-bss__section-body js-mf-bm-section-body" id="mf_bm_section_mappings">
	<p class="mf-bss__add-hint" style="margin:0 0 12px;">
		<?php if ($warehouseFilterTitle !== ''): ?>
			На складе <strong><?= (int)$warehouseBrandOnStoreCount ?></strong> уникальных брендов в <code>MF_BRAND</code>:
			<strong><?= (int)$aliasVirtualCount ?></strong> без записи в HL,
			<strong><?= (int)$warehouseBrandsWithHlCount ?></strong> с сопоставлением
			(<strong><?= (int)$aliasHlRowCount ?></strong> записей-алиасов в HL — у одного бренда их может быть несколько).
			В таблице <strong><?= (int)$aliasTotal ?></strong> строк
			(<?= (int)$aliasHlRowCount ?> + <?= (int)$aliasVirtualCount ?>).
		<?php else: ?>
			Записи HL <code>mf_brand_alias</code>. Выберите склад, чтобы увидеть бренды товаров на нём.
		<?php endif; ?>
	</p>
	<div class="mf-bss__table-wrap">
		<?php if ($aliasTotal === 0): ?>
			<div class="mf-bss__empty">
				Нет записей по выбранным фильтрам.
				<?php if ($warehouseFilterTitle !== ''): ?>
					<br/>На складе: <?= (int)$warehouseCatalogBrandCount ?> бренд. в каталоге
					<?php if ($warehouseStoreProductCount > 0): ?>
						, <?= (int)$warehouseStoreProductCount ?> поз. в <code>b_catalog_store_product</code>.
					<?php endif; ?>
					<?php if ($warehouseCatalogBrandCount === 0 && $warehouseStoreProductCount > 0): ?>
						<br/>Остатки есть, но <code>MF_BRAND</code> у этих товаров не прочитался — проверьте свойство бренда на карточках.
					<?php elseif ($warehouseCatalogBrandCount > 0 && count($aliasVirtualRows) === 0): ?>
						<br/>Бренды на складе есть, но ни одна запись HL не совпала с фильтром.
					<?php endif; ?>
				<?php endif; ?>
			</div>
		<?php else: ?>
			<table class="mf-bss__table mf-bss__table--map">
				<thead>
				<tr>
					<th class="mf-bss__th-num">#</th>
					<th class="mf-bss__th-alias">Алиас</th>
					<th class="mf-bss__th-arrow"></th>
					<th class="mf-bss__th-canon">Канон</th>
					<th class="mf-bss__th-meta">Статус</th>
					<th class="mf-bss__th-more" title="Приоритет и дата обновления"></th>
					<th class="mf-bss__th-actions"></th>
				</tr>
				</thead>
				<tbody>
				<?php
				$rowNum = ($page - 1) * $perPage;
				foreach ($aliasSlice as $row):
					$rowNum++;
					$isVirtual = !empty($row['IS_VIRTUAL']);
					$rowId = (int)($row['ID'] ?? 0);
					$isActive = !empty($row['UF_ACTIVE']);
					$formId = $isVirtual ? 'mf_bm_virtual_' . md5((string)($row['UF_ALIAS'] ?? '')) : 'mf_bm_alias_' . $rowId;
					$aliasVal = (string)($row['UF_ALIAS'] ?? '');
					$canonVal = (string)($row['UF_CANONICAL'] ?? '');
					$sortVal = (int)($row['UF_SORT'] ?? 0);
					$updatedStr = mf_bm_format_dt($row['UF_UPDATED_AT'] ?? null);
					if ($isVirtual):
						?>
					<tr class="mf-bss__data-row mf-bss__data-row--virtual">
						<td class="mf-bss__num"><?= $rowNum ?></td>
						<td class="mf-bss__alias"><?= mf_bm_escape($aliasVal) ?></td>
						<td class="mf-bss__arrow" aria-hidden="true">→</td>
						<td class="mf-bss__inp-cell mf-bss__inp-cell--canon">
							<select
								form="mf_bm_wh_unmap_form"
								name="map[<?= mf_bm_escape($aliasVal) ?>]"
								class="js-mf-bm-cand-canon"
								title="По умолчанию бренд не меняется при импорте"
							>
								<option value="" selected>— не менять —</option>
								<option value="<?= mf_bm_escape(MF_BM_MAP_SKIP) ?>">— не импортировать —</option>
								<?php foreach ($catalogBrands as $bCh): ?>
									<?php
									$bCh = (string)$bCh;
									if ($bCh === '')
									{
										continue;
									}
									$esc = mf_bm_escape($bCh);
									?>
									<option value="<?= $esc ?>" title="<?= $esc ?>"><?= $esc ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td class="mf-bss__meta">
							<span class="mf-bss__badge mf-bss__badge--stock" title="MF_BRAND товара на складе, записи в HL нет">каталог</span>
						</td>
						<td class="mf-bss__meta">—</td>
						<td class="mf-bss__actions-cell">—</td>
					</tr>
						<?php
						continue;
					endif;
					?>
					<tr class="mf-bss__data-row">
						<td class="mf-bss__num"><?= $rowNum ?></td>
						<td class="mf-bss__inp-cell mf-bss__inp-cell--alias">
							<label class="adm-invisible">Алиас</label>
							<input
								type="text"
								form="<?= mf_bm_escape($formId) ?>"
								name="edit_alias"
								value="<?= mf_bm_escape($aliasVal) ?>"
								title="<?= mf_bm_escape($aliasVal) ?>"
							>
						</td>
						<td class="mf-bss__arrow" aria-hidden="true">→</td>
						<td class="mf-bss__inp-cell mf-bss__inp-cell--canon">
							<label class="adm-invisible">Канон</label>
							<select
								form="<?= mf_bm_escape($formId) ?>"
								name="edit_canon"
								class="js-mf-bm-edit-canon"
								data-selected="<?= mf_bm_escape($canonVal) ?>"
								title="<?= mf_bm_escape($canonVal) ?>"
							>
								<option value="">— загрузка списка —</option>
							</select>
						</td>
						<td class="mf-bss__meta">
							<label class="mf-bss__check-inline" title="Активно">
								<input
									type="checkbox"
									form="<?= mf_bm_escape($formId) ?>"
									name="edit_active"
									value="Y"
									<?= $isActive ? 'checked' : '' ?>
								>
								<?= $isActive ? 'вкл' : 'выкл' ?>
							</label>
						</td>
						<td class="mf-bss__meta">
							<button
								type="button"
								class="mf-bss__btn-more js-mf-bm-details-toggle"
								aria-expanded="false"
								aria-controls="mf_bm_details_<?= $rowId ?>"
								title="Приоритет и дата обновления"
							>⋯</button>
						</td>
						<td class="mf-bss__actions-cell">
							<div class="mf-bss__row-actions">
								<button type="submit" form="<?= mf_bm_escape($formId) ?>" class="mf-bss__btn-save">Сохранить</button>
								<form method="post" action="<?= mf_bm_escape($curPage) ?>" style="margin:0;display:inline;" onsubmit="return confirm('Удалить сопоставление #<?= $rowId ?>?');">
									<?= bitrix_sessid_post() ?>
									<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
									<input type="hidden" name="bm_action" value="delete_alias">
									<input type="hidden" name="delete_id" value="<?= $rowId ?>">
									<input type="hidden" name="active_only" value="<?= $activeOnly ? 'Y' : 'N' ?>">
									<input type="hidden" name="find" value="<?= mf_bm_escape($find) ?>">
									<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
									<input type="hidden" name="page" value="<?= (int)$page ?>">
									<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($findWarehouse) ?>">
									<input type="hidden" name="find_brand" value="<?= mf_bm_escape($findBrandCand) ?>">
									<input type="hidden" name="bm_only_unmapped" value="<?= $onlyUnmapped ? 'Y' : 'N' ?>">
									<input type="hidden" name="cand_page" value="<?= (int)$candPage ?>">
									<button type="submit" class="mf-bss__btn-del">Удалить</button>
								</form>
							</div>
							<form id="<?= mf_bm_escape($formId) ?>" method="post" action="<?= mf_bm_escape($curPage) ?>" style="display:none;">
								<?= bitrix_sessid_post() ?>
								<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
								<input type="hidden" name="bm_action" value="update_alias">
								<input type="hidden" name="edit_id" value="<?= $rowId ?>">
								<input type="hidden" name="active_only" value="<?= $activeOnly ? 'Y' : 'N' ?>">
								<input type="hidden" name="find" value="<?= mf_bm_escape($find) ?>">
								<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
								<input type="hidden" name="page" value="<?= (int)$page ?>">
								<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($findWarehouse) ?>">
								<input type="hidden" name="find_brand" value="<?= mf_bm_escape($findBrandCand) ?>">
								<input type="hidden" name="bm_only_unmapped" value="<?= $onlyUnmapped ? 'Y' : 'N' ?>">
								<input type="hidden" name="cand_page" value="<?= (int)$candPage ?>">
							</form>
						</td>
					</tr>
					<tr class="mf-bss__details-row" id="mf_bm_details_<?= $rowId ?>" hidden>
						<td colspan="7">
							<div class="mf-bss__details-kv">
								<span><strong>ID:</strong> <?= $rowId ?></span>
								<span><strong>Приоритет:</strong> <?= $sortVal ?> <span class="mf-bss__sort-readonly">(не меняется при сохранении)</span></span>
								<span><strong>Обновлено:</strong> <?= mf_bm_escape($updatedStr) ?></span>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ($aliasVirtualOnPage > 0): ?>
				<form id="mf_bm_wh_unmap_form" method="post" action="<?= mf_bm_escape($curPage) ?>" style="padding:12px 14px;border-top:1px solid #edf2f7;">
					<?= bitrix_sessid_post() ?>
					<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
					<input type="hidden" name="bm_action" value="save_candidates">
					<input type="hidden" name="active_only" value="<?= $activeOnly ? 'Y' : 'N' ?>">
					<input type="hidden" name="find" value="<?= mf_bm_escape($find) ?>">
					<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
					<input type="hidden" name="page" value="<?= (int)$page ?>">
					<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($findWarehouse) ?>">
					<input type="hidden" name="find_brand" value="<?= mf_bm_escape($findBrandCand) ?>">
					<input type="hidden" name="bm_only_unmapped" value="<?= $onlyUnmapped ? 'Y' : 'N' ?>">
					<input type="hidden" name="cand_page" value="<?= (int)$candPage ?>">
					<input type="submit" class="adm-btn-save" value="Сохранить сопоставления брендов каталога (<?= (int)$aliasVirtualOnPage ?> на стр.)">
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<?php if ($aliasPages > 1): ?>
		<div class="mf-bss__pager">
			<?php
			$lo = max(1, $page - 3);
			$hi = min($aliasPages, $page + 3);
			if ($page > 1):
				$params = $baseParams;
				$params['page'] = (string)($page - 1);
				$url = htmlspecialcharsbx($APPLICATION->GetCurPageParam(http_build_query($params), $navRemove));
				?>
				<a class="mf-bss__pager-btn" href="<?= $url ?>">← Назад</a>
			<?php endif;
			for ($p = $lo; $p <= $hi; $p++):
				$params = $baseParams;
				$params['page'] = (string)$p;
				$url = htmlspecialcharsbx($APPLICATION->GetCurPageParam(http_build_query($params), $navRemove));
				$cls = $p === $page ? 'mf-bss__pager-btn mf-bss__pager-btn--cur' : 'mf-bss__pager-btn';
				?>
				<a class="<?= $cls ?>" href="<?= $url ?>"><?= (int)$p ?></a>
			<?php endfor;
			if ($page < $aliasPages):
				$params = $baseParams;
				$params['page'] = (string)($page + 1);
				$url = htmlspecialcharsbx($APPLICATION->GetCurPageParam(http_build_query($params), $navRemove));
				?>
				<a class="mf-bss__pager-btn" href="<?= $url ?>">Вперёд →</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
		</div>
	</section>

	<section class="mf-bss__section js-mf-bm-section" data-section-id="skip" data-default-open="0">
		<button type="button" class="mf-bss__section-toggle js-mf-bm-section-toggle" aria-expanded="false" aria-controls="mf_bm_section_skip">
			<span>
				Не импортировать
				<?php if ($warehouseFilterTitle !== ''): ?>
					<span class="mf-bss__section-subtitle"> — <?= mf_bm_escape($warehouseFilterTitle) ?></span>
				<?php endif; ?>
			</span>
			<span class="mf-bss__section-badge"><?= number_format(count($skipRows), 0, '', ' ') ?></span>
			<span class="mf-bss__section-chevron js-mf-bm-section-chevron" aria-hidden="true">▶</span>
		</button>
		<div class="mf-bss__section-body js-mf-bm-section-body" id="mf_bm_section_skip">
	<div class="mf-bss__table-wrap">
		<?php if ($skipRows === []): ?>
			<div class="mf-bss__empty">
				Нет активных записей<?= !function_exists('mf_brand_import_skip_ensure_table') ? ' (таблица ещё не создана)' : '' ?>.
			</div>
		<?php else: ?>
			<table class="mf-bss__table">
				<thead>
				<tr>
					<th class="mf-bss__th-num">#</th>
					<th>Бренд в файле</th>
					<th class="mf-bss__th-meta">Пометка</th>
					<th class="mf-bss__th-date">Обновлено</th>
					<th class="mf-bss__th-actions"></th>
				</tr>
				</thead>
				<tbody>
				<?php $n = 0; foreach ($skipRows as $row): $n++;
					$skipId = (int)($row['ID'] ?? 0);
					$skipFormId = 'mf_bm_skip_' . $skipId;
					$skipVal = (string)($row['UF_ALIAS_RAW'] ?? '');
					?>
					<tr>
						<td class="mf-bss__num"><?= $n ?></td>
						<td class="mf-bss__inp-cell">
							<label class="adm-invisible">Бренд в файле</label>
							<input
								type="text"
								form="<?= mf_bm_escape($skipFormId) ?>"
								name="edit_skip_alias"
								value="<?= mf_bm_escape($skipVal) ?>"
								title="<?= mf_bm_escape($skipVal) ?>"
							>
						</td>
						<td class="mf-bss__meta"><span class="mf-bss__badge mf-bss__badge--skip">пропуск</span></td>
						<td class="mf-bss__date"><?= mf_bm_escape(mf_bm_format_dt($row['UF_UPDATED_AT'] ?? null)) ?></td>
						<td class="mf-bss__actions-cell">
							<div class="mf-bss__row-actions">
								<button type="submit" form="<?= mf_bm_escape($skipFormId) ?>" class="mf-bss__btn-save">Сохранить</button>
								<form method="post" action="<?= mf_bm_escape($curPage) ?>" style="margin:0;display:inline;" onsubmit="return confirm('Удалить запись пропуска #<?= $skipId ?>?');">
									<?= bitrix_sessid_post() ?>
									<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
									<input type="hidden" name="bm_action" value="delete_skip">
									<input type="hidden" name="delete_id" value="<?= $skipId ?>">
									<input type="hidden" name="active_only" value="<?= $activeOnly ? 'Y' : 'N' ?>">
									<input type="hidden" name="find" value="<?= mf_bm_escape($find) ?>">
									<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
									<input type="hidden" name="page" value="<?= (int)$page ?>">
									<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($findWarehouse) ?>">
									<input type="hidden" name="find_brand" value="<?= mf_bm_escape($findBrandCand) ?>">
									<input type="hidden" name="bm_only_unmapped" value="<?= $onlyUnmapped ? 'Y' : 'N' ?>">
									<input type="hidden" name="cand_page" value="<?= (int)$candPage ?>">
									<button type="submit" class="mf-bss__btn-del">Удалить</button>
								</form>
							</div>
							<form id="<?= mf_bm_escape($skipFormId) ?>" method="post" action="<?= mf_bm_escape($curPage) ?>" style="display:none;">
								<?= bitrix_sessid_post() ?>
								<input type="hidden" name="lang" value="<?= mf_bm_escape($lang) ?>">
								<input type="hidden" name="bm_action" value="update_skip">
								<input type="hidden" name="edit_id" value="<?= $skipId ?>">
								<input type="hidden" name="active_only" value="<?= $activeOnly ? 'Y' : 'N' ?>">
								<input type="hidden" name="find" value="<?= mf_bm_escape($find) ?>">
								<input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
								<input type="hidden" name="page" value="<?= (int)$page ?>">
								<input type="hidden" name="find_warehouse" value="<?= mf_bm_escape($findWarehouse) ?>">
								<input type="hidden" name="find_brand" value="<?= mf_bm_escape($findBrandCand) ?>">
								<input type="hidden" name="bm_only_unmapped" value="<?= $onlyUnmapped ? 'Y' : 'N' ?>">
								<input type="hidden" name="cand_page" value="<?= (int)$candPage ?>">
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
		</div>
	</section>

	<script>
	(function () {
		var storageKey = 'mf_bm_sections_v1';
		var storage = {};
		try {
			storage = JSON.parse(localStorage.getItem(storageKey) || '{}') || {};
		} catch (e) {
			storage = {};
		}
		document.querySelectorAll('.js-mf-bm-section').forEach(function (section) {
			var id = section.getAttribute('data-section-id') || '';
			if (id === '') {
				return;
			}
			var toggle = section.querySelector('.js-mf-bm-section-toggle');
			var defaultOpen = section.getAttribute('data-default-open') !== '0';
			var open = Object.prototype.hasOwnProperty.call(storage, id) ? !!storage[id] : defaultOpen;
			function setOpen(isOpen) {
				section.classList.toggle('is-open', isOpen);
				if (toggle) {
					toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
				}
				var chev = section.querySelector('.js-mf-bm-section-chevron');
				if (chev) {
					chev.textContent = isOpen ? '▼' : '▶';
				}
				storage[id] = isOpen;
				try {
					localStorage.setItem(storageKey, JSON.stringify(storage));
				} catch (e) {
					// ignore
				}
			}
			setOpen(open);
			if (toggle) {
				toggle.addEventListener('click', function () {
					setOpen(!section.classList.contains('is-open'));
				});
			}
		});
	})();
	(function () {
		document.querySelectorAll('.js-mf-bm-details-toggle').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var id = btn.getAttribute('aria-controls');
				if (!id) { return; }
				var row = document.getElementById(id);
				if (!row) { return; }
				var open = row.hidden;
				row.hidden = !open;
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
				btn.textContent = open ? '▲' : '⋯';
			});
		});
	})();
	(function () {
		var tpl = document.getElementById('mf_bm_canon_options_tpl');
		if (!tpl) { return; }
		var tplHtml = tpl.innerHTML;
		function syncCanonTitle(sel) {
			var opt = sel.options[sel.selectedIndex];
			sel.title = opt ? (opt.text || opt.value || '') : '';
		}
		var skipValue = <?= json_encode(MF_BM_MAP_SKIP, JSON_UNESCAPED_UNICODE) ?>;
		document.querySelectorAll('.js-mf-bm-edit-canon').forEach(function (sel) {
			var want = sel.getAttribute('data-selected') || '';
			sel.innerHTML = tplHtml;
			if (want !== '') {
				var found = false;
				for (var i = 0; i < sel.options.length; i++) {
					if (sel.options[i].value === want) {
						sel.selectedIndex = i;
						found = true;
						break;
					}
				}
				if (!found && want !== skipValue) {
					var o = document.createElement('option');
					o.value = want;
					o.textContent = want + ' (не в каталоге)';
					o.selected = true;
					o.title = want;
					sel.insertBefore(o, sel.options[2] || null);
				}
			}
			sel.addEventListener('change', function () { syncCanonTitle(sel); });
			syncCanonTitle(sel);
		});
	})();
	</script>

	<div class="mf-bss__footer">
		Связанные разделы:
		<a href="mf_stock_import_missing.php?lang=<?= rawurlencode($lang) ?>">Ненайденные товары (импорт складов)</a>
		·
		<a href="mf_brand_stats.php?lang=<?= rawurlencode($lang) ?>">Бренды каталога</a>
		·
		<a href="mf_catalog_export.php?lang=<?= rawurlencode($lang) ?>">Выгрузка каталога</a>
	</div>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
