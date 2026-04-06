<?php
/**
 * Дополняет полнотекстовый поиск прямым совпадением по артикулу (CML2_ARTICLE / MF_ARTICLE_NORM).
 *
 * Полнотекстовый модуль режет запрос по разделителям (в т.ч. «-»), а свойства артикула
 * часто не попадают в индекс — из‑за этого запросы вида «09-825-02» могут не находить товар.
 */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!is_array($arResult ?? null))
{
	return;
}

$query = isset($arResult['REQUEST']['~QUERY']) ? trim((string)$arResult['REQUEST']['~QUERY']) : '';
if ($query === '' || !class_exists('CIBlockElement') || !\Bitrix\Main\Loader::includeModule('iblock'))
{
	return;
}

$mfCatalogIblockId = 4;
$lib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/php_interface/include/mf_import_analogs_lib.php';
if (is_file($lib))
{
	require_once $lib;
}
$articleNorm = function_exists('mf_analogs_norm_article') ? mf_analogs_norm_article($query) : '';

$or = [
	['=PROPERTY_CML2_ARTICLE' => $query],
];
if ($articleNorm !== '')
{
	$or[] = ['=PROPERTY_MF_ARTICLE_NORM' => $articleNorm];
}

$filter = [
	'IBLOCK_ID' => $mfCatalogIblockId,
	'ACTIVE' => 'Y',
	'CHECK_PERMISSIONS' => 'Y',
	'MIN_PERMISSION' => 'R',
	array_merge(['LOGIC' => 'OR'], $or),
];

$existing = [];
if (!empty($arResult['SEARCH']) && is_array($arResult['SEARCH']))
{
	foreach ($arResult['SEARCH'] as $row)
	{
		if ((string)($row['MODULE_ID'] ?? '') !== 'iblock')
		{
			continue;
		}
		if ((int)($row['PARAM2'] ?? 0) !== $mfCatalogIblockId)
		{
			continue;
		}
		$eid = (int)($row['ITEM_ID'] ?? 0);
		if ($eid > 0)
		{
			$existing[$eid] = true;
		}
	}
}

$extra = [];
$rs = \CIBlockElement::GetList(
	['ID' => 'ASC'],
	$filter,
	false,
	['nTopCount' => 30],
	['ID', 'NAME', 'PREVIEW_TEXT', 'DETAIL_TEXT']
);
while ($e = $rs->Fetch())
{
	$eid = (int)($e['ID'] ?? 0);
	if ($eid <= 0 || isset($existing[$eid]))
	{
		continue;
	}
	$existing[$eid] = true;
	$body = trim((string)($e['PREVIEW_TEXT'] ?? ''));
	if ($body === '')
	{
		$body = trim((string)($e['DETAIL_TEXT'] ?? ''));
	}
	$extra[] = [
		'MODULE_ID' => 'iblock',
		'ITEM_ID' => $eid,
		'PARAM2' => $mfCatalogIblockId,
		'TITLE' => (string)($e['NAME'] ?? ''),
		'TITLE_FORMATED' => (string)($e['NAME'] ?? ''),
		'URL' => '',
		'BODY_FORMATED' => $body,
	];
}

if ($extra === [])
{
	return;
}

$arResult['SEARCH'] = array_merge($extra, is_array($arResult['SEARCH'] ?? null) ? $arResult['SEARCH'] : []);

if (isset($arResult['NAV_RESULT']) && is_object($arResult['NAV_RESULT']))
{
	try
	{
		$add = count($extra);
		$ref = new \ReflectionClass($arResult['NAV_RESULT']);
		if ($ref->hasProperty('NavRecordCount'))
		{
			$p = $ref->getProperty('NavRecordCount');
			$p->setAccessible(true);
			$cur = (int)$p->getValue($arResult['NAV_RESULT']);
			$p->setValue($arResult['NAV_RESULT'], $cur + $add);
		}
	}
	catch (\Throwable $e)
	{
		// ignore
	}
}
