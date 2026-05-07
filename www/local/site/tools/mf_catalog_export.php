<?php
/**
 * Export Bitrix catalog structure for sync scripts (JSON).
 *
 * GET /tools/mf_catalog_export.php?iblock_id=4
 *
 * Output:
 * {
 *   "iblock_id": 4,
 *   "sections": [{id,name,code,parent_id,depth,section_page_url}],
 *   "elements": [{id,name,code,xml_id,section_id,article,article_norm,brand_norm,uniq_key,detail_page_url}],
 *   "redirects": [{id,code,canonical_code,article,article_norm,brand_norm,uniq_key}]
 * }
 */

$_SERVER["DOCUMENT_ROOT"] = dirname(__DIR__);
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define("BX_PUBLIC_MODE", 1);
define("PUBLIC_AJAX_MODE", true);
@ini_set('memory_limit', '2048M');

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;

global $APPLICATION;
if (is_object($APPLICATION))
{
	$APPLICATION->RestartBuffer();
}

header('Content-Type: application/json; charset=utf-8');

try
{
	Loader::includeModule("iblock");

	$iblockId = (int)($_GET['iblock_id'] ?? 4);
	if ($iblockId <= 0)
	{
		throw new RuntimeException("iblock_id is required");
	}

	$sections = [];
	$secRes = CIBlockSection::GetList(
		['LEFT_MARGIN' => 'ASC'],
		['IBLOCK_ID' => $iblockId, 'GLOBAL_ACTIVE' => 'Y'],
		false,
		['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL', 'SECTION_PAGE_URL']
	);
	while ($s = $secRes->Fetch())
	{
		$sections[] = [
			'id' => (int)$s['ID'],
			'name' => (string)$s['NAME'],
			'code' => (string)$s['CODE'],
			'parent_id' => (int)($s['IBLOCK_SECTION_ID'] ?? 0),
			'depth' => (int)($s['DEPTH_LEVEL'] ?? 0),
			'section_page_url' => (string)($s['SECTION_PAGE_URL'] ?? ''),
		];
	}

	$elements = [];
	// Canonical (non-redirect) elements
	$elRes = CIBlockElement::GetList(
		['ID' => 'ASC'],
		['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', '!=PROPERTY_MF_IS_REDIRECT' => 'Y'],
		false,
		false,
		[
			'ID',
			'NAME',
			'CODE',
			'XML_ID',
			'IBLOCK_SECTION_ID',
			'DETAIL_PAGE_URL',
			'PROPERTY_CML2_ARTICLE',
			'PROPERTY_MF_ARTICLE_NORM',
			'PROPERTY_MF_BRAND_NORM',
			'PROPERTY_MF_UNIQ_KEY',
		]
	);
	while ($e = $elRes->Fetch())
	{
		$elements[] = [
			'id' => (int)$e['ID'],
			'name' => (string)$e['NAME'],
			'code' => (string)$e['CODE'],
			'xml_id' => (string)$e['XML_ID'],
			'section_id' => (int)($e['IBLOCK_SECTION_ID'] ?? 0),
			'article' => (string)($e['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''),
			'article_norm' => (string)($e['PROPERTY_MF_ARTICLE_NORM_VALUE'] ?? ''),
			'brand_norm' => (string)($e['PROPERTY_MF_BRAND_NORM_VALUE'] ?? ''),
			'uniq_key' => (string)($e['PROPERTY_MF_UNIQ_KEY_VALUE'] ?? ''),
			'detail_page_url' => (string)($e['DETAIL_PAGE_URL'] ?? ''),
		];
	}

	// Redirect (duplicate) elements. We export minimal mapping so the sync script can
	// attach images from duplicates to their canonical element.
	$redirects = [];
	$redRes = CIBlockElement::GetList(
		['ID' => 'ASC'],
		['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', '=PROPERTY_MF_IS_REDIRECT' => 'Y'],
		false,
		false,
		[
			'ID',
			'CODE',
			'PROPERTY_MF_CANONICAL_CODE',
			'PROPERTY_CML2_ARTICLE',
			'PROPERTY_MF_ARTICLE_NORM',
			'PROPERTY_MF_BRAND_NORM',
			'PROPERTY_MF_UNIQ_KEY',
		]
	);
	while ($r = $redRes->Fetch())
	{
		$redirects[] = [
			'id' => (int)$r['ID'],
			'code' => (string)($r['CODE'] ?? ''),
			'canonical_code' => (string)($r['PROPERTY_MF_CANONICAL_CODE_VALUE'] ?? ''),
			'article' => (string)($r['PROPERTY_CML2_ARTICLE_VALUE'] ?? ''),
			'article_norm' => (string)($r['PROPERTY_MF_ARTICLE_NORM_VALUE'] ?? ''),
			'brand_norm' => (string)($r['PROPERTY_MF_BRAND_NORM_VALUE'] ?? ''),
			'uniq_key' => (string)($r['PROPERTY_MF_UNIQ_KEY_VALUE'] ?? ''),
		];
	}

	echo json_encode([
		'iblock_id' => $iblockId,
		'sections' => $sections,
		'elements' => $elements,
		'redirects' => $redirects,
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
catch (Throwable $e)
{
	http_response_code(500);
	echo json_encode([
		'error' => true,
		'message' => $e->getMessage(),
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

