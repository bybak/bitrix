<?php
/**
 * Поиск каталога: сначала строгое совпадение по артикулу (CML2_ARTICLE / MF_ARTICLE_NORM),
 * затем все остальные результаты полнотекстового поиска (порядок внутри групп сохраняем).
 *
 * Полнотекстовый поиск режет запрос и не гарантирует приоритет артикула; дубликаты из обеих
 * групп снимаем — карточка с точным артикулом всегда выше прочих совпадений по названию.
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

$filterExact = [
	'IBLOCK_ID' => $mfCatalogIblockId,
	'ACTIVE' => 'Y',
	'CHECK_PERMISSIONS' => 'Y',
	'MIN_PERMISSION' => 'R',
	array_merge(['LOGIC' => 'OR'], $or),
];

/** @var array<int, true> $exactIds */
$exactIds = [];
$rsExact = \CIBlockElement::GetList(
	['ID' => 'ASC'],
	$filterExact,
	false,
	['nTopCount' => 100],
	['ID']
);
while ($row = $rsExact->Fetch())
{
	$eid = (int)($row['ID'] ?? 0);
	if ($eid > 0)
	{
		$exactIds[$eid] = true;
	}
}

if ($exactIds === [])
{
	return;
}

$search = is_array($arResult['SEARCH'] ?? null) ? $arResult['SEARCH'] : [];
$exactRows = [];
$restRows = [];
$seenExact = [];

foreach ($search as $row)
{
	if (!is_array($row))
	{
		continue;
	}
	$eid = 0;
	if ((string)($row['MODULE_ID'] ?? '') === 'iblock' && (int)($row['PARAM2'] ?? 0) === $mfCatalogIblockId)
	{
		$eid = (int)($row['ITEM_ID'] ?? 0);
	}
	if ($eid > 0 && isset($exactIds[$eid]))
	{
		$exactRows[] = $row;
		$seenExact[$eid] = true;
	}
	else
	{
		$restRows[] = $row;
	}
}

$missingIds = [];
foreach (array_keys($exactIds) as $eid)
{
	if ($eid > 0 && !isset($seenExact[$eid]))
	{
		$missingIds[] = $eid;
	}
}

$addedFromArticleOnly = 0;
if ($missingIds !== [])
{
	$prefill = [];
	$rs = \CIBlockElement::GetList(
		['ID' => 'ASC'],
		[
			'IBLOCK_ID' => $mfCatalogIblockId,
			'ID' => $missingIds,
			'ACTIVE' => 'Y',
		],
		false,
		false,
		['ID', 'NAME', 'PREVIEW_TEXT', 'DETAIL_TEXT']
	);
	while ($e = $rs->Fetch())
	{
		$eid = (int)($e['ID'] ?? 0);
		if ($eid <= 0)
		{
			continue;
		}
		$body = trim((string)($e['PREVIEW_TEXT'] ?? ''));
		if ($body === '')
		{
			$body = trim((string)($e['DETAIL_TEXT'] ?? ''));
		}
		if (function_exists('mf_catalog_strip_stock_disclaimer'))
		{
			$body = mf_catalog_strip_stock_disclaimer($body);
		}
		$prefill[] = [
			'MODULE_ID' => 'iblock',
			'ITEM_ID' => $eid,
			'PARAM2' => $mfCatalogIblockId,
			'TITLE' => (string)($e['NAME'] ?? ''),
			'TITLE_FORMATED' => (string)($e['NAME'] ?? ''),
			'URL' => '',
			'BODY_FORMATED' => $body,
		];
	}
	$addedFromArticleOnly = count($prefill);
	$exactRows = array_merge($prefill, $exactRows);
}

$arResult['SEARCH'] = array_merge($exactRows, $restRows);

if (isset($arResult['NAV_RESULT']) && is_object($arResult['NAV_RESULT']))
{
	try
	{
		$add = $addedFromArticleOnly;
		if ($add > 0)
		{
			$ref = new \ReflectionClass($arResult['NAV_RESULT']);
			if ($ref->hasProperty('NavRecordCount'))
			{
				$p = $ref->getProperty('NavRecordCount');
				$p->setAccessible(true);
				$cur = (int)$p->getValue($arResult['NAV_RESULT']);
				$p->setValue($arResult['NAV_RESULT'], $cur + $add);
			}
		}
	}
	catch (\Throwable $e)
	{
		// ignore
	}
}
