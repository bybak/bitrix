<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$__mfCvRm = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/components/bitrix/catalog.viewed.products/templates/.default/result_modifier.php';
if (is_file($__mfCvRm))
{
	require $__mfCvRm;
}
unset($__mfCvRm);

if (empty($arResult['ITEMS']) || !function_exists('mf_catalog_listing_display_price'))
{
	return;
}

$hasMap = function_exists('mf_catalog_use_bitrix_base_price_fallback') && !mf_catalog_use_bitrix_base_price_fallback();
$baseCur = class_exists('CCurrency') ? CCurrency::GetBaseCurrency() : 'RUB';

foreach ($arResult['ITEMS'] as &$arItem)
{
	$pid = (int)($arItem['ID'] ?? 0);
	if ($pid <= 0)
	{
		continue;
	}
	$dp = mf_catalog_listing_display_price($pid);
	if ($dp !== null && $dp > 0 && !empty($arItem['MIN_PRICE']) && is_array($arItem['MIN_PRICE']) && function_exists('mf_catalog_patch_bitrix_min_price_display'))
	{
		mf_catalog_patch_bitrix_min_price_display($arItem['MIN_PRICE'], (float)$dp);
	}
	elseif ($hasMap && !empty($arItem['MIN_PRICE']) && is_array($arItem['MIN_PRICE']))
	{
		$arItem['MIN_PRICE']['PRINT_DISCOUNT_VALUE'] = 'Цена по запросу';
		$arItem['MIN_PRICE']['DISCOUNT_VALUE'] = 0;
		$arItem['MIN_PRICE']['VALUE'] = 0;
		$arItem['MIN_PRICE']['PRINT_VALUE'] = 'Цена по запросу';
	}
}
unset($arItem);
