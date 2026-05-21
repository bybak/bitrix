<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$lib = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/php_interface/include/mf_catalog_search_lib.php';
if (is_file($lib))
{
	require_once $lib;
}

$arParams['IBLOCK_ID'] = (int)($arParams['IBLOCK_ID'] ?? 4);
$arParams['PAGE_RESULT_COUNT'] = max(1, min(50, (int)($arParams['PAGE_RESULT_COUNT'] ?? 25)));
$arParams['DISPLAY_TOP_PAGER'] = (($arParams['DISPLAY_TOP_PAGER'] ?? 'N') === 'Y' ? 'Y' : 'N');
$arParams['DISPLAY_BOTTOM_PAGER'] = (($arParams['DISPLAY_BOTTOM_PAGER'] ?? 'Y') === 'N' ? 'N' : 'Y');
$arParams['SHOW_WHERE'] = (($arParams['SHOW_WHERE'] ?? 'N') === 'Y' ? 'Y' : 'N');

$queryRaw = trim((string)($_REQUEST['q'] ?? $_REQUEST['text'] ?? ''));
$page = max(1, (int)($_REQUEST['PAGEN_1'] ?? 1));
$pageSize = max(1, min(50, (int)($arParams['PAGE_RESULT_COUNT'] ?? 25)));
$iblockId = (int)($arParams['IBLOCK_ID'] ?? 4);

$arResult['REQUEST'] = [
	'QUERY' => ($queryRaw !== '' ? $queryRaw : false),
	'~QUERY' => $queryRaw,
	'TAGS' => false,
	'HOW' => ((string)($_REQUEST['how'] ?? 'r') === 'd' ? 'd' : 'r'),
];
$arResult['ERROR_CODE'] = 0;
$arResult['ERROR_TEXT'] = '';
$arResult['SEARCH'] = [];
$arResult['NAV_STRING'] = '';
$arResult['NAV_RESULT'] = (object)['NavRecordCount' => 0];
$arResult['DROPDOWN'] = [];

if ($queryRaw !== '' && function_exists('mf_catalog_search_page'))
{
	$search = mf_catalog_search_page($queryRaw, $page, $pageSize, $iblockId);
	$arResult['SEARCH'] = (array)($search['items'] ?? []);
	$arResult['NAV_STRING'] = (string)($search['nav_string'] ?? '');
	$arResult['NAV_RESULT'] = (object)['NavRecordCount' => (int)($search['total'] ?? 0)];
}

$this->IncludeComponentTemplate();
